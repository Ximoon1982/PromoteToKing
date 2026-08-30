<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\McaResultsCronService;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Repository;

try {
    $seconds=max(20,min(55,(int)($argv[1]??55)));$started=microtime(true);
    $repo=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if(!$repo->schemaInstalled())$repo->upgradeExistingSchema();
    if(!$repo->schemaInstalled())throw new RuntimeException('Team Points schema is not ready.');
    $service=new McaResultsCronService(PublicReadDatabase::analytics(),$repo);
    // Every-minute runner: discovery is internally due-gated to 12 hours. When due,
    // give it a small resumable slice; the remaining duty cycle drains the durable
    // full-arena backlog with new arenas prioritized over historical backfill.
    $discovery=$service->runDiscovery(min(10,max(5,$seconds-15)),false);
    $elapsed=(int)ceil(microtime(true)-$started);$remaining=max(8,$seconds-$elapsed);
    $acquisition=$service->runHydration($remaining);
    fwrite(STDOUT,json_encode(['ok'=>true,'version'=>'2.10.9.4','discovery'=>$discovery,'acquisition'=>$acquisition,'sync'=>$service->status()],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL);
    exit(0);
} catch (ApiException $e) {
    if($e->errorCode==='MCA_SYNC_BUSY'){fwrite(STDOUT,json_encode(['ok'=>true,'busy'=>true,'version'=>'2.10.9.4']).PHP_EOL);exit(0);}throw $e;
} catch (Throwable $e) {
    fwrite(STDERR,'MCA arena synchronization failed: '.$e->getMessage().PHP_EOL);exit(1);
}
