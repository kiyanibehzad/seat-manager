<?php
declare(strict_types=1);
namespace AccManager;
final class Installer
{
    public function __construct(private App $app) {}
    public function status(): array
    {
        $exists=(int)$this->app->db->one("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")['n']>0;
        $installed=$exists&&(int)$this->app->db->one('SELECT COUNT(*) n FROM users')['n']>0;
        return ['installed'=>$installed,'available'=>!$installed&&($this->app->config['setup']['enabled']??false)];
    }
    public function limitAttempt(string $ip): void
    {
        // Setup may precede database migrations, so its small limiter uses private files.
        $path=$this->app->root.'/storage/run/install-'.hash_hmac('sha256',$ip,$this->app->config['app_key']).'.json';
        $file=fopen($path,'c+');
        if (!$file||!flock($file,LOCK_EX)) { throw new ApiError(503,'unavailable','نصب موقتاً در دسترس نیست.'); }
        try {
            $row=json_decode(stream_get_contents($file),true); $now=time();
            if (!is_array($row)||$now-(int)($row['started']??0)>=900) { $row=['started'=>$now,'count'=>0]; }
            if ((int)$row['count']>=20) { throw new ApiError(429,'rate_limited','تعداد تلاش‌های نصب زیاد است؛ ۱۵ دقیقه بعد دوباره تلاش کنید.'); }
            $row['count']++; rewind($file); ftruncate($file,0); fwrite($file,json_encode($row,JSON_THROW_ON_ERROR)); fflush($file);
        } finally { flock($file,LOCK_UN); fclose($file); }
    }
    public function install(string $secret,array $data): void
    {
        $setup=$this->app->config['setup']??[];
        if (!($setup['enabled']??false) || strlen($secret)<32 || !hash_equals($setup['token_hash']??'',hash('sha256',$secret))) { throw new ApiError(403,'forbidden','نصب مجاز نیست.'); }
        if ($this->status()['installed']) { throw new ApiError(409,'conflict','نصب قبلاً انجام شده است.'); }
        Input::only($data,['email','first_name','last_name','department_name','password','password_confirm']);
        $chosen=array_key_exists('password',$data)||array_key_exists('password_confirm',$data);
        $password=$chosen?Input::password($data):null;
        if ($chosen&&(!is_string($data['password_confirm']??null)||!hash_equals($password,$data['password_confirm']))) { Input::invalid('password_confirm','تکرار رمز عبور یکسان نیست.'); }
        $email=Input::email($data); $first=Input::text($data,'first_name'); $last=Input::text($data,'last_name'); $department=Input::text($data,'department_name',120);
        (new Migrator($this->app))->run();
        if (!$chosen&&!(new MailSettings($this->app))->current()['delivery_enabled']) { throw new ApiError(422,'validation_failed','رمز مدیر کل را انتخاب کنید؛ ارسال خودکار ایمیل هنوز فعال نیست.'); }
        $this->app->db->transaction(function () use ($email,$first,$last,$department,$chosen,$password) {
            $this->app->db->one("SELECT value_json FROM settings WHERE setting_key='identity_guard' FOR UPDATE");
            if ((int)$this->app->db->one('SELECT COUNT(*) n FROM users')['n']!==0) { throw new ApiError(409,'conflict','نصب قبلاً انجام شده است.'); }
            $this->app->db->run('INSERT INTO departments(name,base_score) VALUES(?,1)',[$department]);
            $departmentId=(int)$this->app->db->pdo->lastInsertId(); $password=$chosen?$password:bin2hex(random_bytes(12));
            $this->app->db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,app_role,email_verified_at,approval_status,approved_at) VALUES(?,?,?,?,?,'admin',UTC_TIMESTAMP(),'approved',UTC_TIMESTAMP())",[$email,password_hash($password,PASSWORD_DEFAULT),$first,$last,$departmentId]);
            $id=(int)$this->app->db->pdo->lastInsertId();
            $this->app->accessGroups->initializeUser($id,true);
            $this->app->db->run('INSERT IGNORE INTO allowed_email_domains(domain) VALUES(?)',[substr(strrchr($email,'@'),1)]);
            if (!$chosen) { $this->app->mail->enqueue($id,'initial-password:'.$id,$email,'حساب مدیر کل ساخته شد',"آدرس ورود: ".$this->app->url()."\nرمز اولیه: ".$password,null,'initial_password'); }
            $this->app->audit('installation.completed',$id,$id);
        });
    }
}
