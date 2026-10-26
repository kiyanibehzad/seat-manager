<?php
declare(strict_types=1);
namespace AccManager\Operations;
use AccManager\{App,ApiError,Input};
final class Domain
{
    public function __construct(public App $app) {}
    public function tx(array $actor,string $permission,string $action,array $data,callable $callback): array
    {
        $key=$data['idempotency_key']??'';
        if (!is_string($key)||!preg_match('/^[A-Za-z0-9_.:-]{8,100}$/D',$key)) { Input::invalid('idempotency_key'); }
        $hash=hash('sha256',$action.':'.json_encode($this->canonical($data),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        return $this->app->db->transaction(function () use ($actor,$permission,$key,$hash,$callback) {
            $this->lock();
            $fresh=$this->app->db->one('SELECT * FROM users WHERE id=? FOR UPDATE',[$actor['id']]);
            if (!$fresh||!$this->app->auth->eligible($fresh)||(int)$fresh['auth_version']!==(int)$actor['auth_version']) { throw new ApiError(401,'unauthenticated','دوباره وارد شوید.'); }
            $this->app->auth->requirePermission($fresh,$permission);
            $command=$this->app->db->one('SELECT * FROM operation_commands WHERE actor_id=? AND command_key=?',[$actor['id'],$key]);
            if ($command) { if (!hash_equals($command['payload_hash'],$hash)) { $this->conflict('کلید تکراری برای محتوای متفاوت استفاده شده است.'); } return json_decode($command['response_json'],true,64,JSON_THROW_ON_ERROR); }
            $result=$callback($fresh);
            $this->app->db->run('INSERT INTO operation_commands(actor_id,command_key,payload_hash,response_json) VALUES(?,?,?,?)',[$actor['id'],$key,$hash,json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
            return $result;
        });
    }
    public function lock(): void
    {
        // One consistent lock order across identity, seat operations, bulk and reminders.
        $this->app->db->one("SELECT value_json FROM settings WHERE setting_key='identity_guard' FOR UPDATE");
        $this->app->db->one("SELECT value_json FROM settings WHERE setting_key='operations_guard' FOR UPDATE");
    }
    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value); }
        foreach ($value as &$item) { $item=$this->canonical($item); } unset($item); return $value;
    }
    public function conflict(string $message='وضعیت تغییر کرده است؛ اطلاعات را دوباره بخوانید.'): never { throw new ApiError(409,'conflict',$message); }
    public function missing(): never { throw new ApiError(404,'not_found','مورد پیدا نشد.'); }
    public static function iso(?string $date): ?string { return $date===null?null:str_replace(' ','T',$date).'Z'; }
    public static function date(mixed $value,string $field): ?string
    {
        if ($value===null) { return null; }
        if (!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/D',$value)) { Input::invalid($field,'تاریخ و ساعت با منطقهٔ زمانی لازم است.'); }
        try { $date=new \DateTimeImmutable($value); $errors=\DateTimeImmutable::getLastErrors(); }
        catch (\Exception $e) { Input::invalid($field); }
        if ($errors && ($errors['warning_count']||$errors['error_count'])) { Input::invalid($field); }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    public static function iranDay(?string $date=null): string { return (new \DateTimeImmutable($date===null?'now':$date.' UTC'))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d'); }
    public static function state(array $data,bool $allowPast=true): array
    {
        $seat=$data['seat']??'';
        if (!in_array($seat,['none','standard','premium'],true)) { Input::invalid('seat'); }
        $start=self::date($data['starts_at']??null,'starts_at'); $end=self::date($data['ends_at']??null,'ends_at');
        if ($seat==='none' && ($start!==null||$end!==null)) { Input::invalid('seat','No Seat تاریخ تخصیص نمی‌گیرد.'); }
        if (($start===null)!==($end===null)||($end!==null&&$end<$start)) { Input::invalid('ends_at','بازهٔ شروع و پایان معتبر لازم است.'); }
        if (!$allowPast && $start!==null && self::iranDay($start)<self::iranDay()) { Input::invalid('starts_at','شروع در روز گذشته مجاز نیست.'); }
        $result=['seat'=>$seat,'starts_at'=>$start,'ends_at'=>$end];
        if (array_key_exists('return_seat',$data)) { if (!in_array($data['return_seat'],['none','standard','premium'],true)) { Input::invalid('return_seat'); } $result['return_seat']=$data['return_seat']; }
        return $result;
    }
    public function accountSql(): string
    {
        return "SELECT x.*,a.id assignment_id,a.owner_id assignment_owner_id,a.seat,a.starts_at,a.ends_at,a.return_seat,a.assigned_at,a.version assignment_version,l.operation_id,u.first_name,u.last_name,u.is_active,u.approval_status,u.email_verified_at,u.department_id,u.organizational_position,u.special_priority,d.name department_name,(COALESCE(d.base_score,1)+(u.organizational_position='supervisor')+u.special_priority) score FROM openai_accounts x JOIN users u ON u.id=x.owner_id LEFT JOIN departments d ON d.id=u.department_id LEFT JOIN seat_assignments a ON a.account_id=x.id AND a.closed_at IS NULL LEFT JOIN account_operation_locks l ON l.account_id=x.id";
    }
    public function account(int $id): array { return $this->app->db->one($this->accountSql().' WHERE x.id=?',[$id])??$this->missing(); }
    public function version(array $account,array $data,string $field='state_version'): void { if (!isset($data[$field])||!is_int($data[$field])||$data[$field]<0) { Input::invalid($field); } if ($data[$field]!==(int)$account['state_version']) { $this->conflict(); } }
    public function availableAccount(array $account,bool $allowInactive=false): void
    {
        if ($account['ownership_status']!=='clear'||($account['assignment_owner_id']!==null&&(int)$account['assignment_owner_id']!==(int)$account['owner_id'])) { $this->conflict('مالکیت این حساب نیاز به بازبینی دارد.'); }
        if (!$allowInactive && (!(bool)$account['is_active']||$account['approval_status']!=='approved'||!$account['email_verified_at'])) { $this->conflict('مالک حساب فعال نیست.'); }
        if ($account['operation_id']!==null) { $this->conflict('این حساب در عملیات جاری قفل است.'); }
    }
    public function publicAccount(array $row,bool $admin=false): array
    {
        $out=['id'=>(int)$row['id'],'owner_id'=>(int)$row['owner_id'],'email'=>$row['email'],'alias'=>$row['alias'],'seat'=>$row['seat']??'none','assignment_id'=>$row['assignment_id']===null?null:(int)$row['assignment_id'],'state_version'=>(int)$row['state_version'],'assignment_version'=>$row['assignment_version']===null?null:(int)$row['assignment_version'],'starts_at'=>self::iso($row['starts_at']),'ends_at'=>self::iso($row['ends_at']),'assigned_at'=>self::iso($row['assigned_at']),'return_seat'=>$row['return_seat']??'none','locked'=>$row['operation_id']!==null,'needs_review'=>$row['ownership_status']!=='clear'||($row['assignment_owner_id']!==null&&(int)$row['assignment_owner_id']!==(int)$row['owner_id'])];
        if ($admin) { $out+=['first_name'=>$row['first_name'],'last_name'=>$row['last_name'],'is_active'=>(bool)$row['is_active'],'department_id'=>$row['department_id']===null?null:(int)$row['department_id'],'department_name'=>$row['department_name'],'organizational_position'=>$row['organizational_position'],'special_priority'=>(bool)$row['special_priority'],'score'=>(int)$row['score'],'operation_id'=>$row['operation_id']===null?null:(int)$row['operation_id']]; }
        return $out;
    }
    public function snapshot(array $account): array
    {
        return ['seat'=>$account['seat']??'none','starts_at'=>$account['starts_at'],'ends_at'=>$account['ends_at'],'return_seat'=>$account['return_seat']??'none','assigned_at'=>$account['assigned_at'],'assignment_id'=>$account['assignment_id']===null?null:(int)$account['assignment_id'],'assignment_version'=>$account['assignment_version']===null?null:(int)$account['assignment_version'],'state_version'=>(int)$account['state_version']];
    }
    public function history(array $account,int $actor,string $action,array $before,array $after,string $reason='',string $evidence='',?string $observed=null,?int $operation=null,?int $request=null): void
    {
        $this->app->db->run('INSERT INTO seat_history(operation_id,request_id,account_id,owner_id,actor_id,action,before_json,after_json,reason,evidence,observed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$operation,$request,$account['id'],$account['owner_id'],$actor,$action,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),$reason,$evidence,$observed]);
    }
    public function managers(string $key,string $type,string $title,string $body,string $url): void
    {
        foreach ($this->app->auth->recipients('seats.manage') as $recipient) { $this->app->events->notify((int)$recipient,$key,$type,$title,$body,$url); }
    }
    public function apply(array $account,array $state,int $actor,string $action,string $reason,string $evidence,string $observed,?int $operation=null,?int $request=null): array
    {
        $db=$this->app->db; $before=$this->snapshot($account); $oldSeat=$before['seat']; $return=$state['return_seat']??($state['seat']===$oldSeat?$before['return_seat']:$oldSeat);
        $changed=$oldSeat!==$state['seat']||$before['starts_at']!==$state['starts_at']||$before['ends_at']!==$state['ends_at']||($state['seat']!=='none'&&$before['return_seat']!==$return);
        if ($changed) {
            if ($account['assignment_id']!==null) { (new \AccManager\Reminders($this->app))->cancel((int)$account['assignment_id']); }
            if ($oldSeat!==$state['seat']) {
                if ($account['assignment_id']!==null) { $db->run('UPDATE seat_assignments SET closed_at=? WHERE id=?',[$observed,$account['assignment_id']]); }
                if ($state['seat']!=='none') { $db->run('INSERT INTO seat_assignments(account_id,owner_id,seat,starts_at,ends_at,return_seat,assigned_at) VALUES(?,?,?,?,?,?,?)',[$account['id'],$account['owner_id'],$state['seat'],$state['starts_at'],$state['ends_at'],$return,$observed]); }
            } elseif ($state['seat']!=='none') { $db->run('UPDATE seat_assignments SET starts_at=?,ends_at=?,return_seat=?,version=version+1 WHERE id=?',[$state['starts_at'],$state['ends_at'],$return,$account['assignment_id']]); }
            $db->run('UPDATE openai_accounts SET state_version=state_version+1 WHERE id=?',[$account['id']]);
        }
        $afterAccount=$this->account((int)$account['id']); $after=$this->snapshot($afterAccount);
        $this->history($afterAccount,$actor,$action,$before,$after,$reason,$evidence,$observed,$operation,$request);
        if ($changed) {
            if ($after['seat']==='premium'&&$after['ends_at']!==null) {
                (new \AccManager\Reminders($this->app))->schedulePremium((int)$account['owner_id'],(int)$after['assignment_id'],(int)$after['assignment_version'],new \DateTimeImmutable($after['ends_at'].' UTC'),$this->app->url('view=my-requests&renew_account='.(int)$account['id']));
            }
            $labels=['none'=>'No Seat','standard'=>'Standard','premium'=>'Premium'];
            $this->app->events->notify((int)$account['owner_id'],'seat-state:'.$account['id'].':'.$after['state_version'],'seat.changed','وضعیت حساب شما ثبت شد','حساب '.$account['email'].' از '.$labels[$oldSeat].' به '.$labels[$after['seat']].' ثبت شد.',$this->app->url('view=my-accounts&account='.(int)$account['id']));
        }
        return $afterAccount;
    }
}
