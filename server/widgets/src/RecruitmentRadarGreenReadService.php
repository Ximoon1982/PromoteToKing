<?php
declare(strict_types=1);
namespace P2K\Widgets;

use PDO;

/**
 * DB-only Recruitment Radar. Green's existing CRON owns ALL Chess.com traffic.
 * Public read paths must never make external requests, even on a cold cache.
 */
final class RecruitmentRadarGreenReadService
{
    public const MAX_WINDOW_DAYS=7;
    public const LEGACY_BATCH_MAX=4;

    public static function username(string $raw):string
    {
        $name=trim($raw);
        if($name!==''&&!preg_match('/^[A-Za-z0-9_-]{2,25}$/D',$name)){
            throw new \InvalidArgumentException('Enter a valid Chess.com username.');
        }
        return $name;
    }

    public static function player(PDO $core,string $raw):array
    {
        $username=self::username($raw);
        if($username==='')throw new \InvalidArgumentException('Chess.com username required.');
        $q=$core->prepare('SELECT username,daily_rating,chess960_rating,stats_checked_at,current_member
                           FROM p2k_g_players WHERE username_key=? LIMIT 1');
        $q->execute([strtolower($username)]);
        $row=$q->fetch(PDO::FETCH_ASSOC)?:null;
        $rating=static function($n):?int{return is_numeric($n)&& (int)$n>0?(int)$n:null;};
        return [
            'ok'=>true,'username'=>$row['username']??$username,
            'classical'=>$rating($row['daily_rating']??null),
            'chess960'=>$rating($row['chess960_rating']??null),
            'available'=>is_array($row),
            'statsCheckedAt'=>$row['stats_checked_at']??null,
            'source'=>'Green player statistics',
        ];
    }

    private static function rows(PDO $core,string $club,int $now,int $until,?array $onlyIds=null):array
    {
        $where=["m.club_verified=1","m.verified_club_slug=?","m.is_void=0",
            "COALESCE(NULLIF(m.time_class,''),NULLIF(m.index_time_class,''))='daily'",
            "(CASE WHEN m.status='unknown' THEN COALESCE(m.index_bucket,'unknown') ELSE m.status END)='registered'",
            "m.start_epoch>?",'m.start_epoch<=?'];
        $args=[$club,$now,$until];
        if(is_array($onlyIds)){
            $onlyIds=array_values(array_unique(array_filter(array_map('intval',$onlyIds),static fn(int $n):bool=>$n>0)));
            if($onlyIds===[])return [];
            $where[]='m.match_id IN ('.implode(',',array_fill(0,count($onlyIds),'?')).')';
            array_push($args,...$onlyIds);
        }
        $whereSql=implode(' AND ',$where);
        $base='SELECT m.match_id,m.web_url,m.name,m.opponent_name,m.opponent_url,
          m.start_epoch,m.rules,m.time_control,m.max_rating,m.max_rating_state,
          m.last_verified_at,m.board_count';
        $extras=',m.min_rating,m.min_team_players,m.max_team_players,m.registration_locked';
        $suffix=' FROM p2k_g_matches m WHERE '.$whereSql.' ORDER BY m.start_epoch ASC,m.match_id ASC';
        try{
            $q=$core->prepare($base.$extras.$suffix);$q->execute($args);
        }catch(\PDOException $exception){
            // During same-version Release Control preview, the periodic Green worker
            // may not yet have applied the additive columns. Keep the public widget
            // read-only and visibly partial until the next schema convergence.
            if(!preg_match('/unknown column|no such column/i',$exception->getMessage()))throw $exception;
            $q=$core->prepare($base.',NULL AS min_rating,NULL AS min_team_players,
               NULL AS max_team_players,NULL AS registration_locked'.$suffix);
            $q->execute($args);
        }
        return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    private static function isLeague(string $name):bool
    {
        if(function_exists('league_codes'))return \league_codes($name)!==[];
        return (bool)preg_match('/(?:^|[^A-Z0-9])(?:1WL|TCMAC|KOTML|TMCL|WKCL|PCL|CW|WCGC)(?:[^A-Z0-9]|$)/i',$name);
    }

    public static function catalog(PDO $core,string $club,string $username='',?int $now=null):array
    {
        $username=self::username($username);
        $now=$now??time();
        return self::assemble($core,$club,self::rows($core,$club,$now,$now+86400*self::MAX_WINDOW_DAYS),$username);
    }

    /** Legacy route remains DB-only for already cached iframe scripts. */
    public static function batch(PDO $core,string $club,array $ids,string $username='',?int $now=null):array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $n):bool=>$n>0)));
        if(count($ids)>self::LEGACY_BATCH_MAX)throw new \InvalidArgumentException('Too many matches in one batch.');
        $now=$now??time();
        return ['ok'=>true,'rows'=>self::assemble(
            $core,$club,self::rows($core,$club,$now,$now+86400*self::MAX_WINDOW_DAYS,$ids),
            self::username($username)
        )['details']];
    }

    private static function assemble(PDO $core,string $club,array $records,string $username):array
    {
        $matches=[];$details=[];$rawIds=[];
        foreach($records as $m){
            $id=(int)$m['match_id'];if($id<=0)continue;
            $rawIds[]=$id;
            $name=trim((string)($m['name']??''))?:'Match #'.$id;
            $matchUrl=trim((string)($m['web_url']??''));
            if(!str_starts_with($matchUrl,'https://www.chess.com/club/matches/'))$matchUrl='https://www.chess.com/club/matches/'.$id;
            $league=self::isLeague($name);
            $matches[]=[
                'matchId'=>(string)$id,'name'=>$name,
                'url'=>$matchUrl,'opponentName'=>trim((string)($m['opponent_name']??''))?:'Opponent',
                'startTime'=>(int)$m['start_epoch'],
                'timeControl'=>$m['time_control']??null,
                'category'=>$league?'league':'friendly','isLeague'=>$league,
                'source'=>'Green',
            ];
            $maxState=strtolower((string)($m['max_rating_state']??'unknown'));
            $details[$id]=[
                'matchId'=>$id,'verified'=>($m['last_verified_at']??null)!==null,
                'rules'=>(string)($m['rules']??''),
                'timeControl'=>(string)($m['time_control']??''),
                'minRating'=>$m['min_rating']===null?0:(int)$m['min_rating'],
                'maxRating'=>$m['max_rating']===null?null:(int)$m['max_rating'],
                'maxRatingState'=>$maxState,
                'minRatingKnown'=>true, // Chess.com omits minimum when the floor is 0.
                'maxRatingKnown'=>in_array($maxState,['capped','open'],true)||($m['max_rating']!==null&&(int)$m['max_rating']>0),
                'minimumPlayers'=>$m['min_team_players']===null?null:(int)$m['min_team_players'],
                'maximumPlayers'=>$m['max_team_players']===null?null:(int)$m['max_team_players'],
                'locked'=>$m['registration_locked']===null?null:((int)$m['registration_locked']===1),
                'lastVerifiedAt'=>$m['last_verified_at']??null,
                'oursCount'=>0,'opponentCount'=>0,
                'oursRatings'=>[],'opponentRatings'=>[],
                'alreadyRegistered'=>false,
                'rosterComplete'=>false,
                '_boardCount'=>(int)($m['board_count']??0),
            ];
        }
        foreach(array_chunk($rawIds,200) as $ids){
            if(!$ids)continue;
            $q=$core->prepare('SELECT match_id,username_key,is_p2k,start_rating FROM p2k_g_match_players
                WHERE match_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
            $q->execute($ids);
            foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
                $id=(int)$row['match_id'];if(!isset($details[$id]))continue;
                $ours=(int)$row['is_p2k']===1;
                if($ours){
                    $details[$id]['oursCount']++;
                    if($username!==''&&strtolower((string)$row['username_key'])===strtolower($username)){
                        $details[$id]['alreadyRegistered']=true;
                    }
                }else $details[$id]['opponentCount']++;
                $rating=$row['start_rating'];
                if(is_numeric($rating)&& (float)$rating>0){
                    $details[$id][$ours?'oursRatings':'opponentRatings'][]=(float)$rating;
                }
            }
        }
        foreach($details as &$d){
            // Registered-match with no stored roster despite allocated boards is
            // incomplete, not a verified zero-player deficit.
            $d['rosterComplete']=$d['oursCount']+$d['opponentCount']>0||$d['_boardCount']===0;
            if(!$d['rosterComplete'])$d['verified']=false;
            if(!$d['verified'])$d['reason']='green_match_snapshot_unavailable';
            unset($d['_boardCount']);
        }
        unset($d);
        return ['ok'=>true,'source'=>'Green synchronized DB','matches'=>$matches,
            'details'=>array_values($details),'generatedAt'=>gmdate(DATE_ATOM)];
    }
}
