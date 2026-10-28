<?php
use App\Libraries\{AgencyAddress,AgencyContact,SubscriptionInvoicePdf,SubscriptionPricing};
use App\Services\DomainError;
use CodeIgniter\Test\CIUnitTestCase;
final class AgencyAddressTest extends CIUnitTestCase
{
    private function usa(): array{return ['include_address'=>'yes','address_line1'=>'100 Example Street','address_line2'=>'Suite 2','address_city'=>'Sacramento','address_state'=>'CA','address_postal_code'=>'95814'];}
    public function testOptOutDiscardsAllAddressValues(): void
    {
        $d=$this->usa();unset($d['include_address']);$address=AgencyAddress::validate($d,'US');$this->assertSame(0,$address['address_enabled']);$this->assertSame('',$address['address_line1']);$this->assertSame([],AgencyAddress::lines($address+['country'=>'US']));
    }
    public function testUSStateAndZipAndCanadianProvinceAndPostalCode(): void
    {
        $us=AgencyAddress::schema('US');$ca=AgencyAddress::schema('CA');$this->assertSame('State',$us['fields']['address_state']['label']);$this->assertSame('ZIP code',$us['fields']['address_postal_code']['label']);$this->assertArrayHasKey('CA',$us['states']);
        $this->assertSame('Province',$ca['fields']['address_state']['label']);$this->assertSame('Postal code',$ca['fields']['address_postal_code']['label']);
        $valid=AgencyAddress::validate($this->usa(),'US');$this->assertSame(1,$valid['address_enabled']);$this->assertStringContainsString('Sacramento',implode(' ',AgencyAddress::lines($valid+['country'=>'US'])));
        $d=$this->usa();$d['address_city']='Ottawa';$d['address_state']='ON';$d['address_postal_code']='k1a 0b1';$this->assertSame('K1A 0B1',AgencyAddress::validate($d,'CA')['address_postal_code']);
    }
    public function testUKUsesPostTownAndCountriesWithoutPostalCodesIgnoreStaleValues(): void
    {
        $gb=AgencyAddress::schema('GB');$this->assertSame('Town / city',$gb['fields']['address_city']['label']);$this->assertFalse($gb['fields']['address_state']['required']);
        $d=$this->usa();$d['address_city']='London';$d['address_state']='';$d['address_postal_code']='SW1A 1AA';$valid=AgencyAddress::validate($d,'GB');$this->assertSame('',$valid['address_state']);
        $ae=AgencyAddress::schema('AE');$this->assertFalse($ae['fields']['address_postal_code']['used']);$d['address_city']='Dubai';$d['address_state']=(string)array_search('Dubai',$ae['states'],true);$d['address_postal_code']='unused';$valid=AgencyAddress::validate($d,'AE');$this->assertSame('',$valid['address_postal_code']);
    }
    public function testRequiredAddressPartsAndCountryPostalAndStateChecks(): void
    {
        foreach(['address_line1'=>'','address_city'=>'','address_state'=>'INVALID','address_postal_code'=>'invalid'] as $key=>$value){$d=$this->usa();$d[$key]=$value;try{AgencyAddress::validate($d,'US');$this->fail('Invalid address accepted');}catch(DomainError $e){$this->assertSame(422,$e->status);}}
    }
    public function testAddressIsIncludedOnlyInOptedInInvoiceSnapshot(): void
    {
        $agency=['name'=>'Address Example Agency','country'=>'US']+AgencyAddress::validate($this->usa(),'US');$snapshot=AgencyContact::snapshot($agency);$this->assertSame('100 Example Street',$snapshot['address_line1']);
        $s=SubscriptionPricing::calculate(['modules'=>['people'],'service_level'=>'custom','worker_count'=>10]);$s+=['agency'=>$snapshot,'issued_at'=>'2026-10-27 18:00:00','valid_until'=>'2026-11-26'];
        $pdf=SubscriptionInvoicePdf::render(['invoice_no'=>'CSI-ADDRESS-TEST','snapshot'=>json_encode($s)]);$this->assertStringContainsString('100 Example Street',$pdf);
        $s['agency']['address_enabled']='0';$pdf=SubscriptionInvoicePdf::render(['invoice_no'=>'CSI-ADDRESS-TEST','snapshot'=>json_encode($s)]);$this->assertStringNotContainsString('100 Example Street',$pdf);
    }
}
