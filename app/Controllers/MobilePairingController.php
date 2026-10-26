<?php
namespace App\Controllers;

use App\Services\{DomainError,MobilePairingService};

class MobilePairingController extends BaseController
{
    public function testDevice()
    {
        if (ENVIRONMENT!=='development' || !$this->request->isSecure() || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)) {
            return $this->response->setStatusCode(404)->setJSON(['error'=>['code'=>'NOT_FOUND']]);
        }
        $nonce=base64_encode(random_bytes(24));
        return $this->response->setHeader('Cache-Control','no-store, private')->setHeader('Referrer-Policy','no-referrer')
            ->setHeader('Content-Security-Policy',"default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'")
            ->setBody(view('mobile/test-device',compact('nonce')));
    }

    private function execute(string $field, callable $work)
    {
        try {
            $data=$this->request->getJSON(true);
            if (array_keys($data)!==[$field] || !is_string($data[$field])) throw new DomainError('Invalid pairing fields.',400);
            return $this->response->setJSON(['data'=>$work($data[$field])]);
        } catch (DomainError $e) {
            return $this->response->setStatusCode($e->status)->setJSON(['error'=>['code'=>$e->status===428?'AUTHORIZATION_PENDING':'PAIRING_FAILED','message'=>$e->getMessage()]]);
        }
    }

    public function start()
    {
        return $this->execute('device_name',function($name) {
            $result=(new MobilePairingService())->start($name);
            $result['verification_uri']=config('Mobile')->pairingUrl();
            $this->response->setStatusCode(201);
            return $result;
        });
    }

    public function token()
    {
        return $this->execute('device_code',fn($code)=>(new MobilePairingService())->redeem($code));
    }
}
