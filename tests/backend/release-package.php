<?php
declare(strict_types=1);
// Read-only archive verification: no extraction, configuration reads, database or SMTP.
if (PHP_SAPI!=='cli') { exit(1); }
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
try {
    $publicRelease=false; $archive='';
    foreach (array_slice($argv,1) as $argument) {
        if ($argument==='--public'&&!$publicRelease) { $publicRelease=true; }
        elseif (str_starts_with($argument,'--')||$archive!=='') { throw new RuntimeException('Usage: php tests/backend/release-package.php /absolute/release.zip [--public]'); }
        else { $archive=$argument; }
    }
    // Exact upstream v33.003 files: unknown or renamed private fonts are never public assets.
    $publicFontHashes=[
        'public_html/assets/fonts/vazirmatn/Vazirmatn-Regular.woff2'=>'e382101336c6eb32cfb31381c027d02d2e0354bad08f6a395d4088beb3db3d91',
        'public_html/assets/fonts/vazirmatn/Vazirmatn-Medium.woff2'=>'3333e31188a2b628db8780ca22fd5aad85bc083ccee9beb8d4d52db18cb98d48',
        'public_html/assets/fonts/vazirmatn/Vazirmatn-Bold.woff2'=>'836fae7d42d83faa249bc00e0099592be98a1fa260d22d82f269b6091e585627',
        'public_html/assets/fonts/vazirmatn/OFL.txt'=>'17e355067c8284f47743a1ee3b1ef7ff684ff0601eda357f9353b10b3016ab31',
        'public_html/assets/fonts/vazirmatn/AUTHORS.txt'=>'b57746a5f7002c0974c76c32af74079ff7ef1aaf8f35495e9409cfa1eb11e1ca',
    ];
    $publicFontFiles=array_filter(array_keys($publicFontHashes),static fn($name)=>str_ends_with($name,'.woff2'));
    $publicFontUses=[];
    $fontPath=static fn(string $path): bool=>(bool)preg_match('~(^|/)(fonts?|local-fonts)(/|$)|\.(ttf|otf|woff2?|eot|ttc|otc|dfont|pfa|pfb|pfm|fon|fnt)$~i',$path);
    $fontMagic=static fn(string $contents): bool=>in_array(substr($contents,0,4),["\x00\x01\x00\x00",'OTTO','wOFF','wOF2','ttcf'],true);
    $checkPublicFile=static function(string $name,string $contents) use ($publicFontHashes,$publicFontFiles,$fontPath,$fontMagic,&$publicFontUses): void {
        if (isset($publicFontHashes[$name])) {
            if (!hash_equals($publicFontHashes[$name],hash('sha256',$contents))) { throw new RuntimeException('A bundled public font, license or author notice differs from the approved upstream version.'); }
            return;
        }
        if ($fontPath($name)||$fontMagic($contents)) { throw new RuntimeException('A public release contains an unapproved font asset. Build with the bundled Vazirmatn font profile; private fonts are not removed automatically.'); }
        if (!str_starts_with($name,'public_html/')) { return; }
        if (preg_match('~<font(?:\s|>)|<font-face\b|data:(?:font/|application/(?:font-woff|vnd\.ms-fontobject|x-font-ttf|x-font-opentype))~i',$contents)) { throw new RuntimeException('Embedded or data-URL fonts are not allowed in a public release.'); }
        if (!str_ends_with(strtolower($name),'.css')) {
            if (preg_match('~@font-face\b~i',$contents)) { throw new RuntimeException('Public font-face rules must be in bundled CSS.'); }
            return;
        }
        $css=preg_replace('~/\*.*?\*/~s','',$contents);
        if (preg_match('~@import\b~i',$css)) { throw new RuntimeException('Public CSS must be bundled without external imports.'); }
        preg_match_all('~@font-face\s*\{([^{}]*)\}~i',$css,$faces,PREG_SET_ORDER);
        if (preg_match_all('~@font-face\b~i',$css)!==count($faces)) { throw new RuntimeException('A public font-face rule cannot be validated.'); }
        foreach ($faces as $face) {
            if (preg_match('~local\s*\(|data:|(?:https?:)?//~i',$face[1])) { throw new RuntimeException('Public font-face rules must use only bundled relative WOFF2 URLs.'); }
            preg_match_all('~(?:^|;)\s*src\s*:\s*([^;]+)~i',$face[1],$sources);
            if (count($sources[1])!==1||!preg_match('~^\s*url\(\s*([\'"])([^\'"]+)\1\s*\)\s*(?:format\(\s*([\'"])woff2\3\s*\))?\s*$~i',$sources[1][0],$url)) { throw new RuntimeException('Each public font-face rule needs one relative WOFF2 source.'); }
            if (!preg_match('~^[A-Za-z0-9_./-]+$~D',$url[2])||str_starts_with($url[2],'/')) { throw new RuntimeException('A public font URL is not a plain relative path.'); }
            $parts=[];
            foreach (explode('/',dirname($name).'/'.$url[2]) as $part) {
                if ($part===''||$part==='.') { continue; }
                if ($part==='..') { if (!$parts) { throw new RuntimeException('A public font URL escapes the package.'); } array_pop($parts); }
                else { $parts[]=$part; }
            }
            $resolved=implode('/',$parts);
            if (!in_array($resolved,$publicFontFiles,true)) { throw new RuntimeException('A public font-face rule references an unapproved or missing font.'); }
            $publicFontUses[$resolved]=true;
        }
    };
    $zip=new ZipArchive();
    if (!$archive||$zip->open($archive,ZipArchive::RDONLY)!==true) { throw new RuntimeException('Release archive unavailable.'); }
    $seen=[]; $regular=[]; $count=0;
    foreach (range(0,$zip->numFiles-1) as $index) {
        $name=$zip->getNameIndex($index);
        if (!is_string($name)||$name===''||isset($seen[$name])||str_starts_with($name,'/')||str_contains($name,'\\')||in_array('..',explode('/',$name),true)||str_contains($name,"\0")) { throw new RuntimeException('Unsafe or duplicate archive path.'); }
        if ($publicRelease&&$fontPath($name)&&!isset($publicFontHashes[$name])&&!in_array($name,['public_html/assets/fonts/','public_html/assets/fonts/vazirmatn/'],true)) { throw new RuntimeException('A public release contains an unapproved font path or font file.'); }
        $seen[$name]=true; $os=0; $attributes=0;
        if (!$zip->getExternalAttributesIndex($index,$os,$attributes)||$os!==ZipArchive::OPSYS_UNIX||(($attributes>>16)&0170000)===0120000) { throw new RuntimeException('Invalid archive permissions or link entry.'); }
        if (str_ends_with($name,'/')) {
            if (!preg_match('~^(backend|bin|config|database|docs|public_html|storage)(/|$)~',$name)) { throw new RuntimeException('Unexpected archive directory.'); }
            continue;
        }
        $allowed=in_array($name,['README.md','THIRD_PARTY.md','backend/bootstrap.php','backend/composer.json','backend/composer.lock','bin/cron.php','bin/migrate.php','config/app.example.php','docs/SETUP.md','public_html/index.html','public_html/install.html','public_html/.htaccess','RELEASE.txt','MANIFEST.release.json'],true)||preg_match('~^(backend/(src|vendor)|database/migrations|docs/backend|public_html/(assets|api))/~',$name);
        if (!$allowed||preg_match('~(^|/)(\.git|\.env[^/]*|node_modules|tests?|work)(/|$)|(^|/)(local\.php|setup-token\.txt|dev-access\.txt)$~',$name)||str_starts_with($name,'storage/')) { throw new RuntimeException('A forbidden release file was included.'); }
        $mode=($attributes>>16)&0777;
        if (($mode&0022)!==0||($mode&0111)!==0) { throw new RuntimeException('Release files must not be writable by others or executable.'); }
        $regular[$name]=true; $count++;
    }
    foreach (['README.md','THIRD_PARTY.md','backend/bootstrap.php','backend/vendor/autoload.php','backend/vendor/composer/LICENSE','backend/vendor/phpmailer/phpmailer/LICENSE','backend/vendor/phpmailer/phpmailer/src/SMTP.php','backend/src/AccessGroups.php','database/migrations/003_access_groups.sql','database/migrations/004_mail_settings.sql','backend/src/MailSettings.php','backend/src/MailTransport.php','docs/backend/API-MAIL.md','docs/backend/MAIL.md','config/app.example.php','bin/cron.php','bin/migrate.php','public_html/index.html','public_html/install.html','public_html/api/index.php','public_html/api/install.php','public_html/.htaccess','RELEASE.txt','MANIFEST.release.json','storage/logs/','storage/sessions/','storage/run/'] as $required) {
        if (!isset($seen[$required])) { throw new RuntimeException('A required runtime file or empty directory is missing.'); }
    }
    if ($publicRelease) { foreach ($publicFontHashes as $required=>$_) { if (!isset($regular[$required])) { throw new RuntimeException('Bundled public fonts or license/author notices are missing.'); } } }
    if ($zip->getFromName('public_html/.htaccess')!=="DirectoryIndex index.html\n") { throw new RuntimeException('The document-index directive changed.'); }
    $manifest=json_decode($zip->getFromName('MANIFEST.release.json'),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($manifest)||count($manifest)!==$count-1) { throw new RuntimeException('The manifest does not cover the release.'); }
    foreach ($regular as $name=>$_) {
        if ($name==='MANIFEST.release.json') { continue; }
        $contents=$zip->getFromName($name);
        if (!isset($manifest[$name])||!is_string($contents)||!hash_equals((string)$manifest[$name],hash('sha256',$contents))) { throw new RuntimeException('A release checksum does not match.'); }
        if ($publicRelease) { $checkPublicFile($name,$contents); }
    }
    if ($publicRelease&&count($publicFontUses)!==count($publicFontFiles)) { throw new RuntimeException('Public CSS must reference every bundled font.'); }
    foreach (['public_html/index.html','public_html/install.html'] as $html) {
        preg_match_all('/(?:src|href)=["\']([^"\']+)["\']/i',$zip->getFromName($html),$references);
        foreach ($references[1] as $reference) {
            if (str_starts_with($reference,'#')||preg_match('~^[a-z][a-z0-9+.-]*:|^//~i',$reference)) { continue; }
            $path=parse_url($reference,PHP_URL_PATH);
            if (!$path||str_contains($path,'..')) { throw new RuntimeException('An invalid HTML asset reference was found.'); }
            $asset='public_html/'.ltrim(preg_replace('~^\./~','',$path),'/');
            if (!isset($regular[$asset])) { throw new RuntimeException('An HTML asset is missing from the release.'); }
        }
    }
    $zip->close();
    echo 'PASS: '.count($manifest).' release checksums, runtime allowlist, empty storage, permissions, licenses and HTML asset references; no installation secrets or data included.'.($publicRelease?' Approved bundled Vazirmatn fonts, relative CSS sources and license notices verified.':'') . PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'Release verification failed: '.$e->getMessage().PHP_EOL); exit(1); }
