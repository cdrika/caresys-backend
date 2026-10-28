<?php
use App\Services\{Workspace,AdministrationService,NotificationService,DomainError,AgencyAccessService};
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
final class AgencyRegistrationNotificationTest extends CIUnitTestCase
{
    private $database;
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();$runner=new MigrationRunner(config(\Config\Migrations::class),'tests');$runner->setNamespace(null);$runner->latest('tests');
        ob_start();\Config\Database::seeder('tests')->call(\App\Database\Seeds\DemoSeeder::class);ob_end_clean();
    }
    protected function setUp(): void{parent::setUp();$this->database=db_connect('tests');$this->database->transException(true)->transBegin();}
    protected function tearDown(): void{$this->database->transRollback();parent::tearDown();}
    private function actor(string $name): Workspace{$u=$this->database->table('users')->where('username',$name)->get()->getRow();return new Workspace((int)$u->id,null,$this->database);}
    private function data(): array{return ['name'=>'QA Registration Agency','country'=>'US','currency'=>'USD','timezone'=>'America/Los_Angeles','official_email'=>'office@example.invalid','registration_key'=>bin2hex(random_bytes(16))];}
    private function register(array $extra=[]): array{return (new AdministrationService($this->actor('candidate')))->agency(array_replace($this->data(),$extra));}
    private function denied(callable $work,int $status=422): void{try{$work();$this->fail('Expected denial');}catch(DomainError $e){$this->assertSame($status,$e->status,$e->getMessage());}}
    private function decide(array $a,string $state='verified',?int $version=null): void{(new AdministrationService($this->actor('platform')))->verifyAgency(['ref'=>$a['ref'],'version'=>$version??$a['version'],'verification'=>$state,'basis'=>'QA evidence reviewed']);}
    public static function cases(): array
    {
        return [
            'minimal no optional details'=>[[],true],
            'normalized country currency'=>[['country'=>'us','currency'=>'usd'],true],
            'business registration supplied'=>[['business_registration_number'=>'BR-TEST-1'],true],
            'business reused for both roles'=>[['include_business_phone'=>'yes','business_phone'=>'2025550123','business_is_mobile'=>'yes','business_is_contact'=>'yes'],true],
            'UK landline shared as contact'=>[['country'=>'GB','include_business_phone'=>'yes','business_phone'=>'020 7946 0018','business_is_contact'=>'yes'],true],
            'separate Australian mobile'=>[['country'=>'AU','include_mobile_number'=>'yes','mobile_number'=>'0412345678'],true],
            'separate contact only'=>[['include_contact_number'=>'yes','contact_number'=>'2025550123'],true],
            'three different numbers'=>[['include_business_phone'=>'yes','business_phone'=>'2025550123','include_mobile_number'=>'yes','mobile_number'=>'2025550124','include_contact_number'=>'yes','contact_number'=>'2025550125'],true],
            'disabled optional values discarded'=>[['business_phone'=>'bad','mobile_number'=>'bad','contact_number'=>'bad','address_line1'=>['not accepted without opt in']],true],
            'US address'=>[['include_address'=>'yes','address_line1'=>'100 Example Street','address_city'=>'Sacramento','address_state'=>'CA','address_postal_code'=>'95814'],true],
            'Canadian address'=>[['country'=>'CA','include_address'=>'yes','address_line1'=>'100 Example Street','address_city'=>'Ottawa','address_state'=>'ON','address_postal_code'=>'K1A 0B1'],true],
            'UK address optional county'=>[['country'=>'GB','include_address'=>'yes','address_line1'=>'100 Example Street','address_city'=>'London','address_postal_code'=>'SW1A 1AA'],true],
            'UAE address no postal code'=>[['country'=>'AE','include_address'=>'yes','address_line1'=>'100 Example Street','address_city'=>'Dubai','address_state'=>'DU'],true],
            'missing name'=>[['name'=>''],false],
            'overlong name'=>[['name'=>str_repeat('x',181)],false],
            'missing official email'=>[['official_email'=>''],false],
            'malformed official email'=>[['official_email'=>'bad'],false],
            'country wrong length'=>[['country'=>'USA'],false],
            'unknown country'=>[['country'=>'ZZ'],false],
            'currency invalid format'=>[['currency'=>'US1'],false],
            'currency wrong length'=>[['currency'=>'US'],false],
            'timezone invalid'=>[['timezone'=>'No/Such_Zone'],false],
            'selected business missing'=>[['include_business_phone'=>'yes'],false],
            'selected business invalid'=>[['include_business_phone'=>'yes','business_phone'=>'123'],false],
            'selected mobile missing'=>[['include_mobile_number'=>'yes'],false],
            'selected contact missing'=>[['include_contact_number'=>'yes'],false],
            'landline cannot be mobile'=>[['country'=>'GB','include_business_phone'=>'yes','business_phone'=>'020 7946 0018','business_is_mobile'=>'yes'],false],
            'address missing street'=>[['include_address'=>'yes'],false],
            'address missing city'=>[['include_address'=>'yes','address_line1'=>'100 Example','address_state'=>'CA','address_postal_code'=>'95814'],false],
            'address state invalid'=>[['include_address'=>'yes','address_line1'=>'100 Example','address_city'=>'Sacramento','address_state'=>'ZZ','address_postal_code'=>'95814'],false],
            'address zip invalid'=>[['include_address'=>'yes','address_line1'=>'100 Example','address_city'=>'Sacramento','address_state'=>'CA','address_postal_code'=>'invalid'],false],
            'registration number too long'=>[['business_registration_number'=>str_repeat('x',101)],false],
            'malformed retry key'=>[['registration_key'=>'bad'],false],
        ];
    }
    /** @dataProvider cases */
    public function testRegistrationCases(array $extra,bool $valid): void
    {
        $before=$this->database->table('cs_agencies')->countAllResults();$alerts=$this->database->table('cs_notifications')->countAllResults();
        if(!$valid){$this->denied(fn()=>$this->register($extra));$this->assertSame($before,$this->database->table('cs_agencies')->countAllResults());$this->assertSame($alerts,$this->database->table('cs_notifications')->countAllResults());return;}
        $a=$this->register($extra);$this->assertSame('pending',$a['verification']);$this->assertSame($before+1,$this->database->table('cs_agencies')->countAllResults());
        $this->assertSame(2,$this->database->table('cs_notifications')->where('agency_id',$a['id'])->countAllResults());
        $this->assertSame('awaiting_card',(new AgencyAccessService(new Workspace($this->actor('candidate')->userId,$a['ref'],$this->database,true)))->status()['state']);
    }
    public static function optionalCombinations(): array
    {
        $cases=[];for($mask=0;$mask<32;$mask++)foreach([false,true] as $address)foreach([false,true] as $registration)$cases['phone flags '.$mask.' address '.(int)$address.' registration '.(int)$registration]=[$mask,$address,$registration];return $cases;
    }
    /** @dataProvider optionalCombinations */
    public function testAllOptionalRegistrationCombinations(int $mask,bool $address,bool $registration): void
    {
        $flags=['include_business_phone','business_is_mobile','business_is_contact','include_mobile_number','include_contact_number'];$d=[];foreach($flags as $bit=>$name)$d[$name]=($mask&(1<<$bit))?'yes':'';
        $d+=['business_phone'=>'2025550123','mobile_number'=>'2025550124','contact_number'=>'2025550125','include_address'=>$address?'yes':'','address_line1'=>'100 Example Street','address_city'=>'Sacramento','address_state'=>'CA','address_postal_code'=>'95814','business_registration_number'=>$registration?'QA-BR-1':''];
        $a=$this->register($d);$business=(bool)($mask&1);
        $this->assertSame($business?'+12025550123':'',$a['business_phone']);
        $this->assertSame($business&&($mask&2)?'+12025550123':(($mask&8)?'+12025550124':''),$a['mobile_number']);
        $this->assertSame($business&&($mask&4)?'+12025550123':(($mask&16)?'+12025550125':''),$a['contact_number']);
        $this->assertSame($address?'1':'0',$a['address_enabled']);$this->assertSame($registration?'QA-BR-1':'',$a['business_registration_number']);
        $this->assertSame('pending',$a['verification']);$this->assertSame(2,$this->database->table('cs_notifications')->where('agency_id',$a['id'])->countAllResults());
    }
    public function testPlatformAlertAndOwnerReceiptArePrivateAndLinkedToReview(): void
    {
        $a=$this->register();$admin=(new NotificationService($this->actor('platform')))->inbox();$owner=(new NotificationService($this->actor('candidate')))->inbox();
        $this->assertSame(1,$admin['unread']);$this->assertSame('agency.review_requested',$admin['items'][0]['event']);$this->assertSame('/app/platform#agency-'.$a['ref'],$admin['items'][0]['link']);
        $this->assertSame('agency.registration_received',$owner['items'][0]['event']);$this->assertSame(0,(new NotificationService($this->actor('caregiver')))->inbox()['unread']);
    }
    public function testApprovalRejectionSuspensionAndReapprovalNotifyOwnerWithoutStartingTrial(): void
    {
        $a=$this->register();foreach(['verified','rejected','suspended','verified'] as $state){$this->decide($a,$state);$a=$this->database->table('cs_agencies')->where('id',$a['id'])->get()->getRowArray();$items=(new NotificationService($this->actor('candidate')))->inbox();$this->assertSame('agency.'.$state,$items['items'][0]['event']);$this->assertStringContainsString('QA evidence reviewed',$items['items'][0]['body']);}
        $this->assertStringContainsString('verified and approved',$items['items'][0]['body']);$subscription=$this->database->table('cs_agency_subscriptions')->where('agency_id',$a['id'])->get()->getRowArray();$this->assertNull($subscription['trial_started_at']);$this->assertSame('[]',$a['modules']);
    }
    public function testUnauthorizedInvalidAndStaleApprovalDoNotChangeStateOrSendAlerts(): void
    {
        $a=$this->register();$input=['ref'=>$a['ref'],'version'=>1,'verification'=>'verified','basis'=>'Reviewed'];$before=$this->database->table('cs_notifications')->countAllResults();
        $this->denied(fn()=>(new AdministrationService($this->actor('candidate')))->verifyAgency($input),403);
        $this->denied(fn()=>(new AdministrationService($this->actor('platform')))->verifyAgency(array_replace($input,['verification'=>'invalid'])));
        $this->denied(fn()=>(new AdministrationService($this->actor('platform')))->verifyAgency(array_replace($input,['basis'=>''])));
        $this->denied(fn()=>(new AdministrationService($this->actor('platform')))->verifyAgency(array_replace($input,['ref'=>str_repeat('f',32)])),404);
        $this->denied(fn()=>$this->decide($a,'verified',99),409);$this->assertSame($before,$this->database->table('cs_notifications')->countAllResults());
        $this->decide($a);$before=$this->database->table('cs_notifications')->countAllResults();$this->denied(fn()=>$this->decide($a),409);$a['version']=2;$this->decide($a);$this->assertSame($before,$this->database->table('cs_notifications')->countAllResults());
    }
    public function testRegistrationRetryIsIdempotentAndChangedPayloadIsRejected(): void
    {
        $data=$this->data();$svc=new AdministrationService($this->actor('candidate'));$a=$svc->agency($data);$before=$this->database->table('cs_notifications')->countAllResults();$again=$svc->agency($data);$this->assertSame($a['ref'],$again['ref']);$this->assertSame($before,$this->database->table('cs_notifications')->countAllResults());
        $this->denied(fn()=>$svc->agency(array_replace($data,['name'=>'Changed agency'])),409);
    }
    public function testReadStateIsRecipientScopedAndIdempotent(): void
    {
        $this->register();$admin=new NotificationService($this->actor('platform'));$item=$admin->inbox()['items'][0];$this->denied(fn()=>(new NotificationService($this->actor('candidate')))->markRead($item['ref']),404);$this->denied(fn()=>$admin->markRead(str_repeat('f',32)),404);
        $admin->markRead($item['ref']);$admin->markRead($item['ref']);$this->assertSame(0,$admin->inbox()['unread']);$this->assertNotNull($admin->inbox()['items'][0]['read_at']);
    }
    public function testRoleRevocationHidesNotificationsAndEmptyInboxRenders(): void
    {
        $a=$this->register();$admin=$this->actor('platform');$this->database->table('cs_operators')->where('user_id',$admin->userId)->delete();$this->assertSame(0,(new NotificationService($admin))->inbox()['unread']);
        $this->database->table('cs_memberships')->where(['agency_id'=>$a['id'],'user_id'=>$this->actor('candidate')->userId])->update(['state'=>'ended']);$this->assertSame(0,(new NotificationService($this->actor('candidate')))->inbox()['unread']);
        $this->assertStringContainsString('No notifications.',view('components/notification_items',['items'=>[],'unread'=>0]));
    }
    public function testNoActiveOperatorsDoesNotInventRecipientAndOwnerStillGetsReceipt(): void
    {
        $this->database->table('cs_operators')->where('id >',0)->delete();$a=$this->register();$this->assertSame(1,$this->database->table('cs_notifications')->where('agency_id',$a['id'])->countAllResults());
    }
    public function testAllActiveOperatorsAndOwnersReceiveTheirOwnAlerts(): void
    {
        $second=$this->actor('owner2');$this->database->table('cs_operators')->insert(['ref'=>bin2hex(random_bytes(16)),'user_id'=>$second->userId]);$a=$this->register();
        $this->assertSame(1,(new NotificationService($second))->inbox()['unread']);
        $this->database->table('cs_memberships')->insert(['ref'=>bin2hex(random_bytes(16)),'agency_id'=>$a['id'],'user_id'=>$second->userId,'role'=>'owner']);$this->decide($a);
        $this->assertSame('agency.verified',(new NotificationService($second))->inbox()['items'][0]['event']);
    }
    public function testInactiveOperatorIsNotSentRegistrationAlert(): void
    {
        $admin=$this->actor('platform');$this->database->table('users')->where('id',$admin->userId)->update(['active'=>0]);$a=$this->register();$this->assertSame(0,$this->database->table('cs_notifications')->where(['agency_id'=>$a['id'],'recipient_id'=>$admin->userId])->countAllResults());
    }
    public function testNotificationFailureRollsBackRegistrationAndApproval(): void
    {
        $candidate=$this->actor('candidate');$failing=new FailingNotificationWorkspace($candidate->userId,null,$this->database);$before=$this->database->table('cs_agencies')->countAllResults();
        $this->denied(fn()=>(new AdministrationService($failing))->agency($this->data()),503);$this->assertSame($before,$this->database->table('cs_agencies')->countAllResults());
        $a=$this->register();$admin=$this->actor('platform');$failing=new FailingNotificationWorkspace($admin->userId,null,$this->database);
        $this->denied(fn()=>(new AdministrationService($failing))->verifyAgency(['ref'=>$a['ref'],'version'=>1,'verification'=>'verified','basis'=>'Reviewed']),503);
        $this->assertSame('pending',$this->database->table('cs_agencies')->where('id',$a['id'])->get()->getRow()->verification);
    }
    public function testRetryKeyIsScopedToTheRegisteringAccount(): void
    {
        $data=$this->data();$a=(new AdministrationService($this->actor('candidate')))->agency($data);$b=(new AdministrationService($this->actor('former')))->agency($data);$this->assertNotSame($a['ref'],$b['ref']);
    }
    public function testRevokedPlatformRoleCannotApproveUsingStaleWorkspace(): void
    {
        $a=$this->register();$admin=$this->actor('platform');$this->database->table('cs_operators')->where('user_id',$admin->userId)->delete();
        $this->denied(fn()=>(new AdministrationService($admin))->verifyAgency(['ref'=>$a['ref'],'version'=>1,'verification'=>'verified','basis'=>'Reviewed']),403);
        $this->assertSame('pending',$this->database->table('cs_agencies')->where('id',$a['id'])->get()->getRow()->verification);
    }
    public function testDisabledAccountCannotRegisterUsingStaleWorkspace(): void
    {
        $actor=$this->actor('candidate');$this->database->table('users')->where('id',$actor->userId)->update(['active'=>0]);
        $this->denied(fn()=>(new AdministrationService($actor))->agency($this->data()),401);
    }
    public function testNotificationCreationAndReadTimesAreStoredInUtc(): void
    {
        $this->register();$service=new NotificationService($this->actor('platform'));$item=$service->inbox()['items'][0];
        $this->assertLessThanOrEqual(5,abs(time()-strtotime($item['created_at'].' UTC')));
        $service->markRead($item['ref']);$item=$service->inbox()['items'][0];$this->assertLessThanOrEqual(5,abs(time()-strtotime($item['read_at'].' UTC')));
    }
    public function testMarkupInAgencyNameIsEscapedInMenu(): void
    {
        $this->register(['name'=>'<img src=x onerror=alert(1)>']);$data=(new NotificationService($this->actor('platform')))->inbox();$html=view('components/notification_items',$data);$this->assertStringNotContainsString('<img',$html);$this->assertStringContainsString('&lt;img',$html);
    }
}

final class FailingNotificationWorkspace extends Workspace
{
    public function insert(string $table,array $values): array
    {
        if($table==='notifications')throw new DomainError('Simulated notification storage failure.',503);
        return parent::insert($table,$values);
    }
}
