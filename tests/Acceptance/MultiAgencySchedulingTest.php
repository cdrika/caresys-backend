<?php
use App\Services\{Workspace,OperationsService,ShiftPlanningService,EmploymentService,DomainError};
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;

final class MultiAgencySchedulingTest extends CIUnitTestCase
{
    private $database;
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();$runner=new MigrationRunner(config(\Config\Migrations::class),'tests');$runner->setNamespace(null);$runner->latest('tests');
        ob_start();\Config\Database::seeder('tests')->call(\App\Database\Seeds\DemoSeeder::class);ob_end_clean();
    }
    protected function setUp(): void {parent::setUp();$this->database=db_connect('tests');$this->database->transException(true)->transBegin();}
    protected function tearDown(): void {$this->database->transRollback();parent::tearDown();}
    private function actor(string $name,?string $agency=null): Workspace
    {
        $user=$this->database->table('users')->where('username',$name)->get()->getRowArray();
        $a=$agency?$this->database->table('cs_agencies')->where('name',$agency==='willow'?'Willow Care Agency':'Harbor Home Support')->get()->getRowArray():null;
        return new Workspace((int)$user['id'],$a['ref']??null,$this->database);
    }
    private function blocked(callable $call,int $status=409): void
    {
        try{$call();$this->fail('Expected denial');}catch(DomainError $e){$this->assertSame($status,$e->status,$e->getMessage());}
    }
    private function fixture(): array
    {
        $a=$this->actor('owner','willow');$b=$this->actor('owner2','harbor');$person=$this->actor('caregiver');
        $e=$b->insert('employments',['agency_id'=>$b->agency['id'],'person_id'=>$person->person['id'],'employee_no'=>'PART-TIME-TEST','role'=>'Caregiver','start_date'=>'2025-01-01']);
        $first=$a->table('employments')->where('person_id',$person->person['id'])->get()->getRowArray();
        $old=(new OperationsService($a))->schedule(['employment_ref'=>$first['ref'],'client_ref'=>$a->table('clients')->get()->getRow()->ref,'starts_at'=>'2027-04-01T09:00','ends_at'=>'2027-04-01T12:00','service'=>'Private prior service']);
        $d=['employment_ref'=>$e['ref'],'client_ref'=>$b->table('clients')->get()->getRow()->ref,'starts_at'=>'2027-04-01T10:00','ends_at'=>'2027-04-01T13:00','service'=>'New visit'];
        return [$a,$b,$person,$old,$d];
    }
    private function approve(Workspace $p,array $r): array
    {
        return (new ShiftPlanningService($p))->decide(['ref'=>$r['ref'],'version'=>$r['version'],'decision'=>'approve','confirm'=>'yes','password'=>\App\Database\Seeds\DemoSeeder::PASSWORD]);
    }
    public function testCrossAgencyOverlapIsBlockedAndSuggestionsDoNotExposeForeignRecords(): void
    {
        [$a,$b,$p,$old,$d]=$this->fixture();$this->blocked(fn()=>(new OperationsService($b))->schedule($d));
        $availability=(new ShiftPlanningService($b))->availability($d);$this->assertFalse($availability['available']);$this->assertTrue($availability['replacement_possible']);
        $this->assertSame('2027-04-01T12:00',$availability['suggestions'][0]['starts_at']);
        $json=json_encode($availability);$this->assertStringNotContainsString($old['ref'],$json);$this->assertStringNotContainsString('Willow',$json);$this->assertStringNotContainsString('Private prior service',$json);
        $d=array_replace($d,$availability['suggestions'][0]);$this->assertSame('published',(new OperationsService($b))->schedule($d)['state']);
    }
    public function testCaregiverApprovalAtomicallyReplacesShiftAndAlertsPriorAgency(): void
    {
        [$a,$b,$p,$old,$d]=$this->fixture();$r=(new ShiftPlanningService($b))->request($d);
        $this->assertSame('published',$a->record('visits',$old['ref'])['state']);
        $view=(new ShiftPlanningService($p))->requests();$this->assertSame('Willow Care Agency',$view[0]['replaced_shifts'][0]['agency_name']);
        $done=$this->approve($p,$r);$this->assertSame('approved',$done['state']);$this->assertSame('cancelled',$a->record('visits',$old['ref'])['state']);
        $this->assertSame('published',$b->record('visits',$done['visit_ref'])['state']);
        $alert=$a->table('audit')->where('action','visit.replaced_by_caregiver')->get()->getRowArray();$this->assertSame($old['ref'],$alert['record_ref']);$this->assertStringNotContainsString('Harbor',$alert['detail']);
        $this->assertSame(1,$a->table('outbox')->where(['event'=>'visit.coverage_required','record_ref'=>$old['ref']])->countAllResults());
        $this->blocked(fn()=>$this->approve($p,$r));
    }
    public function testOnlyTheNamedCaregiverCanDecideAndApprovalRequiresPasswordAndConfirmation(): void
    {
        [$a,$b,$p,$old,$d]=$this->fixture();$r=(new ShiftPlanningService($b))->request($d);
        $this->blocked(fn()=>$this->approve($this->actor('candidate'),$r),404);
        $this->blocked(fn()=>$this->approve($b,$r),403);
        $this->blocked(fn()=>(new ShiftPlanningService($p))->decide(['ref'=>$r['ref'],'version'=>1,'decision'=>'approve','confirm'=>'yes','password'=>'wrong']),403);
        $this->blocked(fn()=>(new ShiftPlanningService($p))->decide(['ref'=>$r['ref'],'version'=>1,'decision'=>'approve']),422);
        $this->assertSame('published',$a->record('visits',$old['ref'])['state']);
    }
    public function testDeclineAndExpiryLeaveOriginalShiftIntact(): void
    {
        [$a,$b,$p,$old,$d]=$this->fixture();$r=(new ShiftPlanningService($b))->request($d);
        $done=(new ShiftPlanningService($p))->decide(['ref'=>$r['ref'],'version'=>1,'decision'=>'decline']);$this->assertSame('declined',$done['state']);
        $r=(new ShiftPlanningService($b))->request($d);$this->database->table('cs_shift_changes')->where('id',$r['id'])->update(['expires_at'=>'2020-01-01 00:00:00']);
        $this->blocked(fn()=>$this->approve($p,$r));$this->assertSame('published',$a->record('visits',$old['ref'])['state']);
    }
    public function testScheduleChangesAndLostSchedulingPermissionInvalidateApproval(): void
    {
        [$a,$b,$p,$old,$d]=$this->fixture();$r=(new ShiftPlanningService($b))->request($d);
        $this->database->table('cs_visits')->where('id',$old['id'])->update(['version'=>2]);$this->blocked(fn()=>$this->approve($p,$r));
        $r=(new ShiftPlanningService($b))->request($d);$this->database->table('cs_memberships')->where('id',$b->membership['id'])->update(['role'=>'finance']);
        $this->blocked(fn()=>$this->approve($p,$r),403);$this->assertSame('published',$a->record('visits',$old['ref'])['state']);
    }
    public function testQualificationFailureRollsBackPriorCancellations(): void
    {
        [$a,$b,$p,$old,$d]=$this->fixture();$d['required_qualification']='Missing qualification';$r=(new ShiftPlanningService($b))->request($d);
        $this->blocked(fn()=>$this->approve($p,$r));$this->assertSame('published',$a->record('visits',$old['ref'])['state']);$this->assertSame('pending',$b->record('shift_changes',$r['ref'])['state']);
    }
    public function testApprovedLeaveAndStartedShiftsCannotBeReplaced(): void
    {
        [$a,$b,$p,$old,$d]=$this->fixture();$this->database->table('cs_visits')->where('id',$old['id'])->update(['state'=>'in_progress']);
        $this->blocked(fn()=>(new ShiftPlanningService($b))->request($d));
        $this->database->table('cs_visits')->where('id',$old['id'])->update(['state'=>'published']);
        $a->insert('leave',['agency_id'=>$a->agency['id'],'employment_id'=>$old['employment_id'],'starts_at'=>$old['starts_at'],'ends_at'=>$old['ends_at'],'minutes'=>180,'type'=>'annual','state'=>'approved','requested_by'=>$p->userId]);
        $this->blocked(fn()=>(new ShiftPlanningService($b))->request($d));$availability=(new ShiftPlanningService($b))->availability($d);$this->assertTrue($availability['leave_conflict']);$this->assertFalse($availability['replacement_possible']);
    }
    public function testTimezoneChecksUseUtcAndTouchingShiftsAreAllowed(): void
    {
        [$a,$b,$p,$old,$d]=$this->fixture();$this->database->table('cs_agencies')->where('id',$b->agency['id'])->update(['timezone'=>'America/New_York']);$b=$this->actor('owner2','harbor');
        $d['starts_at']='2027-04-01T13:00';$d['ends_at']='2027-04-01T16:00';$this->blocked(fn()=>(new OperationsService($b))->schedule($d));
        $d['starts_at']='2027-04-01T15:00';$d['ends_at']='2027-04-01T18:00';$this->assertSame($old['ends_at'],(new OperationsService($b))->schedule($d)['starts_at']);
    }
    public function testNewLeaveAfterRequestPreventsApprovalWithoutCancellingOriginal(): void
    {
        [$a,$b,$p,$old,$d]=$this->fixture();$r=(new ShiftPlanningService($b))->request($d);
        $a->insert('leave',['agency_id'=>$a->agency['id'],'employment_id'=>$old['employment_id'],'starts_at'=>$old['starts_at'],'ends_at'=>$old['ends_at'],'minutes'=>180,'type'=>'annual','state'=>'cancellation_requested','requested_by'=>$p->userId]);
        $this->blocked(fn()=>$this->approve($p,$r));$this->assertSame('published',$a->record('visits',$old['ref'])['state']);
    }
    public function testAllConflictingShiftsRequireOneExplicitDecisionAndCrossAgencyClockIsBlocked(): void
    {
        [$a,$b,$p,$old,$d]=$this->fixture();
        $later=(new OperationsService($a))->schedule(['employment_ref'=>$a->table('employments')->where('person_id',$p->person['id'])->get()->getRow()->ref,'client_ref'=>$a->table('clients')->get()->getRow()->ref,'starts_at'=>'2027-04-01T12:00','ends_at'=>'2027-04-01T15:00','service'=>'Second prior visit']);
        $r=(new ShiftPlanningService($b))->request($d);$this->assertCount(2,(new ShiftPlanningService($p))->requests()[0]['replaced_shifts']);$done=$this->approve($p,$r);
        $this->assertSame('cancelled',$a->record('visits',$later['ref'])['state']);
        $b->insert('memberships',['agency_id'=>$b->agency['id'],'user_id'=>$p->userId,'role'=>'caregiver']);
        $workerA=$this->actor('caregiver','willow');$workerB=$this->actor('caregiver','harbor');
        $another=(new OperationsService($a))->schedule(['employment_ref'=>$a->table('employments')->where('id',$old['employment_id'])->get()->getRow()->ref,'client_ref'=>$a->table('clients')->get()->getRow()->ref,'starts_at'=>'2027-04-02T09:00','ends_at'=>'2027-04-02T12:00','service'=>'Next day']);
        (new OperationsService($workerA))->clock(['employment_ref'=>$a->table('employments')->where('id',$another['employment_id'])->get()->getRow()->ref,'visit_ref'=>$another['ref'],'event_key'=>'multi-in-a','type'=>'in']);
        $this->blocked(fn()=>(new OperationsService($workerB))->clock(['employment_ref'=>$d['employment_ref'],'visit_ref'=>$done['visit_ref'],'event_key'=>'multi-in-b','type'=>'in']));
    }
    private function join(Workspace $owner,Workspace $p,string $type='part_time'): array
    {
        $vac=$owner->table('vacancies')->get()->getRowArray();$app=$owner->table('applications')->where('person_id',$p->person['id'])->get()->getRowArray()??$owner->insert('applications',['agency_id'=>$owner->agency['id'],'vacancy_id'=>$vac['id'],'person_id'=>$p->person['id']]);
        $svc=new EmploymentService($owner);$c=$svc->issue(['application_ref'=>$app['ref'],'role'=>'Caregiver','work_area'=>'Local','work_type'=>$type,'pay_basis'=>'Hourly','hours_terms'=>'Part time hours','terms'=>'Test terms','start_date'=>'2026-01-01','expiry_date'=>gmdate('Y-m-d',time()+86400)]);
        $decision=['ref'=>$c['ref'],'version'=>1,'digest'=>$c['digest'],'decision'=>'accept','confirm'=>'yes','password'=>\App\Database\Seeds\DemoSeeder::PASSWORD];$svc->contractDecision($decision);$decision['version']=2;(new EmploymentService($p))->contractDecision($decision);
        $j=$owner->table('joining')->where('contract_id',$c['id'])->get()->getRowArray();(new EmploymentService($p))->joiningDecision(['ref'=>$j['ref'],'version'=>1,'decision'=>'authorize','confirm'=>'yes','password'=>\App\Database\Seeds\DemoSeeder::PASSWORD]);
        $svc->conditions(['ref'=>$j['ref'],'version'=>2,'confirm'=>'yes','conditions_note'=>'Reviewed']);
        return $svc->activate(['ref'=>$j['ref'],'version'=>3,'request_key'=>bin2hex(random_bytes(8))]);
    }
    public function testTwoPartTimeContractsCanActivateAndOffboardingPreservesOtherAgency(): void
    {
        $a=$this->actor('owner','willow');$b=$this->actor('owner2','harbor');$p=$this->actor('candidate');$first=$this->join($a,$p);$second=$this->join($b,$p);
        $this->assertNotSame($first['agency_id'],$second['agency_id']);$this->assertSame(2,$this->database->table('cs_employments')->where(['person_id'=>$p->person['id'],'state'=>'active'])->countAllResults());
        (new EmploymentService($a))->offboard(['ref'=>$first['ref'],'version'=>1,'end_date'=>gmdate('Y-m-d')]);$this->assertSame('active',$b->record('employments',$second['ref'])['state']);
        $this->assertSame('active',$b->table('memberships')->where('user_id',$p->userId)->get()->getRow()->state);
    }
    public function testFullTimeContractStillRequiresPolicyReviewBeforeConcurrentEmployment(): void
    {
        $p=$this->actor('candidate');$this->join($this->actor('owner','willow'),$p,'full_time');$this->blocked(fn()=>$this->join($this->actor('owner2','harbor'),$p));
    }
}
