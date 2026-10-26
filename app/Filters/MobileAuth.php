<?php
namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Services\{Workspace, DomainError};

class MobileAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response = service('response')->setHeader('Cache-Control', 'no-store, private');
        $fail = static fn(int $status, string $code) => $response->setStatusCode($status)->setJSON(['error'=>['code'=>$code]]);
        if (!$request->isSecure()) return $fail(403, 'HTTPS_REQUIRED');
        if (!service('throttler')->check('mobile_ip_'.hash('sha256',$request->getIPAddress()),120,60)) {
            $response->setHeader('Retry-After','60');
            return $fail(429,'RATE_LIMITED');
        }
        if ($request->getHeaderLine('Cookie') !== '') return $fail(400,'COOKIES_NOT_ALLOWED');
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer [a-zA-Z0-9]{64}$/D',$header)) return $fail(401,'INVALID_TOKEN')->setHeader('WWW-Authenticate','Bearer');
        $auth = auth('tokens');
        // Shield's attempt() records the supplied raw credential on failure. Validate
        // directly so bearer secrets never enter authentication-attempt records.
        $authenticator=$auth->getAuthenticator();
        $result=$authenticator->check(['token'=>substr($header,7)]);
        if (!$result->isOK() || !$result->extraInfo() || $result->extraInfo()->isBanned()) return $fail(401,'INVALID_TOKEN')->setHeader('WWW-Authenticate','Bearer');
        $authenticator->login($result->extraInfo());
        $token = $auth->user()->currentAccessToken();
        // Reject web/integration tokens, wildcard grants and tokens without a bounded lifetime.
        if (!$token || !str_starts_with((string)$token->name,'mobile:') || $token->scopes !== ['mobile:v1'] || !$token->expires || $token->expires->getTimestamp() <= time() || $token->expires->getTimestamp() > time()+900) return $fail(403,'INVALID_MOBILE_SCOPE');
        try { new Workspace((int)$auth->id()); }
        catch (DomainError $e) { return $fail($e->status,'ACCOUNT_UNAVAILABLE'); }
        if (!service('throttler')->check('mobile_user_'.$auth->id(),60,60)) return $fail(429,'RATE_LIMITED')->setHeader('Retry-After','60');
        if (!in_array(strtoupper($request->getMethod()),['GET','DELETE'],true)) {
            if (strtolower(trim(explode(';',$request->getHeaderLine('Content-Type'))[0])) !== 'application/json') return $fail(415,'JSON_REQUIRED');
            if (strlen($request->getBody()) > 65536) return $fail(413,'BODY_TOO_LARGE');
            try { $body = json_decode($request->getBody(),false,32,JSON_THROW_ON_ERROR); }
            catch (\JsonException) { return $fail(400,'INVALID_JSON'); }
            if (!$body instanceof \stdClass) return $fail(400,'JSON_OBJECT_REQUIRED');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control','no-store, private')->setHeader('Pragma','no-cache')->setHeader('X-Content-Type-Options','nosniff');
    }
}
