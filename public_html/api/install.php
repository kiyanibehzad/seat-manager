<?php
declare(strict_types=1);
use AccManager\ApiError;
use AccManager\Installer;
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff');
$app=null; $id=bin2hex(random_bytes(8));
try {
    $app=require dirname(__DIR__,2).'/backend/bootstrap.php'; $id=$app->requestId;
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { throw new ApiError(405,'method_not_allowed','فقط POST مجاز است.'); }
    if ($app->config['environment']==='production' && !in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true)) { throw new ApiError(403,'forbidden','HTTPS لازم است.'); }
    $url=parse_url($app->config['app_url']); $origin=$url['scheme'].'://'.$url['host'].(isset($url['port'])?':'.$url['port']:'');
    if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN']!==$origin) { throw new ApiError(403,'forbidden','مبدأ مجاز نیست.'); }
    if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') { throw new ApiError(415,'invalid_content_type','ورودی باید JSON باشد.'); }
    $raw=file_get_contents('php://input',false,null,0,32769);
    if (strlen($raw)>32768) { throw new ApiError(413,'payload_too_large','حجم بیش از حد مجاز است.'); }
    try { $object=json_decode($raw,false,16,JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new ApiError(400,'invalid_json','JSON معتبر نیست.'); }
    if (!$object instanceof stdClass) { throw new ApiError(400,'invalid_json','ورودی باید شیء JSON باشد.'); }
    (new Installer($app))->install($_SERVER['HTTP_X_INSTALL_TOKEN']??'',json_decode($raw,true,16,JSON_THROW_ON_ERROR));
    $status=201; $response=['ok'=>true,'data'=>['message'=>'نصب انجام شد؛ اطلاعات ورود در صف ایمیل است.'],'error'=>null,'request_id'=>$id];
} catch (Throwable $e) {
    $status=$e instanceof ApiError?$e->status:503;
    $response=['ok'=>false,'data'=>null,'error'=>['code'=>$e instanceof ApiError?$e->errorCode:'unavailable','message'=>$e instanceof ApiError?$e->getMessage():'نصب موقتاً در دسترس نیست.','fields'=>(object)[]],'request_id'=>$id];
    if ($app && !$e instanceof ApiError) { $app->log('installation_error'); }
}
http_response_code($status); echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
