<?php
namespace App\Controllers;
use App\Services\{Workspace,DomainError,TrialBillingService,SubscriptionBillingService};

class BillingController extends BaseController
{
    public function index(string $agencyRef)
    {
        try{
            $w=new Workspace((int)auth()->id(),$agencyRef,null,true);$billing=new TrialBillingService($w);$status=$billing->status();
            $scope=$agencyRef;
            if(session('workspace_scope')!==$scope)session()->set(['workspace_scope'=>$scope,'workspace_nonce'=>bin2hex(random_bytes(24))]);
            $agency=$w->agency;$base='/agency/'.$agencyRef;$nonce=session('workspace_nonce');
            $subscription_invoices=$status['invoices']??[];
            $active_worker_count=$status['owner']?(int)$w->table('employments')->select('COUNT(DISTINCT person_id) AS total',false)->where('state','active')->get()->getRow()->total:0;
            return $this->response->setHeader('Cache-Control','no-store, private')->setBody(view('caresys/billing',compact('w','status','agency','base','nonce','subscription_invoices','active_worker_count')));
        }catch(DomainError $e){return $this->response->setStatusCode($e->status)->setHeader('Cache-Control','no-store')->setBody(view('caresys/error',['message'=>$e->getMessage(),'status'=>$e->status]));}
    }

    public function action(string $agencyRef,string $action)
    {
        $target='/agency/'.$agencyRef.'/billing';
        try{
            if(session('workspace_scope')!==$agencyRef||!hash_equals((string)session('workspace_nonce'),(string)$this->request->getPost('workspace_nonce')))throw new DomainError('Workspace changed. Refresh billing.',409);
            $w=new Workspace((int)auth()->id(),$agencyRef,null,true);$w->requireBilling();
            if(!service('throttler')->check('billing_web_'.$w->userId,20,60))throw new DomainError('Too many billing requests. Try again shortly.',429);
            $data=$this->request->getPost();
            $allowed=match($action){'quote'=>['modules','service_level','worker_count','request_key'],'setup','payment'=>['request_key','confirm','invoice_ref'],'test-setup','test-expire','test-payment'=>['confirm','invoice_ref'],'complete'=>['ref'],'policy'=>['trial_days','version'],default=>throw new DomainError('Billing action unavailable.',404)};
            if(array_diff(array_keys($data),array_merge($allowed,['workspace_nonce','csrf_test_name','return_page'])))throw new DomainError('Unsupported billing fields.');
            if(str_starts_with($action,'test-')){(new \App\Services\LocalBillingSimulator($w))->run($action,$data);return redirect()->to($target)->with('message','Simulated billing action completed. No card saved and no money collected.');}
            $billing=new TrialBillingService($w);
            if($action==='quote'){(new SubscriptionBillingService($w))->issue($data);return redirect()->to($target)->with('message','Invoice issued. Your free trial and access status are shown below.');}
            if($action==='complete'){$billing->complete(Workspace::text($data,'ref',32));return redirect()->to($target)->with('message','Stripe verification completed. Your current access status is shown below.');}
            if(in_array($action,['setup','payment'],true)){$result=$billing->begin($action,$data);return redirect()->to($result['url']);}
            throw new DomainError('Action unavailable.',404);
        }catch(DomainError $e){return redirect()->to($target)->with('error',$e->getMessage());}
        catch(\Throwable $e){log_message('error','Billing action failed: {type}',['type'=>get_class($e)]);return redirect()->to($target)->with('error','Billing could not complete. Refresh and retry.');}
    }
}
