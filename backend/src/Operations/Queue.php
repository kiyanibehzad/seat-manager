<?php
declare(strict_types=1);
namespace AccManager\Operations;
final class Queue
{
    public function __construct(private Domain $d) {}
    public function pending(): array
    {
        return $this->d->app->db->all("SELECT r.*,(COALESCE(d.base_score,1)+(u.organizational_position='supervisor')+u.special_priority) final_score FROM seat_requests r JOIN users u ON u.id=r.owner_id LEFT JOIN departments d ON d.id=u.department_id WHERE r.status='pending' AND r.kind='seat' ORDER BY final_score DESC,r.created_at,r.id");
    }
    public function estimates(): array
    {
        $capacity=(new Capacity($this->d))->snapshot(); $requests=new Requests($this->d); $events=[]; $result=[]; $rank=['standard'=>0,'premium'=>0];
        $hours=(int)json_decode($this->d->app->db->one("SELECT value_json FROM settings WHERE setting_key='manual_action_hours'")['value_json'],true); $delay=$hours*3600; $base=time()+$delay;
        $available=function (string $seat,int $at) use (&$events,$capacity): int { $n=$capacity[$seat]['free']; foreach ($events as $event) { if ($event['seat']===$seat&&$event['at']<=$at) { $n+=$event['delta']; } } return $n; };
        $fits=function (string $seat,int $from,int $until,int $extra=0) use (&$events,$available): bool {
            if ($available($seat,$from)+$extra<1) { return false; }
            foreach ($events as $event) { if ($event['seat']===$seat&&$event['at']>$from&&$event['at']<$until&&$available($seat,$event['at'])+$extra<1) { return false; } }
            return true;
        };
        // Release is always a conditional forecast, never a database mutation.
        $allocations=$this->d->app->db->all("SELECT a.* FROM seat_assignments a JOIN openai_accounts x ON x.id=a.account_id JOIN users u ON u.id=x.owner_id LEFT JOIN account_operation_locks l ON l.account_id=x.id WHERE a.closed_at IS NULL AND a.ends_at IS NOT NULL AND l.account_id IS NULL AND u.is_active=1 AND x.ownership_status='clear' AND NOT EXISTS (SELECT 1 FROM seat_requests r WHERE r.account_id=x.id AND r.kind='renewal' AND r.status IN ('pending','ongoing')) ORDER BY a.ends_at,a.id");
        foreach ($allocations as $a) {
            if ($a['return_seat']===$a['seat']) { continue; }
            $at=max($base,strtotime($a['ends_at'].' UTC')+$delay); $key='assignment:'.$a['id'];
            if ($a['return_seat']!=='none') { if (!$fits($a['return_seat'],$at,PHP_INT_MAX)) { continue; } $events[]=['seat'=>$a['return_seat'],'at'=>$at,'delta'=>-1,'source'=>$key]; }
            $events[]=['seat'=>$a['seat'],'at'=>$at,'delta'=>1,'source'=>$key];
        }
        foreach ($this->pending() as $r) {
            $a=$this->d->account((int)$r['account_id']); $type=$r['requested_seat']; $blockers=$requests->blockers($r,$a);
            $eta=['rank'=>null,'estimated_at'=>null,'conditional'=>false,'reason'=>'فعلاً قابل برآورد نیست؛ ظرفیت یا تصمیم مدیر لازم است.'];
            if ($blockers) { $eta['reason']='نیاز به بازبینی وضعیت حساب، بازه یا عملیات جاری است.'; $result[(int)$r['id']]=$eta; continue; }
            $eta['rank']=++$rank[$type];
            if ($capacity['reconciliation_required']) { $eta['reason']='ظرفیت ثبت‌شده نیاز به تطبیق با واقعیت دارد.'; $result[(int)$r['id']]=$eta; continue; }
            $at=max($base,$r['requested_starts_at']===null?$base:strtotime($r['requested_starts_at'].' UTC'));
            $needs=($a['seat']??'none')===$type?0:1;
            $end=$r['requested_ends_at']===null?PHP_INT_MAX:strtotime($r['requested_ends_at'].' UTC')+$delay;
            $old=$a['seat']??'none';
            $canReturn=$r['requested_ends_at']!==null&&($old==='none'||($old!==$type&&$fits($old,$end,PHP_INT_MAX,1)));
            $occupiedUntil=$canReturn?$end:PHP_INT_MAX;
            if ($needs&&!$fits($type,$at,$occupiedUntil)) {
                $candidates=[]; foreach ($events as $event) { if ($event['seat']===$type&&$event['delta']>0&&$event['at']>$at) { $candidates[]=$event['at']; } } sort($candidates);
                foreach ($candidates as $candidate) { $at=$candidate; if ($fits($type,$at,$occupiedUntil)) { break; } }
            }
            if ($needs&&!$fits($type,$at,$occupiedUntil)) { $result[(int)$r['id']]=$eta; continue; }
            if ($r['requested_ends_at']!==null&&strtotime($r['requested_ends_at'].' UTC')<$at) { $eta['reason']='بازه پیش از زمان احتمالی اقدام تمام می‌شود؛ بازبینی لازم است.'; $result[(int)$r['id']]=$eta; continue; }
            $eta['estimated_at']=gmdate('Y-m-d\TH:i:s\Z',$at); $eta['conditional']=true; $eta['reason']='برآورد مشروط به اقدام دستی، عدم تمدید و ظرفیت ثبت‌شدهٔ فعلی است.';
            $old=$a['seat']??'none';
            if ($needs) {
                $events=array_values(array_filter($events,fn($event)=>$event['source']!=='assignment:'.($a['assignment_id']??0)));
                $events[]=['seat'=>$type,'at'=>$at,'delta'=>-1,'source'=>'request:'.$r['id']];
                if ($old!=='none') { $events[]=['seat'=>$old,'at'=>$at,'delta'=>1,'source'=>'request:'.$r['id']]; }
            }
            if ($r['requested_ends_at']!==null) {
                $end=strtotime($r['requested_ends_at'].' UTC')+$delay;
                if ($canReturn) {
                    $events[]=['seat'=>$type,'at'=>$end,'delta'=>1,'source'=>'request:'.$r['id']];
                    if ($old!=='none') { $events[]=['seat'=>$old,'at'=>$end,'delta'=>-1,'source'=>'request:'.$r['id']]; }
                }
            }
            $result[(int)$r['id']]=$eta;
        }
        return $result;
    }
    public function view(): array
    {
        $requestService=new Requests($this->d); $estimates=$this->estimates();
        $ordinary=array_map(fn($r)=>$requestService->publicRequest($r,true,$estimates[(int)$r['id']]??null),$this->pending());
        $renewals=$this->d->app->db->all("SELECT r.* FROM seat_requests r LEFT JOIN seat_assignments a ON a.id=r.assignment_id WHERE r.status='pending' AND r.kind='renewal' ORDER BY a.ends_at,r.created_at,r.id");
        return ['requests'=>$ordinary,'renewals'=>array_map(fn($r)=>$requestService->publicRequest($r,true),$renewals),'capacity'=>(new Capacity($this->d))->snapshot()];
    }
    public function assertOrder(array $r,?string $override): void
    {
        if ($r['kind']==='renewal') { return; }
        $requests=new Requests($this->d);
        foreach ($this->pending() as $candidate) {
            if ($candidate['requested_seat']!==$r['requested_seat']||$requests->blockers($candidate)) { continue; }
            if ((int)$candidate['id']!==(int)$r['id']&&($override===null||trim($override)==='')) { $this->d->conflict('رسیدگی خارج از ترتیب صف به دلیل ثبت‌شده نیاز دارد.'); }
            return;
        }
    }
}
