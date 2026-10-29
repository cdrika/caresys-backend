<?php
if(PHP_SAPI!=='cli')exit(1);
define('FCPATH',dirname(__DIR__,2).'/public/');define('ENVIRONMENT','development');require FCPATH.'../app/Config/Paths.php';$paths=new \Config\Paths();require $paths->systemDirectory.'/Boot.php';\CodeIgniter\Boot::bootConsole($paths);
$fixture=json_decode(file_get_contents(ROOTPATH.'build/qa/identity-policy-session.local.json'),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^qa\.reg\.[0-9]+$/D',$fixture['username'])||!preg_match('/^qa\.reg\.[0-9]+@example\.invalid$/D',$fixture['email']))throw new RuntimeException('Only the disposable test account is allowed.');
$db=db_connect();if(config(\Config\Database::class)->default['database']!=='caresys'||config(\Config\Database::class)->default['hostname']!=='127.0.0.1'||(int)config(\Config\Database::class)->default['port']!==3307)throw new RuntimeException('Unexpected database.');
$user=$db->table('users')->where('username',$fixture['username'])->get()->getRowArray();if(!$user)throw new RuntimeException('Fixture account unavailable.');
$identity=$db->table('auth_identities')->where(['user_id'=>$user['id'],'type'=>'email_password','secret'=>$fixture['email']])->get()->getRowArray();if(!$identity)throw new RuntimeException('Fixture identity mismatch.');
$person=$db->table('cs_people')->where('user_id',$user['id'])->get()->getRowArray();$agency=null;
if(isset($fixture['agency_ref'])){$agency=$db->table('cs_agencies')->where(['ref'=>$fixture['agency_ref'],'name'=>$fixture['agency_name']])->get()->getRowArray();if(!$agency)throw new RuntimeException('Fixture agency mismatch.');}
$others=array_column($db->table('users')->select('id')->where('id !=',$user['id'])->orderBy('id')->get()->getResultArray(),'id');
$db->transException(true)->transBegin();
try{
 if($agency){
  if($db->table('cs_memberships')->where('agency_id',$agency['id'])->where('user_id !=',$user['id'])->countAllResults())throw new RuntimeException('Fixture acquired other memberships; manual review required.');
  foreach(['clients','employments','contracts','invoices','subscription_invoices','visits'] as $table)if($db->table('cs_'.$table)->where('agency_id',$agency['id'])->countAllResults())throw new RuntimeException('Fixture has operational records; manual review required.');
  if($db->table('cs_agency_subscriptions')->where('agency_id',$agency['id'])->where('card_verified_at IS NOT NULL',null,false)->countAllResults())throw new RuntimeException('Do not remove a card-enabled fixture.');
  foreach(['notifications','outbox','idempotency','agency_subscriptions','memberships'] as $table)$db->table('cs_'.$table)->where('agency_id',$agency['id'])->delete();
  $db->table('cs_audit')->groupStart()->where('agency_id',$agency['id'])->orWhere('record_ref',$agency['ref'])->orWhere('actor_id',$user['id'])->groupEnd()->delete();
  $db->table('cs_agencies')->where('id',$agency['id'])->delete();
 }
 if($db->table('cs_memberships')->where('user_id',$user['id'])->countAllResults()||$db->table('cs_operators')->where('user_id',$user['id'])->countAllResults())throw new RuntimeException('Unexpected fixture permissions.');
 if($person){foreach(['profiles','profile_regions'] as $table)$db->table('cs_'.$table)->where('person_id',$person['id'])->delete();$db->table('cs_people')->where('id',$person['id'])->delete();}
 foreach(['auth_groups_users','auth_permissions_users','auth_remember_tokens','auth_identities','auth_logins','auth_token_logins'] as $table)$db->table($table)->where('user_id',$user['id'])->delete();
 $db->table('users')->where('id',$user['id'])->delete();
 if($others!==array_column($db->table('users')->select('id')->orderBy('id')->get()->getResultArray(),'id'))throw new RuntimeException('Other accounts changed.');
 $db->transCommit();$result=['fixture_account_removed'=>true,'fixture_agency_removed'=>(bool)$agency,'fixture_notifications_removed'=>true,'other_accounts_preserved'=>true,'stripe_unchanged'=>true];
 file_put_contents(ROOTPATH.'Documentation/Test/Account Identity/cleanup-results.json',json_encode($result,JSON_PRETTY_PRINT));if(is_file(ROOTPATH.'build/qa/identity-policy-session.local.json'))unlink(ROOTPATH.'build/qa/identity-policy-session.local.json');echo json_encode($result);
}catch(Throwable $e){$db->transRollback();throw $e;}
