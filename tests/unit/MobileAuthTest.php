<?php

use App\Filters\MobileAuth;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\HTTP\IncomingRequest;

final class MobileAuthTest extends CIUnitTestCase
{
    public function testMobileAndEnrollmentPathsBypassSharedPageCache(): void
    {
        foreach (['mobile/v1/me/profile','mobile-enrollment/tokens','CareSys/public/mobile/v1/me/profile'] as $path) {
            $request=$this->getMockBuilder(IncomingRequest::class)->disableOriginalConstructor()->onlyMethods(['getUri'])->getMock();
            $request->method('getUri')->willReturn(new \CodeIgniter\HTTP\URI('https://example.com/'.$path));
            $filter=new \App\Filters\PrivateApiPageCache();
            $this->assertNull($filter->before($request));
            $this->assertNull($filter->after($request,service('response')));
        }
    }

    private function request(bool $secure, array $headers = []): IncomingRequest
    {
        $request = $this->getMockBuilder(IncomingRequest::class)->disableOriginalConstructor()
            ->onlyMethods(['isSecure','getIPAddress','getHeaderLine'])->getMock();
        $request->method('isSecure')->willReturn($secure);
        $request->method('getIPAddress')->willReturn('192.0.2.'.random_int(1,254));
        $request->method('getHeaderLine')->willReturnCallback(static fn($name) => $headers[$name] ?? '');
        return $request;
    }

    public function testPlainHttpIsRejected(): void
    {
        $response=(new MobileAuth())->before($this->request(false));
        $this->assertSame(403,$response->getStatusCode());
        $this->assertStringContainsString('HTTPS_REQUIRED',$response->getBody());
    }

    public function testBrowserCookiesCannotAuthenticateMobileRequests(): void
    {
        $response=(new MobileAuth())->before($this->request(true,['Cookie'=>'ci_session=browser']));
        $this->assertSame(400,$response->getStatusCode());
        $this->assertStringContainsString('COOKIES_NOT_ALLOWED',$response->getBody());
    }

    public function testMissingAndMalformedAuthorizationAreRejected(): void
    {
        foreach (['','Basic abc','Bearer short','Bearer '.str_repeat('a',64).' extra'] as $header) {
            $response=(new MobileAuth())->before($this->request(true,['Authorization'=>$header]));
            $this->assertSame(401,$response->getStatusCode());
            $this->assertSame('Bearer',$response->getHeaderLine('WWW-Authenticate'));
            $this->assertStringContainsString('no-store',$response->getHeaderLine('Cache-Control'));
        }
    }
}
