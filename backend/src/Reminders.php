<?php
declare(strict_types=1);
namespace AccManager;
final class Reminders
{
    public function __construct(private App $app) {}
    // Stage 2 adapter contract: call/cancel inside the same assignment transaction.
    // The authoritative expiry is an instant in Asia/Tehran, then persisted as UTC.
    public function schedulePremium(int $recipient,int $assignmentId,int $version,\DateTimeImmutable $end,string $renewalUrl): void
    {
        if ($assignmentId<1||$version<1||!str_starts_with($renewalUrl,$this->app->config['app_url'].'/')) { throw new \InvalidArgumentException('Invalid reminder source'); }
        $due=$end->setTimezone(new \DateTimeZone('Asia/Tehran'))->modify('-1 day')->setTimezone(new \DateTimeZone('UTC'));
        $this->app->db->run("UPDATE scheduled_reminders SET cancelled_at=UTC_TIMESTAMP() WHERE source_type='assignment' AND source_id=? AND source_version<>? AND dispatched_at IS NULL AND cancelled_at IS NULL",[$assignmentId,$version]);
        $payload=$this->app->crypto->encrypt(['title'=>'یادآوری پایان Premium','body'=>'یک روز تا پایان دورهٔ Premium شما باقی مانده است. در صورت نیاز درخواست تمدید ثبت کنید. پایان دوره سیت را خودکار آزاد نمی‌کند.','url'=>$renewalUrl]);
        $this->app->db->run("INSERT INTO scheduled_reminders(recipient_id,source_type,source_id,source_version,event_type,due_at,payload_encrypted) VALUES(?,'assignment',?,?,'premium.expiring',?,?) ON DUPLICATE KEY UPDATE id=id",[$recipient,$assignmentId,$version,$due->format('Y-m-d H:i:s'),$payload]);
    }
    public function cancel(int $assignmentId): void
    {
        $this->app->db->run("UPDATE scheduled_reminders SET cancelled_at=UTC_TIMESTAMP() WHERE source_type='assignment' AND source_id=? AND dispatched_at IS NULL AND cancelled_at IS NULL",[$assignmentId]);
    }
    public function dispatch(int $limit=100,?\DateTimeImmutable $clock=null): array
    {
        $now=($clock??new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $limit=min(200,max(1,$limit));
        return $this->app->db->transaction(function () use ($limit,$now) {
            $d=new \AccManager\Operations\Domain($this->app); $d->lock(); $db=$this->app->db;
            $counts=['reminders'=>0,'cancelled'=>0,'action_alerts'=>0];
            $rows=$db->all('SELECT * FROM scheduled_reminders WHERE dispatched_at IS NULL AND cancelled_at IS NULL AND due_at<=? ORDER BY due_at,id LIMIT '.$limit.' FOR UPDATE',[$now]);
            foreach ($rows as $row) {
                $a=$db->one('SELECT a.*,x.owner_id current_owner,x.email FROM seat_assignments a JOIN openai_accounts x ON x.id=a.account_id WHERE a.id=?',[$row['source_id']]);
                if ($row['source_type']!=='assignment'||!$a||$a['closed_at']!==null||$a['seat']!=='premium'||$a['ends_at']===null||$a['ends_at']<=$now||(int)$a['version']!==(int)$row['source_version']||(int)$a['current_owner']!==(int)$row['recipient_id']) {
                    $db->run('UPDATE scheduled_reminders SET cancelled_at=? WHERE id=?',[$now,$row['id']]); $counts['cancelled']++; continue;
                }
                $this->app->events->notify((int)$row['recipient_id'],'premium-reminder:'.$a['id'].':'.$a['version'],'premium.expiring','یادآوری پایان Premium','پایان دورهٔ Premium حساب '.$a['email'].' نزدیک است؛ در صورت نیاز درخواست تمدید ثبت کنید. پایان دوره به‌تنهایی سیت را آزاد نمی‌کند.',$this->app->url('view=my-requests&renew_account='.$a['account_id']));
                $db->run('UPDATE scheduled_reminders SET dispatched_at=? WHERE id=?',[$now,$row['id']]); $counts['reminders']++;
            }
            foreach (['expiry','inactive'] as $type) {
                $condition=$type==='expiry'?'a.ends_at IS NOT NULL AND a.ends_at<=?':'u.is_active=0';
                $identity=$type==='expiry'?'0':'u.auth_version';
                $query="SELECT a.*,x.email,x.owner_id,u.first_name,u.last_name,u.auth_version FROM seat_assignments a JOIN openai_accounts x ON x.id=a.account_id JOIN users u ON u.id=x.owner_id WHERE a.closed_at IS NULL AND ".$condition." AND NOT EXISTS (SELECT 1 FROM assignment_alert_markers m WHERE m.assignment_id=a.id AND m.version=a.version AND m.event_type='".$type."' AND m.identity_version=".$identity.") ORDER BY a.id LIMIT ".$limit;
                foreach ($db->all($query,$type==='expiry'?[$now]:[]) as $a) {
                    $identityVersion=$type==='expiry'?0:(int)$a['auth_version'];
                    $d->managers('assignment-action:'.$a['id'].':'.$a['version'].':'.$type.':'.$identityVersion,'expiry.action_required',$type==='expiry'?'پایان دوره نیازمند اقدام است':'سیت کاربر غیرفعال نیازمند پیگیری است',$a['first_name'].' '.$a['last_name'].' — '.$a['email'].'؛ وضعیت واقعی تا اقدام و ثبت نتیجه حفظ می‌شود.',$this->app->url('view=operations&account='.$a['account_id']));
                    $db->run('INSERT INTO assignment_alert_markers(assignment_id,version,event_type,identity_version) VALUES(?,?,?,?)',[$a['id'],$a['version'],$type,$identityVersion]); $counts['action_alerts']++;
                }
            }
            return $counts;
        });
    }
}
