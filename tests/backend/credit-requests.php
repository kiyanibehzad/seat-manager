<?php
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
$root=dirname(__DIR__,2); chdir($root);
require $root.'/backend/vendor/autoload.php'; require __DIR__.'/OperationsSupport.php'; configureTestEnvironment($root);
use AccManager\{App,Migrator,Passwords};
use AccManager\Operations\{Credits,Domain,Summary};
$assertions=0;
function creditFingerprint(App $app,array $tables): array {
 $out=[];
 foreach ($tables as $name) {
  if (!preg_match('/^[a-z_]+$/D',$name)) { throw new RuntimeException('Test table name guard'); }
  $keys=$app->db->all("SHOW KEYS FROM `$name` WHERE Key_name='PRIMARY'"); usort($keys,fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
  $order=implode(',',array_map(fn($k)=>'`'.$k['Column_name'].'`',$keys));
  $out[$name]=hash('sha256',serialize($app->db->all('SELECT * FROM `'.$name.'` ORDER BY '.$order)));
 }
 return $out;
}
function creditRequest(OpsClient $client,int $account,int $amount=500,array $extra=[]): array {
 return mutate($client,'/credit-requests',$extra+['account_id'=>$account,'requested_credits'=>$amount,'reason_text'=>"برای تکمیل کار توسعه به اعتبار بیشتری نیاز دارم.\nاین مقدار برای ادامهٔ بررسی ضروری است."])['request'];
}
function creditWorker(int $actor,int $account): array {
 global $root; $pipes=[];
 $p=proc_open([PHP_BINARY,'tests/backend/credit-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
 fwrite($pipes[0],json_encode(['actor'=>$actor,'data'=>['account_id'=>$account,'requested_credits'=>250,'reason_text'=>'درخواست هم‌زمان آزمون','idempotency_key'=>keyOp()]],JSON_THROW_ON_ERROR)); fclose($pipes[0]); return [$p,$pipes];
}
try {
 $app=App::boot($root); $db=$app->db; testDatabaseGuard($app,true); opCheck(true,'independent test DB and server guard');
 $db->run('SET FOREIGN_KEY_CHECKS=0'); foreach ($db->all('SHOW TABLES') as $row) { $table=array_values($row)[0]; if (!preg_match('/^[a-z_]+$/D',$table)) { throw new RuntimeException('Test reset guard'); } $db->pdo->exec('DROP TABLE `'.$table.'`'); } $db->run('SET FOREIGN_KEY_CHECKS=1');
 $oldRoot=$root.'/work/credit-upgrade-'.bin2hex(random_bytes(5)); mkdir($oldRoot.'/database/migrations',0700,true);
 foreach (glob($root.'/database/migrations/00[1-4]_*.sql') as $file) { copy($file,$oldRoot.'/database/migrations/'.basename($file)); }
 $oldApp=new App($oldRoot,$app->config); opCheck(count((new Migrator($oldApp))->run())===4,'create old004 installation');
 $password=bin2hex(random_bytes(12)); $hash=Passwords::hash($password); $people=[]; $clients=[];
 $db->run("INSERT INTO departments(name,base_score) VALUES('آزمون اعتبار',4)"); $department=(int)$db->pdo->lastInsertId();
 foreach (['admin','operator','hr','one','two'] as $name) {
  $role=in_array($name,['admin','operator','hr'],true)?$name:'user';
  $db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,app_role,access_group_id,email_verified_at,approval_status) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),'approved')",['credit-'.$name.'@example.test',$hash,$name,'اعتبار',$department,$role,testGroupId($app,$role)]);
  $people[$name]=(int)$db->pdo->lastInsertId(); $clients[$name]=new OpsClient(); $clients[$name]->login('credit-'.$name.'@example.test',$password);
 }
 $admin=$clients['admin']; $operator=$clients['operator']; $u1=$clients['one']; $u2=$clients['two']; $hr=$clients['hr']; $domain=new Domain($app);
 $a=accountOp($u1,'credit-one@example.test'); $b=accountOp($u2,'credit-two@example.test'); $none=accountOp($u1,'credit-unassigned@example.test');
 $db->run("INSERT INTO seat_assignments(account_id,owner_id,seat,return_seat,assigned_at,ends_at) VALUES(?,?,'standard','none',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY))",[$a['id'],$people['one']]);
 $db->run("INSERT INTO seat_assignments(account_id,owner_id,seat,return_seat,assigned_at) VALUES(?,?,'premium','standard',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 3 DAY))",[$b['id'],$people['two']]);
 $db->run('UPDATE seat_capacity SET standard=5,premium=5 WHERE id=1');
 $oldApp->mail->enqueue($people['one'],'old-message-before005','credit-one@example.test','پیام محفوظ','بدنهٔ موجود پیش از ارتقا');
 $settings=['transport'=>'smtp','delivery_enabled'=>false,'host'=>'127.0.0.1','port'=>2525,'username'=>'synthetic','password'=>'synthetic-only'];
 $db->run('UPDATE mail_settings SET settings_encrypted=?,revision=7 WHERE id=1',[$oldApp->crypto->encrypt($settings)]);
 $pre=readOp($u1,'/credit-requests',[],503); readOp($u1,'/summary',[],503); readOp($operator,'/admin/credit-requests',[],503);
 readOp($operator,'/admin/dashboard'); readOp($u1,'/accounts'); opCheck(!(new Credits($domain))->ready(),'credit005 readiness fails closed');
 $oldTables=array_map(fn($r)=>array_values($r)[0],$db->all("SHOW TABLES")); $oldTables=array_values(array_diff($oldTables,['schema_migrations']));
 $beforeUpgrade=creditFingerprint($app,$oldTables); $migrationBefore=$db->all('SELECT version,checksum,applied_at FROM schema_migrations ORDER BY version');
 opCheck((new Migrator($app))->run()===['005_credit_requests.sql'],'only additive005 runs on old004');
 opCheck(creditFingerprint($app,$oldTables)===$beforeUpgrade,'all old table rows exact preserved incl hashes/auth/mail/outbox/seats');
 opCheck(array_slice($db->all('SELECT version,checksum,applied_at FROM schema_migrations ORDER BY version'),0,4)===$migrationBefore,'old migration records preserved');
 opCheck((new Migrator($app))->run()===[],'repeat migration idempotent'); opCheck((new Credits($domain))->ready(),'credit005 readiness true');
 $capacityBefore=creditFingerprint($app,['seat_capacity','seat_assignments','capacity_history']);
 readOp(new OpsClient(),'/credit-requests',[],401); readOp($hr,'/admin/credit-requests',[],403); readOp($u1,'/admin/credit-requests',[],403);
 readOp($hr,'/admin/people/summary',['owner_id'=>$people['one']],403); readOp($u2,'/summary',['owner_id'=>$people['one']],404);
 foreach ([0,-1,1.5,'500',100000001] as $invalid) { mutate($u1,'/credit-requests',['account_id'=>$a['id'],'requested_credits'=>$invalid,'reason_text'=>'دلیل معتبر'],422); }
 mutate($u1,'/credit-requests',['account_id'=>$a['id'],'requested_credits'=>500,'reason_text'=>''],422);
 mutate($u1,'/credit-requests',['account_id'=>$a['id'],'requested_credits'=>500,'reason_text'=>str_repeat('ا',2001)],422);
 mutate($u1,'/credit-requests',['account_id'=>$a['id'],'requested_credits'=>500,'reason_text'=>"دلیل\x01نامعتبر"],422);
 mutate($u1,'/credit-requests',['account_id'=>$a['id'],'requested_credits'=>500,'reason_text'=>'دلیل','unexpected'=>true],422);
 mutate($u1,'/credit-requests',['account_id'=>$none['id'],'requested_credits'=>500,'reason_text'=>'بدون سیت'],409);
 mutate($u2,'/credit-requests',['account_id'=>$a['id'],'requested_credits'=>500,'reason_text'=>'حساب دیگری'],404);
 $db->run("INSERT INTO seat_operations(kind,reason,started_by) VALUES('direct','عملیات صوری هم‌زمان آزمون',?)",[$people['operator']]); $lockedOperation=(int)$db->pdo->lastInsertId();
 $db->run('INSERT INTO account_operation_locks(account_id,operation_id) VALUES(?,?)',[$a['id'],$lockedOperation]);
 mutate($u1,'/credit-requests',['account_id'=>$a['id'],'requested_credits'=>500,'reason_text'=>'حساب قفل‌شده'],409);
 $db->run('DELETE FROM account_operation_locks WHERE account_id=?',[$a['id']]);
 $key=keyOp(); $r=creditRequest($u1,$a['id'],500,['idempotency_key'=>$key]);
 opCheck($r['unit']==='credits'&&$r['status']==='pending'&&$r['approved_credits']===null,'positive credit request pending');
 opCheck(str_contains($r['reason_text'],"\n"),'multiline user reason preserved');
 opCheck(creditRequest($u1,$a['id'],500,['idempotency_key'=>$key])['id']===$r['id'],'idempotent create returns same request');
 mutate($u1,'/credit-requests',['account_id'=>$a['id'],'requested_credits'=>501,'reason_text'=>'دلیل دیگر','idempotency_key'=>$key],409);
 mutate($u1,'/credit-requests',['account_id'=>$a['id'],'requested_credits'=>500,'reason_text'=>'درخواست تکراری'],409);
 opCheck((int)$db->one("SELECT COUNT(*) n FROM notifications WHERE event_type='credit.created'")['n']===2,'only chief/operator receive credit event');
 opCheck((int)$db->one("SELECT COUNT(*) n FROM notifications WHERE recipient_id=? AND event_type='credit.created'",[$people['hr']])['n']===0,'HR no credit admin notice');
 $dashboard=readOp($operator,'/admin/dashboard'); opCheck((bool)array_filter($dashboard['today'],fn($v)=>$v['kind']==='credit_request'&&$v['credit_request']['id']===$r['id']),'pending credit in daily desk');
 readOp($u2,'/credit-requests/detail',['id'=>$r['id']],404); mutate($u2,'/credit-requests/cancel',['request_id'=>$r['id']],404);
 mutate($u1,'/admin/credit-requests/start',['request_id'=>$r['id'],'approved_credits'=>300],403);
 $started=mutate($operator,'/admin/credit-requests/start',['request_id'=>$r['id'],'approved_credits'=>300,'reason'=>"مقدار متناسب با نیاز\nیادداشت داخلی مدیر"])['request'];
 opCheck($started['status']==='ongoing'&&$started['approved_credits']===300,'admin adjusts requested500 to approved300');
 $ownStarted=readOp($u1,'/credit-requests/detail',['id'=>$r['id']]);
 opCheck(!array_key_exists('review_note',$ownStarted['request'])&&!array_key_exists('requester',$ownStarted['request'])&&!array_key_exists('actor_name',$ownStarted['history'][1])&&!array_key_exists('reason',$ownStarted['history'][1]),'personal history omits internal approval note and manager identity');
 opCheck($started['requester']['id']===$people['one']&&str_contains($started['review_note'],'یادداشت داخلی مدیر'),'admin receives original requester and internal approval note');
 mutate($u1,'/credit-requests/cancel',['request_id'=>$r['id']],409);
 $complete=['request_id'=>$r['id'],'limit_before'=>1000,'limit_after'=>1300,'observed_at'=>nowOp(),'confirmed'=>true,'evidence'=>"سقف همین ایمیل در پنل OpenAI بررسی شد.\nمقدار جدید و امکان استفاده تأیید شد."];
 $db->run('INSERT INTO account_operation_locks(account_id,operation_id) VALUES(?,?)',[$a['id'],$lockedOperation]);
 mutate($operator,'/admin/credit-requests/complete',$complete,409);
 $db->run('DELETE FROM account_operation_locks WHERE account_id=?',[$a['id']]); $db->run('DELETE FROM seat_operations WHERE id=?',[$lockedOperation]);
 mutate($operator,'/admin/credit-requests/complete',array_replace($complete,['confirmed'=>false]),422);
 mutate($operator,'/admin/credit-requests/complete',array_replace($complete,['limit_after'=>1500]),422);
 mutate($operator,'/admin/credit-requests/complete',array_replace($complete,['observed_at'=>nowOp(500)]),422);
 mutate($operator,'/admin/credit-requests/complete',array_replace($complete,['observed_at'=>'2000-01-01T00:00:00Z']),422);
 mutate($operator,'/admin/credit-requests/complete',array_replace($complete,['evidence'=>'']),422);
 mutate($operator,'/admin/credit-requests/complete',array_replace($complete,['evidence'=>str_repeat('ا',2001)]),422);
 mutate($operator,'/admin/credit-requests/complete',array_replace($complete,['period_ends_at'=>'2000-01-01T00:00:00Z']),422);
 $done=mutate($operator,'/admin/credit-requests/complete',$complete+['idempotency_key'=>$key=keyOp()])['request'];
 opCheck($done['status']==='completed'&&$done['limit_after']===1300,'verified actual limit increase completed');
 opCheck(str_contains($done['evidence'],"\n"),'multiline result evidence preserved');
 opCheck(mutate($operator,'/admin/credit-requests/complete',$complete+['idempotency_key'=>$key])['request']['id']===$r['id'],'completion idempotent');
 opCheck(count(readOp($u1,'/credit-requests/detail',['id'=>$r['id']])['history'])===3,'append-only create start complete timeline');
 opCheck(!array_key_exists('evidence',readOp($u1,'/credit-requests/detail',['id'=>$r['id']])['request']),'internal evidence absent own public view');
 mutate($operator,'/admin/credit-requests/reject',['request_id'=>$r['id'],'reason'=>'پس از تکمیل'],409);
 $r2=creditRequest($u1,$a['id'],100); mutate($u1,'/credit-requests/cancel',['request_id'=>$r2['id']]);
 $r3=creditRequest($u1,$a['id'],200); mutate($operator,'/admin/credit-requests/reject',['request_id'=>$r3['id'],'reason'=>''],422); mutate($operator,'/admin/credit-requests/reject',['request_id'=>$r3['id'],'reason'=>'در این دوره تأیید نشد']);
 $notice=$db->one("SELECT title,body FROM notifications WHERE recipient_id=? AND event_type='credit.result' ORDER BY id DESC LIMIT 1",[$people['one']]);
 opCheck($notice['title']==='درخواست اعتبار رد شد'&&str_contains($notice['body'],'200 Credits'),'pending rejection notice identifies outcome and requested amount');
 $r4=creditRequest($u1,$a['id'],50); mutate($operator,'/admin/credit-requests/start',['request_id'=>$r4['id'],'approved_credits'=>50]);
 $db->run('UPDATE openai_accounts SET state_version=state_version+1 WHERE id=?',[$a['id']]);
 mutate($operator,'/admin/credit-requests/complete',array_replace($complete,['request_id'=>$r4['id'],'limit_after'=>1050,'observed_at'=>nowOp()]),409);
 mutate($operator,'/admin/credit-requests/reject',['request_id'=>$r4['id'],'reason'=>'وضعیت حساب تغییر کرده و اقدامی ثبت نشده است']);
 $notice=$db->one("SELECT title,body FROM notifications WHERE recipient_id=? AND event_type='credit.result' ORDER BY id DESC LIMIT 1",[$people['one']]);
 opCheck($notice['title']==='رسیدگی به درخواست اعتبار متوقف شد'&&str_contains($notice['body'],'تأیید اولیه‌شده: 50 Credits'),'ongoing rejection notice distinguishes stopped approval');
 $r5=creditRequest($u2,$b['id'],50); mutate($operator,'/admin/credit-requests/start',['request_id'=>$r5['id'],'approved_credits'=>50]);
 $db->run('UPDATE users SET is_active=0 WHERE id=?',[$people['two']]);
 mutate($operator,'/admin/credit-requests/complete',array_replace($complete,['request_id'=>$r5['id'],'limit_after'=>1050,'observed_at'=>nowOp()]),409);
 mutate($operator,'/admin/credit-requests/reject',['request_id'=>$r5['id'],'reason'=>'مالک غیرفعال شده است']); $db->run('UPDATE users SET is_active=1 WHERE id=?',[$people['two']]);
 $race1=creditWorker($people['one'],$a['id']); $race2=creditWorker($people['one'],$a['id']); $race=[finishOp($race1),finishOp($race2)];
 $codes=array_column($race,'status'); sort($codes); opCheck($codes===[200,409],'concurrent duplicates allow exactly one open request');
 $raceRequest=$db->one("SELECT id FROM credit_requests WHERE account_id=? AND status='pending'",[$a['id']]); mutate($u1,'/credit-requests/cancel',['request_id'=>(int)$raceRequest['id']]);
 opCheck(creditFingerprint($app,['seat_capacity','seat_assignments','capacity_history'])===$capacityBefore,'credit actions never alter seat assignments capacity or finance');
 $summary=readOp($u1,'/summary');
 opCheck($summary['owner_id']===$people['one']&&$summary['credits']['completed_credits']===300&&$summary['credits']['actual_consumption']===null,'summary completed300 distinct from requested/actual spend');
 opCheck($summary['credits']['request_count']===5&&$summary['credits']['requested_credits']===1100,'summary counts each original request once');
 opCheck($summary['seat_totals']['standard_seconds']>9*86400,'planned expired date does not end actual assignment');
 $range=readOp($operator,'/admin/people/summary',['owner_id'=>$people['one'],'from'=>nowOp(-3600),'to'=>nowOp(86400)]);
 opCheck(abs($range['seat_totals']['standard_seconds']-3600)<5,'date range clips seat duration and clamps future end');
 opCheck($range['seat_totals']['premium_seconds']===0,'other owner paid time excluded');
 readOp($operator,'/admin/people/summary',['owner_id'=>$people['one'],'from'=>nowOp(),'to'=>nowOp(-86400)],422);
 // Another simultaneous owned account contributes account-days, not elapsed person-days.
 $second=accountOp($u1,'credit-simultaneous@example.test');
 $db->run("INSERT INTO seat_assignments(account_id,owner_id,seat,return_seat,assigned_at) VALUES(?,?,'premium','none',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY))",[$second['id'],$people['one']]);
 $sum=(new Summary($domain))->view(['from'=>nowOp(-86400),'to'=>nowOp()],$app->auth->user($people['one']));
 opCheck(abs($sum['seat_totals']['account_days']-2)<0.001,'two simultaneous account-days not collapsed to one person-day');
 // Backdated conflicting history is flagged and ambiguous time is excluded from both seat totals.
 $db->run("INSERT INTO seat_assignments(account_id,owner_id,seat,return_seat,assigned_at,closed_at) VALUES(?,?,'premium','none',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR))",[$a['id'],$people['one']]);
 $sum=(new Summary($domain))->view(['from'=>nowOp(-10800),'to'=>nowOp()],$app->auth->user($people['one'])); $one=array_values(array_filter($sum['accounts'],fn($v)=>$v['id']===$a['id']))[0];
 opCheck($one['history_incomplete']&&abs($one['ambiguous_seconds']-3600)<5&&$one['premium_seconds']===0,'ambiguous different-seat hour explicitly excluded');
 // A completed request created before the range still contributes its completion amount.
 $db->run('UPDATE credit_requests SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 3 DAY) WHERE id=?',[$r['id']]);
 $sum=readOp($u1,'/summary',['from'=>nowOp(-86400),'to'=>nowOp()]);
 opCheck($sum['credits']['completed_credits']===300&&$sum['credits']['request_count']===4,'request dates and completion dates separated');
 // Fresh custom permission revocation is checked at mutation time, not only on initial display.
 $blocked=creditRequest($u1,$a['id'],20); $db->run('UPDATE users SET access_group_id=? WHERE id=?',[testGroupId($app,'hr'),$people['operator']]);
 mutate($operator,'/admin/credit-requests/start',['request_id'=>$blocked['id'],'approved_credits'=>20],403);
 $db->run('UPDATE users SET access_group_id=? WHERE id=?',[testGroupId($app,'operator'),$people['operator']]);
 opCheck((int)$db->one('SELECT COUNT(*) n FROM credit_request_events WHERE request_id=?',[$blocked['id']])['n']===1,'permission failure does not append approval');
 // Historical requests retain their requester, without exposing another owner's current alias/state.
 $db->run('UPDATE openai_accounts SET owner_id=?,alias=? WHERE id=?',[$people['two'],'نام خصوصی مالک جدید',$a['id']]);
 $former=readOp($u1,'/credit-requests/detail',['id'=>$r['id']]); $adminFormer=readOp($operator,'/admin/credit-requests/detail',['id'=>$r['id']]);
 opCheck($former['request']['account']['owner_id']===$people['one']&&$former['request']['account']['alias']===null&&!isset($former['request']['account']['state_version'])&&$former['request']['score']===null,'former owner receives redacted historical account');
 opCheck($adminFormer['request']['requester']['id']===$people['one']&&$adminFormer['request']['account']['owner_id']===$people['two'],'admin distinguishes original requester and current owner');
 $formerSummary=readOp($u1,'/summary'); $formerAccount=array_values(array_filter($formerSummary['accounts'],fn($v)=>$v['id']===$a['id']))[0];
 opCheck($formerAccount['alias']===null,'summary omits new owner alias from historical account');
 $db->run('UPDATE openai_accounts SET owner_id=?,alias=NULL WHERE id=?',[$people['one'],$a['id']]);
 echo json_encode(['checks'=>$assertions,'passed'=>true,'synthetic_only'=>true,'upgrade_tables_preserved'=>count($oldTables),'migration'=>'004→005','no_transport_send'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
} catch (Throwable $e) { fwrite(STDERR,'Credit integration failed: '.$e->getMessage()."\n"); exit(1); }
