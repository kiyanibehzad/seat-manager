<?php
declare(strict_types=1);
// Disposable database + HTTP server only. Existing dev/QA configuration is never loaded.
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1'); date_default_timezone_set('UTC'); umask(0077);
$root=dirname(__DIR__,2); require $root.'/backend/vendor/autoload.php';
use AccManager\{App,ApiError,Installer};
$count=0; $server=null; $db=null; $database=null; $legacyDatabase=null; $scratch=null; $exit=0;
function fiCheck(bool $ok,string $label): void { global $count; if (!$ok) { throw new RuntimeException('FAIL: '.$label); } $count++; }
try {
    $account=posix_getpwuid(posix_geteuid())['name'];
    $db=new PDO('mysql:unix_socket='.$root.'/storage/run/db.sock;charset=utf8mb4',$account,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $instance=$db->query('SELECT @@port port,@@datadir directory')->fetch(PDO::FETCH_ASSOC);
    fiCheck((int)$instance['port']===33079&&realpath($instance['directory'])===realpath($root.'/storage/mysql'),'project-local DB guard');
    $nonce=bin2hex(random_bytes(6)); $database='acc_first_'.$nonce; $db->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $scratch=$root.'/work/first-install-'.$nonce;
    foreach (['backend/src','config','public_html/api','storage/run','storage/logs','storage/sessions'] as $dir) { mkdir($scratch.'/'.$dir,0700,true); }
    symlink($root.'/backend/vendor',$scratch.'/backend/vendor'); symlink($root.'/database',$scratch.'/database');
    copy($root.'/backend/bootstrap.php',$scratch.'/backend/bootstrap.php');
    copy($root.'/backend/src/SecurityHeaders.php',$scratch.'/backend/src/SecurityHeaders.php');
    foreach (['install.php','index.php'] as $file) { copy($root.'/public_html/api/'.$file,$scratch.'/public_html/api/'.$file); }
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$message); $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
    $config=require $root.'/config/app.example.php';
    $config['environment']='test'; $config['app_url']='http://127.0.0.1:'.$port; $config['app_key']=base64_encode(random_bytes(32));
    $config['db']=['dsn'=>'mysql:unix_socket='.$root.'/storage/run/db.sock;dbname='.$database.';charset=utf8mb4','user'=>$account,'password'=>''];
    $config['session']['secure']=false; $config['session']['name']='fi_'.$nonce; $config['smtp']['enabled']=false;
    $token=bin2hex(random_bytes(32)); $password='First-Install-Only-'.bin2hex(random_bytes(10));
    $config['setup']=['enabled'=>true,'token_hash'=>hash('sha256',$token)];
    file_put_contents($scratch.'/config/local.php','<?php return '.var_export($config,true).';');
    $log=fopen($scratch.'/storage/logs/http.log','ab');
    $server=proc_open([PHP_BINARY,'-d','display_errors=0','-S','127.0.0.1:'.$port,'-t',$scratch.'/public_html'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes,$scratch);
    fclose($pipes[0]); $ready=false;
    for ($i=0;$i<100;$i++) { $probe=@fsockopen('127.0.0.1',$port,$errno,$error,.1); if ($probe) { fclose($probe); $ready=true; break; } usleep(20000); }
    fiCheck($ready,'own HTTP server started');
    $curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]);
    $request=function (string $method,string $path,?array $body=null,array $headers=[]) use ($curl,$port): array {
        curl_setopt_array($curl,[CURLOPT_URL=>'http://127.0.0.1:'.$port.'/'.$path,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>null,CURLOPT_HTTPHEADER=>['Content-Type: application/json',...$headers]]);
        if ($method!=='GET') { curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body??new stdClass(),JSON_THROW_ON_ERROR)); }
        $raw=curl_exec($curl); $json=is_string($raw)?json_decode($raw,true):null; fiCheck(is_array($json),'JSON envelope');
        return [(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),$json];
    };
    [$status,$json]=$request('GET','api/install.php');
    fiCheck($status===200&&$json['data']['available']&&!$json['data']['installed'],'pre-schema status available'); $csrf=$json['data']['csrf_token'];
    $base=['email'=>'first-'.$nonce.'@example.test','first_name'=>'مدیر','last_name'=>'آزمایشی','department_name'=>'فنی','password'=>$password,'password_confirm'=>$password];
    $identity=array_diff_key($base,['password'=>true,'password_confirm'=>true]);
    try { (new Installer(new App($scratch,$config)))->install($token,$identity); throw new RuntimeException('FAIL: legacy mode bypassed SMTP requirement'); }
    catch (ApiError $e) { fiCheck($e->status===422,'legacy random-password mode still needs SMTP'); }
    $headers=['X-CSRF-Token: '.$csrf,'X-Install-Token: '.$token];
    [$status]=$request('POST','api/install.php',$base,['X-Install-Token: '.$token]); fiCheck($status===403,'chosen password needs CSRF');
    [$status]=$request('POST','api/install.php',$base,['X-CSRF-Token: '.$csrf,'X-Install-Token: '.str_repeat('x',64)]); fiCheck($status===403,'wrong setup token rejected');
    [$status]=$request('POST','api/install.php',$base,[...$headers,'Origin: https://other.invalid']); fiCheck($status===403,'cross-origin request rejected');
    [$status,$json]=$request('POST','api/install.php',array_replace($base,['password'=>'short','password_confirm'=>'short']),$headers); fiCheck($status===422&&isset($json['error']['fields']['password']),'weak password field error');
    [$status,$json]=$request('POST','api/install.php',array_replace($base,['password_confirm'=>'different-password']),$headers); fiCheck($status===422&&isset($json['error']['fields']['password_confirm']),'mismatch rejected');
    [$status,$json]=$request('POST','api/install.php',$base,$headers); fiCheck($status===201&&$json['ok'],'chosen-password install without SMTP succeeds');
    fiCheck(!str_contains(json_encode($json),$password)&&!str_contains(json_encode($json),$token),'response never echoes credentials');
    $app=new App($scratch,$config); $user=$app->db->one('SELECT * FROM users');
    fiCheck((int)$user['access_group_id']===4&&$user['app_role']==='admin','first user receives protected chief group');
    fiCheck(password_verify($password,$user['password_hash']),'chosen password was hashed correctly');
    fiCheck((int)$app->db->one('SELECT COUNT(*) n FROM mail_outbox')['n']===0,'chosen password never queued to email');
    fiCheck((int)$app->db->one('SELECT COUNT(*) n FROM openai_accounts')['n']===0,'fresh install has no test accounts');
    fiCheck((int)$app->db->one('SELECT COUNT(*) n FROM seat_assignments')['n']===0,'fresh install has no seats');
    [$status,$json]=$request('GET','api/install.php'); fiCheck($status===200&&$json['data']['installed']&&!$json['data']['available'],'installed form status closed');
    [$status]=$request('POST','api/install.php',array_replace($base,['email'=>'another-'.$nonce.'@example.test']),$headers); fiCheck($status===409,'second installation rejected');
    fiCheck((int)$app->db->one('SELECT COUNT(*) n FROM users')['n']===1,'second installation cannot overwrite/create another user');
    $closedRoot=$scratch.'/closed-check'; mkdir($closedRoot.'/database/migrations',0700,true);
    foreach (glob($root.'/database/migrations/*.sql') as $migration) { copy($migration,$closedRoot.'/database/migrations/'.basename($migration)); }
    file_put_contents($closedRoot.'/database/migrations/004_must_not_run.sql','CREATE TABLE first_install_must_not_run(id INT PRIMARY KEY) ENGINE=InnoDB;');
    try { (new Installer(new App($closedRoot,$config)))->install($token,$base); throw new RuntimeException('FAIL: closed installer accepted'); }
    catch (ApiError $e) { fiCheck($e->status===409,'closed installer rejects retained valid setup token'); }
    fiCheck((int)$app->db->one("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='first_install_must_not_run'")['n']===0,'closed installer cannot run pending migrations');
    [$status,$json]=$request('GET','api/index.php?route=/session'); $loginCsrf=$json['data']['csrf_token'];
    [$status,$json]=$request('POST','api/index.php?route=/login',['email'=>$base['email'],'password'=>$password],['X-CSRF-Token: '.$loginCsrf]);
    fiCheck($status===200&&$json['data']['authenticated']&&in_array('access_groups.manage',$json['data']['permissions'],true),'new chief can sign in immediately');
    $audit=$app->db->all('SELECT details_json FROM audit_log'); fiCheck(!str_contains(json_encode($audit),$password)&&!str_contains(json_encode($audit),$token),'audit has no credential plaintext');
    $limiter=new Installer($app); for ($i=0;$i<20;$i++) { $limiter->limitAttempt('isolated-limiter'); }
    try { $limiter->limitAttempt('isolated-limiter'); throw new RuntimeException('FAIL: limiter allowed attempt21'); }
    catch (ApiError $e) { fiCheck($e->status===429,'pre-schema setup limiter bounds attempts'); }
    $disabled=$config; $disabled['setup']['enabled']=false;
    try { (new Installer(new App($scratch,$disabled)))->install($token,$base); throw new RuntimeException('FAIL: disabled setup accepted'); }
    catch (ApiError $e) { fiCheck($e->status===403,'disabled setup rejects valid token'); }
    $legacyDatabase=$database.'_legacy'; $db->exec('CREATE DATABASE `'.$legacyDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $legacy=$config; $legacy['db']['dsn']=str_replace('dbname='.$database.';','dbname='.$legacyDatabase.';',$config['db']['dsn']); $legacy['smtp']['enabled']=true;
    $legacyApp=new App($scratch,$legacy);
    try { (new Installer($legacyApp))->install($token,$identity); throw new RuntimeException('FAIL: new mail defaults bypassed'); }
    catch (ApiError $e) { fiCheck($e->status===422,'fresh install cannot issue inaccessible random password with delivery disabled'); }
    fiCheck((int)$legacyApp->db->one('SELECT COUNT(*) n FROM users')['n']===0,'disabled delivery creates no inaccessible chief');
    $prepared=(new AccManager\MailSettings($legacyApp))->current(); $prepared['delivery_enabled']=true;
    $legacyApp->db->run('UPDATE mail_settings SET settings_encrypted=? WHERE id=1',[$legacyApp->crypto->encrypt($prepared)]);
    (new Installer($legacyApp))->install($token,$identity);
    $legacyUser=$legacyApp->db->one('SELECT * FROM users'); $legacyMail=$legacyApp->db->one("SELECT payload_encrypted FROM mail_outbox WHERE category='initial_password'");
    fiCheck((int)$legacyUser['access_group_id']===4&&$legacyMail!==null,'legacy setup still creates chief and queues initial-password email');
    $payload=$legacyApp->crypto->decrypt($legacyMail['payload_encrypted']); preg_match('/رمز اولیه: ([a-f0-9]+)/u',$payload['body'],$match);
    fiCheck(isset($match[1])&&password_verify($match[1],$legacyUser['password_hash']),'legacy random emailed password remains usable');
    echo "PASS: {$count} isolated first-install checks; chosen password, no SMTP dependency, first login, CSRF, token and one-time guards.\n";
} catch (Throwable $e) {
    fwrite(STDERR,str_starts_with($e->getMessage(),'FAIL: ')?$e->getMessage()."\n":'First-install check failed ('.get_class($e).') at line '.$e->getLine()."\n"); $exit=1;
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ([$database,$legacyDatabase] as $temporary) { if ($db&&is_string($temporary)&&preg_match('/^acc_first_[a-f0-9]{12}(_legacy)?$/D',$temporary)) { $db->exec('DROP DATABASE IF EXISTS `'.$temporary.'`'); } }
}
exit($exit);
