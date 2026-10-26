<?php
use App\Libraries\{SubscriptionPricing,SubscriptionQuote};
use CodeIgniter\Test\CIUnitTestCase;

final class SubscriptionPricingTest extends CIUnitTestCase
{
    public function testDependenciesAndLowestPackageWithAddons(): void
    {
        $s=SubscriptionPricing::calculate(['modules'=>['scheduling'],'service_level'=>'operations','worker_count'=>10]);
        $this->assertSame(6900,$s['total_cents']);
        $this->assertSame(['care'],$s['added_dependencies']);
        $this->assertSame('custom',$s['selected_package']);
        $s=SubscriptionPricing::calculate(['modules'=>array_keys(SubscriptionQuote::MODULES),'service_level'=>'custom','worker_count'=>40]);
        $this->assertSame(21900,$s['total_cents']);
        $this->assertSame('operations',$s['selected_package']);
        $s=SubscriptionPricing::calculate(['modules'=>array_keys(SubscriptionQuote::MODULES),'service_level'=>'custom','worker_count'=>75]);
        $this->assertSame(24900,$s['total_cents']);
        $this->assertSame('growth',$s['selected_package']);
    }

    public function testWorkerCountCannotBeInvalid(): void
    {
        foreach([0,1001,'1.5',[],true] as $workers){
            try{SubscriptionQuote::selection(['modules'=>['people'],'service_level'=>'custom','worker_count'=>$workers]);$this->fail('Invalid count accepted');}
            catch(\App\Services\DomainError $e){$this->assertSame(422,$e->status);}
        }
    }
}
