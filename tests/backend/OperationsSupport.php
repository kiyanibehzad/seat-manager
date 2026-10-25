<?php
declare(strict_types=1);
use AccManager\Operations\{Domain,Capacity,Engine};
final class OpsClient
{
 public string $csrf=''; private CurlHandle $curl;
 public function __construct() { $this->curl=curl_init(); curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20]); }
 public function call(string $method,string $route,?array $data=null,array $query=[]): array {
  curl_setopt_array($this->curl,[CURLOPT_URL=>'http://127.0.0.1:8788/api/index.php?'.http_build_query(['route'=>$route]+$query),CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>null,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$this->csrf]]);
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
 global $app; $db=$app->db; $db->run('SET FOREIGN_KEY_CHECKS=0');
 foreach (['assignment_alert_markers','account_operation_locks','operation_accounts','seat_history','capacity_history','operation_commands','user_bulk_batches','seat_operations','seat_requests','scheduled_reminders','seat_assignments','openai_accounts','seat_capacity','request_reasons','notifications','mail_outbox'] as $table) { $db->pdo->exec('TRUNCATE TABLE '.$table); }
 $db->run('SET FOREIGN_KEY_CHECKS=1'); $db->run('INSERT INTO seat_capacity(id) VALUES(1)');
}
function spawnOp(array $data): array {
 global $root; $pipes=[]; $p=proc_open([PHP_BINARY,'tests/backend/operations-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root); fwrite($pipes[0],json_encode($data,JSON_THROW_ON_ERROR)); fclose($pipes[0]); return [$p,$pipes];
}
function finishOp(array $process): array { [$p,$pipes]=$process; $out=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); return json_decode($out,true,32,JSON_THROW_ON_ERROR); }
