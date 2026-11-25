<?php
declare(strict_types=1);
namespace TeamFrance\Leaderboard;
require_once __DIR__.'/Core.php';
use RuntimeException;

final class Transport {
    public static function get(string $url,int $timeout=8,bool $allowPlayerRedirect=false): array {
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL required.');
        for($redirect=0;$redirect<4;$redirect++){
            $u=parse_url($url);$host=strtolower($u['host']??'');
            if (($u['scheme']??'')!=='https'||!in_array($host,['api.chess.com','www.chess.com','chess.com'],true)||isset($u['user'])||isset($u['pass'])||isset($u['port'])) throw new RuntimeException('Unapproved Chess.com URL.');
            $body='';$headers=[];$ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>min(4,$timeout),CURLOPT_TIMEOUT=>$timeout,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'TeamFrance-Leaderboard/0.1.0 (+https://www.promotetoking.org/)',CURLOPT_HTTPHEADER=>['Accept: application/json,text/csv,text/html,*/*'],CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$headers){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>12000000)return 0;$body.=$chunk;return strlen($chunk);}]);
            $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
            if(!$ok)throw new RuntimeException('Chess.com transport failure.');
            if(in_array($status,[301,302,303,307,308],true)&&isset($headers['location'])){
                // Public profile redirects are not identity evidence. Return them unresolved.
                $location=$headers['location'];$next=str_starts_with($location,'/')?'https://'.$host.$location:$location;
                if($host==='api.chess.com'&&(!$allowPlayerRedirect||!self::playerRedirect($next)))throw new RuntimeException('HTTP '.$status.'; player rename requires verified identity.');
                $url=$next;continue;
            }
            if($status<200||$status>=300)throw new RuntimeException('Chess.com HTTP '.$status, $status);
            return ['body'=>$body,'url'=>$url,'headers'=>$headers];
        }
        throw new RuntimeException('Too many redirects.');
    }
    public static function playerRedirect(string $url): bool {
        $u=parse_url($url);
        return is_array($u)&&($u['scheme']??'')==='https'&&($u['host']??'')==='api.chess.com'&&!isset($u['port'])&&!isset($u['user'])&&!isset($u['pass'])&&preg_match('~^/pub/player/[a-zA-Z0-9_-]{1,80}/?$~D',$u['path']??'')===1;
    }
    public static function json(string $url,bool $allowPlayerRedirect=false): array {
        $r=self::get($url,8,$allowPlayerRedirect);$j=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($j))throw new RuntimeException('Invalid API response.');
        return $j;
    }
    public static function oauth(array $requests): array {
        if(!class_exists('\P2K\TeamPoints\OAuthSession')||!method_exists('\P2K\TeamPoints\OAuthSession','batchForAuthorizedRequest'))throw new RuntimeException('P2K OAuth gateway unavailable.');
        // Foreground adaptive gateway: requested capacity is the whole finite batch,
        // not a fixed application-specific speed limit. Shared transport controls pacing.
        $batch=\P2K\TeamPoints\OAuthSession::batchForAuthorizedRequest($requests,max(1,count($requests)),0.0,'foreground');
        if($batch===null)throw new RuntimeException('OAuth session expired. Sign in and resume.');
        return $batch;
    }
}
final class Refresh {
    public function __construct(public Store $s, private ?\Closure $arenaFetch=null) {}
    public function daily(array $e,array $p,bool $oauth=false): void {
        // Preserve independently validated metadata even if scoring fails.
        if (preg_match('~/pub/match/'.preg_quote($e['event_id'],'~').'/?$~D',(string)($p['@id']??''))) {
            $teams=is_array($p['teams']??null)?$p['teams']:[];
            foreach ($teams as $t) if (is_array($t)&&clubKey($t['@id']??$t['url']??'')===CLUB) {
                $name=trim((string)($p['name']??''));
                if ($name!=='') $this->s->run('UPDATE events SET name=? WHERE id=?',[$name,$e['id']]);
                break;
            }
        }
        $prior=json_decode($e['evidence'],true)?:[];
        try{$r=matchResult($p,$e['event_id'],$prior);}
        catch(FairPlaySummaryMismatch $x){
            if(!$oauth&&count($x->boards)>2)throw new RuntimeException('Fair Play board verification requires an administrator refresh.');
            $proof=[];
            if($oauth){
                $requests=[];foreach($x->boards as $board)$requests[]=['id'=>(string)$board,'url'=>BASE.'match/'.$e['event_id'].'/'.$board,'headers'=>['accept'=>'application/json']];
                $batch=Transport::oauth($requests);
                foreach($batch['results']??[] as $row)if((int)($row['status']??0)===200&&is_array($row['json']??null))$proof[(string)$row['id']]=$row['json'];
            }else foreach($x->boards as $board)$proof[$board]=Transport::json(BASE.'match/'.$e['event_id'].'/'.$board);
            $r=matchResult($p,$e['event_id'],$prior,$proof);
            foreach($proof as $board=>$body)$r['evidence']['verified_summary_mismatch_boards'][$board]['source_hash']=$this->s->archive(json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        }
        $this->s->saveEvent($e,$r,json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
    public function arena(array $e): void {
        $fetch=$this->arenaFetch??static fn(string $url,int $timeout)=>Transport::get($url,$timeout);
        $page=$fetch($e['url'],12);
        [$id,$url]=eventRef('arena',$page['url']);
        if($id!==$e['event_id'])throw new RuntimeException('Arena redirect identity mismatch.');
        $html=$page['body'];
        // CWL 0.2.10 uses the canonical /download-results route even when
        // Chess.com does not render an anchor. Never follow arbitrary HTML links.
        $csvUrl=rtrim($url,'/').'/download-results';
        // An explicit, recognized non-final tournament status prevents scoring.
        // Missing HTML state alone is not proof of an ongoing event.
        $pending=preg_match('~\bdata-tournament-status=["\x27](?:upcoming|registration|registered|scheduled|in_progress|in-progress|ongoing|started|running)["\x27]~i',$html)===1;
        if($pending){
            if($e['status']==='finished')throw new RuntimeException('Previously imported arena now reports non-final status; prior results retained.');
            $this->s->run('UPDATE events SET status=?,checked=?,due=?,error=? WHERE id=?',['awaiting_results',time(),time()+1800,'Arena is not finished yet.',$e['id']]);
            return;
        }
        // The public results endpoint is the final CSV source of record.
        // Do not use live HTML scores, HTML error bodies or player-only exports.
        // Honor the same conservative inter-request spacing as the CWL scraper.
        if($this->arenaFetch===null)usleep(1000000);
        try{
            $r=$fetch($csvUrl,18);
        }catch(\Throwable $x){
            throw new RuntimeException('Official Results CSV download failed: '.$x->getMessage(),(int)$x->getCode(),$x);
        }
        [$csvId,$csvArena]=eventRef('arena',$r['url']);
        if($csvId!==$id||$csvArena!==$url||!str_ends_with(rtrim((string)parse_url($r['url'],PHP_URL_PATH),'/'),'/download-results'))throw new RuntimeException('Official Results CSV redirected outside the validated arena endpoint.');
        try{$parsed=arenaResult($r['body'],true);}catch(\Throwable $x){throw new RuntimeException('Official Results CSV validation failed: '.$x->getMessage(),0,$x);}
        // An empty Team France attribution after a previously nonempty import
        // is ambiguous; do not silently wipe the season contribution.
        if($parsed['rows']===[]&&$this->s->one('SELECT 1 FROM results WHERE event_id=? LIMIT 1',[$e['id']]))throw new RuntimeException('Team France rows disappeared; review CSV.');
        $name=$e['name']?:'Arena #'.$id;
        if(preg_match('~<title[^>]*>(.*?)</title>~is',$html,$title))$name=trim(html_entity_decode(strip_tags($title[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'))?:$name;
        $parsed['evidence']['source_url']=$csvUrl;
        $this->s->saveEvent($e,$parsed+['name'=>$name,'status'=>'finished'],$r['body']);
        $this->s->run('UPDATE events SET url=? WHERE id=?',[$url,$e['id']]);
    }
    public function importCsv(int $event,string $csv): void {
        $e=$this->s->one("SELECT * FROM events WHERE id=? AND kind='arena'",[$event]);
        if(!$e)throw new RuntimeException('Arena not found.');
        $r=arenaResult($csv);
        if($r['rows']===[]&&$this->s->one('SELECT 1 FROM results WHERE event_id=? LIMIT 1',[$event]))throw new RuntimeException('Team France rows disappeared; review CSV.');
        $this->s->saveEvent($e,$r+['name'=>$e['name']?:'Arena #'.$e['event_id'],'status'=>'finished'],$csv);
    }
    public function publicStep(): array {
        return $this->s->lock(function(){
            $now=time();
            if($this->s->one("SELECT 1 FROM jobs WHERE state='running' AND updated>?",[$now-180]))return ['busy'=>true];
            if((int)$this->s->meta('public_next','0')>$now)return ['deferred'=>true];
            $e=$this->s->one('SELECT e.* FROM events e JOIN seasons s ON s.id=e.season_id WHERE s.archived=0 AND e.due<=? ORDER BY e.due,e.id LIMIT 1',[$now]);
            $day=gmdate('Y-m-d');$count=(int)$this->s->meta('player_count:'.$day,'0');
            $p=$count<500&&(int)$this->s->meta('player_next','0')<=$now?$this->s->one('SELECT * FROM players WHERE due<=? ORDER BY due,username_key LIMIT 1',[$now]):null;
            if(!$e&&!$p)return ['fresh'=>true];
            $player=$p&&(!$e||$this->s->meta('last_kind')!=='player');
            $this->s->put('public_next',(string)($now+2));$this->s->put('last_kind',$player?'player':'event');
            try{
                if($player){
                    $this->s->put('player_count:'.$day,(string)($count+1));$this->s->put('player_next',(string)($now+60));
                    $this->s->saveProfile($p['username_key'],Transport::json(BASE.'player/'.rawurlencode($p['username_key']),$p['stable_id']!==null));
                }elseif($e['kind']==='daily')$this->daily($e,Transport::json(BASE.'match/'.$e['event_id']));
                else $this->arena($e);
            }catch(\Throwable $x){
                $this->s->fail($player?'player':$e['kind'],$player?$p['username_key']:(string)$e['id'],$x->getMessage(),$player?86400:1800);
                if($x->getCode()===429)$this->s->put('public_next',(string)($now+300));
            }
            return ['processed'=>1];
        });
    }
    public function start(string $owner): array {
        return $this->s->lock(function()use($owner){
            $job=$this->s->one('SELECT * FROM jobs WHERE id=1');
            if($job&&in_array($job['state'],['running','paused'],true))throw new RuntimeException('An unfinished refresh exists. Resume or stop it.');
            $this->s->tx(function()use($owner){
                $this->s->run('DELETE FROM tasks');
                $this->s->run("INSERT INTO tasks(kind,item) SELECT kind,CAST(id AS TEXT) FROM events");
                $this->s->run("INSERT INTO jobs(id,state,phase,created,updated,owner) VALUES(1,'running','events',?,?,?) ON CONFLICT(id) DO UPDATE SET state=excluded.state,phase=excluded.phase,created=excluded.created,updated=excluded.updated,owner=excluded.owner",[time(),time(),$owner]);
            });
            return $this->progress();
        });
    }
    public function control(string $action,string $owner): array {
        return $this->s->lock(function()use($action,$owner){
            $job=$this->s->one('SELECT * FROM jobs WHERE id=1');
            if(!$job||!in_array($job['state'],['running','paused'],true))throw new RuntimeException('No resumable refresh.');
            $state=match($action){'pause'=>'paused','resume'=>'running','stop'=>'stopped',default=>throw new RuntimeException('Unknown action.')};
            $this->s->run('UPDATE jobs SET state=?,updated=?,owner=? WHERE id=1',[$state,time(),$owner]);
            return $this->progress();
        });
    }
    public function progress(): array {
        return ['job'=>$this->s->one('SELECT * FROM jobs WHERE id=1'),'counts'=>$this->s->rows('SELECT kind,state,COUNT(*) n FROM tasks GROUP BY kind,state'),'errors'=>$this->s->rows("SELECT kind,item,error FROM tasks WHERE state='error' ORDER BY kind,item LIMIT 30"),'transport'=>json_decode($this->s->meta('last_transport','{}'),true)];
    }
    private function deferTask(array $t,string $error,int $delay): void {
        $attempts=(int)$t['attempts']+1;
        if($attempts>=3){$this->s->fail($t['kind'],(string)$t['item'],$error,$delay);$this->taskDone($t,'error',$error);return;}
        $this->s->run('UPDATE tasks SET attempts=?,next_try=?,error=? WHERE kind=? AND item=?',[$attempts,time()+$delay,substr($error,0,500),$t['kind'],$t['item']]);
    }
    private function taskDone(array $t,string $state,string $error=''): void {
        $this->s->run('UPDATE tasks SET state=?,error=? WHERE kind=? AND item=?',[$state,substr($error,0,500),$t['kind'],$t['item']]);
    }
    public function step(string $owner): array {
        return $this->s->lock(function()use($owner){
            $job=$this->s->one('SELECT * FROM jobs WHERE id=1');
            if(!$job||$job['state']!=='running')return $this->progress();
            if($job['owner']!==$owner)throw new RuntimeException('Resume this refresh explicitly before taking ownership.');
            $this->s->run('UPDATE jobs SET updated=? WHERE id=1',[time()]);
            $kind=$job['phase']==='players'?'player':'daily';
            $tasks=$this->s->rows("SELECT * FROM tasks WHERE kind=? AND state='pending' AND next_try<=? ORDER BY item LIMIT 48",[$kind,time()]);
            if($tasks){
                $requests=[];$valid=[];
                foreach($tasks as $t){
                    $e=$kind==='daily'?$this->s->one('SELECT * FROM events WHERE id=?',[(int)$t['item']]):null;
                    if($kind==='daily'&&!$e){$this->taskDone($t,'error','Event removed.');continue;}
                    $valid[$t['item']]=[$t,$e];
                    $requests[]=['id'=>(string)$t['item'],'url'=>BASE.($kind==='daily'?'match/'.$e['event_id']:'player/'.rawurlencode($t['item'])),'headers'=>['accept'=>'application/json']];
                }
                if($requests){
                    $batch=Transport::oauth($requests);$seen=[];
                    if($kind==='player'){
                        $redirects=[];$redirectIds=[];
                        foreach(($batch['results']??[]) as $entry){
                            $rid=(string)($entry['id']??'');$loc=(string)($entry['headers']['location']??'');
                            if(str_starts_with($loc,'/'))$loc='https://api.chess.com'.$loc;
                            $old=$this->s->one('SELECT stable_id FROM players WHERE username_key=?',[$rid]);
                            if(isset($valid[$rid])&&in_array((int)($entry['status']??0),[301,302,307,308],true)&&!empty($old['stable_id'])&&Transport::playerRedirect($loc)){
                                $redirects[]=['id'=>$rid,'url'=>$loc,'headers'=>['accept'=>'application/json']];$redirectIds[$rid]=true;
                            }
                        }
                        if($redirects){
                            $follow=Transport::oauth($redirects);
                            $batch['results']=array_merge(array_values(array_filter($batch['results'],static fn($v)=>!isset($redirectIds[(string)($v['id']??'')]))),array_values($follow['results']??[]));
                        }
                    }
                    $this->s->put('last_transport',json_encode(array_intersect_key($batch,array_flip(['rate_cps','launch_cps','peak_in_flight','processed','elapsed_ms','transport_capacity'])),JSON_THROW_ON_ERROR));
                    foreach(($batch['results']??[]) as $r){
                        $id=(string)($r['id']??'');if(!isset($valid[$id]))continue;
                        [$t,$e]=$valid[$id];$seen[$id]=true;$status=(int)($r['status']??0);
                        if(in_array($status,[401,403],true)){
                            $this->s->run("UPDATE jobs SET state='paused',updated=? WHERE id=1",[time()]);
                            throw new RuntimeException('OAuth request rejected; sign in and resume.');
                        }
                        if($status===429||$status===0||$status>=500){
                            $retry=(string)($r['headers']['retry-after']??'');$delay=ctype_digit($retry)?max(2,min(3600,(int)$retry)):min(60,2**((int)$t['attempts']+1));
                            $this->deferTask($t,'HTTP '.$status,$delay);continue;
                        }
                        try{
                            if($status<200||$status>=300||!is_array($r['json']??null))throw new RuntimeException('HTTP '.$status);
                            if($kind==='daily')$this->daily($e,$r['json'],true);else $this->s->saveProfile($id,$r['json']);
                            $this->taskDone($t,'done');
                        }catch(\Throwable $x){$this->s->fail($kind,$id,$x->getMessage());$this->taskDone($t,'error',$x->getMessage());}
                    }
                    foreach($valid as $id=>[$t,$e])if(!isset($seen[$id]))$this->deferTask($t,'No transport result',5);
                }
            }elseif($this->s->one("SELECT 1 FROM tasks WHERE kind=? AND state='pending' LIMIT 1",[$kind])){
                return $this->progress()+['wait'=>true];
            }elseif($job['phase']==='events'){
                $t=$this->s->one("SELECT * FROM tasks WHERE kind='arena' AND state='pending' ORDER BY item LIMIT 1");
                if($t){
                    try{$e=$this->s->one('SELECT * FROM events WHERE id=?',[(int)$t['item']]);if(!$e)throw new RuntimeException('Event removed.');
                        $this->arena($e);$this->taskDone($t,'done');
                    }catch(\Throwable $x){$this->s->fail('arena',$t['item'],$x->getMessage());$this->taskDone($t,'error',$x->getMessage());}
                }else{
                    $this->s->tx(function(){
                        $this->s->run("INSERT OR IGNORE INTO tasks(kind,item) SELECT 'player',username_key FROM players");
                        $this->s->run("UPDATE jobs SET phase='players',updated=? WHERE id=1",[time()]);
                    });
                }
            }else{
                $errors=(int)$this->s->run("SELECT COUNT(*) FROM tasks WHERE state='error'")->fetchColumn();
                $this->s->run('UPDATE jobs SET state=?,updated=? WHERE id=1',[$errors?'completed_with_errors':'completed',time()]);
                $this->s->put('updated',(string)time());
            }
            $this->s->run('UPDATE jobs SET updated=? WHERE id=1',[time()]);
            return $this->progress();
        });
    }
}
