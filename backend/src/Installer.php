<?php
declare(strict_types=1);
namespace AccManager;
final class Installer
{
    public function __construct(private App $app) {}
    public function install(string $secret,array $data): void
    {
        $setup=$this->app->config['setup']??[];
        if (!($setup['enabled']??false) || strlen($secret)<32 || !hash_equals($setup['token_hash']??'',hash('sha256',$secret))) { throw new ApiError(403,'forbidden','نصب مجاز نیست.'); }
        if (!$this->app->config['smtp']['enabled']) { throw new ApiError(422,'validation_failed','ابتدا ارسال ایمیل را پیکربندی کنید.'); }
        Input::only($data,['email','first_name','last_name','department_name']);
        $email=Input::email($data); $first=Input::text($data,'first_name'); $last=Input::text($data,'last_name'); $department=Input::text($data,'department_name',120);
        (new Migrator($this->app))->run();
        $this->app->db->transaction(function () use ($email,$first,$last,$department) {
            $this->app->db->one("SELECT value_json FROM settings WHERE setting_key='identity_guard' FOR UPDATE");
            if ((int)$this->app->db->one('SELECT COUNT(*) n FROM users')['n']!==0) { throw new ApiError(409,'conflict','نصب قبلاً انجام شده است.'); }
            $this->app->db->run('INSERT INTO departments(name,base_score) VALUES(?,1)',[$department]);
            $departmentId=(int)$this->app->db->pdo->lastInsertId(); $password=bin2hex(random_bytes(12));
            $this->app->db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,app_role,email_verified_at,approval_status,approved_at) VALUES(?,?,?,?,?,'admin',UTC_TIMESTAMP(),'approved',UTC_TIMESTAMP())",[$email,password_hash($password,PASSWORD_DEFAULT),$first,$last,$departmentId]);
            $id=(int)$this->app->db->pdo->lastInsertId();
            $this->app->db->run('INSERT IGNORE INTO allowed_email_domains(domain) VALUES(?)',[substr(strrchr($email,'@'),1)]);
            $this->app->mail->enqueue($id,'initial-password:'.$id,$email,'حساب مدیر کل ساخته شد',"آدرس ورود: ".$this->app->url()."\nرمز اولیه: ".$password,null,'initial_password');
            $this->app->audit('installation.completed',$id,$id);
        });
    }
}
