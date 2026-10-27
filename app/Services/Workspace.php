<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** All services receive an explicit actor and optional tenant. No posted actor IDs. */
class Workspace
{
    public BaseConnection $db;
    public array $person;
    public ?array $agency = null;
    public ?array $membership = null;
    public bool $operator;

    public const ROLES = [
        'owner' => ['recruitment','history','employment','people','scheduling','time','leave','care','assets','expenses','payroll','finance','reporting','settings'],
        'recruiter' => ['recruitment','history','employment'],
        'scheduler' => ['people','scheduling','care','reporting'],
        'supervisor' => ['people','scheduling','time','leave','care','assets','expenses','reporting'],
        'finance' => ['expenses','payroll','finance','reporting'],
        'caregiver' => ['self'],
        'worker' => ['employment'],
    ];

    public function __construct(public int $userId, ?string $agencyRef = null, ?BaseConnection $db = null, bool $billingOnly=false)
    {
        $this->db = $db ?? db_connect();
        $user = $this->db->table('users')->where('id',$userId)->where('active',1)->where('deleted_at',null)->get()->getRowArray();
        if (!$user || in_array($user['status'], ['suspended','banned','closed'],true)) {
            throw new DomainError('Please sign in with an active account.',401);
        }
        if(ENVIRONMENT==='production' && $this->db->table('auth_identities')->where(['user_id'=>$userId,'type'=>'email_password'])->like('secret','@caresys.test','before')->countAllResults())throw new DomainError('Development demo accounts cannot access production.',403);
        $this->person = $this->db->table('cs_people')->where('user_id',$userId)->get()->getRowArray() ?? [];
        $this->operator = $this->db->table('cs_operators')->where('user_id',$userId)->countAllResults() > 0;
        if ($agencyRef) {
            $agency = $this->db->table('cs_agencies')->where('ref',$agencyRef)->get()->getRowArray();
            $membership = $agency ? $this->db->table('cs_memberships')->where(['agency_id'=>$agency['id'],'user_id'=>$userId,'state'=>'active'])->get()->getRowArray() : null;
            if (!$membership) throw new DomainError('Workspace unavailable.',404);
            $this->agency = $agency;
            $this->membership = $membership;
            if(!$billingOnly)(new AgencyAccessService($this))->assertAccess();
        }
    }

    public function require(string $module): void
    {
        if (!$this->agency || !$this->membership) throw new DomainError('Choose an agency workspace.',403);
        // Recheck live access even inside background jobs or a long-running request.
        $agency = $this->db->table('cs_agencies')->where('id',$this->agency['id'])->get()->getRowArray();
        $member = $this->db->table('cs_memberships')->where(['id'=>$this->membership['id'],'user_id'=>$this->userId,'state'=>'active'])->get()->getRowArray();
        if (!$member || !in_array($module,self::ROLES[$member['role']] ?? [],true)) throw new DomainError('This role cannot access that module.',403);
        $this->agency=$agency;$this->membership=$member;
        (new AgencyAccessService($this))->assertAccess();
        if(in_array($module,['recruitment','history'],true) && !empty($agency['entitlement_until']) && $agency['entitlement_until']<gmdate('Y-m-d'))throw new DomainError('This recruitment entitlement has expired.',403);
        if ($module !== 'self' && $module !== 'settings' && !in_array($module,json_decode($agency['modules'],true),true)) throw new DomainError('This module requires an agency entitlement.',403);
        if (in_array($module,['recruitment','history'],true) && ($agency['verification'] !== 'verified' || !in_array($agency['subscription'],['active','trial'],true))) throw new DomainError('Verified agency and current recruitment access required.',403);
        if ($agency['verification'] === 'suspended') throw new DomainError('This agency is suspended.',403);
    }

    public function can(string $module): bool
    {
        try { $this->require($module); return true; } catch (DomainError) { return false; }
    }

    public function requireBilling(): void
    {
        if(!$this->agency||!$this->membership)throw new DomainError('Choose an agency workspace.',403);
        $member=$this->table('memberships')->where(['user_id'=>$this->userId,'state'=>'active','role'=>'owner'])->get()->getRowArray();
        $agency=$this->db->table('cs_agencies')->where('id',$this->agency['id'])->get()->getRowArray();
        if(!$member||$agency['verification']==='suspended')throw new DomainError('Active agency owner permission required.',403);
        $this->agency=$agency;$this->membership=$member;
    }

    public function ownPerson(): array
    {
        if (!$this->person) throw new DomainError('Complete personal account registration first.',409);
        return $this->person;
    }

    public function table(string $name)
    {
        if (!$this->agency) throw new DomainError('Workspace unavailable.',404);
        return $this->db->table('cs_'.$name)->where('agency_id',$this->agency['id']);
    }

    public function record(string $table, string $ref, bool $lock = false): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$ref)) throw new DomainError('Record unavailable.',404);
        if ($lock) {
            $row = $this->db->query('SELECT * FROM cs_'.$table.' WHERE ref=? AND agency_id=? FOR UPDATE',[$ref,$this->agency['id']])->getRowArray();
        } else { $row = $this->table($table)->where('ref',$ref)->get()->getRowArray(); }
        if (!$row) throw new DomainError('Record unavailable.',404);
        return $row;
    }

    public function insert(string $table, array $values): array
    {
        $values['ref'] ??= bin2hex(random_bytes(16));
        if (!$this->db->table('cs_'.$table)->insert($values)) throw new DomainError('Unable to save this record.',409);
        return $this->db->table('cs_'.$table)->where('id',$this->db->insertID())->get()->getRowArray();
    }

    public function update(string $table, array $row, array $values, ?int $expected = null): array
    {
        if ($expected !== null && (int)$row['version'] !== $expected) throw new DomainError('This record changed. Refresh and review the latest version.',409);
        $values['version'] = (int)$row['version']+1;
        $values['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->db->table('cs_'.$table)->where('id',$row['id'])->where('version',$row['version'])->update($values);
        if ($this->db->affectedRows() !== 1) throw new DomainError('Another update won. Refresh before retrying.',409);
        return array_merge($row,$values);
    }

    public function transaction(callable $work)
    {
        $this->db->transException(true)->transBegin();
        try {
            // Lock shared authorization roots first; membership changes use this order too.
            if ($this->agency) {
                $this->db->query('SELECT id FROM cs_agencies WHERE id=? FOR UPDATE',[$this->agency['id']]);
                $this->db->query('SELECT id FROM cs_memberships WHERE id=? FOR UPDATE',[$this->membership['id']]);
            }
            $result = $work();
            if (!$this->db->transStatus()) throw new DomainError('The transaction could not complete.',409);
            $this->db->transCommit();
            return $result;
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }

    public function audit(string $action, ?string $ref = null, ?int $person = null, string $detail = ''): void
    {
        $this->insert('audit',['agency_id'=>$this->agency['id'] ?? null,'actor_id'=>$this->userId,'person_id'=>$person,'action'=>$action,'record_ref'=>$ref,'detail'=>$detail]);
    }

    public function notify(string $event, string $ref, ?int $recipient = null): void
    {
        $this->insert('outbox',['agency_id'=>$this->agency['id'] ?? null,'recipient_id'=>$recipient,'event'=>$event,'record_ref'=>$ref]);
    }

    public static function text(array $data,string $key,int $max=500,bool $required=true): string
    {
        if (isset($data[$key]) && !is_scalar($data[$key])) throw new DomainError('Invalid '.$key.'.');
        $value=trim((string)($data[$key] ?? ''));
        if (($required && $value==='') || mb_strlen($value)>$max) throw new DomainError('Check '.$key.' (maximum '.$max.' characters).');
        return $value;
    }

    public static function date(string $value): string
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$d || $d->format('Y-m-d')!==$value) throw new DomainError('Enter a valid date.');
        return $value;
    }

    public function employment(string $ref, bool $self=false): array
    {
        $employee=$this->record('employments',$ref,true);
        if ($employee['state']!=='active') throw new DomainError('An active employment is required.',409);
        if ($self && (int)$employee['person_id']!==(int)$this->ownPerson()['id']) throw new DomainError('Employment unavailable.',404);
        return $employee;
    }
}
