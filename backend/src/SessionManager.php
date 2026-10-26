<?php
declare(strict_types=1);
namespace AccManager;
final class SessionManager
{
    public function __construct(private App $app)
    {
        $config=$app->config['session'];
        ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); ini_set('session.use_trans_sid','0');
        ini_set('session.gc_maxlifetime',(string)$config['absolute_seconds']);
        session_save_path($app->root.'/storage/sessions'); session_name($config['name']);
        $path=rtrim(parse_url($app->config['app_url'],PHP_URL_PATH)??'','/').'/';
        session_set_cookie_params(['lifetime'=>0,'path'=>$path,'secure'=>(bool)$config['secure'],'httponly'=>true,'samesite'=>'Lax']);
        session_start();
        if (!isset($_SESSION['started']) || time()-($_SESSION['last_seen']??0)>$config['idle_seconds'] || time()-$_SESSION['started']>$config['absolute_seconds']) { $this->rotate(); }
        $_SESSION['last_seen']=time();
    }
    private function rotate(): void
    {
        session_regenerate_id(true);
        $_SESSION=['started'=>time(),'last_seen'=>time(),'csrf'=>bin2hex(random_bytes(32))];
    }
    public function login(array $user): void { $this->rotate(); $_SESSION['user_id']=(int)$user['id']; $_SESSION['auth_version']=(int)$user['auth_version']; }
    public function logout(): void { $this->rotate(); }
    public function current(): ?array
    {
        if (!isset($_SESSION['user_id'])) { return null; }
        $user=$this->app->auth->user((int)$_SESSION['user_id']);
        if (!$user || !$this->app->auth->eligible($user) || (int)$user['auth_version']!==$_SESSION['auth_version']) { $this->logout(); return null; }
        return $user;
    }
    public function requireUser(): array
    {
        return $this->current()??throw new ApiError(401,'unauthenticated','ابتدا وارد حساب شوید.');
    }
    public function checkCsrf(): void
    {
        $origin=$_SERVER['HTTP_ORIGIN']??null;
        $url=parse_url($this->app->config['app_url']);
        $expected=$url['scheme'].'://'.$url['host'].(isset($url['port'])?':'.$url['port']:'');
        if (($origin!==null && !hash_equals($expected,$origin)) || ($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site') { throw new ApiError(403,'csrf_invalid','مبدأ درخواست مجاز نیست.'); }
        $token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
        if (!is_string($token)||!hash_equals($_SESSION['csrf'],$token)) { throw new ApiError(403,'csrf_invalid','نشست فرم معتبر نیست؛ صفحه را تازه کنید.'); }
    }
    public function summary(): array
    {
        $user=$this->current();
        return ['authenticated'=>$user!==null,'user'=>$user?$this->app->auth->publicUser($user):null,'csrf_token'=>$_SESSION['csrf'],'permissions'=>$user?$this->app->auth->permissions($user):[],'app_url'=>$this->app->config['app_url'],'capabilities'=>$user?$this->app->accessGroups->selected($user):[],'access_groups_ready'=>$this->app->accessGroups->ready()];
    }
}
