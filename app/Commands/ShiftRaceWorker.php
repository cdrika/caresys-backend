<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{Workspace,OperationsService,DomainError};

class ShiftRaceWorker extends BaseCommand
{
    protected $group='CareSys tests';protected $name='caresys:test:shift-race';protected $description='Testing-only cross-agency scheduling race worker.';
    public function run(array $params)
    {
        if(ENVIRONMENT!=='testing'||count($params)!==3)return EXIT_ERROR;
        [$employment,$barrier,$worker]=$params;
        if(!preg_match('/^[a-f0-9]{24}$/D',$barrier)||!in_array($worker,['a','b'],true))return EXIT_ERROR;
        $db=db_connect('tests');$user=$db->table('users')->where('username',$worker==='a'?'owner':'owner2')->get()->getRow();
        $agency=$db->table('cs_agencies')->where('name',$worker==='a'?'Willow Care Agency':'Harbor Home Support')->get()->getRow();$w=new Workspace((int)$user->id,$agency->ref,$db);
        $prefix=ROOTPATH.'build/qa/shift-race-'.$barrier;file_put_contents($prefix.'-'.$worker.'.ready','ready');$limit=microtime(true)+15;
        while(!is_file($prefix.'.release')&&microtime(true)<$limit)usleep(10000);
        if(!is_file($prefix.'.release'))return EXIT_ERROR;
        try{
            (new OperationsService($w))->schedule(['employment_ref'=>$employment,'client_ref'=>$w->table('clients')->get()->getRow()->ref,'starts_at'=>'2060-06-01T09:00','ends_at'=>'2060-06-01T12:00','service'=>'Race fixture '.$barrier]);
            CLI::write('SHIFT_RACE:scheduled');
        }catch(DomainError $e){CLI::write('SHIFT_RACE:denied:'.$e->status);}
        return EXIT_SUCCESS;
    }
}
