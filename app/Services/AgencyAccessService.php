<?php
namespace App\Services;

final class AgencyAccessService
{
    public function __construct(private Workspace $w){}

    public function status(?int $now=null): array
    {
        $now??=time();
        $row=$this->w->table('agency_subscriptions')->get()->getRowArray();
        if(!$row){
            $active=$this->w->agency['subscription']==='active';
            return ['state'=>$active?'legacy_active':'awaiting_card','locked'=>!$active,'owner'=>$this->w->membership['role']==='owner','trial_days'=>(int)$this->w->db->table('cs_billing_policy')->where('id',1)->get()->getRow()->trial_days];
        }
        if($row['card_brand']==='TEST' && !LocalBillingSimulator::enabled())return ['state'=>'test_billing_disabled','locked'=>true,'owner'=>$this->w->membership['role']==='owner','trial_days'=>(int)($row['trial_days']??30),'card_verified'=>false,'card_brand'=>'TEST'];
        $trialEnd=$row['trial_ends_at']?strtotime($row['trial_ends_at'].' UTC'):0;
        $paidEnd=$row['paid_until']?strtotime($row['paid_until'].' UTC'):0;
        $state=$paidEnd>$now?'paid':($trialEnd>$now?'trial':($trialEnd||$paidEnd?'payment_required':($row['card_verified_at']?'awaiting_modules':'awaiting_card')));
        return ['state'=>$state,'locked'=>!in_array($state,['paid','trial'],true),'owner'=>$this->w->membership['role']==='owner',
            'trial_days'=>$row['trial_days']?(int)$row['trial_days']:(int)$this->w->db->table('cs_billing_policy')->where('id',1)->get()->getRow()->trial_days,
            'trial_started_at'=>$row['trial_started_at'],'trial_ends_at'=>$row['trial_ends_at'],'paid_until'=>$row['paid_until'],
            'card_verified'=>(bool)$row['card_verified_at'],'card_brand'=>$row['card_brand'],'card_last4'=>$row['card_last4'],
            'invoice_ref'=>$row['current_invoice_ref'],'approval_required'=>true];
    }

    public function assertAccess(): void
    {
        $status=$this->status();
        if($status['locked'])throw new DomainError($status['owner']?'AGENCY_PAYMENT_REQUIRED: Set up your trial or approve payment to continue.':'AGENCY_LOCKED: This agency account is locked. Contact your agency owner.',402);
    }

    public function enroll(): void
    {
        if(!$this->w->table('agency_subscriptions')->countAllResults())$this->w->db->table('cs_agency_subscriptions')->insert(['agency_id'=>$this->w->agency['id']]);
    }

    public function selectInvoice(array $invoice): void
    {
        $row=$this->w->table('agency_subscriptions')->get()->getRowArray();
        if(!$row)return; // Existing commercially active agreements retain their entitlements.
        if(!$row['card_verified_at'])throw new DomainError('Save a card securely with Stripe before selecting trial modules.',409);
        $values=['current_invoice_ref'=>$invoice['ref'],'updated_at'=>gmdate('Y-m-d H:i:s')];
        if(!$row['trial_started_at']){
            $days=(int)$this->w->db->table('cs_billing_policy')->where('id',1)->get()->getRow()->trial_days;
            $values+=['state'=>'trial','trial_days'=>$days,'trial_started_at'=>gmdate('Y-m-d H:i:s'),'trial_ends_at'=>gmdate('Y-m-d H:i:s',time()+$days*86400)];
            $this->w->audit('subscription.trial_started',$invoice['ref'],null,'Free days: '.$days.'; no payment collected; owner approval required after trial');
        }
        $this->w->table('agency_subscriptions')->update($values);
        if(($values['state']??'')==='trial'||$this->status()['state']==='trial'){
            $s=json_decode($invoice['snapshot'],true);
            $this->w->db->table('cs_agencies')->where('id',$this->w->agency['id'])->update(['modules'=>json_encode($s['effective_modules']),'subscription'=>'trial']);
        }
    }
}
