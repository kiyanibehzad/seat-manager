<?php
declare(strict_types=1);
// Independent HTTP abuse smoke test, only against the dedicated test API/DB.
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
try {
    $root=dirname(__DIR__,2); chdir($root); require __DIR__.'/OperationsSupport.php'; configureTestEnvironment($root);
    $app=require $root.'/backend/bootstrap.php';
    testDatabaseGuard($app,true);
    $curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>10]);
    $call=function (string $route,?array $data=null,string $csrf='') use ($curl): array {
        curl_setopt_array($curl,[CURLOPT_URL=>testBaseUrl().'/api/index.php?route='.rawurlencode($route),CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$csrf],CURLOPT_CUSTOMREQUEST=>$data===null?'GET':'POST',CURLOPT_POSTFIELDS=>$data===null?null:json_encode($data,JSON_THROW_ON_ERROR)]);
        $body=curl_exec($curl); return [(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),json_decode($body,true)];
    };
    $csrf=$call('/session')[1]['data']['csrf_token'];
    $bucket=hash_hmac('sha256','verify-ip:127.0.0.1',$app->config['app_key']);
    $app->db->run('DELETE FROM rate_limits WHERE bucket=?',[$bucket]);
    $maximum=(int)($app->config['auth_limits']['verification_ip']??600);
    for ($i=0;$i<$maximum;$i++) { if ($call('/verify',['token'=>'invalid'],$csrf)[0]!==422) { throw new RuntimeException('verification threshold early'); } }
    if ($call('/verify',['token'=>'invalid'],$csrf)[0]!==429) { throw new RuntimeException('verification not bounded'); }
    $email='recovery-limit@example.test';
    $maximum=(int)($app->config['auth_limits']['recovery_email']??5);
    for ($i=0;$i<$maximum;$i++) { if ($call('/forgot',['email'=>$email],$csrf)[0]!==202) { throw new RuntimeException('recovery threshold early'); } }
    if ($call('/forgot',['email'=>$email],$csrf)[0]!==429) { throw new RuntimeException('recovery not bounded'); }
    echo "PASS: verification IP abuse and recovery per-email limits are enforced over HTTP.\n";
} catch (Throwable $e) { fwrite(STDERR,"Rate-limit smoke test failed; no request data logged.\n"); exit(1); }
