<?php
declare(strict_types=1);
namespace AccManager;
use PHPMailer\PHPMailer\PHPMailer;
final class MailWorker
{
    public function __construct(private App $app) {}
    public function run(int $limit=20,int $seconds=40): array
    {
        $smtp=$this->app->config['smtp'];
        $counts=['sent'=>0,'retry'=>0,'failed'=>0,'uncertain'=>0,'cancelled'=>0];
        if (!$smtp['enabled']) { return $counts+['disabled'=>true]; }
        if ($this->app->config['environment']!=='production' && ($smtp['host']!=='127.0.0.1'||(int)$smtp['port']!==10279)) { throw new \RuntimeException('Development SMTP must use the local sink'); }
        if ($this->app->config['environment']==='production' && !in_array($smtp['encryption'],['tls','ssl'],true)) { throw new \RuntimeException('Encrypted SMTP required'); }
        $db=$this->app->db; $lock='acc-mail-'.substr(hash('sha256',$this->app->config['db']['dsn']),0,32);
        if (!(int)$db->one('SELECT GET_LOCK(?,0) acquired',[$lock])['acquired']) { return $counts+['busy'=>true]; }
        $start=microtime(true);
        try {
            // A worker killed while sending never gets an automatic second delivery attempt.
            $db->run("UPDATE mail_outbox SET status='uncertain',last_error_code='worker_interrupted' WHERE status='sending' AND claimed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)");
            for ($i=0;$i<min(100,$limit)&&microtime(true)-$start<$seconds;$i++) {
                $row=$db->transaction(function () use ($db) {
                    $row=$db->one("SELECT * FROM mail_outbox WHERE status IN ('pending','retry') AND available_at<=UTC_TIMESTAMP() ORDER BY id LIMIT 1 FOR UPDATE");
                    if (!$row) { return null; }
                    if ($row['auth_token_id']!==null && !$db->one('SELECT id FROM auth_tokens WHERE id=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP()',[$row['auth_token_id']])) {
                        $db->run("UPDATE mail_outbox SET status='cancelled',payload_encrypted=NULL WHERE id=?",[$row['id']]);
                        return ['cancelled'=>true];
                    }
                    $db->run("UPDATE mail_outbox SET status='sending',claimed_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id=?",[$row['id']]);
                    return $row;
                });
                if (!$row) { break; }
                if (isset($row['cancelled'])) { $counts['cancelled']++; continue; }
                $transport=new TrackedSmtp();
                try {
                    $payload=$this->app->crypto->decrypt($row['payload_encrypted']);
                    if (!$this->app->events->mailAllowed((int)$row['recipient_id'],$payload,$row['category'])) {
                        $db->run("UPDATE mail_outbox SET status='cancelled',payload_encrypted=NULL,last_error_code='permission_revoked' WHERE id=?",[$row['id']]);
                        $counts['cancelled']++; continue;
                    }
                    $mailer=new PHPMailer(true); $mailer->isSMTP(); $mailer->setSMTPInstance($transport);
                    $mailer->Host=$smtp['host']; $mailer->Port=(int)$smtp['port']; $mailer->SMTPDebug=0;
                    $mailer->Timeout=min(15,max(1,(int)$smtp['timeout_seconds'])); $transport->Timelimit=$mailer->Timeout;
                    $mailer->SMTPAuth=$smtp['username']!==''; $mailer->Username=$smtp['username']; $mailer->Password=$smtp['password'];
                    $mailer->SMTPSecure=$smtp['encryption']; $mailer->SMTPAutoTLS=$smtp['encryption']!=='';
                    $mailer->CharSet='UTF-8'; $mailer->setFrom($smtp['from_email'],$smtp['from_name']); $mailer->addAddress($payload['to']);
                    $mailer->MessageID=$row['message_id']; $mailer->Subject=$payload['subject']; $mailer->Body=$payload['body']; $mailer->isHTML(false);
                    $mailer->send();
                    $db->run("UPDATE mail_outbox SET status='sent',sent_at=UTC_TIMESTAMP(),payload_encrypted=NULL,last_error_code=NULL WHERE id=?",[$row['id']]);
                    $counts['sent']++;
                } catch (\Throwable $e) {
                    // Never persist PHPMailer ErrorInfo/exception text: it can contain credentials or message data.
                    $attempts=(int)$row['attempts']+1;
                    $status=$transport->dataAttempted?'uncertain':($attempts>=min(5,max(1,(int)$smtp['max_attempts']))?'failed':'retry');
                    $available=gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**($attempts-1))));
                    $db->run('UPDATE mail_outbox SET status=?,last_error_code=?,available_at=? WHERE id=?',[$status,$transport->dataAttempted?'smtp_acceptance_unknown':'smtp_pre_delivery_failure',$available,$row['id']]);
                    $counts[$status]++;
                }
            }
        } finally { $db->one('SELECT RELEASE_LOCK(?) released',[$lock]); }
        return $counts;
    }
}
