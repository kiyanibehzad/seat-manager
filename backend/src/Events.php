<?php
declare(strict_types=1);
namespace AccManager;
final class Events
{
    public function __construct(private App $app) {}
    public static function requiredPermission(string $type): ?string
    {
        return match ($type) { 'registration.ready'=>'registrations.review','request.created','expiry.action_required'=>'seats.manage',default=>null };
    }
    // Call inside the business transaction: data, notification and outbox commit together.
    public function notify(int $recipient,string $eventKey,string $type,string $title,string $body,string $url,bool $email=true): void
    {
        if (!str_starts_with($url,$this->app->config['app_url'].'/')) { throw new \InvalidArgumentException('Local action URL required'); }
        $required=self::requiredPermission($type); $user=$this->app->auth->user($recipient);
        if (!$user) { throw new \RuntimeException('Recipient missing'); }
        if ($required!==null&&(!$this->app->auth->eligible($user)||!in_array($required,$this->app->auth->permissions($user),true))) { return; }
        $key=hash('sha256',$eventKey);
        if ($this->app->accessGroups->ready()) {
            $this->app->db->run('INSERT INTO notifications(recipient_id,event_key,event_type,title,body,action_url,required_permission) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[$recipient,$key,$type,$title,$body,$url,$required]);
        } else {
            $this->app->db->run('INSERT INTO notifications(recipient_id,event_key,event_type,title,body,action_url) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[$recipient,$key,$type,$title,$body,$url]);
        }
        if ($email) { $this->app->mail->enqueue($recipient,'event:'.$recipient.':'.$eventKey,$user['email'],$title,$body."\n".$url,null,'event',$required); }
    }
    private function visible(int $recipient): array
    {
        if (!$this->app->accessGroups->ready()) { return ['',[]]; }
        $user=$this->app->auth->user($recipient);
        $permissions=$user&&$this->app->auth->eligible($user)?$this->app->auth->permissions($user):AccessGroups::BASE;
        return [' AND (required_permission IS NULL OR required_permission IN ('.implode(',',array_fill(0,count($permissions),'?')).'))',$permissions];
    }
    public function list(int $recipient,array $query): array
    {
        $limit=isset($query['limit'])?Input::id($query,'limit'):20; $limit=min(100,$limit);
        $before=isset($query['before_id'])?Input::id($query,'before_id'):PHP_INT_MAX;
        [$visible,$permissions]=$this->visible($recipient); $where=($query['unread']??'')==='1'?' AND read_at IS NULL':'';
        $rows=$this->app->db->all('SELECT id,event_type,title,body,action_url,created_at,read_at FROM notifications WHERE recipient_id=? AND id<?'.$where.$visible.' ORDER BY id DESC LIMIT '.($limit+1),[$recipient,$before,...$permissions]);
        $more=count($rows)>$limit; if ($more) { array_pop($rows); }
        foreach ($rows as &$row) { $row['id']=(int)$row['id']; foreach (['created_at','read_at'] as $field) { if ($row[$field]!==null) { $row[$field]=str_replace(' ','T',$row[$field]).'Z'; } } } unset($row);
        return ['items'=>$rows,'unread_count'=>(int)$this->app->db->one('SELECT COUNT(*) n FROM notifications WHERE recipient_id=? AND read_at IS NULL'.$visible,[$recipient,...$permissions])['n'],'next_before_id'=>$more?(int)end($rows)['id']:null];
    }
    public function read(int $recipient,array $data): void
    {
        Input::only($data,['id']); $id=Input::id($data,'id'); [$visible,$permissions]=$this->visible($recipient);
        if (!$this->app->db->one('SELECT id FROM notifications WHERE id=? AND recipient_id=?'.$visible,[$id,$recipient,...$permissions])) { throw new ApiError(404,'not_found','اعلان پیدا نشد.'); }
        $this->app->db->run('UPDATE notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=? AND recipient_id=?'.$visible,[$id,$recipient,...$permissions]);
    }
    public function readAll(int $recipient): void
    {
        [$visible,$permissions]=$this->visible($recipient);
        $this->app->db->run('UPDATE notifications SET read_at=UTC_TIMESTAMP() WHERE recipient_id=? AND read_at IS NULL'.$visible,[$recipient,...$permissions]);
    }
    public function mailAllowed(int $recipient,array $payload,string $category='event'): bool
    {
        $required=$payload['required_permission']??null;
        if ($required===null&&!array_key_exists('required_permission',$payload)&&$category==='event'&&$this->app->accessGroups->ready()) {
            // Pre-migration queued event payloads have no permission metadata.
            $notice=$this->app->db->one('SELECT required_permission FROM notifications WHERE recipient_id=? AND title=? AND CONCAT(body,CHAR(10),action_url)=? AND required_permission IS NOT NULL LIMIT 1',[$recipient,$payload['subject'],$payload['body']]);
            $required=$notice['required_permission']??null;
        }
        if ($required===null) { return true; }
        $user=$this->app->auth->user($recipient);
        return $user!==null&&$this->app->auth->eligible($user)&&in_array($required,$this->app->auth->permissions($user),true);
    }
}
