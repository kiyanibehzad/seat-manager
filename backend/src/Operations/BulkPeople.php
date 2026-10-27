<?php
declare(strict_types=1);
namespace AccManager\Operations;
use AccManager\{Input,ApiError};
final class BulkPeople
{
    public function __construct(private Domain $d) {}
    private function validate(array $rows): array
    {
        $result=[]; $emails=[]; $openai=[];
        foreach ($rows as $index=>$row) {
            $normalized=null; $errors=[];
            try {
                if (!is_array($row)) { Input::invalid('row'); }
                Input::only($row,['email','first_name','last_name','department_id','organizational_position','employee_number','initial_openai_email']);
                $normalized=['email'=>Input::email($row),'first_name'=>Input::text($row,'first_name'),'last_name'=>Input::text($row,'last_name'),'department_id'=>Input::id($row,'department_id'),'organizational_position'=>Input::position($row),'employee_number'=>isset($row['employee_number'])&&$row['employee_number']!==''?Input::text($row,'employee_number',64):null,'initial_openai_email'=>isset($row['initial_openai_email'])&&$row['initial_openai_email']!==''?Input::email(['email'=>$row['initial_openai_email']]):null];
                if (!$this->d->app->db->one('SELECT id FROM departments WHERE id=?',[$normalized['department_id']])) { $errors['department_id']='بخش پیدا نشد.'; }
                if (isset($emails[$normalized['email']])||$this->d->app->db->one('SELECT id FROM users WHERE email=?',[$normalized['email']])) { $errors['email']='ایمیل برنامه تکراری است.'; } $emails[$normalized['email']]=true;
                $mail=$normalized['initial_openai_email'];
                if ($mail!==null) { if (isset($openai[$mail])||$this->d->app->db->one('SELECT id FROM openai_accounts WHERE email=?',[$mail])) { $errors['initial_openai_email']='ایمیل حساب OpenAI تکراری است.'; } $openai[$mail]=true; }
            } catch (ApiError $e) { $errors=$e->fields?:['row'=>$e->getMessage()]; }
            $result[]=['row'=>$index+1,'normalized'=>$normalized,'errors'=>(object)$errors];
        }
        return $result;
    }
    public function preview(array $data,array $actor): array
    {
        Input::only($data,['rows']); $rows=$data['rows']??null;
        if (!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>200) { Input::invalid('rows'); }
        $this->d->app->auth->requirePermission($actor,'people.manage');
        return $this->d->app->db->transaction(function () use ($rows,$actor) {
            $this->d->lock(); $fresh=$this->d->app->auth->user((int)$actor['id']);
            if (!$fresh||!$this->d->app->auth->eligible($fresh)||(int)$fresh['auth_version']!==(int)$actor['auth_version']) { throw new ApiError(401,'unauthenticated','دوباره وارد شوید.'); }
            $this->d->app->auth->requirePermission($fresh,'people.manage');
            $validated=$this->validate($rows); $valid=!array_filter($validated,fn($row)=>(array)$row['errors']!==[]); $expires=gmdate('Y-m-d H:i:s',time()+3600);
            $this->d->app->db->run('INSERT INTO user_bulk_batches(actor_id,payload_encrypted,expires_at) VALUES(?,?,?)',[$actor['id'],$this->d->app->crypto->encrypt(['rows'=>$rows]),$expires]);
            return ['batch_id'=>(int)$this->d->app->db->pdo->lastInsertId(),'expires_at'=>Domain::iso($expires),'rows'=>$validated,'valid'=>$valid];
        });
    }
    public function commit(array $data,array $actor): array
    {
        Input::only($data,['batch_id','idempotency_key']);
        return $this->d->tx($actor,'people.manage','people.bulk_commit',$data,function ($fresh) use ($data) {
            $db=$this->d->app->db; $batch=$db->one('SELECT * FROM user_bulk_batches WHERE id=? AND actor_id=? FOR UPDATE',[Input::id($data,'batch_id'),$fresh['id']]);
            if (!$batch) { $this->d->missing(); }
            if ($batch['status']==='committed') { return json_decode($batch['result_json'],true,64,JSON_THROW_ON_ERROR); }
            if (strtotime($batch['expires_at'].' UTC')<=time()) { $this->d->conflict('پیش‌نمایش منقضی شده است؛ دوباره بررسی کنید.'); }
            $rows=$this->d->app->crypto->decrypt($batch['payload_encrypted'])['rows']; $validated=$this->validate($rows);
            if (array_filter($validated,fn($row)=>(array)$row['errors']!==[])) { throw new ApiError(422,'validation_failed','همهٔ ردیف‌ها باید بدون خطا باشند.',['rows'=>$validated]); }
            $users=[];
            foreach ($validated as $item) {
                $row=$item['normalized']; $password=bin2hex(random_bytes(12));
                $db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,organizational_position,employee_number,email_verified_at,approval_status,approved_by,approved_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),'approved',?,UTC_TIMESTAMP())",[$row['email'],\AccManager\Passwords::hash($password),$row['first_name'],$row['last_name'],$row['department_id'],$row['organizational_position'],$row['employee_number'],$fresh['id']]);
                $id=(int)$db->pdo->lastInsertId();
                $this->d->app->accessGroups->initializeUser($id);
                if ($row['initial_openai_email']!==null) { (new Accounts($this->d))->createInTx($id,$row['initial_openai_email']); }
                $this->d->app->mail->enqueue($id,'initial-password:'.$id,$row['email'],'حساب برنامه ساخته شد',"آدرس ورود: ".$this->d->app->url()."\nرمز اولیه: ".$password,null,'initial_password');
                $this->d->app->audit('user.created',(int)$fresh['id'],$id); $users[]=$this->d->app->auth->publicUser($this->d->app->auth->user($id));
            }
            $result=['users'=>$users]; $db->run("UPDATE user_bulk_batches SET status='committed',result_json=? WHERE id=?",[json_encode($result,JSON_THROW_ON_ERROR),$batch['id']]);
            return $result;
        });
    }
}
