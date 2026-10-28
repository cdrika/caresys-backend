<?php
namespace App\Libraries;
use App\Services\{Workspace,DomainError};
use CommerceGuys\Addressing\Address;
use CommerceGuys\Addressing\AddressFormat\AddressFormatRepository;
use CommerceGuys\Addressing\Country\CountryRepository;
use CommerceGuys\Addressing\Subdivision\SubdivisionRepository;
use CommerceGuys\Addressing\Formatter\DefaultFormatter;

final class AgencyAddress
{
    public const FIELDS=['address_enabled','address_line1','address_line2','address_city','address_state','address_postal_code'];
    private const MAP=['address_line1'=>'addressLine1','address_line2'=>'addressLine2','address_city'=>'locality','address_state'=>'administrativeArea','address_postal_code'=>'postalCode'];
    public static function schema(string $country): array
    {
        if(!preg_match('/^[A-Z]{2}$/D',$country)||!isset((new CountryRepository())->getList('en')[$country]))throw new DomainError('Choose a valid address country.');
        $format=(new AddressFormatRepository())->get($country);$used=$format->getUsedFields();$required=$format->getRequiredFields();
        $label=static fn($s,$default)=>match($s){'town_city'=>'Town / city','post_town'=>'Post town','do_si'=>'Province / city',null,''=>$default,default=>ucwords(str_replace('_',' ',$s))};
        $labels=['address_line1'=>'Address line 1','address_line2'=>'Address line 2','address_city'=>$label($format->getLocalityType(),'City'),'address_state'=>$label($format->getAdministrativeAreaType(),'State / province'),'address_postal_code'=>match($format->getPostalCodeType()){'zip'=>'ZIP code','pin'=>'PIN code','eircode'=>'Eircode',default=>'Postal code'}];
        $fields=[];foreach(self::MAP as $key=>$property)$fields[$key]=['label'=>$labels[$key],'used'=>in_array($property,$used,true)||in_array($key,['address_line1','address_line2'],true),'required'=>$key==='address_line1'||in_array($property,$required,true)];
        $states=(new SubdivisionRepository())->getList([$country],'en');
        return ['country'=>$country,'fields'=>$fields,'states'=>$states,'postal_pattern'=>$format->getPostalCodePattern()];
    }
    public static function validate(array $data,string $country): array
    {
        $result=array_fill_keys(self::FIELDS,'');$result['address_enabled']=0;
        if(($data['include_address']??'')!=='yes')return $result;
        $schema=self::schema($country);$result['address_enabled']=1;
        foreach($schema['fields'] as $key=>$field){
            if(!$field['used'])continue;
            $value=Workspace::text($data,$key,in_array($key,['address_line1','address_line2'],true)?255:($key==='address_postal_code'?32:120),false);
            if($field['required']&&$value==='')throw new DomainError('Enter '.$field['label'].' or turn off the optional agency address.');
            if(preg_match('/[\r\n\x00-\x1f]/',$value))throw new DomainError('Enter each address part on a single line.');
            $result[$key]=$value;
        }
        if($result['address_state']!==''&&$schema['states']&&!array_key_exists($result['address_state'],$schema['states']))throw new DomainError('Choose a valid '.$schema['fields']['address_state']['label'].' for this country.');
        $result['address_postal_code']=mb_strtoupper(preg_replace('/\s+/u',' ',$result['address_postal_code']));
        if($result['address_postal_code']!==''&&$schema['postal_pattern']&&!preg_match('~^(?:'.str_replace('~','\\~',$schema['postal_pattern']).')$~iu',$result['address_postal_code']))throw new DomainError('Enter a valid '.$schema['fields']['address_postal_code']['label'].' for the selected country.');
        return $result;
    }
    public static function lines(array $agency): array
    {
        if(empty($agency['address_enabled']))return [];
        $address=(new Address())->withCountryCode($agency['country'])->withAddressLine1($agency['address_line1']??'')->withAddressLine2($agency['address_line2']??'')->withLocality($agency['address_city']??'')->withAdministrativeArea($agency['address_state']??'')->withPostalCode($agency['address_postal_code']??'');
        $formatter=new DefaultFormatter(new AddressFormatRepository(),new CountryRepository(),new SubdivisionRepository());
        return array_values(array_filter(explode("\n",trim($formatter->format($address,['html'=>false,'locale'=>'en'])))));
    }
}
