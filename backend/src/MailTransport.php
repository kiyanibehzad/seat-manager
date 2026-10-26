<?php
declare(strict_types=1);
namespace AccManager;
use PHPMailer\PHPMailer\PHPMailer;
final class MailTransport
{
    public bool $dataAttempted=false;
    public function __construct(private App $app) {}
    public function assertAllowed(array $settings): void
    {
        if ($this->app->config['environment']!=='production') {
            if ($settings['transport']!=='smtp'||$settings['smtp_host']!=='127.0.0.1'||(int)$settings['smtp_port']!==10279) { throw new ApiError(422,'mail_transport_restricted','در محیط آزمایش فقط گیرندهٔ محلی ایمیل مجاز است.'); }
        } elseif ($settings['transport']==='smtp'&&!in_array($settings['smtp_encryption'],['tls','ssl'],true)) { throw new ApiError(422,'mail_transport_restricted','اتصال SMTP باید TLS یا SSL داشته باشد.'); }
    }
    private function mailer(array $s,?TrackedSmtp $transport=null): PHPMailer
    {
        $this->assertAllowed($s); $mailer=new PHPMailer(true); $mailer->SMTPDebug=0;
        $mailer->Timeout=min(15,max(1,(int)($this->app->config['smtp']['timeout_seconds']??10)));
        if ($s['transport']==='smtp') {
            $transport??=new TrackedSmtp(); $mailer->isSMTP(); $mailer->setSMTPInstance($transport); $transport->Timelimit=$mailer->Timeout;
            $mailer->Host=$s['smtp_host']; $mailer->Port=(int)$s['smtp_port'];
            $mailer->SMTPAuth=$s['smtp_username']!==''; $mailer->Username=$s['smtp_username']; $mailer->Password=$s['smtp_password'];
            $mailer->SMTPSecure=$s['smtp_encryption']; $mailer->SMTPAutoTLS=$s['smtp_encryption']!=='';
        } else { $mailer->isMail(); }
        $mailer->CharSet='UTF-8'; $mailer->setFrom($s['from_email'],$s['from_name']); $mailer->isHTML(false);
        return $mailer;
    }
    public function connection(array $s): array
    {
        $this->assertAllowed($s);
        if ($s['transport']==='phpmail') { return ['ok'=>function_exists('mail'),'code'=>function_exists('mail')?'phpmail_available':'phpmail_unavailable','message'=>function_exists('mail')?'تابع PHP mail در دسترس است؛ تحویل ایمیل تنها با ارسال آزمایشی مشخص می‌شود.':'تابع PHP mail روی این هاست در دسترس نیست.']; }
        $mailer=$this->mailer($s);
        try {
            if (!$mailer->smtpConnect()) { throw new \RuntimeException('Connection failed'); }
            return ['ok'=>true,'code'=>'smtp_connected','message'=>'اتصال و احراز هویت SMTP موفق بود؛ ایمیلی ارسال نشد.'];
        } catch (\Throwable $e) { return ['ok'=>false,'code'=>'smtp_connection_failed','message'=>'اتصال SMTP برقرار نشد؛ میزبان، درگاه، نوع رمزنگاری و اطلاعات ورود را بررسی کنید.']; }
        finally { $mailer->smtpClose(); }
    }
    public function send(array $s,array $payload,string $messageId): void
    {
        $transport=new TrackedSmtp(); $mailer=$this->mailer($s,$transport);
        try {
            $mailer->addAddress($payload['to']); $mailer->MessageID=$messageId; $mailer->Subject=$payload['subject']; $mailer->Body=$payload['body'];
            // PHP mail has no reliable acknowledgement boundary. Treat any send failure as uncertain.
            if ($s['transport']==='phpmail') { $this->dataAttempted=true; }
            $mailer->send();
        } finally { $this->dataAttempted=$this->dataAttempted||$transport->dataAttempted; $mailer->smtpClose(); }
    }
}
