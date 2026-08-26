<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\LiveRanksService;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Repository;

try{
    $seconds=max(20,min(300,(int)($argv[1]??120)));
    $repo=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if(!$repo->schemaInstalled())$repo->upgradeExistingSchema();
    if(!$repo->schemaInstalled())throw new RuntimeException('Team Points schema is not ready.');
    $service=new LiveRanksService(PublicReadDatabase::analytics(),$repo,new ChessApi($repo));
    $state=$service->runAutoSyncCron($seconds);
    fwrite(STDOUT,json_encode(['ok'=>true,'version'=>'2.10.6','sync'=>$state],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL);
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,'MCA Results Auto-Sync failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
