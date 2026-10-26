<?php
declare(strict_types=1);
// Combined offline release. Secrets and installation-specific configuration are created on the target.
if (PHP_SAPI!=='cli') { exit(1); }
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1'); umask(0077);
$zip=null; $created=false; $destination=null;
try {
    $root=realpath(dirname(__DIR__)); $target=$argv[1]??'';
    if (!$root||$target===''||$target[0]!=='/'||!str_ends_with(strtolower($target),'.zip')||file_exists($target)||is_link($target)) { throw new RuntimeException('A new absolute .zip output path is required.'); }
    $parent=realpath(dirname($target)); $public=$root.'/public_html';
    if (!$parent||!is_dir($parent)||$parent===$public||str_starts_with($parent,$public.'/')) { throw new RuntimeException('The archive must be outside public_html in an existing directory.'); }
    $destination=$parent.'/'.basename($target);
    if (file_exists($destination)||is_link($destination)) { throw new RuntimeException('The output already exists.'); }
    $fixed=['backend/bootstrap.php','backend/composer.json','backend/composer.lock','bin/cron.php','bin/migrate.php','config/app.example.php','docs/SETUP.md','public_html/index.html','public_html/install.html'];
    $directories=['backend/src','backend/vendor','database/migrations','docs/backend','public_html/assets','public_html/api'];
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
        $add($relative,$contents);
    }
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
working SMTP. Then disable setup and clear its token hash.
Configure the hosting control panel to run PHP bin/cron.php every minute.

Do not overwrite an existing private configuration or storage when updating.
Back up the database/configuration before migrations; schema changes may commit
independently. Read docs/SETUP.md and docs/backend/ before installation.
MANIFEST.release.json contains SHA-256 hashes of every regular packaged file
except the manifest itself. DirectoryIndex selects the built index.html.
Optional licensed local fonts, if present in built assets, remain subject to
their existing license and are included only for the owner's deployment.
TEXT;
    $add('RELEASE.txt',$release."\n");
    foreach (['storage/logs','storage/sessions','storage/run'] as $directory) { $addDirectory($directory); }
    ksort($manifest,SORT_STRING);
    if (!$zip->addFromString('MANIFEST.release.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n")||!$zip->setExternalAttributesName('MANIFEST.release.json',ZipArchive::OPSYS_UNIX,(0100000|0640)<<16)||!$zip->close()) { throw new RuntimeException('The release archive cannot be finalized.'); }
    $zip=null; chmod($destination,0600);
    echo 'Combined release created: '.count($manifest).' hashed files; SHA-256 '.hash_file('sha256',$destination).". No installation secrets included.\n";
} catch (Throwable $e) {
    if ($zip instanceof ZipArchive) { try { $zip->close(); } catch (Throwable $ignored) {} }
    if ($created&&$destination!==null) { @unlink($destination); }
    fwrite(STDERR,'Release build failed: '.$e->getMessage()."\n"); exit(1);
}
