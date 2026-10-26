<?php
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
try { $root=dirname(__DIR__,2); require __DIR__.'/OperationsSupport.php'; configureTestEnvironment($root); $app=require $root.'/backend/bootstrap.php'; testDatabaseGuard($app); echo json_encode((new AccManager\MailWorker($app))->run(10,20),JSON_THROW_ON_ERROR); }
catch (Throwable $e) { echo '{"error":"worker_failed"}'; exit(1); }
