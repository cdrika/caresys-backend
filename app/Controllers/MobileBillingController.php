<?php
namespace App\Controllers;
class MobileBillingController extends BillingApiController
{
    protected function actorId(): int{return (int)auth('tokens')->id();}
    protected function checkWriteWorkspace(?string $agency): void{}
}
