<?php
namespace App\Controllers;
use App\Services\{Workspace,DomainError};

class BillingPolicyController extends BaseController
{
    private function operator(): Workspace
    {
        $w=new Workspace((int)auth()->id());
        if(!$w->operator)throw new DomainError('Platform administrator access required.',403);
        return $w;
    }
    public function index()
    {
        try{$w=$this->operator();$policy=$w->db->table('cs_billing_policy')->where('id',1)->get()->getRowArray();return $this->response->setHeader('Cache-Control','no-store')->setBody(view('caresys/billing_policy',compact('policy')));}
        catch(DomainError $e){return $this->response->setStatusCode($e->status)->setBody(view('caresys/error',['message'=>$e->getMessage(),'status'=>$e->status]));}
    }
    public function save()
    {
        try{$w=$this->operator();$days=$this->request->getPost('trial_days');$version=$this->request->getPost('version');
            if(!is_string($days)||!preg_match('/^[0-9]{1,3}$/D',$days)||(int)$days<1||(int)$days>365)throw new DomainError('Choose 1 to 365 free days.');
            $w->transaction(function()use($w,$days,$version){
                $policy=$w->db->query('SELECT * FROM cs_billing_policy WHERE id=1 FOR UPDATE')->getRowArray();
                if((string)$policy['version']!==$version)throw new DomainError('Policy changed. Refresh before saving.',409);
                $w->db->table('cs_billing_policy')->where('id',1)->update(['trial_days'=>(int)$days,'version'=>$policy['version']+1,'updated_at'=>gmdate('Y-m-d H:i:s')]);
                $w->audit('billing.trial_policy_changed',null,null,'Free days '.$policy['trial_days'].' -> '.$days.'; future trials only');
            });return redirect()->to('/app/billing-policy')->with('message','Trial policy saved for future trials. Existing trial end dates are unchanged.');
        }catch(DomainError $e){return redirect()->to('/app/billing-policy')->with('error',$e->getMessage());}
    }
}
