<?php
declare(strict_types=1);
// Synthetic packaging regression. No installation configuration, database or transport is used.
if (PHP_SAPI!=='cli') { exit(1); }
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1'); umask(0077);
$root=dirname(__DIR__,2); $temporary=null; $checks=0; $exitCode=0;
function legacyCheck(bool $condition,string $message): void {
    global $checks; $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function legacyRun(array $command): array {
    $pipes=[]; $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start a synthetic packaging subprocess.'); }
    fclose($pipes[0]); $output=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['exit'=>proc_close($process),'output'=>$output,'error'=>$error];
}
function legacyPut(string $path,string $contents): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path),0700,true); }
    if (file_put_contents($path,$contents)===false) { throw new RuntimeException('Cannot create synthetic fixture.'); }
}
function legacyDelete(string $directory): void {
    if (!is_dir($directory)||is_link($directory)) { if (file_exists($directory)||is_link($directory)) { unlink($directory); } return; }
    foreach (new FilesystemIterator($directory,FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isDir()&&!$file->isLink()) { legacyDelete($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($directory);
}
try {
    if (!class_exists(ZipArchive::class)||!function_exists('proc_open')) { throw new RuntimeException('CLI ZipArchive and proc_open are required for this test.'); }
    if (!is_dir($root.'/work')) { mkdir($root.'/work',0700); }
    $temporary=$root.'/work/legacy-package-regression-'.bin2hex(random_bytes(6)); mkdir($temporary,0700);

    // Both historical names forward arguments and exit status; neither evaluates local.php.
    foreach ([['bin/build-package.php','bin/build-release.php'],['tests/backend/package.php','tests/backend/release-package.php']] as [$old,$new]) {
        $stub=$temporary.'/delegation-'.str_replace('/','-',dirname($old));
        legacyPut($stub.'/'.$old,file_get_contents($root.'/'.$old));
        legacyPut($stub.'/'.$new,'<?php echo json_encode(array_slice($argv,1),JSON_THROW_ON_ERROR); if (($argv[1]??null)==="fail") { exit(7); }');
        legacyPut($stub.'/config/local.php','<?php throw new RuntimeException("A test must never read installation config.");');
        $arguments=['--public',$temporary.'/not-created.zip']; $result=legacyRun([PHP_BINARY,$stub.'/'.$old,...$arguments]);
        legacyCheck($result['exit']===0,'Compatibility delegation failed.');
        legacyCheck(json_decode($result['output'],true,512,JSON_THROW_ON_ERROR)===$arguments,'Compatibility arguments changed.');
        legacyCheck(legacyRun([PHP_BINARY,$stub.'/'.$old,'fail'])['exit']===7,'Compatibility exit status changed.');
        legacyCheck(!file_exists($temporary.'/not-created.zip'),'Compatibility entry point created unexpected files.');
    }

    // A minimal prepared checkout, including intentionally forbidden synthetic secrets.
    $fixture=$temporary.'/prepared'; mkdir($fixture,0700);
    foreach (['bin/build-package.php','bin/build-release.php','tests/backend/package.php','tests/backend/release-package.php'] as $file) { legacyPut($fixture.'/'.$file,file_get_contents($root.'/'.$file)); }
    $sourceFiles=[
        'README.md','THIRD_PARTY.md','backend/bootstrap.php','backend/composer.json','backend/composer.lock',
        'bin/cron.php','bin/migrate.php','config/app.example.php','docs/SETUP.md','docs/backend/API.md',
        'docs/backend/API-OPERATIONS.md','docs/backend/OPERATIONS.md','docs/backend/INSTALL.md','docs/backend/API-MAIL.md',
        'docs/backend/MAIL.md','docs/backend/ACCESS-GROUPS-CONTRACT.md','docs/backend/SECURITY-HEADERS.md',
        'docs/user-guide/seat-manager-training-fa.pdf','public_html/index.html','public_html/install.html',
        'public_html/api/index.php','public_html/api/install.php',
        'backend/vendor/autoload.php','backend/vendor/composer/LICENSE','backend/vendor/phpmailer/phpmailer/LICENSE',
        'backend/vendor/phpmailer/phpmailer/src/PHPMailer.php','backend/vendor/phpmailer/phpmailer/src/SMTP.php',
        'backend/src/Placeholder.php','backend/src/Passwords.php','backend/src/SecurityHeaders.php','backend/src/AccessGroups.php',
        'backend/src/MailSettings.php','backend/src/MailTransport.php','database/migrations/001_fixture.sql',
        'database/migrations/003_access_groups.sql','database/migrations/004_mail_settings.sql',
    ];
    foreach ($sourceFiles as $file) { legacyPut($fixture.'/'.$file,'synthetic prepared file'); }
    if (is_file($root.'/public_html/.htaccess')) { legacyPut($fixture.'/public_html/.htaccess',file_get_contents($root.'/public_html/.htaccess')); }
    legacyPut($fixture.'/public_html/assets/app.css','/* Synthetic application assets. */');
    legacyPut($fixture.'/config/local.php','<?php return ["synthetic_secret"=>"must_not_enter_archive"];');
    legacyPut($fixture.'/storage/setup-token.txt','must_not_enter_archive');
    legacyPut($fixture.'/storage/logs/fixture.log','must_not_enter_archive');
    mkdir($fixture.'/public_html/nested',0700); mkdir($temporary.'/output',0700);
    $command=[PHP_BINARY,$fixture.'/bin/build-package.php'];

    $archive=$temporary.'/output/combined.zip'; $result=legacyRun([...$command,$archive]);
    legacyCheck($result['exit']===0&&is_file($archive),'Historical command did not create a combined release.');
    $zip=new ZipArchive(); legacyCheck($zip->open($archive,ZipArchive::RDONLY)===true,'Combined archive unavailable.');
    foreach (['config/local.php','storage/setup-token.txt','storage/logs/fixture.log','MANIFEST.backend.json'] as $forbidden) {
        legacyCheck($zip->locateName($forbidden)===false,'A private or obsolete package entry was included.');
    }
    foreach (['MANIFEST.release.json','public_html/index.html','public_html/install.html','RELEASE.txt'] as $required) {
        legacyCheck($zip->locateName($required)!==false,'Combined package entry missing.');
    }
    $manifest=json_decode($zip->getFromName('MANIFEST.release.json'),true,512,JSON_THROW_ON_ERROR);
    foreach ($manifest as $name=>$checksum) { legacyCheck(hash_equals($checksum,hash('sha256',$zip->getFromName($name))),'Manifest mismatch.'); }
    $zip->close();
    legacyCheck(legacyRun([PHP_BINARY,$fixture.'/tests/backend/package.php',$archive])['exit']===0,'Legacy verifier did not accept the combined release.');

    // Reject output through real and symlinked web-root paths, without writing an archive.
    $blocked=[$fixture.'/public_html/public.zip',$fixture.'/public_html/nested/public.zip'];
    if (!symlink($fixture.'/public_html',$temporary.'/web-alias')) { throw new RuntimeException('Cannot create synthetic path alias.'); }
    $blocked[]=$temporary.'/web-alias/public.zip';
    foreach ($blocked as $target) {
        legacyCheck(legacyRun([...$command,$target])['exit']!==0,'Public output was accepted.');
        legacyCheck(!file_exists($target),'Blocked public output was created.');
    }
    $existing=$temporary.'/output/existing.zip'; legacyPut($existing,'do not replace');
    legacyCheck(legacyRun([...$command,$existing])['exit']!==0&&file_get_contents($existing)==='do not replace','Existing output was replaced.');
    legacyCheck(legacyRun([...$command,'relative.zip'])['exit']!==0,'Relative output was accepted.');
    legacyCheck(legacyRun([...$command,$temporary.'/output/not-zip.txt'])['exit']!==0,'Non-ZIP output was accepted.');
    legacyCheck(legacyRun([...$command,'--unknown',$temporary.'/output/unknown.zip'])['exit']!==0,'Unknown argument was accepted.');
    legacyCheck(legacyRun([...$command,'--public','--public',$temporary.'/output/repeated.zip'])['exit']!==0,'Duplicate public option was accepted.');

    // Public mode is passed to the real builder and retains its pinned, bundled-font checks.
    $css='';
    foreach (['Vazirmatn-Regular.woff2','Vazirmatn-Medium.woff2','Vazirmatn-Bold.woff2','OFL.txt','AUTHORS.txt'] as $font) {
        legacyPut($fixture.'/public_html/assets/fonts/vazirmatn/'.$font,file_get_contents($root.'/frontend/vendor-fonts/vazirmatn/'.$font));
        if (str_ends_with($font,'.woff2')) { $css.='@font-face{font-family:"Fixture";src:url("./fonts/vazirmatn/'.$font.'") format("woff2");}'; }
    }
    legacyPut($fixture.'/public_html/assets/app.css',$css);
    $publicArchive=$temporary.'/output/public.zip'; $result=legacyRun([...$command,'--public',$publicArchive]);
    legacyCheck($result['exit']===0&&is_file($publicArchive),'Public mode was not preserved.');
    $zip=new ZipArchive(); legacyCheck($zip->open($publicArchive,ZipArchive::RDONLY)===true,'Public archive unavailable.');
    legacyCheck($zip->locateName('config/local.php')===false&&$zip->locateName('storage/setup-token.txt')===false,'Public mode included installation secrets.');
    legacyCheck($zip->locateName('public_html/assets/fonts/vazirmatn/Vazirmatn-Bold.woff2')!==false,'Public fonts were omitted.'); $zip->close();
    legacyCheck(legacyRun([PHP_BINARY,$fixture.'/tests/backend/package.php','--public',$publicArchive])['exit']===0,'Legacy verifier did not preserve public verification.');
    legacyPut($fixture.'/public_html/assets/fonts/vazirmatn/Vazirmatn-Bold.woff2','unapproved synthetic font');
    $bad=$temporary.'/output/bad-font.zip';
    legacyCheck(legacyRun([...$command,'--public',$bad])['exit']!==0&&!file_exists($bad),'Public font validation was bypassed or failed output remained.');
    echo 'PASS: '.$checks." legacy-entry, secret-exclusion, manifest, public-output, overwrite and public-font checks; no private config, database or SMTP used.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'Legacy package regression failed: '.$error->getMessage()."\n"); $exitCode=1;
} finally {
    if ($temporary!==null) { legacyDelete($temporary); }
}
exit($exitCode);
