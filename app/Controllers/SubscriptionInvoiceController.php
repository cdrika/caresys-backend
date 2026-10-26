<?php
namespace App\Controllers;

use App\Services\{Workspace,SubscriptionBillingService,DomainError};
use App\Libraries\SubscriptionInvoicePdf;

class SubscriptionInvoiceController extends BaseController
{
    public function download(string $agencyRef,string $ref)
    {
        try {
            $invoice=(new SubscriptionBillingService(new Workspace((int)auth()->id(),$agencyRef)))->get($ref);
            return $this->response->setHeader('Cache-Control','no-store, private')->setHeader('X-Content-Type-Options','nosniff')->setContentType('application/pdf')->setHeader('Content-Disposition','attachment; filename="'.$invoice['invoice_no'].'.pdf"')->setBody(SubscriptionInvoicePdf::render($invoice));
        }catch(DomainError $e){return $this->response->setStatusCode($e->status)->setHeader('Cache-Control','no-store')->setBody(view('caresys/error',['message'=>$e->getMessage(),'status'=>$e->status]));}
    }
}
