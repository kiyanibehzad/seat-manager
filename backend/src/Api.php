<?php
declare(strict_types=1);
namespace AccManager;
final class Api
{
    private const ROUTES = [
        '/health'=>'GET','/session'=>'GET','/login'=>'POST','/logout'=>'POST','/register'=>'POST','/verify'=>'POST','/resend-verification'=>'POST','/forgot'=>'POST','/reset'=>'POST','/change-password'=>'POST',
        '/notifications'=>'GET','/notifications/read'=>'POST','/notifications/read-all'=>'POST','/admin/registrations'=>'GET','/admin/registrations/approve'=>'POST','/admin/registrations/reject'=>'POST',
        '/admin/users'=>'GET,POST,PATCH','/admin/users/status'=>'POST','/admin/users/role'=>'POST','/departments'=>'GET','/admin/departments'=>'POST','/admin/settings'=>'GET,POST',
    ];
    public function __construct(private App $app) {}
    public function handle(): array
    {
        if ($this->app->config['environment']==='production' && !in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true)) { throw new ApiError(403,'forbidden','HTTPS لازم است.'); }
        $route=$_GET['route']??'/session'; $method=$_SERVER['REQUEST_METHOD']??'GET';
        if (!is_string($route)||(!isset(self::ROUTES[$route])&&!isset(\AccManager\Operations\Api::ROUTES[$route]))) { throw new ApiError(404,'not_found','مسیر پیدا نشد.'); }
        $allowed=self::ROUTES[$route]??\AccManager\Operations\Api::ROUTES[$route];
        if (!in_array($method,explode(',',$allowed),true)) { header('Allow: '.str_replace(',',', ',$allowed)); throw new ApiError(405,'method_not_allowed','روش درخواست مجاز نیست.'); }
        if ($route==='/health') { $this->app->db->one('SELECT 1'); return [200,['status'=>'ok']]; }
        $session=new SessionManager($this->app); $session->current();
        $data=[];
        if ($method!=='GET') {
            $session->checkCsrf();
            if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') { throw new ApiError(415,'invalid_content_type','ورودی باید JSON باشد.'); }
            $maxBytes=str_starts_with($route,'/admin/users/bulk-')?262144:32768;
            if ((int)($_SERVER['CONTENT_LENGTH']??0)>$maxBytes) { throw new ApiError(413,'payload_too_large','حجم درخواست بیش از حد مجاز است.'); }
            $raw=file_get_contents('php://input',false,null,0,$maxBytes+1);
            if (strlen($raw)>$maxBytes) { throw new ApiError(413,'payload_too_large','حجم درخواست بیش از حد مجاز است.'); }
            try { $object=json_decode($raw,false,16,JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new ApiError(400,'invalid_json','JSON معتبر نیست.'); }
            if (!$object instanceof \stdClass) { throw new ApiError(400,'invalid_json','ورودی باید یک شیء JSON باشد.'); }
            $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        }
        $ip=$_SERVER['REMOTE_ADDR']??'unknown'; // Do not trust client supplied proxy headers.
        switch ($route) {
            case '/session': return [200,$session->summary()];
            case '/login': $session->login($this->app->auth->login($data,$ip)); return [200,$session->summary()];
            case '/logout':
                Input::only($data,[]); $user=$session->current();
                if ($user) { $this->app->audit('auth.logout',(int)$user['id'],(int)$user['id']); }
                $session->logout(); return [200,$session->summary()];
            case '/register': $this->app->auth->register($data,$ip); return [202,['message'=>'اگر ثبت‌نام امکان‌پذیر باشد، راهنمای تأیید ایمیل ارسال می‌شود.']];
            case '/verify': $this->app->auth->verify($data,$ip); return [200,['message'=>'ایمیل تأیید شد؛ ثبت‌نام منتظر بررسی منابع انسانی است.']];
            case '/resend-verification': $this->app->auth->resend($data,$ip,false); return [202,['message'=>'در صورت امکان، ایمیل تأیید ارسال می‌شود.']];
            case '/forgot': $this->app->auth->resend($data,$ip,true); return [202,['message'=>'اگر حساب فعالی با این ایمیل باشد، راهنمای بازیابی ارسال می‌شود.']];
            case '/reset': $this->app->auth->reset($data,$ip); $session->logout(); return [200,$session->summary()];
        }
        $user=$session->requireUser(); $id=(int)$user['id'];
        switch ($route) {
            case '/change-password': $session->login($this->app->auth->change($data,$user)); return [200,$session->summary()];
            case '/notifications': return [200,$this->app->events->list($id,$_GET)];
            case '/notifications/read': $this->app->events->read($id,$data); return [200,['updated'=>true]];
            case '/notifications/read-all': Input::only($data,[]); $this->app->events->readAll($id); return [200,['updated'=>true]];
            case '/departments':
                $rows=$this->app->db->all('SELECT id,name,base_score FROM departments ORDER BY name');
                foreach ($rows as &$row) { $row['id']=(int)$row['id']; $row['base_score']=(int)$row['base_score']; } unset($row);
                return [200,['items'=>$rows]];
        }
        if (isset(\AccManager\Operations\Api::ROUTES[$route])) { return (new \AccManager\Operations\Api($this->app))->handle($route,$method,$data,$user); }
        $permission=match ($route) { '/admin/users/role'=>'roles.manage','/admin/settings'=>'settings.manage','/admin/departments'=>'departments.manage',default=>'people.manage' };
        $this->app->auth->requirePermission($user,$permission);
        return match ($route) {
            '/admin/registrations'=>[200,['items'=>$this->app->people->registrations()]],
            '/admin/registrations/approve'=>[200,['user'=>$this->app->people->approve($data,$user)]],
            '/admin/registrations/reject'=>[200,['user'=>$this->app->people->reject($data,$user)]],
            '/admin/users'=>match ($method) {
                'GET'=>[200,['items'=>$this->app->people->listUsers()]],
                'POST'=>[201,['user'=>$this->app->people->create($data,$user)]],
                'PATCH'=>[200,['user'=>$this->app->people->update($data,$user)]],
            },
            '/admin/users/status'=>[200,['user'=>$this->app->people->status($data,$user)]],
            '/admin/users/role'=>[200,['user'=>$this->app->people->role($data,$user)]],
            '/admin/departments'=>[200,['department'=>$this->app->people->departmentSave($data,$user)]],
            '/admin/settings'=>$method==='GET'?[200,$this->app->people->settings()]:[200,$this->app->people->settingsSave($data,$user)],
            default=>throw new ApiError(404,'not_found','مسیر پیدا نشد.'),
        };
    }
}
