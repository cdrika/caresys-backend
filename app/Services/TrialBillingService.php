<?php
namespace App\Services;

final class TrialBillingService
{
    private StripeGateway $stripe;
    public function __construct(private Workspace $w,?StripeGateway $stripe=null){$this->stripe=$stripe??new StripeGateway();}

    public function status(): array
    {
        $s=(new AgencyAccessService($this->w))->status();
        if(!$s['owner'])return ['state'=>$s['state'],'locked'=>$s['locked'],'owner'=>false,'notice'=>$s['locked']?'This agency account is locked. Contact your agency owner.':'Agency access is available.'];
        $this->w->requireBilling();
        $s['stripe_configured']=$this->stripe->configured();
        $s['notice']='No subscription charge is made during your free trial. After it ends, agency access is locked until an owner approves and completes payment.';
        $s['invoices']=$this->w->table('subscription_invoices')->select('ref,invoice_no,currency,total_cents,state,created_at')->orderBy('id','DESC')->limit(50)->get()->getResultArray();
        if($s['invoice_ref']??null){$invoice=$this->w->table('subscription_invoices')->where('ref',$s['invoice_ref'])->get()->getRowArray();if($invoice){$snapshot=json_decode($invoice['snapshot'],true);$s['current_selection']=['modules'=>$snapshot['requested_modules'],'service_level'=>$snapshot['preferred_package'],'worker_count'=>$snapshot['worker_count']];}}
        return $s;
    }

    public function begin(string $purpose,array $data): array
    {
        return $this->w->transaction(function()use($purpose,$data){
            $this->w->requireBilling();
            if(!in_array($purpose,['setup','payment'],true))throw new DomainError('Invalid checkout purpose.');
            $key=$data['request_key']??'';
            if(!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainError('Refresh the billing form.');
            if(($data['confirm']??'')!=='yes')throw new DomainError('Confirm the displayed card or payment terms.');
            $access=new AgencyAccessService($this->w);$access->enroll();
            $row=$this->w->table('agency_subscriptions')->get()->getRowArray();$state=$access->status();
            if($purpose==='payment' && ($state['state']!=='payment_required'||!$row['card_verified_at']))throw new DomainError('Payment is available only after the trial or paid period ends. No trial charge is permitted.',409);
            $invoice=null;
            if($purpose==='payment'){
                $invoice=(new SubscriptionBillingService($this->w))->get((string)($data['invoice_ref']??''));
                if($invoice['ref']!==$row['current_invoice_ref']||$invoice['state']!=='issued')throw new DomainError('Review and request a current invoice before approving payment.',409);
                $snapshot=json_decode($invoice['snapshot'],true);
                if($snapshot['valid_until']<gmdate('Y-m-d'))throw new DomainError('This proposal has expired. Request a new invoice before paying.',409);
            }
            $existing=$this->w->table('billing_checkouts')->where('request_key',$key)->get()->getRowArray();
            if($existing){
                if($existing['purpose']!==$purpose||$existing['invoice_ref']!==($invoice['ref']??null))throw new DomainError('Request key already used for another checkout.',409);
                return $this->present($existing);
            }
            if(!$row['stripe_customer']){
                $customer=$this->stripe->request('POST','customers',['name'=>$this->w->agency['name'],'metadata'=>['agency_ref'=>$this->w->agency['ref']]],'caresys-customer-'.$this->w->agency['ref']);
                $row['stripe_customer']=$customer['id'];$this->w->table('agency_subscriptions')->update(['stripe_customer'=>$customer['id']]);
            }
            if($purpose==='payment'){
                $pending=$this->w->table('billing_checkouts')->where(['purpose'=>'payment','state'=>'pending'])->get()->getRowArray();
                if($pending){
                    $prior=$this->stripe->request('GET','checkout/sessions/'.rawurlencode($pending['stripe_session']));
                    if(($prior['status']??'')!=='expired'){
                        if($pending['invoice_ref']!==$invoice['ref'])throw new DomainError('A previous invoice checkout is pending. Complete it or let it expire before another payment.',409);
                        return $this->present($pending);
                    }
                    $this->w->table('billing_checkouts')->where('id',$pending['id'])->update(['state'=>'expired']);
                }
            }
            $ref=bin2hex(random_bytes(16));
            $base=rtrim(config('Billing')->webBaseURL,'/').'/agency/'.$this->w->agency['ref'].'/billing';
            if(!str_starts_with($base,'https://'))throw new DomainError('Billing requires an HTTPS return URL.',503);
            $payload=['mode'=>$purpose,'customer'=>$row['stripe_customer'],'payment_method_types'=>['card'],
                'client_reference_id'=>$ref,'metadata'=>['agency_ref'=>$this->w->agency['ref'],'checkout_ref'=>$ref],
                'success_url'=>$base.'?checkout='.$ref,'cancel_url'=>$base,
                'custom_text'=>['submit'=>['message'=>$purpose==='setup'?'Save your card only. No subscription charge during the free trial. Payment requires owner approval after the trial.':'Approve this payment for 30 days of agency access. No automatic renewal.']]];
            if($purpose==='setup'){$payload['currency']='usd';}
            else{
                $payload['line_items']=[['price_data'=>['currency'=>'usd','unit_amount'=>(int)$invoice['total_cents'],'product_data'=>['name'=>'CareSys - '.$invoice['invoice_no'].' - 30 days agency access']],'quantity'=>1]];
                $payload['automatic_tax']=['enabled'=>config('Billing')->automaticTax?'true':'false'];
                $payload['billing_address_collection']='required';
            }
            // Use a stable local reference across provider retries (Stripe idempotency replays the original result).
            $payload['client_reference_id']=$this->w->agency['ref'].'-'.$key;
            $payload['metadata']['checkout_ref']=$key;
            $payload['success_url']=$base.'?checkout_key='.$key;
            $remote=$this->stripe->request('POST','checkout/sessions',$payload,'caresys-'.$this->w->agency['ref'].'-'.$key);
            if(!preg_match('~^https://checkout\.stripe\.com/~',$remote['url']??''))throw new DomainError('Unexpected Stripe checkout URL.',502);
            $checkout=$this->w->insert('billing_checkouts',['ref'=>$ref,'agency_id'=>$this->w->agency['id'],'created_by'=>$this->w->userId,'request_key'=>$key,'purpose'=>$purpose,'invoice_ref'=>$invoice['ref']??null,'stripe_session'=>$remote['id'],'checkout_url'=>$remote['url']]);
            $this->w->audit('billing.'.$purpose.'_approved',$checkout['ref'],null,$invoice?$invoice['invoice_no'].'; USD '.number_format($invoice['total_cents']/100,2):'Card setup only; no charge');
            return $this->present($checkout);
        });
    }

    public function complete(string $ref): array
    {
        return $this->w->transaction(function()use($ref){
            $this->w->requireBilling();$checkout=$this->w->record('billing_checkouts',$ref,true);
            if($checkout['state']==='completed')return $this->status();
            $remote=$this->stripe->request('GET','checkout/sessions/'.rawurlencode($checkout['stripe_session']).'?expand[]=setup_intent.payment_method');
            $row=$this->w->table('agency_subscriptions')->get()->getRowArray();
            if(($remote['status']??'')!=='complete')throw new DomainError('Checkout is not complete. Agency access remains unchanged.',409);
            if(($remote['mode']??'')!==$checkout['purpose']||($remote['customer']??'')!==$row['stripe_customer']||($remote['client_reference_id']??'')!==$this->w->agency['ref'].'-'.$checkout['request_key']||($remote['metadata']['agency_ref']??'')!==$this->w->agency['ref'])throw new DomainError('Checkout verification failed.',409);
            if($checkout['purpose']==='setup'){
                $intent=$remote['setup_intent']??[];$card=$intent['payment_method']['card']??null;
                if(($intent['status']??'')!=='succeeded'||!$card||($intent['payment_method']['type']??'')!=='card')throw new DomainError('Card setup is not verified.',409);
                $this->w->table('agency_subscriptions')->update(['card_verified_at'=>gmdate('Y-m-d H:i:s'),'card_brand'=>$card['brand'],'card_last4'=>$card['last4']]);
            }else{
                $invoice=(new SubscriptionBillingService($this->w))->get($checkout['invoice_ref']);
                if(($remote['payment_status']??'')!=='paid'||($remote['currency']??'')!=='usd'||(int)($remote['amount_subtotal']??-1)!==(int)$invoice['total_cents'])throw new DomainError('Payment has not been verified for this invoice.',409);
                // One invoice buys one period even when multiple checkout returns/webhooks race.
                if($invoice['state']!=='paid'){
                    $s=json_decode($invoice['snapshot'],true);
                    $this->w->table('agency_subscriptions')->update(['state'=>'paid','paid_until'=>gmdate('Y-m-d H:i:s',time()+30*86400),'current_invoice_ref'=>$invoice['ref']]);
                    $this->w->db->table('cs_agencies')->where('id',$this->w->agency['id'])->update(['subscription'=>'active','modules'=>json_encode($s['effective_modules'])]);
                    $this->w->table('subscription_invoices')->where('id',$invoice['id'])->update(['state'=>'paid']);
                }
                $this->w->table('billing_checkouts')->where('id',$checkout['id'])->update(['paid_total_cents'=>(int)$remote['amount_total']]);
            }
            $this->w->table('billing_checkouts')->where('id',$checkout['id'])->update(['state'=>'completed']);
            $this->w->audit('billing.'.$checkout['purpose'].'_verified',$checkout['ref']);
            return $this->status();
        });
    }

    private function present(array $row): array{return ['ref'=>$row['ref'],'purpose'=>$row['purpose'],'url'=>$row['checkout_url'],'state'=>$row['state']];}
}
