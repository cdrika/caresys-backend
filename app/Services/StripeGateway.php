<?php
namespace App\Services;

/** Only Stripe receives card details. Never log provider responses or secret keys. */
class StripeGateway
{
    public function configured(): bool {return (bool)config('Billing')->stripeSecretKey;}

    public function request(string $method,string $path,array $data=[],string $key=''): array
    {
        if(!$this->configured())throw new DomainError('Stripe is not configured. Ask the platform administrator to enable secure card setup.',503);
        $secret=config('Billing')->stripeSecretKey;
        $handle=curl_init('https://api.stripe.com/v1/'.$path);
        $headers=['Authorization: Bearer '.$secret,'Stripe-Version: 2024-06-20'];
        if($key)$headers[]='Idempotency-Key: '.$key;
        curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>$headers]);
        if($method==='POST')curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
        $body=curl_exec($handle);$status=curl_getinfo($handle,CURLINFO_HTTP_CODE);curl_close($handle);
        $result=is_string($body)?json_decode($body,true):null;
        if($status<200||$status>=300||!is_array($result))throw new DomainError('Stripe could not complete the request. Retry or contact support.',502);
        if(isset($result['livemode']) && (bool)$result['livemode']!==str_starts_with($secret,'sk_live_'))throw new DomainError('Stripe mode mismatch.',502);
        return $result;
    }
}
