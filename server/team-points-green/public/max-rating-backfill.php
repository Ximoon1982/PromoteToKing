<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\Green\GreenConfig;
use P2K\Green\GreenRepository;

try {
    GreenConfig::authorizeAdmin();
    $repo=GreenRepository::open();
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $action=strtolower(trim((string)($_GET['action']??'status')));

    if($method==='GET' && $action==='status'){
        GreenConfig::json(['ok'=>true,'snapshot'=>$repo->maxRatingBackfillSnapshot()]);
    }

    if($method==='GET' && $action==='candidates'){
        $limit=max(1,min(1000,(int)($_GET['limit']??250)));
        $rows=$repo->maxRatingBackfillCandidates($limit);
        GreenConfig::json(['ok'=>true,'rows'=>$rows,'count'=>count($rows),'snapshot'=>$repo->maxRatingBackfillSnapshot()]);
    }

    if($method==='POST' && $action==='ingest'){
        $body=GreenConfig::body();
        $rows=is_array($body['rows']??null)?$body['rows']:[];
        if($rows===[]||count($rows)>100)throw new RuntimeException('Provide between 1 and 100 backfill rows.');
        $results=[];$accepted=0;$states=['capped'=>0,'open'=>0,'unavailable'=>0,'unknown'=>0];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $matchId=(int)($row['match_id']??0);
            $payload=is_array($row['payload']??null)?$row['payload']:null;
            if($matchId<=0||$payload===null)continue;
            try{
                $result=$repo->storeMaxRatingBackfill($matchId,$payload);
                $state=(string)($result['state']??'unknown');
                if(isset($states[$state]))$states[$state]++;
                $results[]=['ok'=>true]+$result;$accepted++;
            }catch(Throwable $rowError){
                $results[]=['ok'=>false,'match_id'=>$matchId,'error'=>$rowError->getMessage()];
            }
        }
        GreenConfig::json(['ok'=>true,'accepted'=>$accepted,'states'=>$states,'results'=>$results,'snapshot'=>$repo->maxRatingBackfillSnapshot()]);
    }

    if($method==='POST' && $action==='mark-unavailable'){
        $body=GreenConfig::body();
        $ids=is_array($body['match_ids']??null)?$body['match_ids']:[];
        if(count($ids)>1000)throw new RuntimeException('At most 1000 match ids may be marked unavailable at once.');
        $changed=$repo->markMaxRatingUnavailable($ids);
        GreenConfig::json(['ok'=>true,'changed'=>$changed,'snapshot'=>$repo->maxRatingBackfillSnapshot()]);
    }

    GreenConfig::json(['ok'=>false,'error'=>'Unknown max-rating backfill action.'],404);
} catch(Throwable $e) {
    GreenConfig::json(['ok'=>false,'error'=>$e->getMessage()],500);
}
