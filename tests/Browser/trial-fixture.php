<?php
// CLI-only synthetic records, never a public route or live payment-provider call.
if(PHP_SAPI!=='cli')exit(1);
define('FCPATH',dirname(__DIR__,2).'/public/');
define('ENVIRONMENT','development');
require FCPATH.'../app/Config/Paths.php';$paths=new \Config\Paths();
require $paths->systemDirectory.'/Boot.php';\CodeIgniter\Boot::bootConsole($paths);
date_default_timezone_set('UTC');
$db=db_connect();$command=$argv[1]??'';
$owner=$db->table('users')->where('username','owner')->get()->getRowArray();
$caregiver=$db->table('users')->where('username','caregiver')->get()->getRowArray();
if(!$owner||!$caregiver)throw new RuntimeException('Synthetic demo actors required.');
if($command==='create'){
    $agency=(new \App\Services\AdministrationService(new \App\Services\Workspace((int)$owner['id'])))->agency(['name'=>'QA Trial '.bin2hex(random_bytes(6)),'country'=>'US','currency'=>'USD','timezone'=>'America/Los_Angeles','official_email'=>'trial@example.invalid','business_phone'=>'2025550123','contact_number'=>'2025550124','mobile_number'=>'2025550125']);
    $db->table('cs_agencies')->where('id',$agency['id'])->update(['verification'=>'verified']);
    $db->table('cs_memberships')->insert(['ref'=>bin2hex(random_bytes(16)),'agency_id'=>$agency['id'],'user_id'=>$caregiver['id'],'role'=>'caregiver']);
    $tokens=[];foreach(['owner'=>$owner,'caregiver'=>$caregiver] as $key=>$user){$u=model(\CodeIgniter\Shield\Models\UserModel::class)->find($user['id']);$tokens[$key]=$u->generateAccessToken('mobile:QA Trial '.$agency['ref'],['mobile:v1'],\CodeIgniter\I18n\Time::now()->addSeconds(900))->raw_token;}
    echo json_encode(['ref'=>$agency['ref'],'tokens'=>$tokens])."\n";exit;
}
$ref=$argv[2]??'';
if(!preg_match('/^[a-f0-9]{32}$/D',$ref))throw new RuntimeException('Invalid fixture reference.');
$agency=$db->table('cs_agencies')->where('ref',$ref)->like('name','QA Trial ','after')->get()->getRowArray();
if(!$agency)throw new RuntimeException('Synthetic fixture unavailable.');
if($command==='verified')$db->table('cs_agency_subscriptions')->where('agency_id',$agency['id'])->update(['card_verified_at'=>gmdate('Y-m-d H:i:s'),'card_brand'=>'test card','card_last4'=>'4242']);
elseif($command==='expire')$db->table('cs_agency_subscriptions')->where('agency_id',$agency['id'])->update(['trial_ends_at'=>gmdate('Y-m-d H:i:s',time()-1)]);
elseif($command==='cleanup'){
    $db->transException(true)->transBegin();
    foreach(['billing_checkouts','subscription_invoices','outbox','audit','memberships','agency_subscriptions'] as $table)$db->table('cs_'.$table)->where('agency_id',$agency['id'])->delete();
    $db->table('cs_audit')->where('record_ref',$ref)->delete();
    $db->table('auth_identities')->where('name','mobile:QA Trial '.$ref)->delete();
    $db->table('cs_agencies')->where('id',$agency['id'])->delete();$db->transCommit();
}else throw new RuntimeException('Unknown fixture command.');
echo "OK\n";
