<?php
namespace App\Controllers;
use App\Services\{Workspace,DomainError,TrialBillingService,AgencyAccessService,SubscriptionBillingService};
use App\Libraries\{SubscriptionPricing,SubscriptionInvoicePdf};

class BillingApiController extends ApiController
{
    private function runBilling(string $agency,callable $work)
    {
        try{
            $w=new Workspace($this->actorId(),$agency,null,true);
            if($this->request->getMethod()!=='GET'){
                $this->checkWriteWorkspace($agency);
                if(!service('throttler')->check('billing_api_'.$w->userId,20,60))throw new DomainError('Too many billing requests.',429);
            }
            return $this->response->setHeader('Cache-Control','no-store, private')->setJSON(['data'=>$work($w)]);
        }catch(DomainError $e){return $this->response->setHeader('Cache-Control','no-store')->setStatusCode($e->status)->setJSON(['error'=>['code'=>$e->status===402?'AGENCY_LOCKED':'BILLING_'.$e->status,'message'=>$e->getMessage()]]);}
        catch(\Throwable $e){log_message('error','Billing API error: {type}',['type'=>get_class($e)]);return $this->response->setStatusCode(503)->setJSON(['error'=>['code'=>'BILLING_UNAVAILABLE','message'=>'Billing is unavailable. No access change was made.']]);}
    }
    private function body(array $allowed): array
    {
        $d=$this->request->getJSON(true);
        if(!is_array($d)||array_diff(array_keys($d),$allowed))throw new DomainError('Unsupported billing fields.');
        return $d;
    }
    public function agencies()
    {
        $w=new Workspace($this->actorId());$rows=$w->db->table('cs_memberships m')->select('a.ref,a.name,m.role')->join('cs_agencies a','a.id=m.agency_id')->where(['m.user_id'=>$w->userId,'m.state'=>'active'])->get()->getResultArray();
        foreach($rows as &$row)$row['access']=(new AgencyAccessService(new Workspace($w->userId,$row['ref'],null,true)))->status();
        // Non-owners never receive card details, invoice refs or payment dates.
        foreach($rows as &$row)$row['access']=array_intersect_key($row['access'],array_flip(['state','locked','owner']));
        return $this->response->setHeader('Cache-Control','no-store, private')->setJSON(['data'=>$rows]);
    }
    public function status(string $agency){return $this->runBilling($agency,fn($w)=>(new TrialBillingService($w))->status());}
    public function catalog(string $agency){return $this->runBilling($agency,function($w){$w->requireBilling();return SubscriptionPricing::catalog();});}
    public function quote(string $agency){return $this->runBilling($agency,function($w){$invoice=(new SubscriptionBillingService($w))->issue($this->body(['modules','service_level','worker_count','request_key']));return ['ref'=>$invoice['ref'],'invoice_no'=>$invoice['invoice_no'],'currency'=>$invoice['currency'],'total_cents'=>(int)$invoice['total_cents'],'state'=>$invoice['state']];});}
    public function checkout(string $agency,string $purpose){return $this->runBilling($agency,fn($w)=>(new TrialBillingService($w))->begin($purpose,$this->body(['request_key','confirm','invoice_ref'])));}
    public function complete(string $agency,string $ref){return $this->runBilling($agency,fn($w)=>(new TrialBillingService($w))->complete($ref));}
    public function download(string $agency,string $ref)
    {
        try{$invoice=(new SubscriptionBillingService(new Workspace($this->actorId(),$agency,null,true)))->get($ref);return $this->response->setHeader('Cache-Control','no-store, private')->setContentType('application/pdf')->setHeader('Content-Disposition','attachment; filename="'.$invoice['invoice_no'].'.pdf"')->setBody(SubscriptionInvoicePdf::render($invoice));}
        catch(DomainError $e){return $this->response->setStatusCode($e->status)->setJSON(['error'=>['code'=>'BILLING_'.$e->status,'message'=>$e->getMessage()]]);}
    }
}
