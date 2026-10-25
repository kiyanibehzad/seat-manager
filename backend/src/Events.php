<?php
declare(strict_types=1);
namespace AccManager;
final class Events
{
    public function __construct(private App $app) {}
    // Call inside the business transaction: data, notification and outbox commit together.
    public function notify(int $recipient,string $eventKey,string $type,string $title,string $body,string $url,bool $email=true): void
    {
        if (!str_starts_with($url,$this->app->config['app_url'].'/')) { throw new \InvalidArgumentException('Local action URL required'); }
        $key=hash('sha256',$eventKey);
        $this->app->db->run('INSERT INTO notifications(recipient_id,event_key,event_type,title,body,action_url) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[$recipient,$key,$type,$title,$body,$url]);
        if ($email) {
            $user=$this->app->auth->user($recipient);
            if (!$user) { throw new \RuntimeException('Recipient missing'); }
            $this->app->mail->enqueue($recipient,'event:'.$recipient.':'.$eventKey,$user['email'],$title,$body."\n".$url);
        }
    }
    public function list(int $recipient,array $query): array
    {
        $limit=isset($query['limit'])?Input::id($query,'limit'):20; $limit=min(100,$limit);
        $before=isset($query['before_id'])?Input::id($query,'before_id'):PHP_INT_MAX;
        $where=($query['unread']??'')==='1'?' AND read_at IS NULL':'';
        $rows=$this->app->db->all('SELECT id,event_type,title,body,action_url,created_at,read_at FROM notifications WHERE recipient_id=? AND id<?'.$where.' ORDER BY id DESC LIMIT '.($limit+1),[$recipient,$before]);
        $more=count($rows)>$limit; if ($more) { array_pop($rows); }
        foreach ($rows as &$row) { $row['id']=(int)$row['id']; foreach (['created_at','read_at'] as $field) { if ($row[$field]!==null) { $row[$field]=str_replace(' ','T',$row[$field]).'Z'; } } } unset($row);
        return ['items'=>$rows,'unread_count'=>(int)$this->app->db->one('SELECT COUNT(*) n FROM notifications WHERE recipient_id=? AND read_at IS NULL',[$recipient])['n'],'next_before_id'=>$more?(int)end($rows)['id']:null];
    }
    public function read(int $recipient,array $data): void
    {
        Input::only($data,['id']); $id=Input::id($data,'id');
        if (!$this->app->db->one('SELECT id FROM notifications WHERE id=? AND recipient_id=?',[$id,$recipient])) { throw new ApiError(404,'not_found','اعلان پیدا نشد.'); }
        $this->app->db->run('UPDATE notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=? AND recipient_id=?',[$id,$recipient]);
    }
    public function readAll(int $recipient): void { $this->app->db->run('UPDATE notifications SET read_at=UTC_TIMESTAMP() WHERE recipient_id=? AND read_at IS NULL',[$recipient]); }
}
