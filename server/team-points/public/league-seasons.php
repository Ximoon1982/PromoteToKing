<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\{ApiException,Database,Http,Repository,ResponseCache};
try {
    Http::method('GET');
    $config=p2k_tp_config(); $repository=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
    $club=(string)$config['app']['club_slug'];$generation=$repository->publicReadGenerationToken($club,false,false);
    $cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);
    $entry=$cache->remember('league-seasons|'.$club.'|'.$generation,120,static fn()=>['ok'=>true,'meta'=>$repository->publicInsightsMeta($club)]+$repository->publicLeagueSeasons($club),600);
    Http::jsonCacheable($entry['payload'],200,60,300,$entry['etag']);
} catch (ApiException $e) { Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus); }
catch (Throwable $e) { error_log('P2K League seasons: '.$e); Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'League season data is temporarily unavailable.']],500); }
