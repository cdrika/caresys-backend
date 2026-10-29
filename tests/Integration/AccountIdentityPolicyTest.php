<?php
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class IdentityRegistrationRules extends \App\Controllers\RegisterController {public function rules():array{return $this->getValidationRules();}}
final class AccountIdentityPolicyTest extends CIUnitTestCase
{
    private $database;
    public static function setUpBeforeClass():void
    {
        parent::setUpBeforeClass();$runner=new MigrationRunner(config(\Config\Migrations::class),'tests');$runner->setNamespace(null)->latest('tests');
        ob_start();\Config\Database::seeder('tests')->call(\App\Database\Seeds\DemoSeeder::class);ob_end_clean();
    }
    protected function setUp():void{parent::setUp();$this->database=db_connect('tests');$this->database->transException(true)->transBegin();}
    protected function tearDown():void{$this->database->transRollback();$this->database->resetTransStatus();parent::tearDown();}
    public static function states():array{return [['active'],['inactive'],['suspended'],['banned'],['closed'],['deleted']];}
    #[DataProvider('states')]
    public function testPersonalIdentifiersRemainReserved(string $state):void
    {
        $user=$this->database->table('users')->where('username','candidate')->get()->getRowArray();
        $identity=$this->database->table('auth_identities')->where(['user_id'=>$user['id'],'type'=>'email_password'])->get()->getRowArray();
        $this->database->table('users')->where('id',$user['id'])->update(['active'=>$state==='active'?1:0,'status'=>in_array($state,['suspended','banned','closed'],true)?$state:null,'deleted_at'=>$state==='deleted'?gmdate('Y-m-d H:i:s'):null]);
        $rules=(new IdentityRegistrationRules())->rules();$validator=service('validation',null,false);
        $validator->setRules(array_intersect_key($rules,array_flip(['username','email'])));
        $this->assertFalse($validator->run(['username'=>strtoupper($user['username']),'email'=>strtoupper($identity['secret'])],null,'tests'));
        $this->assertArrayHasKey('username',$validator->getErrors());$this->assertArrayHasKey('email',$validator->getErrors());
        $this->assertStringContainsString('Sign in',$validator->getError('email'));
    }
    public function testSharedCorrespondenceDetailsAreAllowed():void
    {
        $user=$this->database->table('users')->where('username','candidate')->get()->getRowArray();$w=new \App\Services\Workspace((int)$user['id'],null,$this->database);
        $data=['country'=>'US','currency'=>'USD','timezone'=>'America/Los_Angeles','official_email'=>'SHARED@example.invalid','include_business_phone'=>'yes','business_phone'=>'2025550123','include_address'=>'yes','address_line1'=>'100 Example Street','address_city'=>'Sacramento','address_state'=>'CA','address_postal_code'=>'95814'];
        $a=(new \App\Services\AdministrationService($w))->agency(['name'=>'Shared Contact Branch A']+$data);
        $b=(new \App\Services\AdministrationService($w))->agency(['name'=>'Shared Contact Branch B']+$data);
        $this->assertNotSame($a['id'],$b['id']);
        foreach(['official_email','business_phone','address_line1','address_city','address_state','address_postal_code'] as $field)$this->assertSame($a[$field],$b[$field]);
        $this->assertSame('shared@example.invalid',$a['official_email']);
    }
    public function testDatabaseRejectsDuplicateEmailEvenWithoutFormValidation():void
    {
        $i=$this->database->table('auth_identities')->where('type','email_password')->get()->getRowArray();
        $other=$this->database->table('users')->where('id !=',$i['user_id'])->get()->getRowArray();
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->database->table('auth_identities')->insert(['user_id'=>$other['id'],'type'=>'email_password','secret'=>$i['secret'],'secret2'=>'unused-test-hash']);
    }
    public function testDatabaseRejectsDuplicateUsernameEvenWithoutFormValidation():void
    {
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->database->table('users')->insert(['username'=>'candidate','active'=>1]);
    }
}
