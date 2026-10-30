<?php
declare(strict_types=1);
use AccManager\Operations\{Domain,Capacity,Engine};
// Optional overrides keep broad regressions away from an occupied QA environment.
// The destructive suites validate both the intended database and loopback URL first.
function testConfigPath(string $root): string {
 $override=getenv('ACC_TEST_CONFIG');
 if ($override===false||$override==='') { return $root.'/storage/run/test-config.php'; }
 $path=realpath($override); $work=realpath($root.'/work');
 if (!$path||!$work||!str_starts_with($path,$work.DIRECTORY_SEPARATOR)||!is_file($path)) { throw new RuntimeException('Independent test config must exist under work/'); }
 return $path;
}
function testBaseUrl(): string { return rtrim((string)(getenv('ACC_TEST_BASE_URL')?:'http://127.0.0.1:8788'),'/'); }
function testSubfolderBaseUrl(): string {
 $override=(bool)getenv('ACC_TEST_CONFIG'); $value=getenv('ACC_TEST_SUBFOLDER_BASE_URL');
 if (!$override) { return rtrim((string)($value?:'http://127.0.0.1:8789/portal'),'/'); }
 $url=$value?parse_url($value):false; $port=(int)($url['port']??80);
 if (!$url||($url['scheme']??'')!=='http'||($url['host']??'')!=='127.0.0.1'||($url['path']??'')!=='/portal'||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||$port<1024||in_array($port,[8787,8788,8789],true)||rtrim((string)$value,'/')===testBaseUrl()) { throw new RuntimeException('Independent subfolder test URL required'); }
 return rtrim((string)$value,'/');
}
function configureTestEnvironment(string $root): void { putenv('ACC_CONFIG='.testConfigPath($root)); }
function testDatabaseGuard(\AccManager\App $app,bool $http=false): void {
 $database=$app->db->one('SELECT DATABASE() n')['n']; $override=(bool)getenv('ACC_TEST_CONFIG'); $base=testBaseUrl(); $url=parse_url($base);
 $expected=$override?preg_match('/^acc_groups_regression_[a-f0-9]{8,24}$/D',(string)$database)===1:$database==='acc_manager_test';
 $port=(int)($url['port']??80);
 if ($app->config['environment']!=='test'||!$expected||!$url||($url['scheme']??'')!=='http'||($url['host']??'')!=='127.0.0.1'||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||rtrim($app->config['app_url'],'/')!==$base||($override&&($port<1024||in_array($port,[8787,8788,8789],true)))) { throw new RuntimeException('Independent test database/URL guard rejected configuration'); }
 if ($override&&!str_contains($app->config['db']['dsn'],'unix_socket=')) { throw new RuntimeException('Independent regression must use local OS-user socket'); }
 if ($http) {
  $curl=curl_init($base.'/api/index.php?route=%2Fsession'); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]); $raw=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); unset($curl); $body=is_string($raw)?json_decode($raw,true):null;
  if ($status!==200||($body['data']['app_url']??null)!==$base) { throw new RuntimeException('Intended isolated HTTP server is unavailable or mismatched'); }
 }
}
// These legacy fixtures deliberately test generated-password email. A fresh real
// installation still defaults delivery OFF; opt in only inside the guarded test DB.
function testPrepareMail(\AccManager\App $app): void {
 testDatabaseGuard($app); $mail=new \AccManager\MailSettings($app);
 if (!$mail->ready()) { return; }
 $settings=$mail->current(); $settings['delivery_enabled']=(bool)$app->config['smtp']['enabled'];
 $app->db->run('UPDATE mail_settings SET settings_encrypted=? WHERE id=1',[$app->crypto->encrypt($settings)]);
}
function testSetupSecret(string $root): string {
 $path=getenv('ACC_TEST_SETUP_TOKEN_PATH');
 if (getenv('ACC_TEST_CONFIG')&&(!$path||!str_starts_with((string)realpath($path),(string)realpath($root.'/work').DIRECTORY_SEPARATOR))) { throw new RuntimeException('Independent setup token must remain under work/'); }
 return trim(file_get_contents($path?:$root.'/storage/run/setup-token.txt'));
}
function testGroupId(\AccManager\App $app,string $role): int {
 $group=$app->db->one('SELECT id FROM access_groups WHERE system_key=?',[$role]);
 if (!$group) { throw new RuntimeException('Seeded test access group missing'); }
 return (int)$group['id'];
}
final class OpsClient
{
 public string $csrf=''; private CurlHandle $curl;
 public function __construct() { $this->curl=curl_init(); curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20]); }
 public function call(string $method,string $route,?array $data=null,array $query=[]): array {
  curl_setopt_array($this->curl,[CURLOPT_URL=>testBaseUrl().'/api/index.php?'.http_build_query(['route'=>$route]+$query),CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>null,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$this->csrf]]);
  if ($method!=='GET') { curl_setopt($this->curl,CURLOPT_POSTFIELDS,json_encode($data??new stdClass(),JSON_THROW_ON_ERROR)); }
  $raw=curl_exec($this->curl); $json=json_decode($raw,true); if (!is_array($json)) { throw new RuntimeException('FAIL: non-JSON HTTP response'); }
  if (isset($json['data']['csrf_token'])) { $this->csrf=$json['data']['csrf_token']; }
  return ['status'=>(int)curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE),'body'=>$json,'raw'=>$raw];
 }
 public function login(string $email,string $password): void { $this->call('GET','/session'); $r=$this->call('POST','/login',['email'=>$email,'password'=>$password]); opCheck($r['status']===200,'fixture login'); }
}
function opCheck(bool $ok,string $label): void { global $assertions; if (!$ok) { throw new RuntimeException('FAIL: '.$label); } $assertions++; }
function keyOp(): string { static $n=0; return 'operation-test-'.(++$n).'-'.bin2hex(random_bytes(4)); }
function mutate(OpsClient $client,string $route,array $data,int $expected=200,string $method='POST'): array {
 if ($route!=='/admin/users/bulk-preview'&&!str_starts_with($route,'/admin/users')&&!str_starts_with($route,'/admin/departments')) { $data['idempotency_key']??=keyOp(); }
 if ($route==='/admin/users/bulk-commit') { $data['idempotency_key']??=keyOp(); }
 $r=$client->call($method,$route,$data); opCheck($r['status']===$expected,$route.' expected '.$expected.', received '.$r['status'].' '.($r['body']['error']['code']??'')); return $r['body']['data']??[];
}
function readOp(OpsClient $client,string $route,array $query=[],int $expected=200): array { $r=$client->call('GET',$route,null,$query); opCheck($r['status']===$expected,'GET '.$route.' HTTP '.$r['status']); return $r['body']['data']??[]; }
function nowOp(int $seconds=0): string { return gmdate('Y-m-d\TH:i:s\Z',time()+$seconds); }
function accountOp(OpsClient $client,string $email): array { return mutate($client,'/accounts',['email'=>$email])['account']; }
function freshOp(int $id): array { global $domain; return $domain->publicAccount($domain->account($id),true); }
function capacityOp(int $extraStd=0,int $extraPrem=0): array {
 global $admin,$domain; $c=(new Capacity($domain))->snapshot();
 return mutate($admin,'/admin/capacity',['standard'=>$c['standard']['assigned']+$c['standard']['held']+$extraStd,'premium'=>$c['premium']['assigned']+$c['premium']['held']+$extraPrem,'expected_revision'=>$c['revision'],'confirmed'=>true,'reason'=>'ظرفیت واقعی آزمون','evidence'=>'بررسی ثبت‌شدهٔ محیط آزمون'])['capacity'];
}
function correctOp(array $account,string $seat,?string $start=null,?string $end=null,?string $return=null): array {
 global $admin; $state=['seat'=>$seat,'starts_at'=>$start,'ends_at'=>$end]; if ($return!==null) { $state['return_seat']=$return; }
 return mutate($admin,'/admin/corrections',['account_id'=>$account['id'],'state_version'=>freshOp($account['id'])['state_version'],'state'=>$state,'observed_at'=>nowOp(),'confirmed'=>true,'reason'=>'اصلاح واقعیت آزمون','evidence'=>'بررسی مستقل وضعیت واقعی'])['operation']['items'][0]['account'];
}
function requestOp(OpsClient $client,array $account,string $seat='premium',?string $start=null,?string $end=null,array $extra=[]): array {
 return mutate($client,'/requests',$extra+['account_id'=>$account['id'],'kind'=>'seat','seat'=>$seat,'temporary'=>$end!==null,'starts_at'=>$start,'ends_at'=>$end])['request'];
}
function startRequestOp(array $request,array $extra=[]): array {
 global $operator; return mutate($operator,'/admin/requests/start',$extra+['request_id'=>$request['id'],'state_version'=>freshOp($request['account']['id'])['state_version'],'reason'=>'شروع رسیدگی آزمون'])['operation'];
}
function resultsOp(array $operation,?array $only=null,bool $before=false): array {
 $out=[]; foreach ($operation['items'] as $item) { $a=freshOp($item['account']['id']); if ($only!==null&&!in_array($a['id'],$only,true)) { continue; } $state=$item[$before?'before':'target']; $out[]=['account_id'=>$a['id'],'owner_id'=>$a['owner_id'],'email'=>$a['email'],'state_version'=>$a['state_version'],'confirmed'=>true,'seat'=>$state['seat'],'starts_at'=>$state['starts_at']??null,'ends_at'=>$state['ends_at']??null,'observed_at'=>nowOp()]; } return $out;
}
function resultOp(array $operation,bool $complete=true,?array $only=null,OpsClient|null $actor=null,array $extra=[]): array {
 global $operator; return mutate($actor??$operator,'/admin/operations/result',$extra+['operation_id'=>$operation['id'],'complete'=>$complete,'results'=>resultsOp($operation,$only),'reason'=>'ثبت نتیجهٔ بررسی‌شده','evidence'=>'تأیید دقیق حساب‌های آزمون']);
}
function directOp(array $account,string $seat,array $extra=[]): array {
 global $operator; return mutate($operator,'/admin/operations/start',$extra+['kind'=>'direct','account_id'=>$account['id'],'state_version'=>freshOp($account['id'])['state_version'],'target'=>['seat'=>$seat],'reason'=>'اقدام مستقیم آزمون'])['operation'];
}
function resetOperations(): void {
 global $app; testDatabaseGuard($app); $db=$app->db; $db->run('SET FOREIGN_KEY_CHECKS=0');
 foreach (['credit_request_events','credit_requests'] as $table) { if ((int)$db->one('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])['n']===1) { $db->pdo->exec('TRUNCATE TABLE '.$table); } }
 foreach (['assignment_alert_markers','account_operation_locks','operation_accounts','seat_history','capacity_history','operation_commands','user_bulk_batches','seat_operations','seat_requests','scheduled_reminders','seat_assignments','openai_accounts','seat_capacity','request_reasons','notifications','mail_outbox'] as $table) { $db->pdo->exec('TRUNCATE TABLE '.$table); }
 $db->run('SET FOREIGN_KEY_CHECKS=1'); $db->run('INSERT INTO seat_capacity(id) VALUES(1)');
}
function spawnOp(array $data): array {
 global $root; $pipes=[]; $p=proc_open([PHP_BINARY,'tests/backend/operations-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root); fwrite($pipes[0],json_encode($data,JSON_THROW_ON_ERROR)); fclose($pipes[0]); return [$p,$pipes];
}
function finishOp(array $process): array { [$p,$pipes]=$process; $out=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); return json_decode($out,true,32,JSON_THROW_ON_ERROR); }
