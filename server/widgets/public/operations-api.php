<?php
declare(strict_types=1);
/**
 * Anonymous, read-only P2K Club Operations widget data.
 * Never forward administrative API responses: every field is explicitly projected.
 * No writes, worker triggers, OAuth credentials, closure reasons or raw visitor identifiers.
 */
require_once dirname(__DIR__, 3).'/api/_common.php';
require_once dirname(__DIR__, 2).'/team-points-green/src/bootstrap.php';

use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\ResponseCache;
use P2K\Green\GreenRepository;
use P2K\Shared\BoundedRateLimiter;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    api_error(405,'METHOD_NOT_ALLOWED','Only public read requests are supported.');
}
$action=strtolower(trim((string)($_GET['action']??'')));
if (!in_array($action,['matches','member-events','member-lookup','match-search','match-data','match-history'],true)) {
    api_error(404,'UNKNOWN_ACTION','Unknown public operation.');
}
// A capped fixed-window limiter applies to the costlier public reads.
// The limiter stores a hash of its subject, never a raw IP address.
try {
    $subject=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    $subject=hash_hmac('sha256',$subject,(string)(p2k_tp_config()['app']['admin_token']??'p2k-operations'));
    $limiter=new BoundedRateLimiter(dirname(__DIR__,3).'/data/runtime/p2k-operations-rate.json');
    $quota=in_array($action,['member-lookup','match-history','match-data'],true)?45:120;
    $limit=$limiter->consume($action.'|'.$subject,$quota,60);
    if (!$limit['allowed']) {
        header('Retry-After: '.(int)$limit['retry_after_seconds']);
        api_error(429,'RATE_LIMIT','Public match data request limit reached. Try again shortly.');
    }
} catch (Throwable $e) {
    error_log('P2K public operations rate limiter unavailable: '.$e->getMessage());
    // Retain read availability during a host filesystem incident; bounded SQL still applies.
}
function ops_username(mixed $raw): string {
    $username=trim((string)$raw);
    if (!preg_match('/^[A-Za-z0-9_-]{2,25}$/D',$username)) api_error(400,'USERNAME_REQUIRED','Enter a valid Chess.com username.');
    return $username;
}
function ops_id(mixed $value): int {
    $raw=trim((string)$value);
    if (!preg_match('/^[1-9][0-9]{0,12}$/D',$raw)) api_error(400,'MATCH_ID_REQUIRED','Enter a valid match ID.');
    $id=(int)$raw;
    if ($id<=0) api_error(400,'MATCH_ID_REQUIRED','Enter a valid match ID.');
    return $id;
}
function ops_reference(string $q): ?int {
    if (preg_match('/^[1-9][0-9]{0,12}$/D',$q)) return (int)$q;
    if (!str_contains($q,'://')) return null;
    $parsed=parse_url($q);
    if (!is_array($parsed) || strtolower((string)($parsed['scheme']??''))!=='https') return null;
    $host=strtolower((string)($parsed['host']??''));
    $path=(string)($parsed['path']??'');
    if (!in_array($host,['chess.com','www.chess.com','api.chess.com'],true)) return null;
    if (!preg_match('~^/(?:club/matches|pub/match)/([1-9][0-9]{0,12})/?$~D',$path,$m)) return null;
    return (int)$m[1];
}
function ops_event(array $e): array {
    return [
        'event_type'=>(string)($e['event_type']??''),
        'username'=>(string)($e['username']??''),
        'new_username'=>(string)($e['new_username']??''),
        'old_username'=>(string)($e['old_username']??$e['previous_username']??''),
        'detected_at'=>(string)($e['detected_at']??''),
        'effective_at'=>(string)($e['effective_at']??'')
    ];
}
function ops_green(): GreenRepository {
    $club=strtolower((string)(p2k_tp_config()['app']['club_slug']??'promote-to-king'));
    return new GreenRepository(PublicReadDatabase::core(),PublicReadDatabase::analytics(),$club);
}
function ops_match_row(array $m): array {
    $id=(int)$m['match_id'];
    $web=trim((string)($m['web_url']??''));
    $web=preg_match('~^https://www\.chess\.com/club/matches/[0-9]+/?$~i',$web)?$web:'https://www.chess.com/club/matches/'.$id;
    return [
        'matchId'=>$id,'name'=>(string)($m['name']?:'Match #'.$id),
        'opponent'=>(string)($m['opponent_name']?:'Opponent'),
        'startTime'=>(int)($m['start_epoch']??0),
        'url'=>$web,'boards'=>max(0,(int)($m['board_count']??0)),
        'minRating'=>max(0,(int)($m['min_rating']??0)),
        'maxRating'=>is_numeric($m['max_rating']??null)?(int)$m['max_rating']:null,
        'minimumPlayers'=>is_numeric($m['min_team_players']??null)?(int)$m['min_team_players']:null,
        'maximumPlayers'=>is_numeric($m['max_team_players']??null)?(int)$m['max_team_players']:null,
        'locked'=>($m['registration_locked']??null)===null?null:(bool)$m['registration_locked'],
        'verified'=>($m['last_verified_at']??null)!==null,
        'lastVerified'=>(string)($m['last_verified_at']??''),
        'status'=>(string)($m['status']??'unknown')
    ];
}
function ops_match_query(string $where,array $args,string $suffix=''): array {
    $sql="SELECT match_id,start_epoch,board_count,name,opponent_name,web_url,
            min_team_players,max_team_players,min_rating,max_rating,registration_locked,
            last_verified_at,status
        FROM p2k_g_matches WHERE club_verified=1 AND verified_club_slug=?
        AND is_void=0 AND COALESCE(NULLIF(time_class,''),NULLIF(index_time_class,''))='daily'
        AND (".$where.") ".$suffix;
    $q=PublicReadDatabase::core()->prepare($sql);
    $q->execute(array_merge([strtolower((string)(p2k_tp_config()['app']['club_slug']??'promote-to-king'))],$args));
    return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
}
function ops_cache(string $key,int $ttl,callable $builder): array {
    $config=p2k_tp_config();
    $cache=new ResponseCache((array)($config['storage']??[]));
    $response=$cache->remember('p2k-public-operations-v1|'.$key,$ttl,$builder,180);
    return $response['payload'];
}
try {
    if ($action==='matches') {
        $tz=new DateTimeZone('Europe/Luxembourg');
        $start=new DateTimeImmutable('today',$tz);
        $end=$start->modify('+7 days');
        $result=ops_cache('week|'.$start->format('Y-m-d'),45,static function() use($tz,$start,$end):array {
            $days=[];
            for ($i=0;$i<7;$i++) {
                $date=$start->modify('+'.$i.' days')->format('Y-m-d');
                $days[$date]=['date'=>$date,'matches'=>0,'boards'=>0,'items'=>[]];
            }
            $rows=ops_match_query(
                "start_epoch>=? AND start_epoch<? AND (CASE WHEN status='unknown' THEN COALESCE(index_bucket,'unknown') ELSE status END)='registered'",
                [max(time(),$start->getTimestamp()),$end->getTimestamp()],
                'ORDER BY start_epoch ASC,match_id ASC LIMIT 600'
            );
            $ids=[];$positions=[];
            foreach($rows as $m) {
                $item=ops_match_row($m);
                $date=(new DateTimeImmutable('@'.$item['startTime']))->setTimezone($tz)->format('Y-m-d');
                if (!isset($days[$date])) continue;
                $days[$date]['matches']++;
                $days[$date]['boards']+=$item['boards'];
                $item+=['oursCount'=>0,'opponentCount'=>0,'oursEligible'=>0,'opponentEligible'=>0,'rosterComplete'=>false];
                $positions[$item['matchId']]=[$date,count($days[$date]['items'])];
                $days[$date]['items'][]=$item;
                $ids[]=$item['matchId'];
            }
            foreach(array_chunk($ids,180) as $chunk){
                $query=PublicReadDatabase::core()->prepare(
                    'SELECT match_id,is_p2k,start_rating FROM p2k_g_match_players WHERE match_id IN ('.
                    implode(',',array_fill(0,count($chunk),'?')).')'
                );
                $query->execute($chunk);
                foreach($query->fetchAll(PDO::FETCH_ASSOC)?:[] as $p) {
                    $pos=$positions[(int)$p['match_id']]??null;if(!$pos)continue;
                    [$date,$index]=$pos;
                    $item=&$days[$date]['items'][$index];
                    $us=(int)$p['is_p2k']===1;
                    if($us)$item['oursCount']++;else $item['opponentCount']++;
                    $rating=$p['start_rating'];
                    if(is_numeric($rating)&&(float)$rating>0 && (float)$rating>=$item['minRating']
                        && ($item['maxRating']===null || (float)$rating<=$item['maxRating'])){
                        if($us)$item['oursEligible']++;else $item['opponentEligible']++;
                    }
                    unset($item);
                }
            }
            foreach($days as &$day)foreach($day['items'] as &$item) {
                $item['rosterComplete']=$item['oursCount']+$item['opponentCount']>0 || $item['boards']===0;
            }
            unset($day,$item);
            return ['ok'=>true,'source'=>'Green verified daily registration','timezone'=>'Europe/Luxembourg',
                'asOf'=>gmdate(DATE_ATOM),'days'=>array_values($days)];
        });
        json_response(200,$result);
    }
    if ($action==='member-events') {
        $page=max(1,min(1000,(int)($_GET['page']??1)));
        $data=ops_cache('member-events|'.$page,120,static function()use($page):array {
            $repo=ops_green();
            $view=$repo->memberEventsPage($page,10);
            return ['ok'=>true,'events'=>array_map('ops_event',(array)($view['events']??[])),
                'pagination'=>$view['pagination']??[],'asOf'=>gmdate(DATE_ATOM)];
        });
        json_response(200,$data);
    }
    if ($action==='member-lookup') {
        $username=ops_username($_GET['username']??'');
        $result=ops_cache('member-lookup|'.strtolower($username),120,static function()use($username):array {
            $raw=ops_green()->memberLookup($username);
            if (empty($raw['found'])) return ['ok'=>true,'lookup'=>['found'=>false,'query'=>$username]];
            $m=(array)($raw['member']??[]);
            $a=(array)($raw['activity']??[]);
            $lc=(array)($raw['lifecycle']??[]);
            $aliases=array_slice(array_values((array)($raw['aliases']??[])),0,60);
            $events=array_slice(array_values((array)($lc['events']??[])),0,60);
            return ['ok'=>true,'lookup'=>[
                'found'=>true,'member'=>[
                    'username'=>(string)($m['username']??$username),
                    'current_member'=>(bool)($m['current_member']??false),
                    'joined_epoch'=>$m['joined_epoch']??null,'left_at'=>$m['left_at']??null,
                    'daily_rating'=>$m['daily_rating']??null,'chess960_rating'=>$m['chess960_rating']??null
                ],
                'aliases'=>array_map(static fn(array $r):array=>[
                    'username'=>(string)($r['username']??''),
                    'current'=>(bool)($r['current']??false),
                    'first_seen_at'=>(string)($r['first_seen_at']??''),
                    'last_seen_at'=>(string)($r['last_seen_at']??'')
                ],$aliases),
                'lifecycle'=>['events'=>array_map('ops_event',$events)],
                'activity'=>array_intersect_key($a,array_flip(['games','wins','draws','losses','points','registered_matches','in_progress_matches'])),
                'achievements'=>$raw['achievements']??null
            ]];
        });
        json_response(200,$result);
    }
    if ($action==='match-search') {
        $q=trim((string)($_GET['q']??''));
        if ($q===''||strlen($q)>150) api_error(400,'INVALID_QUERY','Enter a match ID, URL or name (up to 150 characters).');
        $id=ops_reference($q);
        if ($id!==null) {
            $rows=ops_match_query('match_id=?',[$id],'LIMIT 1');
        } else {
            if(str_contains($q,'://')) api_error(400,'INVALID_URL','Use a valid Chess.com match URL.');
            $like=str_replace(['!','%','_'],['!!','!%','!_'],$q);
            $rows=ops_match_query("name LIKE ? ESCAPE '!' OR opponent_name LIKE ? ESCAPE '!'",['%'.$like.'%','%'.$like.'%'],
                "ORDER BY CASE WHEN status='registered' THEN 0 ELSE 1 END,start_epoch DESC LIMIT 8");
        }
        json_response(200,['ok'=>true,'results'=>array_map('ops_match_row',$rows)]);
    }
    if ($action==='match-data') {
        $id=ops_id($_GET['id']??'');
        $result=ops_cache('match-data|'.$id,45,static function()use($id):array {
            $rows=ops_match_query('match_id=?',[$id],'LIMIT 1');
            if (!$rows) return ['ok'=>true,'found'=>false];
            $m=ops_match_row($rows[0]);
            $q=PublicReadDatabase::core()->prepare(
                'SELECT is_p2k,start_rating FROM p2k_g_match_players WHERE match_id=? LIMIT 5001'
            );$q->execute([$id]);
            $team=[[],[]];$counts=[0,0];$truncated=false;
            while($p=$q->fetch(PDO::FETCH_ASSOC)){
                $side=(int)$p['is_p2k']===1?0:1;
                $counts[$side]++;
                if($counts[0]+$counts[1]>5000){$truncated=true;break;}
                $rating=$p['start_rating']??null;
                if(is_numeric($rating) && (float)$rating>0)$team[$side][]=(int)$rating;
            }
            $complete=!$truncated&&($counts[0]+$counts[1]>0 || $m['boards']===0)&&$m['verified'];
            return ['ok'=>true,'found'=>true,'match'=>$m,
                'roster'=>['p2k'=>$counts[0],'opponent'=>$counts[1],
                    'p2kRatings'=>$team[0],'opponentRatings'=>$team[1],
                    'complete'=>$complete,'truncated'=>$truncated],
                'asOf'=>gmdate(DATE_ATOM)];
        });
        json_response(200,$result);
    }
    if ($action==='match-history') {
        $id=ops_id($_GET['id']??'');
        if (!ops_match_query('match_id=?',[$id],'LIMIT 1')) {
            api_error(404,'MATCH_NOT_FOUND','Match not in verified P2K data.');
        }
        $result=ops_cache('match-history|'.$id,180,static function()use($id):array {
            $files=glob(history_root().'/'.$id.'/*.json')?:[];
            sort($files,SORT_STRING);
            // Limit work for anonymously accessed widgets, avoiding raw archived player exports.
            $files=array_slice($files,-40);
            $out=[];
            foreach($files as $file){
                if(!is_file($file)||is_link($file)||filesize($file)>3145728)continue;
                $raw=json_decode((string)file_get_contents($file),true);
                if(!is_array($raw)||!isset($raw['match']['teams']))continue;
                $teams=(array)$raw['match']['teams'];
                $members=[];
                foreach($teams as $team){
                    if(!is_array($team))continue;
                    $identity=strtolower((string)($team['@id']??$team['url']??$team['name']??''));
                    $players=(array)($team['players']??[]);
                    $ratings=[];
                    foreach($players as $p){
                        if(!is_array($p))continue;
                        $rating=$p['rating']??$p['elo']??null;
                        if(is_numeric($rating) && (float)$rating>0 && count($ratings)<5000)$ratings[]=(int)$rating;
                    }
                    $members[]=['isP2K'=>str_contains($identity,'promote-to-king'),'count'=>count($players),'ratings'=>$ratings];
                }
                if(count($members)!==2 || !in_array(true,array_column($members,'isP2K'),true))continue;
                usort($members,static fn(array $a,array $b):int=>(int)$b['isP2K']<=>(int)$a['isP2K']);
                $out[]=['at'=>(string)($raw['trackedAt']??''),
                    'p2k'=>$members[0]['count'],'opponent'=>$members[1]['count'],
                    'p2kRatings'=>$members[0]['ratings'],'opponentRatings'=>$members[1]['ratings']];
            }
            return ['ok'=>true,'points'=>$out,'hasHistory'=>count($out)>0,'asOf'=>gmdate(DATE_ATOM)];
        });
        json_response(200,$result);
    }
} catch (Throwable $e) {
    error_log('P2K public operations read '.$action.': '.$e->getMessage());
    api_error(503,'DATA_UNAVAILABLE','Public club operations data is temporarily unavailable.');
}
