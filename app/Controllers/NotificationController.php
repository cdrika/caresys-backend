<?php
namespace App\Controllers;
use App\Services\{Workspace,NotificationService,DomainError};
class NotificationController extends BaseController
{
    public function menu()
    {
        try{$w=new Workspace((int)auth()->id());$data=(new NotificationService($w))->inbox();return $this->response->setHeader('Cache-Control','no-store, private')->setJSON(['unread'=>$data['unread'],'html'=>view('components/notification_items',$data)]);}
        catch(DomainError $e){return $this->response->setStatusCode($e->status)->setHeader('Cache-Control','no-store')->setJSON(['error'=>'Notifications unavailable.']);}
    }
    public function read(string $ref)
    {
        try{
            if(array_diff(array_keys($this->request->getPost()),['csrf_test_name']))throw new DomainError('Unsupported fields.');
            $w=new Workspace((int)auth()->id());(new NotificationService($w))->markRead($ref);
            return redirect()->to('/app/notifications')->with('message','Notification marked as read.');
        }catch(DomainError $e){return $this->response->setStatusCode($e->status)->setHeader('Cache-Control','no-store')->setBody(view('caresys/error',['message'=>$e->getMessage(),'status'=>$e->status]));}
    }
}
