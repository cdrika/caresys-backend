<?php
namespace App\Filters;

use CodeIgniter\Filters\PageCache;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Never read or write a shared page cache for mobile credentials or data. */
class PrivateApiPageCache extends PageCache
{
    private function privatePath(RequestInterface $request): bool
    {
        if (!$request instanceof \CodeIgniter\HTTP\IncomingRequest) return false;
        $path=trim($request->getUri()->getPath(),'/');
        return preg_match('~(?:^|/)(?:mobile/v1|mobile-enrollment)(?:/|$)~D',$path)===1;
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        if ($this->privatePath($request)) return null;
        return parent::before($request,$arguments);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if ($this->privatePath($request)) return null;
        return parent::after($request,$response,$arguments);
    }
}
