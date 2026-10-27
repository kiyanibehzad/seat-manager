<?php
declare(strict_types=1);
// Pure password-policy regression: no app bootstrap, private config, database or network.
namespace AccManager {
    // Observe real native calls without replacing their cryptographic behavior or timing.
    function password_verify(string $password,string $hash): bool
    {
        $info=\password_get_info($hash);
        $GLOBALS['passwordPolicyWork'][]=($info['algoName']??'')==='bcrypt'?(1<<(int)$info['options']['cost']):0;
        return \password_verify($password,$hash);
    }
    function password_hash(string $password,string|int|null $algorithm,array $options=[]): string
    {
        $GLOBALS['passwordPolicyHashCalls']++;
        return \password_hash($password,$algorithm,$options);
    }
}
namespace {
require __DIR__.'/../../backend/src/Passwords.php';
use AccManager\Passwords;

$checks=0; $GLOBALS['passwordPolicyHashCalls']=0; $GLOBALS['passwordPolicyWork']=[];
function passwordCheck(bool $ok,string $label): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
    $checks++;
}
function checkedVerify(string $password,?string $hash,bool $expected,string $label): void
{
    $GLOBALS['passwordPolicyWork']=[];
    $hashCalls=$GLOBALS['passwordPolicyHashCalls'];
    passwordCheck(Passwords::verify($password,$hash)===$expected,$label);
    passwordCheck(array_sum($GLOBALS['passwordPolicyWork'])===(1<<Passwords::COST),$label.' performs the common bcrypt work budget');
    passwordCheck($GLOBALS['passwordPolicyHashCalls']===$hashCalls,$label.' never generates a replacement or dummy hash during verification');
}

try {
    passwordCheck(!class_exists('AccManager\\App',false),'test does not bootstrap an installation');
    passwordCheck(Passwords::ALGORITHM===PASSWORD_BCRYPT&&Passwords::COST===12,'runtime-independent bcrypt policy');
    $password='Policy-Only-رمز-2026';
    $current=Passwords::hash($password);
    $info=password_get_info($current);
    passwordCheck($info['algoName']==='bcrypt'&&$info['options']['cost']===12,'new hashes use explicit policy');
    passwordCheck(!Passwords::needsRehash($current),'current hash stays current');
    checkedVerify($password,$current,true,'current password accepted');
    checkedVerify('wrong-password',$current,false,'current wrong password rejected');
    checkedVerify($password,null,false,'absent account rejected');
    checkedVerify('seat-manager-login-dummy-no-account',null,false,'dummy password cannot authenticate an absent account');
    checkedVerify('wrong-password','malformed',false,'malformed stored hash rejected');
    checkedVerify('wrong-password','',false,'empty stored hash rejected');
    $dummy=(new ReflectionClass(Passwords::class))->getConstant('DUMMY_HASHES');
    passwordCheck(is_array($dummy)&&array_keys($dummy)===range(4,12),'all legacy padding costs and target dummy exist');
    foreach ($dummy as $cost=>$hash) {
        $info=password_get_info($hash);
        passwordCheck($info['algoName']==='bcrypt'&&$info['options']['cost']===$cost,'dummy metadata matches work factor '.$cost);
        passwordCheck(password_verify('seat-manager-login-dummy-no-account',$hash),'valid precomputed dummy at work factor '.$cost);
    }
    foreach ([4,10,11] as $cost) {
        $legacy=$dummy[$cost];
        passwordCheck(Passwords::needsRehash($legacy),'legacy hash marked for migration at cost '.$cost);
        checkedVerify('seat-manager-login-dummy-no-account',$legacy,true,'legacy correct password remains usable at cost '.$cost);
        checkedVerify('wrong-password',$legacy,false,'legacy wrong password rejected at cost '.$cost);
    }
    $legacy=password_hash($password,PASSWORD_BCRYPT,['cost'=>10]);
    passwordCheck(Passwords::verify($password,$legacy)&&Passwords::needsRehash($legacy),'real old default password accepted before migration');
    $migrated=Passwords::hash($password);
    passwordCheck(Passwords::verify($password,$migrated)&&!Passwords::needsRehash($migrated),'same password remains usable after migration');
    passwordCheck($current!==$migrated,'hash creation retains independent random salts');
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../backend/src',FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile()||$file->getExtension()!=='php'||$file->getFilename()==='Passwords.php') { continue; }
        passwordCheck(!preg_match('/\bpassword_(hash|needs_rehash|verify)\s*\(/',file_get_contents($file->getPathname())),'all application password paths use the common policy: '.$file->getFilename());
    }
    echo 'PASS: '.$checks." password-policy checks on PHP ".PHP_VERSION."; no installation or network used.\n";
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n");
    exit(1);
}
}
