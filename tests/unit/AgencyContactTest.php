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
    public function testRegistrationNumberOptionalButEmailAndAllNumbersRequired(): void
    {
        $d=['official_email'=>'office@example.invalid','business_phone'=>'2025550123','contact_number'=>'2025550124','mobile_number'=>'2025550125'];
        $result=AgencyContact::validate($d,'US');$this->assertSame('',$result['business_registration_number']);
        foreach(['official_email','business_phone','contact_number','mobile_number'] as $field){$invalid=$d;unset($invalid[$field]);try{AgencyContact::validate($invalid,'US');$this->fail('Missing field accepted');}catch(DomainError $e){$this->assertSame(422,$e->status);}}
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
