<?php
declare(strict_types=1);
use AccManager\ApiError;
use AccManager\Installer;
use AccManager\SessionManager;
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
require_once dirname(__DIR__,2).'/backend/src/SecurityHeaders.php';
\AccManager\SecurityHeaders::send($_SERVER);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$app=null; $id=bin2hex(random_bytes(8));
try {
    $app=require dirname(__DIR__,2).'/backend/bootstrap.php'; $id=$app->requestId;
    $method=$_SERVER['REQUEST_METHOD']??'';
    if (!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST'); throw new ApiError(405,'method_not_allowed','فقط GET و POST مجاز است.'); }
    if ($app->config['environment']==='production' && !in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true)) { throw new ApiError(403,'forbidden','HTTPS لازم است.'); }
    $url=parse_url($app->config['app_url']); $origin=$url['scheme'].'://'.$url['host'].(isset($url['port'])?':'.$url['port']:'');
    if ((isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN']!==$origin)||($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site') { throw new ApiError(403,'forbidden','مبدأ مجاز نیست.'); }
    $installer=new Installer($app);
    if ($method==='GET') {
        $session=new SessionManager($app);
        $status=200; $response=['ok'=>true,'data'=>$installer->status()+['csrf_token'=>$_SESSION['csrf']],'error'=>null,'request_id'=>$id];
    } else {
    $installer->limitAttempt($_SERVER['REMOTE_ADDR']??'unknown');
    if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') { throw new ApiError(415,'invalid_content_type','ورودی باید JSON باشد.'); }
    $raw=file_get_contents('php://input',false,null,0,32769);
    if (strlen($raw)>32768) { throw new ApiError(413,'payload_too_large','حجم بیش از حد مجاز است.'); }
    try { $object=json_decode($raw,false,16,JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new ApiError(400,'invalid_json','JSON معتبر نیست.'); }
    if (!$object instanceof stdClass) { throw new ApiError(400,'invalid_json','ورودی باید شیء JSON باشد.'); }
    $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    $chosen=array_key_exists('password',$data)||array_key_exists('password_confirm',$data);
    if ($chosen) { (new SessionManager($app))->checkCsrf(); }
    $installer->install($_SERVER['HTTP_X_INSTALL_TOKEN']??'',$data);
    $status=201; $response=['ok'=>true,'data'=>['message'=>$chosen?'مدیر کل ساخته شد؛ اکنون با ایمیل و رمز انتخابی خود وارد شوید.':'نصب انجام شد؛ اطلاعات ورود در صف ایمیل است.'],'error'=>null,'request_id'=>$id];
    }
} catch (Throwable $e) {
    $status=$e instanceof ApiError?$e->status:503;
    $response=['ok'=>false,'data'=>null,'error'=>['code'=>$e instanceof ApiError?$e->errorCode:'unavailable','message'=>$e instanceof ApiError?$e->getMessage():'نصب موقتاً در دسترس نیست.','fields'=>(object)($e instanceof ApiError?$e->fields:[])],'request_id'=>$id];
    if ($app && !$e instanceof ApiError) { $app->log('installation_error'); }
}
if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
if ($status===429) { header('Retry-After: 900'); }
http_response_code($status); echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
