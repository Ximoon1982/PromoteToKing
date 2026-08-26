<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\{ApiException,Database,Http,Repository,ResponseCache};
try{
 Http::method('GET');$config=p2k_tp_config();$repository=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());if(!$repository->schemaInstalled())throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
 $club=(string)$config['app']['club_slug'];$section=strtolower(trim((string)($_GET['section']??'all')));$options=['page'=>(int)($_GET['page']??1),'page_size'=>(int)($_GET['page_size']??25),'search'=>(string)($_GET['search']??''),'filter'=>(string)($_GET['filter']??'all'),'sort'=>(string)($_GET['sort']??'total'),'direction'=>(string)($_GET['direction']??'desc'),'include_summary'=>(string)($_GET['include_summary']??'1')!=='0','section'=>$section];
 $meta=$repository->publicReadMeta($club);$cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);
 if($section==='balance'){
   // Heatmaps are historical aggregates. A stable versioned cache key avoids
   // expensive cold rebuilds whenever unrelated Core work advances generation.
   // A 15-minute bound is negligible for this view and dramatically improves IONOS reads.
   $cacheKey='opponents-balance-v3|'.$club;$ttl=900;$stale=3600;$browserTtl=300;$browserStale=1800;
 }else{
   $generations=is_array($meta['generations']??null)?$meta['generations']:[];
   $gen=substr(hash('sha256','core:'.(string)($generations['core']??'none').'|analytics:'.(string)($generations['analytics']??'none')),0,24);
   $cacheKey='opponents|'.$club.'|'.$gen.'|'.hash('sha256',json_encode($options));$ttl=120;$stale=600;$browserTtl=60;$browserStale=300;
 }
 $entry=$cache->remember($cacheKey,$ttl,static fn()=>['ok'=>true,'meta'=>$meta]+($section==='all'?$repository->publicOpponentStats($club,$options):$repository->publicOpponentStatsSection($club,$options,$section)),$stale);
 Http::jsonCacheable($entry['payload'],200,$browserTtl,$browserStale,$entry['etag']);
}catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}catch(Throwable $e){error_log('P2K Opponents: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>$e->getMessage()]],500);}
