<?php
declare(strict_types=1);

require_once __DIR__.'/../src/bootstrap.php';

use P2K\TeamPoints\{ApiException,Auth,Http,RecruitmentHistoryStore};

try{
    Auth::requireAdmin();
    $store=RecruitmentHistoryStore::fromConfig(p2k_tp_config());
    $action=strtolower(trim((string)($_GET['action']??'list')));

    if($action==='list'){
        Http::method('GET');
        $page=max(1,(int)($_GET['page']??1));
        $perPage=max(1,min(RecruitmentHistoryStore::MAX_PAGE_SIZE,(int)($_GET['per_page']??RecruitmentHistoryStore::DEFAULT_PAGE_SIZE)));
        Http::json(['ok'=>true,'history'=>$store->page($page,$perPage)]);
    }

    if($action==='download'){
        Http::method('GET');
        $id=trim((string)($_GET['id']??''));
        $entry=$store->get($id);
        if(!is_array($entry)) throw new ApiException('Recruitment archive not found.',404,'ARCHIVE_NOT_FOUND');
        $path=$store->pathFor($entry);
        if(!is_file($path)) throw new ApiException('Recruitment CSV file is unavailable.',404,'ARCHIVE_FILE_MISSING');
        $name='p2k-match-'.(int)($entry['match_id']??0).'-eligible-'.preg_replace('/[^0-9TZ]+/','',(string)($entry['created_at']??'')).'.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.(string)filesize($path));
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    if($action==='archive'){
        Http::method('POST');
        $body=Http::body();
        $matchId=(int)($body['match_id']??0);
        $matchName=(string)($body['match_name']??'');
        $csv=(string)($body['csv']??'');
        $entry=$store->archive($matchId,$matchName,$csv);
        Http::json(['ok'=>true,'entry'=>$entry]);
    }

    if($action==='delete'){
        Http::method('POST');
        $body=Http::body();
        $id=trim((string)($body['id']??''));
        if($id==='') throw new ApiException('Recruitment archive ID is required.',400,'ARCHIVE_ID_REQUIRED');
        if(!$store->delete($id)) throw new ApiException('Recruitment archive not found.',404,'ARCHIVE_NOT_FOUND');
        Http::json(['ok'=>true]);
    }

    throw new ApiException('Unknown recruitment history action.',404,'UNKNOWN_ACTION');
}catch(ApiException $e){
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
}catch(Throwable $e){
    error_log('P2K recruitment history: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Recruitment history operation failed safely.']],500);
}
