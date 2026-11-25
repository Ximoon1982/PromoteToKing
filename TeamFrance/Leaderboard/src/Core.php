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
function avatarUrl(mixed $v): string {
    if (!is_string($v)||strlen($v)>2000||preg_match('/[\x00-\x20\x7f]/',$v)) return '';
    $url=parse_url($v);
    if (!is_array($url)||strtolower($url['scheme']??'')!=='https'||strtolower($url['host']??'')!=='images.chesscomfiles.com'||isset($url['user'])||isset($url['pass'])||isset($url['port'])||empty($url['path'])) return '';
    return $v;
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
        $pattern=$kind==='daily'?'~^/(?:pub/match/|club/matches/(?:[^/]+/)?)([1-9][0-9]{0,17})/?$~D':'~^/tournament/live/arena/(?:[a-zA-Z0-9_-]+-)?([1-9][0-9]{0,17})(?:/download-results)?/?$~D';
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
final class FairPlaySummaryMismatch extends RuntimeException {
    public function __construct(public readonly array $boards){parent::__construct('Reconstructed points differ from official match score; board verification required.');}
}
function matchResult(array $payload,string $id,array $previous=[],array $proof=[]): array {
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
    // A finished 0–0 without physical boards is void, even when the API retains registration rows.
    if ($finished&&is_numeric($ours['score']??null)&&is_numeric($other['score']??null)&&(float)$ours['score']===0.0&&(float)$other['score']===0.0&&empty($previous['games'])) {
        $assigned=false;
        foreach (array_merge($ours['players'],$other['players']) as $p) {
            if (!is_array($p)||boardKey($p)!==''||!empty($p['played_as_white'])||!empty($p['played_as_black'])||!empty($p['results'])) {$assigned=true;break;}
        }
        if (!$assigned) return ['name'=>$name,'status'=>'finished','rows'=>[],'evidence'=>['games'=>[],'ours'=>[],'other'=>[],'void'=>true]];
    }
    $removedO=array_values(array_unique(array_merge($previous['ours']??[],removalKeys((array)($ours['fair_play_removals']??[])))));
    $removedT=array_values(array_unique(array_merge($previous['other']??[],removalKeys((array)($other['fair_play_removals']??[])))));
    $opp=[];$seen=[];$games=[];$participants=[];$prior=$previous['games']??[];
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
        if(isset($opp[$b]))$participants[$u]=['username'=>(string)$p['username'],'points'=>0];
        foreach (['played_as_white','played_as_black'] as $side) {
            $key=$b.':'.$side;$old=$prior[$key]??null;
            if ($old&&$old['user']!==$u) throw new RuntimeException('Board identity changed; review required.');
            $v=$p[$side]??$p['results'][$side]??'';
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
    // A verified paired board counts as participation even before its first game finishes.
    $rows=$participants;$total=0;$doubleRemoved=0;$rawTotal=0;$ownDelta=0;$oppDelta=0;$affected=[];
    foreach ($games as $key=>$g) {
        $ownRemoved=in_array($g['user'],$removedO,true);$oppRemoved=in_array($g['opp'],$removedT,true);
        if ($ownRemoved&&$oppRemoved) $doubleRemoved++;
        $effective=$ownRemoved?0:($oppRemoved?100:$g['raw']);
        $rawTotal+=$g['raw'];
        if($ownRemoved||$oppRemoved){
            $board=explode(':',$key,2)[0];
            $affected[$board]['games'][$key]=$g;
            $affected[$board]['ours']=($affected[$board]['ours']??0)+$effective;
            $affected[$board]['other']=($affected[$board]['other']??0)+100-$effective;
            if($ownRemoved)$ownDelta+=$effective-$g['raw'];else $oppDelta+=$effective-$g['raw'];
        }
        $u=$g['user'];$rows[$u]??=['username'=>$g['display'],'points'=>0];$rows[$u]['points']+=$effective;$total+=$effective;
    }
    $score=$ours['score']??null;
    if (!is_numeric($score)&&$finished) throw new RuntimeException('Official finished match score is missing.');
    $delta=is_numeric($score)?(int)round((float)$score*100)-$total:null;
    // Chess.com may award half a point per game on a double-removal board.
    // P2K's own-side removal rule still gives the excluded player zero.
    $verifiedBoards=[];
    if ($delta!==null&&$delta!==0&&($doubleRemoved===0||$delta!==50*$doubleRemoved)) {
        // A finished match summary may reflect only one of two sides' removals.
        // Accept this specific explanation only after checking both affected
        // sides' official board scores AND the original two game identities.
        $official=(int)round((float)$score*100);
        $opScore=$other['score']??null;
        $eligible=$finished&&$doubleRemoved===0&&$ownDelta<0&&$oppDelta>0&&count($affected)<=32&&count($games)===(int)($payload['boards']??0)*2
            &&is_numeric($opScore)&&$official+(int)round((float)$opScore*100)===count($games)*100
            &&in_array($official,[$rawTotal+$ownDelta,$rawTotal+$oppDelta,$rawTotal],true);
        if(!$eligible)throw new RuntimeException('Reconstructed points differ from official match score; previous results retained.');
        if(!$proof)throw new FairPlaySummaryMismatch(array_keys($affected));
        foreach($affected as $board=>$a){
            $bp=$proof[$board]??null;
            if(!is_array($bp)||count($a['games'])!==2||count($bp['games']??[])!==2||!is_array($bp['board_scores']??null))throw new RuntimeException('Incomplete Fair Play board verification; previous results retained.');
            $g=reset($a['games']);$u=$g['user'];$op=$g['opp'];$scores=[];
            foreach($bp['board_scores'] as $name=>$points){
                $k=userKey($name);
                if($k===''||!is_numeric($points)||isset($scores[$k]))throw new RuntimeException('Invalid official board score.');
                $scores[$k]=(int)round((float)$points*100);
            }
            if(count($scores)!==2||($scores[$u]??null)!==$a['ours']||($scores[$op]??null)!==$a['other'])throw new RuntimeException('Official Fair Play board scores disagree; previous results retained.');
            $seenSides=[];
            foreach($bp['games'] as $game){
                if(!is_array($game)||rtrim((string)($game['match']??''),'/')!==BASE.'match/'.$id||($game['time_class']??'')!=='daily')throw new RuntimeException('Wrong board game identity.');
                $white=userKey($game['white']['username']??'');$black=userKey($game['black']['username']??'');
                $side=$white===$u&&$black===$op?'played_as_white':($black===$u&&$white===$op?'played_as_black':'');
                if($side===''||isset($seenSides[$side]))throw new RuntimeException('Board game pairing mismatch.');
                $expected=$a['games'][$board.':'.$side]??null;
                $result=$game[$side==='played_as_white'?'white':'black']['result']??'';
                if(!$expected||!is_string($result)||rawPoints($result)!==$expected['raw'])throw new RuntimeException('Board game result mismatch.');
                $seenSides[$side]=true;
            }
            $verifiedBoards[$board]=['board_scores'=>$scores,'source'=>'official_board_api'];
        }
    }
    if ($finished&&$games===[]&&(int)($payload['boards']??0)>0) throw new RuntimeException('Finished match has no verified game results.');
    return ['name'=>$name,'status'=>$finished?'finished':'in_progress','rows'=>$rows,'evidence'=>['games'=>$games,'ours'=>$removedO,'other'=>$removedT,'official_delta_x100'=>$delta,'double_removed_games'=>$doubleRemoved,'verified_summary_mismatch_boards'=>$verifiedBoards]];
}
function arenaResult(string $csv,bool $official=false): array {
    if (strlen($csv)>12000000||str_contains(substr(ltrim($csv),0,100),'<')) throw new RuntimeException('Invalid arena CSV.');
    $csv=preg_replace('/^\xEF\xBB\xBF/','',$csv)??$csv;
    $fp=fopen('php://temp','r+');fwrite($fp,$csv);rewind($fp);
    // Official MCA exports contain two tables, in either order. Determine the
    // delimiter from the player header, not a title or first one-cell row.
    $sep=',';
    foreach(preg_split('/\r\n|\n|\r/',$csv) as $line){
        foreach([',',';',"\t"] as $candidate){
            $h=array_map(static fn($v)=>strtolower(trim((string)$v)),str_getcsv($line,$candidate,'"',''));
            if(in_array('username',$h,true)&&in_array('club',$h,true)&&in_array('score',$h,true)){$sep=$candidate;break 2;}
        }
    }
    $map=null;$clubMap=null;$rows=[];$count=0;$clubRows=0;$officialClubs=0;$seenHeader=false;$repairedRows=0;
    try {
        while (($r=fgetcsv($fp,0,$sep,'"',''))!==false) {
            if ($r===[null]||trim(implode('',array_map('strval',$r)))==='') continue;
            $h=array_map(static fn($s)=>strtolower(trim((string)$s)),$r);
            if (in_array('username',$h,true)&&in_array('club',$h,true)&&in_array('score',$h,true)) {$map=array_flip($h);$clubMap=null;$seenHeader=true;continue;}
            if (in_array('club',$h,true)&&!in_array('username',$h,true)&&(in_array('points',$h,true)||in_array('score',$h,true))) {
                $clubMap=array_flip($h);$map=null;continue;
            }
            // Section labels are not data.
            if(count(array_filter($h,static fn($v)=>$v!==''))===1&&in_array($h[0],['player results','club results'],true)){$map=null;$clubMap=null;continue;}
            if($clubMap!==null){
                $club=trim((string)($r[$clubMap['club']]??''));$score=trim((string)($r[$clubMap['points']??$clubMap['score']]??''));
                if($club!==''&&is_numeric($score)&&(float)$score>=0)$officialClubs++;
                else throw new RuntimeException('Invalid official Club Results row.');
                continue;
            }
            if ($map===null) continue;
            if (count($r)<=max($map['username'],$map['club'],$map['score'])) {
                // Chess.com sometimes writes an unquoted LF inside the optional
                // display Name, splitting a real player row in two. Recover only
                // a structurally proven continuation of that field, never guess
                // a missing username, club or score, and never skip a broken row.
                $namePos=$map['name']??-1;$rankPos=$map['rank']??-1;
                if($namePos<0||$rankPos<0||$rankPos>=$namePos||$map['username']>=$namePos||$map['club']<=$namePos||$map['score']<=$namePos||count($r)!==$namePos+1||!preg_match('/^\d+$/D',trim((string)($r[$rankPos]??'')))||userKey($r[$map['username']]??'')==='')throw new RuntimeException('Truncated arena CSV row.');
                $tail=fgetcsv($fp,0,$sep,'"','');
                if($tail===false||$tail===[null]||trim((string)($tail[0]??''))===''||preg_match('/^\d+$/D',trim((string)$tail[0])))throw new RuntimeException('Truncated arena CSV row: no verified display-name continuation.');
                $r[$namePos]=(string)$r[$namePos]."\n".(string)array_shift($tail);
                $r=array_merge($r,$tail);$expected=max($map)+1;
                $candidateScore=str_replace(',','.',trim((string)($r[$map['score']]??'')));
                if((count($r)!==$expected&&(count($r)!==$expected+1||end($r)!==''))||trim((string)($r[$map['club']]??''))===''||!is_numeric($candidateScore)||!is_finite((float)$candidateScore)||(float)$candidateScore<0)throw new RuntimeException('Truncated arena CSV row: invalid display-name continuation.');
                $repairedRows++;
            }
            $u=userKey($r[$map['username']]);$score=str_replace(',','.',trim((string)$r[$map['score']]));
            if ($u===''||!is_numeric($score)||!is_finite((float)$score)||(float)$score<0) throw new RuntimeException('Invalid arena participant row.');
            $count++;
            if (clubKey($r[$map['club']])!==CLUB) continue;
            $clubRows++;
            $rows[$u]??=['username'=>trim((string)$r[$map['username']]),'points'=>0];
            $rows[$u]['points']+=(int)round((float)$score*100);
        }
    } finally {fclose($fp);}
    if (!$seenHeader||$count===0) throw new RuntimeException('Missing official Player Results table.');
    if ($official&&$officialClubs===0) throw new RuntimeException('Official Results CSV lacks Club Results.');
    return ['rows'=>$rows,'evidence'=>['player_rows'=>$count,'club_rows'=>$clubRows,'official_club_rows'=>$officialClubs,'repaired_player_rows'=>$repairedRows]];
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
        if(!$refs||count($refs)>10000)throw new RuntimeException('Enter 1–10000 event references.');
        $hints=is_array($p['name_hints']??null)?$p['name_hints']:[];
        $this->tx(function()use($season,$refs,$hints){
            foreach($refs as $ref){
                [$id,$url]=eventRef($season['kind'],$ref);
                $existing=$this->one('SELECT season_id FROM events WHERE kind=? AND event_id=?',[$season['kind'],$id]);
                if($existing&&(int)$existing['season_id']!==(int)$season['id'])throw new RuntimeException('Event '.$id.' already belongs to another season. Move it explicitly.');
                $hint=is_string($hints[$id]??null)?trim($hints[$id]):'';
                if(strlen($hint)>500)$hint='';
                $this->run('INSERT INTO events(kind,event_id,season_id,url,name) VALUES(?,?,?,?,?) ON CONFLICT(kind,event_id) DO UPDATE SET name=CASE WHEN events.name=\'\' THEN excluded.name ELSE events.name END',[$season['kind'],$id,$season['id'],$url,$hint]);
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
            // Cached avatar is keyed by verified stable account identity, shared across aliases.
            $this->put('avatar:id:'.$id,avatarUrl($p['avatar']??null));
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
    public function leaderboard(int $season,bool $includeUnranked=false): array {
        return $this->rankings('e.season_id=?',[$season],$includeUnranked);
    }
    public function categoryLeaderboard(string $kind,bool $includeUnranked=false): array {
        if(!in_array($kind,['daily','arena'],true))throw new RuntimeException('Unknown leaderboard category.');
        return $this->rankings('e.kind=?',[$kind],$includeUnranked);
    }
    private function rankings(string $scope,array $args,bool $includeUnranked): array {
        // Aggregate aliases only after independent proof of a common stable ID; take
        // at most one contribution per event for that account to prevent alias duplication.
        $sql="WITH attributed AS (
SELECT r.event_id, COALESCE('id:'||p.stable_id,'name:'||p.username_key) identity,MAX(r.points) points
FROM results r JOIN players p ON p.username_key=r.username_key JOIN events e ON e.id=r.event_id
WHERE $scope AND p.account_status NOT IN ('closed:fair_play_violations','closed:fair_play','closed:cheating')
AND NOT EXISTS(SELECT 1 FROM players x WHERE x.stable_id=p.stable_id AND x.account_status IN ('closed:fair_play_violations','closed:fair_play','closed:cheating'))
GROUP BY r.event_id,identity),
totals AS (SELECT identity,SUM(points) points,COUNT(*) events FROM attributed GROUP BY identity)
SELECT t.identity,t.points,t.events,COALESCE((SELECT value FROM meta WHERE key='avatar:'||t.identity),'') avatar,(SELECT p.display FROM players p WHERE COALESCE('id:'||p.stable_id,'name:'||p.username_key)=t.identity ORDER BY p.verified DESC,p.username_key LIMIT 1) username
FROM totals t ".($includeUnranked?"":"WHERE points>0 ")."ORDER BY points DESC,lower(username),identity";
        $rows=$this->rows($sql,$args);
        foreach($rows as $i=>&$r){$r['rank']=(int)$r['points']>0?$i+1:null;$r['points']=(int)$r['points']/100;$r['events']=(int)$r['events'];$r['avatar']=avatarUrl($r['avatar']);}unset($r);
        return $rows;
    }
    public function publicPlayer(string $identity): ?array {
        $p=$this->one("SELECT display,stable_id,username_key FROM players p WHERE COALESCE('id:'||p.stable_id,'name:'||p.username_key)=? AND NOT EXISTS(SELECT 1 FROM players x WHERE (x.username_key=p.username_key OR x.stable_id=p.stable_id) AND x.account_status IN ('closed:fair_play_violations','closed:fair_play','closed:cheating')) ORDER BY verified DESC,username_key LIMIT 1",[$identity]);
        return $p?['username'=>$p['display'],'avatar'=>avatarUrl($this->meta('avatar:'.$identity))]:null;
    }
    public function lookup(string $username): ?string {
        $key=userKey($username);if($key==='')return null;
        $p=$this->one('SELECT * FROM players WHERE username_key=? OR lower(display)=? ORDER BY verified DESC LIMIT 1',[$key,$key]);
        if($p&&in_array($p['account_status'],['closed:fair_play_violations','closed:fair_play','closed:cheating'],true))return null;
        return $p?($p['stable_id']!==null?'id:'.$p['stable_id']:'name:'.$p['username_key']):null;
    }
}
