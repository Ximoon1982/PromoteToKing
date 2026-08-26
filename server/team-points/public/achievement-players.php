<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\ResponseCache;

try {
    Http::method('GET');
    $config=p2k_tp_config();$repository=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
    if(!$repository->schemaInstalled())throw new ApiException('The Team Points database is not installed or upgraded.',503,'SCHEMA_NOT_INSTALLED');
    $club=(string)$config['app']['club_slug'];
    $options=['page'=>(int)($_GET['page']??1),'page_size'=>(int)($_GET['page_size']??12),'filter'=>(string)($_GET['filter']??'current'),'usernames'=>(string)($_GET['usernames']??'')];
    $generation=$repository->publicReadGenerationToken($club,true,true);
    $cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);
    $key='achievement-players|'.$club.'|'.$generation.'|'.hash('sha256',json_encode($options,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $entry=$cache->remember($key,120,static function()use($repository,$club,$options){return ['ok'=>true,'meta'=>$repository->publicReadMeta($club)]+$repository->publicAchievementPlayers($club,$options);},600);
    Http::jsonCacheable($entry['payload'],200,60,300,$entry['etag']);
}catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}
catch(Throwable $e){error_log('P2K Achievement players: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Achievement player data is temporarily unavailable.']],500);}
