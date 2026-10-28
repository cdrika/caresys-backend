<?php
use App\Services\{Workspace,AdministrationService,DomainError,SubscriptionBillingService};
use App\Libraries\SubscriptionInvoicePdf;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
final class AgencyContactRegistrationTest extends CIUnitTestCase
{
    private $database;
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();$runner=new MigrationRunner(config(\Config\Migrations::class),'tests');$runner->setNamespace(null);$runner->latest('tests');
        ob_start();\Config\Database::seeder('tests')->call(\App\Database\Seeds\DemoSeeder::class);ob_end_clean();
    }
    protected function setUp(): void{parent::setUp();$this->database=db_connect('tests');$this->database->transException(true)->transBegin();}
    protected function tearDown(): void{$this->database->transRollback();parent::tearDown();}
    private function details(): array{return ['name'=>'Contact Test Agency','country'=>'US','currency'=>'USD','timezone'=>'America/Los_Angeles','official_email'=>'office@example.invalid','include_business_phone'=>'yes','include_mobile_number'=>'yes','include_contact_number'=>'yes','business_phone'=>'2025550123','contact_number'=>'2025550124','mobile_number'=>'2025550125','business_registration_number'=>'BR-TEST-100'];}
    public function testRegistrationPersistsNormalizedContactsAndInvoiceKeepsItsSnapshot(): void
    {
        $user=$this->database->table('users')->where('username','candidate')->get()->getRow();$w=new Workspace((int)$user->id,null,$this->database);
        $agency=(new AdministrationService($w))->agency($this->details()+['include_address'=>'yes','address_line1'=>'100 Example Street','address_city'=>'Sacramento','address_state'=>'CA','address_postal_code'=>'95814']);$this->assertSame('+12025550123',$agency['business_phone']);$this->assertSame('office@example.invalid',$agency['official_email']);
        $this->assertSame('owner',$this->database->table('cs_memberships')->where('agency_id',$agency['id'])->get()->getRow()->role);
        // Isolated fake card verification only, to issue a trial proposal without Stripe.
        $this->database->table('cs_agency_subscriptions')->where('agency_id',$agency['id'])->update(['card_verified_at'=>gmdate('Y-m-d H:i:s')]);
        $owner=new Workspace((int)$user->id,$agency['ref'],$this->database,true);$invoice=(new SubscriptionBillingService($owner))->issue(['modules'=>['people'],'service_level'=>'custom','worker_count'=>10,'request_key'=>bin2hex(random_bytes(16))]);
        $saved=json_decode($invoice['snapshot'],true);$this->assertSame('100 Example Street',$saved['agency']['address_line1']);$this->assertSame('1',$saved['agency']['address_enabled']);$this->assertSame('BR-TEST-100',$saved['agency']['business_registration_number']);
        $this->database->table('cs_agencies')->where('id',$agency['id'])->update(['official_email'=>'changed@example.invalid']);
        $pdf=SubscriptionInvoicePdf::render($invoice);$this->assertStringContainsString('office@example.invalid',$pdf);$this->assertStringContainsString('BR-TEST-100',$pdf);$this->assertStringNotContainsString('changed@example.invalid',$pdf);
    }
    public function testRegistrationCanOmitAllPhoneNumbers(): void
    {
        $user=$this->database->table('users')->where('username','candidate')->get()->getRow();$w=new Workspace((int)$user->id,null,$this->database);$data=$this->details();
        unset($data['include_business_phone'],$data['include_mobile_number'],$data['include_contact_number']);
        $agency=(new AdministrationService($w))->agency($data);
        foreach(['business_phone','mobile_number','contact_number'] as $field)$this->assertSame('',$agency[$field]);
    }
    public function testInvalidContactDoesNotCreateAgencyOrMembership(): void
    {
        $user=$this->database->table('users')->where('username','candidate')->get()->getRow();$w=new Workspace((int)$user->id,null,$this->database);$before=$this->database->table('cs_agencies')->countAllResults();$d=$this->details();$d['mobile_number']='123';
        try{(new AdministrationService($w))->agency($d);$this->fail('Invalid registration accepted');}catch(DomainError $e){$this->assertSame(422,$e->status);}
        $this->assertSame($before,$this->database->table('cs_agencies')->countAllResults());
    }
}
