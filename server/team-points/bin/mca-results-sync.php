<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\McaDiscoveryService;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Repository;

// v2.10.6.25 compatibility entry point. Existing twice-daily CRON lines may keep
// invoking this filename, but it is discovery-only now. Historical stored MCA
// dates are never backfilled by CRON; hydration and rebuild have separate jobs.
try {
    $seconds=max(20,min(300,(int)($argv[1]??120)));
    $repo=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if(!$repo->schemaInstalled())$repo->upgradeExistingSchema();
    if(!$repo->schemaInstalled())throw new RuntimeException('Team Points schema is not ready.');
    $service=new McaDiscoveryService(PublicReadDatabase::analytics(),$repo,new ChessApi($repo));
    $state=$service->runDiscoveryCron($seconds);
    fwrite(STDOUT,json_encode(['ok'=>true,'version'=>'2.10.6.25','job'=>'discovery-compat','sync'=>$state],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL);
    exit(0);
} catch(Throwable $e) {
    fwrite(STDERR,'MCA discovery failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
