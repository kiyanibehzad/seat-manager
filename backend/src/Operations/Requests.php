<?php
declare(strict_types=1);
namespace AccManager\Operations;
use AccManager\{Input,ApiError};
final class Requests
{
    public function __construct(public Domain $d) {}
    public function row(int $id): array { return $this->d->app->db->one('SELECT * FROM seat_requests WHERE id=?',[$id])??$this->d->missing(); }
    public function blockers(array $row,?array $account=null): array
    {
        $account??=$this->d->account((int)$row['account_id']); $out=[];
        if (!$account['is_active']||$account['approval_status']!=='approved') { $out[]='inactive_owner'; }
        if ($account['ownership_status']!=='clear'||($account['assignment_owner_id']!==null&&(int)$account['assignment_owner_id']!==(int)$account['owner_id'])) { $out[]='ownership_review'; }
        if ((int)$account['state_version']!==(int)$row['expected_state_version']) { $out[]='stale_account'; }
        if ($row['kind']==='renewal' && ((int)($account['assignment_id']??0)!==(int)$row['assignment_id']||(int)($account['assignment_version']??0)!==(int)$row['assignment_version']||$account['seat']!=='premium'||$account['ends_at']===null)) { $out[]='stale_assignment'; }
        if ($row['kind']==='seat' && ($account['seat']??'none')===$row['requested_seat']) { $out[]='same_seat'; }
        if ($row['kind']==='seat' && $row['requested_ends_at']!==null && Domain::iranDay($row['requested_ends_at'])<Domain::iranDay()) { $out[]='expired_window'; }
        if ($account['operation_id']!==null && $row['status']==='pending') { $out[]='account_locked'; }
        return array_values(array_unique($out));
    }
    public function publicRequest(array $row,bool $admin=false,?array $eta=null): array
    {
        $account=$this->d->account((int)$row['account_id']);
        // Follow this request's operation, never another operation locking its account.
        $phase=null;
        if ($row['status']==='ongoing') {
            $phase=$this->d->app->db->one("SELECT phase FROM seat_operations WHERE request_id=? AND status='ongoing' ORDER BY id DESC LIMIT 1",[$row['id']])['phase']??null;
            $reason=match ($phase) {
                'waiting_invite'=>'منتظر پذیرش دعوت و بررسی عضویت و سیت همین حساب در OpenAI توسط مدیر است.',
                'partial'=>'بخشی از نتیجهٔ واقعی ثبت شده است؛ عملیات هنوز به بررسی و تأیید نهایی نیاز دارد.',
                'scheduled'=>'تغییر برای موعد ثبت‌شده برنامه‌ریزی شده است؛ وضعیت واقعی تا تأیید نتیجه حفظ می‌شود.',
                default=>'در انتظار ثبت و تأیید نتیجهٔ اقدام دستی مدیر است.',
            };
            $eta=['rank'=>null,'estimated_at'=>null,'conditional'=>false,'reason'=>$reason];
        }
        $out=['id'=>(int)$row['id'],'owner_id'=>(int)$row['owner_id'],'account'=>$this->d->publicAccount($account,$admin),'kind'=>$row['kind'],'requested_seat'=>$row['requested_seat'],'temporary'=>(bool)$row['temporary'],'status'=>$row['status'],'operation_phase'=>$phase,'reason_code'=>$row['reason_code'],'reason_text'=>$row['reason_text'],'reason_label'=>$row['reason_label'],'rejection_reason'=>$row['rejection_reason'],'expected_state_version'=>(int)$row['expected_state_version'],'assignment_id'=>$row['assignment_id']===null?null:(int)$row['assignment_id'],'assignment_version'=>$row['assignment_version']===null?null:(int)$row['assignment_version'],'score'=>(int)$account['score'],'blockers'=>$row['status']==='pending'?$this->blockers($row,$account):[],'eta'=>$eta??['rank'=>null,'estimated_at'=>null,'conditional'=>false,'reason'=>in_array($row['status'],['completed','rejected','cancelled'],true)?'پرونده پایان یافته است.':($row['kind']==='renewal'?'منتظر تصمیم دربارهٔ تمدید است.':'در انتظار اقدام دستی مدیر است.')]];
        foreach (['requested_starts_at','requested_ends_at','approved_starts_at','approved_ends_at','created_at','reviewed_at','completed_at'] as $field) { $out[$field]=Domain::iso($row[$field]); }
        return $out;
    }
    public function create(array $data,array $actor): array
    {
        Input::only($data,['account_id','kind','seat','temporary','starts_at','ends_at','reason_code','reason_text','idempotency_key']);
        return $this->d->tx($actor,'personal.request','request.create',$data,function ($fresh) use ($data) {
            $account=$this->d->account(Input::id($data,'account_id')); if ((int)$account['owner_id']!==(int)$fresh['id']) { $this->d->missing(); }
            if ($account['ownership_status']!=='clear'||($account['assignment_owner_id']!==null&&(int)$account['assignment_owner_id']!==(int)$account['owner_id'])) { $this->d->conflict('مالکیت حساب نیاز به بررسی دارد.'); }
            $kind=$data['kind']??'seat'; if (!in_array($kind,['seat','renewal'],true)) { Input::invalid('kind'); }
            $temporary=isset($data['temporary'])?Input::boolean($data,'temporary'):false;
            $assignment=null; $version=null;
            if ($kind==='renewal') {
                if ($account['seat']!=='premium'||$account['ends_at']===null||strtotime($account['ends_at'].' UTC')<time()) { $this->d->conflict('تمدید فقط برای Premium موقت فعال قابل ثبت است.'); }
                if (isset($data['seat'])&&$data['seat']!=='premium') { Input::invalid('seat'); }
                if (isset($data['temporary'])&&!$data['temporary']) { Input::invalid('temporary'); }
                $state=Domain::state(['seat'=>'premium','starts_at'=>Domain::iso($account['starts_at']),'ends_at'=>$data['ends_at']??null]); $temporary=true;
                if ($state['ends_at']===null||$state['ends_at']<=$account['ends_at']||strtotime($state['ends_at'].' UTC')<=time()) { Input::invalid('ends_at','پایان تمدید باید از پایان فعلی و زمان جاری جلوتر باشد.'); }
                if (isset($data['starts_at']) && Domain::date($data['starts_at'],'starts_at')!==$account['starts_at']) { Input::invalid('starts_at'); }
                $assignment=(int)$account['assignment_id']; $version=(int)$account['assignment_version'];
            } else {
                $state=Domain::state(['seat'=>$data['seat']??'standard','starts_at'=>$data['starts_at']??null,'ends_at'=>$data['ends_at']??null],false);
                if ($state['seat']==='none') { Input::invalid('seat'); }
                if ($temporary!==($state['ends_at']!==null)) { Input::invalid('temporary','حالت موقت باید با بازهٔ تاریخ هماهنگ باشد.'); }
                if (($account['seat']??'none')===$state['seat']) { $this->d->conflict('برای همین نوع سیت از تمدید Premium یا اصلاح توسط مدیر استفاده کنید.'); }
            }
            $reasonText=isset($data['reason_text'])&&$data['reason_text']!==''?Input::text($data,'reason_text',2000):null;
            if ($kind==='renewal'&&$reasonText===null) { Input::invalid('reason_text','دلیل تمدید الزامی است.'); }
            $code=$data['reason_code']??($reasonText!==null?'other':null); $label=null;
            if ($code!==null) {
                if (!is_string($code)) { Input::invalid('reason_code'); }
                if ($code==='other') { if ($reasonText===null) { Input::invalid('reason_text'); } $label='سایر'; }
                else { $reason=$this->d->app->db->one('SELECT label FROM request_reasons WHERE code=? AND enabled=1',[$code]); if (!$reason) { Input::invalid('reason_code'); } $label=$reason['label']; }
            }
            $db=$this->d->app->db;
            if ($db->one("SELECT id FROM seat_requests WHERE account_id=? AND kind=? AND status IN ('pending','ongoing')",[$account['id'],$kind])) { $this->d->conflict('درخواست باز برای همین حساب وجود دارد.'); }
            $db->run('INSERT INTO seat_requests(owner_id,account_id,kind,requested_seat,temporary,requested_starts_at,requested_ends_at,expected_state_version,assignment_id,assignment_version,reason_code,reason_label,reason_text) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$fresh['id'],$account['id'],$kind,$state['seat'],(int)$temporary,$state['starts_at'],$state['ends_at'],$account['state_version'],$assignment,$version,$code,$label,$reasonText]);
            $id=(int)$db->pdo->lastInsertId(); $this->d->history($account,(int)$fresh['id'],'request.created',$this->d->snapshot($account),$this->d->snapshot($account),$reasonText??'','',null,null,$id);
            $this->d->managers('request-created:'.$id,'request.created',$kind==='renewal'?'درخواست تمدید تازه':'درخواست سیت تازه',$account['first_name'].' '.$account['last_name'].' — '.$account['email'],$this->d->app->url('view=operations&request='.$id));
            return ['request'=>$this->publicRequest($this->row($id))];
        });
    }
    public function cancel(array $data,array $actor): array
    {
        Input::only($data,['request_id','idempotency_key']);
        return $this->d->tx($actor,'personal.request','request.cancel',$data,function ($fresh) use ($data) {
            $r=$this->row(Input::id($data,'request_id')); if ((int)$r['owner_id']!==(int)$fresh['id']) { $this->d->missing(); }
            if ($r['status']!=='pending') { $this->d->conflict('فقط درخواست منتظر بررسی قابل لغو است.'); }
            $this->d->app->db->run("UPDATE seat_requests SET status='cancelled',completed_at=UTC_TIMESTAMP() WHERE id=?",[$r['id']]);
            $a=$this->d->account((int)$r['account_id']); $s=$this->d->snapshot($a); $this->d->history($a,(int)$fresh['id'],'request.cancelled',$s,$s,'لغو توسط صاحب درخواست','',null,null,(int)$r['id']);
            return ['request'=>$this->publicRequest($this->row((int)$r['id']))];
        });
    }
    public function reject(array $data,array $actor): array
    {
        Input::only($data,['request_id','reason','idempotency_key']);
        return $this->d->tx($actor,'requests.manage','request.reject',$data,function ($fresh) use ($data) {
            $r=$this->row(Input::id($data,'request_id')); $reason=Input::text($data,'reason',2000); if ($r['status']!=='pending') { $this->d->conflict(); }
            $this->d->app->db->run("UPDATE seat_requests SET status='rejected',rejection_reason=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP() WHERE id=?",[$reason,$fresh['id'],$r['id']]);
            $a=$this->d->account((int)$r['account_id']); $s=$this->d->snapshot($a); $this->d->history($a,(int)$fresh['id'],'request.rejected',$s,$s,$reason,'',null,null,(int)$r['id']);
            $this->d->app->events->notify((int)$r['owner_id'],'request-rejected:'.$r['id'],'request.result','نتیجهٔ درخواست شما','درخواست حساب '.$a['email'].' تأیید نشد. '.$reason,$this->d->app->url('view=my-requests&request='.$r['id']));
            return ['request'=>$this->publicRequest($this->row((int)$r['id']),true)];
        });
    }
    public function revalidate(array $data,array $actor): array
    {
        Input::only($data,['request_id','state_version','reason','idempotency_key']);
        return $this->d->tx($actor,'requests.manage','request.revalidate',$data,function ($fresh) use ($data) {
            $r=$this->row(Input::id($data,'request_id')); if ($r['status']!=='pending') { $this->d->conflict(); }
            $a=$this->d->account((int)$r['account_id']); $this->d->version($a,$data); $this->d->availableAccount($a);
            if ($r['kind']==='renewal'&&($a['seat']!=='premium'||$a['ends_at']===null||$r['requested_ends_at']<=$a['ends_at'])) { $this->d->conflict('درخواست تمدید با تخصیص فعلی سازگار نیست؛ درخواست تازه لازم است.'); }
            if ($r['kind']==='seat'&&$r['requested_ends_at']!==null&&Domain::iranDay($r['requested_ends_at'])<Domain::iranDay()) { $this->d->conflict('بازهٔ درخواستی پایان یافته است؛ درخواست تازه لازم است.'); }
            $this->d->app->db->run('UPDATE seat_requests SET expected_state_version=?,assignment_id=?,assignment_version=? WHERE id=?',[$a['state_version'],$r['kind']==='renewal'?$a['assignment_id']:null,$r['kind']==='renewal'?$a['assignment_version']:null,$r['id']]);
            $s=$this->d->snapshot($a); $this->d->history($a,(int)$fresh['id'],'request.revalidated',$s,$s,Input::text($data,'reason',2000),'',null,null,(int)$r['id']);
            return ['request'=>$this->publicRequest($this->row((int)$r['id']),true)];
        });
    }
    public function adminList(array $query): array
    {
        $where=['id<?']; $params=[isset($query['before_id'])?Input::id($query,'before_id'):PHP_INT_MAX]; $limit=min(100,isset($query['limit'])?Input::id($query,'limit'):30);
        if (isset($query['status'])) { if (!in_array($query['status'],['pending','ongoing','completed','rejected','cancelled'],true)) { Input::invalid('status'); } $where[]='status=?'; $params[]=$query['status']; }
        foreach (['owner_id','account_id'] as $key) { if (isset($query[$key])) { $where[]=$key.'=?'; $params[]=Input::id($query,$key); } }
        $rows=$this->d->app->db->all('SELECT * FROM seat_requests WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT '.($limit+1),$params); $more=count($rows)>$limit; if ($more) { array_pop($rows); }
        $etas=(new Queue($this->d))->estimates();
        return ['items'=>array_map(fn($r)=>$this->publicRequest($r,true,$etas[(int)$r['id']]??null),$rows),'next_before_id'=>$more?(int)end($rows)['id']:null];
    }
    public function list(array $actor): array
    {
        $etas=(new Queue($this->d))->estimates();
        $rows=$this->d->app->db->all('SELECT * FROM seat_requests WHERE owner_id=? ORDER BY id DESC LIMIT 500',[$actor['id']]);
        return ['items'=>array_map(fn($r)=>$this->publicRequest($r,false,$etas[(int)$r['id']]??null),$rows)];
    }
}
