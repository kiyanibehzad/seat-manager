<?php
declare(strict_types=1);
// Read-only archive verification: no extraction, configuration reads, database or SMTP.
if (PHP_SAPI!=='cli') { exit(1); }
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
try {
    $archive=$argv[1]??''; $zip=new ZipArchive();
    if (!$archive||$zip->open($archive,ZipArchive::RDONLY)!==true) { throw new RuntimeException('Release archive unavailable.'); }
    $seen=[]; $regular=[]; $count=0;
    foreach (range(0,$zip->numFiles-1) as $index) {
        $name=$zip->getNameIndex($index);
        if (!is_string($name)||$name===''||isset($seen[$name])||str_starts_with($name,'/')||str_contains($name,'\\')||in_array('..',explode('/',$name),true)||str_contains($name,"\0")) { throw new RuntimeException('Unsafe or duplicate archive path.'); }
        $seen[$name]=true; $os=0; $attributes=0;
        if (!$zip->getExternalAttributesIndex($index,$os,$attributes)||$os!==ZipArchive::OPSYS_UNIX||(($attributes>>16)&0170000)===0120000) { throw new RuntimeException('Invalid archive permissions or link entry.'); }
        if (str_ends_with($name,'/')) {
            if (!preg_match('~^(backend|bin|config|database|docs|public_html|storage)(/|$)~',$name)) { throw new RuntimeException('Unexpected archive directory.'); }
            continue;
        }
        $allowed=in_array($name,['backend/bootstrap.php','backend/composer.json','backend/composer.lock','bin/cron.php','bin/migrate.php','config/app.example.php','docs/SETUP.md','public_html/index.html','public_html/install.html','public_html/.htaccess','RELEASE.txt','MANIFEST.release.json'],true)||preg_match('~^(backend/(src|vendor)|database/migrations|docs/backend|public_html/(assets|api))/~',$name);
        if (!$allowed||preg_match('~(^|/)(\.git|\.env[^/]*|node_modules|tests?|work)(/|$)|(^|/)(local\.php|setup-token\.txt|dev-access\.txt)$~',$name)||str_starts_with($name,'storage/')) { throw new RuntimeException('A forbidden release file was included.'); }
        $mode=($attributes>>16)&0777;
        if (($mode&0022)!==0||($mode&0111)!==0) { throw new RuntimeException('Release files must not be writable by others or executable.'); }
        $regular[$name]=true; $count++;
    }
    foreach (['backend/bootstrap.php','backend/vendor/autoload.php','backend/vendor/composer/LICENSE','backend/vendor/phpmailer/phpmailer/LICENSE','backend/vendor/phpmailer/phpmailer/src/SMTP.php','backend/src/AccessGroups.php','database/migrations/003_access_groups.sql','database/migrations/004_mail_settings.sql','backend/src/MailSettings.php','backend/src/MailTransport.php','docs/backend/API-MAIL.md','docs/backend/MAIL.md','config/app.example.php','bin/cron.php','bin/migrate.php','public_html/index.html','public_html/install.html','public_html/api/index.php','public_html/api/install.php','public_html/.htaccess','RELEASE.txt','MANIFEST.release.json','storage/logs/','storage/sessions/','storage/run/'] as $required) {
        if (!isset($seen[$required])) { throw new RuntimeException('A required runtime file or empty directory is missing.'); }
    }
    if ($zip->getFromName('public_html/.htaccess')!=="DirectoryIndex index.html\n") { throw new RuntimeException('The document-index directive changed.'); }
    $manifest=json_decode($zip->getFromName('MANIFEST.release.json'),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($manifest)||count($manifest)!==$count-1) { throw new RuntimeException('The manifest does not cover the release.'); }
    foreach ($regular as $name=>$_) {
        if ($name==='MANIFEST.release.json') { continue; }
        $contents=$zip->getFromName($name);
        if (!isset($manifest[$name])||!is_string($contents)||!hash_equals((string)$manifest[$name],hash('sha256',$contents))) { throw new RuntimeException('A release checksum does not match.'); }
    }
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
    echo 'PASS: '.count($manifest).' release checksums, runtime allowlist, empty storage, permissions, licenses and HTML asset references; no installation secrets or data included.' . PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'Release verification failed: '.$e->getMessage().PHP_EOL); exit(1); }
