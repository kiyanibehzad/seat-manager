<?php
declare(strict_types=1);
// Own DB/user, HTTP server, keys, sessions and private config. No dev/QA reset or external delivery.
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1'); date_default_timezone_set('UTC'); umask(0077);
$root=dirname(__DIR__,2); require $root.'/backend/vendor/autoload.php';
use AccManager\{App,ApiError,Installer,MailSettings,MailTransport,MailWorker,Migrator};
$count=0; $exit=0; $adminDb=null; $database=null; $principal=null; $scratch=null; $server=null;
function msCheck(bool $ok,string $label): void { global $count; if (!$ok) { throw new RuntimeException('FAIL: '.$label); } $count++; }
function msError(callable $f,int $status,string $label): void { try { $f(); } catch (ApiError $e) { msCheck($e->status===$status,$label); return; } throw new RuntimeException('FAIL: '.$label); }
final class MsClient
{
    public string $csrf=''; private CurlHandle $curl;
    public function __construct(private string $base) { $this->curl=curl_init(); curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>25]); }
    public function call(string $method,string $route,array $data=[],int $expected=200,bool $csrf=true): array
    {
        curl_setopt_array($this->curl,[CURLOPT_URL=>$this->base.'/api/index.php?'.http_build_query(['route'=>$route]),CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>null,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.($csrf?$this->csrf:'')]]);
        if ($method!=='GET') { curl_setopt($this->curl,CURLOPT_POSTFIELDS,json_encode($data?:new stdClass(),JSON_THROW_ON_ERROR)); }
        $raw=curl_exec($this->curl); $json=is_string($raw)?json_decode($raw,true):null;
        msCheck(is_array($json),'valid API JSON'); msCheck((int)curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE)===$expected,$route.' expected '.$expected.' actual '.curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE).' '.($json['error']['code']??''));
        if (isset($json['data']['csrf_token'])) { $this->csrf=$json['data']['csrf_token']; } return $json['data']??[];
    }
    public function login(string $email,string $password): void { $this->call('GET','/session'); $this->call('POST','/login',['email'=>$email,'password'=>$password]); }
}
function msChild(array $command,array $extraEnv=[]): array
{
    global $scratch; $env=getenv(); $env['ACC_CONFIG']=$scratch.'/config/local.php'; $pipes=[];
    $p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$scratch,array_replace($env,$extraEnv)); fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); return [proc_close($p),$out,$err];
}
try {
    $os=posix_getpwuid(posix_geteuid())['name']; $adminDb=new PDO('mysql:unix_socket='.$root.'/storage/run/db.sock;charset=utf8mb4',$os,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $instance=$adminDb->query('SELECT @@port port,@@datadir directory')->fetch(); msCheck((int)$instance['port']===33079&&realpath($instance['directory'])===realpath($root.'/storage/mysql'),'project instance guard');
    $nonce=bin2hex(random_bytes(6)); $database='acc_mail_'.$nonce; $dbUser='am_'.$nonce; $dbPassword=bin2hex(random_bytes(24)); $principal=$adminDb->quote($dbUser)."@'127.0.0.1'";
    $adminDb->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $adminDb->exec('CREATE USER '.$principal.' IDENTIFIED BY '.$adminDb->quote($dbPassword)); $adminDb->exec('GRANT ALL PRIVILEGES ON `'.$database.'`.* TO '.$principal);
    $scratch=$root.'/work/mail-settings-'.$nonce;
    foreach (['backend/src','bin','config','database/migrations','public_html/api','storage/logs','storage/run','storage/sessions'] as $dir) { mkdir($scratch.'/'.$dir,0700,true); }
    symlink($root.'/backend/vendor',$scratch.'/backend/vendor'); copy($root.'/backend/bootstrap.php',$scratch.'/backend/bootstrap.php'); copy($root.'/bin/cron.php',$scratch.'/bin/cron.php');
    copy($root.'/backend/src/SecurityHeaders.php',$scratch.'/backend/src/SecurityHeaders.php');
    foreach (['index.php','install.php'] as $file) { copy($root.'/public_html/api/'.$file,$scratch.'/public_html/api/'.$file); }
    foreach (['001_identity.sql','002_operations.sql','003_access_groups.sql'] as $file) { copy($root.'/database/migrations/'.$file,$scratch.'/database/migrations/'.$file); }
    $sock=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $port=(int)substr(strrchr(stream_socket_get_name($sock,false),':'),1); fclose($sock);
    $config=require $root.'/config/app.example.php'; $config['environment']='test'; $config['app_url']='http://127.0.0.1:'.$port; $config['app_key']=base64_encode(random_bytes(32));
    $config['db']=['dsn'=>'mysql:host=127.0.0.1;port=33079;dbname='.$database.';charset=utf8mb4','user'=>$dbUser,'password'=>$dbPassword];
    $config['session']['secure']=false; $config['session']['name']='am_'.$nonce;
    $config['smtp']=array_replace($config['smtp'],['enabled'=>true,'host'=>'127.0.0.1','port'=>10279,'encryption'=>'','from_email'=>'noreply@example.test','timeout_seconds'=>2]);
    $secret=bin2hex(random_bytes(32)); $config['setup']=['enabled'=>true,'token_hash'=>hash('sha256',$secret)]; $password=bin2hex(random_bytes(16));
    $identity=['email'=>'chief-'.$nonce.'@example.test','first_name'=>'مدیر','last_name'=>'آزمایش','department_name'=>'فنی','password'=>$password,'password_confirm'=>$password];
    file_put_contents($scratch.'/config/local.php','<?php return '.var_export($config,true).';'); $configHash=hash_file('sha256',$scratch.'/config/local.php');
    $app=new App($scratch,$config); (new Installer($app))->install($secret,$identity); $db=$app->db; $chief=$app->auth->user((int)$db->one('SELECT id FROM users')['id']);
    $db->run("INSERT INTO users(email,password_hash,first_name,last_name,app_role,access_group_id,email_verified_at,approval_status) VALUES(?,?,'عادی','آزمایش','admin',1,UTC_TIMESTAMP(),'approved')",['plain-'.$nonce.'@example.test',password_hash($password,PASSWORD_DEFAULT)]); $plain=$app->auth->user((int)$db->pdo->lastInsertId());
    $before=$db->all('SELECT * FROM users ORDER BY id'); $outbox=$app->mail->enqueue((int)$plain['id'],'kept-event','plain@example.test','عنوان خصوصی','متن خصوصی token=not-for-api');
    $mailBefore=$db->all('SELECT * FROM mail_outbox');
    copy($root.'/database/migrations/004_mail_settings.sql',$scratch.'/database/migrations/004_mail_settings.sql'); msCheck((new Migrator($app))->run()===['004_mail_settings.sql'],'only additive mail migration');
    msCheck($before===$db->all('SELECT * FROM users ORDER BY id'),'migration preserves users/hashes/versions'); msCheck($mailBefore===$db->all('SELECT * FROM mail_outbox'),'migration preserves encrypted queue'); msCheck((new Migrator($app))->run()===[],'migration repeat');
    $settings=new MailSettings($app); msCheck(!$settings->current()['delivery_enabled'],'new migration always defaults off despite legacy enabled');
    msCheck((new MailWorker($app))->run()['disabled']&&$db->one('SELECT status FROM mail_outbox WHERE id=?',[$outbox])['status']==='pending','disabled worker never sends pending events');
    msError(fn()=>$settings->view($plain),403,'legacy app_role spoof cannot access chief-only settings');
    $v=$settings->view($chief); msCheck(!$v['status']['cron_ready']&&$v['status']['worker_last_finished_at']!==null,'disabled worker heartbeat does not imply host cron');
    $smtpSecret='test-password-'.bin2hex(random_bytes(12));
    $save=function (array $fields) use ($settings,$chief): array { return $settings->save(['expected_revision'=>$settings->current()['revision']]+$fields,$chief); };
    $v=$save(['smtp_password'=>$smtpSecret]); msCheck($v['settings']['password_configured']&&!isset($v['settings']['smtp_password']),'password never returned');
    msCheck(!str_contains($db->one('SELECT settings_encrypted FROM mail_settings')['settings_encrypted'],$smtpSecret),'settings encrypted at rest');
    $save(['smtp_password'=>'']); msCheck($settings->current()['smtp_password']===$smtpSecret,'blank keeps password'); $save(['clear_password'=>true]); msCheck($settings->current()['smtp_password']==='','explicit clear'); $save(['smtp_password'=>$smtpSecret]);
    msError(fn()=>$settings->save(['expected_revision'=>0,'delivery_enabled'=>true],$chief),409,'stale revision cannot overwrite settings');
    foreach ([['smtp_password'=>'new','clear_password'=>true],['smtp_port'=>0],['smtp_port'=>'587'],['smtp_host'=>"localhost\r\nBcc:x"],['transport'=>null],['smtp_encryption'=>null],['from_name'=>"a\nb"],['delivery_enabled'=>1]] as $bad) { msError(fn()=>$save($bad),422,'invalid setting rejected'); }
    msError(fn()=>$save(['transport'=>'phpmail']),422,'nonproduction PHP mail rejected'); msError(fn()=>$save(['smtp_host'=>'smtp.example.test']),422,'nonproduction external SMTP rejected');
    $production=$config; $production['environment']='production'; $prod=new App($scratch,$production); msError(fn()=>(new MailTransport($prod))->assertAllowed($settings->current()),422,'production plaintext SMTP rejected');
    $log=fopen($scratch.'/storage/logs/http.log','ab'); $server=proc_open([PHP_BINARY,'-d','display_errors=0','-S','127.0.0.1:'.$port,'-t',$scratch.'/public_html'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes,$scratch); fclose($pipes[0]);
    for ($i=0;$i<100;$i++) { $probe=@fsockopen('127.0.0.1',$port,$errno,$error,.1); if ($probe) { fclose($probe); break; } usleep(20000); }
    $guest=new MsClient($config['app_url']); $guest->call('GET','/session'); $guest->call('GET','/admin/mail',[],401);
    $owner=new MsClient($config['app_url']); $owner->login($chief['email'],$password); $owner->call('POST','/admin/mail',['expected_revision'=>0],403,false);
    $user=new MsClient($config['app_url']); $user->login($plain['email'],$password);
    foreach (['/admin/mail','/admin/mail/test-connection','/admin/mail/send-test','/admin/mail/retry'] as $route) { $user->call($route==='/admin/mail'?'GET':'POST',$route,$route==='/admin/mail/send-test'?['recipient'=>'test@example.test','idempotency_key'=>'forbidden-test']:($route==='/admin/mail/retry'?['id'=>$outbox]:[]),403); }
    $view=$owner->call('GET','/admin/mail'); msCheck(!str_contains(json_encode($view),$smtpSecret)&&!str_contains(json_encode($view),'not-for-api')&&!str_contains(json_encode($view),'plain@example.test'),'API status redacts password/body/recipient');
    $countBefore=(int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n']; $connection=$owner->call('POST','/admin/mail/test-connection'); msCheck($connection['ok']&&$connection['code']==='smtp_connected','local SMTP handshake'); msCheck((int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n']===$countBefore,'connection test never enqueues email');
    $test=['recipient'=>'test@example.test','idempotency_key'=>'test-explicit-'.$nonce]; $sent=$owner->call('POST','/admin/mail/send-test',$test); msCheck($sent['status']==='sent','explicit test reaches local sink while automatic delivery off');
    msCheck($db->one('SELECT status FROM mail_outbox WHERE id=?',[$outbox])['status']==='pending','explicit test cannot flush ordinary mail');
    $again=$owner->call('POST','/admin/mail/send-test',$test); msCheck($again['id']===$sent['id']&&(int)$db->one('SELECT attempts FROM mail_outbox WHERE id=?',[$sent['id']])['attempts']===1,'idempotent test replay never resends');
    $owner->call('POST','/admin/mail/send-test',array_replace($test,['recipient'=>'other@example.test']),409);
    $save(['smtp_username'=>'unsupported-local-auth']); $failed=$owner->call('POST','/admin/mail/send-test',['recipient'=>'test@example.test','idempotency_key'=>'pre-failure-'.$nonce]); msCheck($failed['status']==='retry'&&$failed['code']==='smtp_pre_delivery_failure','pre-DATA failure safe retry classification');
    $owner->call('POST','/admin/mail/retry',['id'=>$failed['id']]); msCheck($db->one('SELECT attempts FROM mail_outbox WHERE id=?',[$failed['id']])['attempts']===0,'safe manual retry resets budget');
    $db->run("UPDATE mail_outbox SET status='sending',claimed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 16 MINUTE) WHERE id=?",[$failed['id']]); (new MailWorker($app))->run();
    msCheck($db->one('SELECT status FROM mail_outbox WHERE id=?',[$failed['id']])['status']==='uncertain','interrupted sending becomes uncertain even disabled'); $owner->call('POST','/admin/mail/retry',['id'=>$failed['id']],409); $owner->call('POST','/admin/mail/retry',['id'=>$sent['id']],409);
    $save(['smtp_username'=>'','delivery_enabled'=>true]); $app->auth->issueToken((int)$plain['id'],'reset_password'); $db->run('UPDATE auth_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND)');
    $authMail=$db->one("SELECT id FROM mail_outbox WHERE category='auth' ORDER BY id DESC LIMIT 1")['id']; $owner->call('POST','/admin/mail/retry',['id'=>(int)$authMail],409);
    $stats=(new MailWorker($app))->run(); msCheck($stats['sent']===1&&$stats['cancelled']===1,'enabled worker sends safe event and cancels expired auth link');
    $v=$settings->view($chief); msCheck($v['status']['last_success_at']!==null&&!$v['status']['cron_ready'],'last accepted send independent of cron proof');
    // Full identity path on the same disposable installation; tokens remain in memory only.
    $registered='registered-'.$nonce.'@example.test'; $guest->call('POST','/register',['email'=>$registered,'password'=>$password,'first_name'=>'ثبت','last_name'=>'آزمون'],202);
    $guest->call('POST','/login',['email'=>$registered,'password'=>$password],401);
    $registeredId=(int)$db->one('SELECT id FROM users WHERE email=?',[$registered])['id'];
    $tokenFor=function (string $purpose) use ($app,$db,$registeredId): string {
        $row=$db->one('SELECT m.payload_encrypted FROM mail_outbox m JOIN auth_tokens t ON t.id=m.auth_token_id WHERE t.user_id=? AND t.purpose=? AND t.consumed_at IS NULL ORDER BY t.id DESC LIMIT 1',[$registeredId,$purpose]);
        preg_match('/token=([a-f0-9]{64})/',$app->crypto->decrypt($row['payload_encrypted'])['body'],$m); return $m[1];
    };
    $oldVerify=$tokenFor('verify_email'); $guest->call('POST','/resend-verification',['email'=>$registered],202); $verify=$tokenFor('verify_email');
    msCheck($oldVerify!==$verify,'resend rotates verification token'); $guest->call('POST','/verify',['token'=>$oldVerify],422); $guest->call('POST','/verify',['token'=>$verify]); $guest->call('POST','/verify',['token'=>$verify],422);
    $guest->call('POST','/login',['email'=>$registered,'password'=>$password],401);
    $owner->call('POST','/admin/registrations/approve',['user_id'=>$registeredId,'department_id'=>(int)$chief['department_id'],'organizational_position'=>'employee']);
    $member=new MsClient($config['app_url']); $member->login($registered,$password); $guest->call('POST','/forgot',['email'=>$registered],202); $reset=$tokenFor('reset_password'); $newPassword=bin2hex(random_bytes(18));
    $guest->call('POST','/reset',['token'=>$reset,'password'=>$newPassword]); msCheck(!$member->call('GET','/session')['authenticated'],'password recovery revokes old session');
    $guest->call('POST','/reset',['token'=>$reset,'password'=>$newPassword],422); $guest->call('POST','/login',['email'=>$registered,'password'=>$password],401); $member->login($registered,$newPassword);
    $member->call('GET','/admin/mail',[],403);
    $app->auth->issueToken($registeredId,'reset_password'); $app->people->status(['user_id'=>$registeredId,'is_active'=>false],$chief);
    msCheck(!$member->call('GET','/session')['authenticated'],'deactivation revokes session');
    msCheck((int)$db->one("SELECT COUNT(*) n FROM mail_outbox WHERE recipient_id=? AND category='auth' AND status IN ('pending','retry')",[$registeredId])['n']===0,'deactivation cancels authentication queue');
    $save(['delivery_enabled'=>false]);
    $account=$owner->call('POST','/accounts',['email'=>'reminder-'.$nonce.'@example.test','idempotency_key'=>'reminder-account-'.$nonce])['account'];
    $owner->call('POST','/admin/capacity',['standard'=>1,'premium'=>1,'expected_revision'=>0,'confirmed'=>true,'reason'=>'ظرفیت آزمون','evidence'=>'فقط پایگاه موقت','idempotency_key'=>'reminder-capacity-'.$nonce]);
    $end=new DateTimeImmutable('+12 hours',new DateTimeZone('UTC'));
    $owner->call('POST','/admin/corrections',['account_id'=>$account['id'],'state_version'=>0,'state'=>['seat'=>'premium','starts_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-86400),'ends_at'=>$end->format('Y-m-d\TH:i:s\Z'),'return_seat'=>'standard'],'observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'confirmed'=>true,'reason'=>'وضعیت واقعی ساختگی','evidence'=>'فقط پایگاه آزمون','idempotency_key'=>'reminder-correction-'.$nonce]);
    $assignment=$db->one('SELECT * FROM seat_assignments WHERE account_id=? AND closed_at IS NULL',[$account['id']]);
    $reminders=new AccManager\Reminders($app); $firstReminder=$reminders->dispatch(); $repeatReminder=$reminders->dispatch();
    msCheck($firstReminder['reminders']===1&&$repeatReminder['reminders']===0,'due reminder dispatched once while mail disabled');
    $expired=$reminders->dispatch(100,$end->modify('+1 second')); msCheck($expired['action_alerts']===1,'expiry creates manual action alert');
    msCheck($assignment===$db->one('SELECT * FROM seat_assignments WHERE id=?',[$assignment['id']]),'reminder and expiry never release/change a seat');
    $reminders->schedulePremium((int)$chief['id'],(int)$assignment['id'],(int)$assignment['version']+100,$end,$app->url('renewal'));
    msCheck($reminders->dispatch()['cancelled']===1,'stale assignment version reminder cancelled');
    [$code,$output,$err]=msChild([PHP_BINARY,$scratch.'/bin/cron.php']); msCheck($code===0&&is_array(json_decode($output,true)),'isolated cron executes with delivery off'); msCheck($settings->view($chief)['status']['cron_ready'],'successful isolated cron creates freshness evidence');
    $db->run("UPDATE mail_service_state SET cron_last_finished_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 MINUTE)"); msCheck(!$settings->view($chief)['status']['cron_ready'],'stale cron evidence expires');
    $db->run("UPDATE mail_service_state SET cron_last_finished_at=UTC_TIMESTAMP(),cron_last_error_code='cron_failed'"); msCheck(!$settings->view($chief)['status']['cron_ready'],'failed cron not reported ready');
    // Exercise PHP mail only in a child with a fake local sendmail that discards all input.
    $production['app_url']='https://example.test'; $production['session']['secure']=true;
    file_put_contents($scratch.'/config/prod-test.php','<?php return '.var_export($production,true).';');
    $prodSettings=new MailSettings($prod); $prodSettings->save(['expected_revision'=>$settings->current()['revision'],'transport'=>'phpmail'],$chief);
    $availability=(new MailTransport($prod))->connection($prodSettings->current()); msCheck($availability['code']==='phpmail_available','PHP availability is separate from delivery proof');
    $fake=$scratch.'/storage/run/fake-sendmail'; file_put_contents($fake,"#!/bin/sh\ncat >/dev/null\nexit 0\n"); chmod($fake,0700);
    file_put_contents($scratch.'/storage/run/phpmail-test.php','<?php $app=require '.var_export($scratch.'/backend/bootstrap.php',true).'; $s=new AccManager\\MailSettings($app); $r=$s->sendTest(["recipient"=>"local@example.test","idempotency_key"=>"phpmail-simulation"],$app->auth->user('.(int)$chief['id'].')); echo json_encode($r);');
    [$code,$output,$err]=msChild([PHP_BINARY,'-d','sendmail_path='.$fake,$scratch.'/storage/run/phpmail-test.php'],['ACC_CONFIG'=>$scratch.'/config/prod-test.php']); $phpResult=json_decode($output,true); msCheck($code===0&&($phpResult['status']??'')==='sent','PHP mail success simulated with local discard-only executable');
    file_put_contents($fake,"#!/bin/sh\ncat >/dev/null\nexit 1\n");
    $script=file_get_contents($scratch.'/storage/run/phpmail-test.php'); file_put_contents($scratch.'/storage/run/phpmail-test.php',str_replace('phpmail-simulation','phpmail-failure',$script));
    [$code,$output,$err]=msChild([PHP_BINARY,'-d','sendmail_path='.$fake,$scratch.'/storage/run/phpmail-test.php'],['ACC_CONFIG'=>$scratch.'/config/prod-test.php']); $phpResult=json_decode($output,true); msCheck($code===0&&($phpResult['status']??'')==='uncertain'&&$phpResult['code']==='phpmail_acceptance_unknown','PHP mail failure never blindly resends');
    $prodSettings->save(['expected_revision'=>$settings->current()['revision'],'transport'=>'smtp','smtp_host'=>'127.0.0.1','smtp_port'=>10279,'smtp_encryption'=>'tls'],$chief);
    $save(['smtp_encryption'=>'']);
    $db->run('DELETE FROM rate_limits'); for ($i=0;$i<5;$i++) { $settings->connection([],$chief); } msError(fn()=>$settings->connection([],$chief),429,'connection tests bounded');
    $db->run('DELETE FROM rate_limits'); $save(['smtp_username'=>'unsupported-local-auth']); for ($i=0;$i<5;$i++) { $settings->sendTest(['recipient'=>'test@example.test','idempotency_key'=>'rate-test-'.$i],$chief); } msError(fn()=>$settings->sendTest(['recipient'=>'test@example.test','idempotency_key'=>'rate-test-over'],$chief),429,'send tests bounded');
    $audit=json_encode($db->all('SELECT details_json FROM audit_log')); msCheck(!str_contains($audit,$smtpSecret)&&!str_contains($audit,$password)&&!str_contains($audit,'not-for-api'),'audit never contains secret/body');
    msCheck(hash_file('sha256',$scratch.'/config/local.php')===$configHash,'private application config never overwritten');
    $stale=$chief; $db->run('UPDATE users SET auth_version=auth_version+1 WHERE id=?',[$chief['id']]); msError(fn()=>$settings->view($stale),403,'stale actor cannot view mail settings');
    echo "PASS: {$count} isolated mail checks; encrypted settings, chief/CSRF guards, SMTP local sink, PHP discard simulation, safe retry, cron freshness, migration preservation.\n";
} catch (Throwable $e) { fwrite(STDERR,str_starts_with($e->getMessage(),'FAIL: ')?$e->getMessage()."\n":'Mail check failed ('.get_class($e).') at line '.$e->getLine()."\n"); $exit=1; }
finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($adminDb&&is_string($database)&&preg_match('/^acc_mail_[a-f0-9]{12}$/D',$database)) { $adminDb->exec('DROP DATABASE IF EXISTS `'.$database.'`'); }
    if ($adminDb&&$principal) { $adminDb->exec('DROP USER IF EXISTS '.$principal); }
}
exit($exit);
