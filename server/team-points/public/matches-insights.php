<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\{ApiException,Database,Http,Repository,ResponseCache};
try{
 Http::method('GET');$config=p2k_tp_config();$repository=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());if(!$repository->schemaInstalled())throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
 $club=(string)$config['app']['club_slug'];$section=strtolower(trim((string)($_GET['section']??'all')));$options=['page'=>(int)($_GET['page']??1),'page_size'=>(int)($_GET['page_size']??25),'search'=>(string)($_GET['search']??''),'filter'=>(string)($_GET['filter']??'all'),'sort'=>(string)($_GET['sort']??'start_time'),'direction'=>(string)($_GET['direction']??'desc'),'include_summary'=>(string)($_GET['include_summary']??'1')!=='0','section'=>$section];
 $gen=$repository->publicReadGenerationToken($club,false,false);$cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);$entry=$cache->remember('matches-insights|'.$club.'|'.$gen.'|'.hash('sha256',json_encode($options)),120,static fn()=>['ok'=>true,'meta'=>$repository->publicReadMeta($club)]+($section==='all'?$repository->publicMatchInsights($club,$options):$repository->publicMatchInsightsSection($club,$options,$section)),600);Http::jsonCacheable($entry['payload'],200,60,300,$entry['etag']);
}catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}catch(Throwable $e){error_log('P2K Match insights: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>$e->getMessage()]],500);}
