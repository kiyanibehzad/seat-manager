<?php
declare(strict_types=1);
// Targeted serializer/handler regression. No QA/dev reset, no SMTP or cron.
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
date_default_timezone_set('UTC'); umask(0077);
$root=dirname(__DIR__,2); chdir($root); require $root.'/backend/vendor/autoload.php';
use AccManager\{App,Api,ApiError,Migrator,SessionManager};
use AccManager\Operations\{Domain,Requests};
$count=0; $app=null; $scratch=null;
function contractCheck(bool $condition,string $label): void {
    global $count;
    if (!$condition) { throw new RuntimeException('FAIL: '.$label); }
    $count++;
}
try {
    $config=require $root.'/storage/run/test-config.php';
    contractCheck($config['environment']==='test'&&str_contains($config['db']['dsn'],'127.0.0.1;port=33079;dbname=acc_manager_test;'),'source config is isolated local test');
    $config['db']['dsn']=str_replace('dbname=acc_manager_test;','dbname=acc_install_test;',$config['db']['dsn']);
    $config['app_url']='http://contract-check.invalid'; $config['session']['name']='acc_contract_read';
    $schema=new App($root,$config);
    contractCheck($schema->db->one('SELECT DATABASE() n')['n']==='acc_install_test','independent DB guard');
    // Only additive migrations in the separate install-test DB; existing rows are not reset.
    (new Migrator($schema))->run();
    $scratch=$root.'/work/read-contract-'.bin2hex(random_bytes(6));
    foreach (['sessions','logs','run'] as $name) { mkdir($scratch.'/storage/'.$name,0700,true); }
    $app=new App($scratch,$config); $db=$app->db; $db->pdo->beginTransaction();
    $nonce=bin2hex(random_bytes(6)); $hash=password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT);
    $db->run('INSERT INTO departments(name,base_score) VALUES(?,5)',['قرارداد '.$nonce]); $department=(int)$db->pdo->lastInsertId();
    $users=[];
    foreach (['admin','hr','operator','user'] as $role) {
        $db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,app_role,email_verified_at,approval_status) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(),'approved')",[$role.'-'.$nonce.'@example.test',$hash,$role,'قرارداد',$department,$role]);
        $users[$role]=(int)$db->pdo->lastInsertId();
    }
    $db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,organizational_position,special_priority,email_verified_at,approval_status) VALUES(?,?,?,?,?,'supervisor',1,UTC_TIMESTAMP(),'approved')",['supervisor-'.$nonce.'@example.test',$hash,'سرپرست','قرارداد',$department]);
    $users['supervisor']=(int)$db->pdo->lastInsertId();
    $db->run('INSERT INTO users(email,password_hash,first_name,last_name) VALUES(?,?,?,?)',['pending-'.$nonce.'@example.test',$hash,'منتظر','قرارداد']);
    $users['pending']=(int)$db->pdo->lastInsertId();

    $managementRead=function (string $role) use ($app,$users): array {
        $session=new SessionManager($app); $session->login($app->auth->user($users[$role])); session_write_close();
        $_SERVER['REQUEST_METHOD']='GET'; $_GET=['route'=>'/admin/users'];
        try { return (new Api($app))->handle(); }
        finally { if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); } }
    };
    foreach (['admin','hr'] as $actor) {
        [$status,$data]=$managementRead($actor); contractCheck($status===200,'management handler allowed for '.$actor);
        $items=array_column($data['items'],null,'id');
        foreach (['admin','hr','operator','user'] as $role) {
            contractCheck($items[$users[$role]]['score']===5,'no role bonus and integer score for '.$actor.'/'.$role);
        }
        contractCheck($items[$users['supervisor']]['score']===7,'base plus supervisor plus special max seven');
        contractCheck($items[$users['pending']]['score']===null,'unassigned department score is null');
        $json=json_encode($data,JSON_THROW_ON_ERROR);
        contractCheck(!str_contains($json,'password_hash')&&!str_contains($json,'auth_version'),'management response keeps private fields hidden');
    }
    try { $managementRead('operator'); throw new RuntimeException('FAIL: operator unexpectedly listed staff'); }
    catch (ApiError $e) { contractCheck($e->status===403,'operator cannot list staff scores'); }
    $db->run('UPDATE departments SET base_score=2 WHERE id=?',[$department]);
    $items=array_column($app->people->listUsers(),null,'id');
    contractCheck($items[$users['supervisor']]['score']===4&&$items[$users['admin']]['score']===2,'department edits reflected server-side on next read');
    $db->run("UPDATE users SET organizational_position='employee',special_priority=0 WHERE id=?",[$users['supervisor']]);
    $items=array_column($app->people->listUsers(),null,'id'); contractCheck($items[$users['supervisor']]['score']===2,'position and special edits reflected server-side');

    $db->run('INSERT INTO openai_accounts(owner_id,email,alias) VALUES(?,?,?)',[$users['user'],'destination-'.$nonce.'@example.test','حساب من']);
    $destination=(int)$db->pdo->lastInsertId();
    $sourceEmail='private-source-'.$nonce.'@example.test';
    $db->run('INSERT INTO openai_accounts(owner_id,email,alias) VALUES(?,?,?)',[$users['supervisor'],$sourceEmail,'نام محرمانهٔ طرف دیگر']); $source=(int)$db->pdo->lastInsertId();
    $db->run("INSERT INTO seat_assignments(account_id,owner_id,seat,return_seat,assigned_at) VALUES(?,?,'premium','none',UTC_TIMESTAMP())",[$source,$users['supervisor']]);
    $db->run("INSERT INTO seat_requests(owner_id,account_id,kind,requested_seat,expected_state_version,status) VALUES(?,?,'seat','premium',0,'ongoing')",[$users['user'],$destination]); $request=(int)$db->pdo->lastInsertId();
    $db->run("INSERT INTO seat_requests(owner_id,account_id,kind,requested_seat,expected_state_version) VALUES(?,?,'seat','standard',0)",[$users['supervisor'],$source]); $sourceRequest=(int)$db->pdo->lastInsertId();
    $db->run("INSERT INTO seat_operations(request_id,kind,phase,reason,started_by) VALUES(?,'transfer','waiting_invite',?,?)",[$request,'داخلی '.$sourceEmail,$users['operator']]); $operation=(int)$db->pdo->lastInsertId();
    $db->run('INSERT INTO account_operation_locks(account_id,operation_id) VALUES(?,?),(?,?)',[$destination,$operation,$source,$operation]);
    $d=new Domain($app); $service=new Requests($d);
    $personal=$service->publicRequest($service->row($request));
    contractCheck($personal['status']==='ongoing'&&$personal['operation_phase']==='waiting_invite','own request exposes waiting invite without new status');
    contractCheck($personal['eta']['estimated_at']===null&&$personal['eta']['rank']===null&&!$personal['eta']['conditional'],'waiting invite gets no fabricated countdown');
    contractCheck(str_contains($personal['eta']['reason'],'منتظر پذیرش دعوت'),'waiting invite has specific ETA explanation');
    contractCheck(!isset($personal['operation'])&&!isset($personal['operation_id'])&&!isset($personal['account']['operation_id']),'no complete operation leaked in personal serializer');
    $json=json_encode($personal,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    contractCheck(!str_contains($json,$sourceEmail)&&!str_contains($json,'نام محرمانهٔ طرف دیگر'),'no transfer source identity leaked');
    $other=$service->publicRequest($service->row($sourceRequest));
    contractCheck($other['operation_phase']===null,'pending source request does not inherit destination invitation phase');
    $db->run("UPDATE seat_requests SET status='ongoing' WHERE id=?",[$sourceRequest]);
    $other=$service->publicRequest($service->row($sourceRequest));
    contractCheck($other['operation_phase']===null&&!str_contains($other['eta']['reason'],'پذیرش دعوت'),'unlinked operation cannot provide phase even on same locked account');
    foreach (['working'=>'اقدام دستی','partial'=>'بخشی از نتیجه','scheduled'=>'موعد ثبت‌شده'] as $phase=>$phrase) {
        $db->run('UPDATE seat_operations SET phase=? WHERE id=?',[$phase,$operation]);
        $personal=$service->publicRequest($service->row($request));
        contractCheck($personal['operation_phase']===$phase&&str_contains($personal['eta']['reason'],$phrase),'own phase and explanation track '.$phase);
        contractCheck($personal['eta']['estimated_at']===null,'ongoing '.$phase.' has no invented ETA');
    }
    $db->run("UPDATE seat_operations SET status='completed',phase='completed' WHERE id=?",[$operation]);
    $personal=$service->publicRequest($service->row($request)); contractCheck($personal['operation_phase']===null,'closed operation is not presented as ongoing phase');
    $db->run("UPDATE seat_operations SET status='ongoing',phase='waiting_invite' WHERE id=?",[$operation]);
    $db->run("UPDATE seat_requests SET status='completed' WHERE id=?",[$request]);
    $personal=$service->publicRequest($service->row($request)); contractCheck($personal['operation_phase']===null&&str_contains($personal['eta']['reason'],'پایان یافته'),'terminal request has no stale invitation phase');
    $db->pdo->rollBack();
    contractCheck(!$db->one('SELECT id FROM users WHERE email=?',['admin-'.$nonce.'@example.test']),'targeted fixture rows rolled back');
    echo "PASS: {$count} targeted management-score and personal-operation-phase checks on independent MariaDB acc_install_test; no QA/dev reset or cron.\n";
} catch (Throwable $e) {
    if ($app && $app->db->pdo->inTransaction()) { $app->db->pdo->rollBack(); }
    $message=str_starts_with($e->getMessage(),'FAIL: ')?$e->getMessage():'Targeted contract test failed ('.get_class($e).') at line '.$e->getLine();
    fwrite(STDERR,$message.PHP_EOL); exit(1);
} finally {
    if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
}
