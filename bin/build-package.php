<?php
declare(strict_types=1);
// Build offline from the prepared checkout. The shared host needs neither Composer nor this builder.
if (PHP_SAPI!=='cli') { exit(1); }
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1'); umask(0077);
try {
    $root=dirname(__DIR__); $target=$argv[1]??'';
    if ($target===''||$target[0]!=='/'||is_file($target)||!is_dir(dirname($target))) { throw new RuntimeException('New absolute zip path required'); }
    if (!is_file($root.'/backend/vendor/phpmailer/phpmailer/src/PHPMailer.php')) { throw new RuntimeException('Bundled SMTP library missing'); }
    $zip=new ZipArchive(); if ($zip->open($target,ZipArchive::CREATE|ZipArchive::EXCL)!==true) { throw new RuntimeException('Archive unavailable'); }
    $manifest=[];
    foreach (['backend','database','docs/backend','public_html/api'] as $directory) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile()||$file->isLink()||$file->getFilename()==='.DS_Store') { continue; }
            $relative=substr($file->getPathname(),strlen($root)+1);
            $zip->addFile($file->getPathname(),$relative); $manifest[$relative]=hash_file('sha256',$file->getPathname());
        }
    }
    foreach (['bin/cron.php','bin/migrate.php','config/app.example.php'] as $relative) { $zip->addFile($root.'/'.$relative,$relative); $manifest[$relative]=hash_file('sha256',$root.'/'.$relative); }
    foreach (['storage/logs','storage/sessions','storage/run'] as $directory) { $zip->addEmptyDir($directory); }
    $config=require $root.'/config/app.example.php'; $config['app_key']=base64_encode(random_bytes(32));
    $setup=bin2hex(random_bytes(32)); $config['setup']=['enabled'=>true,'token_hash'=>hash('sha256',$setup)];
    $zip->addFromString('config/local.php','<?php return '.var_export($config,true).';');
    $zip->addFromString('storage/setup-token.txt',$setup);
    $zip->addFromString('MANIFEST.backend.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $zip->addFromString('BACKEND-PACKAGE.txt',"Backend stage 1 only. Frontend is delivered separately. Read docs/backend/INSTALL.md.\nPrivate config/key/setup-token must stay outside public_html. No development data is included.\n");
    $zip->close(); echo 'Backend package created: '.count($manifest)." source files; no local data or credentials included.\n";
} catch (Throwable $e) { fwrite(STDERR,"Package build failed; check the target path and prepared dependencies.\n"); exit(1); }
