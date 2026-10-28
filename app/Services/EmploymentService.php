<?php
namespace App\Services;

class EmploymentService
{
    public function __construct(public Workspace $w) {}

    public function stepUp(array $d): void
    {
        $user=model(\CodeIgniter\Shield\Models\UserModel::class)->findById($this->w->userId);
        $verified=service('passwords')->verify(Workspace::text($d,'password',255),$user->password_hash);
        if(!$verified) throw new DomainError('Password confirmation failed.',403);
    }

    public function issue(array $d): array
    {
        return $this->w->transaction(function()use($d){
            $this->w->require('employment');
            $app=$this->w->record('applications',$d['application_ref']??'',true);
            if(in_array($app['stage'],['withdrawn','declined','cancelled','employed','unsuccessful'],true)) throw new DomainError('The application cannot receive an offer.',409);
            $this->w->db->query('SELECT id FROM cs_people WHERE id=? FOR UPDATE',[$app['person_id']]);
            $old=$this->w->table('contracts')->where('application_id',$app['id'])->orderBy('revision','DESC')->get()->getRowArray();
            if($old) {
                $join=$this->w->db->query('SELECT * FROM cs_joining WHERE contract_id=? FOR UPDATE',[$old['id']])->getRowArray();
                if($join && $join['state']==='activated') throw new DomainError('Use effective-dated employment changes after activation.',409);
                $this->w->update('contracts',$old,['state'=>'superseded']);
                if($join) $this->w->update('joining',$join,['state'=>'cancelled']);
            }
            $v=['agency_id'=>$this->w->agency['id'],'person_id'=>$app['person_id'],'application_id'=>$app['id'],'revision'=>$old?(int)$old['revision']+1:1];
            foreach(['role'=>100,'work_area'=>160,'work_type'=>40,'pay_basis'=>80,'hours_terms'=>200,'terms'=>12000] as $field=>$max) $v[$field]=Workspace::text($d,$field,$max);
            $v['start_date']=Workspace::date($d['start_date']??'');
            $expiry=Workspace::date($d['expiry_date']??'');if($expiry<gmdate('Y-m-d')) throw new DomainError('Offer expiry must be today or later.');
            $v['expires_at']=$expiry.' 23:59:59';$v['digest']=hash('sha256',json_encode($v,JSON_UNESCAPED_UNICODE));
            $r=$this->w->insert('contracts',$v);$this->w->update('applications',$app,['stage'=>'offer_issued']);
            $this->w->insert('joining',['agency_id'=>$v['agency_id'],'person_id'=>$v['person_id'],'contract_id'=>$r['id']]);
            $this->w->audit('contract.issued',$r['ref'],(int)$v['person_id']);$this->w->notify('contract.issued',$r['ref']);return $r;
        });
    }

    public function contractDecision(array $d): void
    {
        $this->stepUp($d);
        $this->w->transaction(function()use($d){
            if($this->w->agency){$this->w->require('employment');$c=$this->w->record('contracts',$d['ref'],true);}
            else {$c=$this->w->db->query('SELECT * FROM cs_contracts WHERE ref=? AND person_id=? FOR UPDATE',[$d['ref'],$this->w->ownPerson()['id']])->getRowArray();}
            if(!$c) throw new DomainError('Contract unavailable.',404);
            if($c['state']!=='issued' || strtotime($c['expires_at'].' UTC')<time()) throw new DomainError('This contract is superseded, closed or expired.',409);
            if(!hash_equals($c['digest'],(string)($d['digest']??''))) throw new DomainError('The displayed contract changed. Review again.',409);
            $this->w->db->query('SELECT id FROM cs_people WHERE id=? FOR UPDATE',[$c['person_id']]);
            $j=$this->w->db->query('SELECT * FROM cs_joining WHERE contract_id=? FOR UPDATE',[$c['id']])->getRowArray();
            $decision=$d['decision']??'accept';
            if(!$this->w->agency && $decision==='decline') {
                $this->w->update('contracts',$c,['state'=>'declined'],(int)$d['version']);$this->w->update('joining',$j,['state'=>'declined']);
            } else {
                if($decision!=='accept' || ($d['confirm']??'')!=='yes') throw new DomainError('Explicit agreement to the displayed version is required.');
                $v=$this->w->agency?['agency_accepted_at'=>gmdate('Y-m-d H:i:s'),'agency_signer'=>$this->w->userId]:['person_accepted_at'=>gmdate('Y-m-d H:i:s')];
                $this->w->update('contracts',$c,$v,(int)$d['version']);
            }
            $this->w->audit('contract.'.$decision,$c['ref'],(int)$c['person_id'],'Version-specific recorded acceptance; not provider-certified signature');
        });
    }

    public function joiningDecision(array $d): void
    {
        $this->stepUp($d);
        $this->w->transaction(function()use($d){
            $p=$this->w->ownPerson();$this->w->db->query('SELECT id FROM cs_people WHERE id=? FOR UPDATE',[$p['id']]);
            $j=$this->w->db->query('SELECT * FROM cs_joining WHERE ref=? AND person_id=? FOR UPDATE',[$d['ref']??'',$p['id']])->getRowArray();
            if(!$j) throw new DomainError('Joining request unavailable.',404);
            if(in_array($j['state'],['activated','cancelled','declined','withdrawn'],true)) throw new DomainError('This request is closed. Active employment uses offboarding.',409);
            $c=$this->w->db->table('cs_contracts')->where('id',$j['contract_id'])->get()->getRowArray();
            $decision=$d['decision']??'';
            if(!in_array($decision,['authorize','decline','withdraw'],true)) throw new DomainError('Choose a joining decision.');
            if($decision==='authorize' && (!$c['agency_accepted_at'] || !$c['person_accepted_at'] || $c['state']!=='issued' || ($d['confirm']??'')!=='yes')) throw new DomainError('Both parties must agree to this contract before joining authorization.');
            $this->w->update('joining',$j,['state'=>['authorize'=>'authorized','decline'=>'declined','withdraw'=>'withdrawn'][$decision],'person_authorized_at'=>$decision==='authorize'?gmdate('Y-m-d H:i:s'):null],(int)$d['version']);
            $this->w->audit('joining.'.$decision,$j['ref'],(int)$p['id']);
        });
    }

    public function conditions(array $d): void
    {
        $this->w->transaction(function()use($d){
            $this->w->require('employment');$j=$this->w->record('joining',$d['ref'],true);
            if(in_array($j['state'],['activated','cancelled','withdrawn','declined'],true)) throw new DomainError('This joining process is closed.',409);
            $note=Workspace::text($d,'conditions_note',500);
            if(($d['confirm']??'')!=='yes') throw new DomainError('Confirm that the agency reviewed its required conditions.');
            $this->w->update('joining',$j,['conditions'=>'complete','conditions_note'=>$note],(int)$d['version']);$this->w->audit('joining.conditions_confirmed',$j['ref'],(int)$j['person_id']);
        });
    }

    public function activate(array $d): array
    {
        return $this->w->transaction(function()use($d){
            $this->w->require('employment');$j=$this->w->record('joining',$d['ref'],false);
            $p=$this->w->db->query('SELECT * FROM cs_people WHERE id=? FOR UPDATE',[$j['person_id']])->getRowArray();
            $j=$this->w->record('joining',$d['ref'],true);
            $key=Workspace::text($d,'request_key',80);$digest=hash('sha256',$j['ref']);
            $previous=$this->w->table('idempotency')->where(['actor_id'=>$this->w->userId,'operation'=>'activate','request_key'=>$key])->get()->getRowArray();
            if($previous && $previous['digest']!==$digest) throw new DomainError('This request key was used for another action.',409);
            if($j['state']==='activated') return $this->w->table('employments')->where('joining_id',$j['id'])->get()->getRowArray();
            if((int)$j['version']!==(int)($d['version']??0)) throw new DomainError('Joining state changed. Refresh.',409);
            $c=$this->w->record('contracts',$this->w->db->table('cs_contracts')->where('id',$j['contract_id'])->get()->getRow()->ref,true);
            if($p['identity_status']!=='resolved' || $this->w->agency['verification']!=='verified' || $j['state']!=='authorized' || !$j['person_authorized_at'] || $j['conditions']!=='complete' || !$c['agency_accepted_at'] || !$c['person_accepted_at'] || $c['state']!=='issued') throw new DomainError('Joining is pending: identity, both agreements, individual authorization and conditions are required.',409);
            $signer=$this->w->db->table('cs_memberships')->where(['agency_id'=>$j['agency_id'],'user_id'=>$c['agency_signer'],'state'=>'active'])->get()->getRowArray();
            if(!$signer || !in_array($signer['role'],['owner','recruiter'],true)) throw new DomainError('Agency signing authority requires review.',409);
            $today=(new \DateTimeImmutable('now',new \DateTimeZone($this->w->agency['timezone'])))->format('Y-m-d');
            if($c['start_date']>$today) throw new DomainError('Pending start: the agreed local start date has not arrived.',409);
            $active=$this->w->db->query("SELECT e.agency_id,c.work_type FROM cs_employments e LEFT JOIN cs_joining j ON j.id=e.joining_id LEFT JOIN cs_contracts c ON c.id=j.contract_id WHERE e.person_id=? AND e.state='active' FOR UPDATE",[$p['id']])->getResultArray();
            foreach($active as $prior){
                if((int)$prior['agency_id']===(int)$j['agency_id'])throw new DomainError('This caregiver already has active employment with this agency.',409);
                if($c['work_type']!=='part_time'||$prior['work_type']!=='part_time')throw new DomainError('Concurrent agency employment requires agreed part-time contracts for every active employment. Existing employment is preserved.',409);
            }
            $e=$this->w->insert('employments',['agency_id'=>$j['agency_id'],'person_id'=>$p['id'],'joining_id'=>$j['id'],'employee_no'=>'EMP-'.strtoupper(substr($j['ref'],0,8)),'role'=>$c['role'],'start_date'=>$c['start_date']]);
            $member=$this->w->table('memberships')->where('user_id',$p['user_id'])->get()->getRowArray();
            if(!$member) $this->w->insert('memberships',['agency_id'=>$j['agency_id'],'user_id'=>$p['user_id'],'role'=>'caregiver']);
            elseif($member['state']!=='active') $this->w->update('memberships',$member,['state'=>'active','role'=>'caregiver']);
            $this->w->insert('leave_balances',['agency_id'=>$j['agency_id'],'employment_id'=>$e['id'],'minutes'=>0]);
            $this->w->update('joining',$j,['state'=>'activated']);
            $app=$this->w->table('applications')->where('id',$c['application_id'])->get()->getRowArray();$this->w->update('applications',$app,['stage'=>'employed']);
            $this->w->insert('idempotency',['agency_id'=>$j['agency_id'],'actor_id'=>$this->w->userId,'operation'=>'activate','request_key'=>$key,'digest'=>$digest,'result_ref'=>$e['ref']]);
            $this->w->audit('employment.activated',$e['ref'],(int)$p['id']);$this->w->notify('employment.activated',$e['ref'],(int)$p['user_id']);return $e;
        });
    }

    public function offboard(array $d): void
    {
        $this->w->transaction(function()use($d){
            $this->w->require('people');$e=$this->w->record('employments',$d['ref']);(new ShiftPlanningService($this->w))->lockPerson((int)$e['person_id']);$e=$this->w->record('employments',$d['ref'],true);
            $date=Workspace::date($d['end_date']??'');if($date<$e['start_date'] || $date>gmdate('Y-m-d')) throw new DomainError('Use an actual end date between employment start and today.');
            if($e['state']!=='active') throw new DomainError('Employment has already ended.',409);
            $this->w->update('employments',$e,['state'=>'ended','end_date'=>$date],(int)$d['version']);
            $p=$this->w->db->table('cs_people')->where('id',$e['person_id'])->get()->getRowArray();
            $member=$this->w->table('memberships')->where('user_id',$p['user_id'])->get()->getRowArray();
            if($member && $member['role']==='caregiver') $this->w->update('memberships',$member,['state'=>'ended']);
            $this->w->audit('employment.ended',$e['ref'],(int)$p['id'],'Review future visits, outstanding assets and payroll');$this->w->notify('offboarding.review_required',$e['ref']);
        });
    }
}
