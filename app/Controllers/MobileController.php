<?php
namespace App\Controllers;

/** Mobile authentication is enforced by MobileAuth, never by browser cookies. */
class MobileController extends ApiController
{
    protected function actorId(): int { return (int)auth('tokens')->id(); }

    protected function checkWriteWorkspace(?string $agency): void
    {
        // The tenant is explicit in the route and checked by Workspace on every call.
    }

    public function logout()
    {
        $auth = auth('tokens');
        $auth->user()->revokeAccessToken($auth->getBearerToken());
        return $this->response->setJSON(['data'=>['state'=>'revoked']]);
    }
}
