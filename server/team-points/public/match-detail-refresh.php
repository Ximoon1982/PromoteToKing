<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\Worker;

try {
    Auth::requireAdmin();
    Http::method('POST');
    $body=Http::body();$matchId=(int)($body['match_id']??$body['match']??0);
    if($matchId<=0)throw new ApiException('match_id is required.',400,'MATCH_ID_REQUIRED');

    $source=PublicReadDatabase::source();
    $core=PublicReadDatabase::core();$analytics=PublicReadDatabase::analytics();
    $repo=new Repository($core,$analytics);
    if(!$repo->schemaInstalled())$repo->upgradeExistingSchema();
    if(!$repo->schemaInstalled())throw new ApiException('Team Points schema is not ready for the immediate refresh.',503,'SCHEMA_NOT_INSTALLED');

    $api=new ChessApi($repo);$refresh=[];
    if($source==='green'){
        require_once dirname(__DIR__,2).'/team-points-green/src/bootstrap.php';
        $green=\P2K\Green\GreenRepository::open();
        $payload=$api->json('https://api.chess.com/pub/match/'.$matchId,true);
        $refresh=$green->storeMatch($matchId,$payload,200);
        $projection=(new \P2K\Green\GreenCompatibility($green))->projectMatch($matchId);
        $refresh['compatibility_projection']=$projection;
    }else{
        $worker=new Worker($core,$repo,$api,'club');
        $refresh=$worker->refreshMatchNow($matchId);
    }

    // Read after write, without the normal match-detail response cache.
    $match=$repo->publicMatchDetail((string)(p2k_tp_config()['app']['club_slug']??'promote-to-king'),$matchId);
    Http::json(['ok'=>true,'source'=>$source,'refreshed_at'=>gmdate(DATE_ATOM),'refresh'=>$refresh,'match'=>$match]);
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K immediate match refresh: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>$e->getMessage()]],500);
}
