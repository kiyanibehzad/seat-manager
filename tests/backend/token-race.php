<?php
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
try {
    $root=dirname(__DIR__,2); require __DIR__.'/OperationsSupport.php'; configureTestEnvironment($root); $app=require $root.'/backend/bootstrap.php'; testDatabaseGuard($app);
    if ($app->config['environment']!=='test') { exit(1); }
    $payload=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    $app->auth->reset($payload,'127.0.0.1'); echo '200';
} catch (AccManager\ApiError $e) { echo $e->status; }
catch (Throwable $e) { echo '500:'.get_class($e).':'.basename($e->getFile()).':'.$e->getLine().':'.$e->getCode().':'.($e instanceof PDOException?($e->errorInfo[1]??0):0); exit(1); }
