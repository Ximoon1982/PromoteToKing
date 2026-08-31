<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\{ApiException,Auth,ChessApi,Database,FairPlayReconciliationService,Http,Repository};

try {
    Auth::requireAdmin();
    $config=p2k_tp_config();
    $club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
    $core=Database::core();$repo=new Repository($core,Database::analytics());
    $svc=new FairPlayReconciliationService($core,$repo,new ChessApi($repo),$club);
    $action=strtolower(trim((string)($_GET['action']??'status')));

    if($action==='run-step'){
        Http::method('POST');$body=Http::body();
        $limit=max(1,min(10,(int)($body['limit']??3)));
        Http::json(['ok'=>true,'data'=>$svc->runStep($limit,microtime(true)+25.0)]);
    }
    if(in_array($action,['start','resume','pause','restart'],true)){
        Http::method('POST');
        Http::json(['ok'=>true,'data'=>$svc->control($action)]);
    }
    Http::method('GET');
    Http::json(['ok'=>true,'data'=>$svc->status()]);
} catch(ApiException $e){
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch(Throwable $e){
    error_log('P2K fair-play maintenance: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Fair-play maintenance failed safely; canonical game results were not rewritten.']],500);
}
