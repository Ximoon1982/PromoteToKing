<?php
declare(strict_types=1);
namespace P2K\Green;

use PDO;
use P2K\TeamPoints\Database;
use RuntimeException;

/** Dedicated, resumable Green Analytics Bootstrap (GAB). */
final class GreenAnalyticsBootstrap
{
    private const LANES=[
        ['compat_schema','Compatibility schema',10],
        ['reference_core','Opponent + identity reference history',20],
        ['live_ranks','MCA / Live Ranks raw history',30],
        ['achievement_history','Achievement unlock history',40],
        ['core_projection_members','Green member projection',50],
        ['core_projection_matches','Green match/board/game projection',60],
        ['compat_reconciliation','Green compatibility reconciliation (GABCRF)',65],
        ['analytics_build','Green analytics/read-model build',70],
        ['opponent_enrichment','Missing opponent profile enrichment',80],
        ['read_parity','Public read compatibility validation',90],
    ];
    private GreenCompatibility $compat;
    public function __construct(private GreenRepository $green){$this->compat=new GreenCompatibility($green);$this->ensureStateTables();}

    private function isTransientSerializationFailure(\Throwable $e): bool
    {
        $code=(string)$e->getCode();$message=strtolower($e->getMessage());
        if($code==='40001')return true;
        if($e instanceof \PDOException){$info=$e->errorInfo??null;if(is_array($info)&&((string)($info[0]??'')==='40001'||(int)($info[1]??0)===1213))return true;}
        return str_contains($message,'deadlock found')||str_contains($message,'serialization failure')||str_contains($message,'try restarting transaction');
    }
    private function storedGabErrorIsTransient(): bool
    {
        $s=$this->green->state();$message=strtolower((string)($s['gab_last_error']??''));
        if((string)($s['gab_status']??'')!=='error')return false;
        return str_contains($message,'sqlstate[40001]')||str_contains($message,'deadlock found')||str_contains($message,'serialization failure')||str_contains($message,'1213');
    }
    public function resumeTransientErrorIfSafe(): bool
    {
        if(!$this->storedGabErrorIsTransient())return false;
        $q=$this->green->core->query("SELECT lane_key,last_error FROM p2k_g_gab_lanes WHERE status='error' ORDER BY priority LIMIT 1");$lane=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($lane)||(string)($lane['lane_key']??'')!=='compat_reconciliation')return false;
        $message=strtolower((string)($lane['last_error']??''));if($message!==''&&!str_contains($message,'40001')&&!str_contains($message,'deadlock')&&!str_contains($message,'serialization')&&!str_contains($message,'1213'))return false;
        $this->green->core->prepare("UPDATE p2k_g_gab_lanes SET status='running',error_rows=0,last_error=NULL,completed_at=NULL,updated_at=UTC_TIMESTAMP() WHERE lane_key='compat_reconciliation' AND status='error'")->execute();
        $this->green->core->prepare("UPDATE p2k_g_state SET gab_status='running',gab_phase='compat_reconciliation',gab_last_error=NULL,gab_completed_at=NULL WHERE club_slug=? AND gab_status='error'")->execute([$this->green->clubSlug]);
        return true;
    }

    private function ensureStateTables(): void
    {
        $this->green->core->exec("CREATE TABLE IF NOT EXISTS p2k_g_gab_lanes(lane_key VARCHAR(64) NOT NULL PRIMARY KEY,label VARCHAR(160) NOT NULL,priority INT NOT NULL,status ENUM('pending','running','completed','error') NOT NULL DEFAULT 'pending',total_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,processed_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,changed_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,error_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,cursor_json MEDIUMTEXT NULL,started_at DATETIME NULL,completed_at DATETIME NULL,last_error TEXT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_g_gab_status(status,priority)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->green->core->exec("CREATE TABLE IF NOT EXISTS p2k_g_gab_external_work(work_key VARCHAR(190) NOT NULL PRIMARY KEY,kind VARCHAR(48) NOT NULL,url VARCHAR(500) NOT NULL,status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',attempts INT UNSIGNED NOT NULL DEFAULT 0,retry_after DATETIME NULL,last_http_status INT NULL,last_error TEXT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_g_gab_external(status,retry_after,kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    private function tableCount(PDO $pdo,string $table): int
    {
        if(!$this->tableExists($pdo,$table))return 0;return (int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
    }
    private function setLaneTotal(string $key,int $total): void
    {
        $this->green->core->prepare('UPDATE p2k_g_gab_lanes SET total_rows=?,updated_at=UTC_TIMESTAMP() WHERE lane_key=?')->execute([max(0,$total),$key]);
    }
    private function primeTotals(): void
    {
        $this->setLaneTotal('compat_schema',1);
        $n=0;foreach(['p2k_tp_opponents','p2k_tp_opponent_aliases','p2k_miac_names','p2k_miac_edges','p2k_miac_canonical_map','p2k_miac_state'] as $t)$n+=$this->tableCount(Database::core(),$t);$this->setLaneTotal('reference_core',$n);
        $n=0;foreach(['p2k_lr_files','p2k_lr_source_rows','p2k_lr_attributions','p2k_lr_players','p2k_lr_arena_stats','p2k_lr_processing_state','p2k_lr_sync_state','p2k_lr_sync_queue'] as $t)$n+=$this->tableCount(Database::analytics(),$t);$this->setLaneTotal('live_ranks',$n);
        $this->setLaneTotal('achievement_history',$this->tableCount(Database::analytics(),'p2k_an_achievement_unlocks'));
        $this->setLaneTotal('core_projection_members',(int)$this->green->core->query('SELECT COUNT(*) FROM p2k_g_players')->fetchColumn());
        $matchTotal=(int)$this->green->core->query("SELECT COUNT(*) FROM p2k_g_matches WHERE club_verified=1 AND time_class='daily'")->fetchColumn();
        $this->setLaneTotal('core_projection_matches',$matchTotal);
        $this->setLaneTotal('compat_reconciliation',$matchTotal);
        $this->setLaneTotal('analytics_build',1);
        // Read-parity emits a dynamic number of checks (sample-dependent). Do not
        // prime it with a stale hard-coded denominator. Pending/running audits
        // establish their exact total from smokeTest() immediately before use.
        $this->green->core->exec("UPDATE p2k_g_gab_lanes SET total_rows=CASE WHEN status='completed' THEN GREATEST(total_rows,processed_rows) ELSE 0 END,processed_rows=CASE WHEN status='completed' THEN processed_rows ELSE 0 END,changed_rows=CASE WHEN status='completed' THEN changed_rows ELSE 0 END,error_rows=CASE WHEN status='completed' THEN error_rows ELSE 0 END WHERE lane_key='read_parity'");
    }
    public function start(bool $restart=false): array
    {
        if($restart){$this->green->core->exec('DELETE FROM p2k_g_gab_lanes');$this->green->core->exec("DELETE FROM p2k_g_gab_external_work WHERE kind='gab_opponent_profile'");}
        else{
            // GABCRF is a convergence scan. Resuming it must preserve its cursor/pass/counters;
            // only a deliberate restart is allowed to discard that bookkeeping.
            $this->green->core->exec("UPDATE p2k_g_gab_lanes SET status='pending',error_rows=0,last_error=NULL,completed_at=NULL WHERE status='error' AND lane_key='compat_reconciliation'");
            $this->green->core->exec("UPDATE p2k_g_gab_lanes SET status='pending',processed_rows=0,changed_rows=0,error_rows=0,cursor_json=NULL,last_error=NULL,completed_at=NULL WHERE status='error' AND lane_key<>'compat_reconciliation'");
            $this->green->core->exec("UPDATE p2k_g_gab_external_work SET status='pending',attempts=0,retry_after=NULL,last_error=NULL WHERE kind='gab_opponent_profile' AND status='failed'");
        }
        $ins=$this->green->core->prepare("INSERT IGNORE INTO p2k_g_gab_lanes(lane_key,label,priority,status) VALUES(?,?,?,'pending')");foreach(self::LANES as [$k,$l,$p])$ins->execute([$k,$l,$p]);
        $this->primeTotals();
        $this->green->core->prepare("UPDATE p2k_g_state SET gab_status='running',gab_phase=COALESCE((SELECT lane_key FROM p2k_g_gab_lanes WHERE status<>'completed' ORDER BY priority LIMIT 1),'read_parity'),gab_started_at=COALESCE(gab_started_at,UTC_TIMESTAMP()),gab_completed_at=NULL,gab_last_error=NULL WHERE club_slug=?")->execute([$this->green->clubSlug]);
        return $this->status();
    }
    public function status(): array
    {
        $rows=$this->green->core->query('SELECT * FROM p2k_g_gab_lanes ORDER BY priority')->fetchAll(PDO::FETCH_ASSOC)?:[];$s=$this->green->state();
        $allCompleted=count($rows)===count(self::LANES)&&!array_filter($rows,static fn(array $r): bool => (string)($r['status']??'')!=='completed');
        // A slice can finish the final lane exactly on its deadline and previously leave
        // gab_status='running' until the next invocation. Self-heal that harmless transient
        // immediately; conversely never advertise ready while any lane is incomplete.
        if($allCompleted&&(string)($s['gab_status']??'')!=='ready'){
            $this->green->core->prepare("UPDATE p2k_g_state SET gab_status='ready',gab_phase='complete',gab_completed_at=COALESCE(gab_completed_at,UTC_TIMESTAMP()),gab_last_error=NULL WHERE club_slug=?")->execute([$this->green->clubSlug]);$s=$this->green->state();
        }elseif(!$allCompleted&&(string)($s['gab_status']??'')==='ready'){
            $first=null;foreach($rows as $r){if((string)($r['status']??'')!=='completed'){$first=(string)$r['lane_key'];break;}}
            $this->green->core->prepare("UPDATE p2k_g_state SET gab_status='running',gab_phase=?,gab_completed_at=NULL WHERE club_slug=?")->execute([$first?:'not_started',$this->green->clubSlug]);$s=$this->green->state();
        }
        $fractions=[];$completedLanes=0;$progressNumerator=0;$progressDenominator=0;$lastRealProgressAt=null;
        foreach($rows as &$r){
            $status=(string)($r['status']??'pending');$total=(int)($r['total_rows']??0);$done=(int)($r['processed_rows']??0);$key=(string)($r['lane_key']??'');
            if($status==='completed'){$completedLanes++;$fraction=1.0;}
            elseif($key==='compat_reconciliation'){$fraction=$status==='running'?0.99:0.0;}
            else{$fraction=$total>0?min(0.99,max(0.0,$done/$total)):0.0;}
            $fractions[]=$fraction;
            if($key==='compat_reconciliation'){
                $cursor=json_decode((string)($r['cursor_json']??''),true);if(!is_array($cursor))$cursor=[];
                $r['progress_mode']='convergence';$r['attempted_rows']=$done;$r['last_full_pass_remaining']=array_key_exists('last_full_pass_remaining',$cursor)?(int)$cursor['last_full_pass_remaining']:null;$r['pass_no']=max(1,(int)($cursor['pass_no']??1));
            }elseif($key==='read_parity'){$r['progress_mode']='audit';$r['attempted_rows']=$done;$r['display_total_rows']=$status==='pending'?0:$total;}
            else{$r['progress_mode']='finite';$r['attempted_rows']=$done;}
            if($status==='pending')$r['display_processed_rows']=0;else $r['display_processed_rows']=$status==='completed'&&$total>0?min($done,$total):$done;
            if($total>0){$progressDenominator+=$total;if($status==='completed')$progressNumerator+=$total;elseif($key==='compat_reconciliation'&&isset($r['last_full_pass_remaining']))$progressNumerator+=max(0,min($total,$total-(int)$r['last_full_pass_remaining']));else $progressNumerator+=max(0,min($total,$done));}
            if(($done>0||(int)($r['changed_rows']??0)>0||$status==='completed')&&!empty($r['updated_at'])&&($lastRealProgressAt===null||strcmp((string)$r['updated_at'],$lastRealProgressAt)>0))$lastRealProgressAt=(string)$r['updated_at'];
        }unset($r);
        $percent=$fractions?round(100*array_sum($fractions)/count($fractions),2):0.0;
        if((string)($s['gab_status']??'')==='ready'&&$allCompleted)$percent=100.0;elseif($percent>=100.0)$percent=99.9;
        $ext=$this->green->core->query("SELECT COUNT(*) total,COALESCE(SUM(status='completed'),0) completed,COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='failed'),0) failed,COALESCE(SUM(status='completed' AND last_http_status IN (404,410)),0) terminal_retired,COALESCE(SUM(status='pending' AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP())),0) currently_due,MIN(CASE WHEN status IN ('pending','failed') THEN updated_at END) oldest_unresolved FROM p2k_g_gab_external_work WHERE kind='gab_opponent_profile'")->fetch(PDO::FETCH_ASSOC)?:[];
        $convergence=['progress_numerator'=>$progressNumerator,'progress_denominator'=>$progressDenominator,'total_obligations'=>$progressDenominator,'completed'=>$progressNumerator,'unresolved'=>max(0,$progressDenominator-$progressNumerator),'retryable'=>(int)($ext['pending']??0),'terminal_retired'=>(int)($ext['terminal_retired']??0),'currently_due'=>(int)($ext['currently_due']??0),'oldest_unresolved'=>$ext['oldest_unresolved']??null,'last_real_progress_at'=>$lastRealProgressAt,'display_percent_is_lane_weighted'=>true];
        return ['status'=>(string)($s['gab_status']??'not_started'),'phase'=>(string)($s['gab_phase']??'not_started'),'started_at'=>$s['gab_started_at']??null,'completed_at'=>$s['gab_completed_at']??null,'last_error'=>$s['gab_last_error']??null,'percent'=>$percent,'completed_lanes'=>$completedLanes,'total_lanes'=>count($rows),'convergence'=>$convergence,'lanes'=>$rows,'external'=>$ext];
    }
    private function lane(): ?array {$q=$this->green->core->query("SELECT * FROM p2k_g_gab_lanes WHERE status<>'completed' ORDER BY priority LIMIT 1");$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
    private function setLane(string $key,string $status,array $extra=[]): void
    {
        $sets=['status=?'];$params=[$status];if($status==='running')$sets[]='started_at=COALESCE(started_at,UTC_TIMESTAMP())';if($status==='completed')$sets[]='completed_at=UTC_TIMESTAMP()';foreach(['total_rows','processed_rows','changed_rows','error_rows','cursor_json','last_error'] as $f)if(array_key_exists($f,$extra)){$sets[]="$f=?";$params[]=$extra[$f];}$params[]=$key;$this->green->core->prepare('UPDATE p2k_g_gab_lanes SET '.implode(',',$sets).',updated_at=UTC_TIMESTAMP() WHERE lane_key=?')->execute($params);
        $this->green->core->prepare('UPDATE p2k_g_state SET gab_phase=? WHERE club_slug=?')->execute([$key,$this->green->clubSlug]);
    }
    private function tableExists(PDO $pdo,string $table): bool {$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return (int)$q->fetchColumn()>0;}
    private function primaryKeyColumns(PDO $pdo,string $table): array
    {
        // MariaDB SHOW KEYS supports WHERE but not ORDER BY. Sort the returned
        // PRIMARY-key rows in PHP to keep this portable across IONOS MariaDB
        // versions while preserving deterministic OFFSET pagination.
        $rows=$pdo->query("SHOW KEYS FROM `{$table}` WHERE Key_name='PRIMARY'")->fetchAll(PDO::FETCH_ASSOC)?:[];
        usort($rows,static fn(array $a,array $b): int => (int)($a['Seq_in_index']??0) <=> (int)($b['Seq_in_index']??0));
        return array_values(array_filter(array_map(static fn(array $r)=>(string)($r['Column_name']??''),$rows),static fn(string $c)=>$c!==''));
    }
    private function copyTableBatch(PDO $source,PDO $target,string $table,int $offset,int $limit): array
    {
        if(!$this->tableExists($source,$table)||!$this->tableExists($target,$table))return ['processed'=>0,'changed'=>0,'drained'=>true];
        $cols=array_map(fn($r)=>(string)$r['Field'],$source->query('DESCRIBE `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)?:[]);$tcols=array_flip(array_map(fn($r)=>(string)$r['Field'],$target->query('DESCRIBE `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)?:[]));$cols=array_values(array_filter($cols,fn($c)=>isset($tcols[$c])));if(!$cols)return ['processed'=>0,'changed'=>0,'drained'=>true];
        $pk=$this->primaryKeyColumns($source,$table);$order=$pk?' ORDER BY '.implode(',',array_map(fn($c)=>'`'.$c.'`',$pk)):'';$sql='SELECT '.implode(',',array_map(fn($c)=>'`'.$c.'`',$cols))." FROM `{$table}`{$order} LIMIT ".(int)$limit.' OFFSET '.(int)$offset;$rows=$source->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];if(!$rows)return ['processed'=>0,'changed'=>0,'drained'=>true];
        $names=implode(',',array_map(fn($c)=>'`'.$c.'`',$cols));$ph=implode(',',array_fill(0,count($cols),'?'));$upd=implode(',',array_map(fn($c)=>'`'.$c.'`=VALUES(`'.$c.'`)',$cols));$ins=$target->prepare("INSERT INTO `{$table}`({$names}) VALUES({$ph}) ON DUPLICATE KEY UPDATE {$upd}");$changed=0;foreach($rows as $r){$ins->execute(array_map(fn($c)=>$r[$c]??null,$cols));$changed+=$ins->rowCount()>0?1:0;}return ['processed'=>count($rows),'changed'=>$changed,'drained'=>count($rows)<$limit];
    }
    private function copyLane(string $key,array $tables,PDO $source,PDO $target,int $limit): array
    {
        $q=$this->green->core->prepare('SELECT cursor_json,processed_rows,changed_rows FROM p2k_g_gab_lanes WHERE lane_key=?');$q->execute([$key]);$row=$q->fetch(PDO::FETCH_ASSOC)?:[];$cursor=json_decode((string)($row['cursor_json']??''),true);if(!is_array($cursor))$cursor=['table'=>0,'offset'=>0];$ti=(int)($cursor['table']??0);$offset=(int)($cursor['offset']??0);$processed=(int)($row['processed_rows']??0);$changed=(int)($row['changed_rows']??0);
        if($ti>=count($tables)){return ['done'=>true];}$table=$tables[$ti];$r=$this->copyTableBatch($source,$target,$table,$offset,$limit);$processed+=(int)$r['processed'];$changed+=(int)$r['changed'];if($r['drained']){$ti++;$offset=0;}else{$offset+=(int)$r['processed'];}$done=$ti>=count($tables);$this->setLane($key,$done?'completed':'running',['processed_rows'=>$processed,'changed_rows'=>$changed,'cursor_json'=>json_encode(['table'=>$ti,'offset'=>$offset,'table_name'=>$tables[$ti]??null])]);return ['done'=>$done,'table'=>$table]+$r;
    }
    public function runSlice(float $seconds=8.0): array
    {
        $s=$this->green->state();if((string)($s['gab_status']??'not_started')!=='running')return ['ran'=>false,'reason'=>'gab_not_running','gab'=>$this->status()];$deadline=microtime(true)+max(1,min(20,$seconds));$actions=[];
        try{while(microtime(true)<$deadline){$lane=$this->lane();if(!$lane){$this->green->core->prepare("UPDATE p2k_g_state SET gab_status='ready',gab_phase='complete',gab_completed_at=UTC_TIMESTAMP(),gab_last_error=NULL WHERE club_slug=?")->execute([$this->green->clubSlug]);break;}$key=(string)$lane['lane_key'];$this->setLane($key,'running');
            if($key==='compat_schema'){$r=$this->compat->ensureSchema();$this->setLane($key,'completed',['processed_rows'=>1,'changed_rows'=>1,'cursor_json'=>json_encode($r)]);$actions[]=$r;continue;}
            if($key==='reference_core'){$r=$this->copyLane($key,['p2k_tp_opponents','p2k_tp_opponent_aliases','p2k_miac_names','p2k_miac_edges','p2k_miac_canonical_map','p2k_miac_state'],Database::core(),$this->green->core,1000);$actions[]=$r;if(!$r['done'])break;continue;}
            if($key==='live_ranks'){$r=$this->copyLane($key,['p2k_lr_files','p2k_lr_source_rows','p2k_lr_attributions','p2k_lr_players','p2k_lr_arena_stats','p2k_lr_processing_state','p2k_lr_sync_state','p2k_lr_sync_queue'],Database::analytics(),$this->green->analytics,1000);$actions[]=$r;if(!$r['done'])break;continue;}
            if($key==='achievement_history'){$r=$this->copyLane($key,['p2k_an_achievement_unlocks'],Database::analytics(),$this->green->analytics,1000);$actions[]=$r;if(!$r['done'])break;continue;}
            if($key==='core_projection_members'){$n=$this->compat->projectMembers();$this->setLane($key,'completed',['processed_rows'=>$n,'changed_rows'=>$n]);$actions[]=['members'=>$n];continue;}
            if($key==='core_projection_matches'){$cur=json_decode((string)($lane['cursor_json']??''),true);$after=(int)($cur['last_id']??0);$r=$this->compat->projectMatchBatch($after,25);$done=(bool)$r['drained'];$processed=(int)$lane['processed_rows']+(int)$r['processed'];$this->setLane($key,$done?'completed':'running',['processed_rows'=>$processed,'changed_rows'=>$processed,'cursor_json'=>json_encode(['last_id'=>(int)$r['last_id']])]);$actions[]=$r;if(!$done)break;continue;}
            if($key==='compat_reconciliation'){
                $cur=json_decode((string)($lane['cursor_json']??''),true);if(!is_array($cur))$cur=[];$after=(int)($cur['last_id']??0);$r=null;$transient=null;
                for($attempt=1;$attempt<=3;$attempt++){
                    try{$r=$this->compat->reconcileCompatibilityBatch($after,40);$transient=null;break;}
                    catch(\Throwable $e){if(!$this->isTransientSerializationFailure($e))throw $e;$transient=$e;if($attempt<3){usleep(50000*$attempt);continue;}}
                }
                if(!is_array($r)){
                    // Preserve cursor/pass/counters and yield. The next CRON slice retries the
                    // same authoritative batch; a normal InnoDB deadlock must never kill GAB.
                    $message='Transient GABCRF database contention; batch yielded for retry.';
                    $this->setLane($key,'running',['last_error'=>$message]);$actions[]=['gabcrf_transient_retry'=>true,'attempts'=>3,'error'=>$transient?->getMessage()];break;
                }
                $done=(bool)$r['drained'];$processed=(int)$lane['processed_rows']+(int)$r['processed'];$changed=(int)$lane['changed_rows']+(int)$r['changed'];$nextAfter=!empty($r['reset_cursor'])?0:(int)$r['last_id'];$passNo=max(1,(int)($cur['pass_no']??1));$knownRemaining=array_key_exists('last_full_pass_remaining',$cur)?(int)$cur['last_full_pass_remaining']:null;if(!empty($r['pass_drained'])){$knownRemaining=(int)$r['remaining'];if(!empty($r['reset_cursor']))$passNo++;}$cursor=['last_id'=>$nextAfter,'pass_no'=>$passNo];if($knownRemaining!==null)$cursor['last_full_pass_remaining']=$knownRemaining;$this->setLane($key,$done?'completed':'running',['processed_rows'=>$processed,'changed_rows'=>$changed,'cursor_json'=>json_encode($cursor),'last_error'=>null]);$actions[]=['gabcrf'=>$r];if(!$done)break;continue;
            }
            if($key==='analytics_build'){$r=$this->compat->rebuildAnalytics();$this->setLane($key,'completed',['processed_rows'=>1,'changed_rows'=>1,'cursor_json'=>json_encode($r)]);$actions[]=['analytics_build'=>'completed'];continue;}
            if($key==='opponent_enrichment'){$this->prepareExternalWork();$pending=(int)$this->green->core->query("SELECT COUNT(*) FROM p2k_g_gab_external_work WHERE kind='gab_opponent_profile' AND status='pending'")->fetchColumn();$failed=(int)$this->green->core->query("SELECT COUNT(*) FROM p2k_g_gab_external_work WHERE kind='gab_opponent_profile' AND status='failed'")->fetchColumn();$done=(int)$this->green->core->query("SELECT COUNT(*) FROM p2k_g_gab_external_work WHERE kind='gab_opponent_profile' AND status='completed'")->fetchColumn();$this->setLaneTotal($key,$pending+$failed+$done);if($pending>0){$this->setLane($key,'running',['processed_rows'=>$done,'error_rows'=>$failed]);break;}if($failed>0){$this->setLane($key,'error',['processed_rows'=>$done,'error_rows'=>$failed,'last_error'=>$failed.' opponent enrichment item(s) exhausted retries. Start/resume GAB to retry them.']);throw new RuntimeException('GAB opponent enrichment still has '.$failed.' failed item(s).');}$this->setLane($key,'completed',['processed_rows'=>$done,'error_rows'=>0]);continue;}
            if($key==='read_parity'){$r=$this->compat->smokeTest();$count=count($r['checks']??[]);$this->setLaneTotal($key,$count);if(!$r['ready']){
                $projectionFailed=false;foreach(['members_projection','matches_projection','boards_projection','games_projection'] as $pk){if(array_key_exists($pk,$r['checks']??[])&&empty($r['checks'][$pk])){$projectionFailed=true;break;}}
                if($projectionFailed){
                    $this->green->core->exec("UPDATE p2k_g_gab_lanes SET status='pending',processed_rows=0,changed_rows=0,error_rows=0,cursor_json=NULL,completed_at=NULL,last_error=NULL WHERE lane_key IN ('compat_reconciliation','analytics_build','read_parity')");
                    $this->green->core->prepare("UPDATE p2k_g_state SET gab_status='running',gab_phase='compat_reconciliation',gab_last_error=NULL WHERE club_slug=?")->execute([$this->green->clubSlug]);
                    $actions[]=['gabcrf_reopened'=>true,'parity'=>$r];break;
                }
                $this->setLane($key,'error',['processed_rows'=>count(array_filter($r['checks']??[])),'error_rows'=>count(array_filter($r['checks']??[],fn($v)=>!$v)),'last_error'=>json_encode($r)]);throw new RuntimeException('Green public read compatibility audit failed.');
            }$this->setLane($key,'completed',['processed_rows'=>$count,'changed_rows'=>$count,'cursor_json'=>json_encode($r)]);continue;}
            throw new RuntimeException('Unknown GAB lane '.$key);
        }}catch(\Throwable $e){$lane=$this->lane();if(is_array($lane)&&(string)($lane['lane_key']??'')==='compat_reconciliation'&&$this->isTransientSerializationFailure($e)){try{$this->setLane('compat_reconciliation','running',['last_error'=>'Transient GABCRF database contention; slice yielded for retry.']);}catch(\Throwable){}return ['ran'=>true,'ok'=>true,'transient_retry'=>true,'error'=>$e->getMessage(),'actions'=>$actions,'gab'=>$this->status()];}if($lane)$this->setLane((string)$lane['lane_key'],'error',['error_rows'=>(int)($lane['error_rows']??0)+1,'last_error'=>$e->getMessage()]);$this->green->core->prepare("UPDATE p2k_g_state SET gab_status='error',gab_last_error=? WHERE club_slug=?")->execute([substr($e->getMessage(),0,2000),$this->green->clubSlug]);return ['ran'=>true,'ok'=>false,'error'=>$e->getMessage(),'actions'=>$actions,'gab'=>$this->status()];}
        // Finalize immediately when the last lane completed at the end of this slice.
        if(!$this->lane())$this->green->core->prepare("UPDATE p2k_g_state SET gab_status='ready',gab_phase='complete',gab_completed_at=COALESCE(gab_completed_at,UTC_TIMESTAMP()),gab_last_error=NULL WHERE club_slug=?")->execute([$this->green->clubSlug]);
        return ['ran'=>true,'ok'=>true,'actions'=>$actions,'gab'=>$this->status()];
    }
    public function prepareExternalWork(): int
    {
        $this->compat->ensureSchema();$q=$this->green->core->prepare("INSERT IGNORE INTO p2k_g_gab_external_work(work_key,kind,url,status) SELECT CONCAT('opponent:',opponent_slug),'gab_opponent_profile',CONCAT('https://api.chess.com/pub/club/',opponent_slug),'pending' FROM p2k_tp_opponents WHERE club_slug=? AND disabled=0 AND (country_code IS NULL OR country_code='' OR icon_url IS NULL OR icon_url='')");$q->execute([$this->green->clubSlug]);return (int)$q->rowCount();
    }
    public function planExternal(int $limit=36,string $owner='browser-gab'): array
    {
        $this->prepareExternalWork();$limit=max(1,min(72,$limit));$q=$this->green->core->query("SELECT work_key,kind,url FROM p2k_g_gab_external_work WHERE kind='gab_opponent_profile' AND status='pending' AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP()) ORDER BY attempts,work_key LIMIT {$limit}");return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    public function ingestOpponent(string $url,array $payload,int $httpStatus=200): array
    {
        $path=(string)(parse_url($url,PHP_URL_PATH)?:'');if(!preg_match('~/pub/club/([^/]+)~i',$path,$m))throw new RuntimeException('Invalid GAB opponent URL.');$slug=strtolower(rawurldecode($m[1]));$key='opponent:'.$slug;
        if(in_array($httpStatus,[404,410],true)){$this->green->core->prepare("UPDATE p2k_tp_opponents SET disabled=1,last_checked_at=UTC_TIMESTAMP(),last_error=? WHERE club_slug=? AND opponent_slug=?")->execute(['HTTP '.$httpStatus,$this->green->clubSlug,$slug]);$this->green->core->prepare("UPDATE p2k_g_gab_external_work SET status='completed',attempts=attempts+1,last_http_status=?,last_error=NULL WHERE work_key=?")->execute([$httpStatus,$key]);return ['changed'=>1,'terminal'=>$httpStatus];}
        $country=(string)($payload['country']??'');if($country!=='')$country=strtoupper((string)basename((string)parse_url($country,PHP_URL_PATH)));$icon=(string)($payload['icon']??$payload['image']??'');$name=(string)($payload['name']??$payload['title']??$slug);
        $this->green->core->prepare("UPDATE p2k_tp_opponents SET display_name=COALESCE(NULLIF(?,''),display_name),country_code=COALESCE(NULLIF(?,''),country_code),icon_url=COALESCE(NULLIF(?,''),icon_url),last_checked_at=UTC_TIMESTAMP(),profile_updated_at=UTC_TIMESTAMP(),last_error=NULL WHERE club_slug=? AND opponent_slug=?")->execute([$name,$country,$icon,$this->green->clubSlug,$slug]);$this->green->core->prepare("UPDATE p2k_g_gab_external_work SET status='completed',attempts=attempts+1,last_http_status=200,last_error=NULL WHERE work_key=?")->execute([$key]);return ['changed'=>1,'opponent_slug'=>$slug];
    }
    public function markExternalFailure(string $url,int $status,string $error=''): void
    {
        $path=(string)(parse_url($url,PHP_URL_PATH)?:'');if(!preg_match('~/pub/club/([^/]+)~i',$path,$m))return;$key='opponent:'.strtolower(rawurldecode($m[1]));$delay=$status===429?300:1800;$this->green->core->prepare("UPDATE p2k_g_gab_external_work SET attempts=attempts+1,last_http_status=?,last_error=?,retry_after=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),status=CASE WHEN attempts>=5 THEN 'failed' ELSE 'pending' END WHERE work_key=?")->execute([$status,substr($error,0,1000),$delay,$key]);
    }
}
