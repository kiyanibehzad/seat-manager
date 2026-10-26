<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
try {
$root=dirname(__DIR__); chdir($root); umask(0077);
require $root.'/backend/vendor/autoload.php';
$db=new PDO('mysql:unix_socket=storage/run/db.sock;charset=utf8mb4',posix_getpwuid(posix_geteuid())['name'],'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (!is_file($root.'/config/local.php')) {
    $password=bin2hex(random_bytes(24));
    foreach (['acc_manager_dev','acc_manager_test','acc_install_test'] as $name) {
        $db->exec('CREATE DATABASE IF NOT EXISTS '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    foreach (['127.0.0.1','localhost'] as $host) {
        $identity="'acc_local'@".$db->quote($host);
        $db->exec('CREATE USER IF NOT EXISTS '.$identity.' IDENTIFIED BY '.$db->quote($password));
        $db->exec('ALTER USER '.$identity.' IDENTIFIED BY '.$db->quote($password));
        foreach (['acc_manager_dev','acc_manager_test','acc_install_test'] as $name) { $db->exec('GRANT ALL PRIVILEGES ON '.$name.'.* TO '.$identity); }
    }
    $config=require $root.'/config/app.example.php';
    $config['environment']='development'; $config['app_url']='http://127.0.0.1:8787'; $config['app_key']=base64_encode(random_bytes(32));
    $config['db']=['dsn'=>'mysql:host=127.0.0.1;port=33079;dbname=acc_manager_dev;charset=utf8mb4','user'=>'acc_local','password'=>$password];
    $config['session']['secure']=false; $config['session']['name']='acc_dev';
    $config['smtp']=array_replace($config['smtp'],['enabled'=>true,'host'=>'127.0.0.1','port'=>10279,'encryption'=>'','from_email'=>'noreply@example.test','timeout_seconds'=>2]);
    $secret=bin2hex(random_bytes(32)); $config['setup']=['enabled'=>true,'token_hash'=>hash('sha256',$secret)];
    file_put_contents($root.'/config/local.php','<?php return '.var_export($config,true).';');
    file_put_contents($root.'/storage/run/setup-token.txt',$secret);
}
$config=require $root.'/config/local.php';
$test=$config; $test['environment']='test'; $test['db']['dsn']=str_replace('acc_manager_dev','acc_manager_test',$config['db']['dsn']);
$test['app_url']='http://127.0.0.1:8788'; $test['session']['name']='acc_test';
file_put_contents($root.'/storage/run/test-config.php','<?php return '.var_export($test,true).';');
$sub=$test; $sub['app_url']='http://127.0.0.1:8789/portal'; $sub['session']['name']='acc_subfolder';
file_put_contents($root.'/storage/run/subfolder-config.php','<?php return '.var_export($sub,true).';');
$app=AccManager\App::boot($root);
(new AccManager\Migrator($app))->run();
if (!(int)$app->db->one('SELECT COUNT(*) n FROM users')['n']) {
    $firstPassword=bin2hex(random_bytes(16));
    (new AccManager\Installer($app))->install(trim(file_get_contents($root.'/storage/run/setup-token.txt')),['email'=>'admin@example.test','first_name'=>'مدیر','last_name'=>'آزمایشی','department_name'=>'بخش آزمایشی','password'=>$firstPassword,'password_confirm'=>$firstPassword]);
    file_put_contents($root.'/storage/dev-access.txt',"LOCAL TEST ONLY — never use in production\nURL: ".$config['app_url']."/\nEmail: admin@example.test\nPassword: ".$firstPassword."\n");
    chmod($root.'/storage/dev-access.txt',0600);
}
$accessPath=$root.'/storage/dev-access.txt';
if (!is_file($accessPath)) {
    $adminUser=$app->db->one("SELECT id,email FROM users WHERE app_role='admin' AND email LIKE '%@example.test' ORDER BY id LIMIT 1");
    $initial=$app->db->one("SELECT payload_encrypted FROM mail_outbox WHERE recipient_id=? AND category='initial_password' ORDER BY id DESC LIMIT 1",[$adminUser['id']]);
    if ($initial && $initial['payload_encrypted']!==null) {
        $payload=$app->crypto->decrypt($initial['payload_encrypted']);
        preg_match('/رمز اولیه: ([a-f0-9]+)/u',$payload['body'],$match);
        if (isset($match[1])) {
            file_put_contents($accessPath,"LOCAL TEST ONLY — never use in production\nURL: ".$config['app_url']."/\nEmail: ".$adminUser['email']."\nPassword: ".$match[1]."\n");
            chmod($accessPath,0600);
        }
    }
}
echo "Isolated development database ready.\n";

} catch (Throwable $e) { fwrite(STDERR,"Local database setup failed; no secrets were logged.\n"); exit(1); }
