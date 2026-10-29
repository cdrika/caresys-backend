<?php
use App\Services\{Workspace,AgencyAccessService,TrialBillingService,SubscriptionBillingService,StripeGateway,DomainError};
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;

final class TrialStripeFake extends StripeGateway
{
    public array $sessions=[];
    public array $calls=[];
    public function configured(): bool{return true;}
    public function request(string $method,string $path,array $data=[],string $key=''): array
    {
        $this->calls[]=[$method,$path,$data,$key];
        if($path==='customers')return ['id'=>'cus_test'];
        if($method==='GET')return $this->sessions[explode('?',basename($path))[0]];
        $id='cs_test_'.count($this->sessions);
        $session=['id'=>$id,'url'=>'https://checkout.stripe.com/c/'.$id,'status'=>'open','mode'=>$data['mode'],'customer'=>$data['customer'],'metadata'=>$data['metadata'],'client_reference_id'=>$data['client_reference_id']];
        if($data['mode']==='setup')$session['setup_intent']=['status'=>'succeeded','payment_method'=>['type'=>'card','card'=>['brand'=>'visa','last4'=>'4242']]];
        else $session+=['currency'=>'usd','payment_status'=>'unpaid','amount_subtotal'=>$data['line_items'][0]['price_data']['unit_amount'],'amount_total'=>$data['line_items'][0]['price_data']['unit_amount']];
        return $this->sessions[$id]=$session;
    }
}

final class AgencyTrialTest extends CIUnitTestCase
{
    private $connection;
    private Workspace $w;
    private TrialStripeFake $stripe;
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();$runner=new MigrationRunner(config(\Config\Migrations::class),'tests');$runner->setNamespace(null)->latest('tests');
        ob_start();\Config\Database::seeder('tests')->call(\App\Database\Seeds\DemoSeeder::class);ob_end_clean();
    }
    protected function setUp(): void
    {
        parent::setUp();$this->connection=db_connect('tests');$this->connection->transException(true)->transBegin();
        $uid=$this->connection->table('users')->where('username','owner')->get()->getRow()->id;
        $agency=$this->connection->table('cs_agencies')->where('name','Willow Care Agency')->get()->getRowArray();
        $this->w=new Workspace((int)$uid,$agency['ref'],$this->connection,true);
        (new AgencyAccessService($this->w))->enroll();$this->stripe=new TrialStripeFake();
    }
    protected function tearDown(): void{$this->connection->transRollback();parent::tearDown();}
    private function denied(callable $fn,int $status): void
    {
        try{$fn();$this->fail('Expected denial');}catch(DomainError $e){$this->assertSame($status,$e->status,$e->getMessage());}
    }
    private function invoice(): array
    {
        return (new SubscriptionBillingService($this->w))->issue(['modules'=>['scheduling'],'service_level'=>'custom','worker_count'=>10,'request_key'=>bin2hex(random_bytes(16))]);
    }
    private function verifyCard(): void
    {
        $service=new TrialBillingService($this->w,$this->stripe);
        $c=$service->begin('setup',['request_key'=>bin2hex(random_bytes(16)),'confirm'=>'yes']);
        $row=$this->w->record('billing_checkouts',$c['ref']);
        $this->stripe->sessions[$row['stripe_session']]['status']='complete';$service->complete($c['ref']);
    }
    public function testCardRequiredAndTrialBoundaryUsesPolicySnapshot(): void
    {
        $before=$this->w->table('subscription_invoices')->countAllResults();
        $this->denied(fn()=>$this->invoice(),409);
        $this->assertSame($before,$this->w->table('subscription_invoices')->countAllResults());
        $this->verifyCard();
        $this->assertSame('awaiting_modules',(new AgencyAccessService($this->w))->status()['state']);
        $this->connection->table('cs_billing_policy')->where('id',1)->update(['trial_days'=>7]);
        $invoice=$this->invoice();$access=new AgencyAccessService($this->w);$status=$access->status();
        $this->assertSame(7,$status['trial_days']);$this->assertSame('trial',$status['state']);
        $end=strtotime($status['trial_ends_at'].' UTC');
        $this->assertFalse($access->status($end-1)['locked']);$this->assertTrue($access->status($end)['locked']);
        $this->assertSame(7*86400,$end-strtotime($status['trial_started_at'].' UTC'));
        $this->connection->table('cs_billing_policy')->where('id',1)->update(['trial_days'=>45]);
        $this->invoice();$this->assertSame($status['trial_ends_at'],$access->status()['trial_ends_at']);
        $this->denied(fn()=>(new TrialBillingService($this->w,$this->stripe))->begin('payment',['request_key'=>bin2hex(random_bytes(16)),'confirm'=>'yes','invoice_ref'=>$invoice['ref']]),409);
        $this->assertCount(1,$this->stripe->sessions); // Setup only; no payment created during free period.
        $setupCalls=array_values(array_filter($this->stripe->calls,static fn($call)=>$call[1]==='checkout/sessions'));
        $this->assertSame('setup',$setupCalls[0][2]['mode']);
        $this->assertSame(['enabled'=>'false'],$setupCalls[0][2]['managed_payments']);
        $this->assertArrayNotHasKey('line_items',$setupCalls[0][2]);
    }
    public function testVerifiedPaymentUnlocksOnceAndRenewalNeedsAnotherApproval(): void
    {
        $this->verifyCard();$invoice=$this->invoice();
        $this->w->table('agency_subscriptions')->update(['trial_ends_at'=>gmdate('Y-m-d H:i:s',time()-1)]);
        $caregiver=(int)$this->connection->table('users')->where('username','caregiver')->get()->getRow()->id;
        $this->denied(fn()=>new Workspace($caregiver,$this->w->agency['ref'],$this->connection),402);
        $this->denied(fn()=>$this->w->require('people'),402);
        $notice=(new TrialBillingService(new Workspace($caregiver,$this->w->agency['ref'],$this->connection,true),$this->stripe))->status();
        $this->assertSame(['state','locked','owner','notice'],array_keys($notice));
        $service=new TrialBillingService($this->w,$this->stripe);$data=['request_key'=>bin2hex(random_bytes(16)),'confirm'=>'yes','invoice_ref'=>$invoice['ref']];
        $checkout=$service->begin('payment',$data);
        $this->assertSame($checkout['ref'],$service->begin('payment',$data)['ref']);
        $this->assertSame($checkout['ref'],$service->begin('payment',array_replace($data,['request_key'=>bin2hex(random_bytes(16))]))['ref']);
        $row=$this->w->record('billing_checkouts',$checkout['ref']);$id=$row['stripe_session'];
        $this->stripe->sessions[$id]['status']='complete';
        $this->denied(fn()=>$service->complete($checkout['ref']),409);
        $this->assertTrue((new AgencyAccessService($this->w))->status()['locked']);
        $this->stripe->sessions[$id]['payment_status']='paid';$this->stripe->sessions[$id]['amount_subtotal']=1;
        $this->denied(fn()=>$service->complete($checkout['ref']),409);
        $this->stripe->sessions[$id]['amount_subtotal']=(int)$invoice['total_cents'];
        $paid=$service->complete($checkout['ref']);$this->assertSame('paid',$paid['state']);
        $this->assertSame($paid['paid_until'],$service->complete($checkout['ref'])['paid_until']);
        (new Workspace($caregiver,$this->w->agency['ref'],$this->connection))->require('self');
        $this->w->table('agency_subscriptions')->update(['paid_until'=>gmdate('Y-m-d H:i:s',time()-1)]);
        $this->denied(fn()=>new Workspace($caregiver,$this->w->agency['ref'],$this->connection),402);
        $this->denied(fn()=>$service->begin('payment',array_replace($data,['request_key'=>bin2hex(random_bytes(16))])),409);
    }

    public function testLocalSimulatorTrialInvoiceExpiryApprovalAndDisable(): void
    {
        $config=config('Billing');$old=$config->localSimulator;$config->localSimulator=true;
        try{
            $sim=new \App\Services\LocalBillingSimulator($this->w);
            $sim->run('test-setup',['confirm'=>'yes']);
            $this->assertSame('awaiting_modules',(new AgencyAccessService($this->w))->status()['state']);
            $invoice=$this->invoice();$this->assertTrue(json_decode($invoice['snapshot'],true)['simulated_billing']);$this->assertStringContainsString('TEST ONLY',\App\Libraries\SubscriptionInvoicePdf::render($invoice));$access=new AgencyAccessService($this->w);
            $this->assertSame('trial',$access->status()['state']);
            $this->denied(fn()=>$sim->run('test-payment',['confirm'=>'yes','invoice_ref'=>$invoice['ref']]),409);
            $sim->run('test-expire',['confirm'=>'yes']);
            $this->assertSame('payment_required',$access->status()['state']);
            $caregiver=(int)$this->connection->table('users')->where('username','caregiver')->get()->getRow()->id;
            $this->denied(fn()=>new Workspace($caregiver,$this->w->agency['ref'],$this->connection),402);
            $nonowner=new \App\Services\LocalBillingSimulator(new Workspace($caregiver,$this->w->agency['ref'],$this->connection,true));
            $this->denied(fn()=>$nonowner->run('test-payment',['confirm'=>'yes','invoice_ref'=>$invoice['ref']]),403);
            $this->denied(fn()=>$sim->run('test-payment',['invoice_ref'=>$invoice['ref']]),422);
            $sim->run('test-payment',['confirm'=>'yes','invoice_ref'=>$invoice['ref']]);
            $paid=$access->status();$this->assertSame('paid',$paid['state']);
            $sim->run('test-payment',['confirm'=>'yes','invoice_ref'=>$invoice['ref']]);
            $this->assertSame($paid['paid_until'],$access->status()['paid_until']);
            $this->assertSame('paid',(new SubscriptionBillingService($this->w))->get($invoice['ref'])['state']);
            $this->assertSame(0,$this->w->table('billing_checkouts')->countAllResults());
            $this->denied(fn()=>(new TrialBillingService($this->w,$this->stripe))->begin('setup',['request_key'=>bin2hex(random_bytes(16)),'confirm'=>'yes']),409);
            $config->localSimulator=false;
            $this->assertTrue($access->status()['locked']);
            $this->denied(fn()=>$sim->run('test-setup',['confirm'=>'yes']),403);
        }finally{$config->localSimulator=$old;}
    }
    public function testLocalSimulatorCannotChangeRealStripeAccount(): void
    {
        $config=config('Billing');$old=$config->localSimulator;$config->localSimulator=true;
        try{
            $this->verifyCard();$before=(new AgencyAccessService($this->w))->status();
            $sim=new \App\Services\LocalBillingSimulator($this->w);
            $this->denied(fn()=>$sim->run('test-setup',['confirm'=>'yes']),409);
            $this->assertSame($before,(new AgencyAccessService($this->w))->status());
        }finally{$config->localSimulator=$old;}
    }

    public function testBillingLayoutHasOneCheckoutReturnActionAndClearStages(): void
    {
        $dir=ROOTPATH.'build/qa/billing-layout';if(!is_dir($dir))mkdir($dir,0777,true);
        $base='/agency/'.$this->w->agency['ref'];
        foreach(['returned','setup','modules','trial'] as $scene){
            $status=['owner'=>true,'locked'=>$scene!=='trial','state'=>$scene==='trial'?'trial':'awaiting_card','trial_days'=>30,'card_verified'=>in_array($scene,['modules','trial'],true),'stripe_configured'=>true,'card_brand'=>'visa','card_last4'=>'4242'];
            $html=view('caresys/billing',['w'=>$this->w,'status'=>$status,'agency'=>$this->w->agency,'base'=>$base,'nonce'=>'test-nonce','subscription_invoices'=>[],'active_worker_count'=>10,'memberships'=>[['ref'=>$this->w->agency['ref'],'name'=>$this->w->agency['name']]],'checkout'=>$scene==='returned'?['ref'=>str_repeat('a',32),'purpose'=>'setup']:null]);
            $this->assertStringContainsString('Agency setup progress',$html);
            $this->assertStringContainsString('class="sidebar"',$html);
            if($scene==='returned'){$this->assertStringContainsString('Confirm saved card',$html);$this->assertStringNotContainsString('Continue to secure card setup',$html);$this->assertStringNotContainsString('Request subscription quote',$html);}
            if($scene==='setup')$this->assertStringContainsString('Continue to secure card setup',$html);
            if($scene==='modules')$this->assertStringContainsString('Step 2 &middot; Choose your package and modules',$html);
            if($scene==='trial'){$this->assertStringContainsString('Open agency workspace',$html);$this->assertStringContainsString('details class="billing-edit"',$html);}
            file_put_contents($dir.'/'.$scene.'.html',$html);
        }
    }
}
