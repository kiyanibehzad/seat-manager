<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
try {
    require dirname(__DIR__).'/backend/vendor/autoload.php';
    $config=require dirname(__DIR__).'/config/local.php';
    $data=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    echo (new AccManager\Crypto($config['app_key']))->encrypt($data);
} catch (Throwable $e) { exit(1); }
