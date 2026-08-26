<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\OAuthSession;
use P2K\TeamPoints\Repository;

function p2k_opponent_slug(array $team): string {
    foreach (['@id','url'] as $field) {
        $value=trim((string)($team[$field]??''));
        $path=trim((string)(parse_url($value,PHP_URL_PATH)?:''),'/');
        if ($path!=='') { $parts=explode('/',$path); $last=strtolower((string)end($parts)); if ($last!==''&&!ctype_digit($last)) return $last; }
    }
    return trim(strtolower(preg_replace('/[^a-z0-9]+/','-',(string)($team['name']??''))??''),'-');
}
function p2k_match_opponent(array $match,string $clubSlug): ?array {
    foreach ((array)($match['teams']??[]) as $team) {
        if (!is_array($team)) continue;
        $slug=p2k_opponent_slug($team);
        if ($slug!==''&&$slug!==strtolower($clubSlug)) return ['slug'=>$slug,'name'=>(string)($team['name']??str_replace('-',' ',$slug)),'url'=>(string)($team['@id']??$team['url']??'')];
    }
    return null;
}
try {
    Auth::requireAdmin();
    $config=p2k_tp_config(); $clubSlug=strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
    $pdo=PublicReadDatabase::core(); $analytics=PublicReadDatabase::analytics(); $repo=new Repository($pdo,$analytics);
    if (!$repo->schemaInstalled()) $repo->upgradeExistingSchema(__DIR__.'/../sql/schema.sql');
    $api=new ChessApi($repo); $action=strtolower(trim((string)($_GET['action']??'list')));
    if ($action==='list') {
        Http::method('GET'); Http::json(['ok'=>true,'rows'=>$repo->opponentAdminRows($clubSlug)]);
    }
    if ($action==='scan') {
        Http::method('POST'); $body=Http::body(); $slugs=is_array($body['slugs']??null)?$body['slugs']:[];
        $inventory=$repo->opponentAdminRows($clubSlug);
        $bySlug=[]; foreach ($inventory as $item) $bySlug[(string)$item['opponent_slug']]=$item;
        if ($slugs===[]) $slugs=array_column($inventory,'opponent_slug');
        $slugs=array_slice(array_values(array_unique(array_filter(array_map('strval',$slugs)))),0,25);
        $oauthConcurrency=max(1,min(256,(int)($body['oauth_concurrency']??8)));$oauthRate=max(0.5,min(120.0,(float)($body['oauth_rate_cps']??8)));
        $oauthRequests=[]; foreach($slugs as $slug)$oauthRequests[]=['id'=>'club:'.$slug,'url'=>'https://api.chess.com/pub/club/'.rawurlencode($slug),'headers'=>[]];
        $oauthBatch=OAuthSession::batchForAuthorizedRequest($oauthRequests,$oauthConcurrency,$oauthRate);
        if(is_array($oauthBatch)){
            $transport=$oauthBatch;unset($transport['results']);$byResult=[];foreach((array)($oauthBatch['results']??[]) as $item)$byResult[(string)($item['id']??'')]=$item;
            $rowsBySlug=[];$fallbackRequests=[];$fallbackCandidates=[];
            foreach($slugs as $slug){
                $known=$bySlug[$slug]??[];$oldName=trim((string)($known['display_name']??$slug))?:$slug;
                $row=['old_slug'=>$slug,'old_name'=>$oldName,'matches'=>(int)($known['matches']??0),'status'=>'unchanged','new_slug'=>$slug,'new_name'=>$oldName,'disabled'=>false,'message'=>'Club endpoint is available.'];
                $item=$byResult['club:'.$slug]??[];$status=(int)($item['status']??0);$club=is_array($item['json']??null)?$item['json']:null;
                if($status>=200&&$status<300&&is_array($club)){
                    $repo->storeOpponentProfileSnapshot($clubSlug,$slug,$club,true);$newSlug=p2k_opponent_slug($club)?:$slug;$newName=trim((string)($club['name']??$slug));$row['new_slug']=$newSlug?:$slug;$row['new_name']=$newName;if(($newSlug&&$newSlug!==$slug)||($newName&&strcasecmp($newName,$oldName)!==0))$row['status']='rename_or_name_update';
                }elseif($status===429||$status===0||$status>=500){
                    $row['status']='retry_error';$row['message']='Temporary Chess.com API pressure/error; no opponent status change is proposed.';
                }else{
                    $candidate=$repo->matchPayloadCandidateForOpponent($clubSlug,$slug);
                    if($candidate){$fallbackCandidates[$slug]=$candidate;$fallbackRequests[]=['id'=>'match:'.$slug,'url'=>'https://api.chess.com/pub/match/'.(int)$candidate['match_id'],'headers'=>[]];}
                    else{$row['status']='disabled';$row['disabled']=true;$row['message']='Club endpoint failed and no replacement slug was found in a stored match.';}
                }
                $rowsBySlug[$slug]=$row;
            }
            if($fallbackRequests!==[]){
                $fallbackBatch=OAuthSession::batchForAuthorizedRequest($fallbackRequests,$oauthConcurrency,$oauthRate);
                if(is_array($fallbackBatch)){
                    $fallbackMap=[];foreach((array)($fallbackBatch['results']??[]) as $item)$fallbackMap[(string)($item['id']??'')]=$item;
                    foreach($fallbackCandidates as $slug=>$candidate){
                        $item=$fallbackMap['match:'.$slug]??[];$status=(int)($item['status']??0);$match=is_array($item['json']??null)?$item['json']:null;$found=($status>=200&&$status<300&&is_array($match))?p2k_match_opponent($match,$clubSlug):null;
                        if($found&&$found['slug']!==$slug){$rowsBySlug[$slug]['status']='renamed';$rowsBySlug[$slug]['new_slug']=$found['slug'];$rowsBySlug[$slug]['new_name']=$found['name'];$rowsBySlug[$slug]['message']='Old endpoint failed; a stored match now identifies a different opponent club.';}
                        elseif($status===429||$status===0||$status>=500){$rowsBySlug[$slug]['status']='retry_error';$rowsBySlug[$slug]['message']='Temporary Chess.com API pressure/error while checking the fallback match; no status change is proposed.';}
                        else{$rowsBySlug[$slug]['status']='disabled';$rowsBySlug[$slug]['disabled']=true;$rowsBySlug[$slug]['message']='Club endpoint failed and no replacement slug was found in a stored match.';}
                    }
                }
            }
            Http::json(['ok'=>true,'rows'=>array_values($rowsBySlug),'oauth_transport'=>$transport]);
        }
        $results=[];
        foreach ($slugs as $slug) {
            $known=$bySlug[$slug]??[];
            $oldName=trim((string)($known['display_name']??$slug))?:$slug;
            $row=['old_slug'=>$slug,'old_name'=>$oldName,'matches'=>(int)($known['matches']??0),'status'=>'unchanged','new_slug'=>$slug,'new_name'=>$oldName,'disabled'=>false,'message'=>'Club endpoint is available.'];
            try {
                $club=$api->json('https://api.chess.com/pub/club/'.rawurlencode($slug),true);
                if (is_array($club)) $repo->storeOpponentProfileSnapshot($clubSlug,$slug,$club,true);
                $newSlug=p2k_opponent_slug($club) ?: $slug;
                $newName=trim((string)($club['name']??$slug));
                $row['new_slug']=$newSlug?:$slug; $row['new_name']=$newName;
                if (($newSlug&&$newSlug!==$slug)||($newName&&strcasecmp($newName,$oldName)!==0)) $row['status']='rename_or_name_update';
            } catch (Throwable $clubError) {
                $candidate=$repo->matchPayloadCandidateForOpponent($clubSlug,$slug);
                $found=null;
                if ($candidate) {
                    try { $match=$api->json('https://api.chess.com/pub/match/'.(int)$candidate['match_id'],true); $found=p2k_match_opponent($match,$clubSlug); }
                    catch (Throwable) { $found=null; }
                }
                if ($found&&$found['slug']!==$slug) {
                    $row['status']='renamed'; $row['new_slug']=$found['slug']; $row['new_name']=$found['name']; $row['message']='Old endpoint failed; a stored match now identifies a different opponent club.';
                } else {
                    $row['status']='disabled'; $row['disabled']=true; $row['message']='Club endpoint failed and no replacement slug was found in a stored match.';
                }
            }
            $results[]=$row;
        }
        Http::json(['ok'=>true,'rows'=>$results]);
    }
    if ($action==='apply') {
        Http::method('POST'); $body=Http::body(); $rows=is_array($body['rows']??null)?$body['rows']:[]; $updated=0;
        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $old=trim(strtolower((string)($row['old_slug']??''))); if ($old==='') continue;
                $repo->recordOpponentCheck($clubSlug,$old,trim(strtolower((string)($row['new_slug']??'')))?:null,trim((string)($row['new_name']??''))?:null,!empty($row['disabled']),(string)($row['message']??'')); $updated++;
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        Http::json(['ok'=>true,'updated'=>$updated]);
    }
    throw new ApiException('Unknown action.',404,'UNKNOWN_ACTION');
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K Opponent admin: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>$e->getMessage()]],500);
}
