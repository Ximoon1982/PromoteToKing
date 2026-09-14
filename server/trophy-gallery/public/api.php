<?php
declare(strict_types=1);
require_once __DIR__.'/../../team-points/src/bootstrap.php';
require_once __DIR__.'/../src/TrophyGalleryStore.php';
use P2K\TeamPoints\{ApiException,Auth,Http,PublicReadDatabase};
use P2K\TrophyGallery\TrophyGalleryStore;

function trophy_expected_revision(array $body=[]):?int{$raw=$body['revision']??$_POST['revision']??null;return $raw===null||$raw===''?null:(int)$raw;}
function trophy_mutation(array $result,string $key):array{return['ok'=>true,$key=>$result['value'],'revision'=>$result['revision']];}

try{
 $action=strtolower(trim((string)($_GET['action']??'list')));$store=new TrophyGalleryStore();
 if($action==='list'){Http::method('GET');Http::jsonCacheable(['ok'=>true]+$store->catalogue(true),200,30,120);}
 Auth::requireAdmin();
 if($action==='admin-index'){Http::method('GET');Http::json(['ok'=>true]+$store->catalogueIndex());}
 if($action==='get'){Http::method('GET');Http::json(['ok'=>true]+$store->record((string)($_GET['id']??'')));}
 if($action==='admin-list'){Http::method('GET');Http::json(['ok'=>true]+$store->catalogue(false));}
 if($action==='save'){Http::method('POST');$body=Http::body();Http::json(trophy_mutation($store->save($body,trophy_expected_revision($body)),'record'));}
 if($action==='duplicate'){Http::method('POST');$body=Http::body();Http::json(trophy_mutation($store->duplicate((string)($body['id']??''),trophy_expected_revision($body)),'record'));}
 if($action==='delete'){Http::method('POST');$body=Http::body();Http::json(trophy_mutation($store->delete((string)($body['id']??''),trophy_expected_revision($body)),'deleted'));}
 if($action==='upload'){
  Http::method('POST');$result=$store->uploadAndAssign($_FILES['artwork']??[],(string)($_POST['trophy_id']??''),(string)($_POST['slot']??''),(string)($_POST['source']??'upload'),trophy_expected_revision());
  Http::json(['ok'=>true,'media'=>$result['value']['media'],'record'=>$result['value']['record'],'revision'=>$result['revision']]);
 }
 if($action==='audit'){Http::method('POST');$body=Http::body();Http::json(['ok'=>true,'audit'=>$store->audit((bool)($body['purge']??false))]);}
 if($action==='match-search'){
  Http::method('GET');$q=trim((string)($_GET['q']??''));if(strlen($q)<2)Http::json(['ok'=>true,'matches'=>[]]);
  $pdo=PublicReadDatabase::core();$config=p2k_tp_config();$club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
  $sql="SELECT match_id,match_name,match_url,status,start_time,end_time,opponent_name FROM p2k_tp_match_metadata WHERE club_slug=? AND (match_name LIKE ? OR opponent_name LIKE ? OR CAST(match_id AS CHAR) LIKE ?) ORDER BY COALESCE(end_time,start_time) DESC,match_id DESC LIMIT 25";
  $statement=$pdo->prepare($sql);$like='%'.$q.'%';$statement->execute([$club,$like,$like,$like]);Http::json(['ok'=>true,'matches'=>$statement->fetchAll()?:[]]);
 }
 throw new ApiException('Unknown Trophy Gallery action.',404,'NOT_FOUND');
}catch(ApiException$e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}catch(Throwable$e){error_log('P2K Trophy Gallery: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'TROPHY_ERROR','message'=>$e->getMessage()]],400);}
