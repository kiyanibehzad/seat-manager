<?php
declare(strict_types=1);
// Pure presentation and MIME tests. No App boot, database, network or delivery.
ini_set('display_errors','0');ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__,2).'/backend/vendor/autoload.php';
use AccManager\MailTemplate;
use PHPMailer\PHPMailer\PHPMailer;
set_error_handler(static function(int $severity,string $message,string $file,int $line): never {throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function mtCheck(bool $condition,string $label): void {global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$label);$checks++;}
function mtLinks(string $html): array {
    $doc=new DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML($html,LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);
    $links=[];foreach($doc->getElementsByTagName('a')as$a)$links[]=$a->getAttribute('href');return $links;
}
function mtRender(string $subject,string $body,?string $category=null,string $base='https://seat.example.test'): array {
    $p=['to'=>'sara@example.test','subject'=>$subject,'body'=>$body];if($category!==null)$p['category']=$category;return MailTemplate::render($p,$base);
}
try {
    $token=str_repeat('a',64);$verifyUrl='https://seat.example.test/#action=verify&token='.$token;
    $verifyBody="تأیید ایمیل سازمانی\n".$verifyUrl."\nاین لینک یک‌بارمصرف است و در 2026-10-30 13:00:00 UTC منقضی می‌شود.\nاگر این درخواست را نداده‌اید، آن را نادیده بگیرید.";
    $verify=mtRender('تأیید ایمیل سازمانی',$verifyBody,'auth');
    mtCheck($verify['subject']==='[Seat Manager] تأیید ایمیل سازمانی','single informative subject prefix');
    mtCheck(str_contains($verify['html'],'lang="fa" dir="rtl"')&&str_contains($verify['html'],'role="presentation"'),'Persian direction and email table layout');
    mtCheck(substr_count($verify['html'],'href="'.htmlspecialchars($verifyUrl,ENT_QUOTES,'UTF-8').'"')===2,'verified action is a button and copyable link');
    mtCheck(in_array($verifyUrl,mtLinks($verify['html']),true)&&str_contains($verify['text'],$verifyUrl),'auth token preserved exactly in both alternatives');
    mtCheck(str_contains($verify['text'],'۱۶:۳۰')&&str_contains($verify['text'],'تاریخ میلادی')&&str_contains($verify['text'],'به وقت ایران'),'expiry has correct Iran time and explicit calendar');
    mtCheck(str_contains($verify['html'],'اگر این درخواست را نداده‌اید')&&str_contains($verify['text'],'یک‌بارمصرف'),'security and one-use text kept');
    mtCheck(substr_count($verify['text'],'تأیید ایمیل سازمانی')===2,'title not redundantly repeated in message paragraphs');
    mtCheck(!str_contains($verify['html'],'<img')&&!str_contains($verify['html'],'@import')&&!str_contains($verify['html'],'@font-face'),'no remote images, fonts or tracking assets');
    mtCheck(str_contains($verify['html'],'prefers-color-scheme:dark')&&str_contains($verify['html'],'max-width:600px'),'dark appearance and responsive bounded width');
    $resetUrl='https://seat.example.test/#action=reset&token='.str_repeat('b',64);
    $reset=mtRender('بازیابی رمز عبور',"بازیابی رمز عبور\n".$resetUrl."\nاین لینک یک‌بارمصرف است و در 2026-10-30 13:00:00 UTC منقضی می‌شود.",'auth');
    mtCheck(str_contains($reset['html'],'انتخاب رمز عبور جدید')&&in_array($resetUrl,mtLinks($reset['html']),true),'reset has distinct action and unchanged token');
    $password='sample-ONLY-not-a-real-password';
    $initial=mtRender('حساب برنامه ساخته شد',"حساب برنامه برای شما ساخته شد.\nآدرس ورود: https://seat.example.test/\nرمز اولیه: ".$password."\nمی‌توانید از بخش حساب، رمز را تغییر دهید.",'initial_password');
    mtCheck(str_contains($initial['html'],'رمز اولیهٔ ورود')&&str_contains($initial['html'],$password)&&str_contains($initial['text'],$password),'initial password retained in distinct readable block');
    mtCheck(!str_contains($initial['subject'],$password)&&preg_match('~<div style="display:none[^>]*>[^<]*حساب شما آماده[^<]*</div>~u',$initial['html'])===1,'secrets excluded from subject and inbox preheader');
    mtCheck(str_contains($initial['html'],'در صورت تمایل')&&str_contains($initial['text'],'محرمانه'),'password guidance matches optional-change policy');
    $eventUrl='https://seat.example.test/#view=my-requests&request=12';
    $event=mtRender('درخواست شما تکمیل شد',"نتیجهٔ حساب sara.work@example.test از Standard به Premium ثبت شد.\n".$eventUrl,'event');
    mtCheck(str_contains($event['html'],'dir="ltr"')&&str_contains($event['html'],'sara.work@example.test'),'mixed account identity remains readable');
    mtCheck(in_array($eventUrl,mtLinks($event['html']),true)&&str_contains($event['html'],'مشاهده و پیگیری در برنامه'),'event has controlled action URL');
    $credit=mtRender('درخواست اعتبار بیشتر',"سارا رضایی برای حساب sara.work@example.test به ۵۰۰ Credits بیشتر نیاز دارد.\n".'https://seat.example.test/#view=operations&credit=23','event');
    mtCheck(str_contains($credit['html'],'۵۰۰')&&str_contains($credit['text'],'Credits')&&str_contains($credit['html'],'credit=23'),'credit notification generic presentation without seat assumptions');
    $creditPersonalUrl='https://seat.example.test/#view=my-requests&tab=credits&credit=23';
    $creditStarted=mtRender('درخواست اعتبار در حال انجام است',"درخواست حساب sara.work@example.test برای ۳۰۰ Credits تأیید اولیه شد؛ افزایش سقف هنوز در انتظار اقدام و بررسی مدیر است.\n".$creditPersonalUrl,'event');
    mtCheck(str_contains($creditStarted['text'],'۳۰۰ Credits')&&str_contains($creditStarted['text'],'هنوز در انتظار')&&in_array($creditPersonalUrl,mtLinks($creditStarted['html']),true),'approved credit amount keeps pending external action explicit');
    $creditCompleted=mtRender('سقف اعتبار ماهانهٔ حساب افزایش یافت',"سقف ماهانهٔ حساب sara.work@example.test از ۵۰۰ به ۸۰۰ Credits افزایش یافت.\nاین مقدار، سقف مجاز مصرف است و به معنی مصرف‌شدن اعتبار نیست.\n".$creditPersonalUrl,'event');
    mtCheck(str_contains($creditCompleted['html'],'۵۰۰ به ۸۰۰')&&str_contains($creditCompleted['text'],'به معنی مصرف‌شدن اعتبار نیست'),'completed credit message distinguishes verified cap from actual consumption');
    $creditRejected=mtRender('درخواست اعتبار رد شد',"درخواست ۵۰۰ Credits برای حساب sara.work@example.test رد شد.\nدلیل: ظرفیت اعتبار این دوره کافی نیست؛ درخواست <جدید> پس از بررسی ثبت شود.\n".$creditPersonalUrl,'event');
    mtCheck(str_contains($creditRejected['text'],'۵۰۰ Credits')&&str_contains($creditRejected['text'],'ظرفیت اعتبار')&&str_contains($creditRejected['html'],'&lt;جدید&gt;'),'rejected credit amount and reason retained and escaped');
    $test=mtRender('آزمایش ارسال ایمیل','این یک پیام آزمایشی است.','test');
    mtCheck(!str_contains($test['html'],'مشاهده و پیگیری در برنامه')&&str_contains($test['html'],'آزمایش ایمیل'),'test email has no fake account action');
    $empty=mtRender('','');mtCheck(str_contains($empty['text'],'جزئیات را از داخل برنامه بررسی کنید.')&&$empty['subject']==='[Seat Manager] پیام سامانه','empty body safe fallback covers both versions');
    $prefixed=mtRender("Seat Manager | [Seat Manager] عنوان\r\nBcc: other@example.test",'متن');
    mtCheck(substr_count($prefixed['subject'],'Seat Manager')===1&&!str_contains($prefixed['subject'],"\r")&&!str_contains($prefixed['subject'],"\n"),'legacy duplicate prefixes and header newlines normalized');
    $unsafe=mtRender('<script>alert(1)</script>',"نام <img src=x onerror=alert(1)> و متن & \"نمونه\"\nhttps://evil.example/#view=users",'event');
    mtCheck(!str_contains($unsafe['html'],'<script>')&&!str_contains($unsafe['html'],'<img src=x')&&str_contains($unsafe['html'],'&lt;script&gt;'),'user title and body escaped');
    mtCheck(!in_array('https://evil.example/#view=users',mtLinks($unsafe['html']),true),'external reason URL never becomes an action');
    foreach([
        'https://seat.example.test.evil.example/#view=users',
        'https://seat.example.test@evil.example/#view=users',
        'https://attacker@seat.example.test/#view=users',
        'https://seat.example.test:444/#view=users',
        'http://seat.example.test/#view=users',
        '//seat.example.test/#view=users',
        'https://seat.example.test/other/#view=users',
        'https://seat.example.test/?action=delete',
        'https://seat.example.test/\\evil/#view=users',
        'javascript:alert(1)',
        'https://seat.example.test/#action=reset&token='.$token,
        'https://seat.example.test/"onclick="alert(1)',
    ]as$i=>$url) {
        $r=mtRender('اعلان',"متن\n".$url,'event');mtCheck(mtLinks($r['html'])===['https://seat.example.test/'],'unsafe action rejected '.$i);
    }
    $subUrl='https://seat.example.test/tools/seat/#view=my-requests&credit=24';
    $sub=mtRender('اعلان',"متن\n".$subUrl,'event','https://seat.example.test/tools/seat');
    mtCheck(in_array($subUrl,mtLinks($sub['html']),true),'subfolder installation action works');
    $outside=mtRender('اعلان',"متن\nhttps://seat.example.test/#view=users",'event','https://seat.example.test/tools/seat');
    mtCheck(mtLinks($outside['html'])===['https://seat.example.test/tools/seat/'],'same-origin outside installation is not an action');
    $embedded=mtRender('اعلان',"https://seat.example.test/#view=users\nاین یک دلیل کاربر است.",'event');
    mtCheck(mtLinks($embedded['html'])===['https://seat.example.test/'],'embedded internal reason URL is not promoted to a button');
    $encoded=mtRender('تأیید ایمیل سازمانی',"https://seat.example.test/#action=verify&token%5B%5D=".$token,'auth');
    mtCheck(mtLinks($encoded['html'])===['https://seat.example.test/'],'array-shaped token rejected');
    $invalidExpiry=mtRender('تأیید ایمیل سازمانی',"این لینک یک‌بارمصرف است و در 2026-99-99 13:00:00 UTC منقضی می‌شود.",'auth');
    mtCheck(str_contains($invalidExpiry['text'],'2026-99-99'),'invalid legacy expiry kept rather than invented');
    foreach([
        ['تأیید ایمیل سازمانی',$verifyBody,$verify],
        ['بازیابی رمز عبور',"بازیابی رمز عبور\n".$resetUrl."\nاین لینک یک‌بارمصرف است و در 2026-10-30 13:00:00 UTC منقضی می‌شود.",$reset],
        ['حساب برنامه ساخته شد',"حساب برنامه برای شما ساخته شد.\nآدرس ورود: https://seat.example.test/\nرمز اولیه: ".$password."\nمی‌توانید از بخش حساب، رمز را تغییر دهید.",$initial],
        ['درخواست شما تکمیل شد',"نتیجهٔ حساب sara.work@example.test از Standard به Premium ثبت شد.\n".$eventUrl,$event],
        ['آزمایش ارسال ایمیل','این یک پیام آزمایشی است.',$test],
    ]as$i=>[$subject,$body,$expected]) {
        $legacy=mtRender($subject,$body);mtCheck($legacy===$expected,'old encrypted payload remains readable '.$i);
    }
    $source=['to'=>'sara@example.test','subject'=>'عنوان','body'=>'متن اصلی token=unchanged','required_permission'=>'seats.manage'];$copy=$source;MailTemplate::render($source,'https://seat.example.test');
    mtCheck($source===$copy,'raw fields used by permission rechecks and token readers never mutated');
    $m=new PHPMailer(true);$m->isSMTP();$m->CharSet='UTF-8';$m->Encoding='quoted-printable';$m->setFrom('noreply@example.test','Seat Manager');$m->addAddress('sara@example.test');$m->MessageID='<deterministic-test@example.test>';
    $m->Subject=$verify['subject'];$m->isHTML(true);$m->Body=$verify['html'];$m->AltBody=$verify['text'];
    mtCheck($m->preSend(),'MIME composed without delivery');$mime=$m->getSentMIMEMessage();
    mtCheck(str_contains($mime,'multipart/alternative')&&str_contains($mime,'Content-Type: text/plain; charset=UTF-8')&&str_contains($mime,'Content-Type: text/html; charset=UTF-8'),'both UTF-8 alternatives in MIME');
    mtCheck(str_contains($mime,'Message-ID: <deterministic-test@example.test>'),'existing deterministic message ID preserved');
    mtCheck(str_contains(quoted_printable_decode($mime),$verifyUrl)&&str_contains(quoted_printable_decode($mime),'تأیید ایمیل سازمانی'),'wire encoding preserves Persian and action token');
    mtCheck(!str_contains(explode("\r\n\r\n",$mime,2)[0],$token),'token not promoted into headers');
    $previews=['verify'=>$verify,'reset'=>$reset,'initial-password'=>$initial,'seat-result'=>$event,'credit-request'=>$credit,'credit-started'=>$creditStarted,'credit-completed'=>$creditCompleted,'credit-rejected'=>$creditRejected,'test'=>$test];
    foreach(array_slice($argv,1)as$arg)if(str_starts_with($arg,'--preview-dir=')) {
        $dir=substr($arg,14);if($dir===''||$dir[0]!=='/')throw new RuntimeException('Absolute private preview directory required');
        if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Preview directory unavailable');
        $index='<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>پیش‌نمایش ایمیل‌ها</title><body style="font:16px Tahoma;line-height:2;padding:24px"><h1>پیش‌نمایش ایمیل‌های Seat Manager</h1><p>همهٔ داده‌ها نمونه‌اند؛ هیچ ایمیلی ارسال نشده است.</p><ul>';
        foreach($previews as$name=>$p){file_put_contents($dir.'/'.$name.'.html',$p['html']);file_put_contents($dir.'/'.$name.'.txt',$p['text']);$index.='<li><a href="'.$name.'.html">'.htmlspecialchars($p['subject'],ENT_QUOTES,'UTF-8').'</a></li>';}
        file_put_contents($dir.'/index.html',$index.'</ul></body></html>');
    }
    echo "PASS: {$checks} email template and MIME checks; no database, network or delivery.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
