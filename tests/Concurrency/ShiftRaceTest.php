<?php
use CodeIgniter\Test\CIUnitTestCase;
use App\Services\Workspace;

final class ShiftRaceTest extends CIUnitTestCase
{
    public function testDifferentAgencyTransactionsCannotDoubleBookOnePerson(): void
    {
        $runner=new \CodeIgniter\Database\MigrationRunner(config(\Config\Migrations::class),'tests');$runner->setNamespace(null);$runner->latest('tests');
        $db=db_connect('tests');$owner=$db->table('users')->where('username','owner2')->get()->getRow();
        if(!$owner){ob_start();\Config\Database::seeder('tests')->call(\App\Database\Seeds\DemoSeeder::class);ob_end_clean();$owner=$db->table('users')->where('username','owner2')->get()->getRow();}
        $agency=$db->table('cs_agencies')->where('name','Harbor Home Support')->get()->getRow();$w=new Workspace((int)$owner->id,$agency->ref,$db);
        $person=$db->table('users')->where('username','caregiver')->get()->getRow();$p=$db->table('cs_people')->where('user_id',$person->id)->get()->getRow();
        $a=$db->table('cs_employments')->where(['person_id'=>$p->id,'state'=>'active'])->get()->getRowArray();
        $barrier=bin2hex(random_bytes(12));$prefix=ROOTPATH.'build/qa/shift-race-'.$barrier;
        $b=$w->insert('employments',['agency_id'=>$agency->id,'person_id'=>$p->id,'employee_no'=>'RACE-'.$barrier,'role'=>'Caregiver','start_date'=>'2025-01-01']);
        $processes=[];$pipes=[];$env=getenv();$env['CI_ENVIRONMENT']='testing';
        try{
            foreach(['a'=>$a,'b'=>$b] as $worker=>$e){$p=[];$processes[$worker]=proc_open([PHP_BINARY,ROOTPATH.'tests/Concurrency/worker.php','caresys:test:shift-race',$e['ref'],$barrier,$worker],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$p,ROOTPATH,$env,['bypass_shell'=>true]);$pipes[$worker]=$p;fclose($p[0]);}
            $limit=microtime(true)+12;while((!is_file($prefix.'-a.ready')||!is_file($prefix.'-b.ready'))&&microtime(true)<$limit)usleep(20000);
            $this->assertFileExists($prefix.'-a.ready');$this->assertFileExists($prefix.'-b.ready');file_put_contents($prefix.'.release','release');$outputs=[];
            foreach(['a','b'] as $worker){$outputs[]=stream_get_contents($pipes[$worker][1]);$outputs[]=stream_get_contents($pipes[$worker][2]);fclose($pipes[$worker][1]);fclose($pipes[$worker][2]);$this->assertSame(0,proc_close($processes[$worker]));unset($processes[$worker]);}
            $result=implode("\n",$outputs);$this->assertSame(1,substr_count($result,'SHIFT_RACE:scheduled'),$result);$this->assertSame(1,substr_count($result,'SHIFT_RACE:denied:409'),$result);
            $this->assertSame(1,$db->table('cs_visits')->where('service','Race fixture '.$barrier)->countAllResults());
        }finally{
            foreach($processes as $process)if(is_resource($process))proc_terminate($process);
            $visits=$db->table('cs_visits')->where('service','Race fixture '.$barrier)->get()->getResultArray();
            foreach($visits as $v){$db->table('cs_outbox')->where('record_ref',$v['ref'])->delete();$db->table('cs_audit')->where('record_ref',$v['ref'])->delete();$db->table('cs_visits')->where('id',$v['id'])->delete();}
            $db->table('cs_employments')->where('id',$b['id'])->delete();
        }
    }
}
