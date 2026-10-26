<?php
namespace App\Services;

use App\Libraries\{SubscriptionQuote,SubscriptionPricing};

class SubscriptionBillingService
{
    public function __construct(private Workspace $w){}

    public function issue(array $data): array
    {
        return $this->w->transaction(function()use($data){
            $this->w->require('settings');
            $selection=SubscriptionQuote::selection($data);
            $key=$data['request_key']??'';
            if(!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainError('Refresh the invoice form and submit again.');
            $hash=hash('sha256',FinanceService::canonical($selection));
            $existing=$this->w->table('subscription_invoices')->where('request_key',$key)->get()->getRowArray();
            if($existing){
                if(!hash_equals($existing['request_hash'],$hash))throw new DomainError('This request already issued an invoice for different selections. Refresh to start a new request.',409);
                return $existing;
            }
            $active=(int)$this->w->table('employments')->select('COUNT(DISTINCT person_id) AS total',false)->where('state','active')->get()->getRow()->total;
            if($selection['worker_count']<$active)throw new DomainError('Choose a worker count covering at least your '.$active.' active workers.');
            $snapshot=SubscriptionPricing::calculate($selection);
            $snapshot['module_details']=array_map(static fn($key)=>['key'=>$key,'label'=>SubscriptionQuote::MODULES[$key],'description'=>SubscriptionQuote::DESCRIPTIONS[$key]],$snapshot['effective_modules']);
            $snapshot['agency']=['ref'=>$this->w->agency['ref'],'name'=>$this->w->agency['name'],'country'=>$this->w->agency['country']];
            $snapshot['issued_at']=gmdate('Y-m-d H:i:s');
            $snapshot['valid_until']=gmdate('Y-m-d',time()+30*86400);
            $ref=bin2hex(random_bytes(16));
            $invoice=$this->w->insert('subscription_invoices',['ref'=>$ref,'agency_id'=>$this->w->agency['id'],'created_by'=>$this->w->userId,'invoice_no'=>'CSI-'.gmdate('Ymd').'-'.strtoupper(substr($ref,0,12)),'request_key'=>$key,'request_hash'=>$hash,'currency'=>'USD','total_cents'=>$snapshot['total_cents'],'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $this->w->audit('subscription.change_requested',$invoice['ref'],null,json_encode($selection,JSON_THROW_ON_ERROR));
            $this->w->audit('subscription.invoice_issued',$invoice['ref'],null,$invoice['invoice_no'].'; USD '.number_format($invoice['total_cents']/100,2,'.','').'; pro forma; access unchanged');
            $this->w->notify('subscription.invoice_issued',$invoice['ref'],$this->w->userId);
            return $invoice;
        });
    }

    public function get(string $ref): array
    {
        $this->w->require('settings');
        return $this->w->record('subscription_invoices',$ref);
    }
}
