<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
use P2K\Green\GreenConfig;
use P2K\Green\GreenRepository;
use P2K\Green\GreenAnalyticsBootstrap;
use P2K\Green\GreenCompatibility;
try{
    GreenConfig::authorizeAdmin();
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')GreenConfig::json(['ok'=>false,'error'=>'Use POST.'],405);
    $repo=GreenRepository::open();$state=$repo->state();$target=(string)($state['client_ingest_target']??'blue');
    if(!in_array($target,['green','both'],true))GreenConfig::json(['ok'=>true,'accepted'=>0,'disabled'=>true,'reason'=>'Green client ingest target is '.$target]);
    $body=GreenConfig::body();$observations=is_array($body['observations']??null)?$body['observations']:[];if(count($observations)>100)throw new RuntimeException('Observation batch exceeds 100 entries.');
    $results=[];$accepted=0;$changed=0;
    foreach($observations as $o){
        if(!is_array($o))continue;
        $url=trim((string)($o['url']??''));
        $payload=is_array($o['payload']??null)?$o['payload']:null;
        if($url===''||$payload===null)continue;
        $terminal=(int)($payload['__p2k_green_terminal_http']??0);
        if(in_array($terminal,[404,410],true)){
            $path=rtrim(strtolower((string)(parse_url($url,PHP_URL_PATH)?:'')),'/');
            $declared=(string)($payload['__p2k_green_kind']??'');
            if(preg_match('~/pub/player/([^/]+)/stats$~',$path,$m)){
                if($declared!==''&&$declared!=='player_stats')throw new RuntimeException('Terminal observation kind does not match stats URL.');
                $username=rawurldecode($m[1]);$did=$repo->markStatsHttp($username,$terminal);
                try{(new GreenCompatibility($repo))->projectMember($username);}catch(Throwable $projectionError){}
                $r=['accepted'=>true,'type'=>'player_stats','terminal_http'=>$terminal,'changed'=>$did?1:0,'result'=>['username'=>$username,'terminal'=>true]];
            }elseif(preg_match('~/pub/player/([^/]+)$~',$path,$m)){
                if($declared!==''&&$declared!=='player_profile')throw new RuntimeException('Terminal observation kind does not match profile URL.');
                $username=rawurldecode($m[1]);$did=$repo->markProfileHttp($username,$terminal);
                try{(new GreenCompatibility($repo))->projectMember($username);}catch(Throwable $projectionError){}
                $r=['accepted'=>true,'type'=>'player_profile','terminal_http'=>$terminal,'changed'=>$did?1:0,'result'=>['username'=>$username,'terminal'=>true]];
            }elseif(preg_match('~/pub/match/(\d+)$~',$path,$m)&&$declared==='gffl_match_detail'){
                $matchId=(int)$m[1];$repo->markMatchHttp($matchId,$terminal,'HTTP '.$terminal.' while servicing GFFL freshness debt');$repo->completeGfflMatch($matchId);
                try{(new GreenCompatibility($repo))->projectMatch($matchId,false);}catch(Throwable $projectionError){}
                $r=['accepted'=>true,'type'=>'match_detail','terminal_http'=>$terminal,'changed'=>1,'result'=>['match_id'=>$matchId,'terminal'=>true,'gffl_completed'=>true]];
            }elseif(preg_match('~/pub/club/([^/]+)$~',$path,$m)&&$declared==='gab_opponent_profile'){
                $gab=new GreenAnalyticsBootstrap($repo);$r=['accepted'=>true,'type'=>'gab_opponent_profile','terminal_http'=>$terminal,'changed'=>1,'result'=>$gab->ingestOpponent($url,[],$terminal)];
            }elseif(preg_match('~/pub/match/(\d+)/(\d+)$~',$path,$m)&&$declared==='heatmap_board_detail'){
                $repo->completeHeatmapBackfill($url,$terminal,'HTTP '.$terminal.' while backfilling paired-rating provenance');
                $r=['accepted'=>true,'type'=>'heatmap_board_detail','terminal_http'=>$terminal,'changed'=>0,'result'=>['match_id'=>(int)$m[1],'board_no'=>(int)$m[2],'terminal'=>true]];
            }else throw new RuntimeException('Terminal browser observation is allowed only for Green player/profile, declared GAB opponent, declared GFFL match, or declared heatmap-backfill board URLs.');
            $results[]=$r;$accepted++;$changed+=(int)($r['changed']??0);continue;
        }
        $declared=(string)($o['kind']??$payload['__p2k_green_kind']??'');
        if($declared==='gab_opponent_profile'){$r=['accepted'=>true,'type'=>'gab_opponent_profile','changed'=>1,'result'=>(new GreenAnalyticsBootstrap($repo))->ingestOpponent($url,$payload,200)];}
        else{$r=$repo->ingestObservation($url,$payload,'migration_browser');if($declared==='gffl_match_detail'&&(string)($r['type']??'')==='match_detail'){$mid=(int)(($r['result']??[])['match_id']??0);if($mid>0){$repo->completeGfflMatch($mid);$r['gffl_completed']=true;}}if(in_array((string)($r['type']??''),['match_detail','board_detail','club_roster','player_profile','player_stats'],true)){try{$compat=new GreenCompatibility($repo);$result=$r['result']??[];$mid=(int)($result['match_id']??0);if($mid>0)$compat->projectMatch($mid,false);elseif((string)$r['type']==='club_roster')$compat->projectMembers();elseif(in_array((string)$r['type'],['player_profile','player_stats'],true)&&!empty($result['username']))$compat->projectMember((string)$result['username']);}catch(Throwable $projectionError){$r['projection_warning']=$projectionError->getMessage();}}if($declared==='heatmap_board_detail'){if(!empty(($r['result']??[])['paired_rating_observed'])){$repo->completeHeatmapBackfill($url,200);$r['heatmap_backfill']=true;}else{$repo->completeHeatmapBackfill($url,422,'Board payload contained no paired player ratings.');$r['heatmap_backfill']=false;$r['heatmap_terminal_reason']='no_paired_ratings';}}}
        $results[]=$r;$accepted++;$changed+=(int)($r['changed']??0);
    }
    GreenConfig::json(['ok'=>true,'accepted'=>$accepted,'changed'=>$changed,'results'=>$results]);
}catch(Throwable $e){GreenConfig::json(['ok'=>false,'error'=>$e->getMessage()],500);}
