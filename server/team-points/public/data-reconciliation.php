<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\{ApiException,Auth,DataReconciliationService,Database,Http,Repository};
try {
    Auth::requireAdmin();
    $config=p2k_tp_config();$club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
    $repo=new Repository(Database::core(),Database::analytics());$svc=new DataReconciliationService($club,$repo);
    $action=strtolower(trim((string)($_GET['action']??'status')));
    if($action==='upload'){
        Http::method('POST');$flat=[];foreach($_FILES as $file){if(!is_array($file))continue;if(is_array($file['name']??null)){foreach($file['name'] as $i=>$name)$flat[]=['name'=>$name,'tmp_name'=>$file['tmp_name'][$i]??'','size'=>$file['size'][$i]??0,'error'=>$file['error'][$i]??UPLOAD_ERR_NO_FILE];}else$flat[]=$file;}
        Http::json(['ok'=>true,'data'=>$svc->createBatch($flat)]);
    }
    if($action==='check'){Http::method('POST');$b=Http::body();Http::json(['ok'=>true,'data'=>$svc->inspect((string)($b['batch_id']??''))]);}
    if($action==='apply-step'){Http::method('POST');$b=Http::body();Http::json(['ok'=>true,'data'=>$svc->applyStep((string)($b['batch_id']??''),(string)($b['confirmation']??''),is_array($b['options']??null)?$b['options']:[],(int)($b['limit']??250))]);}
    Http::method('GET');Http::json(['ok'=>true,'data'=>$svc->batch((string)($_GET['batch_id']??''))]);
} catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}catch(Throwable $e){error_log('P2K data reconciliation: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Data reconciliation failed safely; no unchecked canonical overwrite was performed.']],500);}
