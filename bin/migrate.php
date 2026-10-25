<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try { $app=require dirname(__DIR__).'/backend/bootstrap.php'; $versions=(new AccManager\Migrator($app))->run(); echo 'Migrations applied: '.count($versions).PHP_EOL; }
catch (Throwable $e) { fwrite(STDERR,"Migration failed; check private configuration and database.\n"); exit(1); }
