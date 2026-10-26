<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Models\UserModel;

class MobilePairingService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    public static function normalizeCode(string $code): string
    {
        $code=strtoupper(str_replace(['-',' '],'',trim($code)));
        if (!preg_match('/^[A-F0-9]{12}$/D',$code)) throw new DomainError('Pairing code unavailable or expired.',404);
        return $code;
    }

    public function start(string $deviceName): array
    {
        if (!preg_match('/^[a-zA-Z0-9 _-]{1,60}$/D',$deviceName)) throw new DomainError('Enter a device name using letters, numbers, spaces or hyphens.',400);
        $secret=bin2hex(random_bytes(32));
        $code=strtoupper(bin2hex(random_bytes(6)));
        $this->db->table('cs_mobile_pairings')->where('expires_at <',gmdate('Y-m-d H:i:s',time()-86400))->delete();
        $this->db->table('cs_mobile_pairings')->insert([
            'device_hash'=>hash('sha256',$secret),'code_hash'=>hash('sha256',$code),
            'device_name'=>$deviceName,'expires_at'=>gmdate('Y-m-d H:i:s',time()+300),'created_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return ['device_code'=>$secret,'user_code'=>implode('-',str_split($code,4)),'expires_in'=>300,'interval'=>5];
    }

    public function review(string $code): array
    {
        $row=$this->db->table('cs_mobile_pairings')->where('code_hash',hash('sha256',self::normalizeCode($code)))->get()->getRowArray();
        if (!$row || $row['state']!=='pending' || $row['expires_at']<=gmdate('Y-m-d H:i:s')) throw new DomainError('Pairing code unavailable or expired.',404);
        return ['device_name'=>$row['device_name'],'expires_at'=>$row['expires_at']];
    }

    public function approve(string $code, int $userId, string $password): void
    {
        $w=new Workspace($userId,null,$this->db);
        $user=model(UserModel::class)->findById($userId);
        if (!$user || strlen($password)>255 || !service('passwords')->verify($password,$user->password_hash)) throw new DomainError('Password confirmation failed.',403);
        $this->review($code);
        $this->db->table('cs_mobile_pairings')->where('code_hash',hash('sha256',self::normalizeCode($code)))
            ->where('state','pending')->where('expires_at >',gmdate('Y-m-d H:i:s'))->update(['state'=>'approved','user_id'=>$userId]);
        if ($this->db->affectedRows()!==1) throw new DomainError('Pairing code unavailable or expired.',409);
        $w->audit('mobile.pairing.approved');
    }

    public function redeem(string $secret): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$secret)) throw new DomainError('Pairing unavailable or expired.',400);
        $this->db->transException(true)->transBegin();
        try {
            $row=$this->db->query('SELECT * FROM cs_mobile_pairings WHERE device_hash=? FOR UPDATE',[hash('sha256',$secret)])->getRowArray();
            if (!$row || $row['expires_at']<=gmdate('Y-m-d H:i:s') || $row['state']==='consumed') throw new DomainError('Pairing unavailable or expired.',400);
            if ($row['state']==='pending') throw new DomainError('AUTHORIZATION_PENDING',428);
            if ($row['state']!=='approved') throw new DomainError('Pairing unavailable.',400);
            new Workspace((int)$row['user_id'],null,$this->db);
            $user=model(UserModel::class)->findById($row['user_id']);
            if (!$user || $user->isBanned()) throw new DomainError('Account unavailable.',403);
            $token=$user->generateAccessToken('mobile:'.$row['device_name'],['mobile:v1'],Time::now()->addSeconds(900));
            $this->db->table('cs_mobile_pairings')->where('id',$row['id'])->update(['state'=>'consumed']);
            if (!$this->db->transStatus()) throw new \RuntimeException('Pairing transaction failed.');
            $this->db->transCommit();
            return ['access_token'=>$token->raw_token,'token_type'=>'Bearer','expires_in'=>900];
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }
}
