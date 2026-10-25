<?php
declare(strict_types=1);
namespace AccManager\Operations;
use AccManager\Input;
final class Capacity
{
    public function __construct(private Domain $d) {}
    public function snapshot(): array
    {
        $db=$this->d->app->db;
        // One InnoDB statement snapshot: completion cannot split assigned and hold totals.
        $pool=$db->one("SELECT c.*,
          (SELECT COUNT(*) FROM seat_assignments a WHERE a.closed_at IS NULL AND a.seat='standard') assigned_standard,
          (SELECT COUNT(*) FROM seat_assignments a WHERE a.closed_at IS NULL AND a.seat='premium') assigned_premium,
          (SELECT COALESCE(SUM(o.held_standard),0) FROM seat_operations o WHERE o.status='ongoing') held_standard,
          (SELECT COALESCE(SUM(o.held_premium),0) FROM seat_operations o WHERE o.status='ongoing') held_premium
          FROM seat_capacity c WHERE c.id=1");
        $assigned=['standard'=>(int)$pool['assigned_standard'],'premium'=>(int)$pool['assigned_premium']];
        $held=['standard'=>(int)$pool['held_standard'],'premium'=>(int)$pool['held_premium']];
        $out=['revision'=>(int)$pool['revision'],'reconciliation_required'=>false];
        foreach (['standard','premium'] as $seat) { $free=(int)$pool[$seat]-$assigned[$seat]-(int)$held[$seat]; $out[$seat]=['purchased'=>(int)$pool[$seat],'assigned'=>$assigned[$seat],'held'=>(int)$held[$seat],'free'=>$free]; if ($free<0) { $out['reconciliation_required']=true; } }
        return $out;
    }
    public function assertFree(): void { if ($this->snapshot()['reconciliation_required']) { $this->d->conflict('ظرفیت کافی نیست یا واقعیت ثبت‌شده نیاز به تطبیق ظرفیت دارد.'); } }
    public function holds(int $id): void
    {
        $db=$this->d->app->db; $op=$db->one('SELECT * FROM seat_operations WHERE id=?',[$id]); $holds=['standard'=>0,'premium'=>0]; $actual=$holds;
        if ($op['status']==='ongoing' && ($op['capacity_strategy']==='reserve'||$op['capacity_applied'])) {
            foreach ($db->all('SELECT * FROM operation_accounts WHERE operation_id=?',[$id]) as $item) {
                $target=json_decode($item['target_json'],true,16,JSON_THROW_ON_ERROR); if (isset($holds[$target['seat']])) { $holds[$target['seat']]++; }
                $account=$this->d->account((int)$item['account_id']); if (isset($actual[$account['seat']??'none'])) { $actual[$account['seat']]++; }
            }
            foreach ($holds as $type=>&$count) { $count=max(0,$count-$actual[$type]); } unset($count);
        }
        $db->run('UPDATE seat_operations SET held_standard=?,held_premium=? WHERE id=?',[$holds['standard'],$holds['premium'],$id]);
    }
    public function finance(mixed $value): ?array
    {
        if ($value===null) { return null; }
        if (!is_array($value)) { Input::invalid('finance'); }
        Input::only($value,['amount','currency','reference']); $amount=$value['amount']??null;
        if (!is_string($amount)||!preg_match('/^(0|[1-9]\d{0,12})(\.\d{1,4})?$/D',$amount)) { Input::invalid('finance'); }
        $currency=Input::text($value,'currency',8); if (!preg_match('/^[A-Z]{3,8}$/D',$currency)) { Input::invalid('currency'); }
        return ['amount'=>$amount,'currency'=>$currency,'reference'=>Input::text($value,'reference',200)];
    }
    public function record(int $standard,int $premium,array $actor,string $reason,string $evidence,?array $finance=null,?int $operation=null): void
    {
        if ($standard<0||$premium<0||$standard>1000000||$premium>1000000) { Input::invalid('capacity'); }
        $db=$this->d->app->db; $before=$db->one('SELECT standard,premium,revision FROM seat_capacity WHERE id=1 FOR UPDATE');
        $db->run('UPDATE seat_capacity SET standard=?,premium=?,revision=revision+1 WHERE id=1',[$standard,$premium]);
        $after=$db->one('SELECT standard,premium,revision FROM seat_capacity WHERE id=1');
        $db->run('INSERT INTO capacity_history(operation_id,actor_id,before_json,after_json,reason,evidence,finance_json) VALUES(?,?,?,?,?,?,?)',[$operation,$actor['id'],json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),$reason,$evidence,$finance===null?null:json_encode($finance,JSON_THROW_ON_ERROR)]);
        $this->d->app->audit('capacity.recorded',(int)$actor['id'],null,['before'=>$before,'after'=>$after,'reason'=>$reason]);
    }
    public function set(array $data,array $actor): array
    {
        Input::only($data,['standard','premium','expected_revision','confirmed','reason','evidence','finance','idempotency_key']);
        return $this->d->tx($actor,'capacity.manage','capacity.set',$data,function ($fresh) use ($data) {
            foreach (['standard','premium','expected_revision'] as $key) { if (!isset($data[$key])||!is_int($data[$key])||$data[$key]<0) { Input::invalid($key); } }
            if (!Input::boolean($data,'confirmed')) { Input::invalid('confirmed'); }
            $pool=$this->d->app->db->one('SELECT * FROM seat_capacity WHERE id=1'); if ((int)$pool['revision']!==$data['expected_revision']) { $this->d->conflict(); }
            $this->record($data['standard'],$data['premium'],$fresh,Input::text($data,'reason',2000),Input::text($data,'evidence',4000),$this->finance($data['finance']??null));
            return ['capacity'=>$this->snapshot()];
        });
    }
}
