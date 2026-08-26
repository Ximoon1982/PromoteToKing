<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\Shared\SharedChessGateway;
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
try {
    Http::method('GET');
    $raw=(string)($_GET['usernames']??'');
    $usernames=array_values(array_unique(array_filter(array_map(static fn(string $value):string=>trim($value),explode(',',$raw)))));
    if($usernames===[])throw new ApiException('At least one username is required.',400,'USERNAME_REQUIRED');
    if(count($usernames)>12)$usernames=array_slice($usernames,0,12);
    foreach($usernames as $username)if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$username))throw new ApiException('A username is invalid.',400,'INVALID_USERNAME');

    $config=p2k_tp_config();$club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
    $repo=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if(!$repo->schemaInstalled())throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
    $keys=array_map(static fn(string $u):string=>\p2k_tp_username_key($u),$usernames);
    $stored=$repo->playerProfileSnapshots($club,$keys);
    $gateway=null;$profiles=[];$now=time();$refreshAge=30*86400;

    foreach($usernames as $username){
        $key=\p2k_tp_username_key($username);$snapshot=$stored[$key]??null;
        $checked=is_array($snapshot)&&!empty($snapshot['avatar_checked_at'])?(strtotime((string)$snapshot['avatar_checked_at'].' UTC')?:0):0;
        $needsLookup=!is_array($snapshot)||$checked<=0||($checked<$now-$refreshAge && trim((string)($snapshot['avatar_url']??''))==='');
        if($needsLookup){
            try{
                $gateway??=new SharedChessGateway(null,$config['app']??[]);
                $payload=$gateway->json('https://api.chess.com/pub/player/'.rawurlencode(strtolower($username)),[
                    'consumer'=>'achievement-player-card','cache_ttl_seconds'=>$refreshAge,'max_stale_seconds'=>180*86400,'allow_stale_on_error'=>true,
                ]);
                if(is_array($payload)){$repo->storePlayerProfileSnapshot($club,$username,$payload,true);$snapshot=$repo->playerProfileSnapshot($club,$username);}
            }catch(Throwable){/* stale/fallback is intentionally usable */}
        }
        $snapshot=is_array($snapshot)?$snapshot:[];
        $profiles[$key]=[
            'username'=>(string)($snapshot['username']??$username),'avatar'=>(string)($snapshot['avatar_url']??''),
            'country'=>(string)($snapshot['country_code']??''),'url'=>(string)($snapshot['profile_url']??('https://www.chess.com/member/'.rawurlencode($username))),
            'status'=>(string)($snapshot['profile_status']??''),'avatar_checked_at'=>$snapshot['avatar_checked_at']??null,
        ];
    }
    // Avatar URLs change rarely. Login/profile access revalidates explicitly through opportunistic profile ingestion.
    Http::jsonCacheable(['ok'=>true,'profiles'=>$profiles],200,30*86400,90*86400);
}catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}
catch(Throwable $e){error_log('P2K player cards: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Player profile images are temporarily unavailable.']],500);}
