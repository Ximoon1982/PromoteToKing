<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\OAuthSession;
use P2K\TeamPoints\RuntimeTelemetry;
try{
    $action=strtolower(trim((string)($_GET['action']??'session')));
    if($action==='session'){Http::method('GET');Http::json(OAuthSession::sessionInfo());}
    if($action==='login'){Http::method('GET');OAuthSession::login((string)($_GET['return']??'/ui-v2.html?ui=v2'));}
    if($action==='logout'){Http::method('POST');$body=Http::body();Http::json(OAuthSession::logout((string)($_SERVER['HTTP_X_P2K_OAUTH_CSRF']??$body['csrf']??'')));}
    if($action==='batch'){Http::method('POST');$body=Http::body();$requests=is_array($body['requests']??null)?$body['requests']:[];$concurrency=max(1,min(256,(int)($body['concurrency']??32)));$rawRate=(float)($body['rate_cps']??0);$rate=$rawRate>0?max(0.5,min(120.0,$rawRate)):0.0;$traffic=strtolower(trim((string)($body['traffic_class']??'foreground')))==='background'?'background':'foreground';Http::json(OAuthSession::batch($requests,$concurrency,(string)($_SERVER['HTTP_X_P2K_OAUTH_CSRF']??$body['csrf']??''),$rate,$traffic));}
    if($action==='throughput'){Http::method('GET');Auth::requireAdmin();$minutes=max(1,min(60,(int)($_GET['minutes']??10)));Http::json(['ok'=>true,'minutes'=>$minutes,'rows'=>RuntimeTelemetry::chessApiThroughput($minutes)]);}
    throw new ApiException('Unknown OAuth action.',404,'OAUTH_ACTION_NOT_FOUND');
}catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}catch(Throwable $e){error_log('P2K OAuth: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'OAUTH_SERVER_ERROR','message'=>'Chess.com OAuth is temporarily unavailable.']],500);}
