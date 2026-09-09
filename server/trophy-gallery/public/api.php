<?php
declare(strict_types=1);
require_once __DIR__.'/../../team-points/src/bootstrap.php';
require_once __DIR__.'/../src/TrophyGalleryStore.php';
use P2K\TeamPoints\{ApiException,Auth,Http,PublicReadDatabase};
use P2K\TrophyGallery\TrophyGalleryStore;
try {
    $action=strtolower(trim((string)($_GET['action']??'list'))); $store=new TrophyGalleryStore();
    if($action==='list'){Http::method('GET');Http::jsonCacheable(['ok'=>true,'records'=>$store->records(true)],200,30,120);}
    Auth::requireAdmin();
    if($action==='admin-list'){Http::method('GET');Http::json(['ok'=>true,'records'=>$store->records(false)]);}
    if($action==='save'){Http::method('POST');Http::json(['ok'=>true,'record'=>$store->save(Http::body())]);}
    if($action==='delete'){Http::method('POST');Http::json(['ok'=>true,'deleted'=>$store->delete((string)(Http::body()['id']??''))]);}
    if($action==='upload'){Http::method('POST');Http::json(['ok'=>true,'media'=>$store->upload($_FILES['artwork']??[])]);}
    if($action==='audit'){Http::method('POST');Http::json(['ok'=>true,'audit'=>$store->audit((bool)(Http::body()['purge']??false))]);}
    if($action==='match-search'){
        Http::method('GET');$q=trim((string)($_GET['q']??''));$pdo=PublicReadDatabase::core();$config=p2k_tp_config();$club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
        $s=$pdo->prepare("SELECT match_id,match_name,match_url,status,start_time,end_time,opponent_name FROM p2k_tp_match_metadata WHERE club_slug=? AND (match_name LIKE ? OR opponent_name LIKE ? OR CAST(match_id AS CHAR) LIKE ?) ORDER BY COALESCE(end_time,start_time) DESC,match_id DESC LIMIT 30");$like='%'.$q.'%';$s->execute([$club,$like,$like,$like]);Http::json(['ok'=>true,'matches'=>$s->fetchAll()?:[]]);
    }
    throw new ApiException('Unknown Trophy Gallery action.',404,'NOT_FOUND');
} catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);} catch(Throwable $e){error_log('P2K Trophy Gallery: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'TROPHY_ERROR','message'=>$e->getMessage()]],400);}
