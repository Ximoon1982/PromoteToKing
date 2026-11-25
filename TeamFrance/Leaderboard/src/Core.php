<?php
declare(strict_types=1);
namespace TeamFrance\Leaderboard;

use PDO;
use RuntimeException;

const CLUB = 'team-france';
const BASE = 'https://api.chess.com/pub/';
const VERSION = '0.1.0';
function userKey(mixed $v): string {
    if (!is_string($v)) return '';
    $v=trim($v);
    if (str_contains($v,'/')) $v=rawurldecode(basename(rtrim((string)parse_url($v,PHP_URL_PATH),'/')));
    $v=strtolower($v);
    return preg_match('/^[a-z0-9_-]{1,80}$/D',$v)?$v:'';
}
function clubKey(mixed $v): string {
    if (!is_string($v)) return '';
    $v=trim($v);
    if (str_contains($v,'/')) $v=rawurldecode(basename(rtrim((string)parse_url($v,PHP_URL_PATH),'/')));
    return strtolower(preg_replace('/[\s_-]+/u','-',trim($v))??'');
}
function eventRef(string $kind,string $ref): array {
    $ref=trim($ref);
    if (!in_array($kind,['daily','arena'],true)) throw new RuntimeException('Unknown event category.');
    if (preg_match('/^[1-9][0-9]{0,17}$/D',$ref)) $id=$ref;
    else {
        $u=parse_url($ref);
        if (!is_array($u)||strtolower($u['scheme']??'')!=='https'||!in_array(strtolower($u['host']??''),['chess.com','www.chess.com','api.chess.com'],true)||isset($u['user'])||isset($u['pass'])||isset($u['port'])) throw new RuntimeException('Enter a Chess.com HTTPS event URL or numeric ID.');
        $p=rawurldecode($u['path']??'');
        $pattern=$kind==='daily'?'~^/(?:pub/match/|club/matches/[^/]+/)([1-9][0-9]{0,17})/?$~D':'~^/tournament/live/arena/(?:[a-zA-Z0-9_-]+-)?([1-9][0-9]{0,17})(?:/download-results)?/?$~D';
        if (!preg_match($pattern,$p,$m)||($kind==='daily'&&str_contains($p,'/matches/live/'))) throw new RuntimeException('URL does not match the selected event category.');
        $id=$m[1];
    }
    $url=$kind==='daily'?'https://www.chess.com/club/matches/team-france/'.$id:'https://www.chess.com/tournament/live/arena/'.$id;
    if ($kind==='arena'&&isset($p)) $url='https://www.chess.com'.preg_replace('~/download-results/?$~','',rtrim($p,'/'));
    return [$id,$url];
}
function rawPoints(string $code): ?int {
    $code=strtolower(trim($code));
    if ($code==='win') return 100;
    if (in_array($code,['agreed','repetition','stalemate','insufficient','50move','timevsinsufficient','draw'],true)) return 50;
    if (in_array($code,['checkmated','timeout','resigned','lose','loss','abandoned','kingofthehill','threecheck','bughousepartnerlose','variantloss'],true)) return 0;
    if (in_array($code,['','unknown','in_progress','ongoing','not_started','scheduled','registered','pending'],true)) return null;
    throw new RuntimeException('Unknown game result: '.$code);
}
function removalKeys(array $raw): array {
    $out=[];
    foreach ($raw as $entry) {
        if (is_array($entry)) $entry=$entry['username']??$entry['name']??$entry['player']??$entry['@id']??$entry['url']??'';
        $key=userKey($entry);
        if ($key!=='') $out[$key]=true;
    }
    return array_keys($out);
}
function boardKey(array $p): string {
    foreach (['board','board_url','board_no','board_number','@id','url'] as $f) {
        $v=$p[$f]??'';
        if (!is_scalar($v)) continue;
        if (preg_match('~/match/\d+/(?:board/)?(\d+)(?:/|$)~',(string)$v,$m)) return (string)(int)$m[1];
        if (preg_match('/^[1-9]\d*$/D',(string)$v)) return (string)(int)$v;
    }
    return '';
}
function matchResult(array $payload,string $id,array $previous=[]): array {
    if (isset($payload['@id'])&&!preg_match('~/pub/match/'.preg_quote($id,'~').'/?$~D',(string)$payload['@id'])) throw new RuntimeException('Wrong Daily match identity.');
    $ours=$other=null;$teamCount=0;
    foreach (($payload['teams']??[]) as $team) {
        if (!is_array($team)) continue;
        $teamCount++;
        if (clubKey($team['@id']??$team['url']??'')===CLUB) $ours=$team;
        else $other=$team;
    }
    if ($teamCount!==2||!$ours||!$other) throw new RuntimeException('Team France is not a verified participant.');
    if (($payload['settings']['time_class']??$payload['time_class']??'daily')!=='daily') throw new RuntimeException('Not a Daily match.');
    $status=strtolower((string)($payload['status']??''));
    $name=trim((string)($payload['name']??''))?:'Match #'.$id;
    if (in_array($status,['registered','registration','scheduled','upcoming','cancelled','canceled','aborted'],true)) {
        if (!empty($previous['games'])) throw new RuntimeException('Unexpected status regression; previous results retained.');
        return ['name'=>$name,'status'=>$status,'rows'=>[],'evidence'=>['games'=>[],'ours'=>[],'other'=>[]]];
    }
    $finished=in_array($status,['finished','complete','completed'],true);
    if (!$finished&&!in_array($status,['in_progress','ongoing','started'],true)) throw new RuntimeException('Unrecognized match status.');
    if (!is_array($ours['players']??null)||!is_array($other['players']??null)) throw new RuntimeException('Incomplete match lineups.');
    $removedO=array_values(array_unique(array_merge($previous['ours']??[],removalKeys((array)($ours['fair_play_removals']??[])))));
    $removedT=array_values(array_unique(array_merge($previous['other']??[],removalKeys((array)($other['fair_play_removals']??[])))));
    $opp=[];$seen=[];$games=[];$prior=$previous['games']??[];
    foreach ($other['players'] as $p) {
        if (!is_array($p)) throw new RuntimeException('Malformed opponent lineup.');
        $b=boardKey($p);$u=userKey($p['username']??'');
        if ($b!==''&&$u!=='') {
            if (isset($opp[$b])&&$opp[$b]!==$u) throw new RuntimeException('Conflicting opponent board.');
            $opp[$b]=$u;
        }
    }
    foreach ($ours['players'] as $p) {
        if (!is_array($p)) throw new RuntimeException('Malformed club lineup.');
        $u=userKey($p['username']??'');$b=boardKey($p);
        if ($u===''||$b==='') throw new RuntimeException('Missing player or board identity.');
        if (isset($seen[$b])) throw new RuntimeException('Duplicate club board.');
        $seen[$b]=true;
        foreach (['played_as_white','played_as_black'] as $side) {
            $key=$b.':'.$side;$old=$prior[$key]??null;
            if ($old&&$old['user']!==$u) throw new RuntimeException('Board identity changed; review required.');
            $v=$p['results'][$side]??'';
            if (!is_string($v)) throw new RuntimeException('Malformed game result.');
            $raw=rawPoints($v);
            $op=$opp[$b]??($old['opp']??'');
            if ($old&&$old['opp']!==''&&$op!==$old['opp']) throw new RuntimeException('Opponent identity changed; review required.');
            if ($raw===null&&$old) {$raw=$old['raw'];$v=$old['result'];}
            if ($raw===null) {
                if ($finished&&!in_array($u,$removedO,true)&&!in_array($op,$removedT,true)) throw new RuntimeException('Finished game result missing.');
                // No invented game points for an unplayed board.
                continue;
            }
            if ($op===''&&$removedT!==[]) throw new RuntimeException('Cannot pair Fair Play opponent.');
            $games[$key]=['user'=>$u,'display'=>(string)$p['username'],'opp'=>$op,'raw'=>$raw,'result'=>$v];
        }
    }
    foreach ($prior as $key=>$g) if (!isset($games[$key])) {
        if (!in_array($g['user'],$removedO,true)&&!in_array($g['opp'],$removedT,true)) throw new RuntimeException('Previously observed game missing; previous results retained.');
        $games[$key]=$g;
    }
    $rows=[];$total=0;
    foreach ($games as $g) {
        $effective=in_array($g['user'],$removedO,true)?0:(in_array($g['opp'],$removedT,true)?100:$g['raw']);
        $u=$g['user'];$rows[$u]??=['username'=>$g['display'],'points'=>0];$rows[$u]['points']+=$effective;$total+=$effective;
    }
    $score=$ours['score']??null;
    if (!is_numeric($score)&&$finished) throw new RuntimeException('Official finished match score is missing.');
    if (is_numeric($score)&&abs($total-(int)round((float)$score*100))>0) throw new RuntimeException('Reconstructed points differ from official match score; previous results retained.');
    if ($finished&&$games===[]&&(int)($payload['boards']??0)>0) throw new RuntimeException('Finished match has no verified game results.');
    return ['name'=>$name,'status'=>$finished?'finished':'in_progress','rows'=>$rows,'evidence'=>['games'=>$games,'ours'=>$removedO,'other'=>$removedT]];
}
function arenaResult(string $csv): array {
    if (strlen($csv)>12000000||str_contains(substr(ltrim($csv),0,100),'<')) throw new RuntimeException('Invalid arena CSV.');
    $csv=preg_replace('/^\xEF\xBB\xBF/','',$csv)??$csv;
    $fp=fopen('php://temp','r+');fwrite($fp,$csv);rewind($fp);
    $first=fgets($fp);rewind($fp);
    $counts=[','=>substr_count((string)$first,','),';'=>substr_count((string)$first,';'),"\t"=>substr_count((string)$first,"\t")];arsort($counts);$sep=(string)key($counts);
    $map=null;$rows=[];$count=0;$clubRows=0;
    try {
        while (($r=fgetcsv($fp,0,$sep,'"',''))!==false) {
            if ($r===[null]||trim(implode('',array_map('strval',$r)))==='') continue;
            $h=array_map(static fn($s)=>strtolower(trim((string)$s)),$r);
            if (in_array('username',$h,true)&&in_array('club',$h,true)&&in_array('score',$h,true)) {$map=array_flip($h);continue;}
            if ($map===null) continue;
            if (in_array('club',$h,true)&&in_array('score',$h,true)&&!in_array('username',$h,true)) break;
            if (count($r)<=max($map['username'],$map['club'],$map['score'])) throw new RuntimeException('Truncated arena CSV row.');
            $u=userKey($r[$map['username']]);$score=str_replace(',','.',trim((string)$r[$map['score']]));
            if ($u===''||!is_numeric($score)||!is_finite((float)$score)||(float)$score<0) throw new RuntimeException('Invalid arena participant row.');
            $count++;
            if (clubKey($r[$map['club']])!==CLUB) continue;
                        $clubRows++;
            $rows[$u]??=['username'=>trim((string)$r[$map['username']]),'points'=>0];
            $rows[$u]['points']+=(int)round((float)$score*100);
        }
    } finally {fclose($fp);}
    if ($map===null||$count===0) throw new RuntimeException('Missing official Player Results table.');
    return ['rows'=>$rows,'evidence'=>['player_rows'=>$count,'club_rows'=>$clubRows]];
}
final class Store {
    public PDO $db;
    public function __construct(public readonly string $dir) {
        if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) throw new RuntimeException('PDO SQLite is unavailable.');
        if (!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir)) throw new RuntimeException('Storage unavailable.');
        $this->db=new PDO('sqlite:'.$dir.'/leaderboard.sqlite3',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
        if ((int)$this->db->query('PRAGMA user_version')->fetchColumn()===0) {
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $this->db->exec("CREATE TABLE IF NOT EXISTS seasons(id INTEGER PRIMARY KEY,kind TEXT NOT NULL CHECK(kind IN ('daily','arena')),name TEXT NOT NULL,start_date TEXT NOT NULL DEFAULT '',end_date TEXT NOT NULL DEFAULT '',archived INTEGER NOT NULL DEFAULT 0,is_default INTEGER NOT NULL DEFAULT 0);
CREATE UNIQUE INDEX IF NOT EXISTS one_default ON seasons(kind) WHERE is_default=1;
CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY,kind TEXT NOT NULL,event_id TEXT NOT NULL,season_id INTEGER NOT NULL REFERENCES seasons(id),url TEXT NOT NULL,name TEXT NOT NULL DEFAULT '',status TEXT NOT NULL DEFAULT 'unknown',checked INTEGER NOT NULL DEFAULT 0,due INTEGER NOT NULL DEFAULT 0,error TEXT NOT NULL DEFAULT '',evidence TEXT NOT NULL DEFAULT '{}',source_hash TEXT NOT NULL DEFAULT '',UNIQUE(kind,event_id));
CREATE INDEX IF NOT EXISTS events_due ON events(due,season_id);
CREATE TABLE IF NOT EXISTS players(username_key TEXT PRIMARY KEY,display TEXT NOT NULL,stable_id TEXT,account_status TEXT NOT NULL DEFAULT 'unverified',verified INTEGER NOT NULL DEFAULT 0,due INTEGER NOT NULL DEFAULT 0,error TEXT NOT NULL DEFAULT '');
CREATE INDEX IF NOT EXISTS players_due ON players(due);
CREATE INDEX IF NOT EXISTS players_identity ON players(stable_id);
CREATE TABLE IF NOT EXISTS results(event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,username_key TEXT NOT NULL REFERENCES players(username_key),points INTEGER NOT NULL CHECK(points>=0),PRIMARY KEY(event_id,username_key));
CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS jobs(id INTEGER PRIMARY KEY CHECK(id=1),state TEXT NOT NULL,phase TEXT NOT NULL,created INTEGER NOT NULL,updated INTEGER NOT NULL,owner TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS tasks(kind TEXT NOT NULL,item TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',error TEXT NOT NULL DEFAULT '',attempts INTEGER NOT NULL DEFAULT 0,next_try INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(kind,item));
PRAGMA user_version=1;");
                $this->db->exec('COMMIT');
            } catch (\Throwable $e) {$this->db->exec('ROLLBACK');throw $e;}
            try {$this->db->query('PRAGMA journal_mode=WAL')->fetchColumn();} catch (\Throwable) {}
        }
    }
    public function run(string $sql,array $args=[]): \PDOStatement {$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    public function rows(string $sql,array $args=[]): array {return $this->run($sql,$args)->fetchAll();}
    public function one(string $sql,array $args=[]): ?array {$r=$this->run($sql,$args)->fetch();return $r?:null;}
    public function tx(callable $f): mixed {$this->db->beginTransaction();try{$r=$f();$this->db->commit();return $r;}catch(\Throwable $e){$this->db->rollBack();throw $e;}}
    public function meta(string $key,string $default=''): string {return $this->one('SELECT value FROM meta WHERE key=?',[$key])['value']??$default;}
    public function put(string $key,string $value): void {$this->run('INSERT INTO meta(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[$key,$value]);}
    public function lock(callable $f): mixed {
        $fp=fopen($this->dir.'/acquisition.lock','c+');
        if (!$fp) throw new RuntimeException('Cannot open acquisition lock.');
        if (!flock($fp,LOCK_EX|LOCK_NB)) {fclose($fp);return ['busy'=>true];}
        try{return $f();}finally{flock($fp,LOCK_UN);fclose($fp);}
    }
    public function archive(string $content): string {
        $hash=hash('sha256',$content);$path=$this->dir.'/sources';
        if (!is_dir($path)&&!mkdir($path,0770,true)&&!is_dir($path)) throw new RuntimeException('Source archive unavailable.');
        $target=$path.'/'.$hash;
        if (!is_file($target)) {
            $tmp=tempnam($path,'.source-');
            if ($tmp===false) throw new RuntimeException('Source archive unavailable.');
            try{if(file_put_contents($tmp,$content)!==strlen($content)||!rename($tmp,$target)) throw new RuntimeException('Source archive write failed.');}finally{if(is_file($tmp))unlink($tmp);}
        }
        return $hash;
    }
    public function saveSeason(array $p): void {
        $kind=(string)($p['kind']??'');$name=trim((string)($p['name']??''));$id=(int)($p['id']??0);
        $start=(string)($p['start_date']??'');$end=(string)($p['end_date']??'');
        if (!in_array($kind,['daily','arena'],true)||$name===''||strlen($name)>160) throw new RuntimeException('Invalid season.');
        foreach([$start,$end] as $d) if($d!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$d)||!checkdate((int)substr($d,5,2),(int)substr($d,8,2),(int)substr($d,0,4))))throw new RuntimeException('Invalid season date.');
        if($start!==''&&$end!==''&&$start>$end)throw new RuntimeException('Invalid season range.');
        $archived=!empty($p['archived'])?1:0;$default=!empty($p['is_default'])&&!$archived?1:0;
        $this->tx(function()use($kind,$name,$id,$start,$end,$archived,$default){
            if($id&&(!$this->one('SELECT id FROM seasons WHERE id=? AND kind=?',[$id,$kind])))throw new RuntimeException('Season not found or category cannot change.');
            if(!$archived&&!$id&&!$this->one('SELECT id FROM seasons WHERE kind=? AND archived=0',[$kind]))$default=1;
            if($default)$this->run('UPDATE seasons SET is_default=0 WHERE kind=?',[$kind]);
            if($id)$this->run('UPDATE seasons SET name=?,start_date=?,end_date=?,archived=?,is_default=? WHERE id=?',[$name,$start,$end,$archived,$default,$id]);
            else $this->run('INSERT INTO seasons(kind,name,start_date,end_date,archived,is_default) VALUES(?,?,?,?,?,?)',[$kind,$name,$start,$end,$archived,$default]);
        });
    }
    public function assign(array $p): void {
        $season=$this->one('SELECT * FROM seasons WHERE id=?',[(int)($p['season_id']??0)]);
        if(!$season)throw new RuntimeException('Select a season.');
        $refs=preg_split('/[\r\n,]+/',trim((string)($p['references']??'')),-1,PREG_SPLIT_NO_EMPTY);
        if(!$refs||count($refs)>500)throw new RuntimeException('Enter 1–500 event references.');
        $this->tx(function()use($season,$refs){
            foreach($refs as $ref){
                [$id,$url]=eventRef($season['kind'],$ref);
                $existing=$this->one('SELECT season_id FROM events WHERE kind=? AND event_id=?',[$season['kind'],$id]);
                if($existing&&(int)$existing['season_id']!==(int)$season['id'])throw new RuntimeException('Event '.$id.' already belongs to another season. Move it explicitly.');
                $this->run('INSERT INTO events(kind,event_id,season_id,url) VALUES(?,?,?,?) ON CONFLICT(kind,event_id) DO NOTHING',[$season['kind'],$id,$season['id'],$url]);
            }
        });
    }
    public function move(int $event,int $season): void {
        $this->tx(function()use($event,$season){
            $s=$this->one('SELECT kind FROM seasons WHERE id=?',[$season]);$e=$this->one('SELECT kind FROM events WHERE id=?',[$event]);
            if(!$s||!$e||$s['kind']!==$e['kind'])throw new RuntimeException('Invalid target season.');
            $this->run('UPDATE events SET season_id=? WHERE id=?',[$season,$event]);
        });
    }
    public function saveEvent(array $event,array $result,string $raw): void {
        $hash=$this->archive($raw);$now=time();$status=$result['status'];
        $ttl=$event['kind']==='arena'?($status==='finished'?315360000:1800):($status==='in_progress'?900:($status==='finished'?((int)$event['checked']&&$event['status']==='finished'?604800:86400):21600));
        $this->tx(function()use($event,$result,$hash,$now,$status,$ttl){
            $this->run('DELETE FROM results WHERE event_id=?',[$event['id']]);
            foreach($result['rows'] as $key=>$row){
                $this->run('INSERT INTO players(username_key,display) VALUES(?,?) ON CONFLICT(username_key) DO NOTHING',[$key,$row['username']]);
                $this->run('INSERT INTO results(event_id,username_key,points) VALUES(?,?,?)',[$event['id'],$key,$row['points']]);
            }
            $this->run('UPDATE events SET name=?,status=?,checked=?,due=?,error=?,evidence=?,source_hash=? WHERE id=?',[$result['name'],$status,$now,$now+$ttl,'',json_encode($result['evidence'],JSON_THROW_ON_ERROR),$hash,$event['id']]);
            $this->put('updated',(string)$now);
        });
    }
    public function saveProfile(string $key,array $p): void {
        $name=userKey($p['username']??'');$id=(string)($p['player_id']??'');$status=strtolower(trim((string)($p['status']??'')));
        if($name===''||!preg_match('/^[1-9]\d*$/D',$id)||!in_array($status,['basic','premium','staff','mod','active','inactive','closed','closed:fair_play_violations','closed:fair_play','closed:cheating','closed:abuse','closed:voluntary'],true))throw new RuntimeException('Incomplete or unrecognized player identity/status.');
        $old=$this->one('SELECT * FROM players WHERE username_key=?',[$key]);
        if(!$old)throw new RuntimeException('Player disappeared.');
        if($old['stable_id']!==null&&$old['stable_id']!==$id)throw new RuntimeException('Username now identifies a different account; review required.');
        if($name!==$key&&$old['stable_id']!==$id)throw new RuntimeException('Rename not verified by stored player ID.');
        $current=$this->one('SELECT stable_id FROM players WHERE username_key=?',[$name]);
        if($current&&$current['stable_id']!==null&&$current['stable_id']!==$id)throw new RuntimeException('Canonical username identity conflict.');
        $this->tx(function()use($key,$name,$id,$status,$p){
            $now=time();$display=(string)$p['username'];
            $this->run('UPDATE players SET stable_id=?,account_status=?,verified=?,due=?,error=?,display=? WHERE username_key=? OR stable_id=?',[$id,$status,$now,$now+2592000,'',$display,$key,$id]);
            // An already known canonical name can be linked only with its own verified ID.
            $this->put('updated',(string)$now);
        });
    }
    public function fail(string $kind,string $item,string $message,int $delay=900): void {
        $message=substr($message,0,500);
        if($kind==='player')$this->run('UPDATE players SET error=?,due=? WHERE username_key=?',[$message,time()+$delay,$item]);
        else $this->run('UPDATE events SET error=?,due=? WHERE id=?',[$message,time()+$delay,(int)$item]);
    }
    public function seasons(): array {return $this->rows('SELECT s.*, (SELECT COUNT(*) FROM events e WHERE e.season_id=s.id) AS event_count FROM seasons s ORDER BY kind,is_default DESC,archived,id DESC');}
    public function leaderboard(int $season): array {
        // Aggregate aliases only after independent proof of a common stable ID; take
        // at most one contribution per event for that account to prevent alias duplication.
        $sql="WITH attributed AS (
SELECT r.event_id, COALESCE('id:'||p.stable_id,'name:'||p.username_key) identity,MAX(r.points) points
FROM results r JOIN players p ON p.username_key=r.username_key JOIN events e ON e.id=r.event_id
WHERE e.season_id=? AND p.account_status NOT IN ('closed:fair_play_violations','closed:fair_play','closed:cheating')
AND NOT EXISTS(SELECT 1 FROM players x WHERE x.stable_id=p.stable_id AND x.account_status IN ('closed:fair_play_violations','closed:fair_play','closed:cheating'))
GROUP BY r.event_id,identity),
totals AS (SELECT identity,SUM(points) points FROM attributed GROUP BY identity)
SELECT t.identity,t.points,(SELECT p.display FROM players p WHERE COALESCE('id:'||p.stable_id,'name:'||p.username_key)=t.identity ORDER BY p.verified DESC,p.username_key LIMIT 1) username
FROM totals t WHERE points>0 ORDER BY points DESC,lower(username),identity";
        $rows=$this->rows($sql,[$season]);
        foreach($rows as $i=>&$r){$r['rank']=$i+1;$r['points']=(int)$r['points']/100;}unset($r);
        return $rows;
    }
    public function lookup(string $username): ?string {
        $key=userKey($username);if($key==='')return null;
        $p=$this->one('SELECT * FROM players WHERE username_key=? OR lower(display)=? ORDER BY verified DESC LIMIT 1',[$key,$key]);
        if($p&&in_array($p['account_status'],['closed:fair_play_violations','closed:fair_play','closed:cheating'],true))return null;
        return $p?($p['stable_id']!==null?'id:'.$p['stable_id']:'name:'.$p['username_key']):null;
    }
}
