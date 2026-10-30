<?php
declare(strict_types=1);
namespace AccManager\Operations;
use AccManager\{Input,ApiError};

/** Requests record a manually verified increase to a monthly limit, never actual spend. */
final class Credits
{
    public const MAX_AMOUNT=100000000;
    public function __construct(private Domain $d) {}
    public function ready(): bool
    {
        $db=$this->d->app->db;
        $schema=$db->one("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'");
        if (!(bool)$schema['n']||!$db->one('SELECT version FROM schema_migrations WHERE version=?',['005_credit_requests.sql'])) { return false; }
        return (int)$db->one("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('credit_requests','credit_request_events')")['n']===2;
    }
    public function requireReady(): void
    {
        if (!$this->ready()) { throw new ApiError(503,'migration_required','درخواست اعتبار پس از اجرای مهاجرت هماهنگ‌شده فعال می‌شود.'); }
    }
    public static function amount(array $data,string $field,bool $zero=false): int
    {
        $n=$data[$field]??null;
        if (!is_int($n)||$n<($zero?0:1)||$n>self::MAX_AMOUNT) { Input::invalid($field,'مقدار اعتبار باید عدد صحیح و در محدودهٔ مجاز باشد.'); }
        return $n;
    }
    private static function text(array $data,string $field): string
    {
        $value=$data[$field]??null;
        if (!is_string($value)||!mb_check_encoding($value,'UTF-8')) { Input::invalid($field); }
        $value=trim(str_replace(["\r\n","\r"],"\n",$value));
        if ($value===''||mb_strlen($value)>2000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value)) { Input::invalid($field); }
        return $value;
    }
    public function row(int $id): array { return $this->d->app->db->one('SELECT * FROM credit_requests WHERE id=?',[$id])??$this->d->missing(); }
    public function blockers(array $row,?array $a=null): array
    {
        $a??=$this->d->account((int)$row['account_id']); $out=[];
        if ((int)$a['owner_id']!==(int)$row['owner_id']) { $out[]='ownership_review'; }
        if (!$a['is_active']||$a['approval_status']!=='approved'||$a['email_verified_at']===null) { $out[]='inactive_owner'; }
        if ($a['ownership_status']!=='clear'||($a['assignment_owner_id']!==null&&(int)$a['assignment_owner_id']!==(int)$row['owner_id'])) { $out[]='ownership_review'; }
        if (!in_array($a['seat']??'none',['standard','premium'],true)) { $out[]='unpaid_account'; }
        if ($a['operation_id']!==null) { $out[]='account_locked'; }
        if ($row['status']==='ongoing'&&(int)$a['state_version']!==(int)$row['approved_state_version']) { $out[]='stale_account'; }
        return array_values(array_unique($out));
    }
    private function assertAccount(array $row,array $a): void
    {
        if ($this->blockers($row,$a)) { $this->d->conflict('مالک و سیت فعلی حساب باید دوباره بررسی شوند؛ در صورت تغییر وضعیت، پرونده را با دلیل ببندید و درخواست تازه ثبت کنید.'); }
    }
    public function publicRequest(array $r,bool $admin=false): array
    {
        $a=$this->d->account((int)$r['account_id']);
        // A historical request never exposes a new owner's current account state.
        $account=$this->d->publicAccount($a,$admin);
        if (!$admin&&(int)$a['owner_id']!==(int)$r['owner_id']) { $account=['id'=>(int)$r['account_id'],'owner_id'=>(int)$r['owner_id'],'email'=>$a['email'],'alias'=>null,'seat'=>'none','needs_review'=>true]; }
        $out=['id'=>(int)$r['id'],'kind'=>'credit','unit'=>'credits','owner_id'=>(int)$r['owner_id'],'account'=>$account,'requested_credits'=>(int)$r['requested_credits'],'approved_credits'=>$r['approved_credits']===null?null:(int)$r['approved_credits'],'status'=>$r['status'],'reason_text'=>$r['reason_text'],'rejection_reason'=>$r['rejection_reason'],'limit_before'=>$r['limit_before']===null?null:(int)$r['limit_before'],'limit_after'=>$r['limit_after']===null?null:(int)$r['limit_after'],'score'=>!$admin&&(int)$a['owner_id']!==(int)$r['owner_id']?null:(int)$a['score'],'blockers'=>in_array($r['status'],['pending','ongoing'],true)?$this->blockers($r,$a):[]];
        foreach (['created_at','reviewed_at','completed_at','observed_at','period_ends_at'] as $field) { $out[$field]=Domain::iso($r[$field]); }
        if ($admin) {
            $owner=$this->d->app->db->one("SELECT u.id,u.first_name,u.last_name,(COALESCE(d.base_score,1)+(u.organizational_position='supervisor')+u.special_priority) score FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=?",[$r['owner_id']]);
            $out['score']=(int)$owner['score'];
            $out+=['requester'=>['id'=>(int)$owner['id'],'first_name'=>$owner['first_name'],'last_name'=>$owner['last_name']],'review_note'=>$r['review_note'],'reviewed_by'=>$r['reviewed_by']===null?null:(int)$r['reviewed_by'],'completed_by'=>$r['completed_by']===null?null:(int)$r['completed_by'],'evidence'=>$r['evidence']];
        }
        return $out;
    }
    private function event(array $r,int $actor,string $action,array $before,string $reason='',string $evidence=''): void
    {
        $state=fn($v)=>array_intersect_key($v,array_flip(['status','requested_credits','approved_credits','limit_before','limit_after','observed_at','period_ends_at']));
        $this->d->app->db->run('INSERT INTO credit_request_events(request_id,owner_id,actor_id,action,before_json,after_json,reason,evidence) VALUES(?,?,?,?,?,?,?,?)',[$r['id'],$r['owner_id'],$actor,$action,json_encode($state($before),JSON_THROW_ON_ERROR),json_encode($state($r),JSON_THROW_ON_ERROR),$reason,$evidence]);
        $this->d->app->audit($action,$actor,(int)$r['owner_id'],['before'=>$state($before),'after'=>$state($r)]);
    }
    public function history(int $request,bool $admin=false): array
    {
        $rows=$this->d->app->db->all('SELECT e.*,u.first_name,u.last_name FROM credit_request_events e JOIN users u ON u.id=e.actor_id WHERE e.request_id=? ORDER BY e.id',[$request]);
        return array_map(static function($r)use($admin){
            $v=['id'=>(int)$r['id'],'action'=>$r['action'],'recorded_at'=>Domain::iso($r['recorded_at']),'before'=>json_decode($r['before_json'],true,32,JSON_THROW_ON_ERROR),'after'=>json_decode($r['after_json'],true,32,JSON_THROW_ON_ERROR)];
            if ($admin||in_array($r['action'],['credit.created','credit.cancelled','credit.rejected'],true)) { $v['reason']=$r['reason']; }
            foreach (['before','after'] as $side) { foreach (['observed_at','period_ends_at'] as $f) { if (isset($v[$side][$f])) { $v[$side][$f]=Domain::iso($v[$side][$f]); } } }
            if ($admin) { $v+=['actor_id'=>(int)$r['actor_id'],'actor_name'=>$r['first_name'].' '.$r['last_name'],'evidence'=>$r['evidence']]; }
            return $v;
        },$rows);
    }
    public function create(array $data,array $actor): array
    {
        $this->requireReady(); Input::only($data,['account_id','requested_credits','reason_text','idempotency_key']);
        return $this->d->tx($actor,'personal.request','credit.create',$data,function($fresh)use($data){
            $a=$this->d->account(Input::id($data,'account_id'));
            if ((int)$a['owner_id']!==(int)$fresh['id']) { $this->d->missing(); }
            $this->assertAccount(['owner_id'=>$fresh['id'],'account_id'=>$a['id'],'status'=>'pending'],$a);
            $amount=self::amount($data,'requested_credits'); $reason=self::text($data,'reason_text'); $db=$this->d->app->db;
            if ($db->one("SELECT id FROM credit_requests WHERE account_id=? AND status IN ('pending','ongoing')",[$a['id']])) { $this->d->conflict('یک درخواست باز اعتبار برای همین حساب وجود دارد.'); }
            $db->run('INSERT INTO credit_requests(owner_id,account_id,requested_credits,reason_text) VALUES(?,?,?,?)',[$fresh['id'],$a['id'],$amount,$reason]);
            $r=$this->row((int)$db->pdo->lastInsertId()); $this->event($r,(int)$fresh['id'],'credit.created',[],$reason);
            $this->d->managers('credit-created:'.$r['id'],'credit.created','درخواست اعتبار بیشتر',$a['first_name'].' '.$a['last_name'].' برای حساب '.$a['email'].'، '.$amount.' Credits اعتبار اضافه درخواست کرده است.',$this->d->app->url('view=operations&tab=credits&credit='.$r['id']));
            return ['request'=>$this->publicRequest($r)];
        });
    }
    public function cancel(array $data,array $actor): array
    {
        $this->requireReady(); Input::only($data,['request_id','idempotency_key']);
        return $this->d->tx($actor,'personal.request','credit.cancel',$data,function($fresh)use($data){
            $before=$this->row(Input::id($data,'request_id')); if ((int)$before['owner_id']!==(int)$fresh['id']) { $this->d->missing(); }
            if ($before['status']!=='pending') { $this->d->conflict('فقط درخواست اعتبار منتظر بررسی قابل لغو است.'); }
            $this->d->app->db->run("UPDATE credit_requests SET status='cancelled',completed_at=UTC_TIMESTAMP() WHERE id=?",[$before['id']]);
            $r=$this->row((int)$before['id']); $this->event($r,(int)$fresh['id'],'credit.cancelled',$before,'لغو توسط صاحب درخواست'); return ['request'=>$this->publicRequest($r)];
        });
    }
    public function start(array $data,array $actor): array
    {
        $this->requireReady(); Input::only($data,['request_id','approved_credits','reason','idempotency_key']);
        return $this->d->tx($actor,'seats.manage','credit.start',$data,function($fresh)use($data){
            $before=$this->row(Input::id($data,'request_id')); if ($before['status']!=='pending') { $this->d->conflict('درخواست باید در انتظار بررسی باشد.'); }
            $a=$this->d->account((int)$before['account_id']); $this->assertAccount($before,$a);
            $amount=self::amount($data,'approved_credits'); $reason=isset($data['reason'])&&$data['reason']!==''?self::text($data,'reason'):null;
            $this->d->app->db->run("UPDATE credit_requests SET status='ongoing',approved_credits=?,review_note=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),approved_state_version=? WHERE id=?",[$amount,$reason,$fresh['id'],$a['state_version'],$before['id']]);
            $r=$this->row((int)$before['id']); $this->event($r,(int)$fresh['id'],'credit.started',$before,$reason??'تأیید اولیه برای اقدام دستی');
            $this->d->app->events->notify((int)$r['owner_id'],'credit-started:'.$r['id'],'credit.progress','درخواست اعتبار در حال انجام است','درخواست حساب '.$a['email'].' برای '.$amount.' Credits تأیید اولیه شد؛ افزایش سقف هنوز در انتظار اقدام و بررسی مدیر است.',$this->d->app->url('view=my-requests&tab=credits&credit='.$r['id']));
            return ['request'=>$this->publicRequest($r,true)];
        });
    }
    public function complete(array $data,array $actor): array
    {
        $this->requireReady(); Input::only($data,['request_id','limit_before','limit_after','observed_at','confirmed','evidence','period_ends_at','idempotency_key']);
        return $this->d->tx($actor,'seats.manage','credit.complete',$data,function($fresh)use($data){
            $before=$this->row(Input::id($data,'request_id')); if ($before['status']!=='ongoing') { $this->d->conflict('ابتدا رسیدگی به درخواست اعتبار را شروع کنید.'); }
            $a=$this->d->account((int)$before['account_id']); $this->assertAccount($before,$a);
            if (!Input::boolean($data,'confirmed')) { Input::invalid('confirmed','بررسی افزایش واقعی سقف در OpenAI باید تأیید شود.'); }
            $old=self::amount($data,'limit_before',true); $new=self::amount($data,'limit_after',true);
            if ($new-$old!==(int)$before['approved_credits']) { Input::invalid('limit_after','تفاوت سقف قبلی و جدید باید برابر مقدار اعتبار تأییدشده باشد.'); }
            $observed=Domain::date($data['observed_at']??null,'observed_at');
            if ($observed===null||$observed<($before['reviewed_at']??$before['created_at'])||strtotime($observed.' UTC')>time()+60) { Input::invalid('observed_at','زمان بررسی باید پس از شروع رسیدگی باشد و در آینده نباشد.'); }
            $period=Domain::date($data['period_ends_at']??null,'period_ends_at'); if ($period!==null&&$period<=$observed) { Input::invalid('period_ends_at','پایان دوره باید پس از زمان اقدام باشد.'); }
            $evidence=self::text($data,'evidence');
            $this->d->app->db->run("UPDATE credit_requests SET status='completed',limit_before=?,limit_after=?,observed_at=?,period_ends_at=?,evidence=?,completed_by=?,completed_at=UTC_TIMESTAMP() WHERE id=?",[$old,$new,$observed,$period,$evidence,$fresh['id'],$before['id']]);
            $r=$this->row((int)$before['id']); $this->event($r,(int)$fresh['id'],'credit.completed',$before,'ثبت افزایش واقعی سقف مجاز مصرف',$evidence);
            $this->d->app->events->notify((int)$r['owner_id'],'credit-completed:'.$r['id'],'credit.result','سقف اعتبار ماهانهٔ حساب افزایش یافت','سقف ماهانهٔ حساب '.$a['email'].' از '.$old.' به '.$new.' Credits افزایش یافت. این مقدار، سقف مجاز مصرف است و به معنی مصرف‌شدن اعتبار نیست.',$this->d->app->url('view=my-requests&tab=credits&credit='.$r['id']));
            return ['request'=>$this->publicRequest($r,true)];
        });
    }
    public function reject(array $data,array $actor): array
    {
        $this->requireReady(); Input::only($data,['request_id','reason','idempotency_key']);
        return $this->d->tx($actor,'seats.manage','credit.reject',$data,function($fresh)use($data){
            $before=$this->row(Input::id($data,'request_id')); if (!in_array($before['status'],['pending','ongoing'],true)) { $this->d->conflict('فقط پروندهٔ باز اعتبار قابل رد یا توقف است.'); }
            $reason=self::text($data,'reason');
            $this->d->app->db->run("UPDATE credit_requests SET status='rejected',rejection_reason=?,reviewed_by=?,reviewed_at=COALESCE(reviewed_at,UTC_TIMESTAMP()),completed_by=?,completed_at=UTC_TIMESTAMP() WHERE id=?",[$reason,$fresh['id'],$fresh['id'],$before['id']]);
            $r=$this->row((int)$before['id']); $this->event($r,(int)$fresh['id'],'credit.rejected',$before,$reason);
            $a=$this->d->account((int)$r['account_id']);
            $title=$before['status']==='pending'?'درخواست اعتبار رد شد':'رسیدگی به درخواست اعتبار متوقف شد';
            $body='درخواست '.$r['requested_credits'].' Credits برای حساب '.$a['email'].' تکمیل نشد.';
            if ($before['status']==='ongoing') { $body.=' افزایش سقف تأیید اولیه‌شده: '.$before['approved_credits'].' Credits.'; }
            $body.="\nدلیل: ".$reason;
            $this->d->app->events->notify((int)$r['owner_id'],'credit-rejected:'.$r['id'],'credit.result',$title,$body,$this->d->app->url('view=my-requests&tab=credits&credit='.$r['id']));
            return ['request'=>$this->publicRequest($r,true)];
        });
    }
    public function detail(int $id,array $actor,bool $admin=false): array
    {
        $this->requireReady(); $r=$this->row($id); if (!$admin&&(int)$r['owner_id']!==(int)$actor['id']) { $this->d->missing(); }
        return ['request'=>$this->publicRequest($r,$admin),'history'=>$this->history($id,$admin)];
    }
    public function list(array $query,array $actor,bool $admin=false): array
    {
        $this->requireReady(); $where=['id<?']; $params=[isset($query['before_id'])?Input::id($query,'before_id'):PHP_INT_MAX];
        if (!$admin) { $where[]='owner_id=?'; $params[]=$actor['id']; }
        foreach (['owner_id','account_id'] as $f) { if (isset($query[$f])) { if (!$admin&&$f==='owner_id'&&(int)$query[$f]!== (int)$actor['id']) { $this->d->missing(); } $where[]=$f.'=?'; $params[]=Input::id($query,$f); } }
        if (isset($query['status'])) { if (!in_array($query['status'],['pending','ongoing','completed','rejected','cancelled'],true)) { Input::invalid('status'); } $where[]='status=?'; $params[]=$query['status']; }
        $limit=$admin?min(100,isset($query['limit'])?Input::id($query,'limit'):30):500;
        $rows=$this->d->app->db->all('SELECT * FROM credit_requests WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT '.($limit+1),$params);
        $more=count($rows)>$limit; if ($more) { array_pop($rows); }
        return ['items'=>array_map(fn($r)=>$this->publicRequest($r,$admin),$rows),'next_before_id'=>$more?(int)end($rows)['id']:null];
    }
}
