<?php
declare(strict_types=1);
// Legacy verification must not read an installation's private configuration or rebuild its database.
if (PHP_SAPI!=='cli') { exit(1); }
fwrite(STDERR,"Compatibility entry point: verifying the combined release without installation, configuration reads, database or SMTP.\n");
require __DIR__.'/release-package.php';
