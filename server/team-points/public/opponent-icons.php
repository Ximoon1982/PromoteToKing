<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\Shared\SharedChessGateway;
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;

function p2k_opponent_canonical_from_match(array $match,string $clubSlug): ?array {
    foreach(is_array($match['teams']??null)?$match['teams']:[] as $key=>$team){
        if(!is_array($team))continue;
        $slug=Repository::clubSlugFromTeamPayload($team,is_string($key)?$key:'');
        if($slug===''||$slug===strtolower($clubSlug))continue;
        $url=trim((string)($team['@id']??$team['url']??''));
        return ['slug'=>$slug,'name'=>trim((string)($team['name']??''))?:$slug,'url'=>$url];
    }
    return null;
}

try {
    Http::method('GET');
    $raw=(string)($_GET['slugs']??'');
    $slugs=array_values(array_unique(array_filter(array_map(static fn(string $v):string=>strtolower(trim($v)),explode(',',$raw)))));
    if($slugs===[])throw new ApiException('At least one opponent slug is required.',400,'OPPONENT_REQUIRED');
    if(count($slugs)>15)$slugs=array_slice($slugs,0,15);
    foreach($slugs as $slug)if(!preg_match('/^[a-z0-9_-]{1,160}$/',$slug))throw new ApiException('An opponent slug is invalid.',400,'INVALID_OPPONENT');
    $config=p2k_tp_config();$club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
    $repo=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if(!$repo->schemaInstalled())throw new ApiException('Team Points schema must be upgraded before opponent icons are available.',503,'SCHEMA_NOT_INSTALLED');
    $stored=$repo->opponentProfileSnapshots($club,$slugs);$gateway=null;$profiles=[];$now=time();$refreshAge=30*86400;$missingRetryAge=86400;$refreshBudget=3;
    foreach($slugs as $requestedSlug){
        $snapshot=$stored[$requestedSlug]??null;
        $lookupSlug=$requestedSlug;
        if(is_array($snapshot)){
            $urlSlug=Repository::chessClubSlugFromUrl((string)($snapshot['club_url']??''));
            if($urlSlug!=='')$lookupSlug=$urlSlug;
        }
        $checked=is_array($snapshot)&&!empty($snapshot['icon_checked_at'])?(strtotime((string)$snapshot['icon_checked_at'].' UTC')?:0):0;
        $hasIcon=is_array($snapshot)&&trim((string)($snapshot['icon_url']??''))!=='';
        $due=!is_array($snapshot)||$checked<=0||(!$hasIcon&&$checked<$now-$missingRetryAge)||($hasIcon&&$checked<$now-$refreshAge);
        $lookupFailed=false;
        if($due && $refreshBudget>0){
            $refreshBudget--;
            try{
                $gateway??=new SharedChessGateway(null,$config['app']??[]);
                $payload=$gateway->json('https://api.chess.com/pub/club/'.rawurlencode($lookupSlug),['consumer'=>'opponent-icon','cache_ttl_seconds'=>$refreshAge,'max_stale_seconds'=>180*86400,'allow_stale_on_error'=>true]);
                if(is_array($payload)){
                    $repo->storeOpponentProfileSnapshot($club,$lookupSlug,$payload,true);
                    $snapshot=$repo->opponentProfileSnapshot($club,$lookupSlug);
                }
            }catch(Throwable){$lookupFailed=true;}
        }
        // Self-heal legacy aliases (notably non-ASCII display names that were once
        // slugified without a Chess.com @id/url) from one authoritative stored match.
        if(($lookupFailed || !is_array($snapshot) || trim((string)($snapshot['icon_url']??''))==='') && $refreshBudget>0){
            $candidate=$repo->matchPayloadCandidateForOpponent($club,$requestedSlug);
            if(is_array($candidate)&&!empty($candidate['match_id'])){
                try{
                    $refreshBudget--;$gateway??=new SharedChessGateway(null,$config['app']??[]);
                    $match=$gateway->json('https://api.chess.com/pub/match/'.(int)$candidate['match_id'],['consumer'=>'opponent-icon-repair','cache_ttl_seconds'=>86400,'max_stale_seconds'=>30*86400,'allow_stale_on_error'=>true]);
                    $resolved=is_array($match)?p2k_opponent_canonical_from_match($match,$club):null;
                    if(is_array($resolved)&&$resolved['slug']!==''&&$resolved['slug']!==$requestedSlug){
                        $lookupSlug=(string)$resolved['slug'];
                        $repo->recordOpponentCheck($club,$requestedSlug,$lookupSlug,(string)$resolved['name'],false,'Legacy opponent slug repaired from authoritative match team URL.');
                        $payload=$gateway->json('https://api.chess.com/pub/club/'.rawurlencode($lookupSlug),['consumer'=>'opponent-icon-repair','cache_ttl_seconds'=>$refreshAge,'max_stale_seconds'=>180*86400,'allow_stale_on_error'=>true]);
                        if(is_array($payload)){$repo->storeOpponentProfileSnapshot($club,$lookupSlug,$payload,true);$snapshot=$repo->opponentProfileSnapshot($club,$lookupSlug);}
                    }
                }catch(Throwable){}
            }
        }
        $snapshot=is_array($snapshot)?$snapshot:[];
        $profiles[$requestedSlug]=['slug'=>$lookupSlug,'requested_slug'=>$requestedSlug,'name'=>(string)($snapshot['display_name']??$requestedSlug),'icon'=>(string)($snapshot['icon_url']??''),'url'=>(string)($snapshot['club_url']??('https://www.chess.com/club/'.rawurlencode($lookupSlug))),'icon_checked_at'=>$snapshot['icon_checked_at']??null];
    }
    Http::jsonCacheable(['ok'=>true,'profiles'=>$profiles],200,30*86400,90*86400);
}catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}catch(Throwable $e){error_log('P2K opponent icons: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Opponent icons are temporarily unavailable.']],500);}
