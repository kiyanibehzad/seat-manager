<?php
declare(strict_types=1);
namespace AccManager;
final class People
{
    public function __construct(private App $app) {}
    private function guarded(array $actor,string $permission,callable $callback): mixed
    {
        return $this->app->db->transaction(function () use ($actor,$permission,$callback) {
            // Serializes identity administration, including the last active administrator check.
            $this->app->db->one("SELECT value_json FROM settings WHERE setting_key='identity_guard' FOR UPDATE");
            $fresh=$this->app->db->one('SELECT * FROM users WHERE id=? FOR UPDATE',[$actor['id']]);
            if (!$fresh || !$this->app->auth->eligible($fresh) || (int)$fresh['auth_version']!==(int)$actor['auth_version']) { throw new ApiError(401,'unauthenticated','دوباره وارد شوید.'); }
            $this->app->auth->requirePermission($fresh,$permission);
            return $callback($fresh);
        });
    }
    private function department(array $data): int
    {
        $id=Input::id($data,'department_id');
        if (!$this->app->db->one('SELECT id FROM departments WHERE id=?',[$id])) { Input::invalid('department_id'); }
        return $id;
    }
    private function target(array $data): array
    {
        $row=$this->app->db->one('SELECT * FROM users WHERE id=? FOR UPDATE',[Input::id($data)]);
        if (!$row) { throw new ApiError(404,'not_found','کاربر پیدا نشد.'); }
        return $row;
    }
    private function employeeNumber(array $data): ?string
    {
        if (!isset($data['employee_number']) || $data['employee_number']==='') { return null; }
        return Input::text($data,'employee_number',64);
    }
    public function listUsers(): array
    {
        // Profile fields and derived score come from one joined statement snapshot.
        // A registration without an assigned department has no base score yet.
        $sql="SELECT CASE WHEN d.id IS NULL THEN NULL ELSE d.base_score + (u.organizational_position='supervisor') + u.special_priority END score, ".substr($this->app->auth->userSelect(),strlen('SELECT '));
        return array_map(function (array $row): array {
            $user=$this->app->auth->publicUser($row);
            $user['score']=$row['score']===null?null:(int)$row['score'];
            return $user;
        },$this->app->db->all($sql.' ORDER BY u.id DESC LIMIT 500'));
    }
    public function registrations(): array
    { return array_map($this->app->auth->publicUser(...),$this->app->db->all($this->app->auth->userSelect()." WHERE u.approval_status='pending_approval' AND u.email_verified_at IS NOT NULL ORDER BY u.id LIMIT 500")); }
    public function create(array $data,array $actor): array
    {
        Input::only($data,['email','first_name','last_name','department_id','organizational_position','employee_number','initial_openai_email']);
        $email=Input::email($data); $first=Input::text($data,'first_name'); $last=Input::text($data,'last_name'); $position=Input::position($data); $employee=$this->employeeNumber($data);
        $initial=isset($data['initial_openai_email'])&&$data['initial_openai_email']!==''?Input::email(['email'=>$data['initial_openai_email']]):null;
        return $this->guarded($actor,'people.manage',function ($fresh) use ($email,$first,$last,$position,$employee,$data,$initial) {
            $department=$this->department($data);
            if ($this->app->db->one('SELECT id FROM users WHERE email=?',[$email])) { throw new ApiError(409,'conflict','این ایمیل قبلاً ثبت شده است.'); }
            $password=bin2hex(random_bytes(12));
            $this->app->db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,organizational_position,employee_number,email_verified_at,approval_status,approved_by,approved_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),'approved',?,UTC_TIMESTAMP())",[$email,password_hash($password,PASSWORD_DEFAULT),$first,$last,$department,$position,$employee,$fresh['id']]);
            $id=(int)$this->app->db->pdo->lastInsertId();
            $this->app->accessGroups->initializeUser($id);
            if ($initial!==null) {
                $domain=new \AccManager\Operations\Domain($this->app); $domain->lock();
                (new \AccManager\Operations\Accounts($domain))->createInTx($id,$initial);
            }
            $this->app->mail->enqueue($id,'initial-password:'.$id,$email,'حساب برنامه ساخته شد',"حساب برنامه برای شما ساخته شد.\nآدرس ورود: ".$this->app->url()."\nرمز اولیه: ".$password."\nمی‌توانید از بخش حساب، رمز را تغییر دهید.",null,'initial_password');
            $this->app->audit('user.created',(int)$fresh['id'],$id);
            return $this->app->auth->publicUser($this->app->auth->user($id));
        });
    }
    public function approve(array $data,array $actor): array
    {
        Input::only($data,['user_id','department_id','organizational_position']); $position=Input::position($data);
        return $this->guarded($actor,'registrations.review',function ($fresh) use ($data,$position) {
            $target=$this->target($data); $department=$this->department($data); $id=(int)$target['id'];
            if ($target['approval_status']!=='pending_approval'||!$target['email_verified_at']||!$target['is_active']) { throw new ApiError(409,'conflict','این ثبت‌نام آمادهٔ تأیید نیست.'); }
            $groupSet=$this->app->accessGroups->ready()?',access_group_id=1':'';
            $this->app->db->run("UPDATE users SET department_id=?,organizational_position=?,app_role='user'".$groupSet.",approval_status='approved',approved_by=?,approved_at=UTC_TIMESTAMP(),auth_version=auth_version+1 WHERE id=?",[$department,$position,$fresh['id'],$id]);
            $this->app->events->notify($id,'registration-approved:'.$id,'registration.approved','حساب شما فعال شد','ثبت‌نام شما تأیید شد. اکنون می‌توانید وارد برنامه شوید.',$this->app->url());
            $this->app->audit('registration.approved',(int)$fresh['id'],$id,['department_id'=>$department]);
            return $this->app->auth->publicUser($this->app->auth->user($id));
        });
    }
    public function reject(array $data,array $actor): array
    {
        Input::only($data,['user_id','reason']); $reason=Input::text($data,'reason',1000);
        return $this->guarded($actor,'registrations.review',function ($fresh) use ($data,$reason) {
            $target=$this->target($data); $id=(int)$target['id'];
            if ($target['approval_status']!=='pending_approval') { throw new ApiError(409,'conflict','این ثبت‌نام منتظر تأیید نیست.'); }
            $this->app->db->run("UPDATE users SET approval_status='rejected',auth_version=auth_version+1 WHERE id=?",[$id]);
            $this->app->events->notify($id,'registration-rejected:'.$id,'registration.rejected','نتیجهٔ بررسی ثبت‌نام','ثبت‌نام تأیید نشد. دلیل: '.$reason,$this->app->url());
            $this->app->audit('registration.rejected',(int)$fresh['id'],$id,['reason'=>$reason]);
            return $this->app->auth->publicUser($this->app->auth->user($id));
        });
    }
    public function update(array $data,array $actor): array
    {
        Input::only($data,['user_id','first_name','last_name','department_id','organizational_position','employee_number','special_priority']);
        return $this->guarded($actor,'people.manage',function ($fresh) use ($data) {
            $target=$this->target($data); $changes=[];
            foreach (['first_name','last_name'] as $key) { if (array_key_exists($key,$data)) { $changes[$key]=Input::text($data,$key); } }
            if (array_key_exists('department_id',$data)) { $changes['department_id']=$this->department($data); }
            if (array_key_exists('organizational_position',$data)) { $changes['organizational_position']=Input::position($data); }
            if (array_key_exists('employee_number',$data)) { $changes['employee_number']=$this->employeeNumber($data); }
            if (array_key_exists('special_priority',$data)&&$this->app->accessGroups->ready()) { $this->app->auth->requirePermission($fresh,'departments.manage'); }
            if (array_key_exists('special_priority',$data)) { $changes['special_priority']=(int)Input::boolean($data,'special_priority'); }
            if (!$changes) { Input::invalid('user_id','حداقل یک تغییر لازم است.'); }
            $parts=array_map(static fn($key)=>$key.'=?',array_keys($changes));
            $this->app->db->run('UPDATE users SET '.implode(',',$parts).' WHERE id=?',[...array_values($changes),$target['id']]);
            $this->app->audit('user.updated',(int)$fresh['id'],(int)$target['id'],['before'=>array_intersect_key($target,$changes),'after'=>$changes]);
            return $this->app->auth->publicUser($this->app->auth->user((int)$target['id']));
        });
    }
    private function protectLastAdmin(array $target): void
    {
        $this->app->accessGroups->protectLastChief($target);
    }

    public function status(array $data,array $actor): array
    {
        Input::only($data,['user_id','is_active']); $active=Input::boolean($data,'is_active');
        return $this->guarded($actor,'people.manage',function ($fresh) use ($data,$active) {
            $target=$this->target($data); $id=(int)$target['id'];
            if ((bool)$target['is_active']!==$active) {
                if (!$active) { $this->protectLastAdmin($target); }
                $this->app->db->run('UPDATE users SET is_active=?,auth_version=auth_version+1 WHERE id=?',[(int)$active,$id]);
                if (!$active) {
                    $this->app->db->run('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND consumed_at IS NULL',[$id]);
                    $this->app->db->run("UPDATE mail_outbox SET status='cancelled',payload_encrypted=NULL WHERE recipient_id=? AND category IN ('auth','initial_password') AND status IN ('pending','retry')",[$id]);
                }
                $this->app->audit('user.status_changed',(int)$fresh['id'],$id,['is_active'=>$active]);
            }
            return $this->app->auth->publicUser($this->app->auth->user($id));
        });
    }
    public function role(array $data,array $actor): array
    {
        Input::only($data,['user_id','app_role']); $role=$data['app_role']??'';
        if (!in_array($role,['user','admin','hr','operator'],true)) { Input::invalid('app_role'); }
        return $this->guarded($actor,'roles.manage',function ($fresh) use ($data,$role) {
            if ($this->app->accessGroups->ready()) { return $this->app->accessGroups->assignInTransaction(Input::id($data),$this->app->accessGroups->legacyGroupId($role),$fresh)['user']; }
            $target=$this->target($data); $id=(int)$target['id'];
            if ($target['approval_status']!=='approved') { throw new ApiError(409,'conflict','ابتدا ثبت‌نام باید تأیید شود.'); }
            if ($target['app_role']!==$role) {
                if ($role!=='admin') { $this->protectLastAdmin($target); }
                $this->app->db->run('UPDATE users SET app_role=?,auth_version=auth_version+1 WHERE id=?',[$role,$id]);
                $this->app->audit('user.role_changed',(int)$fresh['id'],$id,['before'=>['app_role'=>$target['app_role']],'after'=>['app_role'=>$role]]);
            }
            return $this->app->auth->publicUser($this->app->auth->user($id));
        });
    }
    public function departmentSave(array $data,array $actor): array
    {
        Input::only($data,['id','name','base_score']); $name=Input::text($data,'name',120); $score=$data['base_score']??null;
        if (!is_int($score)||$score<1||$score>5) { Input::invalid('base_score'); }
        return $this->guarded($actor,'departments.manage',function ($fresh) use ($data,$name,$score) {
            if (isset($data['id'])) {
                $id=Input::id($data,'id');
                if (!$this->app->db->one('SELECT id FROM departments WHERE id=?',[$id])) { throw new ApiError(404,'not_found','بخش پیدا نشد.'); }
                $this->app->db->run('UPDATE departments SET name=?,base_score=? WHERE id=?',[$name,$score,$id]);
            } else { $this->app->db->run('INSERT INTO departments(name,base_score) VALUES(?,?)',[$name,$score]); $id=(int)$this->app->db->pdo->lastInsertId(); }
            $this->app->audit('department.saved',(int)$fresh['id'],null,['department_id'=>$id]);
            return ['id'=>$id,'name'=>$name,'base_score'=>$score];
        });
    }
    public function settings(): array
    {
        return ['allowed_email_domains'=>array_column($this->app->db->all('SELECT domain FROM allowed_email_domains ORDER BY domain'),'domain'),'manual_action_hours'=>(int)json_decode($this->app->db->one("SELECT value_json FROM settings WHERE setting_key='manual_action_hours'")['value_json'],true)];
    }
    public function settingsSave(array $data,array $actor): array
    {
        Input::only($data,['allowed_email_domains','manual_action_hours']);
        if (!$data) { Input::invalid('settings'); }
        $domains=null; $hours=null;
        if (array_key_exists('allowed_email_domains',$data)) {
            if (!is_array($data['allowed_email_domains'])||!array_is_list($data['allowed_email_domains'])||count($data['allowed_email_domains'])>100) { Input::invalid('allowed_email_domains'); }
            $domains=[];
            foreach ($data['allowed_email_domains'] as $domain) {
                if (!is_string($domain)) { Input::invalid('allowed_email_domains'); }
                $domain=strtolower(trim($domain));
                if (!str_contains($domain,'.') || !filter_var($domain,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) || strlen($domain)>253 || str_ends_with($domain,'.')) { Input::invalid('allowed_email_domains'); }
                $domains[]=$domain;
            }
        }
        if (array_key_exists('manual_action_hours',$data)) { $hours=$data['manual_action_hours']; if (!is_int($hours)||$hours<1||$hours>720) { Input::invalid('manual_action_hours'); } }
        return $this->guarded($actor,'settings.manage',function ($fresh) use ($domains,$hours) {
            if ($domains!==null) { $this->app->db->run('DELETE FROM allowed_email_domains'); foreach (array_unique($domains) as $domain) { $this->app->db->run('INSERT INTO allowed_email_domains(domain) VALUES(?)',[$domain]); } }
            if ($hours!==null) { $this->app->db->run("UPDATE settings SET value_json=? WHERE setting_key='manual_action_hours'",[(string)$hours]); }
            $this->app->audit('settings.updated',(int)$fresh['id'],null,['fields'=>array_values(array_filter([$domains!==null?'allowed_email_domains':null,$hours!==null?'manual_action_hours':null]))]);
            return $this->settings();
        });
    }
}
