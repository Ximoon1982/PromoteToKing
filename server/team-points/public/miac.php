<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\LiveRanksService;
use P2K\TeamPoints\MiacService;
use P2K\TeamPoints\Repository;

try {
    Auth::requireAdmin();
    $core=PublicReadDatabase::core();$analytics=PublicReadDatabase::analytics();$repo=new Repository($core,$analytics);
    if(!$repo->schemaInstalled())$repo->upgradeExistingSchema();
    if(!$repo->schemaInstalled())throw new ApiException('MIAC requires the current Team Points schema.',503,'SCHEMA_NOT_INSTALLED');
    $club=strtolower((string)(p2k_tp_config()['app']['club_slug']??'promote-to-king'));
    $miac=new MiacService($core,$club);$miac->importSeedIfNeeded();
    $action=strtolower(trim((string)($_GET['action']??'summary')));
    if($action==='summary'){
        Http::method('GET');
        Http::json(['ok'=>true,'miac'=>$miac->summary(max(1,min(500,(int)($_GET['limit']??500))),(string)($_GET['search']??''))]);
    }
    if($action==='review'){
        Http::method('POST');$body=Http::body();
        $old=(string)($body['old_username']??'');$new=(string)($body['new_username']??'');$decision=(string)($body['decision']??'');
        $result=$miac->reviewEdge($old,$new,$decision,'admin');
        $mira=(new LiveRanksService($analytics,$repo,new ChessApi($repo)))->identityAttributionStatus();
        Http::json(['ok'=>true,'review'=>$result,'miac'=>$miac->summary(500),'mira'=>$mira]);
    }
    throw new ApiException('Unknown MIAC action.',404,'UNKNOWN_ACTION');
}catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}
catch(Throwable $e){error_log('P2K MIAC admin: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>$e->getMessage()]],500);}
