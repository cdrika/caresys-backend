<?php
namespace App\Controllers;

use App\Services\{Workspace, DomainError, MobilePairingService};
use CodeIgniter\I18n\Time;

/** Browser-only, CSRF-protected enrollment; never accept bearer credentials here. */
class MobileEnrollmentController extends BaseController
{
    private function screen(string $error='', string $success='', ?array $device=null, string $code='', int $status=200)
    {
        $response=$this->response->setHeader('Cache-Control','no-store, private')->setHeader('Referrer-Policy','no-referrer')
            ->setHeader('Content-Security-Policy',"default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        if (!$this->request->isSecure()) {
            $target=config('Mobile')->pairingUrl();
            if (str_starts_with($target,'https://')) return redirect()->to($target);
            return $response->setStatusCode(403)->setBody('HTTPS is required to connect a mobile device.');
        }
        $devices=array_values(array_filter(auth('session')->user()->accessTokens(),static fn($token)=>str_starts_with((string)$token->name,'mobile:') && $token->expires && $token->expires->getTimestamp()>time()));
        $testDeviceUrl=ENVIRONMENT==='development' ? config('Mobile')->testDeviceUrl() : '';
        return $response->setStatusCode($status)->setBody(view('mobile/pairing',compact('error','success','device','code','devices','testDeviceUrl')));
    }

    public function index() { return $this->screen(); }

    private function pairingAction(bool $approve)
    {
        if (!$this->request->isSecure()) return $this->screen();
        $user=auth('session')->user();
        if (!service('throttler')->check('pairing_approval_'.$user->id,10,300)) {
            $this->response->setHeader('Retry-After','300');
            return $this->screen('Too many pairing attempts. Try again in five minutes.','',null,'',429);
        }
        try {
            new Workspace((int)$user->id);
            $code=$this->request->getPost('user_code');
            if (!is_string($code) || strlen($code)>16) throw new DomainError('Enter the code shown on your device.',400);
            $code=implode('-',str_split(MobilePairingService::normalizeCode($code),4));
            $service=new MobilePairingService();
            if (!$approve) return $this->screen('','',$service->review($code),$code);
            $password=$this->request->getPost('password');
            if ($this->request->getPost('confirm')!=='yes' || !is_string($password)) throw new DomainError('Confirm the device and your password.',400);
            $service->approve($code,(int)$user->id,$password);
            return $this->screen('','Device approved. Return to the mobile app to finish connecting.');
        } catch (DomainError $e) {
            $message=$e->status===404 ? 'That code is not active. Request a new code on your device, then enter it here. Example codes cannot be used to connect.' : $e->getMessage();
            return $this->screen($message,'',null,'',$e->status);
        }
    }

    public function review() { return $this->pairingAction(false); }
    public function approve() { return $this->pairingAction(true); }

    public function revoke()
    {
        if (!$this->request->isSecure()) return $this->screen();
        $user=auth('session')->user();
        $id=$this->request->getPost('token_id');
        if (!is_string($id) || !ctype_digit($id) || !$token=$user->getAccessTokenById((int)$id)) return $this->screen('Device unavailable.','',null,'',404);
        if (!str_starts_with((string)$token->name,'mobile:')) return $this->screen('Device unavailable.','',null,'',404);
        $user->revokeAccessTokenBySecret($token->secret);
        return $this->screen('','Device access revoked.');
    }

    public function create()
    {
        $response=$this->response->setHeader('Cache-Control','no-store, private');
        if (!$this->request->isSecure()) return $response->setStatusCode(403)->setJSON(['error'=>['code'=>'HTTPS_REQUIRED']]);
        $user=auth('session')->user();
        if (!service('throttler')->check('mobile_enroll_'.$user->id,5,300)) return $response->setStatusCode(429)->setHeader('Retry-After','300')->setJSON(['error'=>['code'=>'RATE_LIMITED']]);
        $data=$this->request->getPost();
        if (!is_string($data['password']??null) || strlen($data['password'])>255 || !is_string($data['device_name']??null) || !preg_match('/^[a-zA-Z0-9 _-]{1,60}$/D',$data['device_name'])) return $response->setStatusCode(400)->setJSON(['error'=>['code'=>'INVALID_INPUT']]);
        if (!service('passwords')->verify($data['password'],$user->password_hash)) return $response->setStatusCode(403)->setJSON(['error'=>['code'=>'PASSWORD_CONFIRMATION_FAILED']]);
        try { new Workspace((int)$user->id); }
        catch (DomainError $e) { return $response->setStatusCode($e->status)->setJSON(['error'=>['code'=>'ACCOUNT_UNAVAILABLE']]); }
        $expires=Time::now()->addSeconds(900);
        $token=$user->generateAccessToken('mobile:'.$data['device_name'],['mobile:v1'],$expires);
        return $response->setStatusCode(201)->setJSON(['data'=>['access_token'=>$token->raw_token,'token_type'=>'Bearer','expires_in'=>900]]);
    }
}
