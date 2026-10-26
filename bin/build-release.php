<?php
declare(strict_types=1);
// Combined offline release. Secrets and installation-specific configuration are created on the target.
if (PHP_SAPI!=='cli') { exit(1); }
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1'); umask(0077);
$zip=null; $created=false; $destination=null;
try {
    $publicRelease=false; $target='';
    foreach (array_slice($argv,1) as $argument) {
        if ($argument==='--public'&&!$publicRelease) { $publicRelease=true; }
        elseif (str_starts_with($argument,'--')||$target!=='') { throw new RuntimeException('Usage: php bin/build-release.php /absolute/new-release.zip [--public]'); }
        else { $target=$argument; }
    }
    $root=realpath(dirname(__DIR__));
    if (!$root||$target===''||$target[0]!=='/'||!str_ends_with(strtolower($target),'.zip')||file_exists($target)||is_link($target)) { throw new RuntimeException('A new absolute .zip output path is required.'); }
    $parent=realpath(dirname($target)); $public=$root.'/public_html';
    if (!$parent||!is_dir($parent)||$parent===$public||str_starts_with($parent,$public.'/')) { throw new RuntimeException('The archive must be outside public_html in an existing directory.'); }
    $destination=$parent.'/'.basename($target);
    if (file_exists($destination)||is_link($destination)) { throw new RuntimeException('The output already exists.'); }
    $fixed=['README.md','THIRD_PARTY.md','backend/bootstrap.php','backend/composer.json','backend/composer.lock','bin/cron.php','bin/migrate.php','config/app.example.php','docs/SETUP.md','docs/backend/API.md','docs/backend/API-OPERATIONS.md','docs/backend/OPERATIONS.md','docs/backend/INSTALL.md','docs/backend/API-MAIL.md','docs/backend/MAIL.md','docs/backend/ACCESS-GROUPS-CONTRACT.md','public_html/index.html','public_html/install.html'];
    $directories=['backend/src','backend/vendor','database/migrations','public_html/assets','public_html/api'];
    $files=[];
    $safeSource=static function(string $relative) use ($root): string {
        $source=$root.'/'.$relative;
        if (!is_file($source)||is_link($source)||realpath($source)!==$source) { throw new RuntimeException('A required regular release file is missing or linked.'); }
        return $source;
    };
    foreach ($fixed as $relative) { $files[$relative]=$safeSource($relative); }
    foreach ($directories as $directory) {
        $path=$root.'/'.$directory;
        if (!is_dir($path)||is_link($path)||realpath($path)!==$path) { throw new RuntimeException('A prepared release directory is missing or linked.'); }
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()||$file->isLink()||$file->getFilename()==='.DS_Store') { continue; }
            $relative=substr($file->getPathname(),strlen($root)+1);
            $parts=explode('/',$relative);
            if (array_intersect($parts,['.git','node_modules','tests','test','work'])||str_starts_with($file->getFilename(),'.env')||str_ends_with($relative,'.zip')||str_ends_with($relative,'.log')) { continue; }
            $files[$relative]=$safeSource($relative);
        }
    }
    foreach (['backend/vendor/autoload.php','backend/vendor/phpmailer/phpmailer/src/PHPMailer.php','backend/vendor/phpmailer/phpmailer/src/SMTP.php','public_html/api/index.php','public_html/api/install.php'] as $required) {
        if (!isset($files[$required])) { throw new RuntimeException('Prepared runtime dependencies or API entry points are missing.'); }
    }
    if (!array_filter(array_keys($files),static fn($file)=>str_starts_with($file,'public_html/assets/'))) { throw new RuntimeException('Built frontend assets are missing.'); }
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
    if ($publicRelease) {
        foreach ($publicFontHashes as $required=>$_) {
            if (!isset($files[$required])) { throw new RuntimeException('The public release requires all three bundled Vazirmatn fonts and their license/author notices.'); }
        }
    }
    ksort($files,SORT_STRING);
    $zip=new ZipArchive();
    if ($zip->open($destination,ZipArchive::CREATE|ZipArchive::EXCL)!==true) { throw new RuntimeException('The archive cannot be created exclusively.'); }
    $created=true; $manifest=[]; $madeDirs=[];
    $addDirectory=static function(string $directory) use ($zip,&$madeDirs): void {
        $parts=explode('/',trim($directory,'/')); $path='';
        foreach ($parts as $part) {
            $path.=$part.'/'; if (isset($madeDirs[$path])) { continue; }
            $mode=str_starts_with($path,'public_html/')?0755:(str_starts_with($path,'storage/')?0700:0750);
            if (!$zip->addEmptyDir($path)||!$zip->setExternalAttributesName($path,ZipArchive::OPSYS_UNIX,(0040000|$mode)<<16)) { throw new RuntimeException('A release directory cannot be added.'); }
            $madeDirs[$path]=true;
        }
    };
    $add=static function(string $relative,string $contents) use ($zip,&$manifest,$addDirectory): void {
        $parent=dirname($relative); if ($parent!=='.') { $addDirectory($parent); }
        $mode=str_starts_with($relative,'public_html/')?0644:0640;
        if (!$zip->addFromString($relative,$contents)||!$zip->setExternalAttributesName($relative,ZipArchive::OPSYS_UNIX,(0100000|$mode)<<16)) { throw new RuntimeException('A release file cannot be added.'); }
        $manifest[$relative]=hash('sha256',$contents);
    };
    foreach ($files as $relative=>$source) {
        $contents=file_get_contents($source);
        if ($contents===false) { throw new RuntimeException('A release source cannot be read.'); }
        if ($publicRelease) { $checkPublicFile($relative,$contents); }
        $add($relative,$contents);
    }
    if ($publicRelease&&count($publicFontUses)!==count($publicFontFiles)) { throw new RuntimeException('The public CSS must reference all three bundled Vazirmatn fonts.'); }
    $add('public_html/.htaccess',"DirectoryIndex index.html\n");
    $release=<<<'TEXT'
Seat Manager - combined offline release

Only public_html/ is the web document root. Keep backend/, config/, database/,
storage/, bin/ and docs/ outside the public document root. The archive contains
prepared PHP dependencies and frontend assets; hosting needs no Composer or Node.

This archive contains NO local.php, application key, setup token, database
credentials, accounts or other installation data. On the target, privately create
config/local.php from config/app.example.php with the target HTTPS URL/database,
a fresh random 32-byte base64 application key and secure sessions. SMTP is not
required for the chosen-password first installation; configure it separately
before enabling employee email and email-based account recovery.
Create a fresh one-time setup token; store only its SHA-256 hash in setup.token_hash
and enable setup for the initial installation. Never place these values in Git.

Run bin/migrate.php with the intended private target configuration. Open
/install.html over HTTPS to submit the setup token and first administrator's
corporate email/name/department, chosen password and password confirmation.
The chosen password is never emailed or returned by installation. The older
random-password delivery mode remains an optional compatibility path requiring
explicitly prepared and enabled encrypted mail settings. Fresh installations
default automatic delivery OFF. Then disable setup and clear its token hash.
The chief can configure SMTP or PHP mail in Settings, test explicitly, and enable
automatic delivery after choosing a real sender. PHP mail acceptance is not inbox
delivery proof; inspect cron freshness and the destination inbox.
Configure the hosting control panel to run PHP bin/cron.php every minute.

Do not overwrite an existing private configuration or storage when updating.
Before migration, pause writes and cron and take one coordinated private snapshot
of database, config/app_key, code/API and frontend assets. A rollback restores
that matching set together; DDL is not undone by a transaction rollback. Read docs/SETUP.md and docs/backend/ before installation.
MANIFEST.release.json contains SHA-256 hashes of every regular packaged file
except the manifest itself. DirectoryIndex selects the built index.html.
Optional licensed local fonts, if present in built assets, remain subject to
their existing license and are included only for the owner's deployment.
TEXT;
    if ($publicRelease) {
        $release=str_replace("Optional licensed local fonts, if present in built assets, remain subject to\ntheir existing license and are included only for the owner's deployment.","This public release bundles Vazirmatn v33.003 Regular, Medium and Bold WOFF2\nfonts under the SIL Open Font License 1.1, with OFL.txt and AUTHORS.txt. Font\nfiles are served from the application itself; no CDN or external font service\nis required. Proprietary IRANSans files are not included in this public ZIP.",$release);
        $release.="\n\nPublic ready-to-install package: PHP 8.3+ with PDO MySQL, OpenSSL, mbstring\nand sessions, and MySQL 8.0+ or MariaDB 10.6+ with InnoDB/utf8mb4 are required.\nPHP dependencies and compiled frontend assets are included. Node.js and Composer\nare not required on the hosting account. Start with docs/backend/INSTALL.md;\nthe source-development commands in README.md are not needed to install this ZIP.\nRead THIRD_PARTY.md and the bundled dependency licenses. No project-wide\nopen-source license has been selected.\n";
    }
    $add('RELEASE.txt',$release."\n");
    foreach (['storage/logs','storage/sessions','storage/run'] as $directory) { $addDirectory($directory); }
    ksort($manifest,SORT_STRING);
    if (!$zip->addFromString('MANIFEST.release.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n")||!$zip->setExternalAttributesName('MANIFEST.release.json',ZipArchive::OPSYS_UNIX,(0100000|0640)<<16)||!$zip->close()) { throw new RuntimeException('The release archive cannot be finalized.'); }
    $zip=null; chmod($destination,0600);
    echo ($publicRelease?'Public combined release created: ':'Combined release created: ').count($manifest).' hashed files; SHA-256 '.hash_file('sha256',$destination).". No installation secrets included.\n";
} catch (Throwable $e) {
    if ($zip instanceof ZipArchive) { try { $zip->close(); } catch (Throwable $ignored) {} }
    if ($created&&$destination!==null) { @unlink($destination); }
    fwrite(STDERR,'Release build failed: '.$e->getMessage()."\n"); exit(1);
}
