<?php

use App\Services\{Workspace,DomainError,RecruitmentService,EmploymentService,OperationsService,FinanceService};
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;

final class WorkflowTest extends CIUnitTestCase
{
    public function testQuoteSelectionsAreRecordedWithoutChangingAgencyAccess(): void
    {
        $owner=$this->actor('owner','willow');
        $before=$owner->db->table('cs_agencies')->where('id',$owner->agency['id'])->get()->getRowArray();
        (new \App\Services\AdministrationService($owner))->subscriptionRequest(['modules'=>['people','scheduling'],'service_level'=>'operations']);
        $audit=$owner->db->table('cs_audit')->where(['agency_id'=>$owner->agency['id'],'action'=>'subscription.change_requested'])->orderBy('id','DESC')->get()->getRowArray();
        $this->assertSame(['modules'=>['people','scheduling'],'service_level'=>'operations'],json_decode($audit['detail'],true));
        $after=$owner->db->table('cs_agencies')->where('id',$owner->agency['id'])->get()->getRowArray();
        $this->assertSame($before['modules'],$after['modules']);
        $this->assertSame($before['subscription'],$after['subscription']);
        $this->blocked(fn()=>(new \App\Services\AdministrationService($this->actor('caregiver','willow')))->subscriptionRequest(['modules'=>['people'],'service_level'=>'custom']),403);
    }

    private $database;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $runner=new MigrationRunner(config(\Config\Migrations::class),'tests');$runner->setNamespace(null);$runner->latest('tests');
        ob_start();\Config\Database::seeder('tests')->call(\App\Database\Seeds\DemoSeeder::class);ob_end_clean();
    }

    protected function setUp(): void
    {
        parent::setUp();$this->database=\Config\Database::connect('tests');$this->database->transException(true)->transBegin();
    }
    protected function tearDown(): void {$this->database->transRollback();parent::tearDown();}

    private function actor(string $name,?string $agency=null): Workspace
    {
        $user=$this->database->table('users')->where('username',$name)->get()->getRowArray();
        $ref=$agency?$this->database->table('cs_agencies')->where('name',$agency==='willow'?'Willow Care Agency':'Harbor Home Support')->get()->getRow()->ref:null;
        return new Workspace((int)$user['id'],$ref,$this->database);
    }
    private function blocked(callable $work,int $status): void
    {
        try{$work();$this->fail('Expected authorization or state denial.');}catch(DomainError $e){$this->assertSame($status,$e->status,$e->getMessage());}
    }

    public function testDirectoryProjectionAndPauseAreEnforced(): void
    {
        $owner=$this->actor('owner','willow');$service=new RecruitmentService($owner);$rows=$service->search(['purpose'=>'Weekend staffing']);
        $this->assertNotEmpty($rows);$this->assertArrayNotHasKey('user_id',$rows[0]);$this->assertArrayNotHasKey('email',$rows[0]);$this->assertArrayNotHasKey('birth_date',$rows[0]);
        $candidate=$this->actor('candidate');$profile=$this->database->table('cs_profiles')->where('person_id',$candidate->person['id'])->get()->getRowArray();
        (new RecruitmentService($candidate))->visibility(['visibility'=>'paused','version'=>$profile['version']]);
        $this->assertNotContains($candidate->person['ref'],array_column($service->search(['purpose'=>'Weekend staffing']),'ref'));
    }

    public function testUnverifiedAndUnentitledAgenciesCannotSearch(): void
    {
        $owner=$this->actor('owner','willow');$this->database->table('cs_agencies')->where('id',$owner->agency['id'])->update(['verification'=>'pending']);
        $this->blocked(fn()=>(new RecruitmentService($owner))->search(['purpose'=>'Staffing']),403);
        $this->database->table('cs_agencies')->where('id',$owner->agency['id'])->update(['verification'=>'verified','modules'=>'[]']);
        $this->blocked(fn()=>(new RecruitmentService($owner))->search(['purpose'=>'Staffing']),403);
    }

    public function testTenantAndRoleBoundaries(): void
    {
        $owner=$this->actor('owner','willow');$harbor=$this->actor('owner2','harbor');$foreign=$harbor->table('clients')->get()->getRowArray();
        $this->blocked(fn()=>$owner->record('clients',$foreign['ref']),404);
        $this->blocked(fn()=>new Workspace($owner->userId,$harbor->agency['ref'],$this->database),404);
        $this->blocked(fn()=>$this->actor('caregiver','willow')->require('finance'),403);
        $this->blocked(fn()=>$this->actor('platform')->require('history'),403);
    }

    private function grant(): array
    {
        $owner=$this->actor('recruiter','willow');$candidate=$this->actor('candidate');
        $request=(new RecruitmentService($owner))->requestHistory(['person_ref'=>$candidate->person['ref'],'purpose'=>'Check prior home care role','days'=>7]);
        $entries=$this->database->table('cs_career')->where('person_id',$candidate->person['id'])->get()->getResultArray();
        (new RecruitmentService($candidate))->historyDecision(['ref'=>$request['ref'],'version'=>1,'decision'=>'approve','confirm'=>'yes','entries'=>array_column($entries,'ref')]);
        return [$owner,$candidate,$request];
    }

    public function testHistoryIsVersionScopedRecipientScopedAndRevocable(): void
    {
        [$owner,$candidate,$request]=$this->grant();$service=new RecruitmentService($owner);$entries=$service->readHistory($request['ref']);
        $this->assertCount(1,$entries);$this->assertSame('self_declared',$entries[0]['source']);$this->assertArrayNotHasKey('person_id',$entries[0]);
        $this->blocked(fn()=>(new RecruitmentService($this->actor('owner','willow')))->readHistory($request['ref']),403);
        (new RecruitmentService($candidate))->career(['employer'=>'Later employer','role'=>'Carer','summary'=>'New facts','start_period'=>'2024','end_period'=>'']);
        $this->assertCount(1,$service->readHistory($request['ref']));
        (new RecruitmentService($candidate))->dispute(['ref'=>$entries[0]['ref'],'reason'=>'Dates need correcting']);
        $this->assertSame(1,(int)$service->readHistory($request['ref'])[0]['disputed']);
        (new RecruitmentService($candidate))->historyDecision(['ref'=>$request['ref'],'version'=>2,'decision'=>'withdraw']);
        $this->blocked(fn()=>$service->readHistory($request['ref']),403);
    }

    public function testExpiredGrantFailsOnNextRead(): void
    {
        [$owner,$candidate,$request]=$this->grant();$this->database->table('cs_history_requests')->where('id',$request['id'])->update(['expires_at'=>'2020-01-01 00:00:00']);
        $this->blocked(fn()=>(new RecruitmentService($owner))->readHistory($request['ref']),403);
    }

    private function contract(string $start='2026-01-01'): array
    {
        $owner=$this->actor('owner','willow');$candidate=$this->actor('candidate');$app=$owner->table('applications')->where('person_id',$candidate->person['id'])->get()->getRowArray();
        $service=new EmploymentService($owner);$contract=$service->issue(['application_ref'=>$app['ref'],'role'=>'Caregiver','work_area'=>'Sacramento','work_type'=>'part_time','pay_basis'=>'USD 25 per hour','hours_terms'=>'20 hours weekly','terms'=>'Synthetic agreement version','start_date'=>$start,'expiry_date'=>gmdate('Y-m-d',time()+86400*30)]);
        $decision=['ref'=>$contract['ref'],'version'=>1,'digest'=>$contract['digest'],'decision'=>'accept','confirm'=>'yes','password'=>\App\Database\Seeds\DemoSeeder::PASSWORD];
        $service->contractDecision($decision);$decision['version']=2;(new EmploymentService($candidate))->contractDecision($decision);
        $joining=$owner->table('joining')->where('contract_id',$contract['id'])->get()->getRowArray();
        (new EmploymentService($candidate))->joiningDecision(['ref'=>$joining['ref'],'version'=>1,'decision'=>'authorize','confirm'=>'yes','password'=>\App\Database\Seeds\DemoSeeder::PASSWORD]);
        $service->conditions(['ref'=>$joining['ref'],'version'=>2,'confirm'=>'yes','conditions_note'=>'Synthetic required conditions reviewed']);
        return [$owner,$candidate,$contract,$joining];
    }

    public function testActivationIsMutualExplicitAndIdempotent(): void
    {
        [$owner,$candidate,$contract,$joining]=$this->contract();$service=new EmploymentService($owner);$data=['ref'=>$joining['ref'],'version'=>3,'request_key'=>'same-key'];
        $first=$service->activate($data);$second=$service->activate($data);$this->assertSame($first['ref'],$second['ref']);
        $this->assertSame(1,$owner->table('employments')->where('joining_id',$joining['id'])->countAllResults());
        $this->assertSame(1,$owner->table('memberships')->where('user_id',$candidate->userId)->countAllResults());
        $this->assertSame(1,$this->database->table('cs_people')->where('id',$candidate->person['id'])->countAllResults());
    }

    public function testFutureActivationWaitsAndWithdrawalPreventsActivation(): void
    {
        [$owner,$candidate,$contract,$joining]=$this->contract(gmdate('Y-m-d',time()+86400*7));$service=new EmploymentService($owner);
        $this->blocked(fn()=>$service->activate(['ref'=>$joining['ref'],'version'=>3,'request_key'=>'future-key']),409);
        (new EmploymentService($candidate))->joiningDecision(['ref'=>$joining['ref'],'version'=>3,'decision'=>'withdraw','password'=>\App\Database\Seeds\DemoSeeder::PASSWORD]);
        $this->blocked(fn()=>$service->activate(['ref'=>$joining['ref'],'version'=>4,'request_key'=>'withdrawn-key']),409);
    }

    public function testNewContractVersionInvalidatesBothPriorAgreements(): void
    {
        [$owner,$candidate,$old,$joining]=$this->contract();$service=new EmploymentService($owner);$app=$owner->table('applications')->where('id',$old['application_id'])->get()->getRowArray();
        $new=$service->issue(['application_ref'=>$app['ref'],'role'=>'Caregiver','work_area'=>'Sacramento','work_type'=>'part_time','pay_basis'=>'USD 30 per hour','hours_terms'=>'25 hours','terms'=>'Changed terms','start_date'=>'2026-01-01','expiry_date'=>gmdate('Y-m-d',time()+86400)]);
        $this->assertNull($new['agency_accepted_at']);$this->assertNull($new['person_accepted_at']);$this->assertSame(2,(int)$new['revision']);
        $this->blocked(fn()=>$service->activate(['ref'=>$joining['ref'],'version'=>4,'request_key'=>'old-version']),409);
    }

    public function testSchedulingBlocksOverlapsAndCrossAgencyClient(): void
    {
        $w=$this->actor('scheduler','willow');$e=$w->table('employments')->where('state','active')->get()->getRowArray();$client=$w->table('clients')->get()->getRowArray();$svc=new OperationsService($w);
        $data=['employment_ref'=>$e['ref'],'client_ref'=>$client['ref'],'starts_at'=>'2027-02-01T09:00','ends_at'=>'2027-02-01T12:00','service'=>'Home care'];$svc->schedule($data);$this->blocked(fn()=>$svc->schedule($data),409);
        $other=$this->actor('owner2','harbor')->table('clients')->get()->getRowArray();$data['client_ref']=$other['ref'];$this->blocked(fn()=>$svc->schedule($data),404);
    }

    public function testActualElapsedTimeUsesUtcAndCapturedBreaks(): void
    {
        $events=[['type'=>'in','occurred_at'=>'2026-11-01 08:30:00'],['type'=>'break_start','occurred_at'=>'2026-11-01 09:30:00'],['type'=>'break_end','occurred_at'=>'2026-11-01 09:45:00'],['type'=>'out','occurred_at'=>'2026-11-01 10:30:00']];
        $this->assertSame(6300,OperationsService::elapsed($events));
    }

    public function testDuplicateClockDoesNotCreateAdditionalWork(): void
    {
        $w=$this->actor('caregiver','willow');$e=$w->table('employments')->where('person_id',$w->person['id'])->get()->getRowArray();$v=$w->table('visits')->where('state','published')->get()->getRowArray();$s=new OperationsService($w);
        $data=['employment_ref'=>$e['ref'],'visit_ref'=>$v['ref'],'type'=>'in','event_key'=>'repeat-event'];$first=$s->clock($data);$second=$s->clock($data);$this->assertSame($first['ref'],$second['ref']);
        $data['type']='out';$this->blocked(fn()=>$s->clock($data),409);$data['event_key']='out-event';$s->clock($data);
        $this->assertSame(1,$w->table('timesheets')->where('visit_id',$v['id'])->countAllResults());
    }

    public function testLeaveApprovalAndCancellationKeepBalanceLedger(): void
    {
        $worker=$this->actor('caregiver','willow');$employee=$worker->table('employments')->where('person_id',$worker->person['id'])->get()->getRowArray();$self=new OperationsService($worker);
        $r=$self->leave(['employment_ref'=>$employee['ref'],'starts_at'=>'2027-03-01T09:00','ends_at'=>'2027-03-01T17:00','minutes'=>480,'type'=>'annual']);
        $supervisor=$this->actor('supervisor','willow');$review=new OperationsService($supervisor);$review->leaveDecision(['ref'=>$r['ref'],'version'=>1,'decision'=>'approve']);
        $this->assertSame(1920,(int)$supervisor->table('leave_balances')->where('employment_id',$employee['id'])->get()->getRow()->minutes);
        $self->leaveDecision(['ref'=>$r['ref'],'version'=>2,'decision'=>'cancel_request']);$review->leaveDecision(['ref'=>$r['ref'],'version'=>3,'decision'=>'cancel']);
        $this->assertSame(2400,(int)$supervisor->table('leave_balances')->where('employment_id',$employee['id'])->get()->getRow()->minutes);
        $this->assertSame(2,$supervisor->table('leave_ledger')->where('leave_id',$r['id'])->countAllResults());
    }

    public function testAssetHasOnlyOneCustodianAndRetainsReturn(): void
    {
        $w=$this->actor('supervisor','willow');$s=new OperationsService($w);$asset=$w->table('assets')->get()->getRowArray();$employee=$w->table('employments')->where('state','active')->get()->getRowArray();
        $d=['ref'=>$asset['ref'],'version'=>1,'decision'=>'assign','employment_ref'=>$employee['ref'],'condition_note'=>'Good'];$s->custody($d);$this->blocked(fn()=>$s->custody($d),409);
        $s->custody(['ref'=>$asset['ref'],'version'=>2,'decision'=>'return','condition_note'=>'Good']);$this->assertSame(1,$w->table('custody')->where('asset_id',$asset['id'])->where('returned_at !=',null)->countAllResults());
    }

    public function testPayrollAndBillingCannotAllocateSourcesTwice(): void
    {
        $supervisor=$this->actor('supervisor','willow');$t=$supervisor->table('timesheets')->where('state','submitted')->get()->getRowArray();(new OperationsService($supervisor))->approveTime(['ref'=>$t['ref'],'version'=>1,'state'=>'approved']);
        $w=$this->actor('finance','willow');$s=new FinanceService($w);$batch=$s->payroll(['period_start'=>'2020-01-01','period_end'=>'2030-12-31']);$this->assertSame('exported',$batch['state']);
        $this->blocked(fn()=>$s->payroll(['period_start'=>'2020-01-01','period_end'=>'2030-12-31']),409);
        $csv=$s->csv($batch['ref']);$this->assertStringContainsString('REGULAR_WORKED',$csv);
        $client=$w->table('clients')->get()->getRowArray();$input=['client_ref'=>$client['ref'],'hourly_rate'=>'50.00','invoice_no'=>'TEST-INV-1','due_date'=>'2027-01-01','confirm'=>'yes'];$invoice=$s->invoice($input);$this->assertSame('150.0000',$invoice['total']);$input['invoice_no']='TEST-INV-2';$this->blocked(fn()=>$s->invoice($input),409);
        $s->receipt(['ref'=>$invoice['ref'],'version'=>1,'amount'=>'50.00','evidence'=>'TEST-BANK-REF']);$this->assertSame('part_paid',$w->record('invoices',$invoice['ref'])['state']);
    }

    public function testCsvFormulaInjectionIsEscaped(): void
    {
        $this->assertSame("'=HYPERLINK(\"bad\")",FinanceService::csvCell('=HYPERLINK("bad")'));$this->assertSame('SAFE',FinanceService::csvCell('SAFE'));
    }

    public function testExpiredQualificationCannotBeAcceptedOrAssigned():void
    {
        $worker=$this->actor('caregiver','willow');$personal=new Workspace($worker->userId,null,$this->database);$reviews=new \App\Services\ReviewService($personal);
        $reviews->qualification(['name'=>'First aid','issuer'=>'Synthetic Training','issued_on'=>'2020-01-01','expires_on'=>'2021-01-01']);
        $q=$this->database->table('cs_qualifications')->where('person_id',$personal->person['id'])->get()->getRowArray();$owner=$this->actor('owner','willow');$e=$owner->table('employments')->where('person_id',$worker->person['id'])->get()->getRowArray();
        $this->blocked(fn()=>(new \App\Services\ReviewService($owner))->acceptQualification(['employment_ref'=>$e['ref'],'qualification_ref'=>$q['ref'],'evidence'=>'Checked original']),422);
        $client=$owner->table('clients')->get()->getRowArray();$this->blocked(fn()=>(new OperationsService($owner))->schedule(['employment_ref'=>$e['ref'],'client_ref'=>$client['ref'],'starts_at'=>'2027-06-01T10:00','ends_at'=>'2027-06-01T12:00','service'=>'Home care','required_qualification'=>'First aid']),409);
    }

    public function testCorrectionPreservesOriginalClockAndRequiresTimeReapproval():void
    {
        $worker=$this->actor('caregiver','willow');$t=$worker->table('timesheets')->get()->getRowArray();$reviews=new \App\Services\ReviewService($worker);
        $reviews->correction(['ref'=>$t['ref'],'proposed_seconds'=>7200,'reason'=>'Correct a missed break']);$c=$worker->table('time_corrections')->where('timesheet_id',$t['id'])->get()->getRowArray();
        $owner=$this->actor('supervisor','willow');(new \App\Services\ReviewService($owner))->correctionDecision(['ref'=>$c['ref'],'version'=>1,'decision'=>'approved']);
        $new=$owner->record('timesheets',$t['ref']);$this->assertSame(7200,(int)$new['seconds']);$this->assertSame('submitted',$new['state']);$this->assertSame((int)$t['version']+1,(int)$new['version']);
        $this->assertSame('Correct a missed break',$owner->record('time_corrections',$c['ref'])['reason']);
    }

    public function testCreditRetainsInvoiceAndPreventsDuplicateCredit():void
    {
        $owner=$this->actor('owner','willow');$t=$owner->table('timesheets')->where('state','submitted')->get()->getRowArray();(new OperationsService($owner))->approveTime(['ref'=>$t['ref'],'version'=>$t['version'],'state'=>'approved']);
        $client=$owner->table('clients')->get()->getRowArray();$invoice=(new FinanceService($owner))->invoice(['client_ref'=>$client['ref'],'hourly_rate'=>'50.00','invoice_no'=>'CREDIT-TEST','due_date'=>'2027-01-01','confirm'=>'yes']);
        $reviews=new \App\Services\ReviewService($owner);$reviews->credit(['ref'=>$invoice['ref'],'version'=>1,'reason'=>'Service agreement correction']);
        $this->assertSame('credited',$owner->record('invoices',$invoice['ref'])['state']);$this->assertSame(1,$owner->table('invoice_lines')->where('invoice_id',$invoice['id'])->countAllResults());
        $this->blocked(fn()=>$reviews->credit(['ref'=>$invoice['ref'],'version'=>2,'reason'=>'Repeated credit']),409);
    }

    public function testCareerCorrectionDoesNotExpandExistingGrant():void
    {
        [$owner,$candidate,$request]=$this->grant();$entries=(new RecruitmentService($owner))->readHistory($request['ref']);
        (new RecruitmentService($candidate))->correctCareer(['ref'=>$entries[0]['ref'],'summary'=>'Corrected responsibilities','reason'=>'Clarification']);
        $still=(new RecruitmentService($owner))->readHistory($request['ref']);$this->assertSame($entries[0]['summary'],$still[0]['summary']);$this->assertTrue($still[0]['superseded']);
    }

    public function testGuidedProfileSelectionsSaveAndLocationSearchMatchesHierarchy():void
    {
        $candidate=$this->actor('candidate');$profile=$this->database->table('cs_profiles')->where('person_id',$candidate->person['id'])->get()->getRowArray();
        $r=(new RecruitmentService($candidate))->saveProfile(['version'=>$profile['version'],'role'=>'Specialist home caregiver','summary'=>'Home support experience','skills'=>'Personal care, Gentle exercise support','qualifications'=>'First aid, Specialist home support certificate','work_type'=>'part_time','availability'=>'Weekend mornings','regions'=>'US-CA-067, US-CA-113, US-CA-067-C5389489','travel_radius'=>'25 miles','languages'=>'English, French','care_settings'=>'Home care, Respite care']);
        $this->assertSame('Specialist home caregiver',$r['role']);$this->assertSame('private',$r['visibility']);
        (new RecruitmentService($candidate))->visibility(['version'=>$r['version'],'visibility'=>'discoverable','confirm'=>'yes']);
        $owner=$this->actor('owner','willow');$results=(new RecruitmentService($owner))->search(['purpose'=>'Staffing','region'=>'US-CA-067']);$this->assertContains($candidate->person['ref'],array_column($results,'ref'));
    }
}
