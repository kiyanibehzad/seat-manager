<?php
declare(strict_types=1);
// Compatibility entry point: there is only one release policy and no generated installation secrets.
if (PHP_SAPI!=='cli') { exit(1); }
fwrite(STDERR,"Compatibility entry point: building the combined release via bin/build-release.php. Prepare frontend assets and PHP dependencies first.\n");
require __DIR__.'/build-release.php';
