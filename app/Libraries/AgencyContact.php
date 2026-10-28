<?php
namespace App\Libraries;
use App\Services\{Workspace,DomainError};
use libphonenumber\{PhoneNumberUtil,PhoneNumberFormat,PhoneNumberType,NumberParseException};

final class AgencyContact
{
    public const FIELDS=['official_email','business_phone','contact_number','mobile_number','business_registration_number'];
    public static function phone(string $value,string $country,bool $mobile=false): string
    {
        if(!preg_match('/^\+?[0-9\s().-]+$/D',$value))throw new DomainError('Enter a phone number using digits and its optional international country code.');
        $util=PhoneNumberUtil::getInstance();
        try{$number=$util->parse($value,$country);}catch(NumberParseException){throw new DomainError('Enter a valid '.($mobile?'mobile':'phone').' number, including its country code for an international number.');}
        if(!$util->isValidNumber($number)||$number->hasExtension())throw new DomainError('Enter a valid phone number without an extension.');
        if($mobile&&!in_array($util->getNumberType($number),[PhoneNumberType::MOBILE,PhoneNumberType::FIXED_LINE_OR_MOBILE],true))throw new DomainError('Enter a valid mobile number.');
        return $util->format($number,PhoneNumberFormat::E164);
    }
    public static function validate(array $d,string $country): array
    {
        $email=Workspace::text($d,'official_email',254);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new DomainError('Enter a valid official agency email address.');
        $business=($d['include_business_phone']??'')==='yes';
        $sameMobile=$business&&($d['business_is_mobile']??'')==='yes';
        $sameContact=$business&&($d['business_is_contact']??'')==='yes';
        $businessNumber=$business?self::phone(Workspace::text($d,'business_phone',80),$country,$sameMobile):'';
        return ['official_email'=>$email,'business_phone'=>$businessNumber,
            'contact_number'=>$sameContact?$businessNumber:(($d['include_contact_number']??'')==='yes'?self::phone(Workspace::text($d,'contact_number',80),$country):''),
            'mobile_number'=>$sameMobile?$businessNumber:(($d['include_mobile_number']??'')==='yes'?self::phone(Workspace::text($d,'mobile_number',80),$country,true):''),
            'business_registration_number'=>Workspace::text($d,'business_registration_number',100,false)];
    }
    public static function snapshot(array $agency): array
    {
        $result=[];foreach(array_merge(['ref','name','country'],array_merge(self::FIELDS,AgencyAddress::FIELDS)) as $key)$result[$key]=(string)($agency[$key]??'');return $result;
    }
    public static function display(string $value): string
    {
        if($value==='')return '';
        try{return PhoneNumberUtil::getInstance()->format(PhoneNumberUtil::getInstance()->parse($value,null),PhoneNumberFormat::INTERNATIONAL);}catch(NumberParseException){return $value;}
    }
    public static function hints(): array
    {
        $util=PhoneNumberUtil::getInstance();$result=[];
        foreach($util->getSupportedRegions() as $region){
            $fixed=$util->getExampleNumber($region);$mobile=$util->getExampleNumberForType($region,PhoneNumberType::MOBILE);
            $result[$region]=['code'=>'+'.$util->getCountryCodeForRegion($region),'phone'=>$fixed?$util->format($fixed,PhoneNumberFormat::INTERNATIONAL):'','mobile'=>$mobile?$util->format($mobile,PhoneNumberFormat::INTERNATIONAL):''];
        }return $result;
    }
}
