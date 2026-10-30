<?php
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
$root=dirname(__DIR__,2); chdir($root);
require $root.'/backend/vendor/autoload.php'; require __DIR__.'/OperationsSupport.php'; configureTestEnvironment($root);
try {
    $app=\AccManager\App::boot($root); testDatabaseGuard($app);
    $in=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    $actor=$app->auth->user((int)$in['actor']);
    $out=(new \AccManager\Operations\Credits(new \AccManager\Operations\Domain($app)))->create($in['data'],$actor);
    echo json_encode(['status'=>200,'id'=>$out['request']['id']],JSON_THROW_ON_ERROR);
} catch (\AccManager\ApiError $e) { echo json_encode(['status'=>$e->status],JSON_THROW_ON_ERROR); }
catch (\Throwable $e) { echo json_encode(['status'=>500],JSON_THROW_ON_ERROR); }
