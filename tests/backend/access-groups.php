<?php
declare(strict_types=1);
// Creates its own DB/user/server/sessions. Never connects application requests to dev/QA.
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1'); date_default_timezone_set('UTC'); umask(0077);
$root=dirname(__DIR__,2); chdir($root); require $root.'/backend/vendor/autoload.php';
use AccManager\{App,ApiError,Events,Installer,Migrator,MailWorker};
use AccManager\Operations\{Accounts,Capacity,Domain,Engine};
$count=0; $exitCode=0; $server=null; $databases=[]; $adminDb=null; $databaseUser=null; $scratch=null;
function agCheck(bool $condition,string $label): void { global $count; if (!$condition) { throw new RuntimeException('FAIL: '.$label); } $count++; }
function agKey(): string { return 'groups-test-'.bin2hex(random_bytes(10)); }
final class AgClient
{
    public string $csrf=''; private CurlHandle $curl;
    public function __construct(private string $base) { $this->curl=curl_init(); curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]); }
    public function request(string $method,string $route,?array $data=null,array $query=[],bool $csrf=true): array
    {
        $headers=['Content-Type: application/json']; if ($csrf) { $headers[]='X-CSRF-Token: '.$this->csrf; }
        curl_setopt_array($this->curl,[CURLOPT_URL=>$this->base.'?'.http_build_query(['route'=>$route]+$query),CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>null,CURLOPT_HTTPHEADER=>$headers]);
        if ($method!=='GET') { curl_setopt($this->curl,CURLOPT_POSTFIELDS,json_encode($data===null||$data===[]?new stdClass():$data,JSON_THROW_ON_ERROR)); }
        $raw=curl_exec($this->curl); $body=is_string($raw)?json_decode($raw,true):null;
        if (!is_array($body)) { throw new RuntimeException('FAIL: HTTP JSON envelope'); }
        if (isset($body['data']['csrf_token'])) { $this->csrf=$body['data']['csrf_token']; }
        return ['status'=>(int)curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE),'json'=>$body];
    }
    public function get(string $route,array $query=[],int $expected=200): array { return $this->assertResponse($this->request('GET',$route,null,$query),$route,$expected); }
    public function write(string $route,array $data,int $expected=200,string $method='POST',bool $key=true): array
    { if ($key) { $data['idempotency_key']??=agKey(); } return $this->assertResponse($this->request($method,$route,$data),$route,$expected); }
    private function assertResponse(array $response,string $route,int $expected): array
    { agCheck($response['status']===$expected,$route.' HTTP '.$response['status'].' expected '.$expected.' '.($response['json']['error']['code']??'')); return $response['json']['data']??[]; }
    public function login(string $email,string $password): array
    { $this->get('/session'); return $this->write('/login',['email'=>$email,'password'=>$password],200,'POST',false); }
}
function agProcess(array $input): array
{
    global $root,$scratch; $pipes=[]; $env=getenv(); $env['ACC_CONFIG']=$scratch.'/config/local.php'; $env['ACC_GROUPS_TEST_ROOT']=$scratch;
    $p=proc_open([PHP_BINARY,$root.'/tests/backend/access-groups-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$scratch,$env);
    fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR)); fclose($pipes[0]); return [$p,$pipes];
}
function agJoin(array $process): array
{ [$p,$pipes]=$process; $raw=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); return json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
try {
    $osUser=posix_getpwuid(posix_geteuid())['name'];
    $adminDb=new PDO('mysql:unix_socket='.$root.'/storage/run/db.sock;charset=utf8mb4',$osUser,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $serverInfo=$adminDb->query('SELECT @@port port,@@datadir directory')->fetch();
    agCheck((int)$serverInfo['port']===33079&&realpath($serverInfo['directory'])===realpath($root.'/storage/mysql'),'project DB instance guard');
    $nonce=bin2hex(random_bytes(6)); $database='acc_groups_'.$nonce; $databaseUser='ag_'.$nonce; $databasePassword=bin2hex(random_bytes(24));
    $adminDb->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $databases[]=$database;
    $principal=$adminDb->quote($databaseUser)."@'127.0.0.1'";
    $adminDb->exec('CREATE USER '.$principal.' IDENTIFIED BY '.$adminDb->quote($databasePassword)); $adminDb->exec('GRANT ALL PRIVILEGES ON `'.$database.'`.* TO '.$principal);
    $scratch=$root.'/work/access-groups-'.$nonce;
    foreach (['backend','config','public_html/api','database/migrations','storage/logs','storage/sessions','storage/run'] as $dir) { mkdir($scratch.'/'.$dir,0700,true); }
    copy($root.'/backend/bootstrap.php',$scratch.'/backend/bootstrap.php'); symlink($root.'/backend/vendor',$scratch.'/backend/vendor');
    foreach (['index.php','install.php'] as $file) { copy($root.'/public_html/api/'.$file,$scratch.'/public_html/api/'.$file); }
    foreach (['001_identity.sql','002_operations.sql'] as $file) { copy($root.'/database/migrations/'.$file,$scratch.'/database/migrations/'.$file); }
    $socket=stream_socket_server('tcp://127.0.0.1:0',$err,$msg); $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
    $config=require $root.'/config/app.example.php'; $config['environment']='test'; $config['app_url']='http://127.0.0.1:'.$port; $config['app_key']=base64_encode(random_bytes(32));
    $config['db']=['dsn'=>'mysql:host=127.0.0.1;port=33079;dbname='.$database.';charset=utf8mb4','user'=>$databaseUser,'password'=>$databasePassword];
    $config['session']['name']='ag_'.$nonce; $config['session']['secure']=false;
    $config['smtp']=array_replace($config['smtp'],['enabled'=>true,'host'=>'127.0.0.1','port'=>10279,'encryption'=>'','from_email'=>'noreply@example.test','timeout_seconds'=>1]);
    $installSecret=bin2hex(random_bytes(32)); $config['setup']=['enabled'=>true,'token_hash'=>hash('sha256',$installSecret)];
    file_put_contents($scratch.'/config/local.php','<?php return '.var_export($config,true).';');
    $app=new App($scratch,$config); agCheck(count((new Migrator($app))->run())===2,'baseline migrations in fresh independent DB');
    $db=$app->db; $password=bin2hex(random_bytes(16)); $passwordHash=password_hash($password,PASSWORD_DEFAULT);
    $db->run("INSERT INTO departments(name,base_score) VALUES('فنی',4)"); $department=(int)$db->pdo->lastInsertId(); $ids=[]; $emails=[];
    foreach (['admin','hr','operator','user'] as $role) {
        $email=$role.'-'.$nonce.'@example.test'; $emails[$role]=$email;
        $db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,organizational_position,special_priority,app_role,email_verified_at,approval_status) VALUES(?,?,?,?,?,'supervisor',1,?,UTC_TIMESTAMP(),'approved')",[$email,$passwordHash,$role,'آزمون',$department,$role]);
        $ids[$role]=(int)$db->pdo->lastInsertId();
    }
    $db->run("INSERT INTO allowed_email_domains(domain) VALUES('example.test')");
    $domain=new Domain($app); $account=(new Accounts($domain))->create(['email'=>'kept-'.$nonce.'@example.test','idempotency_key'=>agKey()],$app->auth->user($ids['user']))['account'];
    (new Engine($domain))->correct(['account_id'=>$account['id'],'state_version'=>0,'state'=>['seat'=>'standard'],'observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'reason'=>'دادهٔ پایهٔ مهاجرت','evidence'=>'تست مستقل','confirmed'=>true,'idempotency_key'=>agKey()],$app->auth->user($ids['admin']));
    (new Capacity($domain))->set(['standard'=>2,'premium'=>1,'expected_revision'=>0,'reason'=>'دادهٔ پایه','evidence'=>'تست مستقل','confirmed'=>true,'idempotency_key'=>agKey()],$app->auth->user($ids['admin']));
    $app->events->notify($ids['operator'],'legacy-ops','request.created','صف پیشین','بدنهٔ پیشین',$app->url('legacy'));
    $legacyNotice=$db->one("SELECT * FROM notifications WHERE event_type='request.created'");
    $legacyPayload=$app->crypto->encrypt(['to'=>$emails['operator'],'subject'=>$legacyNotice['title'],'body'=>$legacyNotice['body']."\n".$legacyNotice['action_url']]);
    $db->run('UPDATE mail_outbox SET payload_encrypted=? WHERE recipient_id=?',[$legacyPayload,$ids['operator']]);
    $usersBefore=$db->all('SELECT * FROM users ORDER BY id'); $hashes=[];
    foreach (['openai_accounts','seat_assignments','seat_history','seat_capacity','capacity_history','mail_outbox'] as $table) { $hashes[$table]=hash('sha256',json_encode($db->all('SELECT * FROM '.$table),JSON_THROW_ON_ERROR)); }
    $env=getenv(); unset($env['ACC_CONFIG']); $pipes=[];
    $server=proc_open([PHP_BINARY,'-d','display_errors=0','-d','zend.exception_ignore_args=1','-S','127.0.0.1:'.$port,'-t',$scratch.'/public_html'],[0=>['file','/dev/null','r'],1=>['file',$scratch.'/storage/logs/http.log','a'],2=>['file',$scratch.'/storage/logs/http.log','a']],$pipes,$scratch,$env);
    $deadline=microtime(true)+5; do { $up=@fsockopen('127.0.0.1',$port,$err,$msg,.1); if ($up) { fclose($up); break; } usleep(10000); } while (microtime(true)<$deadline);
    $base=$config['app_url'].'/api/index.php'; $chief=new AgClient($base); $hr=new AgClient($base); $operator=new AgClient($base); $user=new AgClient($base); $guest=new AgClient($base);
    $old=$chief->login($emails['admin'],$password); agCheck(!$old['access_groups_ready']&&$old['user']['access_group_id']===null,'pre-migration API remains usable');
    $chief->get('/admin/access-groups',[],503); $operator->login($emails['operator'],$password); $operator->get('/admin/dashboard'); $operator->get('/admin/registrations',[],403);
    copy($root.'/database/migrations/003_access_groups.sql',$scratch.'/database/migrations/003_access_groups.sql');
    agCheck((new Migrator($app))->run()===['003_access_groups.sql'],'only additive group migration applied in independent DB');
    $app=new App($scratch,$config); $db=$app->db;
    $usersAfter=$db->all('SELECT * FROM users ORDER BY id');
    foreach ($usersBefore as $index=>$before) {
        $after=$usersAfter[$index]; agCheck((int)$after['access_group_id']===$app->accessGroups->legacyGroupId($before['app_role']),'existing role mapped to one group');
        agCheck((int)$after['auth_version']===(int)$before['auth_version']+1,'migration rotates existing authentication version');
        unset($before['auth_version'],$before['updated_at'],$after['access_group_id'],$after['auth_version'],$after['updated_at']); agCheck($before===$after,'identity and organization preserved');
    }
    foreach ($hashes as $table=>$hash) { agCheck(hash_equals($hash,hash('sha256',json_encode($db->all('SELECT * FROM '.$table),JSON_THROW_ON_ERROR))),'migration preserves '.$table); }
    agCheck($db->one('SELECT required_permission FROM notifications WHERE id=?',[$legacyNotice['id']])['required_permission']==='seats.manage','legacy managerial notification permission backfilled');
    agCheck(!$chief->get('/session')['authenticated']&&!$operator->get('/session')['authenticated'],'migration invalidates open sessions');
    $session=$chief->login($emails['admin'],$password); agCheck($session['access_groups_ready']&&$session['user']['access_group_id']===4&&$session['user']['is_super_admin'],'chief session has actual group metadata');
    $hrSession=$hr->login($emails['hr'],$password); $opSession=$operator->login($emails['operator'],$password); $userSession=$user->login($emails['user'],$password);
    agCheck(in_array('registrations.review',$opSession['permissions'],true)&&!in_array('people.manage',$opSession['permissions'],true),'operator reviews registrations without people management');
    foreach ([$session,$hrSession,$opSession,$userSession] as $s) { agCheck(in_array('personal.read',$s['permissions'],true)&&in_array('personal.request',$s['permissions'],true)&&in_array('notifications.read',$s['permissions'],true),'all groups retain personal/notification permissions'); }
    $catalog=$chief->get('/admin/access-groups'); $byKey=array_column($catalog['items'],null,'system_key');
    agCheck(count($catalog['capability_catalog'])===5&&$byKey['user']['protected']&&!$byKey['user']['is_super_admin']&&$byKey['user']['capabilities']===[],'five checkboxes and protected personal-only default');
    foreach (['user','admin'] as $system) { $g=$byKey[$system]; $chief->write('/admin/access-groups',['group_id'=>$g['id'],'name'=>'تغییر نام','capabilities'=>[],'expected_revision'=>$g['revision']],409,'PATCH'); }
    $chief->write('/admin/access-groups',['name'=>'روزانهٔ ناقص','capabilities'=>['dashboard.read']],422);
    $chief->write('/admin/access-groups',['name'=>'امتیاز ناقص','capabilities'=>['departments.manage']],422);
    $chief->write('/admin/access-groups',['name'=>'مالی غیرمجاز','capabilities'=>['finance.read']],422);
    $chief->write('/admin/access-groups',['name'=>'ارتقا غیرمجاز','capabilities'=>[],'is_super_admin'=>true],422);
    $operator->get('/admin/access-groups',[],403); $hr->get('/admin/access-groups',[],403); $user->get('/admin/access-groups',[],403);
    $operator->get('/admin/registrations'); $operator->get('/admin/users',[],403); $hr->get('/admin/users'); $hr->get('/admin/dashboard',[],403); $hr->get('/admin/queue',[],403);
    foreach ([$operator,$hr,$user] as $client) {
        foreach (['/admin/finance','/admin/settings'] as $route) { $client->get($route,[],403); }
        $client->write('/admin/users/access-group',['user_id'=>$ids['user'],'access_group_id'=>4],403);
        $client->write('/admin/users/role',['user_id'=>$ids['user'],'app_role'=>'admin'],403,'POST',false);
    }
    echo "Migration, compatibility, initial groups, protected defaults and API guard checks passed.\n";
    $createGroup=function (string $name,array $capabilities) use (&$chief): array { return $chief->write('/admin/access-groups',['name'=>$name,'capabilities'=>$capabilities],201)['group']; };
    $makeMember=function (int $group,string $label) use (&$chief,$app,$department,$base,$nonce): array {
        $email=$label.'-'.$nonce.'@example.test';
        $user=$chief->write('/admin/users',['email'=>$email,'first_name'=>$label,'last_name'=>'آزمون','department_id'=>$department,'organizational_position'=>'employee'],201,'POST',false)['user'];
        agCheck($user['access_group_id']===1&&!$user['is_super_admin'],'provisioned person defaults to protected user group');
        $row=$app->db->one("SELECT payload_encrypted FROM mail_outbox WHERE recipient_id=? AND category='initial_password'",[$user['id']]);
        preg_match('/رمز اولیه: ([a-f0-9]+)/u',$app->crypto->decrypt($row['payload_encrypted'])['body'],$m); $password=$m[1];
        if ($group!==1) { $chief->write('/admin/users/access-group',['user_id'=>$user['id'],'access_group_id'=>$group]); }
        $client=new AgClient($base); $session=$client->login($email,$password);
        return ['id'=>$user['id'],'email'=>$email,'password'=>$password,'client'=>$client,'session'=>$session];
    };
    $reviewGroup=$createGroup('بررسی ثبت‌نام',['registrations.review']); $peopleGroup=$createGroup('امور کارکنان',['people.manage']);
    $seatsGroup=$createGroup('رسیدگی عملیاتی',['seats.manage']); $dailyGroup=$createGroup('میزکار عملیاتی',['dashboard.read','seats.manage']);
    $deptGroup=$createGroup('بخش و اولویت',['people.manage','departments.manage']); $noneGroup=$createGroup('فقط شخصی',[]);
    $createOnce=['name'=>'ساخت یکتا','capabilities'=>[],'idempotency_key'=>agKey()];
    $once=$chief->write('/admin/access-groups',$createOnce,201)['group']; $again=$chief->write('/admin/access-groups',$createOnce,201)['group']; agCheck($once['id']===$again['id'],'create idempotency avoids duplicate group');
    $reviewer=$makeMember($reviewGroup['id'],'reviewer'); $people=$makeMember($peopleGroup['id'],'people'); $seats=$makeMember($seatsGroup['id'],'seats');
    $daily=$makeMember($dailyGroup['id'],'daily'); $dept=$makeMember($deptGroup['id'],'dept'); $plain=$makeMember(1,'plain');
    $reviewer['client']->get('/admin/registrations'); $reviewer['client']->get('/admin/users',[],403); $reviewer['client']->get('/admin/queue',[],403);
    $people['client']->get('/admin/users'); $people['client']->get('/admin/registrations',[],403); $people['client']->get('/admin/dashboard',[],403);
    $people['client']->write('/admin/departments',['name'=>'غیرمجاز','base_score'=>2],403,'POST',false);
    $people['client']->write('/admin/users',['user_id'=>$plain['id'],'first_name'=>'نام تازه'],200,'PATCH',false);
    $people['client']->write('/admin/users',['user_id'=>$plain['id'],'special_priority'=>true],403,'PATCH',false);
    $dept['client']->write('/admin/departments',['name'=>'بخش مجاز','base_score'=>3],200,'POST',false);
    $dept['client']->write('/admin/users',['user_id'=>$plain['id'],'special_priority'=>true],200,'PATCH',false);
    $seats['client']->get('/admin/queue'); $seats['client']->get('/admin/capacity'); $seats['client']->get('/admin/dashboard',[],403);
    $daily['client']->get('/admin/dashboard'); $daily['client']->get('/admin/queue');
    foreach ([$reviewer['client'],$people['client'],$seats['client'],$daily['client'],$dept['client'],$plain['client']] as $client) {
        $client->get('/admin/finance',[],403); $client->get('/admin/settings',[],403); $client->get('/admin/access-groups',[],403);
        $client->write('/admin/capacity',['standard'=>100,'premium'=>100,'expected_revision'=>0,'confirmed'=>true,'reason'=>'آزمون','evidence'=>'آزمون'],403);
        $client->write('/admin/users/access-group',['user_id'=>$plain['id'],'access_group_id'=>4],403);
        $own=$client->get('/accounts'); agCheck(is_array($own['items']),'every custom capability group retains own account path');
    }
    $plainAccount=$plain['client']->write('/accounts',['email'=>'plain-account-'.$nonce.'@example.test'])['account'];
    $orgBefore=$db->one('SELECT department_id,organizational_position,special_priority FROM users WHERE id=?',[$plain['id']]);
    $scoreBefore=$db->one("SELECT d.base_score+(u.organizational_position='supervisor')+u.special_priority score FROM users u JOIN departments d ON d.id=u.department_id WHERE u.id=?",[$plain['id']])['score'];
    $chief->write('/admin/users/access-group',['user_id'=>$plain['id'],'access_group_id'=>$dailyGroup['id']]);
    agCheck(!$plain['client']->get('/session')['authenticated'],'assignment invalidates existing session'); $s=$plain['client']->login($plain['email'],$plain['password']);
    agCheck($s['user']['access_group_name']===$dailyGroup['name']&&$s['user']['access_group_id']===$dailyGroup['id'],'session identifies custom group');
    agCheck($db->one('SELECT department_id,organizational_position,special_priority FROM users WHERE id=?',[$plain['id']])===$orgBefore,'assignment does not alter organization or special priority');
    $scoreAfter=$db->one("SELECT d.base_score+(u.organizational_position='supervisor')+u.special_priority score FROM users u JOIN departments d ON d.id=u.department_id WHERE u.id=?",[$plain['id']])['score']; agCheck($scoreBefore===$scoreAfter,'assignment does not add queue points');
    $chief->write('/admin/users/access-group',['user_id'=>$plain['id'],'access_group_id'=>$noneGroup['id']]); $plain['client']->login($plain['email'],$plain['password']);
    $db->run("UPDATE users SET app_role='admin' WHERE id=?",[$plain['id']]);
    $s=$plain['client']->get('/session'); agCheck(!in_array('finance.read',$s['permissions'],true)&&$s['capabilities']===[]&&!$s['user']['is_super_admin'],'app_role spoof cannot grant permissions');
    $plain['client']->get('/admin/finance',[],403); $plain['client']->get('/admin/queue',[],403);
    $db->run("INSERT INTO access_group_capabilities(group_id,capability) VALUES(1,'people.manage')");
    $s=$user->get('/session'); agCheck(!in_array('people.manage',$s['permissions'],true)&&$s['capabilities']===[],'protected user stays personal-only even with a stray capability row');
    agCheck(!in_array($ids['user'],array_map('intval',$app->auth->recipients('people.manage')),true),'protected user excluded from managerial recipients');
    $db->run("DELETE FROM access_group_capabilities WHERE group_id=1 AND capability='people.manage'");
    $seatAccount=$seats['client']->write('/accounts',['email'=>'seat-owner-'.$nonce.'@example.test'])['account'];
    $request=$seats['client']->write('/requests',['account_id'=>$seatAccount['id'],'seat'=>'standard'])['request'];
    $operation=$seats['client']->write('/admin/requests/start',['request_id'=>$request['id'],'state_version'=>0,'reason'=>'آزمون دسترسی واقعی'])['operation'];
    $seats['client']->write('/admin/operations/invitation',['operation_id'=>$operation['id'],'waiting'=>true]);
    $own=$seats['client']->get('/requests/detail',['id'=>$request['id']])['request']; agCheck($own['operation_phase']==='waiting_invite','custom operator has complete request/invitation flow');
    $item=$operation['items'][0]['account'];
    $seats['client']->write('/admin/operations/result',['operation_id'=>$operation['id'],'complete'=>true,'results'=>[['account_id'=>$item['id'],'owner_id'=>$item['owner_id'],'email'=>$item['email'],'state_version'=>$item['state_version'],'confirmed'=>true,'seat'=>'standard','observed_at'=>gmdate('Y-m-d\TH:i:s\Z')]],'reason'=>'نتیجهٔ تست','evidence'=>'مشاهدهٔ واقعی تست']);
    $seats['client']->get('/admin/history'); $seats['client']->get('/admin/requests',['status'=>'completed']);
    echo "Custom capabilities, dependencies, ownership and role-independent authority checks passed.\n";

    $register=function (string $label) use ($guest,$password,$app,$nonce): array {
        $email=$label.'-'.$nonce.'@example.test'; $guest->get('/session');
        $guest->write('/register',['email'=>$email,'password'=>$password,'first_name'=>$label,'last_name'=>'ثبت‌نام'],202,'POST',false);
        $user=$app->db->one('SELECT id FROM users WHERE email=?',[$email]);
        $mail=$app->db->one("SELECT m.payload_encrypted FROM mail_outbox m JOIN auth_tokens t ON t.id=m.auth_token_id WHERE t.user_id=? AND t.purpose='verify_email' AND t.consumed_at IS NULL",[$user['id']]);
        preg_match('/token=([a-f0-9]{64})/',$app->crypto->decrypt($mail['payload_encrypted'])['body'],$m);
        $guest->write('/verify',['token'=>$m[1]],200,'POST',false); return ['id'=>(int)$user['id'],'email'=>$email];
    };
    $signup=$register('approved');
    $reviewRecipients=array_map('intval',array_column($db->all("SELECT recipient_id FROM notifications WHERE event_type='registration.ready' AND event_key=?",[hash('sha256','registration-ready:'.$signup['id'])]),'recipient_id'));
    agCheck(in_array($ids['operator'],$reviewRecipients,true)&&in_array($reviewer['id'],$reviewRecipients,true),'operator and custom registration reviewer receive verification event');
    agCheck(!in_array($people['id'],$reviewRecipients,true)&&!in_array($seats['id'],$reviewRecipients,true),'people-only and seats-only do not receive registration event');
    $operator->write('/admin/registrations/approve',['user_id'=>$signup['id'],'department_id'=>$department,'organizational_position'=>'employee','access_group_id'=>4],422,'POST',false);
    try { $app->people->approve(['user_id'=>$signup['id'],'department_id'=>$department,'organizational_position'=>'employee'],$app->auth->user($people['id'])); throw new RuntimeException('FAIL: direct handler bypass'); }
    catch (ApiError $e) { agCheck($e->status===403,'transactional registration guard separated from people.manage'); }
    $approved=$operator->write('/admin/registrations/approve',['user_id'=>$signup['id'],'department_id'=>$department,'organizational_position'=>'employee'],200,'POST',false)['user'];
    agCheck($approved['access_group_id']===1&&!$approved['is_super_admin'],'operator approval creates only protected ordinary user');
    $operator->write('/admin/users',['user_id'=>$signup['id'],'first_name'=>'غیرمجاز'],403,'PATCH',false);
    $rejected=$register('rejected'); $reviewer['client']->write('/admin/registrations/reject',['user_id'=>$rejected['id'],'reason'=>'رد آزمایشی'],200,'POST',false);
    agCheck($db->one('SELECT approval_status FROM users WHERE id=?',[$rejected['id']])['approval_status']==='rejected','review-only custom group can reject');
    $newGroupInjection=$chief->write('/admin/users',['email'=>'injection-'.$nonce.'@example.test','first_name'=>'ثبت','last_name'=>'تست','department_id'=>$department,'organizational_position'=>'employee','access_group_id'=>4],422,'POST',false);
    $rows=[]; for ($i=0;$i<2;$i++) { $rows[]=['email'=>'bulk-'.$i.'-'.$nonce.'@example.test','first_name'=>'لیست','last_name'=>'تست','department_id'=>$department,'organizational_position'=>'employee']; }
    $batch=$people['client']->write('/admin/users/bulk-preview',['rows'=>$rows],200,'POST',false); agCheck($batch['valid'],'people-only bulk preview');
    $bulk=$people['client']->write('/admin/users/bulk-commit',['batch_id'=>$batch['batch_id']])['users'];
    foreach ($bulk as $staff) { agCheck($staff['access_group_id']===1&&!$staff['is_super_admin'],'bulk defaults never inherit creator group'); }
    $reviewer['client']->write('/admin/users/bulk-preview',['rows'=>$rows],403,'POST',false);
    echo "Registration approval/rejection separation and protected provisioning defaults passed.\n";

    $beforeRevision=$deptGroup['revision']; $beforeVersion=(int)$db->one('SELECT auth_version FROM users WHERE id=?',[$dept['id']])['auth_version'];
    $barrier=bin2hex(random_bytes(6)); $worker=agProcess(['actor_id'=>$dept['id'],'action'=>'department','barrier'=>$barrier,'data'=>['name'=>'باید رد شود '.$nonce,'base_score'=>2]]);
    $barrierPath=$scratch.'/storage/run/group-'.$barrier; $deadline=microtime(true)+5;
    while (!is_file($barrierPath.'.ready')&&microtime(true)<$deadline) { usleep(1000); } agCheck(is_file($barrierPath.'.ready'),'stale actor captured before group change');
    $edit=['group_id'=>$deptGroup['id'],'name'=>'امور کارکنانِ محدود','capabilities'=>['people.manage'],'expected_revision'=>$beforeRevision,'idempotency_key'=>agKey()];
    $changed=$chief->write('/admin/access-groups',$edit,200,'PATCH')['group'];
    $chief->write('/admin/access-groups',$edit,200,'PATCH');
    agCheck($changed['revision']===$beforeRevision+1&&(int)$db->one('SELECT auth_version FROM users WHERE id=?',[$dept['id']])['auth_version']===$beforeVersion+1,'group edit and replay rotate member version once');
    file_put_contents($barrierPath.'.go','go'); $out=agJoin($worker); agCheck($out['status']===401,'stale actor rejected inside mutation after revocation');
    agCheck(!$db->one('SELECT id FROM departments WHERE name=?',['باید رد شود '.$nonce]),'revoked transactional mutation made no write');
    agCheck(!$dept['client']->get('/session')['authenticated'],'open session revoked after group capability edit');
    $session=$dept['client']->login($dept['email'],$dept['password']); agCheck($session['user']['access_group_name']==='امور کارکنانِ محدود'&&!in_array('departments.manage',$session['permissions'],true),'new session has actual renamed group and reduced capabilities');
    $dept['client']->write('/admin/departments',['name'=>'ممنوع','base_score'=>2],403,'POST',false);
    $chief->write('/admin/access-groups',['group_id'=>$deptGroup['id'],'name'=>'نسخهٔ قدیمی','capabilities'=>['people.manage'],'expected_revision'=>$beforeRevision],409,'PATCH');
    echo "Revision checks, open-session invalidation and transactional stale-actor rejection passed.\n";
    $opGroup=$chief->get('/admin/access-groups')['items']; $opGroup=array_column($opGroup,null,'system_key')['operator'];
    $chief->write('/admin/access-groups',['group_id'=>$opGroup['id'],'name'=>$opGroup['name'],'capabilities'=>['registrations.review'],'expected_revision'=>$opGroup['revision']],200,'PATCH');
    agCheck(!$operator->get('/session')['authenticated'],'initial operator sessions also rotate on group edit'); $operator->login($emails['operator'],$password);
    $operator->get('/admin/registrations'); $operator->get('/admin/queue',[],403); $operator->get('/admin/dashboard',[],403);
    $hrGroup=$chief->get('/admin/access-groups')['items']; $hrGroup=array_column($hrGroup,null,'system_key')['hr'];
    $chief->write('/admin/access-groups',['group_id'=>$hrGroup['id'],'name'=>'واحد اداری','capabilities'=>['people.manage','departments.manage'],'expected_revision'=>$hrGroup['revision']],200,'PATCH');
    $nextSignup=$register('capability-recipients');
    $recipients=array_map('intval',array_column($db->all("SELECT recipient_id FROM notifications WHERE event_type='registration.ready' AND event_key=?",[hash('sha256','registration-ready:'.$nextSignup['id'])]),'recipient_id'));
    agCheck(!in_array($ids['hr'],$recipients,true)&&in_array($ids['operator'],$recipients,true)&&in_array($reviewer['id'],$recipients,true)&&!in_array($plain['id'],$recipients,true),'registration recipients follow capabilities, not legacy role');
    $domain=new Domain($app); $domain->managers('capability-manager-notice','request.created','مدیریت واقعی','جزئیات فقط مجاز',$app->url('manager'));
    $recipients=array_map('intval',array_column($db->all('SELECT recipient_id FROM notifications WHERE event_key=?',[hash('sha256','capability-manager-notice')]),'recipient_id'));
    agCheck(in_array($seats['id'],$recipients,true)&&in_array($daily['id'],$recipients,true)&&!in_array($ids['operator'],$recipients,true),'operation recipients follow seats capability, not operator role');
    $newNotice=$db->one('SELECT * FROM notifications WHERE recipient_id=? AND event_key=?',[$seats['id'],hash('sha256','capability-manager-notice')]);
    $newMail=$db->one('SELECT * FROM mail_outbox WHERE idempotency_key=?',[hash('sha256','event:'.$seats['id'].':capability-manager-notice')]);
    $newPayload=$app->crypto->decrypt($newMail['payload_encrypted']); agCheck($newPayload['required_permission']==='seats.manage','queued managerial email carries capability metadata');
    $app->events->notify($seats['id'],'private-after-revoke','auth.password_changed','اعلان شخصی','متن فقط برای خودم',$app->url());
    $edit=['group_id'=>$seatsGroup['id'],'name'=>'شخصی پس از لغو','capabilities'=>[],'expected_revision'=>$seatsGroup['revision']];
    $chief->write('/admin/access-groups',$edit,200,'PATCH'); $seats['client']->login($seats['email'],$seats['password']);
    $visible=$seats['client']->get('/notifications'); agCheck(!array_filter($visible['items'],fn($n)=>$n['id']===(int)$newNotice['id']),'revoked managerial notification hidden');
    $ownNotice=$db->one('SELECT id FROM notifications WHERE recipient_id=? AND event_key=?',[$seats['id'],hash('sha256','private-after-revoke')]);
    agCheck(in_array((int)$ownNotice['id'],array_column($visible['items'],'id'),true),'personal notification remains visible');
    $expectedUnread=(int)$db->one('SELECT COUNT(*) n FROM notifications WHERE recipient_id=? AND read_at IS NULL AND required_permission IS NULL',[$seats['id']])['n']; agCheck($visible['unread_count']===$expectedUnread,'unread count excludes hidden privileged notifications');
    $seats['client']->write('/notifications/read',['id'=>(int)$newNotice['id']],404,'POST',false);
    $seats['client']->write('/notifications/read-all',[],200,'POST',false); agCheck($db->one('SELECT read_at FROM notifications WHERE id=?',[$newNotice['id']])['read_at']===null,'read-all cannot acknowledge hidden managerial notification');
    $legacyMail=$db->one('SELECT id,payload_encrypted FROM mail_outbox WHERE recipient_id=? ORDER BY id LIMIT 1',[$ids['operator']]);
    agCheck(!array_key_exists('required_permission',$app->crypto->decrypt($legacyMail['payload_encrypted'])),'pre-migration email fixture lacks new metadata');
    agCheck(!$app->events->mailAllowed($ids['operator'],$app->crypto->decrypt($legacyMail['payload_encrypted'])),'legacy queued mail rechecked against backfilled notification and actual group');
    set_error_handler(static function (int $level,string $message): never { throw new RuntimeException('FAIL: auth mail metadata inspected'); });
    try { agCheck($app->events->mailAllowed($seats['id'],['body'=>'توکن خصوصی '.bin2hex(random_bytes(32))],'auth'),'auth tokens never enter legacy event matching'); }
    finally { restore_error_handler(); }
    agCheck($app->events->mailAllowed($seats['id'],['subject'=>'اعلان شخصی','body'=>'متن فقط برای خودم'."\n".$app->url(),'required_permission'=>null]),'personal mail remains allowed after capability removal');
    $db->run("UPDATE mail_outbox SET status='cancelled',payload_encrypted=NULL WHERE id NOT IN (?,?)",[$newMail['id'],$legacyMail['id']]);
    if (getenv('ACC_GROUPS_SKIP_MAIL_WORKER')!=='1') {
        $stats=(new MailWorker($app))->run(5,5);
        agCheck($stats['cancelled']===2&&$stats['sent']===0&&$stats['retry']===0,'mail worker cancels revoked new and legacy email before any SMTP attempt');
        agCheck($db->one('SELECT last_error_code FROM mail_outbox WHERE id=?',[$newMail['id']])['last_error_code']==='permission_revoked','revocation cancellation has safe metadata code');
    } else { echo "MailWorker execution deferred for the exclusive SMTP regression window.\n"; }
    echo "Capability-based recipients, hidden notifications and queued-email revocation checks passed.\n";

    $chief->write('/admin/users/access-group',['user_id'=>$ids['admin'],'access_group_id'=>1],409);
    $chief->write('/admin/users/status',['user_id'=>$ids['admin'],'is_active'=>false],409,'POST',false);
    $chief->write('/admin/users/role',['user_id'=>$ids['admin'],'app_role'=>'user'],409,'POST',false);
    $second=$makeMember(4,'chief-two');
    $cachedChief=$app->auth->user($ids['admin']);
    $chief->write('/admin/users/access-group',['user_id'=>$ids['admin'],'access_group_id'=>1]);
    agCheck(!$chief->get('/session')['authenticated'],'chief self-assignment revokes own session when another active chief exists');
    try { $app->accessGroups->save(['name'=>'نباید ساخته شود','capabilities'=>[],'idempotency_key'=>agKey()],$cachedChief); throw new RuntimeException('FAIL: stale chief mutated group'); }
    catch (ApiError $e) { agCheck($e->status===401,'stale chief denied before group mutation'); }
    $chief=$second['client'];
    $legacy=$chief->write('/admin/users/role',['user_id'=>$plain['id'],'app_role'=>'hr'],200,'POST',false)['user'];
    agCheck($legacy['access_group_id']===2&&$legacy['access_group_name']==='واحد اداری','legacy role endpoint delegates to actual initial group');
    $third=$makeMember(4,'chief-three');
    $p1=agProcess(['actor_id'=>$second['id'],'action'=>'assign','data'=>['user_id'=>$second['id'],'access_group_id'=>1,'idempotency_key'=>agKey()]]);
    $p2=agProcess(['actor_id'=>$third['id'],'action'=>'assign','data'=>['user_id'=>$third['id'],'access_group_id'=>1,'idempotency_key'=>agKey()]]);
    $statuses=[agJoin($p1)['status'],agJoin($p2)['status']]; sort($statuses);
    agCheck($statuses===[200,409],'concurrent chiefs cannot both remove the last active chief');
    $chiefCount=(int)$db->one("SELECT COUNT(*) n FROM users u JOIN access_groups g ON g.id=u.access_group_id WHERE g.is_super_admin=1 AND u.is_active=1 AND u.approval_status='approved' AND u.email_verified_at IS NOT NULL")['n']; agCheck($chiefCount===1,'one true active chief preserved despite spoofed legacy admin role');
    foreach (['access_group.created','access_group.updated','user.access_group_changed','access_groups.migrated'] as $action) {
        $audit=$db->one('SELECT * FROM audit_log WHERE action=? ORDER BY id DESC LIMIT 1',[$action]); agCheck($audit!==null&&json_decode($audit['details_json'],true)!==null,'structured audit retained for '.$action);
        if ($action!=='access_groups.migrated') { agCheck($audit['actor_id']!==null,'audit actor retained'); }
    }
    $audit=json_encode($db->all('SELECT details_json FROM audit_log'),JSON_THROW_ON_ERROR); agCheck(!str_contains($audit,$password)&&!str_contains($audit,$databasePassword),'audit contains no credentials');
    echo "Protected-chief concurrency, legacy assignment compatibility and audit checks passed.\n";

    proc_terminate($server); proc_close($server); $server=null;
    $freshDatabase=$database.'_fresh'; $adminDb->exec('CREATE DATABASE `'.$freshDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $databases[]=$freshDatabase;
    $adminDb->exec('GRANT ALL PRIVILEGES ON `'.$freshDatabase.'`.* TO '.$principal);
    $freshConfig=$config; $freshConfig['db']['dsn']=str_replace('dbname='.$database.';','dbname='.$freshDatabase.';',$config['db']['dsn']);
    $freshApp=new App($scratch,$freshConfig);
    (new Installer($freshApp))->install($installSecret,['email'=>'fresh-'.$nonce.'@example.test','first_name'=>'مدیر','last_name'=>'نصب تازه','department_name'=>'تازه']);
    $freshUser=$freshApp->db->one('SELECT id,access_group_id FROM users'); agCheck((int)$freshUser['access_group_id']===4,'fresh installer explicitly assigns chief group');
    $freshUser=$freshApp->auth->user((int)$freshUser['id']); agCheck(in_array('access_groups.manage',$freshApp->auth->permissions($freshUser),true)&&$freshUser['is_super_admin'],'fresh install has working group administration');
    agCheck((int)$freshApp->db->one('SELECT COUNT(*) n FROM access_groups')['n']===4&&$freshApp->accessGroups->view(1)['capabilities']===[],'fresh install seeds four groups and personal-only default');
    agCheck($freshApp->db->one('SELECT COUNT(*) n FROM openai_accounts')['n']==0,'fresh user activation does not assign OpenAI accounts/seats');
    echo "Fresh installation DDL and chief initialization checks passed.\n";
    echo "PASS: {$count} isolated access-group checks on MariaDB; temporary HTTP server and independent databases only.\n";
} catch (Throwable $e) {
    $message=str_starts_with($e->getMessage(),'FAIL: ')?$e->getMessage():'Access-group test failed ('.get_class($e).') at line '.$e->getLine();
    fwrite(STDERR,$message.PHP_EOL); $exitCode=1;
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($adminDb) {
        try {
            foreach ($databases as $name) { if (preg_match('/^acc_groups_[a-f0-9]{12}(_fresh)?$/D',$name)) { $adminDb->exec('DROP DATABASE IF EXISTS `'.$name.'`'); } }
            if ($databaseUser&&preg_match('/^ag_[a-f0-9]{12}$/D',$databaseUser)) { $adminDb->exec('DROP USER IF EXISTS '.$adminDb->quote($databaseUser)."@'127.0.0.1'"); }
        } catch (Throwable $e) { fwrite(STDERR,"Temporary test cleanup needs inspection; no primary/QA action attempted.\n"); }
    }
}

if ($exitCode!==0) { exit($exitCode); }
