<?php
declare(strict_types=1);
// Standalone policy and real HTTP error-response regression. No DB/config/SMTP.
require dirname(__DIR__,2).'/backend/src/SecurityHeaders.php';
use AccManager\SecurityHeaders;
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1'); umask(0077);
$root=dirname(__DIR__,2); $count=0; $scratch=null; $process=null; $pipes=[]; $failed=false;
function headersCheck(bool $condition,string $label): void {
    global $count;
    if (!$condition) { throw new RuntimeException('FAIL: '.$label); }
    $count++;
}
function fetchHeaders(string $url,array $headers=[]): array {
    $context=stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headers),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>3]]);
    $body=file_get_contents($url,false,$context);
    $result=[];
    foreach ($http_response_header??[] as $line) {
        if (str_starts_with($line,'HTTP/')) { $result['status']=(int)explode(' ',$line)[1]; continue; }
        if (str_contains($line,':')) { [$name,$value]=explode(':',$line,2); $result[strtolower($name)][]=trim($value); }
    }
    $result['body']=$body;
    return $result;
}
try {
    foreach ([[],['HTTPS'=>'off'],['HTTPS'=>''],['HTTPS'=>'false'],['SERVER_PORT'=>'443'],['HTTP_X_FORWARDED_PROTO'=>'https'],['HTTP_FORWARDED'=>'proto=https'],['HTTPS'=>'off','HTTP_X_FORWARDED_PROTO'=>'https','HTTP_FORWARDED'=>'proto=https','SERVER_PORT'=>'443']] as $server) {
        $headers=SecurityHeaders::forRequest($server);
        headersCheck(!isset($headers['Strict-Transport-Security']),'ordinary HTTP and spoofed proxy/port evidence do not enable HSTS');
        headersCheck($headers['Content-Security-Policy']==="frame-ancestors 'none'"&&$headers['X-Frame-Options']==='DENY','framing is denied independently of HTTPS');
    }
    foreach (['on','ON','1'] as $https) {
        $headers=SecurityHeaders::forRequest(['HTTPS'=>$https]);
        headersCheck($headers['Strict-Transport-Security']==='max-age=86400','real HTTPS starts with a host-only one-day HSTS policy');
        headersCheck(!str_contains($headers['Strict-Transport-Security'],'includeSubDomains')&&!str_contains($headers['Strict-Transport-Security'],'preload'),'HSTS does not affect subdomains or preload');
    }
    headersCheck(SecurityHeaders::forRequest([])['Referrer-Policy']==='no-referrer'&&SecurityHeaders::forRequest([])['X-Content-Type-Options']==='nosniff','baseline response hardening is preserved');

    $scratch=$root.'/work/security-headers-test-'.bin2hex(random_bytes(6));
    foreach (['public_html/api','backend/src'] as $directory) { mkdir($scratch.'/'.$directory,0700,true); }
    foreach (['public_html/api/index.php','public_html/api/install.php','backend/src/SecurityHeaders.php'] as $relative) { copy($root.'/'.$relative,$scratch.'/'.$relative); }
    // Deliberate unavailable-bootstrap fixture. It cannot contact any real DB or mail service.
    file_put_contents($scratch.'/backend/bootstrap.php',"<?php throw new RuntimeException('Synthetic unavailable bootstrap');\n");
    $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    headersCheck(is_resource($listener),'a local ephemeral test port is available');
    $address=stream_socket_get_name($listener,false); fclose($listener);
    $process=proc_open([PHP_BINARY,'-S',$address,'-t',$scratch.'/public_html'],[['pipe','r'],['file',$scratch.'/server.log','ab'],['file',$scratch.'/server.log','ab']],$pipes,$scratch);
    headersCheck(is_resource($process),'isolated PHP HTTP test starts');
    if (isset($pipes[0])) { fclose($pipes[0]); }
    $ready=false;
    for ($i=0;$i<50;$i++) {
        $socket=@stream_socket_client('tcp://'.$address,$errno,$error,.1);
        if (is_resource($socket)) { fclose($socket); $ready=true; break; }
        usleep(20000);
    }
    headersCheck($ready,'isolated HTTP test becomes ready');
    foreach (['index.php','install.php'] as $entry) {
        foreach ([[],['X-Forwarded-Proto: https','Forwarded: proto=https']] as $spoof) {
            $response=fetchHeaders('http://'.$address.'/api/'.$entry,$spoof);
            headersCheck($response['status']===503,'unavailable bootstrap is handled without a real installation');
            headersCheck(($response['content-security-policy']??[])===["frame-ancestors 'none'"],'API error still carries one framing CSP');
            headersCheck(($response['x-frame-options']??[])===['DENY'],'API error still denies framing');
            headersCheck(($response['x-content-type-options']??[])===['nosniff']&&($response['referrer-policy']??[])===['no-referrer'],'API error keeps baseline headers');
            headersCheck(!isset($response['strict-transport-security']),'local HTTP error and proxy spoof do not emit HSTS');
            $json=json_decode($response['body'],true,16,JSON_THROW_ON_ERROR);
            headersCheck($json['ok']===false&&!str_contains($response['body'],'Synthetic unavailable'),'synthetic bootstrap internals are not exposed');
        }
    }
    echo "PASS: {$count} security-header checks; isolated loopback HTTP, no database or SMTP.\n";
} catch (Throwable $e) {
    $failed=true;
    fwrite(STDERR,str_starts_with($e->getMessage(),'FAIL: ')?$e->getMessage()."\n":"Security-header test failed (".get_class($e).") at line ".$e->getLine().".\n");
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if ($scratch&&is_dir($scratch)) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname()); }
        rmdir($scratch);
    }
}
if ($failed) { exit(1); }
