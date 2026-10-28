<?php
namespace App\Controllers;

use App\Services\{Workspace,DomainError,RecruitmentService,EmploymentService,OperationsService,FinanceService,ReviewService};

class ApiController extends BaseController
{
    protected function actorId(): int { return (int)auth()->id(); }

    protected function checkWriteWorkspace(?string $agency): void
    {
        if(session('workspace_scope')!==($agency??'personal') || !hash_equals((string)session('workspace_nonce'),$this->request->getHeaderLine('X-Workspace-Nonce')))throw new DomainError('WORKSPACE_CHANGED: refresh the intended workspace.',409);
    }

    private function runAction(?string $agency,callable $work)
    {
        $correlation=bin2hex(random_bytes(8));
        try {
            $w=new Workspace($this->actorId(),$agency);
            if(strtoupper($this->request->getMethod())!=='GET') {
                $this->checkWriteWorkspace($agency);
            }
            return $this->response->setHeader('Cache-Control','no-store, private')->setJSON(['data'=>$this->present($work($w)),'correlation_id'=>$correlation]);
        }catch(DomainError $e){return $this->response->setStatusCode($e->status)->setHeader('Cache-Control','no-store')->setJSON(['error'=>['code'=>'CARESYS_'.$e->status,'message'=>$e->getMessage(),'correlation_id'=>$correlation]]);}
        catch(\Throwable $e){log_message('error','API failure {correlation}: {type}',['correlation'=>$correlation,'type'=>get_class($e)]);return $this->response->setStatusCode(409)->setJSON(['error'=>['code'=>'WORKFLOW_CONFLICT','message'=>'Unable to complete the action. Refresh the current state before retrying.','correlation_id'=>$correlation]]);}
    }

    private function input(array $allowed): array
    {
        $d=$this->request->getJSON(true)??[];
        if(!is_array($d)||array_diff(array_keys($d),$allowed))throw new DomainError('Unsupported writable fields.');
        return $d;
    }

    private function present(mixed $value,string $key=''):mixed
    {
        if(is_array($value)){foreach($value as $k=>&$v)$v=$this->present($v,(string)$k);return $value;}
        if(is_string($value)&&str_ends_with($key,'_at')&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/D',$value))return str_replace(' ','T',$value).'Z';
        return $value;
    }

    public function profile()
    {
        return $this->runAction(null,function($w){$p=$w->ownPerson();return $w->db->table('cs_profiles')->select('ref,version,role,summary,skills,qualifications,work_type,availability,availability_at,visibility,preferences')->where('person_id',$p['id'])->get()->getRowArray();});
    }

    public function updateProfile()
    {
        return $this->runAction(null,function($w){$r=(new RecruitmentService($w))->saveProfile($this->input(['version','role','summary','skills','qualifications','work_type','availability','regions','travel_radius','languages','care_settings']));return ['ref'=>$r['ref'],'version'=>$r['version'],'visibility'=>$r['visibility']];});
    }

    public function publish()
    {
        return $this->runAction(null,function($w){$d=$this->input(['version','visibility','confirm']);(new RecruitmentService($w))->visibility($d);return ['state'=>$d['visibility']];});
    }

    public function candidates(string $agency)
    {
        return $this->runAction($agency,function($w){if(!service('throttler')->check('search_'.$w->userId,30,60))throw new DomainError('Search rate exceeded.',429);$d=$this->request->getGet();if(array_diff(array_keys($d),['purpose','name','region','role','skill','qualification','work_type','page']))throw new DomainError('Unsupported search filters.');return (new RecruitmentService($w))->search($d);});
    }

    public function requestHistory(string $agency)
    {
        return $this->runAction($agency,function($w){$r=(new RecruitmentService($w))->requestHistory($this->input(['person_ref','purpose','days']));return ['ref'=>$r['ref'],'state'=>$r['state'],'version'=>$r['version']];});
    }

    public function decideHistory(string $ref)
    {
        return $this->runAction(null,function($w)use($ref){$d=$this->input(['version','decision','entries','confirm']);$d['ref']=$ref;(new RecruitmentService($w))->historyDecision($d);return ['ref'=>$ref,'decision'=>$d['decision']];});
    }

    public function readHistory(string $agency,string $ref)
    {
        return $this->runAction($agency,fn($w)=>(new RecruitmentService($w))->readHistory($ref));
    }

    public function acceptPersonalContract(string $ref) {return $this->acceptContract(null,$ref);}
    public function acceptAgencyContract(string $agency,string $ref){return $this->acceptContract($agency,$ref);}
    private function acceptContract(?string $agency,string $ref)
    {
        return $this->runAction($agency,function($w)use($ref){$d=$this->input(['version','digest','decision','confirm','password']);$d['ref']=$ref;(new EmploymentService($w))->contractDecision($d);return ['ref'=>$ref,'decision'=>$d['decision']];});
    }

    public function decideJoining(string $ref)
    {
        return $this->runAction(null,function($w)use($ref){$d=$this->input(['version','decision','confirm','password']);$d['ref']=$ref;(new EmploymentService($w))->joiningDecision($d);return ['ref'=>$ref,'decision'=>$d['decision']];});
    }

    public function activate(string $agency,string $ref)
    {
        return $this->runAction($agency,function($w)use($ref){$d=$this->input(['version']);$d['ref']=$ref;$d['request_key']=$this->request->getHeaderLine('Idempotency-Key');$r=(new EmploymentService($w))->activate($d);return ['ref'=>$r['ref'],'state'=>$r['state']];});
    }

    public function operation(string $agency,string $operation,?string $ref=null)
    {
        return $this->runAction($agency,function($w)use($operation,$ref){
            $map=[
                'visits'=>[OperationsService::class,'schedule',['employment_ref','client_ref','starts_at','ends_at','service','required_qualification']],
                'clients'=>[OperationsService::class,'client',['name','location','contact','instructions']],
                'clock-events'=>[OperationsService::class,'clock',['employment_ref','visit_ref','event_key','type']],
                'time-approval'=>[OperationsService::class,'approveTime',['version','state']],
                'leave-requests'=>[OperationsService::class,'leave',['employment_ref','starts_at','ends_at','minutes','type']],
                'leave-decision'=>[OperationsService::class,'leaveDecision',['version','decision']],
                'assets'=>[OperationsService::class,'asset',['tag','name']],
                'asset-custody'=>[OperationsService::class,'custody',['version','decision','employment_ref','condition_note','maintenance']],
                'expenses'=>[OperationsService::class,'expense',['employment_ref','category','amount','incurred_on','description']],
                'expense-decision'=>[OperationsService::class,'expenseDecision',['version','decision','payment_evidence']],
                'notes'=>[OperationsService::class,'note',['visit_ref','body','kind','severity']],
                'payroll-exports'=>[FinanceService::class,'payroll',['period_start','period_end']],
                'invoices'=>[FinanceService::class,'invoice',['client_ref','hourly_rate','invoice_no','due_date','confirm']],
                'receipt'=>[FinanceService::class,'receipt',['version','amount','evidence']],
                'credit'=>[ReviewService::class,'credit',['version','reason']],
            ];
            if(!isset($map[$operation]))throw new DomainError('Operation unavailable.',404);
            [$class,$method,$fields]=$map[$operation];$d=$this->input($fields);if($ref)$d['ref']=$ref;
            if(!service('throttler')->check('api_write_'.$w->userId,60,60))throw new DomainError('Action rate exceeded.',429);
            $r=(new $class($w))->$method($d);
            return is_array($r)?['ref'=>$r['ref'],'version'=>$r['version'],'state'=>$r['state']??'recorded']:['state'=>'recorded'];
        });
    }

    public function shiftAvailability(string $agency)
    {
        return $this->runAction($agency,fn($w)=>(new \App\Services\ShiftPlanningService($w))->availability($this->input(['employment_ref','client_ref','starts_at','ends_at'])));
    }

    public function requestShiftChange(string $agency)
    {
        return $this->runAction($agency,function($w){$r=(new \App\Services\ShiftPlanningService($w))->request($this->input(['employment_ref','client_ref','starts_at','ends_at','service','required_qualification']));return ['ref'=>$r['ref'],'version'=>$r['version'],'state'=>$r['state'],'expires_at'=>$r['expires_at']];});
    }

    public function shiftRequests()
    {
        return $this->runAction(null,fn($w)=>(new \App\Services\ShiftPlanningService($w))->requests());
    }

    public function decideShiftChange(string $ref)
    {
        return $this->runAction(null,function($w)use($ref){$d=$this->input(['version','decision','confirm','password']);$d['ref']=$ref;$r=(new \App\Services\ShiftPlanningService($w))->decide($d);return ['ref'=>$ref,'version'=>$r['version'],'state'=>$r['state'],'visit_ref'=>$r['visit_ref']??null];});
    }
}
