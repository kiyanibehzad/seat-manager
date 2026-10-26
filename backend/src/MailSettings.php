<?php
declare(strict_types=1);
namespace AccManager;
final class MailSettings
{
    public function __construct(private App $app) {}
    public function ready(): bool
    {
        return $this->app->db->one("SELECT version FROM schema_migrations WHERE version='004_mail_settings.sql'")!==null;
    }
    private function requireReady(): void
    { if (!$this->ready()) { throw new ApiError(503,'migration_required','تنظیمات ایمیل پس از به‌روزرسانی پایگاه داده در دسترس است.'); } }
    public function current(): array
    {
        $s=$this->app->config['smtp']; $ready=$this->ready();
        $defaults=['delivery_enabled'=>$ready?false:(bool)$s['enabled'],'transport'=>'smtp','from_email'=>$s['from_email'],'from_name'=>$s['from_name'],'smtp_host'=>$s['host'],'smtp_port'=>(int)$s['port'],'smtp_encryption'=>$s['encryption'],'smtp_username'=>$s['username'],'smtp_password'=>$s['password'],'revision'=>0];
        if (!$ready) { return $defaults; }
        $row=$this->app->db->one('SELECT settings_encrypted,revision FROM mail_settings WHERE id=1');
        return array_replace($defaults,$row['settings_encrypted']===null?[]:$this->app->crypto->decrypt($row['settings_encrypted']),['revision'=>(int)$row['revision']]);
    }
    private function guarded(array $actor,callable $callback): mixed
    {
        $this->requireReady(); $this->app->accessGroups->requireReady();
        return $this->app->db->transaction(function () use ($actor,$callback) {
            $this->app->db->one("SELECT value_json FROM settings WHERE setting_key='identity_guard' FOR UPDATE");
            $fresh=$this->app->db->one('SELECT * FROM users WHERE id=? FOR UPDATE',[$actor['id']]);
            if (!$fresh||!$this->app->auth->eligible($fresh)||(int)$fresh['auth_version']!==(int)$actor['auth_version']||!$this->app->accessGroups->isChief($fresh)) { throw new ApiError(403,'forbidden','این بخش فقط در اختیار مدیر کل فعال است.'); }
            return $callback($fresh);
        });
    }
    private function safe(): array
    { $s=$this->current(); $s['password_configured']=$s['smtp_password']!==''; unset($s['smtp_password']); return $s; }
    public function view(array $actor): array
    { return $this->guarded($actor,fn()=>['settings'=>$this->safe(),'status'=>$this->status()]); }
    public function save(array $data,array $actor): array
    {
        Input::only($data,['delivery_enabled','transport','from_email','from_name','smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','clear_password','expected_revision']);
        if (!isset($data['expected_revision'])||!is_int($data['expected_revision'])||$data['expected_revision']<0) { Input::invalid('expected_revision'); }
        return $this->guarded($actor,function ($fresh) use ($data) {
            $this->app->db->one('SELECT id FROM mail_settings WHERE id=1 FOR UPDATE'); $s=$this->current();
            if ($data['expected_revision']!==$s['revision']) { throw new ApiError(409,'conflict','تنظیمات تغییر کرده است؛ صفحه را تازه کنید.'); }
            foreach (['delivery_enabled','clear_password'] as $key) { if (array_key_exists($key,$data)) { Input::boolean($data,$key); } }
            if (array_key_exists('transport',$data)&&!in_array($data['transport'],['smtp','phpmail'],true)) { Input::invalid('transport'); }
            if (array_key_exists('smtp_encryption',$data)&&!in_array($data['smtp_encryption'],['tls','ssl',''],true)) { Input::invalid('smtp_encryption'); }
            if (array_key_exists('from_email',$data)) { $data['from_email']=Input::email(['email'=>$data['from_email']]); }
            if (array_key_exists('from_name',$data)) { $data['from_name']=Input::text($data,'from_name',120); }
            foreach (['smtp_host'=>253,'smtp_username'=>254,'smtp_password'=>1024] as $key=>$max) {
                if (array_key_exists($key,$data)&&(!is_string($data[$key])||!mb_check_encoding($data[$key],'UTF-8')||mb_strlen($data[$key])>$max||preg_match('/[\x00-\x1F\x7F]/u',$data[$key]))) { Input::invalid($key); }
            }
            if (isset($data['smtp_host'])&&$data['smtp_host']!==''&&!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.\-]*[a-zA-Z0-9])?$/D',$data['smtp_host'])) { Input::invalid('smtp_host'); }
            if (array_key_exists('smtp_port',$data)&&(!is_int($data['smtp_port'])||$data['smtp_port']<1||$data['smtp_port']>65535)) { Input::invalid('smtp_port'); }
            if (($data['clear_password']??false)&&($data['smtp_password']??'')!=='') { Input::invalid('clear_password'); }
            $before=['delivery_enabled'=>$s['delivery_enabled'],'transport'=>$s['transport'],'password_configured'=>$s['smtp_password']!==''];
            if (($data['smtp_password']??'')==='') { unset($data['smtp_password']); }
            $s=array_replace($s,array_diff_key($data,array_flip(['clear_password','expected_revision'])));
            if ($data['clear_password']??false) { $s['smtp_password']=''; }
            if ($s['delivery_enabled']&&$s['transport']==='smtp'&&$s['smtp_host']==='') { Input::invalid('smtp_host'); }
            // A dormant SMTP host may remain blank; any configured active transport must obey environment restrictions.
            if ($s['transport']==='phpmail'||$s['smtp_host']!==''||$s['delivery_enabled']) { (new MailTransport($this->app))->assertAllowed($s); }
            $s['revision']++;
            $this->app->db->run('UPDATE mail_settings SET settings_encrypted=?,revision=?,updated_at=UTC_TIMESTAMP() WHERE id=1',[$this->app->crypto->encrypt($s),$s['revision']]);
            $this->app->audit('mail.settings_updated',(int)$fresh['id'],null,['before'=>$before,'after'=>['delivery_enabled'=>$s['delivery_enabled'],'transport'=>$s['transport'],'password_configured'=>$s['smtp_password']!=='']]);
            return ['settings'=>$this->safe(),'status'=>$this->status()];
        });
    }
    public function connection(array $data,array $actor): array
    {
        Input::only($data,[]); $s=$this->guarded($actor,function ($fresh) {
            (new RateLimiter($this->app))->consume('mail.connection',(string)$fresh['id'],5);
            $this->app->audit('mail.connection_test',(int)$fresh['id'],null); return $this->current();
        });
        return (new MailTransport($this->app))->connection($s);
    }
    public function sendTest(array $data,array $actor): array
    {
        Input::only($data,['recipient','idempotency_key']); $to=Input::email(['email'=>$data['recipient']??null]);
        $key=Input::text($data,'idempotency_key',100); if (!preg_match('/^[a-zA-Z0-9_.:\-]{8,100}$/D',$key)) { Input::invalid('idempotency_key'); }
        $hash=hash('sha256','mail-test:'.$actor['id'].':'.$key);
        $result=$this->guarded($actor,function ($fresh) use ($to,$hash,$key) {
            $old=$this->app->db->one('SELECT id,payload_encrypted,status FROM mail_outbox WHERE idempotency_key=?',[$hash]);
            if ($old) {
                // Store a hash of the recipient separately from the disposable mail body for replay validation.
                $command=$this->app->db->one('SELECT request_hash FROM mail_test_commands WHERE idempotency_key=?',[$hash]);
                if (!$command||!hash_equals($command['request_hash'],hash('sha256',$to))) { throw new ApiError(409,'conflict','کلید آزمایش قبلاً با گیرندهٔ دیگری استفاده شده است.'); }
                return ['id'=>(int)$old['id'],'new'=>false];
            }
            (new MailTransport($this->app))->assertAllowed($this->current());
            (new RateLimiter($this->app))->consume('mail.send_test',(string)$fresh['id'],5);
            $id=$this->app->mail->enqueue((int)$fresh['id'],'mail-test:'.$fresh['id'].':'.$key,$to,'آزمایش ارسال ایمیل','این یک پیام آزمایشی از مدیر سیت است. هیچ رمز عبور یا لینک ورود در آن وجود ندارد.',null,'test','settings.manage');
            $this->app->db->run('INSERT INTO mail_test_commands(idempotency_key,request_hash) VALUES(?,?)',[$hash,hash('sha256',$to)]);
            $this->app->audit('mail.test_requested',(int)$fresh['id'],null,['after'=>['outbox_id'=>$id]]);
            return ['id'=>$id,'new'=>true];
        });
        if ($result['new']) { (new MailWorker($this->app))->run(1,20,$result['id']); }
        $row=$this->app->db->one('SELECT id,status,last_error_code FROM mail_outbox WHERE id=?',[$result['id']]);
        return ['id'=>(int)$row['id'],'status'=>$row['status'],'code'=>$row['last_error_code'],'message'=>self::message($row['last_error_code']??($row['status']==='sent'?'sent':'pending'))];
    }
    public function retry(array $data,array $actor): array
    {
        Input::only($data,['id']); $id=Input::id($data,'id');
        return $this->guarded($actor,function ($fresh) use ($id) {
            $row=$this->app->db->one('SELECT * FROM mail_outbox WHERE id=? FOR UPDATE',[$id]);
            if (!$row||!$this->retryable($row)) { throw new ApiError(409,'conflict','ارسال دوبارهٔ این پیام ایمن یا ممکن نیست.'); }
            if ($row['auth_token_id']!==null&&!$this->app->db->one('SELECT id FROM auth_tokens WHERE id=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE',[$row['auth_token_id']])) { throw new ApiError(409,'conflict','اعتبار پیام پایان یافته است؛ از مسیر بازیابی یا تأیید، پیام جدید بخواهید.'); }
            $this->app->db->run("UPDATE mail_outbox SET status='pending',available_at=UTC_TIMESTAMP(),last_error_code=NULL,attempts=0 WHERE id=?",[$id]);
            $this->app->audit('mail.retry_requested',(int)$fresh['id'],null,['after'=>['outbox_id'=>$id]]); return ['id'=>$id,'status'=>'pending'];
        });
    }
    private function retryable(array $row): bool
    { return $row['payload_encrypted']!==null&&(in_array($row['status'],['pending','retry'],true)||($row['status']==='failed'&&$row['last_error_code']==='smtp_pre_delivery_failure')); }
    public function status(): array
    {
        $db=$this->app->db; $counts=array_fill_keys(['pending','sending','retry','sent','failed','uncertain','cancelled'],0);
        foreach ($db->all('SELECT status,COUNT(*) n FROM mail_outbox GROUP BY status') as $row) { $counts[$row['status']]=(int)$row['n']; }
        $items=$db->all('SELECT id,category,status,attempts,created_at,available_at,claimed_at,sent_at,last_error_code,payload_encrypted IS NOT NULL has_payload,auth_token_id FROM mail_outbox ORDER BY id DESC LIMIT 20');
        foreach ($items as &$row) {
            $row['id']=(int)$row['id']; $row['attempts']=(int)$row['attempts']; $row['error_code']=$row['last_error_code']; $row['error_message']=$row['error_code']===null?null:self::message($row['error_code']);
            $row['payload_encrypted']=$row['has_payload']?'present':null; $row['retryable']=$this->retryable($row);
            if ($row['retryable']&&$row['auth_token_id']!==null) { $row['retryable']=$db->one('SELECT id FROM auth_tokens WHERE id=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP()',[$row['auth_token_id']])!==null; }
            unset($row['last_error_code'],$row['has_payload'],$row['payload_encrypted'],$row['auth_token_id']);
        } unset($row);
        $state=$db->one('SELECT * FROM mail_service_state WHERE id=1'); unset($state['id']);
        $cronReady=$state['cron_last_error_code']===null&&$state['cron_last_finished_at']!==null&&strtotime($state['cron_last_finished_at'].' UTC')>=time()-300;
        $s=$this->current(); return ['counts'=>$counts,'items'=>$items,'last_success_at'=>$db->one('SELECT MAX(sent_at) t FROM mail_outbox')['t']]+$state+['cron_ready'=>$cronReady,'cron_interval_minutes'=>1,'delivery_ready'=>$s['delivery_enabled']&&$cronReady];
    }
    public static function message(string $code): string
    {
        return match ($code) {
            'sent'=>'پیام به سرویس ایمیل تحویل داده شد؛ دریافت در صندوق مقصد باید بررسی شود.',
            'pending'=>'پیام در صف است؛ پس از آماده بودن سرویس ارسال بررسی می‌شود.',
            'smtp_pre_delivery_failure'=>'اتصال یا احراز هویت SMTP ناموفق بود؛ تنظیمات ایمیل را بررسی کنید.',
            'smtp_acceptance_unknown','phpmail_acceptance_unknown','worker_interrupted'=>'نتیجهٔ تحویل مشخص نیست؛ برای جلوگیری از ایمیل تکراری، ارسال خودکار دوباره متوقف است.',
            'auth_token_expired'=>'اعتبار لینک این پیام پایان یافته است؛ پیام جدید درخواست کنید.',
            'permission_revoked'=>'مجوز دریافت این پیام دیگر معتبر نیست.',
            'payload_unreadable'=>'محتوای رمزنگاری‌شده خوانده نشد؛ کلید خصوصی نصب را بررسی کنید.',
            'mail_configuration_invalid'=>'تنظیمات ارسال برای این محیط معتبر نیست.',
            default=>'ارسال انجام نشد؛ وضعیت سرویس ایمیل را بررسی کنید.',
        };
    }
}
