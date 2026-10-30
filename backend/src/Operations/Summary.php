<?php
declare(strict_types=1);
namespace AccManager\Operations;
use AccManager\Input;

final class Summary
{
    public function __construct(private Domain $d) {}
    public function view(array $query,array $actor,bool $admin=false): array
    {
        $credits=new Credits($this->d); $credits->requireReady();
        $owner=$admin?Input::id($query,'owner_id'):(int)$actor['id'];
        if (!$admin&&isset($query['owner_id'])&&Input::id($query,'owner_id')!==$owner) { $this->d->missing(); }
        $db=$this->d->app->db; $person=$db->one('SELECT id,first_name,last_name,created_at FROM users WHERE id=?',[$owner])??$this->d->missing();
        $firstAssignment=$db->one('SELECT MIN(assigned_at) earliest FROM seat_assignments WHERE owner_id=?',[$owner])['earliest']??null;
        $from=isset($query['from'])?Domain::date($query['from'],'from'):min($person['created_at'],$firstAssignment??$person['created_at']);
        $to=isset($query['to'])?Domain::date($query['to'],'to'):gmdate('Y-m-d H:i:s');
        if ($to!==null) { $to=min($to,gmdate('Y-m-d H:i:s')); }
        if ($from===null||$to===null||$from>$to) { Input::invalid('to','بازهٔ گزارش معتبر و تا زمان جاری لازم است.'); }
        $lo=strtotime($from.' UTC'); $hi=strtotime($to.' UTC'); $items=[]; $intervals=[];
        $accounts=$db->all('SELECT x.id,x.email,CASE WHEN x.owner_id=? THEN x.alias ELSE NULL END alias,x.created_at FROM openai_accounts x WHERE x.owner_id=? OR EXISTS(SELECT 1 FROM seat_assignments a WHERE a.account_id=x.id AND a.owner_id=?) OR EXISTS(SELECT 1 FROM credit_requests r WHERE r.account_id=x.id AND r.owner_id=?) ORDER BY x.id',[$owner,$owner,$owner,$owner]);
        foreach ($accounts as $a) {
            $id=(int)$a['id']; $items[$id]=['id'=>$id,'email'=>$a['email'],'alias'=>$a['alias'],'standard_seconds'=>0,'premium_seconds'=>0,'ambiguous_seconds'=>0,'seat_changes'=>0,'renewals'=>0,'history_incomplete'=>false];
        }
        foreach ($db->all('SELECT account_id,seat,assigned_at,closed_at FROM seat_assignments WHERE owner_id=? ORDER BY account_id,assigned_at,id',[$owner]) as $a) {
            $id=(int)$a['account_id']; if (!isset($items[$id])) { continue; }
            $start=strtotime($a['assigned_at'].' UTC'); $end=$a['closed_at']===null?time():strtotime($a['closed_at'].' UTC');
            if ($start===false||$end===false||$end<$start) { $items[$id]['history_incomplete']=true; continue; }
            $start=max($lo,$start); $end=min($hi,$end); if ($end<=$start) { continue; }
            $intervals[$id][]=[$start,$end,$a['seat']];
        }
        foreach ($intervals as $id=>$spans) {
            $events=[];
            foreach ($spans as [$start,$end,$seat]) { $events[$start][$seat]=($events[$start][$seat]??0)+1; $events[$end][$seat]=($events[$end][$seat]??0)-1; }
            ksort($events,SORT_NUMERIC); $active=['standard'=>0,'premium'=>0]; $previous=null;
            foreach ($events as $at=>$delta) {
                if ($previous!==null&&$at>$previous) {
                    $seconds=$at-$previous;
                    if ($active['standard']>0&&$active['premium']>0) { $items[$id]['ambiguous_seconds']+=$seconds; $items[$id]['history_incomplete']=true; }
                    elseif ($active['standard']>0) { $items[$id]['standard_seconds']+=$seconds; }
                    elseif ($active['premium']>0) { $items[$id]['premium_seconds']+=$seconds; }
                    if ($active['standard']>1||$active['premium']>1) { $items[$id]['history_incomplete']=true; }
                }
                foreach ($delta as $seat=>$n) { $active[$seat]+=$n; } $previous=(int)$at;
            }
        }
        $changes=[]; $renewals=[];
        foreach ($db->all('SELECT h.account_id,h.id,h.operation_id,h.before_json,h.after_json,o.kind FROM seat_history h LEFT JOIN seat_operations o ON o.id=h.operation_id WHERE h.owner_id=? AND COALESCE(h.observed_at,h.recorded_at)>=? AND COALESCE(h.observed_at,h.recorded_at)<=? ORDER BY h.id',[$owner,$from,$to]) as $h) {
            $id=(int)$h['account_id']; if (!isset($items[$id])) { continue; }
            $before=json_decode($h['before_json'],true,32,JSON_THROW_ON_ERROR); $after=json_decode($h['after_json'],true,32,JSON_THROW_ON_ERROR);
            if (($before['seat']??'none')!==($after['seat']??'none')) { $changes[$h['id']]=true; $items[$id]['seat_changes']++; }
            if ($h['kind']==='renewal'&&($before['ends_at']??null)!==($after['ends_at']??null)) { $key=$h['operation_id'].':'.$id; if (!isset($renewals[$key])) { $renewals[$key]=true; $items[$id]['renewals']++; } }
        }
        $statusCounts=array_fill_keys(['pending','ongoing','completed','rejected','cancelled'],0); $requested=0; $approved=0; $count=0;
        foreach ($db->all('SELECT status,COUNT(*) n,COALESCE(SUM(requested_credits),0) requested,COALESCE(SUM(CASE WHEN status IN (\'ongoing\',\'completed\') THEN approved_credits ELSE 0 END),0) approved FROM credit_requests WHERE owner_id=? AND created_at>=? AND created_at<=? GROUP BY status',[$owner,$from,$to]) as $r) {
            $statusCounts[$r['status']]=(int)$r['n']; $count+=(int)$r['n']; $requested+=(int)$r['requested']; $approved+=(int)$r['approved'];
        }
        $completed=$db->one("SELECT COUNT(*) n,COALESCE(SUM(approved_credits),0) credits FROM credit_requests WHERE owner_id=? AND status='completed' AND completed_at>=? AND completed_at<=?",[$owner,$from,$to]);
        $standard=array_sum(array_column($items,'standard_seconds')); $premium=array_sum(array_column($items,'premium_seconds'));
        return ['owner_id'=>$owner,'person'=>['first_name'=>$person['first_name'],'last_name'=>$person['last_name']],
            'range'=>['from'=>Domain::iso($from),'to'=>Domain::iso($to),'seconds'=>$hi-$lo],
            'seat_totals'=>['standard_seconds'=>$standard,'premium_seconds'=>$premium,'account_days'=>round(($standard+$premium)/86400,4),'ambiguous_seconds'=>array_sum(array_column($items,'ambiguous_seconds'))],
            'accounts'=>array_values($items),'seat_changes'=>count($changes),'renewals'=>count($renewals),
            'credits'=>['unit'=>'credits','request_count'=>$count,'requested_credits'=>$requested,'approved_credits'=>$approved,'completed_credits'=>(int)$completed['credits'],'completed_request_count'=>(int)$completed['n'],'status_counts'=>$statusCounts,'actual_consumption'=>null],
            'notes'=>['مدت‌ها بر اساس زمان واقعی تخصیص و بسته‌شدن ثبت‌شده محاسبه شده‌اند؛ تاریخ پایان برنامه‌ریزی‌شده مبنای آزادسازی نیست.','جمع مدت چند حساب به‌صورت روزِ حساب است؛ استفادهٔ هم‌زمان جمع می‌شود. زمان‌های متناقض در مدت هیچ سیتی شمرده نمی‌شوند.','تعداد و مقدار درخواست‌ها بر اساس زمان ثبت در بازه است؛ اعتبار تکمیل‌شده بر اساس زمان تکمیل در بازه است. مقادیر اعتبار، سقف مجازند و مصرف واقعی نیستند.','این گزارش تنها سوابق ثبت‌شده در برنامه را پوشش می‌دهد؛ وضعیت قبل از ثبت حساب قابل استنتاج نیست.']];
    }
}
