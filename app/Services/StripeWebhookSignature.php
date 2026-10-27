<?php
namespace App\Services;
final class StripeWebhookSignature
{
    public static function valid(string $body,string $header,string $secret,?int $now=null): bool
    {
        if($secret===''||strlen($body)>262144)return false;
        $timestamps=[];$signatures=[];
        foreach(explode(',',$header) as $part){$pair=explode('=',trim($part),2);if(count($pair)!==2)continue;if($pair[0]==='t')$timestamps[]=$pair[1];if($pair[0]==='v1')$signatures[]=$pair[1];}
        if(count($timestamps)!==1||!ctype_digit($timestamps[0])||abs(($now??time())-(int)$timestamps[0])>300)return false;
        $expected=hash_hmac('sha256',$timestamps[0].'.'.$body,$secret);
        foreach($signatures as $signature)if(hash_equals($expected,$signature))return true;
        return false;
    }
}
