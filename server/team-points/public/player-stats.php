<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\Shared\SharedChessGateway;
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
try {
    Http::method('GET');
    $username=trim((string)($_GET['username']??''));
    if ($username==='' || strlen($username)>80 || !preg_match('/^[A-Za-z0-9_-]+$/',$username)) throw new ApiException('A valid Chess.com username is required.',400,'INVALID_USERNAME');
    $config=p2k_tp_config(); $club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
    $repo=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if (!$repo->schemaInstalled()) throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
    if (!$repo->schemaInstalled()) throw new ApiException('The Team Points database is not installed or upgraded.',503,'SCHEMA_NOT_INSTALLED');
    $gateway=new SharedChessGateway(null,$config['app']??[]);
    $stats=$gateway->json('https://api.chess.com/pub/player/'.rawurlencode(strtolower($username)).'/stats',[
        'consumer'=>'member-rating-snapshot','cache_ttl_seconds'=>1800,'max_stale_seconds'=>86400,'allow_stale_on_error'=>true,
    ]);
    if (!is_array($stats)) throw new ApiException('Chess.com statistics are unavailable for this member.',404,'PLAYER_STATS_UNAVAILABLE');
    $ratings=Repository::ratingsFromStats($stats);
    $stored=$repo->storeMemberRatings($club,$username,$ratings['daily_rating'],$ratings['chess960_rating']);
    $stats['_p2k_rating_snapshot']=['stored'=>$stored,'daily_rating'=>$ratings['daily_rating'],'chess960_rating'=>$ratings['chess960_rating'],'observed_at'=>gmdate('c')];
    Http::json($stats);
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K player stats snapshot: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Player ratings are temporarily unavailable.']],500);
}
