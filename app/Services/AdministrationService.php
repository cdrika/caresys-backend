<?php
namespace App\Services;

class AdministrationService
{
    public function __construct(public Workspace $w) {}

    public function agency(array $d): array
    {
        return $this->w->transaction(function()use($d){
            $country=strtoupper(Workspace::text($d,'country',2));$currency=strtoupper(Workspace::text($d,'currency',3));$tz=Workspace::text($d,'timezone',64);
            if(!preg_match('/^[A-Z]{2}$/D',$country) || !preg_match('/^[A-Z]{3}$/D',$currency) || !in_array($tz,\DateTimeZone::listIdentifiers(),true)) throw new DomainError('Check country, currency and IANA timezone.');
            $r=$this->w->insert('agencies',['name'=>Workspace::text($d,'name',180),'country'=>$country,'currency'=>$currency,'timezone'=>$tz,'modules'=>'[]']);
            $this->w->insert('memberships',['agency_id'=>$r['id'],'user_id'=>$this->w->userId,'role'=>'owner']);
            $this->w->db->table('cs_agency_subscriptions')->insert(['agency_id'=>$r['id']]);
            $this->w->audit('agency.registered',$r['ref']);return $r;
        });
    }

    public function member(array $d): void
    {
        $this->w->transaction(function()use($d){
            $this->w->require('settings');if($this->w->membership['role']!=='owner') throw new DomainError('Agency owner permission required.',403);
            $email=Workspace::text($d,'email',254);$role=Workspace::text($d,'role',30);
            if(!in_array($role,['owner','recruiter','scheduler','supervisor','finance'],true)) throw new DomainError('Caregiver membership comes from authorized employment. Choose an administrative role.');
            $identity=$this->w->db->table('auth_identities')->where(['type'=>'email_password','secret'=>$email])->get()->getRowArray();
            if(!$identity) throw new DomainError('Ask this person to create an account first.');
            $member=$this->w->table('memberships')->where('user_id',$identity['user_id'])->get()->getRowArray();
            if($member && (int)$member['user_id']===$this->w->userId) throw new DomainError('Another owner must manage your role.',409);
            if($member) $this->w->update('memberships',$member,['role'=>$role,'state'=>'active']);
            else $this->w->insert('memberships',['agency_id'=>$this->w->agency['id'],'user_id'=>$identity['user_id'],'role'=>$role]);
            $this->w->audit('membership.granted',null,null,'Administrative role: '.$role);
        });
    }

    public function verifyAgency(array $d): void
    {
        if(!$this->w->operator) throw new DomainError('Platform operator access required.',403);
        $this->w->transaction(function()use($d){
            $a=$this->w->db->query('SELECT * FROM cs_agencies WHERE ref=? FOR UPDATE',[$d['ref']??''])->getRowArray();
            if(!$a) throw new DomainError('Agency unavailable.',404);
            $state=$d['verification']??'';if(!in_array($state,['verified','rejected','suspended'],true)) throw new DomainError('Invalid verification decision.');
            $basis=Workspace::text($d,'basis',300);
            $this->w->update('agencies',$a,['verification'=>$state],(int)$d['version']);$this->w->audit('agency.'.$state,$a['ref'],null,$basis);
        });
    }

    public function disputeResolution(array $d): void
    {
        if(!$this->w->operator) throw new DomainError('Platform dispute reviewer access required.',403);
        $this->w->transaction(function()use($d){
            $r=$this->w->db->query('SELECT * FROM cs_disputes WHERE ref=? FOR UPDATE',[$d['ref']??''])->getRowArray();
            if(!$r) throw new DomainError('Dispute unavailable.',404);
            $state=$d['state']??'';if(!in_array($state,['awaiting_source','under_review','resolved','appealed'],true)) throw new DomainError('Invalid review stage.');
            $resolution=Workspace::text($d,'resolution',3000);$this->w->update('disputes',$r,['state'=>$state,'resolution'=>$resolution],(int)$d['version']);
            // Resolution never quietly turns a disputed source fact into verified evidence.
            $this->w->audit('dispute.'.$state,$r['ref'],(int)$r['person_id']);$this->w->notify('dispute.review_updated',$r['ref']);
        });
    }

    public function subscriptionRequest(array $d): array
    {
        return (new SubscriptionBillingService($this->w))->issue($d);
    }

    public function entitlement(array $d):void
    {
        if(!$this->w->operator)throw new DomainError('Platform commercial administration required.',403);
        $this->w->transaction(function()use($d){
            $a=$this->w->db->query('SELECT * FROM cs_agencies WHERE ref=? FOR UPDATE',[$d['ref']??''])->getRowArray();if(!$a)throw new DomainError('Agency unavailable.',404);
            $modules=array_values(array_unique(array_filter(array_map('trim',explode(',',Workspace::text($d,'modules',500))))));$catalog=['recruitment','history','employment','people','scheduling','time','leave','care','assets','expenses','payroll','finance','reporting'];if(array_diff($modules,$catalog))throw new DomainError('Choose known module keys only.');
            foreach(['history'=>['recruitment'],'scheduling'=>['people','care'],'payroll'=>['people','time']] as $module=>$dependencies)if(in_array($module,$modules,true)&&array_diff($dependencies,$modules))throw new DomainError('Module dependencies are missing for '.$module.'.');
            $until=Workspace::date($d['valid_until']??'');if($until<gmdate('Y-m-d'))throw new DomainError('Use a current agreement end date.');
            $basis=Workspace::text($d,'basis',500);$state=$d['state']??'';if(!in_array($state,['active','grace','restricted','cancelled'],true))throw new DomainError('Choose an agreement state.');
            $this->w->insert('entitlement_events',['agency_id'=>$a['id'],'actor_id'=>$this->w->userId,'modules'=>json_encode($modules),'state'=>$state,'valid_until'=>$until,'basis'=>$basis]);
            $this->w->update('agencies',$a,['modules'=>json_encode($modules),'subscription'=>$state,'entitlement_until'=>$until,'agreement_basis'=>$basis],(int)$d['version']);$this->w->audit('subscription.manual_agreement',$a['ref'],null,'Audited commercial agreement; no provider payment claimed');
        });
    }
}
