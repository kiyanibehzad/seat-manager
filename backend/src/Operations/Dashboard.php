<?php
declare(strict_types=1);
namespace AccManager\Operations;
final class Dashboard
{
    public function __construct(private Domain $d) {}
    public function view(): array
    {
        $today=Domain::iranDay(); $tomorrow=(new \DateTimeImmutable('tomorrow',new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $queue=(new Queue($this->d))->view(); $pending=[]; $covered=[]; $buckets=['today'=>[],'tomorrow'=>[]];
        foreach ([...$queue['renewals'],...$queue['requests']] as $r) { $pending[$r['account']['id']][]=$r; }
        // Build each case once, then choose its actionable day; ETA never supplies a due date.
        foreach ($this->d->app->db->all("SELECT id FROM seat_operations WHERE status='ongoing' ORDER BY id") as $row) {
            $op=(new Engine($this->d))->view((int)$row['id']); $related=[]; $due=$op['effect_at']; $inactive=false;
            foreach ($op['items'] as $item) {
                $a=$item['account']; $related=[...$related,...($pending[$a['id']]??[])]; $inactive=$inactive||!$a['is_active'];
                if ($a['ends_at']!==null&&($due===null||$a['ends_at']<$due)) { $due=$a['ends_at']; }
            }
            $renewal=$op['kind']==='renewal'||(bool)array_filter($related,fn($r)=>$r['kind']==='renewal');
            $bucket='today';
            if ($op['phase']==='scheduled'&&!$related&&!$inactive) {
                $effectDay=$op['effect_at']===null?null:Domain::iranDay(str_replace(['T','Z'],[' ',''],$op['effect_at']));
                if ($effectDay!==null&&$effectDay>$tomorrow) { foreach ($op['items'] as $item) { $covered[$item['account']['id']]=true; } continue; }
                if ($effectDay===$tomorrow) { $bucket='tomorrow'; }
            }
            foreach ($op['items'] as $item) { $covered[$item['account']['id']]=true; }
            $buckets[$bucket][]=['key'=>'operation:'.$op['id'],'kind'=>'operation','priority'=>$renewal?0:1,'due_at'=>$due,'operation'=>$op,'related_requests'=>$related,'related_renewal'=>$renewal,'action'=>'continue_operation'];
        }
        foreach ($pending as $id=>$requests) {
            if (isset($covered[$id])) { continue; }
            usort($requests,static fn($a,$b)=>($a['kind']==='renewal'?0:1)<=>($b['kind']==='renewal'?0:1));
            $r=$requests[0]; $covered[$id]=true; $renewal=$r['kind']==='renewal';
            $buckets['today'][]=['key'=>'account:'.$id,'kind'=>$renewal?'renewal':'request','priority'=>$renewal?0:3,'due_at'=>$renewal?$r['account']['ends_at']:$r['requested_starts_at'],'request'=>$r,'related_requests'=>$requests,'related_expiry_at'=>$r['account']['ends_at'],'action'=>$renewal?'review_renewal':'review_request'];
        }
        foreach ($this->d->app->db->all($this->d->accountSql().' WHERE a.id IS NOT NULL ORDER BY a.ends_at,x.id') as $a) {
            $id=(int)$a['id']; if (isset($covered[$id])) { continue; }
            $inactive=!(bool)$a['is_active']; $endDay=$a['ends_at']===null?null:Domain::iranDay($a['ends_at']);
            if (!$inactive&&($endDay===null||$endDay>$tomorrow)) { continue; }
            $bucket=(!$inactive&&$endDay===$tomorrow)?'tomorrow':'today';
            $buckets[$bucket][]=['key'=>'account:'.$id,'kind'=>$inactive?'inactive_reclaim':'expiry','priority'=>2,'due_at'=>Domain::iso($a['ends_at']),'account'=>$this->d->publicAccount($a,true),'suggested_return_seat'=>$a['return_seat'],'action'=>'start_return'];
        }
        foreach ($buckets as &$tasks) {
            usort($tasks,static function ($a,$b) {
                $cmp=$a['priority']<=>$b['priority']; if ($cmp!==0) { return $cmp; }
                if ($a['kind']==='request'&&$b['kind']==='request') { return ($b['request']['score']<=>$a['request']['score'])?:strcmp($a['request']['created_at'],$b['request']['created_at'])?:($a['request']['id']<=>$b['request']['id']); }
                return strcmp($a['due_at']??'9999',$b['due_at']??'9999')?:strcmp($a['key'],$b['key']);
            });
        } unset($tasks);
        return $buckets+['counts'=>['today'=>count($buckets['today']),'tomorrow'=>count($buckets['tomorrow'])],'iran_today'=>$today];
    }
}
