<?php
declare(strict_types=1);
namespace AccManager;
final class Auth
{
    public const USER_SELECT = 'SELECT u.id,u.email,u.first_name,u.last_name,u.employee_number,u.department_id,d.name department_name,u.organizational_position,u.app_role,u.special_priority,u.is_active,u.email_verified_at,u.approval_status,u.auth_version FROM users u LEFT JOIN departments d ON d.id=u.department_id';
    public function __construct(private App $app) {}
    public function userSelect(): string
    {
        if (!$this->app->accessGroups->ready()) { return self::USER_SELECT; }
        return str_replace('u.auth_version FROM users u','u.auth_version,u.access_group_id,g.name access_group_name,g.is_super_admin FROM users u',self::USER_SELECT).' JOIN access_groups g ON g.id=u.access_group_id';
    }
    public function user(int $id): ?array { return $this->app->db->one($this->userSelect() . ' WHERE u.id=?',[$id]); }
    public function publicUser(array $user): array
    {
        unset($user['auth_version']);
        $user['access_group_id']=isset($user['access_group_id'])?(int)$user['access_group_id']:null;
        $user['access_group_name']=$user['access_group_name']??null;
        $user['is_super_admin']=(bool)($user['is_super_admin']??false);
        $user['id'] = (int)$user['id'];
        $user['department_id'] = $user['department_id'] === null ? null : (int)$user['department_id'];
        $user['is_active'] = (bool)$user['is_active'];
        $user['special_priority'] = (bool)$user['special_priority'];
        $user['email_verified'] = $user['email_verified_at'] !== null;
        unset($user['email_verified_at']);
        return $user;
    }
    public function permissions(array $user): array
    {
        return $this->app->accessGroups->permissions($user);
    }
    public function recipients(string $permission): array { return $this->app->accessGroups->recipients($permission); }

    public function requirePermission(array $user, string $permission): void
    {
        if (!in_array($permission,$this->permissions($user),true)) { throw new ApiError(403,'forbidden','دسترسی این عملیات را ندارید.'); }
    }
    public function eligible(array $user): bool { return (bool)$user['is_active'] && $user['email_verified_at'] !== null && $user['approval_status'] === 'approved'; }
    public function login(array $data, string $ip): array
    {
        Input::only($data,['email','password']);
        $email = Input::email($data);
        $password = $data['password'] ?? '';
        if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0")) { $password = ''; }
        $limiter = new RateLimiter($this->app);
        $limits=$this->app->config['auth_limits']??[];
        $window=(int)($limits['window_seconds']??900); $ipLimit=(int)($limits['login_failures_ip']??1000); $emailLimit=(int)($limits['login_failures_email']??8);
        $limiter->check('login-ip',$ip,$ipLimit,$window);
        $limiter->check('login-email',$email,$emailLimit,$window);
        $row = $this->app->db->one('SELECT * FROM users WHERE email=?',[$email]);
        // A fixed non-user hash makes the absent-user path perform password verification too.
        $hash = $row['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $verified = password_verify($password,$hash);
        if (!$row || !$verified || !$this->eligible($row)) {
            $limiter->consume('login-ip',$ip,$ipLimit,$window); $limiter->consume('login-email',$email,$emailLimit,$window);
            throw new ApiError(401,'invalid_credentials','ایمیل یا رمز صحیح نیست یا حساب هنوز فعال نشده است.');
        }
        $limiter->clear('login-email',$email);
        return $this->app->db->transaction(function () use ($row,$password) {
            $fresh = $this->app->db->one('SELECT * FROM users WHERE id=? FOR UPDATE',[$row['id']]);
            if (!$this->eligible($fresh) || !hash_equals($row['password_hash'],$fresh['password_hash'])) { throw new ApiError(401,'invalid_credentials','امکان ورود به حساب وجود ندارد.'); }
            if (password_needs_rehash($fresh['password_hash'], PASSWORD_DEFAULT)) { $this->app->db->run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$row['id']]); }
            $this->app->audit('auth.login',(int)$row['id'],(int)$row['id']);
            return $this->user((int)$row['id']);
        });
    }
    public function register(array $data, string $ip): void
    {
        Input::only($data,['email','password','first_name','last_name']);
        $email=Input::email($data); $password=Input::password($data);
        $first=Input::text($data,'first_name'); $last=Input::text($data,'last_name');
        (new RateLimiter($this->app))->consume('register-ip',$ip,(int)($this->app->config['auth_limits']['registration_ip']??300),(int)($this->app->config['auth_limits']['window_seconds']??900));
        $domain = substr(strrchr($email,'@'),1);
        if (!$this->app->db->one('SELECT domain FROM allowed_email_domains WHERE domain=?',[$domain])) { Input::invalid('email','دامنهٔ ایمیل سازمانی مجاز نیست.'); }
        $hash = password_hash($password,PASSWORD_DEFAULT);
        try {
            $this->app->db->transaction(function () use ($email,$hash,$first,$last) {
                if ($this->app->db->one('SELECT id FROM users WHERE email=?',[$email])) { return; }
                $this->app->db->run('INSERT INTO users(email,password_hash,first_name,last_name) VALUES(?,?,?,?)',[$email,$hash,$first,$last]);
                $id=(int)$this->app->db->pdo->lastInsertId();
                $this->app->accessGroups->initializeUser($id);
                $this->issueToken($id,'verify_email');
                $this->app->audit('registration.created',null,$id);
            });
        } catch (\PDOException $e) { if ($e->getCode() !== '23000') { throw $e; } }
    }
    public function resend(array $data, string $ip, bool $reset): void
    {
        Input::only($data,['email']);
        $email=Input::email($data); $scope=$reset?'forgot':'resend';
        $limits=$this->app->config['auth_limits']??[]; $window=(int)($limits['window_seconds']??900);
        $limit=new RateLimiter($this->app); $limit->consume($scope.'-ip',$ip,(int)($limits['recovery_ip']??600),$window); $limit->consume($scope.'-email',$email,(int)($limits['recovery_email']??5),$window);
        $this->app->db->transaction(function () use ($email,$reset) {
            $row=$this->app->db->one('SELECT * FROM users WHERE email=? FOR UPDATE',[$email]);
            if (!$row || ($reset ? !$this->eligible($row) : ($row['approval_status'] !== 'pending_email' || !$row['is_active']))) { return; }
            $this->issueToken((int)$row['id'],$reset?'reset_password':'verify_email');
        });
    }
    public function issueToken(int $id, string $purpose): void
    {
        $db=$this->app->db;
        $db->run('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND purpose=? AND consumed_at IS NULL',[$id,$purpose]);
        $db->run("UPDATE mail_outbox m JOIN auth_tokens t ON t.id=m.auth_token_id SET m.status='cancelled',m.payload_encrypted=NULL WHERE t.user_id=? AND t.purpose=? AND m.status IN ('pending','retry')",[$id,$purpose]);
        $token=bin2hex(random_bytes(32)); $expires=gmdate('Y-m-d H:i:s',time()+($purpose==='verify_email'?86400:3600));
        $db->run('INSERT INTO auth_tokens(user_id,purpose,token_hash,expires_at) VALUES(?,?,?,?)',[$id,$purpose,hash('sha256',$token),$expires]);
        $tokenId=(int)$db->pdo->lastInsertId();
        $user=$this->user($id);
        $action=$purpose==='verify_email'?'verify':'reset';
        $title=$purpose==='verify_email'?'تأیید ایمیل سازمانی':'بازیابی رمز عبور';
        $body=$title."\n".$this->app->url('action='.$action.'&token='.$token)."\nاین لینک یک‌بارمصرف است و در ".$expires." UTC منقضی می‌شود.\nاگر این درخواست را نداده‌اید، آن را نادیده بگیرید.";
        $this->app->mail->enqueue($id,'token:'.$tokenId,$user['email'],$title,$body,$tokenId,'auth');
    }
    private function token(array $data, string $purpose): array
    {
        $token=$data['token']??'';
        if (!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)) { $this->invalidToken(); }
        // Token issuer also holds the user lock; keep the same lock order to avoid races.
        $candidate=$this->app->db->one('SELECT user_id FROM auth_tokens WHERE token_hash=?',[hash('sha256',$token)]);
        if (!$candidate) { $this->invalidToken(); }
        $user=$this->app->db->one('SELECT * FROM users WHERE id=? FOR UPDATE',[$candidate['user_id']]);
        $row=$this->app->db->one('SELECT * FROM auth_tokens WHERE token_hash=? AND purpose=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE',[hash('sha256',$token),$purpose]);
        if (!$row||!$user['is_active']) { $this->invalidToken(); }
        return [$row,$user];
    }
    private function invalidToken(): never { throw new ApiError(422,'invalid_token','لینک نامعتبر، مصرف‌شده یا منقضی است.'); }
    public function verify(array $data, string $ip): void
    {
        Input::only($data,['token']); (new RateLimiter($this->app))->consume('verify-ip',$ip,(int)($this->app->config['auth_limits']['verification_ip']??600),(int)($this->app->config['auth_limits']['window_seconds']??900));
        $this->app->db->transaction(function () use ($data) {
            [$token,$user]=$this->token($data,'verify_email');
            if ($user['approval_status']!=='pending_email') { $this->invalidToken(); }
            $id=(int)$user['id'];
            $this->app->db->run("UPDATE users SET email_verified_at=UTC_TIMESTAMP(),approval_status='pending_approval' WHERE id=?",[$id]);
            $this->app->db->run('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE id=?',[$token['id']]);
            foreach ($this->recipients('registrations.review') as $reviewer) {
                $this->app->events->notify((int)$reviewer,'registration-ready:'.$id,'registration.ready','ثبت‌نام منتظر بررسی','ثبت‌نام '.$user['first_name'].' '.$user['last_name'].' برای بررسی آماده است.',$this->app->url('view=registrations'));
            }
            $this->app->audit('registration.email_verified',$id,$id);
        });
    }
    public function reset(array $data, string $ip): void
    {
        Input::only($data,['token','password']); $password=Input::password($data);
        (new RateLimiter($this->app))->consume('reset-ip',$ip,(int)($this->app->config['auth_limits']['reset_ip']??600),(int)($this->app->config['auth_limits']['window_seconds']??900));
        $this->app->db->transaction(function () use ($data,$password) {
            [$token,$user]=$this->token($data,'reset_password');
            if (!$this->eligible($user)) { $this->invalidToken(); }
            $this->setPassword((int)$user['id'],$password);
            $this->app->audit('auth.password_reset',(int)$user['id'],(int)$user['id']);
        });
    }
    public function change(array $data, array $user): array
    {
        Input::only($data,['current_password','password']); $password=Input::password($data);
        $current=$data['current_password']??'';
        if (!is_string($current)||strlen($current)>72||str_contains($current,"\0")) { $current=''; }
        (new RateLimiter($this->app))->consume('change-user',(string)$user['id'],5);
        return $this->app->db->transaction(function () use ($user,$password,$current) {
            $row=$this->app->db->one('SELECT * FROM users WHERE id=? FOR UPDATE',[$user['id']]);
            if (!$this->eligible($row) || (int)$row['auth_version'] !== (int)$user['auth_version']) { throw new ApiError(401,'unauthenticated','دوباره وارد شوید.'); }
            if (!password_verify($current,$row['password_hash'])) { throw new ApiError(422,'invalid_credentials','رمز فعلی صحیح نیست.'); }
            $this->setPassword((int)$user['id'],$password);
            $this->app->audit('auth.password_changed',(int)$user['id'],(int)$user['id']);
            return $this->user((int)$user['id']);
        });
    }
    private function setPassword(int $id,string $password): void
    {
        $db=$this->app->db;
        $db->run('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$id]);
        $db->run('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND consumed_at IS NULL',[$id]);
        $db->run("UPDATE mail_outbox SET status='cancelled',payload_encrypted=NULL WHERE recipient_id=? AND category IN ('auth','initial_password') AND status IN ('pending','retry')",[$id]);
        $this->app->events->notify($id,'password-changed:'.bin2hex(random_bytes(16)),'auth.password_changed','رمز عبور تغییر کرد','رمز عبور برنامه تغییر کرد. اگر این کار را انجام نداده‌اید، با مسئول سامانه تماس بگیرید.',$this->app->url());
    }
}
