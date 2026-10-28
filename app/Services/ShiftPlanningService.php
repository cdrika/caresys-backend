<?php
namespace App\Services;

/** Cross-agency access is confined to this service; agencies never receive foreign visit records. */
final class ShiftPlanningService
{
    public function __construct(private Workspace $w){}

    public function lockPerson(int $person): void
    {
        $this->w->db->query('SELECT id FROM cs_people WHERE id=? FOR UPDATE',[$person]);
    }

    public function conflicts(int $person,string $start,string $end): array
    {
        return $this->w->db->query("SELECT v.* FROM cs_visits v JOIN cs_employments e ON e.id=v.employment_id AND e.agency_id=v.agency_id WHERE e.person_id=? AND v.state NOT IN ('cancelled','no_show') AND v.starts_at < ? AND v.ends_at > ? ORDER BY v.id FOR UPDATE",[$person,$end,$start])->getResultArray();
    }

    public function onLeave(int $person,string $start,string $end): bool
    {
        return (bool)$this->w->db->query("SELECT l.id FROM cs_leave l JOIN cs_employments e ON e.id=l.employment_id AND e.agency_id=l.agency_id WHERE e.person_id=? AND l.state IN ('approved','cancellation_requested') AND l.starts_at < ? AND l.ends_at > ? LIMIT 1 FOR UPDATE",[$person,$end,$start])->getRowArray();
    }

    private function proposal(array $d): array
    {
        $this->w->require('scheduling');
        // Serialize every agency against the caregiver, before locking the employment.
        $e=$this->w->record('employments',$d['employment_ref']??'');$this->lockPerson((int)$e['person_id']);
        $e=$this->w->employment($d['employment_ref']??'');$this->w->record('clients',$d['client_ref']??'');
        $start=OperationsService::instant($d['starts_at']??'',$this->w->agency['timezone']);
        $end=OperationsService::instant($d['ends_at']??'',$this->w->agency['timezone']);
        if($end<=$start||strtotime($end.' UTC')-strtotime($start.' UTC')>86400)throw new DomainError('Choose a shift duration greater than zero and no longer than 24 hours.');
        if(substr($d['starts_at'],0,10)<$e['start_date'])throw new DomainError('Visit falls before employment starts.');
        return [$e,$start,$end];
    }

    public function availability(array $d): array
    {
        return $this->w->transaction(function()use($d){
            [$e,$start,$end]=$this->proposal($d);$person=(int)$e['person_id'];
            $conflicts=$this->conflicts($person,$start,$end);$leave=$this->onLeave($person,$start,$end);
            $duration=strtotime($end.' UTC')-strtotime($start.' UTC');$cursor=$start;$suggestions=[];
            $limit=strtotime($start.' UTC')+7*86400;
            // Earliest gaps after the requested start, not a promise of working-hour availability.
            while(count($suggestions)<3&&strtotime($cursor.' UTC')+$duration<=$limit){
                $finish=gmdate('Y-m-d H:i:s',strtotime($cursor.' UTC')+$duration);
                $busy=$this->conflicts($person,$cursor,$finish);
                $leaves=$this->w->db->query("SELECT l.ends_at FROM cs_leave l JOIN cs_employments e ON e.id=l.employment_id WHERE e.person_id=? AND l.state IN ('approved','cancellation_requested') AND l.starts_at < ? AND l.ends_at > ? FOR UPDATE",[$person,$finish,$cursor])->getResultArray();
                if($busy||$leaves){$cursor=max(array_column(array_merge($busy,$leaves),'ends_at'));continue;}
                $zone=new \DateTimeZone($this->w->agency['timezone']);
                $local=static fn($s)=>(new \DateTimeImmutable($s,new \DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d\TH:i');
                $suggestions[]=['starts_at'=>$local($cursor),'ends_at'=>$local($finish)];
                $cursor=$finish;
            }
            return ['available'=>!$conflicts&&!$leave,'conflict'=> (bool)$conflicts,'leave_conflict'=>$leave,
                'replacement_possible'=>(bool)$conflicts&&!$leave&&$this->replaceable($conflicts),
                'timezone'=>$this->w->agency['timezone'],'suggestions'=>$suggestions,
                'notice'=>'Suggestions check recorded shifts and approved leave only. Confirm working hours, travel and caregiver availability before scheduling.'];
        });
    }

    private function replaceable(array $rows): bool
    {
        foreach($rows as $v){
            if($v['state']!=='published'||$v['starts_at']<=gmdate('Y-m-d H:i:s')||$this->w->db->table('cs_clock')->where('visit_id',$v['id'])->countAllResults())return false;
        }
        return true;
    }

    public function request(array $d): array
    {
        return $this->w->transaction(function()use($d){
            [$e,$start,$end]=$this->proposal($d);$person=(int)$e['person_id'];
            if($start<=gmdate('Y-m-d H:i:s'))throw new DomainError('A replacement request must be for a future shift.',409);
            Workspace::text($d,'service',120);Workspace::text($d,'required_qualification',160,false);
            $conflicts=$this->conflicts($person,$start,$end);
            if(!$conflicts||$this->onLeave($person,$start,$end)||!$this->replaceable($conflicts))throw new DomainError('Only future unstarted shifts can be replaced. Approved leave cannot be overridden. Check availability again.',409);
            $payload=array_intersect_key($d,array_flip(['employment_ref','client_ref','starts_at','ends_at','service','required_qualification']));
            $r=$this->w->insert('shift_changes',['agency_id'=>$this->w->agency['id'],'person_id'=>$person,'created_by'=>$this->w->userId,'payload'=>json_encode($payload),'conflicts'=>json_encode(array_map(static fn($v)=>['id'=>(int)$v['id'],'version'=>(int)$v['version']],$conflicts)),
                'expires_at'=>min($start,gmdate('Y-m-d H:i:s',time()+86400))]);
            $p=$this->w->db->table('cs_people')->where('id',$person)->get()->getRowArray();
            $this->w->audit('shift_change.requested',$r['ref'],$person,'Pending caregiver approval; no shift changed');
            $this->w->notify('shift_change.requested',$r['ref'],(int)$p['user_id']);return $r;
        });
    }

    public function requests(): array
    {
        $p=$this->w->ownPerson();
        $rows=$this->w->db->table('cs_shift_changes r')->select('r.*,a.name AS agency_name,a.timezone')->join('cs_agencies a','a.id=r.agency_id')->where('r.person_id',$p['id'])->orderBy('r.id','DESC')->limit(100)->get()->getResultArray();
        foreach($rows as &$r){
            $payload=json_decode($r['payload'],true);$r['starts_at']=$payload['starts_at'];$r['ends_at']=$payload['ends_at'];$r['service']=$payload['service'];
            $r['replaced_shifts']=[];
            foreach(json_decode($r['conflicts'],true) as $c){
                $v=$this->w->db->query('SELECT v.starts_at,v.ends_at,v.state,a.name AS agency_name FROM cs_visits v JOIN cs_employments e ON e.id=v.employment_id JOIN cs_agencies a ON a.id=v.agency_id WHERE v.id=? AND e.person_id=?',[$c['id'],$p['id']])->getRowArray();
                if($v)$r['replaced_shifts'][]=$v;
            }
            if($r['state']==='pending'&&$r['expires_at']<=gmdate('Y-m-d H:i:s'))$r['state']='expired';
            unset($r['payload'],$r['conflicts'],$r['id'],$r['agency_id'],$r['person_id'],$r['created_by']);
        }unset($r);return $rows;
    }

    public function decide(array $d): array
    {
        if($this->w->agency)throw new DomainError('Decide in your personal workspace.',403);
        $p=$this->w->ownPerson();
        return $this->w->transaction(function()use($d,$p){
            $this->lockPerson((int)$p['id']);
            $r=$this->w->db->query('SELECT * FROM cs_shift_changes WHERE ref=? AND person_id=? FOR UPDATE',[$d['ref']??'',$p['id']])->getRowArray();
            if(!$r)throw new DomainError('Request unavailable.',404);
            if($r['state']!=='pending'||$r['expires_at']<=gmdate('Y-m-d H:i:s'))throw new DomainError('This request is closed or expired.',409);
            if((int)($d['version']??0)!==(int)$r['version'])throw new DomainError('This request changed. Review again.',409);
            $decision=$d['decision']??'';if(!in_array($decision,['approve','decline'],true))throw new DomainError('Choose approve or decline.');
            if($decision==='decline'){
                $this->w->audit('shift_change.declined',$r['ref'],(int)$p['id']);
                return $this->w->update('shift_changes',$r,['state'=>'declined'],(int)$d['version']);
            }
            if(($d['confirm']??'')!=='yes')throw new DomainError('Confirm cancellation of the listed prior shifts.');
            (new EmploymentService($this->w))->stepUp($d);
            $agency=$this->w->db->table('cs_agencies')->where('id',$r['agency_id'])->get()->getRowArray();
            $requester=new Workspace((int)$r['created_by'],$agency['ref'],$this->w->db);$requester->require('scheduling');
            $payload=json_decode($r['payload'],true);[$e,$start,$end]=(new self($requester))->proposal($payload);
            if((int)$e['person_id']!==(int)$p['id'])throw new DomainError('Employment changed.',409);
            $current=$this->conflicts((int)$p['id'],$start,$end);
            $snapshot=array_map(static fn($v)=>['id'=>(int)$v['id'],'version'=>(int)$v['version']],$current);
            if($snapshot!==json_decode($r['conflicts'],true)||!$this->replaceable($current)||$this->onLeave((int)$p['id'],$start,$end))throw new DomainError('Your schedule changed. Ask the agency for a new request; nothing has been replaced.',409);
            foreach($current as $v){
                $this->w->update('visits',$v,['state'=>'cancelled'],(int)$v['version']);
                // Notify the affected agency without exposing the new agency or client.
                $this->w->insert('audit',['agency_id'=>$v['agency_id'],'actor_id'=>$this->w->userId,'person_id'=>$p['id'],'action'=>'visit.replaced_by_caregiver','record_ref'=>$v['ref'],'detail'=>'Caregiver approved replacement of this future shift. Arrange coverage.']);
                $this->w->insert('outbox',['agency_id'=>$v['agency_id'],'recipient_id'=>null,'event'=>'visit.coverage_required','record_ref'=>$v['ref']]);
            }
            // Shared scheduling validation runs again; any failure rolls back all cancellations.
            $visit=(new OperationsService($requester))->schedule($payload);
            $this->w->audit('shift_change.approved',$r['ref'],(int)$p['id'],'Caregiver approved replacement; prior agencies require coverage');
            $this->w->notify('shift_change.approved',$r['ref'],(int)$p['user_id']);
            return $this->w->update('shift_changes',$r,['state'=>'approved','visit_ref'=>$visit['ref']],(int)$d['version']);
        });
    }
}
