<?php
declare(strict_types=1);
// Destructive only to the dedicated *_test database; never the development database.
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
$root=dirname(__DIR__,2); chdir($root);
require __DIR__.'/OperationsSupport.php'; configureTestEnvironment($root);
require $root.'/backend/vendor/autoload.php';
use AccManager\App;
use AccManager\Installer;
use AccManager\MailWorker;
use AccManager\Migrator;
use AccManager\Reminders;

final class Client
{
    private CurlHandle $curl;
    public string $csrf='';
    public array $lastHeaders=[];
    public string $base;
    public function __construct(?string $base=null)
    {
        $this->base=$base??testBaseUrl().'/api/index.php';
        $this->curl=curl_init(); curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20]);
    }
    public function cookie(): string
    {
        $cookies=curl_getinfo($this->curl,CURLINFO_COOKIELIST); foreach ($cookies as $cookie) { $parts=explode("\t",$cookie); if (str_starts_with($parts[5]??'','acc_')) { return $parts[5].'='.$parts[6]; } } return '';
    }
    public function injectCookie(string $cookie): void { curl_setopt($this->curl,CURLOPT_COOKIE,$cookie); }
    public function call(string $method,string $route,array|string|null $data=null,array $extra=[],bool $csrf=true): array
    {
        $this->lastHeaders=[]; $headers=['Content-Type: application/json'];
        if ($csrf) { $headers[]='X-CSRF-Token: '.$this->csrf; }
        foreach ($extra as $header) { $headers[]=$header; }
        curl_setopt_array($this->curl,[CURLOPT_URL=>$this->base.'?route='.rawurlencode($route),CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>null,CURLOPT_HEADERFUNCTION=>function ($curl,$line) { $this->lastHeaders[]=trim($line); return strlen($line); }]);
        if ($method!=='GET') { curl_setopt($this->curl,CURLOPT_POSTFIELDS,is_string($data)?$data:json_encode($data??new stdClass(),JSON_THROW_ON_ERROR)); }
        $raw=curl_exec($this->curl); if ($raw===false) { throw new RuntimeException('HTTP connection failed'); }
        $json=json_decode($raw,true); if (!is_array($json)) { throw new RuntimeException('Response is not JSON'); }
        if (isset($json['data']['csrf_token'])) { $this->csrf=$json['data']['csrf_token']; }
        return [(int)curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE),$json,$raw];
    }
    public function session(): array { return $this->call('GET','/session'); }
    public function login(string $email,string $password): array { $this->session(); return $this->call('POST','/login',['email'=>$email,'password'=>$password]); }
}
$passed=0;
function check(bool $condition,string $label): void { global $passed; if (!$condition) { throw new RuntimeException('FAIL: '.$label); } $passed++; }
function status(array $response,int $expected,string $label): void { check($response[0]===$expected,$label.' (HTTP '.$response[0].')'); }
function payload(array $response): array { return $response[1]['data']; }
function expectError(callable $call,int $status,string $label): void { try { $call(); } catch (AccManager\ApiError $e) { check($e->status===$status,$label); return; } throw new RuntimeException('FAIL: '.$label); }
function passwordFor(App $app,int $id): string {
    $row=$app->db->one("SELECT payload_encrypted FROM mail_outbox WHERE recipient_id=? AND category='initial_password' ORDER BY id DESC LIMIT 1",[$id]);
    $body=$app->crypto->decrypt($row['payload_encrypted'])['body']; preg_match('/رمز اولیه: ([a-f0-9]+)/u',$body,$matches); return $matches[1]??throw new RuntimeException('Initial password unavailable');
}
function tokenFor(App $app,int $id,string $purpose): string {
    $row=$app->db->one('SELECT m.payload_encrypted FROM mail_outbox m JOIN auth_tokens t ON t.id=m.auth_token_id WHERE t.user_id=? AND t.purpose=? AND t.consumed_at IS NULL ORDER BY t.id DESC LIMIT 1',[$id,$purpose]);
    $body=$app->crypto->decrypt($row['payload_encrypted'])['body']; preg_match('/token=([a-f0-9]{64})/',$body,$matches); return $matches[1]??throw new RuntimeException('Token unavailable');
}
function userId(App $app,string $email): int { return (int)$app->db->one('SELECT id FROM users WHERE email=?',[$email])['id']; }
function sinkCount(string $root): int { return count(glob($root.'/storage/mail-sink/*.enc')); }
function worker(App $app): array { return (new MailWorker($app))->run(100,20); }

try {
    $app=App::boot($root); $db=$app->db;
    testDatabaseGuard($app,true); testSubfolderBaseUrl(); check(true,'test database and HTTP isolation guard');
    $db->run('SET FOREIGN_KEY_CHECKS=0'); foreach ($db->all('SHOW TABLES') as $table) { $name=array_values($table)[0]; if (!preg_match('/^[a-z_]+$/',$name)) { throw new RuntimeException('Unexpected table'); } $db->pdo->exec('DROP TABLE `'.$name.'`'); } $db->run('SET FOREIGN_KEY_CHECKS=1');
    check(count((new Migrator($app))->run())===count(glob($root.'/database/migrations/*.sql')),'fresh migrations (stage 1 plus additive migrations)'); check((new Migrator($app))->run()===[],'migration repeat');
    check(!$db->all("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE<>'InnoDB'"),'all tables InnoDB');
    $secret=testSetupSecret($root);
    expectError(fn()=>(new Installer($app))->install('wrong',[]),403,'installer protected');
    (new Installer($app))->install($secret,['email'=>'admin@example.test','first_name'=>'مدیر','last_name'=>'آزمایش','department_name'=>'فنی']);
    expectError(fn()=>(new Installer($app))->install($secret,['email'=>'other@example.test','first_name'=>'دوم','last_name'=>'آزمایش','department_name'=>'دوم']),409,'installer cannot repeat');
    $adminId=userId($app,'admin@example.test'); $adminPassword=passwordFor($app,$adminId); $admin=new Client();
    $guest=new Client(); $response=$guest->session(); status($response,200,'anonymous session'); check(payload($response)['authenticated']===false&&strlen($guest->csrf)===64,'anonymous CSRF');
    $cookieHeaders=implode("\n",$guest->lastHeaders); check(str_contains(strtolower($cookieHeaders),'httponly')&&str_contains(strtolower($cookieHeaders),'samesite=lax'),'cookie flags');
    status($guest->call('POST','/login',['email'=>'admin@example.test','password'=>$adminPassword],[],false),403,'login CSRF required');
    status($guest->call('POST','/login',['email'=>'admin@example.test','password'=>$adminPassword],['Origin: https://attacker.invalid']),403,'cross-origin rejected');
    status($guest->call('POST','/login','{bad'),400,'malformed JSON'); status($guest->call('POST','/login','[]'),400,'JSON object required');
    status($guest->call('GET','/login'),405,'method guard'); status($guest->call('GET','/missing'),404,'unknown route');
    status($guest->call('POST','/register',str_repeat('x',32769)),413,'body limit');
    status($admin->session(),200,'admin anonymous start'); $beforeCookie=$admin->cookie(); $beforeCsrf=$admin->csrf;
    $response=$admin->call('POST','/login',['email'=>'ADMIN@EXAMPLE.TEST','password'=>$adminPassword]); status($response,200,'admin login canonical email');
    check($admin->cookie()!==$beforeCookie&&$admin->csrf!==$beforeCsrf,'login session rotation');
    $raw=$response[2]; check(!str_contains($raw,$adminPassword)&&!str_contains($raw,'password_hash')&&!str_contains($raw,'auth_version'),'session hides secrets');
    check(is_int(payload($response)['user']['id'])&&is_bool(payload($response)['user']['is_active'])&&payload($response)['user']['employee_number']===null,'public user types');
    $department=(int)$db->one('SELECT id FROM departments LIMIT 1')['id'];
    status($guest->call('GET','/notifications'),401,'notifications require login');
    $create=['email'=>'member@example.test','password'=>'Test-password-2026','first_name'=>'کاربر','last_name'=>'نمونه'];
    status($guest->call('POST','/register',$create+['app_role'=>'admin']),422,'registration mass assignment denied');
    foreach (['member@example.test.attacker.invalid','member@evil-example.test','member@sub.example.test'] as $email) { status($guest->call('POST','/register',array_replace($create,['email'=>$email])),422,'full domain exact match'); }
    status($guest->call('POST','/register',$create),202,'registration accepted'); $memberId=userId($app,$create['email']);
    status($guest->call('POST','/register',array_replace($create,['email'=>' MEMBER@EXAMPLE.TEST '])),202,'duplicate registration generic');
    check((int)$db->one("SELECT COUNT(*) n FROM users WHERE email='member@example.test'")['n']===1,'canonical identity unique');
    $row=$db->one('SELECT * FROM users WHERE id=?',[$memberId]); check($row['approval_status']==='pending_email'&&password_verify($create['password'],$row['password_hash']),'password hashed and first gate pending');
    status($guest->login($create['email'],$create['password']),401,'unverified cannot login');
    status($admin->call('POST','/admin/registrations/approve',['user_id'=>$memberId,'department_id'=>$department,'organizational_position'=>'employee']),409,'HR cannot skip email gate');
    $token=tokenFor($app,$memberId,'verify_email'); $stored=$db->one('SELECT token_hash FROM auth_tokens WHERE user_id=? ORDER BY id DESC LIMIT 1',[$memberId]);
    check($stored['token_hash']===hash('sha256',$token),'token hash only');
    $encrypted=$db->one('SELECT payload_encrypted FROM mail_outbox WHERE recipient_id=? ORDER BY id DESC LIMIT 1',[$memberId])['payload_encrypted']; check(!str_contains($encrypted,$token),'mail token encrypted');
    status($guest->call('POST','/resend-verification',['email'=>$create['email']]),202,'resend verification'); $newToken=tokenFor($app,$memberId,'verify_email');
    status($guest->call('POST','/verify',['token'=>$token]),422,'resend invalidates old link');
    $db->run("UPDATE auth_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE token_hash=?",[hash('sha256',$newToken)]);
    status($guest->call('POST','/verify',['token'=>$newToken]),422,'expired token denied');
    status($guest->call('POST','/resend-verification',['email'=>$create['email']]),202,'resend after expiry'); $newToken=tokenFor($app,$memberId,'verify_email');
    status($guest->call('POST','/verify',['token'=>$newToken]),200,'email verified');
    status($guest->call('POST','/verify',['token'=>$newToken]),422,'verification single use');
    status($guest->login($create['email'],$create['password']),401,'email verified still needs HR');
    check(count(payload($admin->call('GET','/admin/registrations'))['items'])===1,'HR waiting list second gate');
    status($admin->call('POST','/admin/users',['email'=>'badrole@example.test','first_name'=>'x','last_name'=>'y','department_id'=>$department,'organizational_position'=>'employee','app_role'=>'admin']),422,'provision role cannot be injected');
    $roles=[];
    foreach (['hr','operator','user'] as $role) {
        $r=$admin->call('POST','/admin/users',['email'=>$role.'@example.test','first_name'=>$role,'last_name'=>'آزمایش','department_id'=>$department,'organizational_position'=>$role==='user'?'supervisor':'employee']); status($r,201,'admin provisioning');
        $id=payload($r)['user']['id']; $password=passwordFor($app,$id); check(!str_contains($r[2],$password),'provision never returns password');
        if ($role!=='user') { status($admin->call('POST','/admin/users/role',['user_id'=>$id,'app_role'=>$role]),200,'role assignment'); }
        $client=new Client(); $r=$client->login($role.'@example.test',$password); status($r,200,'manager provisioning immediate login');
        check(in_array('personal.read',payload($r)['permissions'],true)&&in_array('personal.request',payload($r)['permissions'],true),'every role retains personal scope');
        $roles[$role]=['client'=>$client,'id'=>$id,'password'=>$password];
    }
    $hr=$roles['hr']['client']; $operator=$roles['operator']['client']; $supervisor=$roles['user']['client'];
    status($operator->call('GET','/admin/users'),403,'operator no HR data access'); status($hr->call('GET','/admin/settings'),403,'HR no settings'); status($supervisor->call('GET','/admin/users'),403,'supervisor not admin');
    status($hr->call('POST','/admin/users/role',['user_id'=>$roles['user']['id'],'app_role'=>'admin']),403,'HR cannot appoint manager');
    status($hr->call('POST','/admin/registrations/approve',['user_id'=>$memberId,'department_id'=>$department,'organizational_position'=>'supervisor']),200,'HR second gate approval');
    status($hr->call('POST','/admin/registrations/approve',['user_id'=>$memberId,'department_id'=>$department,'organizational_position'=>'supervisor']),409,'approval not repeated');
    $member=new Client(); $r=$member->login($create['email'],$create['password']); status($r,200,'approved member login'); check(payload($r)['user']['app_role']==='user','self signup gets only user role');
    check(!in_array('people.manage',payload($r)['permissions'],true),'organizational supervisor no management privilege');
    $notices=payload($member->call('GET','/notifications')); check($notices['unread_count']===1&&$notices['items'][0]['event_type']==='registration.approved','recipient notification actual event');
    $noticeId=$notices['items'][0]['id']; status($operator->call('POST','/notifications/read',['id'=>$noticeId]),404,'notification ownership');
    check(payload($operator->call('GET','/notifications'))['items']===[],'cross-recipient list isolation');
    status($member->call('POST','/notifications/read',['id'=>$noticeId]),200,'mark own notification'); check(payload($member->call('GET','/notifications'))['unread_count']===0,'unread count');
    status($member->call('POST','/notifications/read-all'),200,'mark all own notifications');
    $eventCount=(int)$db->one('SELECT COUNT(*) n FROM notifications')['n']; $mailCount=(int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n'];
    $db->transaction(fn()=>$app->events->notify($memberId,'registration-approved:'.$memberId,'registration.approved','حساب شما فعال شد','تکرار آزمایشی',$app->url()));
    check((int)$db->one('SELECT COUNT(*) n FROM notifications')['n']===$eventCount&&(int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n']===$mailCount,'event and mail idempotency');
    try { $db->transaction(function () use ($app,$memberId) { $app->events->notify($memberId,'rollback-event','test.rollback','test','test',$app->url()); throw new RuntimeException('rollback'); }); } catch (RuntimeException $e) {}
    check((int)$db->one('SELECT COUNT(*) n FROM notifications')['n']===$eventCount&&(int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n']===$mailCount,'business event rollback atomic');
    $otherSession=new Client(); status($otherSession->login($create['email'],$create['password']),200,'second session');
    $oldCookie=$member->cookie(); $oldCsrf=$member->csrf;
    status($member->call('POST','/change-password',['current_password'=>'incorrect','password'=>'Changed-password-2026']),422,'change requires current password');
    status($member->call('POST','/change-password',['current_password'=>$create['password'],'password'=>'Changed-password-2026']),200,'password change');
    check($member->cookie()!==$oldCookie&&$member->csrf!==$oldCsrf,'password session rotation'); check(payload($otherSession->session())['authenticated']===false,'password invalidates other session');
    status($otherSession->login($create['email'],$create['password']),401,'old password denied');
    status($otherSession->login($create['email'],'Changed-password-2026'),200,'new password works');
    $mailCount=(int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n'];
    $known=$guest->call('POST','/forgot',['email'=>$create['email']]); $unknown=$guest->call('POST','/forgot',['email'=>'absent@example.test']); status($known,202,'forgot known'); status($unknown,202,'forgot absent'); check($known[1]['data']===$unknown[1]['data'],'forgot response avoids enumeration');
    check((int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n']===$mailCount+1,'forgot only real recipient');
    $reset=tokenFor($app,$memberId,'reset_password'); status($guest->call('POST','/forgot',['email'=>$create['email']]),202,'forgot new link');
    status($guest->call('POST','/reset',['token'=>$reset,'password'=>'Reset-password-2026']),422,'old reset invalidated'); $reset=tokenFor($app,$memberId,'reset_password');
    status($guest->call('POST','/reset',['token'=>$reset,'password'=>'Reset-password-2026']),200,'password reset'); status($guest->call('POST','/reset',['token'=>$reset,'password'=>'Reset-password-2026']),422,'reset token single use');
    check(payload($member->session())['authenticated']===false&&payload($otherSession->session())['authenticated']===false,'reset revokes all sessions');
    status($member->login($create['email'],'Reset-password-2026'),200,'reset password usable');
    $auditBefore=(int)$db->one('SELECT COUNT(*) n FROM audit_log WHERE subject_id=?',[$memberId])['n'];
    status($hr->call('POST','/admin/users/status',['user_id'=>$memberId,'is_active'=>false]),200,'deactivation'); check(payload($member->session())['authenticated']===false,'disabled session revoked');
    status($member->login($create['email'],'Reset-password-2026'),401,'disabled login blocked');
    check($db->one('SELECT id FROM users WHERE id=?',[$memberId])!==null&&(int)$db->one('SELECT COUNT(*) n FROM audit_log WHERE subject_id=?',[$memberId])['n']>$auditBefore,'disabled history preserved');
    check((int)$db->one('SELECT COUNT(*) n FROM notifications WHERE recipient_id=?',[$memberId])['n']>0,'disabled notification history preserved');
    status($hr->call('POST','/admin/users/status',['user_id'=>$memberId,'is_active'=>true]),200,'reactivate');
    status($member->login($create['email'],'Reset-password-2026'),200,'reactivated login');
    status($admin->call('POST','/admin/users/status',['user_id'=>$adminId,'is_active'=>false]),409,'last admin cannot deactivate');
    status($admin->call('POST','/admin/users/role',['user_id'=>$adminId,'app_role'=>'user']),409,'last admin cannot demote');
    status($admin->call('POST','/admin/users/role',['user_id'=>$roles['operator']['id'],'app_role'=>'hr']),200,'role change'); check(payload($operator->session())['authenticated']===false,'role change invalidates current session');
    check((int)$db->one("SELECT COUNT(*) n FROM audit_log WHERE action='user.created' AND actor_id=?",[$adminId])['n']===3,'creation actor audit');
    $snapshot=$member->cookie(); status($member->call('POST','/logout'),200,'logout'); $replay=new Client(); $replay->injectCookie($snapshot); check(payload($replay->session())['authenticated']===false,'logged-out session cannot replay');
    // Failed-attempt limit and reset window, without counting healthy office logins.
    $failed=new Client(); $failed->session();
    for ($i=0;$i<8;$i++) { status($failed->call('POST','/login',['email'=>'bruteforce@example.test','password'=>'wrong']),401,'failed login counted'); }
    status($failed->call('POST','/login',['email'=>'bruteforce@example.test','password'=>'wrong']),429,'failed email limit');
    $db->run('UPDATE rate_limits SET window_started=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');
    status($failed->call('POST','/login',['email'=>'bruteforce@example.test','password'=>'wrong']),401,'rate window resets');
    $fixtureHash=password_hash('Office-password-2026',PASSWORD_DEFAULT); $office=new Client(); $office->session();
    $rateBefore=$db->one('SELECT COALESCE(SUM(attempts),0) n FROM rate_limits')['n'];
    for ($i=0;$i<60;$i++) {
        $email='office-'.$i.'@example.test';
        $db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,email_verified_at,approval_status) VALUES(?,?, 'office','fixture',?,UTC_TIMESTAMP(),'approved')",[$email,$fixtureHash,$department]);
        status($office->call('POST','/login',['email'=>$email,'password'=>'Office-password-2026']),200,'healthy office NAT login');
    }
    check($db->one('SELECT COALESCE(SUM(attempts),0) n FROM rate_limits')['n']===$rateBefore,'successful office logins do not consume failed budget');
    for ($i=0;$i<150;$i++) { status($guest->call('POST','/register',['email'=>'onboarding-'.$i.'@example.test','password'=>'Onboarding-password-2026','first_name'=>'ورود','last_name'=>'لیستی آزمایش']),202,'150 healthy signups on one IP'); }
    check((int)$db->one("SELECT COUNT(*) n FROM users WHERE email LIKE 'onboarding-%'")['n']===150,'all office onboarding persisted');
    for ($i=0;$i<150;$i++) {
        $email='onboarding-'.$i.'@example.test'; $id=userId($app,$email);
        status($guest->call('POST','/resend-verification',['email'=>$email]),202,'office NAT resend verification');
        $verify=tokenFor($app,$id,'verify_email');
        status($guest->call('POST','/verify',['token'=>$verify]),200,'150 valid office NAT verifications');
        status($hr->call('POST','/admin/registrations/approve',['user_id'=>$id,'department_id'=>$department,'organizational_position'=>'employee']),200,'150 office HR approvals');
        status($guest->call('POST','/forgot',['email'=>$email]),202,'150 valid office NAT recovery emails');
        $recovery=tokenFor($app,$id,'reset_password');
        status($guest->call('POST','/reset',['token'=>$recovery,'password'=>'Office-reset-password-2026']),200,'150 valid office NAT password resets');
    }
    check((int)$db->one("SELECT COUNT(*) n FROM users WHERE email LIKE 'onboarding-%' AND approval_status='approved'")['n']===150,'office onboarding completed through both gates');
    echo "Authentication, ownership, roles, registration and full office NAT onboarding/recovery checks passed.\n";
    // Cancel fixture backlog; only purpose-built mail cases below are delivered to the local sink.
    $db->run("UPDATE mail_outbox SET status='cancelled',payload_encrypted=NULL WHERE status IN ('pending','retry')");
    $count=sinkCount($root);
    $mailId=$app->mail->enqueue($adminId,'smtp-success','admin@example.test','SMTP local test','Private test body');
    check($app->mail->enqueue($adminId,'smtp-success','admin@example.test','SMTP local test','Private test body')===$mailId,'queue dedupe returns same id');
    $stats=worker($app); check($stats['sent']===1,'SMTP sink delivery'); check(sinkCount($root)===$count+1,'single sink message');
    check(worker($app)['sent']===0&&sinkCount($root)===$count+1,'worker rerun no duplicate');
    check($db->one('SELECT payload_encrypted FROM mail_outbox WHERE id=?',[$mailId])['payload_encrypted']===null,'sent secret payload erased');
    $saved=$app->config['smtp']['host']; $app->config['smtp']['host']='example.invalid';
    try { worker($app); throw new RuntimeException('FAIL: external SMTP allowed'); } catch (RuntimeException $e) { check($e->getMessage()==='Development SMTP must use the local sink','development blocks external SMTP'); } $app->config['smtp']['host']=$saved;
    file_put_contents($root.'/storage/run/smtp-mode','pre_fail');
    $failureId=$app->mail->enqueue($adminId,'smtp-retry','admin@example.test','Retry test','Retry test');
    check(worker($app)['retry']===1,'pre-delivery retry'); check(worker($app)['retry']===0,'retry waits backoff');
    $db->run('UPDATE mail_outbox SET available_at=UTC_TIMESTAMP() WHERE id=?',[$failureId]); check(worker($app)['retry']===1,'second retry');
    $db->run('UPDATE mail_outbox SET available_at=UTC_TIMESTAMP() WHERE id=?',[$failureId]); check(worker($app)['failed']===1,'bounded retry terminal');
    check((int)$db->one('SELECT attempts FROM mail_outbox WHERE id=?',[$failureId])['attempts']===3,'retry count bound');
    file_put_contents($root.'/storage/run/smtp-mode','drop_ack'); $count=sinkCount($root);
    $uncertain=$app->mail->enqueue($adminId,'smtp-uncertain','admin@example.test','Unknown acknowledgement','Uncertain');
    check(worker($app)['uncertain']===1,'unknown SMTP acceptance isolated');
    check(sinkCount($root)===$count+1&&worker($app)['sent']===0&&sinkCount($root)===$count+1,'uncertain not automatically redelivered');
    unlink($root.'/storage/run/smtp-mode');
    $stale=$app->mail->enqueue($adminId,'stale-worker','admin@example.test','Stale','Stale');
    $db->run("UPDATE mail_outbox SET status='sending',claimed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 16 MINUTE) WHERE id=?",[$stale]); worker($app);
    check($db->one('SELECT status FROM mail_outbox WHERE id=?',[$stale])['status']==='uncertain','interrupted worker cannot duplicate');
    $lock='acc-mail-'.substr(hash('sha256',$app->config['db']['dsn']),0,32); $db->one('SELECT GET_LOCK(?,0) n',[$lock]);
    $cmd=[PHP_BINARY,'tests/backend/worker.php']; $pipes=[]; $process=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root); $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); $db->one('SELECT RELEASE_LOCK(?) n',[$lock]);
    check((json_decode($stdout,true)['busy']??false)===true,'second worker respects DB lock');
    file_put_contents($root.'/storage/run/smtp-mode','delay_ack'); $count=sinkCount($root); $app->mail->enqueue($adminId,'concurrent-worker','admin@example.test','Concurrent','Concurrent');
    $processes=[];
    for ($i=0;$i<2;$i++) { $pipes=[]; $p=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root); $processes[]=[$p,$pipes]; }
    foreach ($processes as [$p,$pipes]) { stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); check(proc_close($p)===0,'concurrent worker exit'); }
    unlink($root.'/storage/run/smtp-mode'); check(sinkCount($root)===$count+1,'concurrent workers deliver once');
    $reminders=new Reminders($app); $end=new DateTimeImmutable('2027-03-22 00:00:00',new DateTimeZone('Asia/Tehran'));
    $db->transaction(fn()=>$reminders->schedulePremium($memberId,100,1,$end,$app->url('renew=100')));
    $db->transaction(fn()=>$reminders->schedulePremium($memberId,100,1,$end,$app->url('renew=100')));
    $row=$db->one('SELECT * FROM scheduled_reminders WHERE source_id=100');
    check($row['due_at']==='2027-03-20 20:30:00'&&$row['cancelled_at']===null,'Iran one-day reminder and idempotency');
    $db->transaction(fn()=>$reminders->schedulePremium($memberId,100,2,$end->modify('+2 days'),$app->url('renew=100')));
    check($db->one('SELECT cancelled_at FROM scheduled_reminders WHERE source_id=100 AND source_version=1')['cancelled_at']!==null,'extension cancels prior reminder version');
    check($db->one('SELECT dispatched_at FROM scheduled_reminders WHERE source_id=100 AND source_version=2')['dispatched_at']===null,'no fake reminder dispatch without assignment adapter');
    // Token race uses two independent PHP processes/DB connections, beyond the serial local HTTP server.
    $db->transaction(fn()=>$app->auth->issueToken($memberId,'reset_password'));
    $expired=tokenFor($app,$memberId,'reset_password');
    $expiredMail=$db->one('SELECT m.id FROM mail_outbox m JOIN auth_tokens t ON t.id=m.auth_token_id WHERE t.token_hash=?',[hash('sha256',$expired)])['id'];
    $db->run('UPDATE auth_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE token_hash=?',[hash('sha256',$expired)]);
    status($guest->call('POST','/reset',['token'=>$expired,'password'=>'Race-password-2026']),422,'expired password reset denied');
    worker($app); check($db->one('SELECT status FROM mail_outbox WHERE id=?',[$expiredMail])['status']==='cancelled','expired token email suppressed');
    $db->transaction(fn()=>$app->auth->issueToken($memberId,'reset_password')); $raceToken=tokenFor($app,$memberId,'reset_password');
    $versionBefore=(int)$db->one('SELECT auth_version FROM users WHERE id=?',[$memberId])['auth_version'];
    $processes=[];
    for ($i=0;$i<2;$i++) {
        $pipes=[]; $process=proc_open([PHP_BINARY,'tests/backend/token-race.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        fwrite($pipes[0],json_encode(['token'=>$raceToken,'password'=>'Race-password-2026'],JSON_THROW_ON_ERROR)); fclose($pipes[0]); $processes[]=[$process,$pipes];
    }
    $statuses=[]; foreach ($processes as [$process,$pipes]) { $statuses[]=trim(stream_get_contents($pipes[1])); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); } sort($statuses);
    check($statuses===['200','422'],'concurrent reset consumes once');
    check((int)$db->one('SELECT auth_version FROM users WHERE id=?',[$memberId])['auth_version']===$versionBefore+1,'concurrent reset has one password mutation');
    $subfolder=new Client(testSubfolderBaseUrl().'/api/index.php'); $r=$subfolder->session(); status($r,200,'subfolder session');
    check(str_contains(implode(' ', $subfolder->lastHeaders),'path=/portal/'),'subfolder cookie path');
    status($subfolder->login('admin@example.test',$adminPassword),200,'subfolder authenticated API');
    status($subfolder->call('POST','/forgot',['email'=>'admin@example.test']),202,'subfolder email route');
    $mail=$db->one("SELECT m.payload_encrypted FROM mail_outbox m JOIN auth_tokens t ON t.id=m.auth_token_id WHERE t.user_id=? AND t.purpose='reset_password' ORDER BY t.id DESC LIMIT 1",[$adminId]);
    check(str_contains($app->crypto->decrypt($mail['payload_encrypted'])['body'],testSubfolderBaseUrl().'/#action=reset&token='),'subfolder link base');
    status($hr->call('POST','/admin/departments',['name'=>'پشتیبانی','base_score'=>6]),422,'department score constrained');
    status($hr->call('POST','/admin/departments',['name'=>'پشتیبانی','base_score'=>3]),200,'HR department management');
    $updated=$hr->call('PATCH','/admin/users',['user_id'=>$memberId,'special_priority'=>true,'organizational_position'=>'employee','employee_number'=>'P-123']); status($updated,200,'HR profile and priority update');
    check(payload($updated)['user']['special_priority']===true&&payload($updated)['user']['app_role']==='user','priority independent from app role');
    status($admin->call('POST','/admin/settings',['allowed_email_domains'=>['EXAMPLE.TEST'],'manual_action_hours'=>24]),200,'admin settings update');
    $settings=$admin->call('GET','/admin/settings'); check(payload($settings)['allowed_email_domains']===['example.test']&&!str_contains($settings[2],'smtp')&&!str_contains($settings[2],'app_key'),'settings public projection');
    $provision=$hr->call('POST','/admin/users',['email'=>'hr-created@example.test','first_name'=>'ساخته','last_name'=>'منابع انسانی','department_id'=>$department,'organizational_position'=>'employee']); status($provision,201,'HR provisioning');
    $provisionId=payload($provision)['user']['id']; check(payload($provision)['user']['approval_status']==='approved'&&payload($provision)['user']['email_verified']===true,'provision organizational verification');
    check((int)$db->one("SELECT actor_id FROM audit_log WHERE action='user.created' AND subject_id=?",[$provisionId])['actor_id']===$roles['hr']['id'],'HR provisioning actor retained');
    status($guest->call('POST','/register',['email'=>'rejected@example.test','password'=>'Rejected-password-2026','first_name'=>'ثبت','last_name'=>'بررسی']),202,'rejected applicant signup');
    $rejectedId=userId($app,'rejected@example.test'); $verifyToken=tokenFor($app,$rejectedId,'verify_email');
    status($guest->call('POST','/reset',['token'=>$verifyToken,'password'=>'Wrong-purpose-2026']),422,'token purpose separated');
    status($guest->call('POST','/verify',['token'=>$verifyToken]),200,'rejected applicant email gate');
    status($hr->call('POST','/admin/registrations/reject',['user_id'=>$rejectedId,'reason'=>'نمونهٔ آزمون بررسی']),200,'HR registration rejection');
    status($guest->login('rejected@example.test','Rejected-password-2026'),401,'rejected cannot login');
    check($db->one('SELECT event_type FROM notifications WHERE recipient_id=?',[$rejectedId])['event_type']==='registration.rejected','rejection notifies actual recipient');
    $edgePassword=str_repeat('x',72);
    $db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,email_verified_at,approval_status) VALUES('edge@example.test',?,'edge','test',?,UTC_TIMESTAMP(),'approved')",[password_hash($edgePassword,PASSWORD_DEFAULT),$department]);
    $edge=new Client(); status($edge->login('edge@example.test',$edgePassword.'suffix'),401,'bcrypt truncation prefix cannot login');
    status($edge->login('edge@example.test',$edgePassword),200,'72 byte password works');
    status($guest->call('POST','/register',['email'=>'long-password@example.test','password'=>str_repeat('x',73),'first_name'=>'رمز','last_name'=>'آزمایش']),422,'oversize password rejected');
    $unicodeCases=[
        [str_repeat('a',11),422,'رمز عبور باید حداقل ۱۲ نویسه داشته باشد.'],
        [str_repeat('a',12),202,null],
        [str_repeat('م',6),422,'رمز عبور باید حداقل ۱۲ نویسه داشته باشد.'],
        [str_repeat('م',12),202,null],
        [str_repeat('م',36),202,null],
        [str_repeat('م',37),422,'رمز عبور طولانی است؛ رمز کوتاه‌تری انتخاب کنید.'],
        [str_repeat('a',72),202,null],
        [str_repeat('a',73),422,'رمز عبور طولانی است؛ رمز کوتاه‌تری انتخاب کنید.'],
    ];
    foreach ($unicodeCases as $index=>[$password,$expected,$message]) {
        $r=$guest->call('POST','/register',['email'=>'unicode-'.$index.'@example.test','password'=>$password,'first_name'=>'نویسه','last_name'=>'آزمایش']); status($r,$expected,'Unicode password policy');
        if ($message!==null) { check($r[1]['error']['message']===$message,'plain password validation message'); }
    }
    $legacyPassword=str_repeat('م',6);
    $db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,email_verified_at,approval_status) VALUES('legacy@example.test',?,'legacy','test',?,UTC_TIMESTAMP(),'approved')",[password_hash($legacyPassword,PASSWORD_DEFAULT),$department]);
    $legacy=new Client(); status($legacy->login('legacy@example.test',$legacyPassword),200,'new minimum does not block an existing shorter password');
    $allAudit=json_encode($db->all('SELECT details_json FROM audit_log'),JSON_THROW_ON_ERROR);
    check(!str_contains($allAudit,$adminPassword)&&!str_contains($allAudit,$reset)&&!str_contains($allAudit,$newToken)&&!str_contains($allAudit,'password_hash'),'audit secrets absent');
    $log=is_file($root.'/storage/logs/app.log')?file_get_contents($root.'/storage/logs/app.log'):'';
    check(!str_contains($log,$adminPassword)&&!str_contains($log,$reset)&&!str_contains($log,$newToken),'application logs secrets absent');
    echo "SMTP, retry, duplicate prevention, transaction and reminder-contract checks passed.\n";
    echo "PASS: {$passed} assertions on ".$db->one('SELECT VERSION() v')['v'].', PHP '.PHP_VERSION.".\n";
} catch (Throwable $e) {
    @unlink($root.'/storage/run/smtp-mode');
    // Fixed assertion labels only. Never dump objects, API bodies, SQL parameters or credentials.
    $message=str_starts_with($e->getMessage(),'FAIL: ')?$e->getMessage():'Unexpected test failure ('.get_class($e).') at line '.$e->getLine();
    fwrite(STDERR,$message.PHP_EOL); exit(1);
}
