<?php
use App\Libraries\{SubscriptionInvoicePdf,SubscriptionPricing,SubscriptionQuote};
use CodeIgniter\Test\CIUnitTestCase;

final class SubscriptionInvoicePdfTest extends CIUnitTestCase
{
    public function testAllModuleScopePaginatesAndPreservesSavedDescription(): void
    {
        $s=SubscriptionPricing::calculate(['modules'=>array_keys(SubscriptionQuote::MODULES),'service_level'=>'growth','worker_count'=>75]);
        $s+=['agency'=>['name'=>'Example Care Agency','country'=>'US'],'issued_at'=>'2026-10-26 12:00:00','valid_until'=>'2026-11-25'];
        $s['module_details']=array_map(static fn($key)=>['key'=>$key,'label'=>SubscriptionQuote::MODULES[$key],'description'=>SubscriptionQuote::DESCRIPTIONS[$key]],$s['effective_modules']);
        $s['module_details'][0]['description']='Description saved at invoice issuance.';
        $pdf=SubscriptionInvoicePdf::render(['invoice_no'=>'CSI-TEST','snapshot'=>json_encode($s)]);
        $this->assertStringStartsWith('%PDF-1.4',$pdf);
        $this->assertStringContainsString('Description saved at invoice issuance.',$pdf);
        $this->assertStringContainsString('Reporting - Selected',$pdf);
        $this->assertStringContainsString('USD 249.00',$pdf);
        preg_match('~/Type /Pages /Kids \[.*?\] /Count (\d+)~',$pdf,$pages);
        $this->assertGreaterThanOrEqual(2,(int)$pages[1]);
        $this->assertStringContainsString('Page 2 of '.$pages[1],$pdf);
    }

    public function testPreviouslyIssuedInvoicesGainDescriptionsWithoutChangingAmounts(): void
    {
        $s=SubscriptionPricing::calculate(['modules'=>['scheduling'],'service_level'=>'custom','worker_count'=>10]);
        $s+=['agency'=>['name'=>'Existing agency','country'=>'US'],'issued_at'=>'2026-10-26 12:00:00','valid_until'=>'2026-11-25'];
        $pdf=SubscriptionInvoicePdf::render(['invoice_no'=>'CSI-EXISTING','snapshot'=>json_encode($s)]);
        $this->assertStringContainsString('Scheduling & visits - Selected',$pdf);
        $this->assertStringContainsString('Care notes & incidents - Required dependency',$pdf);
        $this->assertStringContainsString('USD 69.00',$pdf);
    }
}
