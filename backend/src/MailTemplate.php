<?php
declare(strict_types=1);
namespace AccManager;

/** Presentation only: queued source text, permission metadata and delivery state stay unchanged. */
final class MailTemplate
{
    private const FONT='Tahoma, Arial, sans-serif';

    /** @return array{subject:string,html:string,text:string} */
    public static function render(array $payload,string $appUrl): array
    {
        $title=self::title((string)($payload['subject']??''));
        $body=str_replace(["\r\n","\r"],"\n",(string)($payload['body']??''));
        $lines=explode("\n",$body);
        $category=self::category($payload,$title);
        [$action,$actionLine,$actionKind]=self::action($lines,$category,$appUrl);
        $badge=match ($category) {'auth'=>'دسترسی به حساب','initial_password'=>'حساب کاربری','test'=>'آزمایش ایمیل',default=>'اعلان برنامه'};
        $cta=match ($actionKind) {'verify'=>'تأیید ایمیل سازمانی','reset'=>'انتخاب رمز عبور جدید','login'=>'ورود به برنامه',default=>'مشاهده و پیگیری در برنامه'};
        $preview=match ($actionKind) {
            'verify'=>'برای ادامهٔ ثبت‌نام، ایمیل سازمانی خود را تأیید کنید.',
            'reset'=>'لینک یک‌بارمصرف برای انتخاب رمز عبور جدید آماده است.',
            'login'=>'حساب شما آماده است؛ اطلاعات ورود و اقدام بعدی را ببینید.',
            default=>$category==='test'?'این پیام برای بررسی دریافت و خوانایی ایمیل‌های برنامه است.':'جزئیات این اعلان و اقدام بعدی را در برنامه ببینید.',
        };
        $paragraphs=[];$plain=[];
        foreach ($lines as $i=>$line) {
            $line=trim($line);
            if($line===''||($i===0&&$line===$title)||$i===$actionLine)continue;
            $line=self::readableExpiry($line,$category);
            $plain[]=$line;
            if($category==='initial_password'&&preg_match('/\Aرمز اولیه:\s*(.+)\z/u',$line,$m)) {
                $paragraphs[]='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;"><tr><td class="email-soft" style="padding:16px 18px;background:#f3f6fc;border:1px solid #dce3ef;border-radius:10px;"><div class="email-muted" style="font-size:13px;color:#58667c;margin-bottom:7px;">رمز اولیهٔ ورود</div><div dir="ltr" style="direction:ltr;text-align:center;font-family:Consolas,monospace;font-size:18px;font-weight:bold;letter-spacing:1px;overflow-wrap:anywhere;word-break:break-all;">'.self::escape($m[1]).'</div></td></tr></table>';
            } else {
                $paragraphs[]='<p style="margin:0 0 12px;font-size:16px;line-height:1.95;">'.self::inline($line).'</p>';
            }
        }
        if(!$paragraphs) {
            $plain[]=$category==='test'?'این پیام برای بررسی دریافت ایمیل ارسال شده است.':'جزئیات را از داخل برنامه بررسی کنید.';
            $paragraphs[]='<p style="margin:0 0 12px;font-size:16px;line-height:1.95;">'.self::escape(end($plain)).'</p>';
        }
        $button='';$fallback='';
        if($action!==null) {
            $button='<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 18px;"><tr><td align="center" bgcolor="#365fd6" style="background:#365fd6;border-radius:9px;"><a href="'.self::escape($action).'" style="display:inline-block;padding:12px 24px;border:1px solid #365fd6;border-radius:9px;font-family:'.self::FONT.';font-size:15px;line-height:1.7;font-weight:bold;text-decoration:none;color:#ffffff;">'.self::escape($cta).'</a></td></tr></table>';
            $fallback='<p class="email-muted" style="margin:18px 0 7px;color:#58667c;font-size:12px;line-height:1.8;">اگر دکمه باز نشد، این نشانی را در مرورگر کپی کنید:</p><p dir="ltr" style="direction:ltr;text-align:left;overflow-wrap:anywhere;word-break:break-all;margin:0;font-family:Arial,sans-serif;font-size:12px;line-height:1.7;"><a class="email-link" href="'.self::escape($action).'" style="color:#365fd6;text-decoration:underline;">'.self::escape($action).'</a></p>';
        }
        $security=$category==='initial_password'?'<p class="email-muted" style="margin:16px 0 0;font-size:13px;line-height:1.9;color:#58667c;">رمز اولیه را محرمانه نگه دارید. در صورت تمایل، پس از ورود آن را از منوی حساب کاربری تغییر دهید.</p>':'';
        $site=self::safeUrl(rtrim($appUrl,'/').'/',$appUrl);
        $brand=$site===null?'Seat Manager':'<a href="'.self::escape($site).'" style="color:inherit;text-decoration:none;">Seat Manager</a>';
        $html='<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>'.self::escape($title).'</title><style>@media (prefers-color-scheme:dark){.email-bg{background:#171b24!important}.email-card{background:#222835!important;color:#f2f5fc!important;border-color:#414c61!important}.email-muted{color:#bac5d9!important}.email-soft{background:#2a3448!important;border-color:#485574!important}.email-footer{color:#bac5d9!important}.email-link{color:#a9c0ff!important}}@media screen and (max-width:480px){.email-padding{padding:25px 20px!important}.email-title{font-size:21px!important}}</style></head>'
            .'<body class="email-bg" style="margin:0;padding:0;background:#f1f4f9;color:#202b3d;font-family:'.self::FONT.';-webkit-text-size-adjust:100%;"><div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'.self::escape($preview).'</div>'
            .'<table role="presentation" class="email-bg" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="background:#f1f4f9;"><tr><td align="center" style="padding:28px 12px;"><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">'
            .'<tr><td class="email-muted" style="padding:0 4px 16px;color:#58667c;font-size:14px;font-weight:bold;direction:ltr;text-align:left;">'.$brand.'</td></tr><tr><td class="email-card" style="background:#ffffff;color:#202b3d;border:1px solid #dce3ef;border-radius:14px;overflow:hidden;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td width="24%" height="4" bgcolor="#ee987e" style="height:4px;background:#ee987e;font-size:0;line-height:0;">&nbsp;</td><td height="4" bgcolor="#7197ef" style="height:4px;background:#7197ef;font-size:0;line-height:0;">&nbsp;</td></tr></table>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="email-padding" style="padding:30px 34px;text-align:right;direction:rtl;font-family:'.self::FONT.';"><p class="email-muted" style="margin:0 0 8px;color:#58667c;font-size:12px;line-height:1.8;">'.self::escape($badge).'</p><h1 class="email-title" style="margin:0 0 22px;font-size:24px;line-height:1.7;font-weight:bold;">'.self::escape($title).'</h1>'.implode('',$paragraphs).$button.$security.$fallback.'</td></tr></table></td></tr>'
            .'<tr><td class="email-footer" style="padding:18px 8px 0;text-align:center;direction:rtl;color:#58667c;font-family:'.self::FONT.';font-size:12px;line-height:1.9;">این پیام به‌صورت خودکار از <span dir="ltr">Seat Manager</span> ارسال شده است.<br>برای پیگیری، از داخل برنامه اقدام کنید.</td></tr></table></td></tr></table></body></html>';
        $text=$title."\n\n".implode("\n\n",$plain);
        if($action!==null)$text.="\n\n".$cta.":\n".$action;
        if($category==='initial_password')$text.="\n\nرمز اولیه را محرمانه نگه دارید. در صورت تمایل، پس از ورود آن را از منوی حساب کاربری تغییر دهید.";
        $text.="\n\n—\nSeat Manager\nاین پیام به‌صورت خودکار ارسال شده است. برای پیگیری، از داخل برنامه اقدام کنید.";
        return ['subject'=>'[Seat Manager] '.$title,'html'=>$html,'text'=>$text];
    }

    private static function title(string $title): string
    {
        $title=trim((string)preg_replace('/[\x00-\x1f\x7f\x{202a}-\x{202e}\x{2066}-\x{2069}]+/u',' ',$title));
        do{$before=$title;$title=trim((string)preg_replace('/\A(?:\[\s*Seat\s+Manager\s*\]|Seat\s+Manager\s*[|:\x{2013}\x{2014}-])\s*/iu','',$title));}while($title!==$before);
        return $title===''?'پیام سامانه':mb_substr($title,0,180);
    }
    private static function category(array $payload,string $title): string
    {
        if(in_array($payload['category']??null,['auth','initial_password','event','test'],true))return $payload['category'];
        // Older encrypted queue entries contain only recipient, subject and body.
        return match($title){'تأیید ایمیل سازمانی','بازیابی رمز عبور'=>'auth','حساب برنامه ساخته شد','حساب مدیر کل ساخته شد'=>'initial_password','آزمایش ارسال ایمیل'=>'test',default=>'event'};
    }
    /** @return array{0:?string,1:?int,2:string} */
    private static function action(array $lines,string $category,string $appUrl): array
    {
        if($category==='test')return [null,null,''];
        if($category==='initial_password') {
            foreach($lines as $i=>$line)if(preg_match('/\Aآدرس ورود:\s*(\S+)\s*\z/u',trim($line),$m)&&($url=self::safeUrl($m[1],$appUrl))!==null&&empty(parse_url($url,PHP_URL_FRAGMENT)))return [$url,$i,'login'];
            return [null,null,''];
        }
        if($category==='auth') {
            foreach($lines as $i=>$line) {
                $url=self::safeUrl(trim($line),$appUrl);if($url===null)continue;
                parse_str((string)parse_url($url,PHP_URL_FRAGMENT),$args);
                if(count($args)===2&&in_array($args['action']??null,['verify','reset'],true)&&is_string($args['token']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$args['token']))return [$url,$i,$args['action']];
            }
            return [null,null,''];
        }
        // Event producers append their controlled action URL on the last line.
        // Never turn an arbitrary URL inside an employee's reason into an action.
        for($i=count($lines)-1;$i>=0;$i--) {
            $line=trim($lines[$i]);if($line==='')continue;
            $url=self::safeUrl($line,$appUrl);if($url===null)return [null,null,''];
            $fragment=(string)parse_url($url,PHP_URL_FRAGMENT);parse_str($fragment,$args);
            if($fragment===''||(is_string($args['view']??null)&&preg_match('/\A[a-z][a-z0-9-]{0,39}\z/D',$args['view'])))return [$url,$i,$fragment===''?'login':'event'];
            return [null,null,''];
        }
        return [null,null,''];
    }
    private static function safeUrl(string $url,string $appUrl): ?string
    {
        if($url===''||preg_match('/[\x00-\x20\x7f<>"\'\\\\]/u',$url))return null;
        $base=parse_url($appUrl);$p=parse_url($url);
        if(!is_array($base)||!is_array($p)||!isset($base['scheme'],$base['host'],$p['scheme'],$p['host'])||!in_array(strtolower($p['scheme']),['https','http'],true)||strtolower($p['scheme'])!==strtolower($base['scheme'])||strtolower($p['host'])!==strtolower($base['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query']))return null;
        $port=static fn(array $v)=>(int)($v['port']??(strtolower($v['scheme'])==='https'?443:80));
        $basePath=rtrim($base['path']??'','/');
        if($port($p)!==$port($base)||!in_array($p['path']??'',[$basePath,$basePath.'/'],true))return null;
        return $url;
    }
    private static function readableExpiry(string $line,string $category): string
    {
        if($category!=='auth'||!preg_match('/\Aاین لینک یک‌بارمصرف است و در (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC منقضی می‌شود\.\z/u',$line,$m))return $line;
        $time=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$m[1],new \DateTimeZone('UTC'));
        if(!$time||$time->format('Y-m-d H:i:s')!==$m[1])return $line;
        $date=$time->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y/m/d، ساعت H:i');
        return 'این لینک یک‌بارمصرف است و تا '.strtr($date,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']).' به وقت ایران اعتبار دارد (تاریخ میلادی).';
    }
    private static function escape(string $text): string {return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function inline(string $text): string
    {
        $pattern='~((?:[A-Za-z0-9.!#$%&\x27*+/=?^_`{|}\~-]+@[A-Za-z0-9][A-Za-z0-9.-]*)|(?:https?://[^\s<>]+)|(?:\b(?:Standard|Premium|No Seat|Credits?|Seat Manager|UTC)\b))~u';
        $parts=preg_split($pattern,$text,-1,PREG_SPLIT_DELIM_CAPTURE);$out='';
        if($parts===false)return self::escape($text);
        foreach($parts as $i=>$part)$out.=$i%2?'<span dir="ltr" style="direction:ltr;unicode-bidi:isolate;display:inline-block;overflow-wrap:anywhere;">'.self::escape($part).'</span>':self::escape($part);
        return $out;
    }
}
