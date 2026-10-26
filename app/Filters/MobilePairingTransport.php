<?php
namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class MobilePairingTransport implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response=service('response')->setHeader('Cache-Control','no-store, private');
        $fail=static fn($status,$code)=>$response->setStatusCode($status)->setJSON(['error'=>['code'=>$code]]);
        if (!$request->isSecure()) return $fail(403,'HTTPS_REQUIRED');
        if ($request->getHeaderLine('Cookie')!=='' || $request->getHeaderLine('Authorization')!=='') return $fail(400,'CREDENTIALS_NOT_ALLOWED');
        $start=str_ends_with(trim($request->getUri()->getPath(),'/'),'/requests');
        if (!service('throttler')->check('mobile_pair_'.($start?'start_':'poll_').hash('sha256',$request->getIPAddress()),$start?5:60,60)) return $fail(429,'RATE_LIMITED')->setHeader('Retry-After','60');
        if (strtolower(trim(explode(';',$request->getHeaderLine('Content-Type'))[0]))!=='application/json') return $fail(415,'JSON_REQUIRED');
        if (strlen($request->getBody())>2048) return $fail(413,'BODY_TOO_LARGE');
        try { $data=json_decode($request->getBody(),false,8,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return $fail(400,'INVALID_JSON'); }
        if (!$data instanceof \stdClass) return $fail(400,'JSON_OBJECT_REQUIRED');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control','no-store, private')->setHeader('Pragma','no-cache');
    }
}
