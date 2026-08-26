<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\Green\GreenConfig;
use P2K\Green\GreenRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}
if (!is_file(GreenConfig::localPath())) {
    fwrite(STDOUT, "Green local configuration is not installed; schema migration skipped.\n");
    exit(0);
}
try {
    $repo=GreenRepository::open();
    $repo->initializeSchemas();
    $state=$repo->state();
    fwrite(STDOUT, "Green v2.10.5 schema ready; cycle #".(int)($state['cycle_no']??0).", public reads ".(string)($state['public_read_target']??'blue').".\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Green v2.10.5 schema migration failed: ".$e->getMessage()."\n");
    exit(1);
}
