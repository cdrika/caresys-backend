<?php
namespace App\Controllers;
use App\Services\{StripeWebhookSignature,Workspace,TrialBillingService,DomainError};
class StripeWebhookController extends BaseController
{
    public function receive()
    {
        $response=$this->response->setHeader('Cache-Control','no-store');$body=$this->request->getBody();
        if(!StripeWebhookSignature::valid($body,$this->request->getHeaderLine('Stripe-Signature'),config('Billing')->stripeWebhookSecret))return $response->setStatusCode(400)->setJSON(['error'=>'Invalid signature']);
        $event=json_decode($body,true);
        if(!in_array($event['type']??'',['checkout.session.completed','checkout.session.async_payment_succeeded'],true))return $response->setJSON(['received'=>true]);
        $row=db_connect()->table('cs_billing_checkouts c')->select('c.ref,c.created_by,a.ref AS agency_ref')->join('cs_agencies a','a.id=c.agency_id')->where('c.stripe_session',$event['data']['object']['id']??'')->get()->getRowArray();
        if(!$row)return $response->setStatusCode(409)->setJSON(['error'=>'Checkout not yet available']);
        try{(new TrialBillingService(new Workspace((int)$row['created_by'],$row['agency_ref'],null,true)))->complete($row['ref']);return $response->setJSON(['received'=>true]);}
        catch(DomainError $e){return $response->setStatusCode(409)->setJSON(['error'=>'Checkout verification pending']);}
        catch(\Throwable $e){return $response->setStatusCode(503)->setJSON(['error'=>'Retry verification']);}
    }
}
