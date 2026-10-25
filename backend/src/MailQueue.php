<?php
declare(strict_types=1);
namespace AccManager;
final class MailQueue
{
    public function __construct(private App $app) {}
    public function enqueue(int $recipient,string $key,string $to,string $subject,string $body,?int $tokenId=null,string $category='event'): int
    {
        $hash=hash('sha256',$key); $host=parse_url($this->app->config['app_url'],PHP_URL_HOST);
        $payload=$this->app->crypto->encrypt(['to'=>$to,'subject'=>$subject,'body'=>$body]);
        $this->app->db->run('INSERT INTO mail_outbox(recipient_id,idempotency_key,message_id,payload_encrypted,auth_token_id,category) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$recipient,$hash,'<'.$hash.'@'.$host.'>',$payload,$tokenId,$category]);
        return (int)$this->app->db->pdo->lastInsertId();
    }
}
