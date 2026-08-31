<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\{ApiException,Auth,ChessApi,Database,FairPlayReconciliationService,Http,Repository};

try {
    Auth::requireAdmin();
    $config=p2k_tp_config();
    $club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
    $core=Database::core();$repo=new Repository($core,Database::analytics());
    $api=new ChessApi($repo);
    $svc=new FairPlayReconciliationService($core,$repo,$api,$club);
    $action=strtolower(trim((string)($_GET['action']??'status')));

    if($action==='process-match'){
        Http::method('POST');$body=Http::body();$matchId=(int)($body['match_id']??0);
        if($matchId<=0)throw new ApiException('A valid match_id is required.',400,'MATCH_ID_REQUIRED');
        $payload=$api->json('https://api.chess.com/pub/match/'.$matchId,true);
        $status=strtolower(trim((string)($payload['status']??'unknown')));
        $finalize=in_array($status,['finished','complete','completed'],true);
        Http::json(['ok'=>true,'data'=>$svc->applyMatchPayload($matchId,$payload,$finalize,false),'status'=>$svc->status()]);
    }
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
