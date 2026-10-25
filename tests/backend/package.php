<?php
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1'); umask(0077);
try {
    $root=dirname(__DIR__,2); chdir($root); putenv('ACC_CONFIG');
    $archive=$argv[1]??''; $zip=new ZipArchive(); if ($zip->open($archive)!==true) { throw new RuntimeException('archive'); }
    $destination=$root.'/work/package-smoke-'.bin2hex(random_bytes(4)); mkdir($destination,0700);
    if ($zip->locateName('storage/dev-access.txt')!==false || $zip->locateName('config/local.php')===false || $zip->locateName('backend/vendor/phpmailer/phpmailer/src/SMTP.php')===false) { throw new RuntimeException('package files'); }
    for ($i=0;$i<$zip->numFiles;$i++) {
        $name=$zip->getNameIndex($i);
        if (str_contains($name,'..')||str_starts_with($name,'/')||str_starts_with($name,'frontend/')||str_starts_with($name,'storage/mysql')||str_starts_with($name,'storage/mail-sink')||str_starts_with($name,'tests/')) { throw new RuntimeException('unexpected package path'); }
    }
    $zip->extractTo($destination); $zip->close();
    $config=require $destination.'/config/local.php'; $development=require $root.'/config/local.php';
    if ($config['environment']!=='production'||!$config['session']['secure']||$config['smtp']['enabled']||hash_equals($config['app_key'],$development['app_key'])) { throw new RuntimeException('production defaults'); }
    $setup=trim(file_get_contents($destination.'/storage/setup-token.txt'));
    if (!hash_equals($config['setup']['token_hash'],hash('sha256',$setup))) { throw new RuntimeException('setup key'); }
    $manifest=json_decode(file_get_contents($destination.'/MANIFEST.backend.json'),true,512,JSON_THROW_ON_ERROR);
    foreach ($manifest as $file=>$checksum) { if (!hash_equals($checksum,hash_file('sha256',$destination.'/'.$file))) { throw new RuntimeException('checksum'); } }
    $rawConfig=file_get_contents($destination.'/config/local.php');
    if (str_contains($rawConfig,$development['db']['password']) || str_contains($rawConfig,$development['app_key'])) { throw new RuntimeException('development secret'); }
    // Test only: point this extracted package to its own local DB/sink, without Composer/Node.
    $test=require $root.'/storage/run/test-config.php';
    $config['environment']='test'; $config['app_url']='http://127.0.0.1:8790'; $config['session']['secure']=false;
    $config['db']=$test['db']; $config['db']['dsn']=str_replace('acc_manager_test','acc_install_test',$test['db']['dsn']); $config['smtp']=$test['smtp'];
    file_put_contents($destination.'/config/local.php','<?php return '.var_export($config,true).';');
    $app=require $destination.'/backend/bootstrap.php';
    if ($app->db->one('SELECT DATABASE() n')['n']!=='acc_install_test') { throw new RuntimeException('database guard'); }
    $app->db->run('SET FOREIGN_KEY_CHECKS=0');
    foreach ($app->db->all('SHOW TABLES') as $table) { $name=array_values($table)[0]; if (!preg_match('/^[a-z_]+$/',$name)) { throw new RuntimeException('table name'); } $app->db->pdo->exec('DROP TABLE `'.$name.'`'); }
    $app->db->run('SET FOREIGN_KEY_CHECKS=1');
    (new AccManager\Installer($app))->install($setup,['email'=>'package-admin@example.test','first_name'=>'مدیر','last_name'=>'بستهٔ آزمایشی','department_name'=>'آزمایش بسته']);
    $row=$app->db->one("SELECT payload_encrypted FROM mail_outbox WHERE category='initial_password'"); $body=$app->crypto->decrypt($row['payload_encrypted'])['body']; preg_match('/رمز اولیه: ([a-f0-9]+)/u',$body,$match);
    $user=$app->auth->login(['email'=>'package-admin@example.test','password'=>$match[1]],'127.0.0.1');
    if ($user['app_role']!=='admin') { throw new RuntimeException('package auth'); }
    $stats=(new AccManager\MailWorker($app))->run(); if ($stats['sent']!==1) { throw new RuntimeException('package SMTP'); }
    echo 'PASS: '.count($manifest)." package file checksums; fresh isolated install, login and local SMTP with bundled dependencies.\n";
} catch (Throwable $e) { fwrite(STDERR,'Package smoke test failed ('.get_class($e).') at line '.$e->getLine()."; no secrets logged.\n"); exit(1); }
