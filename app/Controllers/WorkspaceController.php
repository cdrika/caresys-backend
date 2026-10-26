<?php
namespace App\Controllers;

use App\Services\{Workspace,DomainError,RecruitmentService,EmploymentService,OperationsService,FinanceService,AdministrationService,ReviewService};

class WorkspaceController extends BaseController
{
    public function personal(string $page='dashboard') {return $this->screen(null,$page);}
    public function agency(string $agencyRef,string $page='dashboard') {return $this->screen($agencyRef,$page);}

    private function context(?string $agencyRef): Workspace
    {
        $w=new Workspace((int)auth()->id(),$agencyRef);
        if(!$w->person) {
            $w->person=$w->insert('people',['user_id'=>$w->userId,'name'=>auth()->user()->username ?: 'New caregiver','identity_status'=>'pending','verified'=>0]);
            $w->insert('profiles',['person_id'=>$w->person['id'],'summary'=>'','preferences'=>'{}']);
        }
        return $w;
    }

    private function screen(?string $agencyRef,string $page)
    {
        try {
            $w=$this->context($agencyRef);$scope=$agencyRef??'personal';
            if(session('workspace_scope')!==$scope) session()->set(['workspace_scope'=>$scope,'workspace_nonce'=>bin2hex(random_bytes(24))]);
            $memberships=$w->db->table('cs_memberships m')->select('a.ref,a.name,m.role')->join('cs_agencies a','a.id=m.agency_id')->where(['m.user_id'=>$w->userId,'m.state'=>'active'])->get()->getResultArray();
            $data=['w'=>$w,'page'=>$page,'memberships'=>$memberships,'base'=>$agencyRef?'/agency/'.$agencyRef:'/app','nonce'=>session('workspace_nonce'),'title'=>ucfirst(str_replace('_',' ',$page)),'rows'=>[]];
            if(!$agencyRef) $this->personalData($w,$page,$data);else $this->agencyData($w,$page,$data);
            return $this->response->setHeader('Cache-Control','no-store, private')->setBody(view('caresys/workspace',$data));
        }catch(DomainError $e){return $this->response->setStatusCode($e->status)->setHeader('Cache-Control','no-store')->setBody(view('caresys/error',['message'=>$e->getMessage(),'status'=>$e->status]));}
    }

    private function personalData(Workspace $w,string $page,array &$d): void
    {
        $pid=$w->person['id'];
        if(!in_array($page,['dashboard','profile','career','privacy','opportunities','offers','activity','agencies','platform'],true)) throw new DomainError('Page unavailable.',404);
        $d['profile']=$w->db->table('cs_profiles')->where('person_id',$pid)->get()->getRowArray();
        $d['regions']=implode(', ',array_column($w->db->table('cs_profile_regions')->where('person_id',$pid)->get()->getResultArray(),'region'));
        $d['careers']=$w->db->table('cs_career')->where('person_id',$pid)->orderBy('id','DESC')->get()->getResultArray();
        $d['qualifications']=$w->db->table('cs_qualifications')->where('person_id',$pid)->get()->getResultArray();
        $d['disputes']=$w->db->table('cs_disputes')->where('person_id',$pid)->orderBy('id','DESC')->get()->getResultArray();
        if(in_array($page,['privacy','dashboard'],true)) $d['requests']=$w->db->table('cs_history_requests h')->select('h.*,a.name AS agency_name,m.role AS recipient_role')->join('cs_agencies a','a.id=h.agency_id')->join('cs_memberships m','m.id=h.recipient_membership_id')->where('h.person_id',$pid)->orderBy('h.id','DESC')->get()->getResultArray();
        if($page==='opportunities') {
            $d['vacancies']=$w->db->table('cs_vacancies v')->select('v.ref,v.title,v.region,v.work_type,v.requirements,v.closing_date,a.name AS agency_name')->join('cs_agencies a','a.id=v.agency_id')->where(['v.state'=>'open','a.verification'=>'verified'])->where('v.closing_date >=',gmdate('Y-m-d'))->get()->getResultArray();
            $d['applications']=$w->db->table('cs_applications x')->select('x.*,v.title,a.name AS agency_name')->join('cs_vacancies v','v.id=x.vacancy_id')->join('cs_agencies a','a.id=x.agency_id')->where('x.person_id',$pid)->get()->getResultArray();
            $d['invitations']=$w->db->table('cs_invitations i')->select('i.*,a.name AS agency_name')->join('cs_agencies a','a.id=i.agency_id')->where('i.recipient_email',auth()->user()->email)->get()->getResultArray();
        }
        if($page==='offers') {
            $d['contracts']=$w->db->table('cs_contracts c')->select('c.*,a.name AS agency_name')->join('cs_agencies a','a.id=c.agency_id')->where('c.person_id',$pid)->orderBy('c.id','DESC')->get()->getResultArray();
            $d['joining']=$w->db->table('cs_joining j')->select('j.*,a.name AS agency_name,c.start_date')->join('cs_agencies a','a.id=j.agency_id')->join('cs_contracts c','c.id=j.contract_id')->where('j.person_id',$pid)->get()->getResultArray();
        }
        if(in_array($page,['activity','dashboard'],true)) $d['activity']=$w->db->table('cs_audit')->select('action,record_ref,detail,created_at')->groupStart()->where('person_id',$pid)->orWhere('actor_id',$w->userId)->groupEnd()->orderBy('id','DESC')->limit(50)->get()->getResultArray();
        if($page==='platform') {
            if(!$w->operator) throw new DomainError('Platform access required.',403);
            $d['agencies']=$w->db->table('cs_agencies')->get()->getResultArray();
            $d['disputes']=$w->db->table('cs_disputes')->get()->getResultArray();
            $d['outbox']=$w->db->table('cs_outbox')->select('event,record_ref,state,attempts,created_at')->orderBy('id','DESC')->limit(50)->get()->getResultArray();
        }
    }

    private function agencyData(Workspace $w,string $page,array &$d): void
    {
        if($page==='settings'){
            $w->require('settings');
            $d['subscription_invoices']=$w->table('subscription_invoices')->orderBy('id','DESC')->limit(50)->get()->getResultArray();
            $d['active_worker_count']=(int)$w->table('employments')->select('COUNT(DISTINCT person_id) AS total',false)->where('state','active')->get()->getRow()->total;
        }
        $map=['recruitment'=>'recruitment','applications'=>'recruitment','history'=>'history','contracts'=>'employment','people'=>'people','clients'=>'care','schedule'=>'scheduling','time'=>'time','leave'=>'leave','care'=>'care','assets'=>'assets','expenses'=>'expenses','payroll'=>'payroll','finance'=>'finance','reports'=>'reporting','settings'=>'settings','today'=>'self'];
        if($page!=='dashboard') {if(!isset($map[$page])) throw new DomainError('Page unavailable.',404);$w->require($map[$page]);}
        $canPeople=array_intersect(Workspace::ROLES[$w->membership['role']]??[],['people','scheduling','time','leave','care','assets','payroll','finance']);
        if($canPeople) $d['employees']=$w->db->table('cs_employments e')->select('e.*,p.name')->join('cs_people p','p.id=e.person_id')->where('e.agency_id',$w->agency['id'])->orderBy('p.name')->get()->getResultArray();
        if($page==='people')$d['qualifications']=$w->db->query('SELECT q.*,e.ref AS employment_ref,p.name AS employee_name FROM cs_qualifications q JOIN cs_employments e ON e.person_id=q.person_id JOIN cs_people p ON p.id=e.person_id WHERE e.agency_id=? AND e.state=\'active\'',[$w->agency['id']])->getResultArray();
        if(in_array($page,['clients','schedule','finance'],true)) $d['clients']=$w->table('clients')->get()->getResultArray();
        if(in_array($page,['recruitment','applications'],true)) {
            $d['vacancies']=$w->table('vacancies')->get()->getResultArray();
            $d['applications']=$w->db->table('cs_applications x')->select('x.*,v.title,p.name,p.ref AS person_ref')->join('cs_vacancies v','v.id=x.vacancy_id AND v.agency_id=x.agency_id')->join('cs_people p','p.id=x.person_id')->where('x.agency_id',$w->agency['id'])->get()->getResultArray();
            $d['candidates']=[];
            if($this->request->getGet('purpose')) $d['candidates']=(new RecruitmentService($w))->search($this->request->getGet());
        }
        if($page==='history') $d['requests']=$w->table('history_requests')->get()->getResultArray();
        if($page==='contracts') {
            $d['applications']=$w->db->table('cs_applications x')->select('x.ref,x.stage,p.name')->join('cs_people p','p.id=x.person_id')->where('x.agency_id',$w->agency['id'])->get()->getResultArray();
            $d['contracts']=$w->db->table('cs_contracts c')->select('c.*,p.name')->join('cs_people p','p.id=c.person_id')->where('c.agency_id',$w->agency['id'])->orderBy('c.id','DESC')->get()->getResultArray();
            $d['joining']=$w->db->table('cs_joining j')->select('j.*,p.name,c.start_date')->join('cs_people p','p.id=j.person_id')->join('cs_contracts c','c.id=j.contract_id')->where('j.agency_id',$w->agency['id'])->get()->getResultArray();
        }
        if(in_array($page,['schedule','care','today'],true)) {
            $q=$w->db->table('cs_visits v')->select('v.*,c.name AS client_name,c.location,c.instructions,p.name AS caregiver_name,e.ref AS employment_ref')->join('cs_clients c','c.id=v.client_id AND c.agency_id=v.agency_id')->join('cs_employments e','e.id=v.employment_id AND e.agency_id=v.agency_id')->join('cs_people p','p.id=e.person_id')->where('v.agency_id',$w->agency['id']);
            if($page==='today') $q->where('e.person_id',$w->person['id'])->where('e.state','active');
            $d['visits']=$q->orderBy('v.starts_at','DESC')->limit(100)->get()->getResultArray();
            $d['notes']=$w->table('notes')->orderBy('id','DESC')->limit(100)->get()->getResultArray();
            if($page==='today') {
                $ids=array_column($d['visits'],'id');$d['notes']=array_values(array_filter($d['notes'],fn($n)=>in_array($n['visit_id'],$ids)));
                $d['self_employments']=$w->table('employments')->where(['person_id'=>$w->person['id'],'state'=>'active'])->get()->getResultArray();
                $d['leave']=$w->db->table('cs_leave l')->select('l.*')->join('cs_employments e','e.id=l.employment_id AND e.agency_id=l.agency_id')->where(['l.agency_id'=>$w->agency['id'],'e.person_id'=>$w->person['id']])->get()->getResultArray();
                $d['expenses']=$w->table('expenses')->where('requested_by',$w->userId)->get()->getResultArray();
                $d['timesheets']=$w->db->query('SELECT t.* FROM cs_timesheets t JOIN cs_employments e ON e.id=t.employment_id AND e.agency_id=t.agency_id WHERE t.agency_id=? AND e.person_id=?',[$w->agency['id'],$w->person['id']])->getResultArray();
                $d['balances']=$w->db->table('cs_leave_balances b')->select('b.minutes')->join('cs_employments e','e.id=b.employment_id AND e.agency_id=b.agency_id')->where(['b.agency_id'=>$w->agency['id'],'e.person_id'=>$w->person['id']])->get()->getResultArray();
            }
        }
        if($page==='time') {$d['timesheets']=$w->table('timesheets')->orderBy('id','DESC')->get()->getResultArray();$d['corrections']=$w->table('time_corrections')->get()->getResultArray();}
        if($page==='leave') {$d['leave']=$w->table('leave')->orderBy('id','DESC')->get()->getResultArray();$d['balances']=$w->table('leave_balances')->get()->getResultArray();}
        if($page==='assets') {$d['assets']=$w->table('assets')->get()->getResultArray();$d['custody']=$w->table('custody')->orderBy('id','DESC')->get()->getResultArray();}
        if($page==='expenses') $d['expenses']=$w->table('expenses')->orderBy('id','DESC')->get()->getResultArray();
        if($page==='payroll') $d['batches']=$w->table('payroll')->orderBy('id','DESC')->get()->getResultArray();
        if($page==='finance') {$d['invoices']=$w->table('invoices')->orderBy('id','DESC')->get()->getResultArray();$d['receipts']=$w->table('receipts')->orderBy('id','DESC')->get()->getResultArray();}
        if($page==='settings') {$d['staff']=$w->table('memberships')->select('ref,user_id,role,state,version')->get()->getResultArray();$d['modules']=json_decode($w->agency['modules'],true);}
        if(in_array($page,['reports','dashboard'],true)) {
            $d['metrics']=[];
            $definitions=['people'=>['employments','Active employees','state','active'],'scheduling'=>['visits','Published visits','state','published'],'time'=>['timesheets','Time awaiting review','state','submitted'],'leave'=>['leave','Leave awaiting review','state','pending'],'recruitment'=>['applications','Applications',null,null],'finance'=>['invoices','Outstanding invoices','state','issued']];
            foreach($definitions as $permission=>[$table,$label,$key,$value]) if($w->can($permission)) {$q=$w->table($table);if($key)$q->where($key,$value);$d['metrics'][$label]=$q->countAllResults();}
            $d['activity']=$w->table('audit')->select('action,record_ref,created_at')->where('actor_id',$w->userId)->orderBy('id','DESC')->limit(20)->get()->getResultArray();
        }
    }

    public function personalAction(string $action) {return $this->mutate(null,$action);}
    public function agencyAction(string $agencyRef,string $action) {return $this->mutate($agencyRef,$action);}

    private function mutate(?string $agencyRef,string $action)
    {
        $safe=[];
        try {
            $w=$this->context($agencyRef);$d=$this->request->getPost();
            if(session('workspace_scope')!==($agencyRef??'personal') || !hash_equals((string)session('workspace_nonce'),(string)($d['workspace_nonce']??''))) throw new DomainError('WORKSPACE_CHANGED: return to the intended workspace and retry.',409);
            if(!preg_match('/^[a-z_]+$/D',$action)) throw new DomainError('Action unavailable.',404);
            $allowed=[
                'profile'=>['role','summary','skills','qualifications','work_type','availability','regions','travel_radius','languages','care_settings','version'],
                'visibility'=>['visibility','version','confirm'],'career'=>['employer','role','start_period','end_period','summary'],
                'invite'=>['email','purpose'],'invitation_decision'=>['ref','version','decision'],'vacancy'=>['title','region','work_type','requirements','closing_date'],
                'apply'=>['ref'],'stage'=>['ref','version','stage'],'history_request'=>['person_ref','purpose','days'],
                'history_decision'=>['ref','version','decision','confirm','entries'],'dispute'=>['ref','reason'],
                'contract'=>['application_ref','role','work_area','work_type','pay_basis','hours_terms','terms','start_date','expiry_date'],
                'contract_decision'=>['ref','version','digest','decision','confirm','password'],'joining_decision'=>['ref','version','decision','confirm','password'],
                'conditions'=>['ref','version','confirm','conditions_note'],'activate'=>['ref','version','request_key'],'offboard'=>['ref','version','end_date'],
                'client'=>['name','location','contact','instructions'],'schedule'=>['employment_ref','client_ref','starts_at','ends_at','service','required_qualification'],
                'visit_decision'=>['ref','version','state','reason'],'clock'=>['employment_ref','visit_ref','event_key','type'],
                'approve_time'=>['ref','version','state'],'leave'=>['employment_ref','starts_at','ends_at','minutes','type'],'leave_decision'=>['ref','version','decision'],
                'note'=>['visit_ref','body','kind','severity'],'asset'=>['tag','name'],'custody'=>['ref','version','decision','employment_ref','condition_note','maintenance'],
                'expense'=>['employment_ref','amount','category','incurred_on','description'],'expense_decision'=>['ref','version','decision','payment_evidence'],
                'employee_mapping'=>['ref','version','provider_ref'],'payroll'=>['period_start','period_end'],
                'invoice'=>['client_ref','hourly_rate','invoice_no','due_date','confirm'],'receipt'=>['ref','version','amount','evidence'],
                'agency'=>['name','country','currency','timezone'],'member'=>['email','role'],'verify_agency'=>['ref','version','verification','basis'],
                'dispute_resolution'=>['ref','version','state','resolution'],'subscription_request'=>['modules','service_level','worker_count','request_key'],
                'qualification'=>['name','issuer','issued_on','expires_on','evidence_ref'],'accept_qualification'=>['employment_ref','qualification_ref','evidence'],
                'balance'=>['employment_ref','minutes','reason'],'correction'=>['ref','proposed_seconds','reason'],'correction_decision'=>['ref','version','decision'],'acknowledge'=>['ref','version'],'credit'=>['ref','version','reason'],
                'entitlement'=>['ref','version','modules','valid_until','state','basis'],
                'correct_career'=>['ref','summary','reason'],
            ];
            if(!isset($allowed[$action])) throw new DomainError('Action unavailable.',404);
            $unknown=array_diff(array_keys($d),array_merge($allowed[$action],['workspace_nonce','csrf_test_name','return_page']));
            if($unknown) throw new DomainError('Unsupported writable fields.',422);
            $personal=['profile','visibility','career','invitation_decision','apply','history_decision','dispute','joining_decision','agency','verify_agency','dispute_resolution','qualification','entitlement'];
            $agencyOnly=['invite','vacancy','history_request','contract','conditions','activate','offboard','client','schedule','visit_decision','clock','approve_time','leave','leave_decision','note','asset','custody','expense','expense_decision','employee_mapping','payroll','invoice','receipt','member','subscription_request','accept_qualification','balance','correction','correction_decision','acknowledge','credit'];
            if(($agencyRef && in_array($action,$personal,true)) || (!$agencyRef && in_array($action,$agencyOnly,true))) throw new DomainError('This action belongs in another workspace.',403);
            if(!service('throttler')->check('action_'.$w->userId,60,60)) throw new DomainError('Too many actions. Try again shortly.',429);
            $methodMap=['profile'=>'saveProfile','history_request'=>'requestHistory','history_decision'=>'historyDecision','invitation_decision'=>'invitationDecision','contract'=>'issue','contract_decision'=>'contractDecision','joining_decision'=>'joiningDecision','visit_decision'=>'visitDecision','approve_time'=>'approveTime','leave_decision'=>'leaveDecision','expense_decision'=>'expenseDecision','employee_mapping'=>'employeeMapping','verify_agency'=>'verifyAgency','dispute_resolution'=>'disputeResolution','subscription_request'=>'subscriptionRequest','correct_career'=>'correctCareer'];
            if(in_array($action,['profile','visibility','career','invite','invitation_decision','vacancy','apply','stage','history_request','history_decision','dispute','correct_career'],true)) $service=new RecruitmentService($w);
            elseif(in_array($action,['contract','contract_decision','joining_decision','conditions','activate','offboard'],true)) $service=new EmploymentService($w);
            elseif(in_array($action,['employee_mapping','payroll','invoice','receipt'],true)) $service=new FinanceService($w);
            elseif(in_array($action,['agency','member','verify_agency','dispute_resolution','subscription_request','entitlement'],true)) $service=new AdministrationService($w);
            else $service=new OperationsService($w);
            if(in_array($action,['qualification','accept_qualification','balance','correction','correction_decision','acknowledge','credit'],true))$service=new ReviewService($w);
            $methodMap['accept_qualification']='acceptQualification';$methodMap['correction_decision']='correctionDecision';
            $method=$methodMap[$action]??$action;$service->$method($d);
            if($action==='subscription_request')return redirect()->to($this->returnPath($agencyRef,$d))
                ->with('safe_input',['return_page'=>'settings','modules'=>$d['modules'],'service_level'=>$d['service_level'],'worker_count'=>$d['worker_count']??10])
                ->with('message','Subscription invoice issued. Download it below. Your module access is unchanged.');
            return redirect()->to($this->returnPath($agencyRef,$d))->with('message','Saved. The current record state is shown below.');
        }catch(DomainError $e){
            $safe=array_diff_key($this->request->getPost(),array_flip(['password','csrf_test_name','workspace_nonce']));
            session()->setFlashdata('safe_input',$safe);
            return redirect()->to($this->returnPath($agencyRef,$this->request->getPost()))->with('error',$e->getMessage());
        }catch(\Throwable $e){
            $correlation=bin2hex(random_bytes(6));log_message('error','CareSys workflow failure {correlation}: {type} at {file}:{line}', ['correlation'=>$correlation,'type'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);
            return redirect()->to($this->returnPath($agencyRef,$this->request->getPost()))->with('error','The action could not complete. Refresh and check for a duplicate or changed record. Reference '.$correlation.'.');
        }
    }

    private function returnPath(?string $agencyRef,array $d): string
    {
        $page=$d['return_page']??'dashboard';if(!is_string($page) || !preg_match('/^[a-z_]+$/D',$page)) $page='dashboard';
        return $agencyRef?'/agency/'.$agencyRef.'/'.$page:'/app/'.$page;
    }

    public function history(string $agencyRef,string $ref)
    {
        try {$w=$this->context($agencyRef);$entries=(new RecruitmentService($w))->readHistory($ref);return $this->response->setHeader('Cache-Control','no-store, private')->setBody(view('caresys/history',['entries'=>$entries,'back'=>'/agency/'.$agencyRef.'/history']));}
        catch(DomainError $e){return $this->response->setStatusCode($e->status)->setBody(view('caresys/error',['message'=>$e->getMessage(),'status'=>$e->status]));}
    }

    public function payrollDownload(string $agencyRef,string $ref)
    {
        try{$w=$this->context($agencyRef);$csv=(new FinanceService($w))->csv($ref);return $this->response->setHeader('Cache-Control','no-store')->setHeader('Content-Disposition','attachment; filename="payroll-'.$ref.'.csv"')->setContentType('text/csv')->setBody($csv);}
        catch(DomainError $e){return $this->response->setStatusCode($e->status)->setBody($e->getMessage());}
    }
}
