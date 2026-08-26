<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
require_once dirname(__DIR__,2).'/tournaments/src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\AchievementCatalog;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\LiveRanksService;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\ResponseCache;
use P2K\Tournaments\BrowseIndex;

try {
    Http::method('GET');
    $username=trim((string)($_GET['username']??''));
    if($username===''||strlen($username)>80)throw new ApiException('username is required.',400,'USERNAME_REQUIRED');
    $config=p2k_tp_config();$club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
    $repo=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if(!$repo->schemaInstalled())throw new ApiException('Team Points data is not available yet.',503,'SCHEMA_NOT_INSTALLED');
    $generation=$repo->publicReadGenerationToken($club,true,true);$tournamentIndex=new BrowseIndex();$tournamentGeneration=$tournamentIndex->signature();
    $cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);
    $key='hall-search|'.$club.'|'.$generation.'|tournament:'.$tournamentGeneration.'|'.p2k_tp_username_key($username);
    $entry=$cache->remember($key,120,function()use($repo,$club,$username){
        $hall=$repo->publicHallOfFame($club,'',$username,1,25);$daily=$hall['search']['member']??null;
        $liveService=new LiveRanksService(PublicReadDatabase::analytics(),$repo,new ChessApi($repo));$live=$liveService->publicPlayerPayload($username);if(!($live['available']??false))$live=null;
        $profile=$repo->publicPlayerProfile($club,$username,'search');$player=is_array($profile)?$profile:[];
        $browse=(new BrowseIndex())->index();$needle=p2k_tp_username_key($username);$indexed=$browse['players'][$needle]??[];
        $medals=['gold'=>(int)($indexed['gold']??0),'silver'=>(int)($indexed['silver']??0),'bronze'=>(int)($indexed['bronze']??0),'podiums'=>(int)($indexed['podiums']??0),'participations'=>(int)($indexed['participationCount']??0)];
        $achievements=is_array($player['achievements']??null)?$player['achievements']:[];$total=count(AchievementCatalog::all());
        return ['ok'=>true,'username'=>$player['username']??$daily['username']??$live['username']??$username,'daily'=>$daily,'live'=>$live,'tournaments'=>$medals,'achievements'=>['earned'=>count($achievements),'total'=>$total]];
    },600);
    Http::jsonCacheable($entry['payload'],200,60,300,$entry['etag']);
}catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}catch(Throwable $e){error_log('P2K Hall search: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Hall search is temporarily unavailable.']],500);}
