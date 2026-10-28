<?php
use App\Libraries\AgencyContact;
use App\Services\DomainError;
use CodeIgniter\Test\CIUnitTestCase;
final class AgencyContactTest extends CIUnitTestCase
{
    public function testCountrySpecificNationalNumbersAndInternationalOverrides(): void
    {
        $this->assertSame('+12025550123',AgencyContact::phone('(202) 555-0123','US'));
        $this->assertSame('+442079460018',AgencyContact::phone('020 7946 0018','GB'));
        $this->assertSame('+61412345678',AgencyContact::phone('0412 345 678','AU',true));
        $this->assertSame('+442079460018',AgencyContact::phone('+44 20 7946 0018','US'));
        $this->assertSame('+44 20 7946 0018',AgencyContact::display('+442079460018'));
    }
    public function testOnlyOfficialEmailIsRequiredAndUnusedNumbersAreDiscarded(): void
    {
        $d=['official_email'=>'office@example.invalid','business_phone'=>'invalid hidden value','mobile_number'=>'invalid hidden value','contact_number'=>'invalid hidden value'];
        $result=AgencyContact::validate($d,'US');$this->assertSame('',$result['business_registration_number']);
        foreach(['business_phone','mobile_number','contact_number'] as $key)$this->assertSame('',$result[$key]);
        try{AgencyContact::validate([],'US');$this->fail('Missing official email accepted');}catch(DomainError $e){$this->assertSame(422,$e->status);}
    }
    public function testBusinessNumberCanBeUsedForMobileAndContactWithoutDuplicateEntry(): void
    {
        $d=['official_email'=>'office@example.invalid','include_business_phone'=>'yes','business_phone'=>'2025550123','business_is_mobile'=>'yes','business_is_contact'=>'yes','mobile_number'=>'bad ignored value','contact_number'=>'bad ignored value'];
        $result=AgencyContact::validate($d,'US');$this->assertSame('+12025550123',$result['business_phone']);$this->assertSame($result['business_phone'],$result['mobile_number']);$this->assertSame($result['business_phone'],$result['contact_number']);
        unset($d['include_business_phone']);$result=AgencyContact::validate($d,'US');$this->assertSame('',$result['mobile_number']);$this->assertSame('',$result['contact_number']);
    }
    public function testSeparateMobileAndContactCanBeAddedWithoutABusinessNumber(): void
    {
        $result=AgencyContact::validate(['official_email'=>'office@example.invalid','include_mobile_number'=>'yes','mobile_number'=>'0412 345 678','include_contact_number'=>'yes','contact_number'=>'02 9374 4000'],'AU');
        $this->assertSame('',$result['business_phone']);$this->assertSame('+61412345678',$result['mobile_number']);$this->assertSame('+61293744000',$result['contact_number']);
    }
    public function testSelectedPhoneIsRequiredAndBusinessLandlineCannotBeDeclaredMobile(): void
    {
        foreach([
            ['include_business_phone'=>'yes'],
            ['include_mobile_number'=>'yes'],
            ['include_contact_number'=>'yes'],
            ['include_business_phone'=>'yes','business_phone'=>'020 7946 0018','business_is_mobile'=>'yes'],
        ] as $d){try{AgencyContact::validate(['official_email'=>'office@example.invalid']+$d,'GB');$this->fail('Invalid selected number accepted');}catch(DomainError $e){$this->assertSame(422,$e->status);}}
    }
    public function testInvalidEmailPhoneExtensionAndLandlineMobileAreRejected(): void
    {
        foreach([fn()=>AgencyContact::validate(['official_email'=>'invalid'],'US'),fn()=>AgencyContact::phone('123456','US'),fn()=>AgencyContact::phone('+12025550123 ext 1','US'),fn()=>AgencyContact::phone('020 7946 0018','GB',true)] as $call){try{$call();$this->fail('Invalid contact accepted');}catch(DomainError $e){$this->assertSame(422,$e->status);}}
    }
    public function testCountryHintsAndSnapshotContainCorrespondenceFields(): void
    {
        $hints=AgencyContact::hints();$this->assertSame('+1',$hints['US']['code']);$this->assertSame('+44',$hints['GB']['code']);
        $snapshot=AgencyContact::snapshot(['name'=>'Agency','country'=>'US','official_email'=>'office@example.invalid','business_registration_number'=>'BR-123']);
        $this->assertSame('BR-123',$snapshot['business_registration_number']);$this->assertArrayHasKey('mobile_number',$snapshot);
    }
}
