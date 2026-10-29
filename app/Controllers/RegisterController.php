<?php
namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Database\Exceptions\DatabaseException;

/** One personal identity per email and username, regardless of subscription or account status. */
class RegisterController extends \CodeIgniter\Shield\Controllers\RegisterController
{
    public function registerAction(): RedirectResponse
    {
        $data=$this->request->getPost();
        if(is_string($data['email']??null))$data['email']=strtolower(trim($data['email']));
        if(is_string($data['username']??null))$data['username']=trim($data['username']);
        $this->request->setGlobal('post',$data);
        // Keep user and password identity inserts atomic when simultaneous signups race.
        $db=db_connect(config('Auth')->DBGroup);$db->transException(true)->transBegin();
        try{
            $response=parent::registerAction();
            if(!$db->transStatus())throw new DatabaseException('Registration transaction failed.');
            $db->transCommit();return $response;
        }catch(DatabaseException $e){
            $db->transRollback();
            if(str_contains(strtolower($e->getMessage()),'duplicate'))return redirect()->back()->withInput()->with('error','That email or account name is already registered. Sign in to your existing account or contact support to restore access.');
            throw $e;
        }catch(\Throwable $e){$db->transRollback();throw $e;}
    }

    protected function getValidationRules(): array
    {
        $rules=parent::getValidationRules();
        $rules['email']['errors']['is_unique']='This email is already registered. Sign in to your existing account or contact support to restore access.';
        $rules['username']['errors']['is_unique']='This account name is already registered. Choose another name or sign in to your existing account.';
        return $rules;
    }
}
