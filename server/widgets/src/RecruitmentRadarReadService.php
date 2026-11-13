<?php
declare(strict_types=1);
namespace P2K\Widgets;

use PDO;
use P2K\TeamPoints\ResponseCache;

/**
 * Bounded, read-only Chess.com registration assessment for Recruitment Radar.
 * The source of truth for match inclusion is the verified Green match catalogue.
 * Chess.com match sheets supply current lineup and rating restrictions; cached
 * roster names are never emitted to other visitors in the public API.
 */
final class RecruitmentRadarReadService
{
    public const BATCH_MAX=4;
    private const SHEET_TTL=600;
    private const SHEET_STALE=3600;

    public static function username(string $name):string {
        $name=trim($name);
        if(!preg_match('/^[a-zA-Z0-9_-]{2,25}$/D',$name))throw new \InvalidArgumentException('Enter a valid Chess.com username.');
        return $name;
    }

    /** Prevent a public visitor from making arbitrary remote match-ID requests. */
    public static function validMatchIds(PDO $core,string $club,array $ids,int $now):array {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $n):bool=>$n>0)));
        if(count($ids)>self::BATCH_MAX)throw new \InvalidArgumentException('Too many matches in one batch.');
        if($ids===[])return [];
        $params=array_merge([$club,$now],$ids);
        $q=$core->prepare("SELECT match_id,time_control FROM p2k_g_matches
          WHERE club_verified=1 AND verified_club_slug=? AND is_void=0
          AND COALESCE(NULLIF(time_class,''),NULLIF(index_time_class,''))='daily'
          AND (CASE WHEN status='unknown' THEN COALESCE(index_bucket,'unknown') ELSE status END)='registered'
          AND start_epoch>?
          AND match_id IN (".implode(',',array_fill(0,count($ids),'?')).")");
        $q->execute($params);
        $found=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$found[(int)$r['match_id']]=(string)($r['time_control']??'');
        return $found;
    }

    public static function readJson(string $url):array {
        // Only call with internally built Chess.com API paths, never user URLs.
        $context=stream_context_create(['http'=>[
            'method'=>'GET','timeout'=>2.5,'ignore_errors'=>true,
            'header'=>"Accept: application/json\r\nUser-Agent: P2K-RecruitmentRadar/2.15.9\r\n"
        ]]);
        $body=@file_get_contents($url,false,$context,0,1500000);
        $headers=$http_response_header??[];
        $status=0;
        foreach($headers as $line)if(preg_match('/^HTTP\/\S+\s+(\d{3})/i',$line,$m))$status=(int)$m[1];
        if($body===false||$status!==200)throw new \RuntimeException('Chess.com match/profile information temporarily unavailable.');
        $json=json_decode($body,true);
        if(!is_array($json))throw new \RuntimeException('Chess.com returned an unreadable response.');
        return $json;
    }

    /** Keep the unmodified, cached public match-sheet facts for future username filtering. */
    public static function parseMatch(array $sheet,string $club,int $id,string $defaultTimeControl=''):array {
        $teams=$sheet['teams']??[];
        if(!is_array($teams))$teams=[];
        $ours=null;$opponent=null;
        foreach($teams as $teamKey=>$team){
            if(!is_array($team))continue;
            $uri=strtolower((string)($team['@id']??$team['url']??''));
            $path=(string)(parse_url($uri,PHP_URL_PATH)?:'');
            $segment=strtolower((string)basename(trim($path,'/')));
            $isOurs=$segment===$club || (is_string($teamKey)&&strtolower($teamKey)===$club);
            if($isOurs)$ours=$team;
            elseif($opponent===null)$opponent=$team;
        }
        if($ours===null||$opponent===null)return ['verified'=>false,'matchId'=>$id,'reason'=>'match_teams_unavailable'];
        $cfg=is_array($sheet['settings']??null)?$sheet['settings']:[];
        $min=max(0,(int)($cfg['min_rating']??$cfg['minimum_rating']??0));
        $max=(int)($cfg['max_rating']??$cfg['maximum_rating']??0);
        if($max<=0)$max=null;
        $minimum=max(0,(int)($cfg['min_team_players']??$cfg['minimum_players']??$sheet['minimum_players']??0));
        $maximum=(int)($cfg['max_team_players']??$cfg['maximum_players']??$sheet['maximum_players']??0);
        if($maximum<=0)$maximum=null;
        $players=static function(array $team):array {
            return is_array($team['players']??null)?array_values(array_filter($team['players'],'is_array')):[];
        };
        $oursPlayers=$players($ours);$theirsPlayers=$players($opponent);
        $ratings=static function(array $players):array {
            return array_values(array_filter(array_map(static function(array $player):?float{
                $rating=$player['rating']??null;
                return is_numeric($rating)&&is_finite((float)$rating)?(float)$rating:null;
            },$players),static fn($rating):bool=>$rating!==null));
        };
        $names=[];
        foreach($oursPlayers as $p){
            $name=strtolower(trim((string)($p['username']??'')));
            if($name!=='')$names[$name]=true;
        }
        return [
            'verified'=>true,'matchId'=>$id,
            'rules'=>(string)($cfg['rules']??$sheet['rules']??'chess'),
            'timeControl'=>(string)($cfg['time_control']??$sheet['time_control']??$defaultTimeControl),
            'minRating'=>$min,'maxRating'=>$max,
            'minimumPlayers'=>$minimum,'maximumPlayers'=>$maximum,
            'oursCount'=>count($oursPlayers),'opponentCount'=>count($theirsPlayers),
            'oursRatings'=>$ratings($oursPlayers),'opponentRatings'=>$ratings($theirsPlayers),
            'locked'=>!empty($ours['locked'])||!empty($ours['is_locked']),
            '_registeredNames'=>array_keys($names),
        ];
    }

    public static function publicMatch(array $cached,string $username=''):array {
        $names=is_array($cached['_registeredNames']??null)?$cached['_registeredNames']:[];
        unset($cached['_registeredNames']);
        $cached['alreadyRegistered']=$username!=='' && in_array(strtolower($username),$names,true);
        return $cached;
    }

    /**
     * Uses the existing protected filesystem response cache. Network timeouts
     * never make a guessed match priority look verified.
     */
    public static function batch(PDO $core,ResponseCache $cache,string $club,array $ids,string $username='',?callable $fetch=null):array {
        $allowed=self::validMatchIds($core,$club,$ids,time());
        $fetch=$fetch??[self::class,'readJson'];
        $rows=[];
        foreach($ids as $id){
            $id=(int)$id;
            if(!isset($allowed[$id]))continue;
            try{
                $key='p2k-radar-match-v2159|'.$club.'|'.$id;
                $entry=$cache->remember($key,self::SHEET_TTL,static function()use($fetch,$club,$id,$allowed):array{
                    $sheet=$fetch('https://api.chess.com/pub/match/'.$id);
                    return self::parseMatch($sheet,$club,$id,$allowed[$id]);
                },self::SHEET_STALE);
                $rows[]=self::publicMatch($entry['payload'],$username);
            }catch(\Throwable $error){
                $rows[]=['verified'=>false,'matchId'=>$id,'reason'=>'live_match_unavailable','alreadyRegistered'=>false];
            }
        }
        return ['ok'=>true,'rows'=>$rows];
    }

    public static function playerRatings(array $stats,string $username):array {
        $read=static function(string $name)use($stats):?int {
            $last=$stats[$name]['last']['rating']??null;
            return is_numeric($last)&&is_finite((float)$last)?(int)$last:null;
        };
        return [
            'ok'=>true,'username'=>$username,
            'classical'=>$read('chess_daily'),
            'chess960'=>$read('chess960_daily'),
            'source'=>'Chess.com public Daily ratings'
        ];
    }

    public static function player(ResponseCache $cache,string $username,?callable $fetch=null):array {
        $username=self::username($username);
        $fetch=$fetch??[self::class,'readJson'];
        $key='p2k-radar-ratings-v2159|'.strtolower($username);
        $entry=$cache->remember($key,300,static function()use($fetch,$username):array{
            $stats=$fetch('https://api.chess.com/pub/player/'.rawurlencode(strtolower($username)).'/stats');
            return self::playerRatings($stats,$username);
        },900);
        return $entry['payload'];
    }
}
