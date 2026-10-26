<?php
declare(strict_types=1);
namespace AccManager;
final class MailWorker
{
    public function __construct(private App $app) {}
    public function run(int $limit=20,int $seconds=40,?int $explicitTestId=null): array
    {
        $settings=new MailSettings($this->app); $s=$settings->current();
        $counts=['sent'=>0,'retry'=>0,'failed'=>0,'uncertain'=>0,'cancelled'=>0];
        $db=$this->app->db; $lock='acc-mail-'.substr(hash('sha256',$this->app->config['db']['dsn']),0,32);
        if (!(int)$db->one('SELECT GET_LOCK(?,0) acquired',[$lock])['acquired']) { return $counts+['busy'=>true]; }
        $start=microtime(true); $stateReady=$settings->ready()&&$explicitTestId===null; $error=null;
        try {
            if ($stateReady) { $db->run('UPDATE mail_service_state SET worker_last_started_at=UTC_TIMESTAMP(),worker_last_error_code=NULL WHERE id=1'); }
            // A worker killed while sending never gets an automatic second delivery attempt, even with delivery disabled.
            $db->run("UPDATE mail_outbox SET status='uncertain',last_error_code='worker_interrupted' WHERE status='sending' AND claimed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)");
            if (!$s['delivery_enabled']&&$explicitTestId===null) { return $counts+['disabled'=>true]; }
            (new MailTransport($this->app))->assertAllowed($s);
            for ($i=0;$i<min(100,max(0,$limit))&&microtime(true)-$start<$seconds;$i++) {
                $s=$settings->current(); if (!$s['delivery_enabled']&&$explicitTestId===null) { break; }
                $row=$db->transaction(function () use ($db,$explicitTestId) {
                    $filter=$explicitTestId===null?'':" AND id=? AND category='test'";
                    $row=$db->one("SELECT * FROM mail_outbox WHERE status IN ('pending','retry') AND available_at<=UTC_TIMESTAMP()".$filter.' ORDER BY id LIMIT 1 FOR UPDATE',$explicitTestId===null?[]:[$explicitTestId]);
                    if (!$row) { return null; }
                    if ($row['auth_token_id']!==null && !$db->one('SELECT id FROM auth_tokens WHERE id=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP()',[$row['auth_token_id']])) {
                        $db->run("UPDATE mail_outbox SET status='cancelled',payload_encrypted=NULL,last_error_code='auth_token_expired' WHERE id=?",[$row['id']]);
                        return ['cancelled'=>true];
                    }
                    $db->run("UPDATE mail_outbox SET status='sending',claimed_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id=?",[$row['id']]); return $row;
                });
                if (!$row) { break; }
                if (isset($row['cancelled'])) { $counts['cancelled']++; continue; }
                $transport=new MailTransport($this->app); $payloadReady=false;
                try {
                    $payload=$this->app->crypto->decrypt($row['payload_encrypted']); $payloadReady=true;
                    if (!$this->app->events->mailAllowed((int)$row['recipient_id'],$payload,$row['category'])) {
                        $db->run("UPDATE mail_outbox SET status='cancelled',payload_encrypted=NULL,last_error_code='permission_revoked' WHERE id=?",[$row['id']]);
                        $counts['cancelled']++; continue;
                    }
                    $transport->send($s,$payload,$row['message_id']);
                    $db->run("UPDATE mail_outbox SET status='sent',sent_at=UTC_TIMESTAMP(),payload_encrypted=NULL,last_error_code=NULL WHERE id=?",[$row['id']]); $counts['sent']++;
                } catch (\Throwable $e) {
                    // Never persist transport exception text: it can contain credentials or message data.
                    $attempts=(int)$row['attempts']+1; $code='smtp_pre_delivery_failure';
                    if (!$payloadReady) { $status='failed'; $code='payload_unreadable'; }
                    elseif ($transport->dataAttempted) { $status='uncertain'; $code=$s['transport']==='phpmail'?'phpmail_acceptance_unknown':'smtp_acceptance_unknown'; }
                    elseif ($e instanceof ApiError) { $status='failed'; $code='mail_configuration_invalid'; }
                    else { $status=$attempts>=min(5,max(1,(int)$this->app->config['smtp']['max_attempts']))?'failed':'retry'; }
                    $available=gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**min(6,$attempts-1))));
                    $db->run('UPDATE mail_outbox SET status=?,last_error_code=?,available_at=? WHERE id=?',[$status,$code,$available,$row['id']]); $counts[$status]++;
                }
            }
        } catch (\Throwable $e) { $error='mail_worker_failed'; throw $e; }
        finally {
            try { if ($stateReady) { $db->run('UPDATE mail_service_state SET worker_last_finished_at=UTC_TIMESTAMP(),worker_last_error_code=? WHERE id=1',[$error]); } }
            finally { $db->one('SELECT RELEASE_LOCK(?) released',[$lock]); }
        }
        return $counts;
    }
}
