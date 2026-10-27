<?php
declare(strict_types=1);
use AccManager\Api;
use AccManager\ApiError;

ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
require_once dirname(__DIR__,2).'/backend/src/SecurityHeaders.php';
\AccManager\SecurityHeaders::send($_SERVER);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private');
set_error_handler(static function (int $severity,string $message,string $file,int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException('Internal PHP error',0,$severity,$file,$line);
});
$app=null; $requestId=bin2hex(random_bytes(8));
try {
    $app=require dirname(__DIR__,2).'/backend/bootstrap.php'; $requestId=$app->requestId;
    [$status,$data]=(new Api($app))->handle();
    $response=['ok'=>true,'data'=>$data,'error'=>null,'request_id'=>$requestId];
} catch (Throwable $e) {
    if ($e instanceof ApiError) { $status=$e->status; $code=$e->errorCode; $message=$e->getMessage(); $fields=$e->fields; }
    elseif ($e instanceof PDOException && $e->getCode()==='23000' && $app) { $status=409; $code='conflict'; $message='اطلاعات با رکورد موجود سازگار نیست.'; $fields=[]; }
    else { $status=$app?500:503; $code=$app?'internal_error':'unavailable'; $message='سرویس موقتاً در دسترس نیست.'; $fields=[]; if ($app) { $app->log($code); } }
    if ($status===429) { header('Retry-After: 900'); }
    $response=['ok'=>false,'data'=>null,'error'=>['code'=>$code,'message'=>$message,'fields'=>(object)$fields],'request_id'=>$requestId];
}
if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
http_response_code($status);
header('X-Request-Id: '.$requestId);
echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
