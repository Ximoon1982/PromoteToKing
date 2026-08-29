<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Repository;

try {
    $repo=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if(!$repo->schemaInstalled())$repo->upgradeExistingSchema();
    if(!$repo->schemaInstalled())throw new RuntimeException('Team Points schema is not ready after v2.10.9 upgrade.');
    fwrite(STDOUT,json_encode(['ok'=>true,'version'=>'2.10.9','schema'=>'ready'],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR,'P2K v2.10.9 schema upgrade failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
