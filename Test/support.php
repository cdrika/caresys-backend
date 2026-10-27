<?php
// CLI-only evidence runner. Never enabled as an HTTP payment-provider bypass.
if(PHP_SAPI!=='cli')exit(1);
define('FCPATH',dirname(__DIR__).'/public/');define('ENVIRONMENT','development');
require FCPATH.'../app/Config/Paths.php';$paths=new \Config\Paths();require $paths->systemDirectory.'/Boot.php';\CodeIgniter\Boot::bootConsole($paths);date_default_timezone_set('UTC');
use App\Services\{Workspace,StripeGateway,TrialBillingService,AgencyAccessService,DomainError};
$manifestPath=__DIR__.'/session.local.json';$manifest=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^trial\.owner\.[0-9]+\.[a-f0-9]+@example\.invalid$/D',$manifest['owner']['email']))throw new RuntimeException('Synthetic owner required.');
$db=db_connect();$identity=$db->table('auth_identities')->where(['type'=>'email_password','secret'=>$manifest['owner']['email']])->get()->getRowArray();
$agency=$db->table('cs_agencies')->where('ref',$manifest['agency_ref'])->like('name','Test Trial ','after')->get()->getRowArray();
if(!$identity||!$agency)throw new RuntimeException('New synthetic account and agency required.');
$w=new Workspace((int)$identity['user_id'],$agency['ref'],null,true);$w->requireBilling();
if(config('Billing')->stripeSecretKey!=='')throw new RuntimeException('This evidence runner requires Stripe to remain disabled.');

final class EvidenceStripe extends StripeGateway
{
    private array $sessions;
    public function __construct(private string $path){$this->sessions=is_file($path)?json_decode(file_get_contents($path),true):[];}
    public function configured(): bool{return true;}
    private function save(): void{file_put_contents($this->path,json_encode($this->sessions,JSON_PRETTY_PRINT));}
    public function change(string $id,array $values): void{$this->sessions[$id]=array_replace($this->sessions[$id],$values);$this->save();}
    public function request(string $method,string $path,array $data=[],string $key=''): array
    {
        if($path==='customers')return ['id'=>'cus_SIMULATED_EVIDENCE'];
        if($method==='GET')return $this->sessions[explode('?',basename($path))[0]];
        $id='cs_SIMULATED_'.bin2hex(random_bytes(8));
        $session=['id'=>$id,'url'=>'https://checkout.stripe.com/c/SIMULATED_NOT_A_REAL_CHECKOUT','mode'=>$data['mode'],'status'=>'open','customer'=>$data['customer'],'metadata'=>$data['metadata'],'client_reference_id'=>$data['client_reference_id']];
        if($data['mode']==='setup')$session['setup_intent']=['status'=>'succeeded','payment_method'=>['type'=>'card','card'=>['brand'=>'SIMULATED','last4'=>'4242']]];
        else $session+=['currency'=>'usd','payment_status'=>'unpaid','amount_subtotal'=>$data['line_items'][0]['price_data']['unit_amount'],'amount_total'=>$data['line_items'][0]['price_data']['unit_amount']];
        $this->sessions[$id]=$session;$this->save();return $session;
    }
}
$fake=new EvidenceStripe(__DIR__.'/provider.local.json');$billing=new TrialBillingService($w,$fake);$command=$argv[1]??'';
$output=[];
if($command==='baseline'){
    $manifest['baseline']=['agency'=>array_intersect_key($agency,array_flip(['modules','subscription'])),'subscription'=>$w->table('agency_subscriptions')->get()->getRowArray()];
    file_put_contents($manifestPath,json_encode($manifest,JSON_PRETTY_PRINT));$output=$billing->status();
}elseif($command==='setup'){
    $c=$billing->begin('setup',['request_key'=>bin2hex(random_bytes(16)),'confirm'=>'yes']);$row=$w->record('billing_checkouts',$c['ref']);$fake->change($row['stripe_session'],['status'=>'complete']);
    $output=$billing->complete($c['ref']);$w->audit('test.simulated_card_setup',$c['ref'],null,'CLI test fake only; no Stripe request, card entry or payment');
}elseif($command==='expire'){
    $row=$w->table('agency_subscriptions')->get()->getRowArray();$end=time()-2;
    $w->table('agency_subscriptions')->update(['trial_started_at'=>gmdate('Y-m-d H:i:s',$end-(int)$row['trial_days']*86400),'trial_ends_at'=>gmdate('Y-m-d H:i:s',$end)]);
    $w->audit('test.simulated_trial_expiry',null,null,'Accelerated elapsed-time fixture for this synthetic agency only');$output=$billing->status();
}elseif($command==='payment-open'){
    $status=$billing->status();$c=$billing->begin('payment',['request_key'=>bin2hex(random_bytes(16)),'confirm'=>'yes','invoice_ref'=>$status['invoice_ref']]);
    $manifest['payment_checkout']=$c['ref'];file_put_contents($manifestPath,json_encode($manifest,JSON_PRETTY_PRINT));$output=['ref'=>$c['ref'],'state'=>$c['state']];
    $w->audit('test.simulated_payment_approval',$c['ref'],null,'CLI test fake only; no real charge or provider request');
}elseif(in_array($command,['payment-failed','payment-mismatch','payment-success','payment-repeat'],true)){
    $row=$w->record('billing_checkouts',$manifest['payment_checkout']);
    if($command!=='payment-repeat'){
        $invoice=$w->record('subscription_invoices',$row['invoice_ref']);
        $fake->change($row['stripe_session'],['status'=>'complete','payment_status'=>$command==='payment-failed'?'unpaid':'paid','amount_subtotal'=>$command==='payment-mismatch'?1:(int)$invoice['total_cents']]);
    }
    try{$output=['http_equivalent'=>200,'status'=>$billing->complete($row['ref'])];}
    catch(DomainError $e){$output=['http_equivalent'=>$e->status,'message'=>$e->getMessage(),'status'=>$billing->status()];}
}elseif($command==='boundary'){
    $status=$billing->status();$end=strtotime($status['trial_ends_at'].' UTC');$access=new AgencyAccessService($w);
    $output=['second_before'=>$access->status($end-1)['locked'],'at_deadline'=>$access->status($end)['locked'],'trial_days'=>$status['trial_days']];
}elseif($command==='restore'){
    if(!isset($manifest['baseline']))throw new RuntimeException('Baseline missing.');
    $db->transException(true)->transBegin();
    $db->table('cs_agencies')->where('id',$agency['id'])->update($manifest['baseline']['agency']);
    $baseline=$manifest['baseline']['subscription'];unset($baseline['agency_id']);$w->table('agency_subscriptions')->update($baseline);
    $w->table('subscription_invoices')->update(['state'=>'issued']);
    $w->table('billing_checkouts')->like('stripe_session','cs_SIMULATED_','after')->delete();
    $w->audit('test.simulation_reset',null,null,'Restored awaiting-card baseline; removed fake checkouts; no real payment occurred');$db->transCommit();$output=$billing->status();
}elseif($command==='tokens'){
    foreach(['owner','member'] as $role){$id=$db->table('auth_identities')->where(['type'=>'email_password','secret'=>$manifest[$role]['email']])->get()->getRow()->user_id;$u=model(\CodeIgniter\Shield\Models\UserModel::class)->find($id);$output[$role]=$u->generateAccessToken('mobile:TestEvidence:'.$agency['ref'],['mobile:v1'],\CodeIgniter\I18n\Time::now()->addSeconds(900))->raw_token;}
}elseif($command==='revoke-tokens'){
    $db->table('auth_identities')->where('name','mobile:TestEvidence:'.$agency['ref'])->delete();$output=['revoked'=>true];
}else throw new RuntimeException('Unsupported evidence command.');
echo json_encode($output,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
