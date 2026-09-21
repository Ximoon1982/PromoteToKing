<?php
declare(strict_types=1);

require_once __DIR__ . '/../server/team-points/src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Database;

final class P2KPlayerDiscoveryStore
{
    private const SCHEMA_VERSION = 1;
    private PDO $pdo;

    public function __construct(?PDO $pdo=null){$this->pdo=$pdo??Database::core();$this->ensureSchema();}

    public static function normalizeUsername(string $value): string {
        $value=strtolower(trim($value)); return preg_match('/^[a-z0-9_-]{1,80}$/D',$value)===1?$value:'';
    }

    /** @return array{0:int,1:int,2:list<string>} */
    public static function scanWindow(int $months, ?DateTimeImmutable $now=null): array {
        $months=max(1,min(24,$months));$tz=new DateTimeZone('UTC');$now=($now??new DateTimeImmutable('now',$tz))->setTimezone($tz);
        $y=(int)$now->format('Y');$m=(int)$now->format('n');$d=(int)$now->format('j');$idx=$y*12+$m-1-$months;
        $ty=intdiv($idx,12);$tm=$idx%12+1;if($tm<=0){$tm+=12;$ty--;}
        $last=(int)(new DateTimeImmutable(sprintf('%04d-%02d-01',$ty,$tm),$tz))->modify('last day of this month')->format('j');
        $cut=$now->setDate($ty,$tm,min($d,$last));$cur=new DateTimeImmutable($cut->format('Y-m-01 00:00:00'),$tz);$end=new DateTimeImmutable($now->format('Y-m-01 00:00:00'),$tz);$keys=[];
        while($cur<=$end){$keys[]=$cur->format('Y/m');$cur=$cur->modify('+1 month');}
        return [$cut->getTimestamp(),$now->getTimestamp(),$keys];
    }

    public function createJob(array $owner,int $months,string $mode,bool $include960,int $maxConcurrency): array {
        $months=max(1,min(24,$months));$mode=$mode==='all'?'all':'team';$maxConcurrency=max(1,min(64,$maxConcurrency));[$cut,$end,$keys]=self::scanWindow($months);$id=p2k_tp_uuid();
        $q=$this->pdo->prepare("INSERT INTO p2k_pd_jobs(job_id,owner_player_id,owner_username,state,phase,months,daily_mode,include_chess960,max_concurrency,cutoff_epoch,scan_end_epoch,month_keys_json,created_at,updated_at) VALUES(?,?,?,'draft','seed-import',?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $q->execute([$id,$owner['player_id']?:null,$owner['username'],$months,$mode,$include960?1:0,$maxConcurrency,$cut,$end,json_encode($keys,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);return $this->job($id,$owner);
    }

    public function addSeeds(string $id,array $owner,array $names): array {
        $this->assertState($id,$owner,['draft']);$u=[];foreach($names as $v){$k=self::normalizeUsername((string)$v);if($k!=='')$u[$k]=1;}if(!$u)return ['accepted'=>0,'seed_total'=>$this->count($id,'is_seed=1'),'player_total'=>$this->count($id,'1=1')];
        $this->pdo->beginTransaction();try{$ins=$this->pdo->prepare("INSERT INTO p2k_pd_players(job_id,username_key,username_display,is_seed,is_discovered,discovery_hits,discovery_state,enrichment_state,created_at,updated_at) VALUES(?,?,?,1,0,0,'pending','pending',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE is_seed=1,username_display=VALUES(username_display),updated_at=UTC_TIMESTAMP()");foreach(array_keys($u) as $k)$ins->execute([$id,$k,$k]);$s=$this->count($id,'is_seed=1');$p=$this->count($id,'1=1');$this->pdo->prepare('UPDATE p2k_pd_jobs SET seed_total=?,player_total=?,updated_at=UTC_TIMESTAMP() WHERE job_id=?')->execute([$s,$p,$id]);$this->pdo->commit();}catch(Throwable $e){$this->rollback();throw $e;}return ['accepted'=>count($u),'seed_total'=>$s,'player_total'=>$p];
    }

    public function finalizeSeeds(string $id,array $owner): array {
        $j=$this->assertState($id,$owner,['draft']);if((int)$j['seed_total']<1)throw new ApiException('Add at least one seed player first.',400,'NO_SEEDS');$this->pdo->prepare("UPDATE p2k_pd_jobs SET state='running',phase='discovery',started_at=COALESCE(started_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE job_id=?")->execute([$id]);return $this->job($id,$owner);
    }

    public function pause(string $id,array $owner): array {$this->owned($id,$owner);$this->pdo->prepare("UPDATE p2k_pd_jobs SET state='paused',updated_at=UTC_TIMESTAMP() WHERE job_id=? AND state<>'complete'")->execute([$id]);return $this->job($id,$owner);}

    public function resume(string $id,array $owner,string $client=''): array {
        $j=$this->owned($id,$owner);if($j['state']==='draft')throw new ApiException('Finish importing seeds before starting the job.',409,'JOB_DRAFT');$client=$this->clientId($client);
        if($client!==''){foreach(['discovery','enrichment'] as $p)$this->pdo->prepare("UPDATE p2k_pd_players SET {$p}_state='pending',lease_token=NULL,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE job_id=? AND {$p}_state='in_progress' AND lease_owner=?")->execute([$id,$client]);}
        if($j['state']!=='complete')$this->pdo->prepare("UPDATE p2k_pd_jobs SET state='running',updated_at=UTC_TIMESTAMP() WHERE job_id=?")->execute([$id]);return $this->job($id,$owner);
    }

    public function claim(string $id,array $owner,int $limit,string $client=''): array {
        $limit=max(1,min(100,$limit));$client=$this->clientId($client)?:bin2hex(random_bytes(16));$j=$this->owned($id,$owner);if($j['state']!=='running'||!in_array($j['phase'],['discovery','enrichment'],true))return ['job'=>$this->present($j),'lease_token'=>'','items'=>[]];
        $p=(string)$j['phase'];$token=bin2hex(random_bytes(16));$this->pdo->beginTransaction();try{
            if($p==='discovery'){
                $q=$this->pdo->prepare("SELECT username_key,username_display,discovery_attempts attempts FROM p2k_pd_players WHERE job_id=? AND is_seed=1 AND duplicate_of IS NULL AND (discovery_state='pending' OR (discovery_state='in_progress' AND lease_until<UTC_TIMESTAMP())) ORDER BY username_key LIMIT {$limit} FOR UPDATE");
            }else{
                $q=$this->pdo->prepare("SELECT username_key,username_display,enrichment_attempts attempts FROM p2k_pd_players WHERE job_id=? AND duplicate_of IS NULL AND (enrichment_state='pending' OR (enrichment_state='in_progress' AND lease_until<UTC_TIMESTAMP())) ORDER BY username_key LIMIT {$limit} FOR UPDATE");
            }
            $q->execute([$id]);$items=$q->fetchAll(PDO::FETCH_ASSOC)?:[];if($items){$keys=array_column($items,'username_key');$ph=implode(',',array_fill(0,count($keys),'?'));
                if($p==='discovery')$up=$this->pdo->prepare("UPDATE p2k_pd_players SET discovery_state='in_progress',discovery_attempts=discovery_attempts+1,lease_token=?,lease_owner=?,lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),updated_at=UTC_TIMESTAMP() WHERE job_id=? AND username_key IN ({$ph})");
                else $up=$this->pdo->prepare("UPDATE p2k_pd_players SET enrichment_state='in_progress',enrichment_attempts=enrichment_attempts+1,lease_token=?,lease_owner=?,lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),updated_at=UTC_TIMESTAMP() WHERE job_id=? AND username_key IN ({$ph})");
                $up->execute(array_merge([$token,$client,$id],$keys));foreach($items as &$x)$x['attempts']+1;unset($x);}
            $this->pdo->commit();}catch(Throwable $e){$this->rollback();throw $e;}return ['job'=>$this->job($id,$owner),'lease_token'=>$items?$token:'','items'=>$items];
    }

    public function completeDiscovery(string $id,array $owner,string $username,string $token,array $opponents,array $metrics): array {
        $this->owned($id,$owner);$u=self::normalizeUsername($username);if($u==='')throw new ApiException('Invalid username.',400,'INVALID_USERNAME');$this->pdo->beginTransaction();try{$row=$this->lockPlayer($id,$u);if($row['discovery_state']==='done'){$this->pdo->commit();return $this->job($id,$owner);}if($row['discovery_state']!=='in_progress'||!hash_equals((string)$row['lease_token'],$token))throw new ApiException('Discovery lease expired.',409,'LEASE_EXPIRED');$uniq=[];foreach($opponents as $v){$k=self::normalizeUsername((string)$v);if($k!==''&&$k!==$u)$uniq[$k]=1;}$ins=$this->pdo->prepare("INSERT INTO p2k_pd_players(job_id,username_key,username_display,is_seed,is_discovered,discovery_hits,first_discovered_by,discovery_state,enrichment_state,created_at,updated_at) VALUES(?,?,?,0,1,1,?,'not_applicable','pending',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE is_discovered=1,discovery_hits=discovery_hits+1,first_discovered_by=COALESCE(first_discovered_by,VALUES(first_discovered_by)),updated_at=UTC_TIMESTAMP()");foreach(array_keys($uniq) as $o)$ins->execute([$id,$o,$o,$u]);$this->pdo->prepare("UPDATE p2k_pd_players SET discovery_state='done',discovery_error=NULL,lease_token=NULL,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE job_id=? AND username_key=?")->execute([$id,$u]);$req=max(0,(int)($metrics['requests']??0));$games=max(0,(int)($metrics['daily_games']??0));$edges=max(0,(int)($metrics['opponent_edges']??0));$players=$this->count($id,'duplicate_of IS NULL');$this->pdo->prepare('UPDATE p2k_pd_jobs SET discovery_done=discovery_done+1,archive_requests_done=archive_requests_done+?,daily_games_seen=daily_games_seen+?,opponent_edges_seen=opponent_edges_seen+?,player_total=?,updated_at=UTC_TIMESTAMP() WHERE job_id=?')->execute([$req,$games,$edges,$players,$id]);$this->advanceLocked($id);$this->pdo->commit();}catch(Throwable $e){$this->rollback();throw $e;}return $this->job($id,$owner);
    }

    public function completeEnrichment(string $id,array $owner,string $username,string $token,array $payload,array $metrics): array {
        $this->owned($id,$owner);$u=self::normalizeUsername($username);if($u==='')throw new ApiException('Invalid username.',400,'INVALID_USERNAME');$this->pdo->beginTransaction();try{$row=$this->lockPlayer($id,$u);if($row['enrichment_state']==='done'){$this->pdo->commit();return $this->job($id,$owner);}if($row['enrichment_state']!=='in_progress'||!hash_equals((string)$row['lease_token'],$token))throw new ApiException('Enrichment lease expired.',409,'LEASE_EXPIRED');$profile=is_array($payload['profile']??null)?$payload['profile']:[];$stats=is_array($payload['stats']??null)?$payload['stats']:[];$clubs=is_array($payload['clubs']??null)?$payload['clubs']:[];$daily=is_array($stats['chess_daily']??null)?$stats['chess_daily']:[];$record=is_array($daily['record']??null)?$daily['record']:[];$last=is_array($daily['last']??null)?$daily['last']:[];$best=is_array($daily['best']??null)?$daily['best']:[];$canon=self::normalizeUsername((string)($profile['username']??$u))?:$u;$pid=self::intOrNull($profile['player_id']??null);[$clubCount,$active30,$active90]=$this->clubStats($clubs);
        $up=$this->pdo->prepare("UPDATE p2k_pd_players SET player_id=?,canonical_username=?,profile_status=?,title=?,display_name=?,location=?,country_code=?,joined_epoch=?,last_online_epoch=?,followers=?,fide=?,avatar_url=?,profile_url=?,daily_rating=?,daily_rd=?,daily_wins=?,daily_losses=?,daily_draws=?,daily_timeout_percent=?,daily_time_per_move=?,daily_best_rating=?,daily_last_game_epoch=?,club_count=?,active_clubs_30d=?,active_clubs_90d=?,enrichment_state='done',enrichment_error=NULL,lease_token=NULL,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE job_id=? AND username_key=?");
        $up->execute([$pid,$canon,self::text($profile['status']??null,40),self::text($profile['title']??null,10),self::text($profile['name']??null,160),self::text($profile['location']??null,160),self::country((string)($profile['country']??'')),self::intOrNull($profile['joined']??null),self::intOrNull($profile['last_online']??null),self::intOrNull($profile['followers']??null),self::intOrNull($profile['fide']??null),self::url($profile['avatar']??null),self::url($profile['url']??null),self::intOrNull($last['rating']??null),self::intOrNull($last['rd']??null),self::intOrNull($record['win']??null),self::intOrNull($record['loss']??null),self::intOrNull($record['draw']??null),self::floatOrNull($record['timeout_percent']??null),self::intOrNull($record['time_per_move']??null),self::intOrNull($best['rating']??null),self::intOrNull($last['date']??null),$clubCount,$active30,$active90,$id,$u]);
        $this->mergeDuplicate($id,$u,$pid,$canon);$req=max(0,(int)($metrics['requests']??0));$this->pdo->prepare('UPDATE p2k_pd_jobs SET enrichment_done=enrichment_done+1,enrichment_requests_done=enrichment_requests_done+?,updated_at=UTC_TIMESTAMP() WHERE job_id=?')->execute([$req,$id]);$this->advanceLocked($id);$this->pdo->commit();}catch(Throwable $e){$this->rollback();throw $e;}return $this->job($id,$owner);
    }

    public function failWork(string $id,array $owner,string $phase,string $username,string $token,string $message): array {
        $this->owned($id,$owner);$phase=$phase==='discovery'?'discovery':($phase==='enrichment'?'enrichment':'');$u=self::normalizeUsername($username);if($phase===''||$u==='')throw new ApiException('Invalid failed-work payload.',400,'INVALID_FAILURE');$this->pdo->beginTransaction();try{$row=$this->lockPlayer($id,$u);$state=(string)$row[$phase.'_state'];if(in_array($state,['done','failed'],true)){$this->pdo->commit();return $this->job($id,$owner);}if($state!=='in_progress'||!hash_equals((string)$row['lease_token'],$token))throw new ApiException('Work lease expired.',409,'LEASE_EXPIRED');$attempts=(int)$row[$phase.'_attempts'];$terminal=$attempts>=4;$next=$terminal?'failed':'pending';$msg=self::cut(trim($message)?:'Request failed',255);$this->pdo->prepare("UPDATE p2k_pd_players SET {$phase}_state=?,{$phase}_error=?,lease_token=NULL,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE job_id=? AND username_key=?")->execute([$next,$msg,$id,$u]);$counter=$phase==='discovery'?'discovery_done':'enrichment_done';$sql='UPDATE p2k_pd_jobs SET http_errors=http_errors+1'.($terminal?",{$counter}={$counter}+1":'').',updated_at=UTC_TIMESTAMP() WHERE job_id=?';$this->pdo->prepare($sql)->execute([$id]);$this->advanceLocked($id);$this->pdo->commit();}catch(Throwable $e){$this->rollback();throw $e;}return $this->job($id,$owner);
    }

    public function job(string $id,array $owner): array {return $this->present($this->owned($id,$owner));}
    public function listJobs(array $owner): array {[$where,$params]=$this->ownerWhere($owner);$q=$this->pdo->prepare("SELECT * FROM p2k_pd_jobs WHERE {$where} ORDER BY created_at DESC LIMIT 100");$q->execute($params);return array_map(fn($r)=>$this->present($r),$q->fetchAll(PDO::FETCH_ASSOC)?:[]);}

    public function results(string $id,array $owner,array $args): array {
        $this->owned($id,$owner);$limit=max(1,min(250,(int)($args['limit']??100)));$offset=max(0,(int)($args['offset']??0));$where=['job_id=?','duplicate_of IS NULL'];$params=[$id];$query=trim((string)($args['query']??''));if($query!==''){$where[]='(username_key LIKE ? OR canonical_username LIKE ? OR display_name LIKE ?)';$like='%'.str_replace(['%','_'],['\\%','\\_'],$query).'%';array_push($params,$like,$like,$like);}switch((string)($args['source']??'')){case 'seed':$where[]='is_seed=1';break;case 'new':$where[]='is_seed=0 AND is_discovered=1';break;case 'discovered':$where[]='is_discovered=1';break;}$sort=match((string)($args['sort']??'')){'rating-desc'=>'daily_rating IS NULL,daily_rating DESC','last-online-desc'=>'last_online_epoch IS NULL,last_online_epoch DESC','clubs-desc'=>'club_count IS NULL,club_count DESC','hits-desc'=>'discovery_hits DESC',default=>'COALESCE(canonical_username,username_display) ASC'};$sql=implode(' AND ',$where);$c=$this->pdo->prepare("SELECT COUNT(*) FROM p2k_pd_players WHERE {$sql}");$c->execute($params);$total=(int)$c->fetchColumn();$q=$this->pdo->prepare("SELECT * FROM p2k_pd_players WHERE {$sql} ORDER BY {$sort},username_key ASC LIMIT {$limit} OFFSET {$offset}");$q->execute($params);return ['rows'=>$q->fetchAll(PDO::FETCH_ASSOC)?:[],'total'=>$total,'offset'=>$offset,'limit'=>$limit];
    }

    public function csvRows(string $id,array $owner): Generator {
        $this->owned($id,$owner);$q=$this->pdo->prepare("SELECT username_display,canonical_username,is_seed,is_discovered,discovery_hits,player_id,profile_status,title,display_name,location,country_code,joined_epoch,last_online_epoch,followers,fide,daily_rating,daily_rd,daily_wins,daily_draws,daily_losses,daily_timeout_percent,daily_time_per_move,daily_best_rating,daily_last_game_epoch,club_count,active_clubs_30d,active_clubs_90d,enrichment_state,enrichment_error FROM p2k_pd_players WHERE job_id=? AND duplicate_of IS NULL ORDER BY COALESCE(canonical_username,username_display)");$q->execute([$id]);while($r=$q->fetch(PDO::FETCH_ASSOC))yield $r;
    }

    public function deleteJob(string $id,array $owner): void {$this->owned($id,$owner);$this->pdo->beginTransaction();try{$this->pdo->prepare('DELETE FROM p2k_pd_players WHERE job_id=?')->execute([$id]);$this->pdo->prepare('DELETE FROM p2k_pd_jobs WHERE job_id=?')->execute([$id]);$this->pdo->commit();}catch(Throwable $e){$this->rollback();throw $e;}}

    private function advanceLocked(string $id): void {
        $q=$this->pdo->prepare('SELECT * FROM p2k_pd_jobs WHERE job_id=? FOR UPDATE');$q->execute([$id]);$j=$q->fetch(PDO::FETCH_ASSOC);if(!$j)return;
        if($j['phase']==='discovery'&&(int)$j['discovery_done']>=(int)$j['seed_total']){$n=$this->count($id,'duplicate_of IS NULL');$this->pdo->prepare("UPDATE p2k_pd_jobs SET phase='enrichment',player_total=?,updated_at=UTC_TIMESTAMP() WHERE job_id=?")->execute([$n,$id]);$j['phase']='enrichment';$j['player_total']=$n;}
        if($j['phase']==='enrichment'&&(int)$j['enrichment_done']>=(int)$j['player_total'])$this->pdo->prepare("UPDATE p2k_pd_jobs SET state='complete',phase='complete',completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE job_id=?")->execute([$id]);
    }

    private function mergeDuplicate(string $id,string $source,?int $pid,string $canon): void {
        $parts=[];$params=[$id,$source];if($pid!==null){$parts[]='player_id=?';$params[]=$pid;}if($canon!==''){$parts[]='username_key=?';$params[]=$canon;$parts[]='canonical_username=?';$params[]=$canon;}if(!$parts)return;
        $q=$this->pdo->prepare('SELECT username_key FROM p2k_pd_players WHERE job_id=? AND username_key<>? AND duplicate_of IS NULL AND ('.implode(' OR ',$parts).') ORDER BY is_seed DESC,username_key LIMIT 1 FOR UPDATE');$q->execute($params);$target=(string)($q->fetchColumn()?:'');if($target==='')return;
        $src=$this->lockPlayer($id,$source);$this->pdo->prepare('UPDATE p2k_pd_players SET is_seed=GREATEST(is_seed,?),is_discovered=GREATEST(is_discovered,?),discovery_hits=discovery_hits+?,first_discovered_by=COALESCE(first_discovered_by,?),updated_at=UTC_TIMESTAMP() WHERE job_id=? AND username_key=?')->execute([(int)$src['is_seed'],(int)$src['is_discovered'],(int)$src['discovery_hits'],$src['first_discovered_by'],$id,$target]);$this->pdo->prepare('UPDATE p2k_pd_players SET duplicate_of=?,updated_at=UTC_TIMESTAMP() WHERE job_id=? AND username_key=?')->execute([$target,$id,$source]);
    }

    private function clubStats(array $payload): array {$clubs=is_array($payload['clubs']??null)?$payload['clubs']:[];$now=time();$a30=0;$a90=0;foreach($clubs as $c){$t=(int)($c['last_activity']??0);if($t>=$now-2592000)$a30++;if($t>=$now-7776000)$a90++;}return [count($clubs),$a30,$a90];}
    private function lockPlayer(string $id,string $u): array {$q=$this->pdo->prepare('SELECT * FROM p2k_pd_players WHERE job_id=? AND username_key=? FOR UPDATE');$q->execute([$id,$u]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new ApiException('Player work item not found.',404,'PLAYER_NOT_FOUND');return $r;}
    private function count(string $id,string $predicate): int {$q=$this->pdo->prepare("SELECT COUNT(*) FROM p2k_pd_players WHERE job_id=? AND {$predicate}");$q->execute([$id]);return (int)$q->fetchColumn();}
    private function rollback(): void {if($this->pdo->inTransaction())$this->pdo->rollBack();}
    private function clientId(string $v): string {$v=strtolower(trim($v));return preg_match('/^[a-f0-9]{16,64}$/D',$v)===1?$v:'';}

    private function assertState(string $id,array $owner,array $states): array {$j=$this->owned($id,$owner);if(!in_array($j['state'],$states,true))throw new ApiException('Job is not in a compatible state.',409,'JOB_STATE');return $j;}
    private function owned(string $id,array $owner): array {if(!preg_match('/^[a-f0-9-]{36}$/D',$id))throw new ApiException('Invalid job id.',400,'INVALID_JOB_ID');[$w,$p]=$this->ownerWhere($owner);$q=$this->pdo->prepare("SELECT * FROM p2k_pd_jobs WHERE job_id=? AND ({$w}) LIMIT 1");$q->execute(array_merge([$id],$p));$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new ApiException('Job not found.',404,'JOB_NOT_FOUND');return $r;}
    private function ownerWhere(array $owner): array {$u=self::normalizeUsername((string)($owner['username']??''));$pid=(int)($owner['player_id']??0);return $pid>0?['owner_player_id=? OR (owner_player_id IS NULL AND owner_username=?)',[$pid,$u]]:['owner_player_id IS NULL AND owner_username=?',[$u]];}

    private function present(array $r): array {$keys=json_decode((string)($r['month_keys_json']??'[]'),true);if(!is_array($keys))$keys=[];return ['job_id'=>(string)$r['job_id'],'state'=>(string)$r['state'],'phase'=>(string)$r['phase'],'months'=>(int)$r['months'],'daily_mode'=>(string)$r['daily_mode'],'include_chess960'=>(bool)$r['include_chess960'],'max_concurrency'=>(int)$r['max_concurrency'],'cutoff_epoch'=>(int)$r['cutoff_epoch'],'scan_end_epoch'=>(int)$r['scan_end_epoch'],'month_keys'=>array_values($keys),'seed_total'=>(int)$r['seed_total'],'player_total'=>(int)$r['player_total'],'discovery_done'=>(int)$r['discovery_done'],'enrichment_done'=>(int)$r['enrichment_done'],'archive_requests_done'=>(int)$r['archive_requests_done'],'enrichment_requests_done'=>(int)$r['enrichment_requests_done'],'daily_games_seen'=>(int)$r['daily_games_seen'],'opponent_edges_seen'=>(int)$r['opponent_edges_seen'],'http_errors'=>(int)$r['http_errors'],'created_at'=>$r['created_at'],'started_at'=>$r['started_at'],'updated_at'=>$r['updated_at'],'completed_at'=>$r['completed_at']];}

    private function ensureSchema(): void {
        $runtime='';try{$runtime=rtrim((string)(p2k_tp_config()['storage']['runtime_dir']??''),'/\\');}catch(Throwable){}if($runtime==='')$runtime=dirname(__DIR__).'/data/runtime-v280';$dir=$runtime.'/player-discovery';$marker=$dir.'/schema-v'.self::SCHEMA_VERSION.'.ok';if(is_file($marker))return;if(!is_dir($dir))@mkdir($dir,0700,true);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS p2k_pd_jobs(job_id CHAR(36) NOT NULL PRIMARY KEY,owner_player_id BIGINT NULL,owner_username VARCHAR(80) NOT NULL,state VARCHAR(24) NOT NULL,phase VARCHAR(24) NOT NULL,months SMALLINT NOT NULL,daily_mode VARCHAR(16) NOT NULL,include_chess960 TINYINT(1) NOT NULL DEFAULT 0,max_concurrency SMALLINT NOT NULL DEFAULT 24,cutoff_epoch BIGINT NOT NULL,scan_end_epoch BIGINT NOT NULL,month_keys_json TEXT NOT NULL,seed_total INT NOT NULL DEFAULT 0,player_total INT NOT NULL DEFAULT 0,discovery_done INT NOT NULL DEFAULT 0,enrichment_done INT NOT NULL DEFAULT 0,archive_requests_done BIGINT NOT NULL DEFAULT 0,enrichment_requests_done BIGINT NOT NULL DEFAULT 0,daily_games_seen BIGINT NOT NULL DEFAULT 0,opponent_edges_seen BIGINT NOT NULL DEFAULT 0,http_errors BIGINT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,started_at DATETIME NULL,updated_at DATETIME NOT NULL,completed_at DATETIME NULL,KEY idx_p2k_pd_jobs_owner_player(owner_player_id,created_at),KEY idx_p2k_pd_jobs_owner_name(owner_username,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS p2k_pd_players(job_id CHAR(36) NOT NULL,username_key VARCHAR(80) NOT NULL,username_display VARCHAR(80) NOT NULL,is_seed TINYINT(1) NOT NULL DEFAULT 0,is_discovered TINYINT(1) NOT NULL DEFAULT 0,discovery_hits INT NOT NULL DEFAULT 0,first_discovered_by VARCHAR(80) NULL,discovery_state VARCHAR(24) NOT NULL DEFAULT 'not_applicable',discovery_attempts SMALLINT NOT NULL DEFAULT 0,discovery_error VARCHAR(255) NULL,enrichment_state VARCHAR(24) NOT NULL DEFAULT 'pending',enrichment_attempts SMALLINT NOT NULL DEFAULT 0,enrichment_error VARCHAR(255) NULL,lease_token VARCHAR(64) NULL,lease_owner VARCHAR(64) NULL,lease_until DATETIME NULL,duplicate_of VARCHAR(80) NULL,player_id BIGINT NULL,canonical_username VARCHAR(80) NULL,profile_status VARCHAR(40) NULL,title VARCHAR(10) NULL,display_name VARCHAR(160) NULL,location VARCHAR(160) NULL,country_code VARCHAR(8) NULL,joined_epoch BIGINT NULL,last_online_epoch BIGINT NULL,followers INT NULL,fide INT NULL,avatar_url VARCHAR(500) NULL,profile_url VARCHAR(500) NULL,daily_rating INT NULL,daily_rd INT NULL,daily_wins INT NULL,daily_losses INT NULL,daily_draws INT NULL,daily_timeout_percent DECIMAL(8,3) NULL,daily_time_per_move INT NULL,daily_best_rating INT NULL,daily_last_game_epoch BIGINT NULL,club_count INT NULL,active_clubs_30d INT NULL,active_clubs_90d INT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(job_id,username_key),KEY idx_p2k_pd_discovery(job_id,is_seed,discovery_state,lease_until),KEY idx_p2k_pd_enrichment(job_id,enrichment_state,lease_until),KEY idx_p2k_pd_player_id(job_id,player_id),KEY idx_p2k_pd_duplicate(job_id,duplicate_of),KEY idx_p2k_pd_rating(job_id,daily_rating),KEY idx_p2k_pd_online(job_id,last_online_epoch)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");@file_put_contents($marker,'schema='.self::SCHEMA_VERSION."\n",LOCK_EX);@chmod($marker,0600);
    }

    private static function intOrNull(mixed $v): ?int {if($v===null||$v==='')return null;$n=filter_var($v,FILTER_VALIDATE_INT);return $n===false?null:(int)$n;}
    private static function floatOrNull(mixed $v): ?float {return $v===null||$v===''||!is_numeric($v)?null:(float)$v;}
    private static function cut(string $v,int $n): string {return function_exists('mb_substr')?mb_substr($v,0,$n):substr($v,0,$n);}
    private static function text(mixed $v,int $n): ?string {$s=trim((string)$v);return $s===''?null:self::cut($s,$n);}
    private static function url(mixed $v): ?string {$s=trim((string)$v);return preg_match('~^https://~i',$s)===1?self::cut($s,500):null;}
    private static function country(string $v): ?string {return preg_match('~/country/([A-Za-z]{2})/?$~',$v,$m)?strtoupper($m[1]):null;}
}
