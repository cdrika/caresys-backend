<?php
namespace App\Services;

/** Explicit local-only simulator. Never receives card details or contacts Stripe. */
final class LocalBillingSimulator
{
    public function __construct(private Workspace $w){}
    public static function enabled(): bool
    {
        return config('Billing')->localSimulator
            && in_array(ENVIRONMENT,['development','testing'],true)
            && (is_cli() || in_array((string)service('request')->getServer('REMOTE_ADDR'),['127.0.0.1','::1'],true));
    }
    public function run(string $action,array $data): void
    {
        if(!self::enabled())throw new DomainError('Local billing simulator is unavailable.',403);
        $this->w->transaction(function()use($action,$data){
            $this->w->requireBilling();
            if(($data['confirm']??'')!=='yes')throw new DomainError('Confirm this simulated action.');
            $access=new AgencyAccessService($this->w);$access->enroll();
            $row=$this->w->table('agency_subscriptions')->get()->getRowArray();
            if($row['stripe_customer'] || ($row['card_verified_at'] && $row['card_brand']!=='TEST'))throw new DomainError('Simulation cannot change a Stripe billing account.',409);
            if($action==='test-setup'){
                if(!$row['card_verified_at'])$this->w->table('agency_subscriptions')->update(['card_verified_at'=>gmdate('Y-m-d H:i:s'),'card_brand'=>'TEST','card_last4'=>'0000']);
            }elseif($action==='test-expire'){
                if($row['card_brand']!=='TEST'||$access->status()['state']!=='trial')throw new DomainError('Only an active simulated trial can be expired.',409);
                $this->w->table('agency_subscriptions')->update(['trial_ends_at'=>gmdate('Y-m-d H:i:s',time()-1)]);
            }elseif($action==='test-payment'){
                if($row['card_brand']!=='TEST')throw new DomainError('Simulated card setup is required.',409);
                $invoice=(new SubscriptionBillingService($this->w))->get((string)($data['invoice_ref']??''));
                if($row['current_invoice_ref']!==$invoice['ref'])throw new DomainError('Choose the current invoice.',409);
                // Retrying an approved invoice never extends its access period.
                if($invoice['state']==='paid')return;
                if($access->status()['state']!=='payment_required'||$invoice['state']!=='issued')throw new DomainError('Payment approval is available only after the trial ends.',409);
                $snapshot=json_decode($invoice['snapshot'],true);
                if($snapshot['valid_until']<gmdate('Y-m-d'))throw new DomainError('Request a fresh invoice before approval.',409);
                $this->w->table('agency_subscriptions')->update(['state'=>'paid','paid_until'=>gmdate('Y-m-d H:i:s',time()+30*86400)]);
                $this->w->db->table('cs_agencies')->where('id',$this->w->agency['id'])->update(['subscription'=>'active','modules'=>json_encode($snapshot['effective_modules'])]);
                $this->w->table('subscription_invoices')->where('id',$invoice['id'])->update(['state'=>'paid']);
            }else throw new DomainError('Unknown simulated billing action.',404);
            $this->w->audit('billing.simulated_'.$action,$this->w->agency['ref'],null,'LOCAL TEST ONLY; no card saved and no money collected');
        });
    }
}
