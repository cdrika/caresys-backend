<?php
use App\Services\{MobilePairingService,DomainError};
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\{UserModel,UserIdentityModel};
use CodeIgniter\Test\CIUnitTestCase;

final class MobilePairingTest extends CIUnitTestCase
{
    private $connection;
    private int $userId;
    private string $password='LocalPairingTest!2026';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $runner=new MigrationRunner(config(\Config\Migrations::class),'tests');
        $runner->setNamespace(null)->latest('tests');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection=db_connect('tests');
        $this->connection->transException(true)->transBegin();
        $users=model(UserModel::class);
        $user=new User(['username'=>'pair_'.bin2hex(random_bytes(6)),'email'=>bin2hex(random_bytes(8)).'@example.invalid','password'=>$this->password,'active'=>1]);
        $users->save($user);
        $this->userId=(int)$users->getInsertID();
    }

    protected function tearDown(): void { $this->connection->transRollback(); parent::tearDown(); }

    private function denied(callable $work,int $status): void
    {
        try { $work(); $this->fail('Expected pairing denial.'); }
        catch (DomainError $e) { $this->assertSame($status,$e->status); }
    }

    public function testApprovalAndOneTimeRedemption(): void
    {
        $service=new MobilePairingService($this->connection);
        $request=$service->start('Test phone');
        $stored=$this->connection->table('cs_mobile_pairings')->where('device_hash',hash('sha256',$request['device_code']))->get()->getRowArray();
        $this->assertNotSame($request['device_code'],$stored['device_hash']);
        $this->assertSame('Test phone',$service->review($request['user_code'])['device_name']);
        $this->denied(fn()=>$service->redeem($request['device_code']),428);
        $this->denied(fn()=>$service->approve($request['user_code'],$this->userId,'incorrect'),403);
        $service->approve($request['user_code'],$this->userId,$this->password);
        $result=$service->redeem($request['device_code']);
        $token=model(UserIdentityModel::class)->getAccessTokenByRawToken($result['access_token']);
        $this->assertSame(['mobile:v1'],$token->scopes);
        $this->assertSame($this->userId,(int)$token->user_id);
        $this->assertLessThanOrEqual(time()+900,$token->expires->getTimestamp());
        $this->denied(fn()=>$service->redeem($request['device_code']),400);
    }

    public function testExpiredAndForeignSecretsCannotBeRedeemed(): void
    {
        $service=new MobilePairingService($this->connection);
        $request=$service->start('Expiry phone');
        $this->denied(fn()=>$service->redeem(str_repeat('a',64)),400);
        $this->connection->table('cs_mobile_pairings')->where('device_hash',hash('sha256',$request['device_code']))->update(['expires_at'=>gmdate('Y-m-d H:i:s',time()-1)]);
        $this->denied(fn()=>$service->review($request['user_code']),404);
        $this->denied(fn()=>$service->redeem($request['device_code']),400);
    }
}
