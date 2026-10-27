<?php
namespace App\Libraries;

/** Integer USD cents; catalog changes never recalculate issued invoices. */
final class SubscriptionPricing
{
    public const VERSION='2026-10-26-v1';
    public const MODULE_CENTS=['recruitment'=>2000,'history'=>500,'employment'=>1000,'people'=>0,'scheduling'=>2500,'time'=>1500,'leave'=>500,'care'=>1500,'assets'=>500,'expenses'=>500,'payroll'=>1500,'finance'=>2000,'reporting'=>1000];
    public const DEPENDENCIES=['history'=>['recruitment'],'scheduling'=>['people','care'],'payroll'=>['people','time']];
    public const STARTER=['people','recruitment','employment'];
    public const OPERATIONS=['people','recruitment','employment','scheduling','time','leave','care'];

    public static function catalog(): array
    {
        return ['version'=>self::VERSION,'currency'=>'USD','extra_worker_cents'=>300,'module_cents'=>self::MODULE_CENTS,'module_labels'=>SubscriptionQuote::MODULES,'module_descriptions'=>SubscriptionQuote::DESCRIPTIONS,'dependencies'=>self::DEPENDENCIES,'packages'=>[
            'custom'=>['label'=>'Custom platform','base_cents'=>2900,'included_workers'=>10,'modules'=>['people']],
            'starter'=>['label'=>'Starter package','base_cents'=>5900,'included_workers'=>10,'modules'=>self::STARTER],
            'operations'=>['label'=>'Operations package','base_cents'=>12900,'included_workers'=>30,'modules'=>self::OPERATIONS],
            'growth'=>['label'=>'Growth package','base_cents'=>24900,'included_workers'=>75,'modules'=>array_keys(self::MODULE_CENTS)],
        ]];
    }

    public static function calculate(array $selection): array
    {
        $catalog=self::catalog();
        $required=array_unique(array_merge(['people'],$selection['modules']));
        do {
            $before=count($required);
            foreach($required as $module)$required=array_unique(array_merge($required,self::DEPENDENCIES[$module]??[]));
        } while(count($required)!==$before);
        $required=array_values(array_intersect(array_keys(self::MODULE_CENTS),$required));
        $candidates=[];
        foreach($catalog['packages'] as $key=>$package){
            $lines=[['description'=>$package['label'].' ('.$package['included_workers'].' workers included)','quantity'=>1,'unit_cents'=>$package['base_cents'],'amount_cents'=>$package['base_cents']]];
            foreach(array_diff($required,$package['modules']) as $module){
                if(self::MODULE_CENTS[$module]>0)$lines[]=['description'=>SubscriptionQuote::MODULES[$module],'quantity'=>1,'unit_cents'=>self::MODULE_CENTS[$module],'amount_cents'=>self::MODULE_CENTS[$module]];
            }
            $extra=max(0,$selection['worker_count']-$package['included_workers']);
            if($extra)$lines[]=['description'=>'Additional active workers','quantity'=>$extra,'unit_cents'=>300,'amount_cents'=>$extra*300];
            $candidates[]=['package'=>$key,'label'=>$package['label'],'included_workers'=>$package['included_workers'],'included_modules'=>$package['modules'],'lines'=>$lines,'total_cents'=>array_sum(array_column($lines,'amount_cents'))];
        }
        usort($candidates,static function($a,$b)use($selection){
            $amount=$a['total_cents']<=>$b['total_cents'];
            if($amount)return $amount;
            $priority=static fn($key)=>$key===$selection['service_level']?0:($key==='custom'?2:1);
            return $priority($a['package'])<=>$priority($b['package']);
        });
        $best=$candidates[0];
        return ['catalog_version'=>self::VERSION,'currency'=>'USD','billing_period'=>'monthly','requested_modules'=>$selection['modules'],'required_modules'=>$required,'added_dependencies'=>array_values(array_diff($required,array_merge(['people'],$selection['modules']))),'effective_modules'=>array_values(array_intersect(array_keys(self::MODULE_CENTS),array_unique(array_merge($required,$best['included_modules'])))),'preferred_package'=>$selection['service_level'],'worker_count'=>$selection['worker_count'],'selected_package'=>$best['package'],'package_label'=>$best['label'],'included_workers'=>$best['included_workers'],'lines'=>$best['lines'],'subtotal_cents'=>$best['total_cents'],'total_cents'=>$best['total_cents'],'tax_status'=>'not_calculated','comparison'=>array_map(static fn($candidate)=>['package'=>$candidate['package'],'label'=>$candidate['label'],'total_cents'=>$candidate['total_cents']],$candidates)];
    }
}
