<?php
declare(strict_types=1);
namespace AccManager\Operations;
use AccManager\Input;
final class History
{
    public function __construct(private Domain $d) {}
    public function list(array $query,array $actor,bool $admin=false): array
    {
        if (!$admin&&isset($query['request_id'])&&!$this->d->app->db->one('SELECT id FROM seat_requests WHERE id=? AND owner_id=?',[Input::id($query,'request_id'),$actor['id']])) { $this->d->missing(); }
        $where=['h.id<?']; $params=[isset($query['before_id'])?Input::id($query,'before_id'):PHP_INT_MAX]; $limit=min(100,isset($query['limit'])?Input::id($query,'limit'):30);
        if (!$admin) { $where[]='h.owner_id=?'; $params[]=$actor['id']; }
        foreach (['account_id','operation_id','request_id'] as $field) { if (isset($query[$field])) { if (!$admin&&$field==='operation_id') { continue; } $where[]='h.'.$field.'=?'; $params[]=Input::id($query,$field); } }
        $rows=$this->d->app->db->all('SELECT h.*,r.owner_id request_owner,x.email,x.alias,u.first_name actor_first_name,u.last_name actor_last_name FROM seat_history h JOIN openai_accounts x ON x.id=h.account_id JOIN users u ON u.id=h.actor_id LEFT JOIN seat_requests r ON r.id=h.request_id WHERE '.implode(' AND ',$where).' ORDER BY h.id DESC LIMIT '.($limit+1),$params);
        $more=count($rows)>$limit; if ($more) { array_pop($rows); } $items=[];
        foreach ($rows as $row) {
            $out=['id'=>(int)$row['id'],'account_id'=>(int)$row['account_id'],'email'=>$row['email'],'alias'=>$row['alias'],'action'=>$row['action'],'before'=>json_decode($row['before_json'],true,32,JSON_THROW_ON_ERROR),'after'=>json_decode($row['after_json'],true,32,JSON_THROW_ON_ERROR),'observed_at'=>Domain::iso($row['observed_at']),'recorded_at'=>Domain::iso($row['recorded_at']),'request_id'=>$row['request_id']===null||(!$admin&&(int)$row['request_owner']!==(int)$actor['id'])?null:(int)$row['request_id']];
            foreach (['before','after'] as $side) { foreach (['starts_at','ends_at','assigned_at'] as $field) { if (isset($out[$side][$field])) { $out[$side][$field]=Domain::iso($out[$side][$field]); } } }
            if ($admin) { $out+=['owner_id'=>(int)$row['owner_id'],'actor_id'=>(int)$row['actor_id'],'actor_name'=>$row['actor_first_name'].' '.$row['actor_last_name'],'operation_id'=>$row['operation_id']===null?null:(int)$row['operation_id'],'reason'=>$row['reason'],'evidence'=>$row['evidence']]; }
            $items[]=$out;
        }
        return ['items'=>$items,'next_before_id'=>$more?(int)end($rows)['id']:null];
    }
    public function finance(array $query): array
    {
        $before=isset($query['before_id'])?Input::id($query,'before_id'):PHP_INT_MAX; $limit=min(100,isset($query['limit'])?Input::id($query,'limit'):30);
        $rows=$this->d->app->db->all('SELECT * FROM capacity_history WHERE id<? ORDER BY id DESC LIMIT '.($limit+1),[$before]); $more=count($rows)>$limit; if ($more) { array_pop($rows); }
        foreach ($rows as &$row) { foreach (['id','actor_id'] as $field) { $row[$field]=(int)$row[$field]; } $row['operation_id']=$row['operation_id']===null?null:(int)$row['operation_id']; foreach (['before','after','finance'] as $field) { $row[$field]=$row[$field.'_json']===null?null:json_decode($row[$field.'_json'],true,32,JSON_THROW_ON_ERROR); unset($row[$field.'_json']); } $row['recorded_at']=Domain::iso($row['recorded_at']); } unset($row);
        return ['items'=>$rows,'next_before_id'=>$more?(int)end($rows)['id']:null];
    }
}
