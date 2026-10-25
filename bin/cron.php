<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('zend.exception_ignore_args','1');
try {
    $app=require dirname(__DIR__).'/backend/bootstrap.php';
    $reminders=(new AccManager\Reminders($app))->dispatch(100);
    $counts=(new AccManager\MailWorker($app))->run(20,40);
    $counts['reminders']=$reminders;
    // Retention only: never change any assignment, seat or capacity.
    $app->db->run('DELETE FROM rate_limits WHERE window_started<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY)');
    $app->db->run("UPDATE mail_outbox SET payload_encrypted=NULL WHERE status IN ('failed','uncertain','cancelled') AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)");
    echo json_encode($counts,JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) { if (isset($app)) { $app->log('cron_error'); } fwrite(STDERR,"Cron failed; inspect private service status.\n"); exit(1); }
