<?php
declare(strict_types=1);
// Explicit reset of test DB only. Never writes the human development DB or its credentials.
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1'); umask(0077);
$root=dirname(__DIR__,2); chdir($root); putenv('ACC_CONFIG='.$root.'/storage/run/test-config.php');
require $root.'/backend/vendor/autoload.php';
use AccManager\{App,Migrator,Installer};
use AccManager\Operations\{Domain,Accounts,Requests,Engine,Capacity};
try {
 if (($argv[1]??'')!=='--reset-test-db') { throw new RuntimeException('Explicit test reset required'); }
 $app=App::boot($root); $db=$app->db;
 if ($app->config['environment']!=='test'||$db->one('SELECT DATABASE() n')['n']!=='acc_manager_test') { throw new RuntimeException('Test DB guard'); }
 $db->run('SET FOREIGN_KEY_CHECKS=0'); foreach ($db->all('SHOW TABLES') as $r) { $name=array_values($r)[0]; if (!preg_match('/^[a-z_]+$/',$name)) { throw new RuntimeException('Table guard'); } $db->pdo->exec('DROP TABLE `'.$name.'`'); } $db->run('SET FOREIGN_KEY_CHECKS=1');
 (new Migrator($app))->run();
 (new Installer($app))->install(trim(file_get_contents('storage/run/setup-token.txt')),['email'=>'qa-admin@example.test','first_name'=>'مدیرکل','last_name'=>'آزمایشی','department_name'=>'عمومی']);
 $row=$db->one("SELECT * FROM users WHERE app_role='admin'"); $adminId=(int)$row['id']; $general=(int)$row['department_id'];
 $qaPassword='Local-QA-8788-Only-2026!';
 $access=[['role'=>'admin','email'=>$row['email'],'password'=>$qaPassword]]; $identities=['admin'=>$adminId];
 $db->run("INSERT INTO departments(name,base_score) VALUES('فناوری',5)"); $technical=(int)$db->pdo->lastInsertId();
 foreach ([['operator','اپراتور','حساب‌ها','operator',$general,'employee',false],['hr','منابع','انسانی','hr',$general,'employee',false],['alice','آزاده','آزمایشی','user',$technical,'supervisor',false],['bob','بهرام','آزمایشی','user',$general,'employee',false],['cara','کتایون','آزمایشی','user',$general,'employee',true],['dora','داریوش','آزمایشی','user',$general,'employee',false]] as [$key,$first,$last,$role,$department,$position,$special]) {
  $password=$qaPassword; $email='qa-'.$key.'@example.test';
  $db->run("INSERT INTO users(email,password_hash,first_name,last_name,department_id,organizational_position,special_priority,app_role,email_verified_at,approval_status) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),'approved')",[$email,password_hash($password,PASSWORD_DEFAULT),$first,$last,$department,$position,(int)$special,$role]);
  $identities[$key]=(int)$db->pdo->lastInsertId(); $access[]=['role'=>$key==='operator'||$key==='hr'?$key:'user:'.$key,'email'=>$email,'password'=>$password];
 }
 // Invalidate all pre-reset test sessions even when numeric IDs are reused.
 foreach ($identities as $id) { $db->run('UPDATE users SET auth_version=?,password_hash=? WHERE id=?',[random_int(1000000,2000000000),password_hash($qaPassword,PASSWORD_DEFAULT),$id]); }
 $db->run("UPDATE mail_outbox SET status='cancelled',payload_encrypted=NULL WHERE category='initial_password' AND status='pending'");
 $d=new Domain($app); $accounts=new Accounts($d); $requests=new Requests($d); $engine=new Engine($d); $admin=$app->auth->user($adminId); $operator=$app->auth->user($identities['operator']);
 $key=static fn()=> 'browser-fixture-'.bin2hex(random_bytes(8)); $now=gmdate('Y-m-d\TH:i:s\Z');
 $owned=[];
 foreach ($identities as $name=>$id) {
  $owner=$app->auth->user($id); $owned[$name]=$accounts->create(['email'=>$name.'.openai@example.test','alias'=>'حساب آزمایشی '.$name,'idempotency_key'=>$key()],$owner)['account'];
 }
 $record=function (string $owner,string $seat,?string $start=null,?string $end=null) use ($engine,$admin,$owned,$d,$now,$key): void {
  $a=$d->publicAccount($d->account($owned[$owner]['id']));
  $engine->correct(['account_id'=>$a['id'],'state_version'=>$a['state_version'],'state'=>['seat'=>$seat,'starts_at'=>$start,'ends_at'=>$end],'observed_at'=>$now,'confirmed'=>true,'reason'=>'دادهٔ صرفاً آزمایشی مرورگر','evidence'=>'سناریوی محلی؛ تغییری در OpenAI انجام نشده','idempotency_key'=>$key()],$admin);
 };
 $record('alice','standard');
 $end=(new DateTimeImmutable('tomorrow 23:59:59',new DateTimeZone('Asia/Tehran')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
 $record('alice','premium',gmdate('Y-m-d\TH:i:s\Z',time()-86400),$end);
 $record('bob','standard'); $record('operator','standard'); $record('dora','premium');
 $capacity=new Capacity($d); $capacity->set(['standard'=>5,'premium'=>2,'expected_revision'=>0,'confirmed'=>true,'reason'=>'ظرفیت فرضی محیط مرورگر','evidence'=>'صرفاً تست محلی؛ بدون خرید واقعی','idempotency_key'=>$key()],$admin);
 (new AccManager\Operations\Api($app))->handle('/admin/reasons','POST',['items'=>[['code'=>'project','label'=>'نیاز پروژه'],['code'=>'research','label'=>'بررسی و پژوهش']],'idempotency_key'=>$key()],$admin);
 $alice=$app->auth->user($identities['alice']); $bob=$app->auth->user($identities['bob']); $cara=$app->auth->user($identities['cara']);
 $renewal=$requests->create(['kind'=>'renewal','account_id'=>$owned['alice']['id'],'ends_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+8*86400),'reason_text'=>'برای ادامهٔ پروژه در محیط آزمون','idempotency_key'=>$key()],$alice)['request'];
 $premium=$requests->create(['account_id'=>$owned['bob']['id'],'seat'=>'premium','temporary'=>true,'starts_at'=>$now,'ends_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+5*86400),'reason_code'=>'project','idempotency_key'=>$key()],$bob)['request'];
 $standard=$requests->create(['account_id'=>$owned['cara']['id'],'seat'=>'standard','reason_code'=>'research','idempotency_key'=>$key()],$cara)['request'];
 $direct=$engine->startDirect(['kind'=>'direct','account_id'=>$owned['admin']['id'],'state_version'=>0,'target'=>['seat'=>'standard'],'reason'=>'عملیات آزمایشی منتظر تأیید نتیجه','idempotency_key'=>$key()],$operator)['operation'];
 $scheduled=$engine->startDirect(['kind'=>'scheduled_downgrade','account_id'=>$owned['dora']['id'],'state_version'=>(int)$d->account($owned['dora']['id'])['state_version'],'target'=>['seat'=>'standard'],'effect_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+30*86400),'reason'=>'موعد فرضی دورهٔ بعد در تست','idempotency_key'=>$key()],$operator)['operation'];
 $text="LOCAL TEST DATABASE ONLY — http://127.0.0.1:8788/\nAPI: http://127.0.0.1:8788/api/index.php\nRunning test suites or this fixture reset replaces this database. Development port 8787 is separate.\n\n";
 foreach ($access as $entry) { $text.='Role: '.$entry['role']."\nEmail: ".$entry['email']."\nPassword: ".$entry['password']."\n\n"; }
 file_put_contents('storage/browser-test-access.txt',$text); chmod('storage/browser-test-access.txt',0600);
 $metadata=['database'=>'acc_manager_test','port'=>8788,'users'=>$identities,'accounts'=>array_map(static fn($a)=>$a['id'],$owned),'requests'=>['renewal'=>$renewal['id'],'premium_transfer_candidate'=>$premium['id'],'standard'=>$standard['id']],'operations'=>['ongoing_direct'=>$direct['id'],'scheduled_month_later'=>$scheduled['id']],'capacity'=>$capacity->snapshot()];
 file_put_contents('storage/run/browser-fixture.json',json_encode($metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
 echo "Browser fixture ready on test port 8788. Fictitious QA credentials saved privately with mode 0600. Development DB untouched.\n";
} catch (Throwable $e) { fwrite(STDERR,'Browser fixture failed: '.get_class($e).' at line '.$e->getLine()."; no credentials printed.\n"); exit(1); }
