<?php
declare(strict_types=1);
namespace AccManager\Operations;
use AccManager\Input;
final class Engine
{
    public function __construct(private Domain $d) {}
    private function row(int $id): array { return $this->d->app->db->one('SELECT * FROM seat_operations WHERE id=?',[$id])??$this->d->missing(); }
    private function target(array $account,array $state,bool $renewal=false): array
    {
        $state['return_seat']=($renewal||($account['seat']??'none')===$state['seat'])?($account['return_seat']??'none'):($state['ends_at']===null?'none':($account['seat']??'none'));
        return $state;
    }
    private function begin(array $actor,string $kind,array $targets,string $reason,?int $request=null,string $strategy='reserve',?string $override=null,?string $effect=null): array
    {
        $db=$this->d->app->db;
        $db->run('INSERT INTO seat_operations(request_id,kind,capacity_strategy,reason,override_reason,started_by,effect_at,phase) VALUES(?,?,?,?,?,?,?,?)',[$request,$kind,$strategy,$reason,$override,$actor['id'],$effect,$effect===null?'working':'scheduled']);
        $id=(int)$db->pdo->lastInsertId(); ksort($targets);
        foreach ($targets as $accountId=>$target) {
            $a=$this->d->account((int)$accountId);
            if ($a['operation_id']!==null||$a['ownership_status']!=='clear') { $this->d->conflict('حساب درگیر عملیات یا نیازمند بازبینی است.'); }
            $before=$this->d->snapshot($a);
            $db->run('INSERT INTO operation_accounts(operation_id,account_id,owner_id,before_json,target_json) VALUES(?,?,?,?,?)',[$id,$accountId,$a['owner_id'],json_encode($before,JSON_THROW_ON_ERROR),json_encode($target,JSON_THROW_ON_ERROR)]);
            $db->run('INSERT INTO account_operation_locks(account_id,operation_id) VALUES(?,?)',[$accountId,$id]);
            $this->d->history($a,(int)$actor['id'],'operation.started',$before,$before,$reason,$override??'',null,$id,$request);
        }
        $capacity=new Capacity($this->d); $capacity->holds($id);
        if ($strategy==='reserve' && array_filter($targets,fn($t)=>$t['seat']!=='none')) { $capacity->assertFree(); }
        return $this->view($id);
    }
    public function startRequest(array $data,array $actor): array
    {
        Input::only($data,['request_id','state_version','source_state_version','mode','source_account_id','source_final_seat','approved_ends_at','override_reason','reason','idempotency_key']);
        return $this->d->tx($actor,'requests.manage','operation.start_request',$data,function ($fresh) use ($data) {
            $service=new Requests($this->d); $r=$service->row(Input::id($data,'request_id'));
            if ($r['status']!=='pending') { $this->d->conflict('این درخواست منتظر شروع نیست.'); }
            $account=$this->d->account((int)$r['account_id']); $this->d->version($account,$data); $this->d->availableAccount($account);
            if ($service->blockers($r,$account)) { $this->d->conflict('درخواست یا حساب نیاز به بازبینی دارد.'); }
            $override=isset($data['override_reason'])&&$data['override_reason']!==''?Input::text($data,'override_reason',2000):null;
            (new Queue($this->d))->assertOrder($r,$override);
            $reason=Input::text($data,'reason',2000); $mode=$data['mode']??'reserve'; if (!in_array($mode,['reserve','transfer','convert'],true)) { Input::invalid('mode'); }
            $end=$r['requested_ends_at'];
            if (array_key_exists('approved_ends_at',$data)) {
                if ($r['kind']!=='renewal') { Input::invalid('approved_ends_at'); }
                $end=Domain::date($data['approved_ends_at'],'approved_ends_at');
            }
            if ($r['kind']==='renewal' && ($mode!=='reserve'||$end===null||$end<=$account['ends_at']||strtotime($end.' UTC')<=time())) { Input::invalid('approved_ends_at','پایان مصوب تمدید باید معتبر و از پایان فعلی جلوتر باشد.'); }
            $target=$this->target($account,['seat'=>$r['requested_seat'],'starts_at'=>$r['kind']==='renewal'?$account['starts_at']:$r['requested_starts_at'],'ends_at'=>$end],$r['kind']==='renewal');
            $targets=[(int)$account['id']=>$target]; $kind=$r['kind']==='renewal'?'renewal':'request'; $strategy='reserve';
            if ($mode==='transfer') {
                if ($r['kind']!=='seat'||$r['requested_seat']!=='premium'||!in_array($account['seat']??'none',['none','standard'],true)) { Input::invalid('mode'); }
                $source=$this->d->account(Input::id($data,'source_account_id')); $this->d->version($source,$data,'source_state_version'); $this->d->availableAccount($source,true);
                if ($source['seat']!=='premium'||(int)$source['owner_id']===(int)$account['owner_id']) { $this->d->conflict('مبدأ باید Premium متعلق به فرد دیگری باشد.'); }
                $sourceSeat=$data['source_final_seat']??''; if (!in_array($sourceSeat,['none','standard'],true)) { Input::invalid('source_final_seat'); }
                $targets[(int)$source['id']]=['seat'=>$sourceSeat,'starts_at'=>null,'ends_at'=>null,'return_seat'=>'none']; $kind='transfer';
            } elseif ($mode==='convert') {
                $this->d->app->auth->requirePermission($fresh,'capacity.manage');
                if ($r['kind']!=='seat'||$account['seat']!=='standard'||$r['requested_seat']!=='premium') { Input::invalid('mode'); }
                $kind='paid_conversion'; $strategy='convert';
            } elseif (isset($data['source_account_id'])||isset($data['source_final_seat'])) { Input::invalid('source_account_id'); }
            $operation=$this->begin($fresh,$kind,$targets,$reason,(int)$r['id'],$strategy,$override);
            $this->d->app->db->run("UPDATE seat_requests SET status='ongoing',approved_starts_at=?,approved_ends_at=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?",[$target['starts_at'],$target['ends_at'],$fresh['id'],$r['id']]);
            $this->d->app->events->notify((int)$r['owner_id'],'request-started:'.$operation['id'],'request.review','رسیدگی به درخواست شروع شد','درخواست حساب '.$account['email'].' در حال انجام است؛ تغییر واقعی هنوز ثبت نشده است.',$this->d->app->url('view=my-requests&request='.$r['id']));
            return ['operation'=>$this->view((int)$operation['id'])];
        });
    }
    public function startDirect(array $data,array $actor): array
    {
        Input::only($data,['kind','account_id','state_version','target','effect_at','reason','idempotency_key']);
        return $this->d->tx($actor,'seats.manage','operation.start_direct',$data,function ($fresh) use ($data) {
            $kind=$data['kind']??'direct'; if (!in_array($kind,['direct','return','reclaim','scheduled_downgrade','paid_conversion'],true)) { Input::invalid('kind'); }
            $a=$this->d->account(Input::id($data,'account_id')); $this->d->version($a,$data); $this->d->availableAccount($a,in_array($kind,['return','reclaim'],true));
            if (!isset($data['target'])||!is_array($data['target'])) { Input::invalid('target'); }
            Input::only($data['target'],['seat','starts_at','ends_at']);
            $state=Domain::state($data['target'],false); $strategy='reserve'; $effect=null;
            if ($kind==='return'&&($a['seat']===null||$a['ends_at']===null)) { $this->d->conflict('حساب تخصیص موقت ندارد.'); }
            if ($kind==='reclaim'&&($state['seat']!=='none'||$a['seat']===null)) { Input::invalid('target'); }
            if ($kind==='paid_conversion') {
                $this->d->app->auth->requirePermission($fresh,'capacity.manage');
                if ($a['seat']!=='standard'||$state['seat']!=='premium') { Input::invalid('target'); } $strategy='convert';
            }
            if ($kind==='scheduled_downgrade') {
                if ($a['seat']!=='premium'||$state['seat']!=='standard') { Input::invalid('target'); }
                $effect=Domain::date($data['effect_at']??null,'effect_at'); if ($effect===null||strtotime($effect.' UTC')<=time()) { Input::invalid('effect_at'); } $strategy='deferred';
            } elseif (isset($data['effect_at'])) { Input::invalid('effect_at'); }
            return ['operation'=>$this->begin($fresh,$kind,[(int)$a['id']=>$this->target($a,$state)],Input::text($data,'reason',2000),null,$strategy,null,$effect)];
        });
    }
    public function view(int $id): array
    {
        $row=$this->row($id); $items=[];
        foreach ($this->d->app->db->all('SELECT * FROM operation_accounts WHERE operation_id=? ORDER BY account_id',[$id]) as $item) {
            $a=$this->d->account((int)$item['account_id']); $before=json_decode($item['before_json'],true,16,JSON_THROW_ON_ERROR); $target=json_decode($item['target_json'],true,16,JSON_THROW_ON_ERROR);
            foreach (['starts_at','ends_at','assigned_at'] as $field) { if (isset($before[$field])) { $before[$field]=Domain::iso($before[$field]); } if (isset($target[$field])) { $target[$field]=Domain::iso($target[$field]); } }
            $items[]=['account'=>$this->d->publicAccount($a,true),'before'=>$before,'target'=>$target,'result_recorded'=>(bool)$item['result_recorded']];
        }
        $capacity=(new Capacity($this->d))->snapshot();
        return ['id'=>(int)$row['id'],'request_id'=>$row['request_id']===null?null:(int)$row['request_id'],'kind'=>$row['kind'],'status'=>$row['status'],'phase'=>$row['phase'],'capacity_strategy'=>$row['capacity_strategy'],'capacity_applied'=>(bool)$row['capacity_applied'],'capacity_reversed'=>(bool)$row['capacity_reversed'],'outcome'=>$row['outcome'],'held'=>['standard'=>(int)$row['held_standard'],'premium'=>(int)$row['held_premium']],'effect_at'=>Domain::iso($row['effect_at']),'reason'=>$row['reason'],'override_reason'=>$row['override_reason'],'started_by'=>(int)$row['started_by'],'created_at'=>Domain::iso($row['created_at']),'completed_at'=>Domain::iso($row['completed_at']),'items'=>$items,'reconciliation_required'=>$capacity['reconciliation_required']];
    }
    public function result(array $data,array $actor,bool $rollback=false): array
    {
        Input::only($data,['operation_id','complete','reason','evidence','results','capacity_confirmed','capacity_reversed','finance','idempotency_key']);
        if (!$rollback&&array_key_exists('capacity_reversed',$data)) { Input::invalid('capacity_reversed'); }
        return $this->d->tx($actor,'seats.manage',$rollback?'operation.abort':'operation.result',$data,function ($fresh) use ($data,$rollback) {
            $db=$this->d->app->db; $op=$this->row(Input::id($data,'operation_id')); if ($op['status']!=='ongoing') { $this->d->conflict('عملیات پایان یافته است.'); }
            $complete=Input::boolean($data,'complete'); $reason=Input::text($data,'reason',2000); $evidence=Input::text($data,'evidence',4000);
            $results=$data['results']??null; if (!is_array($results)||!array_is_list($results)||!count($results)||count($results)>2) { Input::invalid('results'); }
            $items=[]; foreach ($db->all('SELECT * FROM operation_accounts WHERE operation_id=? ORDER BY account_id',[$op['id']]) as $item) { $items[(int)$item['account_id']]=$item; }
            if ($complete&&count($results)!==count($items)) { Input::invalid('results','برای تکمیل، تأیید تازهٔ تمام حساب‌ها لازم است.'); }
            $prepared=[];
            foreach ($results as $result) {
                if (!is_array($result)) { Input::invalid('results'); }
                Input::only($result,['account_id','owner_id','email','state_version','confirmed','seat','starts_at','ends_at','observed_at']);
                $id=Input::id($result,'account_id'); if (!isset($items[$id])||isset($prepared[$id])) { Input::invalid('account_id'); }
                $a=$this->d->account($id); $this->d->version($a,$result);
                if ((int)$a['owner_id']!==Input::id($result,'owner_id')||Input::email($result)!==$a['email']||(int)$a['owner_id']!==(int)$items[$id]['owner_id']||($a['ownership_status']!=='clear'||($a['assignment_owner_id']!==null&&(int)$a['assignment_owner_id']!==(int)$a['owner_id']))||(int)$a['operation_id']!==(int)$op['id']) { $this->d->conflict('هویت، مالکیت یا قفل حساب تغییر کرده است.'); }
                if (!Input::boolean($result,'confirmed')) { Input::invalid('confirmed','نتیجهٔ همین حساب باید تأیید شود.'); }
                $state=Domain::state($result); $target=json_decode($items[$id][$rollback?'before_json':'target_json'],true,16,JSON_THROW_ON_ERROR);
                $matches=$state['seat']===$target['seat']&&$state['starts_at']===$target['starts_at']&&$state['ends_at']===$target['ends_at'];
                if ($complete&&!$matches) { $this->d->conflict('نتیجه با هدف مصوب نمی‌خواند؛ ابتدا واقعیت جزئی/اصلاح را ثبت کنید.'); }
                $state['return_seat']=$matches?$target['return_seat']:($state['seat']===($a['seat']??'none')?($a['return_seat']??'none'):($state['ends_at']===null?'none':($a['seat']??'none')));
                $observed=Domain::date($result['observed_at']??null,'observed_at'); if ($observed===null||strtotime($observed.' UTC')>time()+300) { Input::invalid('observed_at','زمان مشاهدهٔ واقعی در آینده نیست.'); }
                $prepared[$id]=[$a,$state,$observed,$matches];
            }
            $capacity=new Capacity($this->d); $capacityConfirmed=isset($data['capacity_confirmed'])?Input::boolean($data,'capacity_confirmed'):false;
            if (isset($data['finance'])||$capacityConfirmed||(!$rollback&&$op['kind']==='paid_conversion'&&!$op['capacity_applied'])) { $this->d->app->auth->requirePermission($fresh,'capacity.manage'); }
            if ($rollback) {
                if ($capacityConfirmed) { Input::invalid('capacity_confirmed'); }
                $reverse=isset($data['capacity_reversed'])?Input::boolean($data,'capacity_reversed'):false;
                if ($reverse) {
                    $this->d->app->auth->requirePermission($fresh,'capacity.manage');
                    if (!$op['capacity_applied']||$op['capacity_reversed']||!in_array($op['kind'],['paid_conversion','scheduled_downgrade'],true)) { Input::invalid('capacity_reversed'); }
                    $delta=$op['kind']==='paid_conversion'?1:-1; $pool=$db->one('SELECT * FROM seat_capacity WHERE id=1');
                    $capacity->record((int)$pool['standard']+$delta,(int)$pool['premium']-$delta,$fresh,$reason,$evidence,$capacity->finance($data['finance']??null),(int)$op['id']);
                    $db->run('UPDATE seat_operations SET capacity_reversed=1 WHERE id=?',[$op['id']]);
                }
            } elseif ($capacityConfirmed&&!$op['capacity_applied']) {
                if (!in_array($op['kind'],['paid_conversion','scheduled_downgrade'],true)||count($items)!==1) { Input::invalid('capacity_confirmed'); }
                [$a,$state]=array_values($prepared)[0]; $before=json_decode(array_values($items)[0]['before_json'],true,16,JSON_THROW_ON_ERROR);
                $delta=$op['kind']==='paid_conversion'?1:-1;
                if ($before['seat']!==($delta===1?'standard':'premium')||$state['seat']!==($delta===1?'premium':'standard')) { Input::invalid('capacity_confirmed'); }
                $pool=$db->one('SELECT * FROM seat_capacity WHERE id=1');
                $capacity->record((int)$pool['standard']-$delta,(int)$pool['premium']+$delta,$fresh,$reason,$evidence,$capacity->finance($data['finance']??null),(int)$op['id']);
                $db->run('UPDATE seat_operations SET capacity_applied=1 WHERE id=?',[$op['id']]);
            } elseif ($op['kind']==='paid_conversion'&&!$op['capacity_applied']) { Input::invalid('capacity_confirmed','ثبت هم‌زمان تبدیل واقعی ظرفیت باید تأیید شود.'); }
            foreach ($prepared as $id=>[$a,$state,$observed,$matches]) {
                $after=$this->d->apply($a,$state,(int)$fresh['id'],$rollback?'operation.rollback_recorded':($complete?'operation.result_confirmed':'operation.partial'),$reason,$evidence,$observed,(int)$op['id'],$op['request_id']===null?null:(int)$op['request_id']);
                $db->run('UPDATE operation_accounts SET result_json=?,result_recorded=1 WHERE operation_id=? AND account_id=?',[json_encode($this->d->snapshot($after),JSON_THROW_ON_ERROR),$op['id'],$id]);
            }
            if ($complete) {
                $db->run("UPDATE seat_operations SET status=?,phase=?,outcome=?,completed_at=UTC_TIMESTAMP(),completed_by=?,held_standard=0,held_premium=0 WHERE id=?",[$rollback?'cancelled':'completed',$rollback?'cancelled':'completed',$rollback?'rolled_back':'target_completed',$fresh['id'],$op['id']]);
                $db->run('DELETE FROM account_operation_locks WHERE operation_id=?',[$op['id']]); $capacity->assertFree();
                if ($op['request_id']!==null) {
                    $r=(new Requests($this->d))->row((int)$op['request_id']);
                    $db->run('UPDATE seat_requests SET status=?,completed_at=? WHERE id=?',[$rollback?'pending':'completed',$rollback?null:gmdate('Y-m-d H:i:s'),$r['id']]);
                    $account=$this->d->account((int)$r['account_id']);
                    $this->d->app->events->notify((int)$r['owner_id'],'request-completed:'.$op['id'],'request.result',$rollback?'عملیات به نتیجه نرسید':'درخواست شما تکمیل شد',$rollback?'عملیات حساب '.$account['email'].' پس از ثبت بازگشت واقعی خاتمه یافت؛ درخواست نیازمند بازبینی است.':'نتیجهٔ واقعی درخواست حساب '.$account['email'].' ثبت و تأیید شد.',$this->d->app->url('view=my-requests&request='.$r['id']));
                }
            } else { $db->run("UPDATE seat_operations SET phase='partial' WHERE id=?",[$op['id']]); $capacity->holds((int)$op['id']); }
            return ['operation'=>$this->view((int)$op['id']),'capacity'=>$capacity->snapshot()];
        });
    }
    public function abort(array $data,array $actor): array { $data['complete']=true; return $this->result($data,$actor,true); }
    public function invitation(array $data,array $actor): array
    {
        Input::only($data,['operation_id','waiting','idempotency_key']);
        return $this->d->tx($actor,'seats.manage','operation.invitation',$data,function ($fresh) use ($data) {
            $op=$this->row(Input::id($data,'operation_id')); if ($op['status']!=='ongoing') { $this->d->conflict(); }
            $partial=$this->d->app->db->one('SELECT account_id FROM operation_accounts WHERE operation_id=? AND result_recorded=1',[$op['id']]);
            if ($partial) { $this->d->conflict('نتیجهٔ جزئی ثبت شده است؛ برچسب ناقص حفظ می‌شود.'); }
            $this->d->app->db->run('UPDATE seat_operations SET phase=? WHERE id=?',[Input::boolean($data,'waiting')?'waiting_invite':'working',$op['id']]);
            $this->d->app->audit('operation.invitation',(int)$fresh['id'],null);
            return ['operation'=>$this->view((int)$op['id'])];
        });
    }
    public function defer(array $data,array $actor): array
    {
        Input::only($data,['operation_id','effect_at','reason','idempotency_key']);
        return $this->d->tx($actor,'seats.manage','operation.defer',$data,function ($fresh) use ($data) {
            $op=$this->row(Input::id($data,'operation_id')); if ($op['status']!=='ongoing') { $this->d->conflict(); }
            if ($this->d->app->db->one('SELECT account_id FROM operation_accounts WHERE operation_id=? AND result_recorded=1',[$op['id']])) { $this->d->conflict('عملیات دارای واقعیت ثبت‌شده قابل تبدیل به زمان‌بندی نیست.'); }
            $effect=Domain::date($data['effect_at']??null,'effect_at'); if ($effect===null||strtotime($effect.' UTC')<=time()) { Input::invalid('effect_at'); }
            $this->d->app->db->run("UPDATE seat_operations SET effect_at=?,phase='scheduled' WHERE id=?",[$effect,$op['id']]);
            foreach ($this->d->app->db->all('SELECT account_id FROM operation_accounts WHERE operation_id=?',[$op['id']]) as $item) { $a=$this->d->account((int)$item['account_id']); $s=$this->d->snapshot($a); $this->d->history($a,(int)$fresh['id'],'operation.deferred',$s,$s,Input::text($data,'reason',2000),'موعد اثر: '.Domain::iso($effect),null,(int)$op['id']); }
            return ['operation'=>$this->view((int)$op['id'])];
        });
    }
    public function cancel(array $data,array $actor): array
    {
        Input::only($data,['operation_id','reason','idempotency_key']);
        return $this->d->tx($actor,'seats.manage','operation.cancel',$data,function ($fresh) use ($data) {
            $op=$this->row(Input::id($data,'operation_id')); if ($op['status']!=='ongoing'||$op['capacity_applied']) { $this->d->conflict(); }
            if ($this->d->app->db->one('SELECT account_id FROM operation_accounts WHERE operation_id=? AND result_recorded=1',[$op['id']])) { $this->d->conflict('عملیات دارای نتیجهٔ واقعی را نمی‌توان بی‌ردپا لغو کرد.'); }
            $reason=Input::text($data,'reason',2000);
            $this->d->app->db->run("UPDATE seat_operations SET status='cancelled',phase='cancelled',outcome='cancelled_before_result',held_standard=0,held_premium=0,completed_by=?,completed_at=UTC_TIMESTAMP() WHERE id=?",[$fresh['id'],$op['id']]);
            $this->d->app->db->run('DELETE FROM account_operation_locks WHERE operation_id=?',[$op['id']]);
            if ($op['request_id']!==null) { $this->d->app->db->run("UPDATE seat_requests SET status='pending',approved_starts_at=NULL,approved_ends_at=NULL WHERE id=?",[$op['request_id']]); }
            foreach ($this->d->app->db->all('SELECT account_id FROM operation_accounts WHERE operation_id=?',[$op['id']]) as $item) { $a=$this->d->account((int)$item['account_id']); $s=$this->d->snapshot($a); $this->d->history($a,(int)$fresh['id'],'operation.cancelled',$s,$s,$reason,'',null,(int)$op['id']); }
            return ['operation'=>$this->view((int)$op['id'])];
        });
    }
    public function correct(array $data,array $actor): array
    {
        Input::only($data,['account_id','state_version','state','observed_at','reason','evidence','confirmed','idempotency_key']);
        return $this->d->tx($actor,'seats.manage','operation.correction',$data,function ($fresh) use ($data) {
            $a=$this->d->account(Input::id($data,'account_id')); $this->d->version($a,$data); $this->d->availableAccount($a,true);
            if (!Input::boolean($data,'confirmed')||!isset($data['state'])||!is_array($data['state'])) { Input::invalid('confirmed'); }
            Input::only($data['state'],['seat','starts_at','ends_at','return_seat']); $state=Domain::state($data['state']); $reason=Input::text($data,'reason',2000); $evidence=Input::text($data,'evidence',4000);
            $observed=Domain::date($data['observed_at']??null,'observed_at'); if ($observed===null||strtotime($observed.' UTC')>time()+300) { Input::invalid('observed_at'); }
            $db=$this->d->app->db; $db->run("INSERT INTO seat_operations(kind,status,phase,outcome,reason,started_by,completed_by,completed_at) VALUES('correction','completed','completed','corrected',?,?,?,UTC_TIMESTAMP())",[$reason,$fresh['id'],$fresh['id']]); $id=(int)$db->pdo->lastInsertId();
            $state['return_seat']??=$state['seat']===($a['seat']??'none')?($a['return_seat']??'none'):($state['ends_at']===null?'none':($a['seat']??'none'));
            $db->run('INSERT INTO operation_accounts(operation_id,account_id,owner_id,before_json,target_json,result_recorded) VALUES(?,?,?,?,?,1)',[$id,$a['id'],$a['owner_id'],json_encode($this->d->snapshot($a),JSON_THROW_ON_ERROR),json_encode($state,JSON_THROW_ON_ERROR)]);
            $after=$this->d->apply($a,$state,(int)$fresh['id'],'state.corrected',$reason,$evidence,$observed,$id);
            $db->run('UPDATE operation_accounts SET result_json=? WHERE operation_id=?',[json_encode($this->d->snapshot($after),JSON_THROW_ON_ERROR),$id]);
            return ['operation'=>$this->view($id),'capacity'=>(new Capacity($this->d))->snapshot()];
        });
    }
    public function list(array $query): array
    {
        $where=['id<?']; $params=[isset($query['before_id'])?Input::id($query,'before_id'):PHP_INT_MAX]; $limit=min(100,isset($query['limit'])?Input::id($query,'limit'):30);
        if (isset($query['status'])) { if (!in_array($query['status'],['ongoing','completed','cancelled'],true)) { Input::invalid('status'); } $where[]='status=?'; $params[]=$query['status']; }
        $rows=$this->d->app->db->all('SELECT id FROM seat_operations WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT '.($limit+1),$params); $more=count($rows)>$limit; if ($more) { array_pop($rows); }
        return ['items'=>array_map(fn($r)=>$this->view((int)$r['id']),$rows),'next_before_id'=>$more?(int)end($rows)['id']:null];
    }
}
