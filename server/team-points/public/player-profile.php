<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\AchievementCatalog;
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\ResponseCache;
try {
    Http::method('GET');
    $username=trim((string)($_GET['username']??''));
    if ($username==='' || strlen($username)>80) throw new ApiException('username is required.',400,'USERNAME_REQUIRED');
    $config=p2k_tp_config(); $repository=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
    $club=(string)$config['app']['club_slug'];$usernameKey=p2k_tp_username_key($username);$mode=(string)($_GET['mode']??'full');if(!in_array($mode,['full','modal','search'],true))$mode='full';
    $generation=$repository->publicReadGenerationToken($club,true,true);
    $cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);
    $entry=$cache->remember('player-profile|'.$club.'|'.$usernameKey.'|'.$mode.'|'.$generation,120,static function()use($repository,$club,$username,$mode){$meta=$repository->publicReadMeta($club);$meta['achievement_total']=count(AchievementCatalog::all());return ['ok'=>true,'meta'=>$meta,'player'=>$repository->publicPlayerProfile($club,$username,$mode)];},600);
    Http::jsonCacheable($entry['payload'],200,60,300,$entry['etag']);
} catch (ApiException $e) { Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus); }
catch (Throwable $e) { error_log('P2K Player profile: '.$e); Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Player profile data is temporarily unavailable.']],500); }
