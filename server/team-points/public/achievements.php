<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\AchievementCatalog;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\ResponseCache;
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Http;
try {
    Http::method('GET');
    $config=p2k_tp_config();$repository=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
    $club=(string)($config['app']['club_slug']??'promote-to-king');
    if(!$repository->schemaInstalled()){
        Http::jsonCacheable(['ok'=>true,'catalog'=>AchievementCatalog::all(),'artwork_batch'=>['completed'=>10,'next_batch'=>10]],200,300,900);
    }
    $generation=$repository->publicReadGenerationToken($club,true,false);
    $cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);
    $entry=$cache->remember('achievement-catalog|'.$club.'|'.$generation,300,static function()use($repository,$club){return ['ok'=>true,'meta'=>$repository->publicReadMeta($club),'catalog'=>$repository->publicAchievementCatalog($club),'artwork_batch'=>['completed'=>10,'next_batch'=>10]];},1800);
    Http::jsonCacheable($entry['payload'],200,300,900,$entry['etag']);
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K Achievement catalog: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'The achievement catalogue is temporarily unavailable.']],500);
}
