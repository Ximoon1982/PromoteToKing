<?php
declare(strict_types=1);

namespace P2K\Green;

use PDO;
use RuntimeException;

final class GreenRepository
{
    public PDO $core;
    public PDO $analytics;
    public string $clubSlug;
    private bool $gqacSchemaReady=false;
    private const GQAC_TRANSIENT_ATTEMPT_LIMIT = 3;
    private const ACCELERATOR_FINITE_RESERVE_MAX = 16;

    public function __construct(PDO $core, PDO $analytics, string $clubSlug)
    {
        $this->core = $core;
        $this->analytics = $analytics;
        $this->clubSlug = $clubSlug;
    }

    public static function open(): self { return new self(GreenConfig::core(),GreenConfig::analytics(),GreenConfig::clubSlug()); }

    public function initializeSchemas(): void
    {
        foreach ([[$this->core,'core-schema.sql'],[$this->analytics,'analytics-schema.sql']] as [$pdo,$file]) {
            $sql=file_get_contents(dirname(__DIR__).'/sql/'.$file);
            if ($sql===false) throw new RuntimeException("Unable to read {$file}.");
            foreach ($this->splitSql($sql) as $statement) $pdo->exec($statement);
        }
        // CREATE TABLE IF NOT EXISTS does not add columns to an already-running Green DB.
        // Keep the live Cycle #1 database and upgrade it in place before any new code uses
        // the integrity/provenance fields introduced by the consolidation release.
        $this->upgradeCoreSchema();
        $q=$this->core->prepare("INSERT IGNORE INTO p2k_g_state(club_slug,mode,stage,worker_target,client_ingest_target) VALUES(?,'seeding','not_started','blue','blue')");
        $q->execute([$this->clubSlug]);
    }

    private function splitSql(string $sql): array
    {
        $out=[]; $buf='';
        foreach (preg_split('/\R/',$sql) ?: [] as $line) {
            if (preg_match('/^\s*--/',$line)) continue;
            $buf.=$line."\n";
            if (substr(rtrim($line), -1) === ';') { $s=trim($buf); if ($s!=='') $out[]=rtrim($s,";\r\n "); $buf=''; }
        }
        if (trim($buf)!=='') $out[]=trim($buf);
        return $out;
    }

    private function columnExists(string $table,string $column): bool
    {
        $q=$this->core->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $q->execute([$table,$column]);return (int)$q->fetchColumn()>0;
    }

    private function indexExists(string $table,string $index): bool
    {
        $q=$this->core->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
        $q->execute([$table,$index]);return (int)$q->fetchColumn()>0;
    }

    private function upgradeCoreSchema(): void
    {
        $columns=[
            'index_time_class'=>"VARCHAR(32) NULL AFTER index_bucket",
            'index_result'=>"VARCHAR(24) NULL AFTER index_time_class",
            'club_verified'=>"TINYINT(1) NOT NULL DEFAULT 0 AFTER index_result",
            'verified_club_slug'=>"VARCHAR(120) NULL AFTER club_verified",
            'club_side'=>"VARCHAR(32) NULL AFTER verified_club_slug",
            'scoring_eligible'=>"TINYINT(1) NOT NULL DEFAULT 0 AFTER club_side",
            'exclusion_reason'=>"VARCHAR(64) NULL AFTER scoring_eligible",
            'trusted_legacy'=>"TINYINT(1) NOT NULL DEFAULT 0 AFTER exclusion_reason",
            'fact_source'=>"VARCHAR(32) NOT NULL DEFAULT 'api' AFTER trusted_legacy",
        ];
        foreach($columns as $name=>$definition){
            if(!$this->columnExists('p2k_g_matches',$name))$this->core->exec('ALTER TABLE p2k_g_matches ADD COLUMN '.$name.' '.$definition);
        }
        if(!$this->indexExists('p2k_g_matches','idx_g_match_current'))$this->core->exec('ALTER TABLE p2k_g_matches ADD INDEX idx_g_match_current (index_bucket,index_time_class,status,last_verified_at)');
        if(!$this->indexExists('p2k_g_matches','idx_g_match_eligibility'))$this->core->exec('ALTER TABLE p2k_g_matches ADD INDEX idx_g_match_eligibility (club_verified,time_class,scoring_eligible,status)');
        if(!$this->columnExists('p2k_g_state','public_read_target'))$this->core->exec("ALTER TABLE p2k_g_state ADD COLUMN public_read_target ENUM('blue','green') NOT NULL DEFAULT 'blue' AFTER force_mode");
        if(!$this->columnExists('p2k_g_state','migration_phase'))$this->core->exec("ALTER TABLE p2k_g_state ADD COLUMN migration_phase ENUM('blue_primary','shadow_writing','green_validated','green_reads_both_writing','green_primary') NOT NULL DEFAULT 'blue_primary' AFTER public_read_target");
        $stateColumns=[
            'gab_status'=>"ENUM('not_started','running','ready','error') NOT NULL DEFAULT 'not_started' AFTER migration_phase",
            'gab_phase'=>"VARCHAR(64) NOT NULL DEFAULT 'not_started' AFTER gab_status",
            'gab_started_at'=>"DATETIME NULL AFTER gab_phase",
            'gab_completed_at'=>"DATETIME NULL AFTER gab_started_at",
            'gab_last_error'=>"TEXT NULL AFTER gab_completed_at",
            'gffl_enabled'=>"TINYINT(1) NOT NULL DEFAULT 1 AFTER gab_last_error",
            'gffl_target_freshness_seconds'=>"INT UNSIGNED NOT NULL DEFAULT 1200 AFTER gffl_enabled",
            'compat_analytics_rebuilt_at'=>"DATETIME NULL AFTER gffl_target_freshness_seconds",
        ];
        foreach($stateColumns as $name=>$definition)if(!$this->columnExists('p2k_g_state',$name))$this->core->exec('ALTER TABLE p2k_g_state ADD COLUMN '.$name.' '.$definition);
        $this->core->exec("CREATE TABLE IF NOT EXISTS p2k_g_gab_lanes(lane_key VARCHAR(64) NOT NULL PRIMARY KEY,label VARCHAR(160) NOT NULL,priority INT NOT NULL,status ENUM('pending','running','completed','error') NOT NULL DEFAULT 'pending',total_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,processed_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,changed_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,error_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,cursor_json MEDIUMTEXT NULL,started_at DATETIME NULL,completed_at DATETIME NULL,last_error TEXT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_g_gab_status(status,priority)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->core->exec("CREATE TABLE IF NOT EXISTS p2k_g_gab_external_work(work_key VARCHAR(190) NOT NULL PRIMARY KEY,kind VARCHAR(48) NOT NULL,url VARCHAR(500) NOT NULL,status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',attempts INT UNSIGNED NOT NULL DEFAULT 0,retry_after DATETIME NULL,last_http_status INT NULL,last_error TEXT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_g_gab_external(status,retry_after,kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->core->exec("CREATE TABLE IF NOT EXISTS p2k_g_gffl_match_debt(match_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,priority SMALLINT UNSIGNED NOT NULL DEFAULT 40,reasons_json MEDIUMTEXT NULL,obligation_count BIGINT UNSIGNED NOT NULL DEFAULT 1,coalesced_count BIGINT UNSIGNED NOT NULL DEFAULT 0,status ENUM('pending','completed') NOT NULL DEFAULT 'pending',detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,due_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,last_served_at DATETIME NULL,completed_at DATETIME NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_g_gffl_due(status,due_at,priority,match_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->core->exec("CREATE TABLE IF NOT EXISTS p2k_g_phase_progress(cycle_no BIGINT UNSIGNED NOT NULL,phase_key VARCHAR(64) NOT NULL,label VARCHAR(160) NOT NULL,status ENUM('pending','running','completed','error') NOT NULL DEFAULT 'pending',completed_units BIGINT UNSIGNED NOT NULL DEFAULT 0,total_units BIGINT UNSIGNED NOT NULL DEFAULT 0,started_at DATETIME NULL,completed_at DATETIME NULL,last_update_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,detail_json MEDIUMTEXT NULL,PRIMARY KEY(cycle_no,phase_key),KEY idx_g_phase_cycle(cycle_no,status,phase_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->core->exec("CREATE TABLE IF NOT EXISTS p2k_g_member_events (event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,identity_id BIGINT UNSIGNED NULL,event_key VARCHAR(255) NOT NULL,event_type ENUM('discovered','joined','left','name_changed','rejoined') NOT NULL,detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,effective_at DATETIME NULL,username VARCHAR(120) NULL,previous_username VARCHAR(120) NULL,new_username VARCHAR(120) NULL,profile_status ENUM('pending','active','closed','unknown') NULL,profile_checked_at DATETIME NULL,cycle_no BIGINT UNSIGNED NULL,source VARCHAR(40) NOT NULL DEFAULT 'green_roster',metadata_json MEDIUMTEXT NULL,UNIQUE KEY uq_g_member_event_key(event_key),KEY idx_g_member_events_time(detected_at,event_id),KEY idx_g_member_events_identity(identity_id,event_id),KEY idx_g_member_events_profile(profile_status,event_type,detected_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if($this->columnExists('p2k_g_quick_board_cycle_items','claim_count')){
            $q=$this->core->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='p2k_g_quick_board_cycle_items' AND column_name='claim_count' LIMIT 1");
            $t=strtolower((string)$q->fetchColumn());if(strpos($t,'unsigned')!==false)$this->core->exec("ALTER TABLE p2k_g_quick_board_cycle_items MODIFY claim_count INT NOT NULL DEFAULT 0");
        }
    }

    private function clubSlugFromRef(string $ref): ?string
    {
        $ref=trim($ref);if($ref==='')return null;
        $path=parse_url($ref,PHP_URL_PATH);if(!is_string($path))return null;
        $parts=array_values(array_filter(explode('/',trim($path,'/')),'strlen'));
        for($i=0,$n=count($parts)-1;$i<$n;$i++){
            if(strtolower(rawurldecode((string)$parts[$i]))==='club')return strtolower(rawurldecode((string)$parts[$i+1]));
        }
        return null;
    }

    private function isExactClubRef(string $ref): bool
    {
        $slug=$this->clubSlugFromRef($ref);
        return $slug!==null && $slug===strtolower($this->clubSlug);
    }

    public function containsExactClub(array $match): bool
    {
        $hits=0;
        foreach(is_array($match['teams']??null)?$match['teams']:[] as $team){
            if(!is_array($team))continue;$hit=false;
            foreach(['@id','url'] as $field){if($this->isExactClubRef((string)($team[$field]??''))){$hit=true;break;}}
            if($hit)$hits++;
        }
        return $hits===1;
    }

    private function normalizeMatchStatus(string $raw): string
    {
        $status=strtolower(str_replace(['-',' '],'_',trim($raw)));
        if($status==='registered'||$status==='registration')return 'registered';
        if(in_array($status,['in_progress','inprogress','started','playing'],true))return 'in_progress';
        if(in_array($status,['finished','complete','completed'],true))return 'finished';
        return 'unknown';
    }

    private static function scoreResult(?float $p2k,?float $opp): string
    {
        if($p2k===null||$opp===null)return 'none';
        if($p2k>$opp)return 'win';
        if(abs($p2k-$opp)<.001)return 'draw';
        return 'loss';
    }

    private function purgeMatchDerivedData(int $matchId): void
    {
        $this->retireQuickBoardItemsForMatch($matchId,204);
        $this->core->prepare('DELETE FROM p2k_g_point_events WHERE match_id=?')->execute([$matchId]);
        $this->core->prepare('DELETE FROM p2k_g_games WHERE match_id=?')->execute([$matchId]);
        $this->core->prepare('DELETE FROM p2k_g_boards WHERE match_id=?')->execute([$matchId]);
        $this->core->prepare('DELETE FROM p2k_g_match_players WHERE match_id=?')->execute([$matchId]);
        $this->core->prepare("DELETE FROM p2k_g_work_claims WHERE (object_type='match_detail' AND object_key=?) OR (object_type='board_detail' AND object_key LIKE ?)")->execute([(string)$matchId,$matchId.':%']);
    }


    public function state(): array
    {
        $q=$this->core->prepare('SELECT * FROM p2k_g_state WHERE club_slug=?');$q->execute([$this->clubSlug]);
        $r=$q->fetch(); if (!is_array($r)) throw new RuntimeException('Green state row is missing; initialize Green first.'); return $r;
    }

    public function setControl(array $changes): array
    {
        $allowed=[
            'worker_target'=>['blue','green','both','paused'],
            'client_ingest_target'=>['blue','green','both','off'],
            'force_mode'=>['auto','seeding','quick','deep'],
            'public_read_target'=>['blue','green'],
            'migration_phase'=>['blue_primary','shadow_writing','green_validated','green_reads_both_writing','green_primary'],
            'gffl_enabled'=>['0','1'],
        ];
        $sets=[];$params=[];
        foreach ($allowed as $field=>$values) if (array_key_exists($field,$changes)) {
            $value=(string)$changes[$field]; if (!in_array($value,$values,true)) throw new RuntimeException("Invalid {$field}.");
            $sets[]="{$field}=?";$params[]=$value;
        }
        if (!$sets) return $this->state();
        $params[]=$this->clubSlug;
        $this->core->prepare('UPDATE p2k_g_state SET '.implode(',',$sets).',updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute($params);
        return $this->state();
    }

    public function setGfflTargetSeconds(int $seconds): array
    {
        $seconds=max(300,min(3600,$seconds));
        $this->core->prepare('UPDATE p2k_g_state SET gffl_target_freshness_seconds=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$seconds,$this->clubSlug]);
        return $this->state();
    }

    public function forceStage(string $mode): array
    {
        if (!in_array($mode,['seeding','quick','deep'],true)) throw new RuntimeException('Invalid mode.');
        $stage=$mode==='seeding'?'seed_index_roster':($mode==='quick'?'quick_index_roster':'deep_scan');
        $this->core->prepare('UPDATE p2k_g_state SET mode=?,stage=?,cycle_kind=NULL,cycle_started_at=NULL,last_error=NULL WHERE club_slug=?')->execute([$mode,$stage,$this->clubSlug]);
        return $this->state();
    }

    public function importFindings(string $text): array
    {
        $ids=[];
        foreach (preg_split('/\R/',$text) ?: [] as $line) {
            $line=trim($line); if ($line==='') continue;
            if (preg_match('/(?:match\/)?(\d+)\/?(?:[?#].*)?$/',$line,$m)) $ids[(int)$m[1]]=true;
        }
        if (!$ids) throw new RuntimeException('No match IDs were found in findings.txt.');
        ksort($ids,SORT_NUMERIC); $sha=hash('sha256',$text); $now=gmdate('Y-m-d H:i:s');
        $this->core->beginTransaction();
        try {
            $insF=$this->core->prepare('INSERT IGNORE INTO p2k_g_findings(match_id,imported_at,source_sha256) VALUES(?,?,?)');
            $insM=$this->core->prepare("INSERT INTO p2k_g_matches(match_id,api_url,status,discovery_findings,created_at,updated_at) VALUES(?,?,'unknown',1,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE discovery_findings=1,updated_at=UTC_TIMESTAMP()");
            $inserted=0;
            foreach (array_keys($ids) as $id) { $insF->execute([$id,$now,$sha]); $inserted+=$insF->rowCount(); $insM->execute([$id,'https://api.chess.com/pub/match/'.$id]); }
            $max=max(array_keys($ids));
            $this->core->prepare("UPDATE p2k_g_state SET mode='seeding',stage='seed_index_roster',seed_started_at=COALESCE(seed_started_at,UTC_TIMESTAMP()),discovery_high_watermark=GREATEST(COALESCE(discovery_high_watermark,0),?),last_error=NULL WHERE club_slug=?")->execute([$max,$this->clubSlug]);
            $this->core->commit();
            return ['source_sha256'=>$sha,'unique_ids'=>count($ids),'inserted_findings'=>$inserted,'min_id'=>min(array_keys($ids)),'max_id'=>$max];
        } catch (\Throwable $e) { if ($this->core->inTransaction()) $this->core->rollBack(); throw $e; }
    }

    public function startCycle(string $kind,string $mode,string $stage): int
    {
        $this->core->beginTransaction();
        try {
            $q=$this->core->prepare('SELECT cycle_no,cycle_started_at FROM p2k_g_state WHERE club_slug=? FOR UPDATE');$q->execute([$this->clubSlug]);$s=$q->fetch();
            if (!is_array($s)) throw new RuntimeException('Green state missing.');
            if ($s['cycle_started_at']!==null) { $this->core->commit(); return (int)$s['cycle_no']; }
            $no=(int)$s['cycle_no']+1;
            $this->core->prepare('INSERT INTO p2k_g_cycles(cycle_no,cycle_kind,mode,status,stage,started_at) VALUES(?,?,?,\'running\',?,UTC_TIMESTAMP())')->execute([$no,$kind,$mode,$stage]);
            $this->core->prepare('UPDATE p2k_g_state SET cycle_no=?,cycle_kind=?,cycle_started_at=UTC_TIMESTAMP(),cycle_completed_at=NULL,stage=? WHERE club_slug=?')->execute([$no,$kind,$stage,$this->clubSlug]);
            $this->core->commit();
            $this->recordPhaseProgress($stage,$this->phaseLabel($stage),'running',0,1,['cycle_kind'=>$kind,'mode'=>$mode]);
            return $no;
        } catch (\Throwable $e) { if ($this->core->inTransaction()) $this->core->rollBack(); throw $e; }
    }

    private function phaseLabel(string $stage): string
    {
        $label=trim(str_replace('_',' ',$stage));
        return $label===''?'Green phase':ucwords($label);
    }

    private function completePhaseProgressRow(int $cycle,string $phaseKey): void
    {
        if($cycle<=0||$phaseKey==='')return;
        $q=$this->core->prepare("UPDATE p2k_g_phase_progress SET status='completed',completed_units=CASE WHEN total_units>0 THEN total_units ELSE GREATEST(completed_units,1) END,total_units=CASE WHEN total_units>0 THEN total_units ELSE 1 END,completed_at=UTC_TIMESTAMP(),last_update_at=UTC_TIMESTAMP() WHERE cycle_no=? AND phase_key=?");
        $q->execute([$cycle,$phaseKey]);
        if($q->rowCount()===0)$this->recordPhaseProgress($phaseKey,$this->phaseLabel($phaseKey),'completed',1,1);
    }

    public function stage(string $stage): void
    {
        $s=$this->state();$previous=(string)($s['stage']??'');$cycle=(int)($s['cycle_no']??0);
        if($cycle>0&&$previous!==''&&$previous!==$stage)$this->completePhaseProgressRow($cycle,$previous);
        $this->core->prepare('UPDATE p2k_g_state SET stage=? WHERE club_slug=?')->execute([$stage,$this->clubSlug]);
        if ($cycle>0) {
            $this->core->prepare("UPDATE p2k_g_cycles SET stage=? WHERE cycle_no=? AND status='running'")->execute([$stage,$cycle]);
            $this->recordPhaseProgress($stage,$this->phaseLabel($stage),'running',0,1);
        }
    }

    public function completeCycle(array $summary=[],?string $nextStage=null): void
    {
        $s=$this->state();$no=(int)$s['cycle_no'];$stage=(string)($s['stage']??'');$mode=(string)($s['mode']??'');
        if($no>0&&$stage!=='')$this->completePhaseProgressRow($no,$stage);
        if($no>0&&$mode==='quick'){$gqac=$this->quickBoardCycleState($no);if(!empty($gqac['initialized']))$summary['gqac']=$gqac;}
        $this->core->beginTransaction();
        try{
            if($no>0)$this->core->prepare("UPDATE p2k_g_cycles SET status='completed',completed_at=UTC_TIMESTAMP(),summary_json=? WHERE cycle_no=?")->execute([json_encode($summary,JSON_UNESCAPED_SLASHES),$no]);
            if($nextStage!==null&&$nextStage!=='')$this->core->prepare('UPDATE p2k_g_state SET cycle_completed_at=UTC_TIMESTAMP(),cycle_started_at=NULL,cycle_kind=NULL,stage=? WHERE club_slug=?')->execute([$nextStage,$this->clubSlug]);
            else $this->core->prepare('UPDATE p2k_g_state SET cycle_completed_at=UTC_TIMESTAMP(),cycle_started_at=NULL,cycle_kind=NULL WHERE club_slug=?')->execute([$this->clubSlug]);
            $this->core->commit();
        }catch(\Throwable $e){if($this->core->inTransaction())$this->core->rollBack();throw $e;}
        // v2.10.6.16: cycle completion is only the durable finite-state transition.
        // Heavy analytics rebuilds are performed by GreenWorker after the global
        // p2k_green_worker lock is released, so they cannot serialize the five
        // staggered Green CRON feeders for several minutes.
    }

    public function recoverQuickCompleteTransition(): bool
    {
        $s=$this->state();
        if((string)($s['mode']??'')!=='quick'||(string)($s['stage']??'')!=='quick_complete')return false;
        if($s['cycle_started_at']!==null){
            $this->completeCycle(['recovered_transient_stage'=>'quick_complete','recovered_at'=>gmdate('c')],'quick_index_roster');
        }else{
            $this->core->prepare("UPDATE p2k_g_state SET stage='quick_index_roster',last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([$this->clubSlug]);
        }
        return true;
    }

    public function metric(int $cycle,string $type,string $source,string $outcome,int $delta=1): void
    {
        $q=$this->core->prepare('INSERT INTO p2k_g_request_metrics(cycle_no,request_type,source,outcome,request_count) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE request_count=request_count+VALUES(request_count)');
        $q->execute([$cycle,$type,$source,$outcome,$delta]);
        $this->core->prepare('UPDATE p2k_g_cycles SET request_total=request_total+? WHERE cycle_no=?')->execute([$delta,$cycle]);
    }

    public function changed(int $cycle,int $delta=1): void { $this->core->prepare('UPDATE p2k_g_cycles SET changed_objects=changed_objects+? WHERE cycle_no=?')->execute([$delta,$cycle]); }

    public function upsertIndex(array $payload): array
    {
        $before=$this->state();$previousWatermark=(int)($before['discovery_high_watermark']??0);$seenIds=[];
        $added=0;$highest=0;$anchor=0;
        // The club-index endpoint itself is scoped to the exact configured club slug, so an
        // ID returned here is verified as a P2K match even before its detail payload arrives.
        // v2.10.6.20: a Daily match may legitimately be cancelled while still in registration
        // and subsequently return 404/410. A terminal observation suppresses automatic GFFL
        // re-arming until this authoritative club index explicitly shows the match again. On
        // reappearance clear only that terminal suppression. Trusted-legacy facts/status stay
        // intact; non-trusted rows that were voided solely by HTTP terminal state are revived
        // to the index bucket so a fresh authoritative detail check can rebuild current facts.
        $q=$this->core->prepare("INSERT INTO p2k_g_matches(match_id,api_url,status,index_bucket,index_time_class,index_result,club_verified,verified_club_slug,discovery_index,created_at,updated_at) VALUES(?,?,'unknown',?,?,?,1,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=CASE WHEN trusted_legacy=0 AND last_http_status IN (404,410) AND exclusion_reason IN ('http_404','http_410') THEN CASE VALUES(index_bucket) WHEN 'registered' THEN 'registered' WHEN 'in_progress' THEN 'in_progress' WHEN 'finished' THEN 'finished' ELSE status END ELSE status END,is_void=CASE WHEN trusted_legacy=0 AND last_http_status IN (404,410) AND exclusion_reason IN ('http_404','http_410') THEN 0 ELSE is_void END,exclusion_reason=CASE WHEN trusted_legacy=0 AND last_http_status IN (404,410) AND exclusion_reason IN ('http_404','http_410') THEN NULL ELSE exclusion_reason END,index_bucket=VALUES(index_bucket),index_time_class=VALUES(index_time_class),index_result=VALUES(index_result),club_verified=1,verified_club_slug=VALUES(verified_club_slug),discovery_index=1,last_http_status=CASE WHEN last_http_status IN (404,410) THEN NULL ELSE last_http_status END,retry_after=NULL,updated_at=UTC_TIMESTAMP()");
        foreach(['registered','in_progress','finished'] as $bucket){
            foreach(is_array($payload[$bucket]??null)?$payload[$bucket]:[] as $m){
                if(!is_array($m))continue;$url=(string)($m['@id']??'');
                if(!preg_match('/\/(\d+)\/?$/',$url,$mm))continue;
                $id=(int)$mm[1];$tc=strtolower(trim((string)($m['time_class']??'')));$ir=strtolower(trim((string)($m['result']??'')));
                // Live/Blitz team-match IDs use a different/high ID space. Keep those rows
                // for factual exclusion, but never let them advance the Daily continuity scan.
                if($tc==='daily'){$seenIds[$id]=true;$highest=max($highest,$id);$anchor=max($anchor,$id);}
                $q->execute([$id,$url,$bucket,$tc?:null,$ir?:null,$this->clubSlug]);if($q->rowCount()===1)$added++;
                if($tc==='daily'&&in_array($bucket,['registered','in_progress'],true))$this->armGfflMatch($id,'club_index_'.$bucket,45,false);
            }
        }
        $this->core->prepare('UPDATE p2k_g_state SET last_index_fetch=UTC_TIMESTAMP(),index_anchor_match_id=GREATEST(COALESCE(index_anchor_match_id,0),?),discovery_high_watermark=GREATEST(COALESCE(discovery_high_watermark,0),?) WHERE club_slug=?')->execute([$anchor,$highest,$this->clubSlug]);
        return ['added'=>$added,'highest'=>$highest,'anchor'=>$anchor,'previous_watermark'=>$previousWatermark,'watermark_present'=>$previousWatermark>0&&isset($seenIds[$previousWatermark])];
    }

    public function normalizeDailyDiscoveryWatermark(): array
    {
        $dailyIndex=(int)($this->core->query("SELECT COALESCE(MAX(match_id),0) FROM p2k_g_matches WHERE discovery_index=1 AND index_time_class='daily'")->fetchColumn()?:0);
        $trustedMax=(int)($this->core->query("SELECT COALESCE(MAX(match_id),0) FROM p2k_g_matches WHERE trusted_legacy=1")->fetchColumn()?:0);
        $knownDaily=(int)($this->core->query("SELECT COALESCE(MAX(match_id),0) FROM p2k_g_matches WHERE time_class='daily'")->fetchColumn()?:0);
        $safe=max($dailyIndex,$trustedMax,$knownDaily);
        if($safe>0){
            $state=$this->state();$from=is_numeric($state['deep_scan_from']??null)?(int)$state['deep_scan_from']:null;$to=is_numeric($state['deep_scan_to']??null)?(int)$state['deep_scan_to']:null;$cursor=is_numeric($state['deep_scan_cursor']??null)?(int)$state['deep_scan_cursor']:null;
            if($from!==null&&$from>$safe){$from=null;$to=null;$cursor=null;}
            else{if($to!==null&&$to>$safe)$to=$safe;if($cursor!==null&&$cursor>$safe+1)$cursor=$safe+1;}
            $this->core->prepare('UPDATE p2k_g_state SET discovery_high_watermark=?,index_anchor_match_id=?,deep_scan_from=?,deep_scan_to=?,deep_scan_cursor=? WHERE club_slug=?')->execute([$safe,$dailyIndex?:$safe,$from,$to,$cursor,$this->clubSlug]);
        }
        return ['daily_index_max'=>$dailyIndex,'trusted_legacy_max'=>$trustedMax,'known_daily_max'=>$knownDaily,'normalized_watermark'=>$safe];
    }

    public function backfillEligibilityFromCurrentFacts(): array
    {
        // Existing Green rows predate scoring_eligible. Only promote a non-legacy row
        // when the exact club index has verified membership and the stored Daily result
        // is internally consistent with the Club Points formula.
        $q=$this->core->prepare("UPDATE p2k_g_matches SET scoring_eligible=1,exclusion_reason=NULL WHERE trusted_legacy=0 AND club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0 AND board_count>0 AND p2k_score IS NOT NULL AND opponent_score IS NOT NULL AND result IN ('win','draw','loss') AND competition_points=CASE result WHEN 'win' THEN 5*board_count WHEN 'draw' THEN 2*board_count ELSE 0 END");
        $q->execute();$promoted=(int)$q->rowCount();
        $q=$this->core->prepare("UPDATE p2k_g_matches SET scoring_eligible=0,competition_points=0,exclusion_reason=CASE WHEN time_class IS NOT NULL AND time_class<>'' AND time_class<>'daily' THEN 'non_daily' WHEN is_void=1 OR status='cancelled' THEN COALESCE(exclusion_reason,'finished_0_0') WHEN club_verified=0 OR status='not_club' THEN COALESCE(exclusion_reason,'not_club') WHEN status='unavailable' THEN COALESCE(exclusion_reason,'unavailable') ELSE exclusion_reason END WHERE trusted_legacy=0 AND (club_verified=0 OR (time_class IS NOT NULL AND time_class<>'' AND time_class<>'daily') OR is_void=1 OR status IN ('cancelled','not_club','unavailable'))");
        $q->execute();$excluded=(int)$q->rowCount();
        $where="m.club_verified=0 OR (m.time_class IS NOT NULL AND m.time_class<>'' AND m.time_class<>'daily') OR m.is_void=1 OR m.status IN ('cancelled','not_club','unavailable')";$purged=0;
        foreach([
            "DELETE e FROM p2k_g_point_events e JOIN p2k_g_matches m ON m.match_id=e.match_id WHERE m.trusted_legacy=0 AND ({$where})",
            "DELETE g FROM p2k_g_games g JOIN p2k_g_matches m ON m.match_id=g.match_id WHERE m.trusted_legacy=0 AND ({$where})",
            "DELETE b FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id WHERE m.trusted_legacy=0 AND ({$where})",
            "DELETE mp FROM p2k_g_match_players mp JOIN p2k_g_matches m ON m.match_id=mp.match_id WHERE m.trusted_legacy=0 AND ({$where})",
        ] as $sql){$purged+=(int)$this->core->exec($sql);}
        return ['promoted'=>$promoted,'excluded_or_zeroed'=>$excluded,'derived_rows_purged'=>$purged];
    }

    private function recordMemberEvent(string $type,?int $identityId,?string $username,?string $previous=null,?string $next=null,?string $profileStatus=null,string $source='green_roster',array $metadata=[]): void
    {
        $cycle=(int)($this->state()['cycle_no']??0);$basis=$type.'|'.($identityId??0).'|'.strtolower((string)$username).'|'.strtolower((string)$previous).'|'.strtolower((string)$next).'|cycle:'.$cycle;
        // Daily event key makes repeated roster observations idempotent while retaining real later lifecycle changes.
        $key=hash('sha256',$basis);
        $q=$this->core->prepare("INSERT IGNORE INTO p2k_g_member_events(identity_id,event_key,event_type,detected_at,effective_at,username,previous_username,new_username,profile_status,cycle_no,source,metadata_json) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,?,?,?,?,?,?)");
        $q->execute([$identityId,$key,$type,$username,$previous,$next,$profileStatus,$cycle?:null,$source,$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
    }

    public function upsertRoster(array $payload): array
    {
        $seen=[];$new=0;$rejoined=0;$left=0;$renamed=0;
        $members=[];
        foreach (['weekly','monthly','all_time'] as $group) foreach (is_array($payload[$group] ?? null)?$payload[$group]:[] as $m) if (is_array($m)) $members[]=$m;
        foreach (is_array($payload['members'] ?? null)?$payload['members']:[] as $m) if (is_array($m)) $members[]=$m;
        foreach ($members as $m) {
            $u=trim((string)($m['username'] ?? '')); if ($u==='') continue; $k=strtolower($u); if(isset($seen[$k])) continue;$seen[$k]=true;
            $join=is_numeric($m['joined']??null)?(int)$m['joined']:null;
            $before=$this->core->prepare('SELECT id,username,current_member FROM p2k_g_players WHERE username_key=? LIMIT 1');$before->execute([$k]);$old=$before->fetch();
            $q=$this->core->prepare('INSERT INTO p2k_g_players(username,username_key,current_member,joined_epoch,last_seen_roster_at,created_at,updated_at) VALUES(?,?,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),current_member=1,joined_epoch=COALESCE(VALUES(joined_epoch),joined_epoch),left_at=NULL,last_seen_roster_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()');
            $q->execute([$u,$k,$join]);
            $idq=$this->core->prepare('SELECT id FROM p2k_g_players WHERE username_key=?');$idq->execute([$k]);$id=(int)$idq->fetchColumn();
            if(!is_array($old)){$new++;$this->recordMemberEvent('discovered',$id,$u,null,null,null,'green_roster',['joined_epoch'=>$join]);$this->recordMemberEvent('joined',$id,$u,null,null,null,'green_roster',['joined_epoch'=>$join]);}
            elseif((int)($old['current_member']??0)!==1){$rejoined++;$this->recordMemberEvent('rejoined',$id,$u,null,null,null,'green_roster',['joined_epoch'=>$join]);$this->markDepartureProfileResult($u,200,['status'=>'active','source'=>'roster_rejoin']);}
        }
        if ($seen) {
            $keys=array_keys($seen);$ph=implode(',',array_fill(0,count($keys),'?'));
            $q=$this->core->prepare("SELECT p.id,p.username,p.username_key,i.canonical_username_key,i.canonical_username FROM p2k_g_players p LEFT JOIN p2k_g_identity_map i ON i.username_key=p.username_key AND i.trusted=1 WHERE p.current_member=1 AND p.username_key NOT IN ({$ph})");$q->execute($keys);$departing=$q->fetchAll()?:[];
            foreach($departing as $d){$canonical=strtolower((string)($d['canonical_username_key']??''));if($canonical!==''&&$canonical!==(string)$d['username_key']&&isset($seen[$canonical])){$renamed++;$this->recordMemberEvent('name_changed',(int)$d['id'],(string)$d['username'],(string)$d['username'],(string)($d['canonical_username']??$canonical),null,'trusted_identity');continue;}$left++;$this->recordMemberEvent('left',(int)$d['id'],(string)$d['username'],null,null,'pending','green_roster');}
            $this->core->prepare("UPDATE p2k_g_players SET current_member=0,left_at=COALESCE(left_at,UTC_TIMESTAMP()) WHERE current_member=1 AND username_key NOT IN ({$ph})")->execute($keys);
        }
        $this->core->prepare('UPDATE p2k_g_state SET last_roster_fetch=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
        return ['observed'=>count($seen),'new'=>$new,'rejoined'=>$rejoined,'left'=>$left,'renamed_by_identity'=>$renamed];
    }

    private function collapseMemberEvents(array $events,int $limit): array
    {
        $drop=[];$window=48*3600;
        foreach($events as $i=>$rename){
            if((string)($rename['event_type']??'')!=='name_changed')continue;
            $renameTs=strtotime((string)($rename['detected_at']??'').' UTC');if($renameTs===false)continue;
            $oldKey=strtolower(trim((string)($rename['previous_username']??$rename['username']??'')));
            $newKey=strtolower(trim((string)($rename['new_username']??'')));
            $identity=(int)($rename['identity_id']??0);$cycle=(int)($rename['cycle_no']??0);
            $nearestLeft=null;$nearestLeftTs=null;
            foreach($events as $j=>$event){
                if($j===$i||isset($drop[$j]))continue;
                $type=(string)($event['event_type']??'');$eventTs=strtotime((string)($event['detected_at']??'').' UTC');if($eventTs===false||$eventTs>$renameTs)continue;
                $delta=$renameTs-$eventTs;if($delta>$window)continue;
                $eventCycle=(int)($event['cycle_no']??0);
                $sameTransition=($cycle>0&&$eventCycle>0&&abs($cycle-$eventCycle)<=3)||$delta<=$window;
                if(!$sameTransition)continue;
                if(in_array($type,['discovered','joined'],true)&&$newKey!==''){
                    $eventKey=strtolower(trim((string)($event['username']??$event['new_username']??'')));
                    if($eventKey===$newKey)$drop[$j]=true;
                    continue;
                }
                if($type==='left'&&$identity>0&&(int)($event['identity_id']??0)===$identity){
                    $eventKey=strtolower(trim((string)($event['username']??'')));
                    if($oldKey===''||$eventKey===$oldKey){if($nearestLeftTs===null||$eventTs>$nearestLeftTs){$nearestLeft=$j;$nearestLeftTs=$eventTs;}}
                }
            }
            if($nearestLeft!==null)$drop[$nearestLeft]=true;
        }
        $out=[];foreach($events as $i=>$event)if(!isset($drop[$i])){$out[]=$event;if(count($out)>=$limit)break;}
        return $out;
    }

    /** Chronology read model only: stored lifecycle events remain append-only/raw. */
    public function memberEvents(int $limit=200,array $filters=[]): array
    {
        $limit=max(1,min(1000,$limit));
        $eventType=strtolower(trim((string)($filters['event_type']??'')));
        if(!in_array($eventType,['','joined','left','name_changed','rejoined'],true))$eventType='';
        $member=strtolower(trim(ltrim((string)($filters['member']??''),'@')));
        $from=$this->memberEventDateFilter((string)($filters['from']??''));
        $to=$this->memberEventDateFilter((string)($filters['to']??''));
        if($from!==null&&$to!==null&&$from>$to)throw new \InvalidArgumentException('The chronology From date must not be after the To date.');

        $where=[];$params=[];
        // Include a 48-hour context around a requested date range so rename-collapse
        // can still see the transient joined/left rows it needs to suppress.
        if($from!==null){$where[]='detected_at>=DATE_SUB(?,INTERVAL 48 HOUR)';$params[]=$from.' 00:00:00';}
        if($to!==null){$where[]='detected_at<DATE_ADD(DATE_ADD(?,INTERVAL 1 DAY),INTERVAL 48 HOUR)';$params[]=$to.' 00:00:00';}

        if($member!==''){
            $scope=$this->memberEventScope($member);$keys=$scope['keys'];$ids=$scope['identity_ids'];$clauses=[];
            if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$clauses[]="identity_id IN ({$ph})";array_push($params,...$ids);}
            if($keys){$ph=implode(',',array_fill(0,count($keys),'?'));$clauses[]="LOWER(username) IN ({$ph})";array_push($params,...$keys);$clauses[]="LOWER(previous_username) IN ({$ph})";array_push($params,...$keys);$clauses[]="LOWER(new_username) IN ({$ph})";array_push($params,...$keys);}
            if(!$clauses)return [];
            $where[]='('.implode(' OR ',$clauses).')';
        }
        $fetchLimit=$member!==''?5000:min(10000,max(3000,$limit*6));
        $sql='SELECT event_id,identity_id,event_type,detected_at,effective_at,username,previous_username,new_username,profile_status,profile_checked_at,cycle_no,source,metadata_json FROM p2k_g_member_events'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY detected_at DESC,event_id DESC LIMIT '.$fetchLimit;
        $q=$this->core->prepare($sql);$q->execute($params);$events=$this->collapseMemberEvents($q->fetchAll()?:[],$fetchLimit);
        $events=$this->memberChronologyView($events);
        $out=[];foreach($events as $event){
            $detected=(string)($event['detected_at']??'');
            if($from!==null&&$detected<$from.' 00:00:00')continue;
            if($to!==null){$next=(new \DateTimeImmutable($to.' 00:00:00',new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');if($detected>=$next)continue;}
            if($eventType!==''&&(string)($event['event_type']??'')!==$eventType)continue;
            $out[]=$event;if(count($out)>=$limit)break;
        }
        return $out;
    }

    private function memberEventDateFilter(string $value): ?string
    {
        $value=trim($value);if($value==='')return null;
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
        $errors=\DateTimeImmutable::getLastErrors();
        if(!$d||($errors!==false&&(($errors['warning_count']??0)>0||($errors['error_count']??0)>0))||$d->format('Y-m-d')!==$value)throw new \InvalidArgumentException('Chronology dates must use YYYY-MM-DD.');
        return $value;
    }

    /** Resolve a chronology username through the stable player-id/alias graph. */
    private function memberEventScope(string $key): array
    {
        $keys=[$key=>true];$ids=[];$pid=null;$canonical='';
        $q=$this->core->prepare('SELECT id,chess_player_id,username_key FROM p2k_g_players WHERE username_key=? LIMIT 1');$q->execute([$key]);$p=$q->fetch();
        if(is_array($p)){$ids[(int)$p['id']]=true;if(is_numeric($p['chess_player_id']??null))$pid=(int)$p['chess_player_id'];$canonical=strtolower((string)$p['username_key']);}
        $q=$this->core->prepare('SELECT canonical_username_key,chess_player_id FROM p2k_g_identity_map WHERE username_key=? ORDER BY trusted DESC,updated_at DESC LIMIT 1');$q->execute([$key]);$m=$q->fetch();
        if(is_array($m)){if($canonical==='')$canonical=strtolower(trim((string)($m['canonical_username_key']??'')));if($pid===null&&is_numeric($m['chess_player_id']??null))$pid=(int)$m['chess_player_id'];}
        if($canonical!=='')$keys[$canonical]=true;
        if($pid===null){$q=$this->core->prepare('SELECT chess_player_id FROM p2k_g_player_aliases WHERE username_key=? ORDER BY last_seen_at DESC LIMIT 1');$q->execute([$key]);$v=$q->fetchColumn();if($v!==false&&is_numeric($v))$pid=(int)$v;}
        if($pid!==null){
            $q=$this->core->prepare('SELECT id,username_key FROM p2k_g_players WHERE chess_player_id=?');$q->execute([$pid]);foreach($q->fetchAll()?:[] as $r){$ids[(int)$r['id']]=true;$keys[strtolower((string)$r['username_key'])]=true;}
            $q=$this->core->prepare('SELECT username_key FROM p2k_g_player_aliases WHERE chess_player_id=?');$q->execute([$pid]);foreach($q->fetchAll(\PDO::FETCH_COLUMN)?:[] as $v)$keys[strtolower((string)$v)]=true;
            $q=$this->core->prepare('SELECT username_key,canonical_username_key FROM p2k_g_identity_map WHERE chess_player_id=?');$q->execute([$pid]);foreach($q->fetchAll()?:[] as $r){$keys[strtolower((string)$r['username_key'])]=true;$c=strtolower(trim((string)($r['canonical_username_key']??'')));if($c!=='')$keys[$c]=true;}
        }elseif($canonical!==''){
            $q=$this->core->prepare('SELECT username_key FROM p2k_g_identity_map WHERE canonical_username_key=?');$q->execute([$canonical]);foreach($q->fetchAll(\PDO::FETCH_COLUMN)?:[] as $v)$keys[strtolower((string)$v)]=true;
        }
        return ['keys'=>array_values(array_filter(array_keys($keys))),'identity_ids'=>array_values(array_keys($ids))];
    }

    /** Consolidate discovered+joined into one displayed Joined event; raw rows are untouched. */
    private function memberChronologyView(array $events): array
    {
        $joined=[];foreach($events as $event){if((string)($event['event_type']??'')!=='joined')continue;$id=(int)($event['identity_id']??0);$user=strtolower(trim((string)($event['username']??'')));$ts=strtotime((string)($event['detected_at']??'').' UTC')?:0;$joined[]=['id'=>$id,'user'=>$user,'ts'=>$ts,'cycle'=>(int)($event['cycle_no']??0)];}
        $out=[];foreach($events as $event){
            $raw=(string)($event['event_type']??'');
            if($raw==='discovered'){
                $id=(int)($event['identity_id']??0);$user=strtolower(trim((string)($event['username']??'')));$ts=strtotime((string)($event['detected_at']??'').' UTC')?:0;$cycle=(int)($event['cycle_no']??0);$paired=false;
                foreach($joined as $j){if($id>0&&$j['id']>0&&$id!==$j['id'])continue;if($user!==''&&$j['user']!==''&&$user!==$j['user'])continue;if(($cycle>0&&$j['cycle']>0&&$cycle===$j['cycle'])||abs($ts-$j['ts'])<=600){$paired=true;break;}}
                if($paired)continue;$event['event_type']='joined';$event['raw_event_type']='discovered';
            }
            $metadata=[];if(is_string($event['metadata_json']??null)&&$event['metadata_json']!==''){$decoded=json_decode((string)$event['metadata_json'],true);if(is_array($decoded))$metadata=$decoded;}
            $event['metadata']=$metadata;unset($event['metadata_json']);$out[]=$event;
        }
        return $out;
    }

    public function memberLookup(string $username): array
    {
        $input=trim(ltrim($username,'@'));$key=strtolower($input);if($key==='')return ['found'=>false,'query'=>$username];
        $player=null;$identityHint=null;
        $q=$this->core->prepare('SELECT * FROM p2k_g_players WHERE username_key=? LIMIT 1');$q->execute([$key]);$player=$q->fetch();
        $iq=$this->core->prepare('SELECT username_key,username,canonical_username_key,canonical_username,chess_player_id,source,trusted,updated_at FROM p2k_g_identity_map WHERE username_key=? ORDER BY trusted DESC,updated_at DESC LIMIT 1');$iq->execute([$key]);$identityHint=$iq->fetch()?:null;
        if(!is_array($player)){
            $pid=null;$aq=$this->core->prepare('SELECT chess_player_id FROM p2k_g_player_aliases WHERE username_key=? ORDER BY last_seen_at DESC LIMIT 1');$aq->execute([$key]);$aliasPid=$aq->fetchColumn();if($aliasPid!==false&&is_numeric($aliasPid))$pid=(int)$aliasPid;
            if($pid===null&&is_array($identityHint)&&is_numeric($identityHint['chess_player_id']??null))$pid=(int)$identityHint['chess_player_id'];
            $canonical=is_array($identityHint)?strtolower(trim((string)($identityHint['canonical_username_key']??''))):'';
            if($pid!==null){$q=$this->core->prepare('SELECT * FROM p2k_g_players WHERE chess_player_id=? LIMIT 1');$q->execute([$pid]);$player=$q->fetch();}
            if(!is_array($player)&&$canonical!==''){$q=$this->core->prepare('SELECT * FROM p2k_g_players WHERE username_key=? LIMIT 1');$q->execute([$canonical]);$player=$q->fetch();}
        }elseif(is_array($identityHint)){
            $canonical=strtolower(trim((string)($identityHint['canonical_username_key']??'')));
            if($canonical!==''&&$canonical!==(string)$player['username_key']){$cq=$this->core->prepare('SELECT * FROM p2k_g_players WHERE username_key=? LIMIT 1');$cq->execute([$canonical]);$canonicalPlayer=$cq->fetch();if(is_array($canonicalPlayer))$player=$canonicalPlayer;}
        }
        if(!is_array($player))return ['found'=>false,'query'=>$input,'identity_hint'=>$identityHint];

        $pid=is_numeric($player['chess_player_id']??null)?(int)$player['chess_player_id']:null;$canonicalKey=strtolower((string)$player['username_key']);
        $aliases=[];$aliasKeys=[];$addAlias=static function(array &$aliases,array &$aliasKeys,string $name,string $source,?string $first=null,?string $last=null) use($canonicalKey): void {
            $name=trim($name);if($name==='')return;$k=strtolower($name);$aliasKeys[$k]=true;if(isset($aliases[$k]))return;$aliases[$k]=['username'=>$name,'username_key'=>$k,'source'=>$source,'first_seen_at'=>$first,'last_seen_at'=>$last,'current'=>$k===$canonicalKey];
        };
        $addAlias($aliases,$aliasKeys,(string)$player['username'],'green_player',null,(string)($player['updated_at']??''));
        if($pid!==null){$aq=$this->core->prepare('SELECT username_key,username,first_seen_at,last_seen_at FROM p2k_g_player_aliases WHERE chess_player_id=? ORDER BY first_seen_at,username_key');$aq->execute([$pid]);foreach($aq->fetchAll()?:[] as $a)$addAlias($aliases,$aliasKeys,(string)($a['username']??$a['username_key']),'player_id',(string)($a['first_seen_at']??''),(string)($a['last_seen_at']??''));}
        $mq=$this->core->prepare('SELECT username_key,username,canonical_username_key,canonical_username,source,trusted,updated_at FROM p2k_g_identity_map WHERE canonical_username_key=?'.($pid!==null?' OR chess_player_id=?':''));$mq->execute($pid!==null?[$canonicalKey,$pid]:[$canonicalKey]);foreach($mq->fetchAll()?:[] as $m)$addAlias($aliases,$aliasKeys,(string)($m['username']??$m['username_key']),'identity:'.(string)($m['source']??'map'),null,(string)($m['updated_at']??''));
        $aliasKeys[$key]=true;$keys=array_keys($aliasKeys);$ph=implode(',',array_fill(0,count($keys),'?'));

        $eventParams=array_merge([(int)$player['id']],$keys,$keys,$keys);$eventsQ=$this->core->prepare("SELECT event_id,identity_id,event_type,detected_at,effective_at,username,previous_username,new_username,profile_status,profile_checked_at,cycle_no,source,metadata_json FROM p2k_g_member_events WHERE identity_id=? OR LOWER(username) IN ({$ph}) OR LOWER(previous_username) IN ({$ph}) OR LOWER(new_username) IN ({$ph}) ORDER BY detected_at DESC,event_id DESC LIMIT 500");$eventsQ->execute($eventParams);$events=$this->collapseMemberEvents($eventsQ->fetchAll()?:[],250);
        $eventCounts=['discovered'=>0,'joined'=>0,'left'=>0,'name_changed'=>0,'rejoined'=>0];$firstEvent=null;$lastLeft=null;$lastClosure=null;
        foreach($events as &$event){$type=(string)($event['event_type']??'');if(isset($eventCounts[$type]))$eventCounts[$type]++;if($firstEvent===null||strcmp((string)$event['detected_at'],(string)$firstEvent['detected_at'])<0)$firstEvent=$event;if($type==='left'&&$lastLeft===null)$lastLeft=$event;if($type==='left'&&(string)($event['profile_status']??'')==='closed'&&$lastClosure===null)$lastClosure=$event;if(is_string($event['metadata_json']??null)&&$event['metadata_json']!==''){$decoded=json_decode((string)$event['metadata_json'],true);$event['metadata']=is_array($decoded)?$decoded:[];}else$event['metadata']=[];unset($event['metadata_json']);}unset($event);

        $stats=['matches'=>0,'games'=>0,'points'=>0.0,'wins'=>0,'draws'=>0,'losses'=>0,'last_activity_epoch'=>null];
        try{$sq=$this->core->prepare("SELECT COUNT(DISTINCT match_id) matches,COUNT(*) games,COALESCE(SUM(points),0) points,SUM(LOWER(result) IN ('win','won','1-0')) wins,SUM(LOWER(result) IN ('draw','drawn','1/2-1/2')) draws,SUM(LOWER(result) IN ('loss','lost','0-1')) losses,MAX(event_epoch) last_activity_epoch FROM p2k_g_point_events WHERE username_key IN ({$ph})");$sq->execute($keys);$stats=array_merge($stats,$sq->fetch()?:[]);}catch(\Throwable $ignored){}
        $load=['registered'=>0,'in_progress'=>0];try{$lq=$this->core->prepare("SELECT SUM(m.status='registered') registered,SUM(m.status='in_progress') in_progress FROM p2k_g_match_players mp JOIN p2k_g_matches m ON m.match_id=mp.match_id WHERE mp.is_p2k=1 AND mp.username_key IN ({$ph}) AND m.status IN ('registered','in_progress')");$lq->execute($keys);$load=array_merge($load,$lq->fetch()?:[]);}catch(\Throwable $ignored){}
        $achievements=0;try{$xq=$this->analytics->prepare("SELECT COUNT(DISTINCT achievement_key) FROM p2k_an_achievement_unlocks WHERE club_slug=? AND username_key IN ({$ph})");$xq->execute(array_merge([$this->clubSlug],$keys));$achievements=(int)$xq->fetchColumn();}catch(\Throwable $ignored){}
        $live=null;try{$lq=$this->analytics->prepare("SELECT username,total_points,arena_count,total_games,total_wins,total_draws,total_losses,best_rank,first_place_count,top3_count,top10_count FROM p2k_lr_players WHERE club_slug=? AND username_key IN ({$ph}) ORDER BY (username_key=?) DESC,total_points DESC LIMIT 1");$lq->execute(array_merge([$this->clubSlug],$keys,[$canonicalKey]));$live=$lq->fetch()?:null;}catch(\Throwable $ignored){}

        return ['found'=>true,'query'=>$input,'member'=>[
            'identity_id'=>(int)$player['id'],'chess_player_id'=>$pid,'username'=>(string)$player['username'],'username_key'=>$canonicalKey,'current_member'=>(bool)$player['current_member'],
            'joined_epoch'=>is_numeric($player['joined_epoch']??null)?(int)$player['joined_epoch']:null,'left_at'=>$player['left_at']??null,'account_status'=>$player['account_status']??null,'country_url'=>$player['country_url']??null,'avatar_url'=>$player['avatar_url']??null,
            'daily_rating'=>is_numeric($player['daily_rating']??null)?(int)$player['daily_rating']:null,'chess960_rating'=>is_numeric($player['chess960_rating']??null)?(int)$player['chess960_rating']:null,
            'profile_checked_at'=>$player['profile_checked_at']??null,'stats_checked_at'=>$player['stats_checked_at']??null,'last_seen_roster_at'=>$player['last_seen_roster_at']??null,'created_at'=>$player['created_at']??null,'updated_at'=>$player['updated_at']??null,
        ],'aliases'=>array_values($aliases),'lifecycle'=>['counts'=>$eventCounts,'first_event'=>$firstEvent,'last_left'=>$lastLeft,'last_closure'=>$lastClosure,'events'=>$events],
        'activity'=>['matches'=>(int)($stats['matches']??0),'games'=>(int)($stats['games']??0),'points'=>(float)($stats['points']??0),'wins'=>(int)($stats['wins']??0),'draws'=>(int)($stats['draws']??0),'losses'=>(int)($stats['losses']??0),'last_activity_epoch'=>is_numeric($stats['last_activity_epoch']??null)?(int)$stats['last_activity_epoch']:null,'registered_matches'=>(int)($load['registered']??0),'in_progress_matches'=>(int)($load['in_progress']??0)],
        'achievements'=>$achievements,'live'=>$live];
    }

    public function nextDepartureProfileCheck(): ?string
    {
        $q=$this->core->query("SELECT username FROM p2k_g_member_events WHERE event_type='left' AND profile_status='pending' ORDER BY detected_at,event_id LIMIT 1");$v=$q->fetchColumn();return $v===false?null:(string)$v;
    }

    public function markDepartureProfileResult(string $username,int $httpStatus,?array $payload=null): void
    {
        $reported=strtolower(trim((string)($payload['status']??'')));
        $reason=strtolower(trim((string)($payload['closure_reason']??$payload['closed_reason']??$payload['reason']??'')));
        if($reported!==''&&str_contains($reported,':')){[$base,$suffix]=array_pad(explode(':',$reported,2),2,'');if($reason==='')$reason=trim($suffix);$coarse=$base;}else$coarse=$reported;
        $status='unknown';
        if(in_array($httpStatus,[404,410],true)){$status='closed';if($reported==='')$reported='closed';if($reason==='')$reason='http_'.$httpStatus;}
        elseif($httpStatus===200){$status=in_array($coarse,['closed','disabled'],true)?'closed':'active';if($reported==='')$reported=$status;}
        if($status==='unknown')return;
        $q=$this->core->prepare("UPDATE p2k_g_member_events SET profile_status=?,profile_checked_at=UTC_TIMESTAMP(),metadata_json=JSON_SET(COALESCE(metadata_json,'{}'),'$.profile_http_status',?,'$.profile_account_status',?,'$.profile_closure_reason',?) WHERE event_type='left' AND username=? AND profile_status='pending' ORDER BY event_id DESC LIMIT 1");
        $q->execute([$status,$httpStatus,$reported,$reason!==''?$reason:null,$username]);
    }

    private function releaseMatchClaim(int $id): void
    {
        $this->core->prepare("DELETE FROM p2k_g_work_claims WHERE object_type='match_detail' AND object_key=?")->execute([(string)$id]);
    }


    private function releaseBoardClaim(int $matchId,int $boardNo): void
    {
        $this->core->prepare("DELETE FROM p2k_g_work_claims WHERE object_type='board_detail' AND object_key=?")->execute([$matchId.':'.$boardNo]);
    }

    /**
     * GQAC — Green Quick-cycle Accounting & Convergence.
     *
     * Quick board work is snapshotted once per cycle.  New hints arriving after
     * admission never enlarge the current cohort: a terminal observation retires
     * the current-cycle item and leaves needs_refresh armed for the next cycle when
     * the authoritative hint changed while the request was in flight.
     */
    private function ensureGqacSchema(): void
    {
        if($this->gqacSchemaReady)return;
        $this->core->exec("CREATE TABLE IF NOT EXISTS p2k_g_quick_board_cycles (
            cycle_no BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            initialized_at DATETIME NOT NULL,
            total_items INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->core->exec("CREATE TABLE IF NOT EXISTS p2k_g_quick_board_cycle_items (
            cycle_no BIGINT UNSIGNED NOT NULL,
            match_id BIGINT UNSIGNED NOT NULL,
            board_no INT UNSIGNED NOT NULL,
            admitted_hint_hash CHAR(64) NULL,
            status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
            claim_count INT NOT NULL DEFAULT 0,
            requeued_for_next TINYINT(1) NOT NULL DEFAULT 0,
            terminal_http_status INT NULL,
            first_claimed_at DATETIME NULL,
            last_claimed_at DATETIME NULL,
            completed_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(cycle_no,match_id,board_no),
            KEY idx_g_qbc_pending(cycle_no,status,match_id,board_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->gqacSchemaReady=true;
    }

    private function retireIneligibleQuickBoardItems(int $cycleNo): int
    {
        if($cycleNo<=0)return 0;$this->ensureGqacSchema();
        $q=$this->core->prepare("UPDATE p2k_g_quick_board_cycle_items i LEFT JOIN p2k_g_boards b ON b.match_id=i.match_id AND b.board_no=i.board_no LEFT JOIN p2k_g_matches m ON m.match_id=i.match_id SET i.status='completed',i.requeued_for_next=0,i.terminal_http_status=204,i.completed_at=UTC_TIMESTAMP(),i.updated_at=UTC_TIMESTAMP() WHERE i.cycle_no=? AND i.status='pending' AND (b.match_id IS NULL OR m.match_id IS NULL OR m.club_verified<>1 OR COALESCE(m.time_class,'')<>'daily' OR m.is_void=1 OR m.status NOT IN ('registered','in_progress','finished') OR i.board_no>COALESCE(m.board_count,0))");$q->execute([$cycleNo]);return (int)$q->rowCount();
    }

    private function retireExhaustedTransientQuickBoardItems(int $cycleNo): int
    {
        if($cycleNo<=0)return 0;$this->ensureGqacSchema();
        $q=$this->core->prepare("UPDATE p2k_g_quick_board_cycle_items i JOIN p2k_g_boards b ON b.match_id=i.match_id AND b.board_no=i.board_no SET i.status='completed',i.requeued_for_next=1,i.terminal_http_status=b.last_http_status,i.completed_at=UTC_TIMESTAMP(),i.updated_at=UTC_TIMESTAMP() WHERE i.cycle_no=? AND i.status='pending' AND i.claim_count>=? AND b.last_http_status IS NOT NULL AND b.last_http_status NOT IN (200,404,410) AND b.retry_after IS NOT NULL AND b.retry_after>=COALESCE(i.first_claimed_at,'1970-01-01')");
        $q->execute([$cycleNo,self::GQAC_TRANSIENT_ATTEMPT_LIMIT]);return (int)$q->rowCount();
    }

    private function retireQuickBoardItemsForMatch(int $matchId,int $terminalStatus=204,?int $aboveBoardNo=null): int
    {
        if(!$this->gqacSchemaReady){try{$this->ensureGqacSchema();}catch(\Throwable $ignored){return 0;}}
        $sql="UPDATE p2k_g_quick_board_cycle_items SET status='completed',requeued_for_next=0,terminal_http_status=?,completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE match_id=? AND status='pending'";$params=[$terminalStatus,$matchId];if($aboveBoardNo!==null){$sql.=' AND board_no>?';$params[]=$aboveBoardNo;}$q=$this->core->prepare($sql);$q->execute($params);return (int)$q->rowCount();
    }

    public function ensureQuickBoardCycle(?int $cycleNo=null): array
    {
        $this->ensureGqacSchema();
        if($cycleNo===null){$s=$this->state();$cycleNo=(int)($s['cycle_no']??0);}
        if($cycleNo<=0)return $this->quickBoardCycleState(0);
        $exists=$this->core->prepare('SELECT COUNT(*) FROM p2k_g_quick_board_cycles WHERE cycle_no=?');$exists->execute([$cycleNo]);
        if((int)$exists->fetchColumn()===0){
            $lock='p2k_green_gqac_cycle';$got=(int)$this->core->query("SELECT GET_LOCK('{$lock}',2)")->fetchColumn();
            if($got===1){
                try{
                    $exists->execute([$cycleNo]);
                    if((int)$exists->fetchColumn()===0){
                        $this->core->beginTransaction();
                        try{
                            $this->core->prepare('INSERT INTO p2k_g_quick_board_cycles(cycle_no,initialized_at,total_items,updated_at) VALUES(?,UTC_TIMESTAMP(),0,UTC_TIMESTAMP())')->execute([$cycleNo]);
                            $q=$this->core->prepare("INSERT IGNORE INTO p2k_g_quick_board_cycle_items(cycle_no,match_id,board_no,admitted_hint_hash,status,updated_at)
                                SELECT ?,b.match_id,b.board_no,b.hint_hash,'pending',UTC_TIMESTAMP()
                                FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id
                                WHERE b.needs_refresh=1 AND (b.retry_after IS NULL OR b.retry_after<=UTC_TIMESTAMP())
                                  AND m.club_verified=1 AND m.time_class='daily' AND m.is_void=0
                                  AND m.status IN ('registered','in_progress','finished')");
                            $q->execute([$cycleNo]);$total=(int)$q->rowCount();
                            $this->core->prepare('UPDATE p2k_g_quick_board_cycles SET total_items=? WHERE cycle_no=?')->execute([$total,$cycleNo]);
                            $this->core->commit();
                            $cut=max(0,$cycleNo-32);
                            if($cut>0){
                                $this->core->prepare('DELETE FROM p2k_g_quick_board_cycle_items WHERE cycle_no<?')->execute([$cut]);
                                $this->core->prepare('DELETE FROM p2k_g_quick_board_cycles WHERE cycle_no<?')->execute([$cut]);
                            }
                        }catch(\Throwable $e){if($this->core->inTransaction())$this->core->rollBack();throw $e;}
                    }
                }finally{try{$this->core->query("SELECT RELEASE_LOCK('{$lock}')");}catch(\Throwable $ignored){}}
            }
        }
        $this->retireIneligibleQuickBoardItems($cycleNo);
        $this->retireExhaustedTransientQuickBoardItems($cycleNo);
        return $this->quickBoardCycleState($cycleNo);
    }

    public function quickBoardCycleState(?int $cycleNo=null,string $owner=''): array
    {
        $this->ensureGqacSchema();
        if($cycleNo===null){$s=$this->state();$cycleNo=(int)($s['cycle_no']??0);}
        if($cycleNo<=0)return ['planner_kind'=>'quick_boards','cycle_no'=>0,'initialized'=>false,'total'=>0,'completed'=>0,'pending'=>0,'due_unhydrated'=>0,'percent'=>100.0,'claimed_active'=>0,'eligible_now'=>0,'claimed_by_owner'=>0,'claimed'=>0,'claim_attempts'=>0,'unique_claimed_ids'=>0,'repeated_claimed_ids'=>0,'repeated_claims'=>0,'requeued_for_next'=>0,'net_completion'=>0];
        $this->retireIneligibleQuickBoardItems($cycleNo);
        $this->retireExhaustedTransientQuickBoardItems($cycleNo);
        $h=$this->core->prepare('SELECT total_items,initialized_at FROM p2k_g_quick_board_cycles WHERE cycle_no=?');$h->execute([$cycleNo]);$header=$h->fetch();
        if(!is_array($header))return ['planner_kind'=>'quick_boards','cycle_no'=>$cycleNo,'initialized'=>false,'total'=>0,'completed'=>0,'pending'=>0,'due_unhydrated'=>0,'percent'=>100.0,'claimed_active'=>0,'eligible_now'=>0,'claimed_by_owner'=>0,'claimed'=>0,'claim_attempts'=>0,'unique_claimed_ids'=>0,'repeated_claimed_ids'=>0,'repeated_claims'=>0,'requeued_for_next'=>0,'net_completion'=>0];
        $q=$this->core->prepare("SELECT COUNT(*) total,COALESCE(SUM(status='completed'),0) completed,COALESCE(SUM(claim_count),0) claim_attempts,COALESCE(SUM(claim_count>0),0) unique_claimed_ids,COALESCE(SUM(claim_count>1),0) repeated_claimed_ids,COALESCE(SUM(GREATEST(claim_count-1,0)),0) repeated_claims,COALESCE(SUM(requeued_for_next=1),0) requeued_for_next,COALESCE(SUM(status='completed' AND requeued_for_next=1 AND terminal_http_status IS NOT NULL AND terminal_http_status NOT IN (200,204,404,410)),0) deferred_transient,COALESCE(SUM(status='completed' AND terminal_http_status=204),0) retired_ineligible FROM p2k_g_quick_board_cycle_items WHERE cycle_no=?");$q->execute([$cycleNo]);$r=$q->fetch()?:[];
        $total=(int)($r['total']??$header['total_items']??0);$completed=(int)($r['completed']??0);$pending=max(0,$total-$completed);
        $where="i.cycle_no=? AND i.status='pending' AND (b.retry_after IS NULL OR b.retry_after<=UTC_TIMESTAMP())";
        $c=$this->core->prepare("SELECT COUNT(*) FROM p2k_g_quick_board_cycle_items i JOIN p2k_g_boards b ON b.match_id=i.match_id AND b.board_no=i.board_no JOIN p2k_g_work_claims w ON w.object_type='board_detail' AND w.object_key=CONCAT(i.match_id,':',i.board_no) AND w.claim_until>UTC_TIMESTAMP() WHERE {$where}");$c->execute([$cycleNo]);$claimed=(int)$c->fetchColumn();
        $e=$this->core->prepare("SELECT COUNT(*) FROM p2k_g_quick_board_cycle_items i JOIN p2k_g_boards b ON b.match_id=i.match_id AND b.board_no=i.board_no LEFT JOIN p2k_g_work_claims w ON w.object_type='board_detail' AND w.object_key=CONCAT(i.match_id,':',i.board_no) AND w.claim_until>UTC_TIMESTAMP() WHERE {$where} AND w.object_key IS NULL");$e->execute([$cycleNo]);$eligible=(int)$e->fetchColumn();
        $br=$this->core->prepare("SELECT COUNT(*) FROM p2k_g_quick_board_cycle_items i JOIN p2k_g_boards b ON b.match_id=i.match_id AND b.board_no=i.board_no WHERE i.cycle_no=? AND i.status='pending' AND b.retry_after>UTC_TIMESTAMP()");$br->execute([$cycleNo]);$blockedRetry=(int)$br->fetchColumn();
        $owned=0;if($owner!==''){$o=$this->core->prepare("SELECT COUNT(*) FROM p2k_g_quick_board_cycle_items i JOIN p2k_g_work_claims w ON w.object_type='board_detail' AND w.object_key=CONCAT(i.match_id,':',i.board_no) AND w.claim_until>UTC_TIMESTAMP() WHERE i.cycle_no=? AND i.status='pending' AND w.claimed_by=?");$o->execute([$cycleNo,$owner]);$owned=(int)$o->fetchColumn();}
        $requeued=(int)($r['requeued_for_next']??0);
        return ['planner_kind'=>'quick_boards','cycle_no'=>$cycleNo,'initialized'=>true,'initialized_at'=>(string)($header['initialized_at']??''),'total'=>$total,'completed'=>$completed,'pending'=>$pending,'due_unhydrated'=>$pending,'percent'=>$total>0?round(100*$completed/$total,2):100.0,'claimed_active'=>$claimed,'eligible_now'=>$eligible,'blocked_retry'=>$blockedRetry,'claimed_by_owner'=>$owned,'claimed'=>(int)($r['claim_attempts']??0),'claim_attempts'=>(int)($r['claim_attempts']??0),'unique_claimed_ids'=>(int)($r['unique_claimed_ids']??0),'repeated_claimed_ids'=>(int)($r['repeated_claimed_ids']??0),'repeated_claims'=>(int)($r['repeated_claims']??0),'requeued_for_next'=>$requeued,'deferred_transient'=>(int)($r['deferred_transient']??0),'retired_ineligible'=>(int)($r['retired_ineligible']??0),'net_completion'=>max(0,$completed-$requeued)];
    }

    private function claimQuickBoardRows(int $limit,string $owner,int $cycleNo): array
    {
        $this->ensureQuickBoardCycle($cycleNo);$limit=max(1,min(96,$limit));$lockName='p2k_green_board_planner';
        $got=(int)$this->core->query("SELECT GET_LOCK('{$lockName}',2)")->fetchColumn();if($got!==1)return [];
        try{
            $sql="SELECT b.match_id,b.board_no,b.board_url FROM p2k_g_quick_board_cycle_items i JOIN p2k_g_boards b ON b.match_id=i.match_id AND b.board_no=i.board_no LEFT JOIN p2k_g_work_claims c ON c.object_type='board_detail' AND c.object_key=CONCAT(b.match_id,':',b.board_no) AND c.claim_until>UTC_TIMESTAMP() WHERE i.cycle_no=".(int)$cycleNo." AND i.status='pending' AND c.object_key IS NULL AND (b.retry_after IS NULL OR b.retry_after<=UTC_TIMESTAMP()) ORDER BY CASE b.state WHEN 'unknown' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END,b.match_id DESC,b.board_no LIMIT {$limit}";
            $rows=$this->core->query($sql)->fetchAll()?:[];if(!$rows)return [];
            $claim=$this->core->prepare("INSERT INTO p2k_g_work_claims(object_type,object_key,claimed_by,claim_until,attempt_count,updated_at) VALUES('board_detail',?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE claimed_by=VALUES(claimed_by),claim_until=VALUES(claim_until),attempt_count=attempt_count+1,updated_at=UTC_TIMESTAMP()");
            $touch=$this->core->prepare("UPDATE p2k_g_quick_board_cycle_items SET claim_count=claim_count+1,first_claimed_at=COALESCE(first_claimed_at,UTC_TIMESTAMP()),last_claimed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE cycle_no=? AND match_id=? AND board_no=? AND status='pending'");
            foreach($rows as $r){$key=(int)$r['match_id'].':'.(int)$r['board_no'];$claim->execute([$key,$owner]);$touch->execute([$cycleNo,(int)$r['match_id'],(int)$r['board_no']]);}
            return $rows;
        }finally{try{$this->core->query("SELECT RELEASE_LOCK('{$lockName}')");}catch(\Throwable $ignored){}}
    }

    private function pendingQuickBoardItem(int $matchId,int $boardNo): ?array
    {
        $this->ensureGqacSchema();$s=$this->state();$cycle=(int)($s['cycle_no']??0);
        if($cycle<=0||(string)($s['mode']??'')!=='quick'||(string)($s['stage']??'')!=='quick_boards')return null;
        $q=$this->core->prepare("SELECT cycle_no,admitted_hint_hash,claim_count FROM p2k_g_quick_board_cycle_items WHERE cycle_no=? AND match_id=? AND board_no=? AND status='pending' LIMIT 1");$q->execute([$cycle,$matchId,$boardNo]);$r=$q->fetch();return is_array($r)?$r:null;
    }

    private function completeQuickBoardItem(?array $item,int $matchId,int $boardNo,int $httpStatus): void
    {
        if(!is_array($item))return;$q=$this->core->prepare('SELECT needs_refresh FROM p2k_g_boards WHERE match_id=? AND board_no=?');$q->execute([$matchId,$boardNo]);$requeue=(int)($q->fetchColumn()?:0)===1;
        $u=$this->core->prepare("UPDATE p2k_g_quick_board_cycle_items SET status='completed',requeued_for_next=?,terminal_http_status=?,completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE cycle_no=? AND match_id=? AND board_no=? AND status='pending'");$u->execute([$requeue?1:0,$httpStatus,(int)$item['cycle_no'],$matchId,$boardNo]);
    }

    /**
     * A finite Quick-board cohort must not be held open forever by a board that
     * repeatedly returns a transient HTTP result.  After a bounded number of
     * attempts, retire only the current-cycle obligation and leave needs_refresh
     * armed so a later Quick cycle can retry after the board retry_after cooldown.
     */
    public function deferQuickBoardTransientIfExhausted(int $matchId,int $boardNo,int $httpStatus): bool
    {
        $item=$this->pendingQuickBoardItem($matchId,$boardNo);
        if(!is_array($item) || (int)($item['claim_count']??0)<self::GQAC_TRANSIENT_ATTEMPT_LIMIT)return false;
        $q=$this->core->prepare("UPDATE p2k_g_quick_board_cycle_items SET status='completed',requeued_for_next=1,terminal_http_status=?,completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE cycle_no=? AND match_id=? AND board_no=? AND status='pending'");
        $q->execute([$httpStatus,(int)$item['cycle_no'],$matchId,$boardNo]);
        return $q->rowCount()>0;
    }

    private function claimBoardRows(int $limit,string $owner): array
    {
        $limit=max(1,min(96,$limit));$lockName='p2k_green_board_planner';
        $got=(int)$this->core->query("SELECT GET_LOCK('{$lockName}',2)")->fetchColumn();if($got!==1)return [];
        try{
            $sql="SELECT b.match_id,b.board_no,b.board_url FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id LEFT JOIN p2k_g_work_claims c ON c.object_type='board_detail' AND c.object_key=CONCAT(b.match_id,':',b.board_no) AND c.claim_until>UTC_TIMESTAMP() WHERE c.object_key IS NULL AND b.needs_refresh=1 AND (b.retry_after IS NULL OR b.retry_after<=UTC_TIMESTAMP()) AND m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress','finished') ORDER BY CASE b.state WHEN 'unknown' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END,b.match_id DESC,b.board_no LIMIT {$limit}";
            $rows=$this->core->query($sql)->fetchAll()?:[];if(!$rows)return [];
            $claim=$this->core->prepare("INSERT INTO p2k_g_work_claims(object_type,object_key,claimed_by,claim_until,attempt_count,updated_at) VALUES('board_detail',?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE claimed_by=VALUES(claimed_by),claim_until=VALUES(claim_until),attempt_count=attempt_count+1,updated_at=UTC_TIMESTAMP()");
            foreach($rows as $r)$claim->execute([(int)$r['match_id'].':'.(int)$r['board_no'],$owner]);
            return $rows;
        }finally{try{$this->core->query("SELECT RELEASE_LOCK('{$lockName}')");}catch(\Throwable $ignored){}}
    }

    private function claimMatchRows(int $limit,bool $seedOnly,string $owner): array
    {
        $limit=max(1,min(96,$limit));
        $state=$this->state();$cycle=(int)($state['cycle_no']??0);
        $lockName='p2k_green_match_planner';
        $got=(int)$this->core->query("SELECT GET_LOCK('{$lockName}',2)")->fetchColumn();
        if($got!==1)return [];
        try{
            $where=$seedOnly
                ? "m.status='unknown' AND m.payload_hash IS NULL"
                : "((m.status='unknown' AND (m.payload_hash IS NULL OR m.last_verified_at IS NULL OR m.last_verified_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))) OR (((m.index_bucket='in_progress' AND m.status<>'in_progress') OR (m.index_bucket='finished' AND m.status IN ('registered','in_progress','unknown')) OR (m.index_time_class IS NOT NULL AND m.index_time_class<>'' AND COALESCE(m.time_class,'')<>m.index_time_class)) AND (m.last_verified_at IS NULL OR m.last_verified_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE))))";
            $sql="SELECT m.match_id,m.api_url FROM p2k_g_matches m LEFT JOIN p2k_g_work_claims c ON c.object_type='match_detail' AND CAST(c.object_key AS UNSIGNED)=m.match_id AND c.claim_until>UTC_TIMESTAMP() WHERE c.object_key IS NULL AND (m.retry_after IS NULL OR m.retry_after<=UTC_TIMESTAMP()) AND ({$where}) ORDER BY CASE WHEN m.payload_hash IS NULL THEN 0 ELSE 1 END, MOD(m.match_id + ".($cycle*7919).",104729),m.match_id DESC LIMIT {$limit}";
            $rows=$this->core->query($sql)->fetchAll()?:[];
            if(!$rows)return [];
            $claim=$this->core->prepare("INSERT INTO p2k_g_work_claims(object_type,object_key,claimed_by,claim_until,attempt_count,updated_at) VALUES('match_detail',?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE claimed_by=VALUES(claimed_by),claim_until=VALUES(claim_until),attempt_count=attempt_count+1,updated_at=UTC_TIMESTAMP()");
            foreach($rows as $r)$claim->execute([(string)$r['match_id'],$owner]);
            return $rows;
        }finally{try{$this->core->query("SELECT RELEASE_LOCK('{$lockName}')");}catch(\Throwable $ignored){}}
    }

    public function nextSeedMatch(): ?int
    {
        $rows=$this->claimMatchRows(1,true,'worker-seed');
        return $rows? (int)$rows[0]['match_id'] : null;
    }

    public function nextUnknownMatch(): ?int
    {
        $rows=$this->claimMatchRows(1,false,'worker-general');
        return $rows? (int)$rows[0]['match_id'] : null;
    }

    public function hasSeedMatch(): bool
    {
        return (int)$this->core->query("SELECT COUNT(*) FROM p2k_g_matches WHERE status='unknown' AND payload_hash IS NULL AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP())")->fetchColumn()>0;
    }

    public function hasUnknownMatch(): bool
    {
        return (int)$this->core->query("SELECT COUNT(*) FROM p2k_g_matches WHERE (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP()) AND (((status='unknown') AND (payload_hash IS NULL OR last_verified_at IS NULL OR last_verified_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))) OR (((index_bucket='in_progress' AND status='registered') OR (index_bucket='finished' AND status IN ('registered','in_progress'))) AND (last_verified_at IS NULL OR last_verified_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))))")->fetchColumn()>0;
    }

    public function activeMatchIds(): array { return array_map('intval',$this->core->query("SELECT match_id FROM p2k_g_matches WHERE status='in_progress' ORDER BY match_id DESC")->fetchAll(PDO::FETCH_COLUMN)?:[]); }

    public function nextActiveDueForCycle(string $cycleStartedAt): ?int
    {
        $q=$this->core->prepare("SELECT match_id FROM p2k_g_matches WHERE status='in_progress' AND (last_verified_at IS NULL OR last_verified_at<?) AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP()) ORDER BY match_id DESC LIMIT 1");
        $q->execute([$cycleStartedAt]);$v=$q->fetchColumn();return $v===false?null:(int)$v;
    }


    public function nextCurrentMaintenanceDue(int $staleSeconds=300): ?int
    {
        $staleSeconds=max(60,min(3600,$staleSeconds));$cut=gmdate('Y-m-d H:i:s',time()-$staleSeconds);
        $q=$this->core->prepare("SELECT match_id FROM p2k_g_matches WHERE discovery_index=1 AND trusted_legacy=0 AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP()) AND (club_verified=0 OR (index_bucket='registered' AND status='unknown') OR (index_bucket='finished' AND status IN ('registered','in_progress','unknown')) OR (index_bucket='in_progress' AND status IN ('registered','unknown')) OR (index_time_class IS NOT NULL AND index_time_class<>'' AND COALESCE(time_class,'')<>index_time_class) OR (status='in_progress' AND (last_verified_at IS NULL OR last_verified_at<?))) ORDER BY CASE WHEN index_bucket='finished' AND status<>'finished' THEN 0 WHEN index_time_class IS NOT NULL AND index_time_class<>'' AND COALESCE(time_class,'')<>index_time_class THEN 1 ELSE 2 END,match_id DESC LIMIT 1");
        $q->execute([$cut]);$v=$q->fetchColumn();return $v===false?null:(int)$v;
    }

    public function currentMaintenanceCount(int $staleSeconds=300): int
    {
        $staleSeconds=max(60,min(3600,$staleSeconds));$cut=gmdate('Y-m-d H:i:s',time()-$staleSeconds);
        $q=$this->core->prepare("SELECT COUNT(*) FROM p2k_g_matches WHERE discovery_index=1 AND trusted_legacy=0 AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP()) AND (club_verified=0 OR (index_bucket='registered' AND status='unknown') OR (index_bucket='finished' AND status IN ('registered','in_progress','unknown')) OR (index_bucket='in_progress' AND status IN ('registered','unknown')) OR (index_time_class IS NOT NULL AND index_time_class<>'' AND COALESCE(time_class,'')<>index_time_class) OR (status='in_progress' AND (last_verified_at IS NULL OR last_verified_at<?)))");
        $q->execute([$cut]);return (int)$q->fetchColumn();
    }

    public function nextFinishedColdDueForCycle(int $seconds,string $cycleStartedAt): ?int
    {
        $cutoff=gmdate('Y-m-d H:i:s',time()-max(86400,$seconds));
        $q=$this->core->prepare("SELECT match_id FROM p2k_g_matches WHERE trusted_legacy=0 AND club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0 AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP()) AND (last_verified_at IS NULL OR last_verified_at<?) AND (last_verified_at IS NULL OR last_verified_at<?) ORDER BY COALESCE(last_verified_at,'1970-01-01'),match_id LIMIT 1");
        $q->execute([$cutoff,$cycleStartedAt]);$v=$q->fetchColumn();return $v===false?null:(int)$v;
    }

    public function metricCount(int $cycle,string $type,string $source='worker'): int
    {
        $q=$this->core->prepare('SELECT COALESCE(SUM(request_count),0) FROM p2k_g_request_metrics WHERE cycle_no=? AND request_type=? AND source=?');
        $q->execute([$cycle,$type,$source]);return (int)$q->fetchColumn();
    }

    public function nextBoardNeedingRefresh(): ?array
    {
        $rows=$this->claimBoardRows(1,'worker-board');
        return $rows?$rows[0]:null;
    }

    public function nextQuickBoardNeedingRefresh(int $cycleNo): ?array
    {
        $rows=$this->claimQuickBoardRows(1,'worker-board',$cycleNo);
        return $rows?$rows[0]:null;
    }

    public function boardCountPending(): int { return (int)$this->core->query("SELECT COUNT(*) FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id WHERE b.needs_refresh=1 AND m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress','finished')")->fetchColumn(); }
    public function unknownMatchCount(): int { return (int)$this->core->query("SELECT COUNT(*) FROM p2k_g_matches WHERE status='unknown' OR (trusted_legacy=0 AND ((index_bucket='in_progress' AND status='registered') OR (index_bucket='finished' AND status IN ('registered','in_progress')) OR (index_time_class IS NOT NULL AND index_time_class<>'' AND COALESCE(time_class,'')<>index_time_class)))")->fetchColumn(); }

    public function storeMatch(int $id,array $payload,int $httpStatus=200): array
    {
        $oldQ=$this->core->prepare('SELECT payload_hash,trusted_legacy,status,time_class,board_count,p2k_score,opponent_score,result,competition_points,is_void,scoring_eligible,exclusion_reason,fact_source FROM p2k_g_matches WHERE match_id=?');
        $oldQ->execute([$id]);$old=$oldQ->fetch()?:[];$oldHash=$old['payload_hash']??null;$trusted=(int)($old['trusted_legacy']??0)===1;
        $teams=array_values(is_array($payload['teams']??null)?$payload['teams']:[]);$hits=[];
        foreach($teams as $idx=>$team){
            if(!is_array($team))continue;$hit=false;
            foreach(['@id','url'] as $field){if($this->isExactClubRef((string)($team[$field]??''))){$hit=true;break;}}
            if($hit)$hits[]=$idx;
        }
        if(count($hits)!==1){
            if($trusted){
                // The verified legacy CSV is the authority for historical P2K participation.
                // A later PubAPI payload must never invalidate or purge a locked historical fact.
                $this->core->prepare('UPDATE p2k_g_matches SET last_http_status=?,last_observed_at=UTC_TIMESTAMP(),last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=?')->execute([$httpStatus,$id]);
                $this->releaseMatchClaim($id);
                return ['status'=>(string)($old['status']??'finished'),'changed'=>false,'club_verified'=>true,'trusted_legacy'=>true,'observation_rejected'=>'exact_club_mismatch'];
            }
            $this->core->prepare("UPDATE p2k_g_matches SET status='not_club',club_verified=0,verified_club_slug=NULL,club_side=NULL,scoring_eligible=0,exclusion_reason='not_club',competition_points=0,last_http_status=?,last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=?")->execute([$httpStatus,$id]);
            $this->purgeMatchDerivedData($id);$this->releaseMatchClaim($id);
            return ['status'=>'not_club','changed'=>true,'club_verified'=>false];
        }
        $clubIdx=(int)$hits[0];$club=$teams[$clubIdx];$opp=null;
        foreach($teams as $idx=>$team){if($idx!==$clubIdx&&is_array($team)){$opp=$team;break;}}
        if(!is_array($opp)){
            if($trusted){
                $this->core->prepare('UPDATE p2k_g_matches SET last_http_status=?,last_observed_at=UTC_TIMESTAMP(),last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=?')->execute([$httpStatus,$id]);
                $this->releaseMatchClaim($id);
                return ['status'=>(string)($old['status']??'finished'),'changed'=>false,'club_verified'=>true,'trusted_legacy'=>true,'observation_rejected'=>'opponent_missing'];
            }
            $this->core->prepare("UPDATE p2k_g_matches SET status='unavailable',club_verified=0,scoring_eligible=0,exclusion_reason='opponent_missing',competition_points=0,last_http_status=?,last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=?")->execute([$httpStatus,$id]);
            $this->purgeMatchDerivedData($id);$this->releaseMatchClaim($id);
            return ['status'=>'unavailable','changed'=>true,'club_verified'=>false];
        }

        $rawStatus=$this->normalizeMatchStatus((string)($payload['status']??'unknown'));
        $settings=is_array($payload['settings']??null)?$payload['settings']:[];
        $timeClass=strtolower(trim((string)($settings['time_class']??$payload['time_class']??'')));
        $boards=max(0,(int)($payload['boards']??0));
        $score=is_numeric($club['score']??null)?(float)$club['score']:null;$oppScore=is_numeric($opp['score']??null)?(float)$opp['score']:null;
        $void=$timeClass==='daily'&&$rawStatus==='finished'&&$score!==null&&$oppScore!==null&&abs($score)<.001&&abs($oppScore)<.001;
        $status=$void?'cancelled':$rawStatus;
        $result=($rawStatus==='finished'&&!$void)?self::scoreResult($score,$oppScore):'none';
        $eligible=$timeClass==='daily'&&$rawStatus==='finished'&&!$void&&$boards>0&&$score!==null&&$oppScore!==null;
        $points=0;if($eligible){if($result==='win')$points=5*$boards;elseif($result==='draw')$points=2*$boards;}
        $exclusion=$eligible?null:($timeClass!==''&&$timeClass!=='daily'?'non_daily':($void?'finished_0_0':($rawStatus!=='finished'?'not_finished':'incomplete_fact')));
        $hash=hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES));

        $changed=$oldHash!==$hash;
        if($trusted){
            $status=(string)($old['status']??'finished');$timeClass=(string)($old['time_class']??'daily');$boards=(int)($old['board_count']??0);
            $score=is_numeric($old['p2k_score']??null)?(float)$old['p2k_score']:null;$oppScore=is_numeric($old['opponent_score']??null)?(float)$old['opponent_score']:null;
            $result=(string)($old['result']??'none');$points=(int)($old['competition_points']??0);$void=(int)($old['is_void']??0)===1;$eligible=(int)($old['scoring_eligible']??0)===1;
            $exclusion=$old['exclusion_reason']??null;
        }

        $q=$this->core->prepare('UPDATE p2k_g_matches SET web_url=?,name=?,opponent_name=?,opponent_url=?,status=?,club_verified=1,verified_club_slug=?,club_side=?,scoring_eligible=?,exclusion_reason=?,fact_source=?,rules=?,time_class=?,time_control=?,start_epoch=?,end_epoch=CASE WHEN trusted_legacy=1 THEN end_epoch ELSE ? END,board_count=?,p2k_score=?,opponent_score=?,result=?,competition_points=?,is_void=?,payload_hash=?,last_http_status=?,last_observed_at=UTC_TIMESTAMP(),last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=?');
        $q->execute([(string)($payload['url']??''),(string)($payload['name']??''),(string)($opp['name']??''),(string)($opp['url']??$opp['@id']??''),$status,$this->clubSlug,'team_'.$clubIdx,$eligible?1:0,$exclusion,$trusted?'trusted_legacy_csv':'api',(string)($settings['rules']??''),$timeClass,(string)($settings['time_control']??$settings['time_per_move']??''),is_numeric($payload['start_time']??null)?(int)$payload['start_time']:null,is_numeric($payload['end_time']??null)?(int)$payload['end_time']:null,$boards?:null,$score,$oppScore,$result,$points,$void?1:0,$hash,$httpStatus,$id]);

        $boardEligible=$timeClass==='daily'&&!$void&&in_array($status,['registered','in_progress','finished'],true)&&$boards>0;
        if(!$boardEligible){
            $this->purgeMatchDerivedData($id);$this->releaseMatchClaim($id);
            return ['status'=>$status,'changed'=>$changed,'boards'=>$boards,'void'=>$void,'points'=>$points,'time_class'=>$timeClass,'scoring_eligible'=>$eligible,'trusted_legacy'=>$trusted];
        }

        $this->retireQuickBoardItemsForMatch($id,204,$boards);
        $this->core->prepare('DELETE FROM p2k_g_point_events WHERE match_id=? AND board_no>?')->execute([$id,$boards]);
        $this->core->prepare('DELETE FROM p2k_g_games WHERE match_id=? AND board_no>?')->execute([$id,$boards]);
        $this->core->prepare('DELETE FROM p2k_g_boards WHERE match_id=? AND board_no>?')->execute([$id,$boards]);
        $this->core->prepare('DELETE FROM p2k_g_match_players WHERE match_id=? AND board_no>?')->execute([$id,$boards]);

        $clubPlayers=is_array($club['players']??null)?$club['players']:[];$oppPlayers=is_array($opp['players']??null)?$opp['players']:[];$byBoard=[];
        // v2.10.6.18: match players are the CURRENT authoritative lineup snapshot, not
        // a registration-history table. Registration board allocation is mutable and
        // username aliases can also move between observations. Retire rows that are no
        // longer present on a side before upserting the latest snapshot. Games/point
        // events remain untouched historical evidence. If Chess.com returns an empty
        // side, preserve the prior snapshot defensively rather than deleting it.
        foreach([[1,$clubPlayers],[0,$oppPlayers]] as $pair){$isP2k=$pair[0];$players=$pair[1];$currentKeys=[];foreach($players as $p){if(!is_array($p))continue;$u=trim((string)($p['username']??''));if($u!=='')$currentKeys[strtolower($u)]=true;}if($currentKeys){$marks=implode(',',array_fill(0,count($currentKeys),'?'));$args=array_merge([$id,$isP2k],array_keys($currentKeys));$this->core->prepare("DELETE FROM p2k_g_match_players WHERE match_id=? AND is_p2k=? AND username_key NOT IN ({$marks})")->execute($args);}foreach($players as $idx=>$p){if(!is_array($p))continue;$u=trim((string)($p['username']??''));if($u==='')continue;$k=strtolower($u);$url=trim((string)($p['board']??''));$b=$idx+1;if($url!==''&&preg_match('/\/(\d+)\/?$/',$url,$mm))$b=(int)$mm[1];if($b<1||$b>$boards)continue;$hint=hash('sha256',json_encode([$p['played_as_white']??null,$p['played_as_black']??null,$p['rating']??$p['start_rating']??null,$p['status']??null,$p['result']??null],JSON_UNESCAPED_SLASHES));$this->core->prepare('INSERT INTO p2k_g_match_players(match_id,username_key,username,is_p2k,board_no,board_url,played_as_white,played_as_black,start_rating,hint_hash,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),is_p2k=VALUES(is_p2k),board_no=VALUES(board_no),board_url=VALUES(board_url),played_as_white=VALUES(played_as_white),played_as_black=VALUES(played_as_black),start_rating=VALUES(start_rating),hint_hash=VALUES(hint_hash),updated_at=UTC_TIMESTAMP()')->execute([$id,$k,$u,$isP2k,$b,$url?:null,$p['played_as_white']??null,$p['played_as_black']??null,is_numeric($p['rating']??$p['start_rating']??null)?(int)($p['rating']??$p['start_rating']):null,$hint]);if($isP2k){$this->core->prepare('INSERT INTO p2k_g_players(username,username_key,current_member,created_at,updated_at) VALUES(?,?,0,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),updated_at=UTC_TIMESTAMP()')->execute([$u,$k]);}}}
        foreach([['p2k',$clubPlayers],['opp',$oppPlayers]] as $pair){$side=$pair[0];$players=$pair[1];foreach($players as $idx=>$p){if(!is_array($p))continue;$url=trim((string)($p['board']??''));$b=$idx+1;if($url!==''&&preg_match('/\/(\d+)\/?$/',$url,$mm))$b=(int)$mm[1];if($b<1||$b>$boards)continue;$byBoard[$b][$side]=$p;$byBoard[$b]['url']=$url?:('https://api.chess.com/pub/match/'.$id.'/'.$b);}}
        for($b=1;$b<=$boards;$b++){$a=$byBoard[$b]['p2k']??[];$o=$byBoard[$b]['opp']??[];$url=(string)($byBoard[$b]['url']??('https://api.chess.com/pub/match/'.$id.'/'.$b));$hint=hash('sha256',json_encode([$a['played_as_white']??null,$a['played_as_black']??null,$a['status']??null,$a['result']??null,$o['played_as_white']??null,$o['played_as_black']??null,$o['status']??null,$o['result']??null],JSON_UNESCAPED_SLASHES));$existing=$this->core->prepare('SELECT hint_hash,state,finished_game_count FROM p2k_g_boards WHERE match_id=? AND board_no=?');$existing->execute([$id,$b]);$oldBoard=$existing->fetch();$oldHint=is_array($oldBoard)?($oldBoard['hint_hash']??null):false;$terminal=is_array($oldBoard)&&strtolower((string)($oldBoard['state']??''))==='finished'&&(int)($oldBoard['finished_game_count']??0)>=2;$needs=$terminal?0:(($oldHint===false||$oldHint!==$hint)?1:0);$this->core->prepare("INSERT INTO p2k_g_boards(match_id,board_no,board_url,state,hint_hash,needs_refresh,updated_at) VALUES(?,?,?,'unknown',?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE board_url=VALUES(board_url),hint_hash=VALUES(hint_hash),needs_refresh=GREATEST(needs_refresh,VALUES(needs_refresh)),updated_at=UTC_TIMESTAMP()")->execute([$id,$b,$url,$hint,$needs]);}
        // GICL: when the parent match crosses into finished, incomplete boards get one
        // final authoritative refresh even if Chess.com's player hint fields did not change.
        $oldStatus=strtolower((string)($old['status']??''));
        if($status==='finished'&&$oldStatus!=='finished'){
            $this->core->prepare("UPDATE p2k_g_boards SET needs_refresh=1,retry_after=NULL,updated_at=UTC_TIMESTAMP() WHERE match_id=? AND state IN ('unknown','in_progress')")->execute([$id]);
        }
        $this->releaseMatchClaim($id);
        return ['status'=>$status,'changed'=>$changed,'boards'=>$boards,'void'=>$void,'points'=>$points,'time_class'=>$timeClass,'scoring_eligible'=>$eligible,'trusted_legacy'=>$trusted];
    }

    public function markMatchHttp(int $id,int $status,string $error=''): void
    {
        $q=$this->core->prepare('SELECT trusted_legacy FROM p2k_g_matches WHERE match_id=?');$q->execute([$id]);$trusted=(int)($q->fetchColumn()?:0)===1;
        if($trusted){$this->core->prepare('UPDATE p2k_g_matches SET last_http_status=?,last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=?')->execute([$status,$id]);if(in_array($status,[404,410],true))$this->completeGfflMatch($id);$this->releaseMatchClaim($id);return;}
        if($status===404){$this->core->prepare("UPDATE p2k_g_matches SET status='cancelled',is_void=1,result='none',club_verified=1,verified_club_slug=?,scoring_eligible=0,exclusion_reason='http_404',fact_source='api_404',competition_points=0,last_http_status=404,last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=?")->execute([$this->clubSlug,$id]);$this->purgeMatchDerivedData($id);$this->completeGfflMatch($id);$this->releaseMatchClaim($id);return;}
        if($status===410){$this->core->prepare("UPDATE p2k_g_matches SET status='unavailable',scoring_eligible=0,exclusion_reason='http_410',competition_points=0,last_http_status=410,last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=?")->execute([$id]);$this->purgeMatchDerivedData($id);$this->completeGfflMatch($id);$this->releaseMatchClaim($id);return;}
        $delay=$status===429?300:120;$retry=gmdate('Y-m-d H:i:s',time()+$delay);$this->core->prepare('UPDATE p2k_g_matches SET last_http_status=?,retry_after=? WHERE match_id=?')->execute([$status,$retry,$id]);$this->releaseMatchClaim($id);
    }

    private static function gamePoints(?string $result): ?float
    {
        $r=strtolower((string)$result);if($r==='win')return 1.0;if(in_array($r,['agreed','repetition','stalemate','insufficient','50move','timevsinsufficient'],true))return .5;if(in_array($r,['checkmated','timeout','resigned','lose','abandoned','kingofthehill','threecheck','bughousepartnerlose'],true))return 0.0;return null;
    }

    public function storeBoard(int $matchId,int $boardNo,array $payload,int $httpStatus=200): array
    {
        $mq=$this->core->prepare('SELECT club_verified,time_class,status,is_void FROM p2k_g_matches WHERE match_id=?');$mq->execute([$matchId]);$mr=$mq->fetch();
        $eligible=is_array($mr)&&(int)($mr['club_verified']??0)===1&&strtolower((string)($mr['time_class']??''))==='daily'&&(int)($mr['is_void']??0)===0&&in_array((string)($mr['status']??''),['registered','in_progress','finished'],true);
        if(!$eligible){
            $this->core->prepare('DELETE FROM p2k_g_point_events WHERE match_id=? AND board_no=?')->execute([$matchId,$boardNo]);
            $state=is_array($mr)&&(int)($mr['is_void']??0)===1?'cancelled':'unavailable';$gqac=$this->pendingQuickBoardItem($matchId,$boardNo);
            if(is_array($gqac))$this->core->prepare('UPDATE p2k_g_boards SET state=?,needs_refresh=CASE WHEN hint_hash <=> ? THEN 0 ELSE 1 END,last_http_status=?,last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=? AND board_no=?')->execute([$state,$gqac['admitted_hint_hash']??null,$httpStatus,$matchId,$boardNo]);
            else $this->core->prepare('UPDATE p2k_g_boards SET state=?,needs_refresh=0,last_http_status=?,last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=? AND board_no=?')->execute([$state,$httpStatus,$matchId,$boardNo]);
            $this->completeQuickBoardItem($gqac,$matchId,$boardNo,$httpStatus);$this->releaseBoardClaim($matchId,$boardNo);return ['finished_games'=>0,'state'=>$state,'p2k_points'=>0,'changed'=>false,'excluded'=>true];
        }
        $games=is_array($payload['games']??null)?$payload['games']:[];$finished=0;$p2kBoard=0.0;$pairedRatingObserved=false;$hash=hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES));$whiteName=null;$blackName=null;$start=null;$end=null;
        $old=$this->core->prepare('SELECT payload_hash FROM p2k_g_boards WHERE match_id=? AND board_no=?');$old->execute([$matchId,$boardNo]);$oldHash=$old->fetchColumn();$changed=$oldHash!==$hash;
        foreach($games as $idx=>$game){if(!is_array($game))continue;$w=is_array($game['white']??null)?$game['white']:[];$b=is_array($game['black']??null)?$game['black']:[];$whiteName=$whiteName??trim((string)($w['username']??''));$blackName=$blackName??trim((string)($b['username']??''));$start=is_numeric($game['start_time']??null)?(int)$game['start_time']:$start;$end=is_numeric($game['end_time']??null)?max((int)($end??0),(int)$game['end_time']):$end;$wp=self::gamePoints($w['result']??null);$bp=self::gamePoints($b['result']??null);if($wp!==null&&$bp!==null)$finished++;
            if(is_numeric($w['rating']??null)&&(int)$w['rating']>0&&is_numeric($b['rating']??null)&&(int)$b['rating']>0)$pairedRatingObserved=true;
            $url=trim((string)($game['url']??$game['@id']??('https://api.chess.com/pub/match/'.$matchId.'/'.$boardNo.'/'.($idx+1))));
            $this->core->prepare('INSERT INTO p2k_g_games(game_url,match_id,board_no,game_index,white_username,black_username,white_rating,black_rating,white_result,black_result,start_epoch,end_epoch,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE white_username=VALUES(white_username),black_username=VALUES(black_username),white_rating=VALUES(white_rating),black_rating=VALUES(black_rating),white_result=VALUES(white_result),black_result=VALUES(black_result),start_epoch=VALUES(start_epoch),end_epoch=VALUES(end_epoch),updated_at=UTC_TIMESTAMP()')->execute([$url,$matchId,$boardNo,$idx+1,$w['username']??null,$b['username']??null,is_numeric($w['rating']??null)?(int)$w['rating']:null,is_numeric($b['rating']??null)?(int)$b['rating']:null,$w['result']??null,$b['result']??null,is_numeric($game['start_time']??null)?(int)$game['start_time']:null,is_numeric($game['end_time']??null)?(int)$game['end_time']:null]);
            foreach([[$w,$wp],[$b,$bp]] as $pair){$side=$pair[0];$pts=$pair[1];if($pts===null||!is_array($side))continue;$username=trim((string)($side['username']??''));if($username==='')continue;$team=(string)($side['team']??'');$isP2k=$this->isExactClubRef($team);if(!$isP2k){$q=$this->core->prepare('SELECT is_p2k FROM p2k_g_match_players WHERE match_id=? AND username_key=? LIMIT 1');$q->execute([$matchId,strtolower($username)]);$isP2k=(int)($q->fetchColumn()?:0)===1;}
                if($isP2k){$p2kBoard+=$pts;$this->core->prepare('INSERT INTO p2k_g_point_events(game_url,username_key,username,match_id,board_no,points,result,event_epoch,updated_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),points=VALUES(points),result=VALUES(result),event_epoch=VALUES(event_epoch),updated_at=UTC_TIMESTAMP()')->execute([$url,strtolower($username),$username,$matchId,$boardNo,$pts,(string)($side['result']??''),is_numeric($game['end_time']??null)?(int)$game['end_time']:null]);}
            }
        }
        $state=$finished>=2?'finished':'in_progress';$gqac=$this->pendingQuickBoardItem($matchId,$boardNo);
        if(is_array($gqac))$this->core->prepare('UPDATE p2k_g_boards SET state=?,white_username=?,black_username=?,start_epoch=?,end_epoch=?,p2k_board_score=?,opponent_board_score=?,finished_game_count=?,payload_hash=?,needs_refresh=CASE WHEN hint_hash <=> ? THEN 0 ELSE 1 END,last_http_status=?,last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=? AND board_no=?')->execute([$state,$whiteName?:null,$blackName?:null,$start,$end,$p2kBoard,max(0,2-$p2kBoard),$finished,$hash,$gqac['admitted_hint_hash']??null,$httpStatus,$matchId,$boardNo]);
        else $this->core->prepare('UPDATE p2k_g_boards SET state=?,white_username=?,black_username=?,start_epoch=?,end_epoch=?,p2k_board_score=?,opponent_board_score=?,finished_game_count=?,payload_hash=?,needs_refresh=0,last_http_status=?,last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=? AND board_no=?')->execute([$state,$whiteName?:null,$blackName?:null,$start,$end,$p2kBoard,max(0,2-$p2kBoard),$finished,$hash,$httpStatus,$matchId,$boardNo]);
        $this->completeQuickBoardItem($gqac,$matchId,$boardNo,$httpStatus);$this->releaseBoardClaim($matchId,$boardNo);
        return ['finished_games'=>$finished,'state'=>$state,'p2k_points'=>$p2kBoard,'changed'=>$changed,'paired_rating_observed'=>$pairedRatingObserved];
    }

    public function markBoardHttp(int $matchId,int $boardNo,int $status): void
    {
        if(in_array($status,[404,410],true)){$gqac=$this->pendingQuickBoardItem($matchId,$boardNo);if(is_array($gqac))$this->core->prepare("UPDATE p2k_g_boards SET state='unavailable',needs_refresh=CASE WHEN hint_hash <=> ? THEN 0 ELSE 1 END,last_http_status=?,last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=? AND board_no=?")->execute([$gqac['admitted_hint_hash']??null,$status,$matchId,$boardNo]);else $this->core->prepare("UPDATE p2k_g_boards SET state='unavailable',needs_refresh=0,last_http_status=?,last_verified_at=UTC_TIMESTAMP(),retry_after=NULL WHERE match_id=? AND board_no=?")->execute([$status,$matchId,$boardNo]);$this->completeQuickBoardItem($gqac,$matchId,$boardNo,$status);$this->releaseBoardClaim($matchId,$boardNo);return;}
        $delay=$status===429?300:120;$retry=gmdate('Y-m-d H:i:s',time()+$delay);$this->core->prepare('UPDATE p2k_g_boards SET last_http_status=?,retry_after=? WHERE match_id=? AND board_no=?')->execute([$status,$retry,$matchId,$boardNo]);$this->releaseBoardClaim($matchId,$boardNo);
    }

    public function nextProfileDue(): ?string
    {
        $q=$this->core->query("SELECT username FROM p2k_g_players WHERE current_member=1 AND profile_checked_at IS NULL ORDER BY id LIMIT 1");$v=$q->fetchColumn();return $v===false?null:(string)$v;
    }
    public function nextStatsUnseen(): ?string
    {
        $v=$this->core->query("SELECT username FROM p2k_g_players WHERE current_member=1 AND stats_checked_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
        return $v===false?null:(string)$v;
    }

    public function nextStatsDue(int $seconds): ?string
    {
        $cutoff=gmdate('Y-m-d H:i:s',time()-max(1,$seconds));
        $q=$this->core->prepare("SELECT username FROM p2k_g_players WHERE current_member=1 AND (stats_checked_at IS NULL OR stats_checked_at<?) ORDER BY COALESCE(stats_checked_at,'1970-01-01'),id LIMIT 1");
        $q->execute([$cutoff]);$v=$q->fetchColumn();return $v===false?null:(string)$v;
    }

    public function nextStatsDueForCycle(int $seconds,string $cycleStartedAt): ?string
    {
        $cutoff=gmdate('Y-m-d H:i:s',strtotime($cycleStartedAt.' UTC')-$seconds);
        $q=$this->core->prepare("SELECT username FROM p2k_g_players WHERE current_member=1 AND (stats_checked_at IS NULL OR stats_checked_at<?) ORDER BY COALESCE(stats_checked_at,'1970-01-01'),id LIMIT 1");
        $q->execute([$cutoff]);$v=$q->fetchColumn();return $v===false?null:(string)$v;
    }

    public function quickProfileProgress(): array
    {
        $r=$this->core->query("SELECT COUNT(*) total,COALESCE(SUM(profile_checked_at IS NULL),0) due FROM p2k_g_players WHERE current_member=1")->fetch()?:[];
        $total=(int)($r['total']??0);$due=(int)($r['due']??0);
        $departure=(int)$this->core->query("SELECT COUNT(*) FROM p2k_g_member_events WHERE event_type='left' AND profile_status='pending'")->fetchColumn();
        return ['total'=>$total,'completed'=>max(0,$total-$due),'due'=>$due,'departure_due'=>$departure,'percent'=>$total>0?round(100*max(0,$total-$due)/$total,2):100.0];
    }

    public function quickStatsProgress(int $seconds,string $cycleStartedAt): array
    {
        $ts=strtotime($cycleStartedAt.' UTC');if($ts===false)$ts=time();
        $cutoff=gmdate('Y-m-d H:i:s',$ts-max(1,$seconds));
        $q=$this->core->prepare("SELECT COUNT(*) total,COALESCE(SUM(stats_checked_at IS NULL OR stats_checked_at<?),0) due FROM p2k_g_players WHERE current_member=1");
        $q->execute([$cutoff]);$r=$q->fetch()?:[];$total=(int)($r['total']??0);$due=(int)($r['due']??0);
        return ['total'=>$total,'completed'=>max(0,$total-$due),'due'=>$due,'cutoff'=>$cutoff,'percent'=>$total>0?round(100*max(0,$total-$due)/$total,2):100.0];
    }

    /**
     * v2.10.6.16 cycle-boundary self-heal.  A historical finite tail pass could
     * advance a new Quick cycle before startCycle() created its GQAC cohort.
     * If an active Quick cycle is already beyond quick_boards with no cohort,
     * rewind only that active cycle to quick_boards and let the normal planner
     * rebuild the invariant.  No source data is deleted or reseeded.
     */
    public function repairQuickBoardCycleInvariant(): array
    {
        $s=$this->state();$cycle=(int)($s['cycle_no']??0);$stage=(string)($s['stage']??'');
        if((string)($s['mode']??'')!=='quick'||$cycle<=0||$s['cycle_started_at']===null||!in_array($stage,['quick_profiles','quick_stats'],true))return ['repaired'=>false,'state'=>$s];
        $gqac=$this->quickBoardCycleState($cycle);
        if(!empty($gqac['initialized']))return ['repaired'=>false,'state'=>$s,'gqac'=>$gqac];
        $gqac=$this->ensureQuickBoardCycle($cycle);
        $this->core->beginTransaction();
        try{
            $this->core->prepare("UPDATE p2k_g_state SET stage='quick_boards',last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND cycle_no=? AND cycle_started_at IS NOT NULL")->execute([$this->clubSlug,$cycle]);
            $this->core->prepare("UPDATE p2k_g_cycles SET stage='quick_boards' WHERE cycle_no=? AND status='running'")->execute([$cycle]);
            $this->core->prepare("DELETE FROM p2k_g_phase_progress WHERE cycle_no=? AND phase_key IN ('quick_profiles','quick_stats')")->execute([$cycle]);
            $this->core->commit();
        }catch(\Throwable $e){if($this->core->inTransaction())$this->core->rollBack();throw $e;}
        $this->recordPhaseProgress('quick_boards','Quick Boards',(int)($gqac['pending']??0)>0?'running':'completed',(int)($gqac['completed']??0),(int)($gqac['total']??0),['self_healed_missing_gqac'=>true,'recovered_from'=>$stage]);
        return ['repaired'=>true,'cycle_no'=>$cycle,'from'=>$stage,'to'=>'quick_boards','gqac'=>$gqac,'state'=>$this->state()];
    }

    /**
     * Checkpoint terminal Chess.com profile responses.
     * 404/410 are authoritative observations for both the initial seed and any
     * later refresh path; leaving an old timestamp untouched can keep a finite
     * cycle selecting the same identity forever. Transient responses are not checkpointed.
     */
    public function markProfileHttp(string $username,int $status): bool
    {
        if(!in_array($status,[404,410],true))return false;
        $q=$this->core->prepare("UPDATE p2k_g_players SET profile_checked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE current_member=1 AND username_key=?");
        $q->execute([strtolower($username)]);
        return $q->rowCount()>0;
    }

    /**
     * Stats refreshes have the same terminal-response requirement.
     * Marking 404/410 checked stores no invented rating and advances the finite
     * cycle checkpoint; later cycles can revisit the identity naturally.
     */
    public function markStatsHttp(string $username,int $status): bool
    {
        if(!in_array($status,[404,410],true))return false;
        $q=$this->core->prepare("UPDATE p2k_g_players SET stats_checked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE current_member=1 AND username_key=?");
        $q->execute([strtolower($username)]);
        return $q->rowCount()>0;
    }

    public function storeProfile(string $username,array $p): array
    {
        $k=strtolower($username);$pid=is_numeric($p['player_id']??null)?(int)$p['player_id']:null;$status=(string)($p['status']??'');
        $current=$this->core->prepare('SELECT id,chess_player_id,username FROM p2k_g_players WHERE username_key=? LIMIT 1');$current->execute([$k]);$row=$current->fetch();if(!is_array($row))return ['changed'=>false];
        if($pid!==null){$other=$this->core->prepare('SELECT id,username_key,username,current_member,joined_epoch,last_seen_roster_at FROM p2k_g_players WHERE chess_player_id=? AND id<>? LIMIT 1');$other->execute([$pid,(int)$row['id']]);$o=$other->fetch();if(is_array($o)){
                // Rename: delete the transient row first so the stable row can safely adopt the new unique username_key.
                $this->core->beginTransaction();try{
                    $transient=$this->core->prepare('SELECT current_member,joined_epoch,last_seen_roster_at FROM p2k_g_players WHERE id=? FOR UPDATE');$transient->execute([(int)$row['id']]);$t=$transient->fetch()?:[];
                    $this->core->prepare('DELETE FROM p2k_g_players WHERE id=?')->execute([(int)$row['id']]);
                    $this->core->prepare('UPDATE p2k_g_players SET username=?,username_key=?,current_member=?,joined_epoch=COALESCE(?,joined_epoch),left_at=NULL,last_seen_roster_at=COALESCE(?,last_seen_roster_at),profile_checked_at=UTC_TIMESTAMP(),account_status=?,country_url=?,avatar_url=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$username,$k,max((int)($o['current_member']??0),(int)($t['current_member']??0)),$t['joined_epoch']??null,$t['last_seen_roster_at']??null,$status,$p['country']??null,$p['avatar']??null,(int)$o['id']]);
                    $this->core->prepare('INSERT INTO p2k_g_player_aliases(chess_player_id,username_key,username,first_seen_at,last_seen_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),last_seen_at=UTC_TIMESTAMP()')->execute([$pid,$k,$username]);
                    // Stable player_id is authoritative for future Green rename observations.
                    $aliases=$this->core->prepare('SELECT username_key,username FROM p2k_g_player_aliases WHERE chess_player_id=?');$aliases->execute([$pid]);
                    $imap=$this->core->prepare("INSERT INTO p2k_g_identity_map(username_key,username,canonical_username_key,canonical_username,chess_player_id,source,trusted,source_ref,imported_at) VALUES(?,?,?,?,?,'green_player_id',1,'player_id',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),canonical_username_key=VALUES(canonical_username_key),canonical_username=VALUES(canonical_username),chess_player_id=VALUES(chess_player_id),source=CASE WHEN trusted=1 AND source='blue_miac_trusted' THEN source ELSE VALUES(source) END,trusted=1,updated_at=UTC_TIMESTAMP()");
                    foreach($aliases->fetchAll()?:[] as $a)$imap->execute([(string)$a['username_key'],(string)$a['username'],$k,$username,$pid]);
                    $this->recordMemberEvent('name_changed',(int)$o['id'],$username,(string)($o['username']??''),$username,null,'green_player_id',['chess_player_id'=>$pid]);
                    // The new username existed only as a transient roster identity while the
                    // stable Chess.com player_id was being confirmed.  Collapse those temporary
                    // lifecycle interpretations into the single authoritative rename event.
                    $this->core->prepare("DELETE FROM p2k_g_member_events WHERE identity_id=? AND event_type IN ('discovered','joined') AND detected_at>=UTC_TIMESTAMP()-INTERVAL 48 HOUR")->execute([(int)$row['id']]);
                    $this->core->prepare("DELETE FROM p2k_g_member_events WHERE event_id IN (SELECT event_id FROM (SELECT event_id FROM p2k_g_member_events WHERE identity_id=? AND event_type='left' AND detected_at>=UTC_TIMESTAMP()-INTERVAL 48 HOUR ORDER BY detected_at DESC,event_id DESC LIMIT 1) recent_false_departure)")->execute([(int)$o['id']]);
                    $this->core->commit();return ['changed'=>true,'rename'=>true];
                }catch(\Throwable $e){if($this->core->inTransaction())$this->core->rollBack();throw $e;}}
        }
        $this->core->prepare('UPDATE p2k_g_players SET chess_player_id=COALESCE(?,chess_player_id),account_status=?,country_url=?,avatar_url=?,profile_checked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$pid,$status,$p['country']??null,$p['avatar']??null,(int)$row['id']]);
        if($pid!==null){
            $this->core->prepare('INSERT INTO p2k_g_player_aliases(chess_player_id,username_key,username,first_seen_at,last_seen_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),last_seen_at=UTC_TIMESTAMP()')->execute([$pid,$k,$username]);
            $this->core->prepare("INSERT INTO p2k_g_identity_map(username_key,username,canonical_username_key,canonical_username,chess_player_id,source,trusted,source_ref,imported_at) VALUES(?,?,?,?,?,'green_player_id',1,'player_id',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),chess_player_id=VALUES(chess_player_id),updated_at=UTC_TIMESTAMP()")->execute([$k,$username,$k,$username,$pid]);
        }
        return ['changed'=>true,'rename'=>false];
    }

    public function storeStats(string $username,array $s): array
    {
        $daily=$s['chess_daily']['last']['rating']??null;$r960=$s['chess960_daily']['last']['rating']??$s['chess960']['last']['rating']??null;
        $this->core->prepare('UPDATE p2k_g_players SET daily_rating=?,chess960_rating=?,stats_checked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE username_key=?')->execute([is_numeric($daily)?(int)$daily:null,is_numeric($r960)?(int)$r960:null,strtolower($username)]);
        return ['daily'=>is_numeric($daily)?(int)$daily:null,'chess960'=>is_numeric($r960)?(int)$r960:null];
    }

    public function configureDeep(?int $from,?int $to,string $reason='discovery_gap'): array
    {
        if($from===null||$to===null||$to<=$from)throw new RuntimeException('Deep scan requires a finite from/to range with to > from.');
        $s=$this->state();$cycle=(int)($s['cycle_no']??0);
        $this->core->beginTransaction();
        try{
            if($cycle>0 && $s['cycle_started_at']!==null){
                $this->core->prepare("UPDATE p2k_g_cycles SET status='cancelled',completed_at=UTC_TIMESTAMP(),summary_json=? WHERE cycle_no=? AND status='running'")->execute([json_encode(['transition'=>'deep','reason'=>$reason],JSON_UNESCAPED_SLASHES),$cycle]);
            }
            $this->core->prepare("UPDATE p2k_g_state SET mode='deep',stage='deep_scan',deep_scan_from=?,deep_scan_to=?,deep_scan_cursor=?,cycle_started_at=NULL,cycle_kind=NULL,last_error=NULL WHERE club_slug=?")->execute([$from,$to,$from,$this->clubSlug]);
            $this->core->commit();return $this->state();
        }catch(\Throwable $e){if($this->core->inTransaction())$this->core->rollBack();throw $e;}
    }

    public function nextDeepId(): ?int
    {
        $s=$this->state();$cur=(int)($s['deep_scan_cursor']??0);$to=(int)($s['deep_scan_to']??0);if($cur<=0||$to<=0||$cur>=$to)return null;$next=$cur+1;$this->core->prepare('UPDATE p2k_g_state SET deep_scan_cursor=? WHERE club_slug=?')->execute([$next,$this->clubSlug]);return $next;
    }

    public function deepRemaining(): int
    {
        $s=$this->state();$cur=(int)($s['deep_scan_cursor']??0);$to=(int)($s['deep_scan_to']??0);
        return ($cur>0&&$to>$cur)?($to-$cur):0;
    }

    public function configureSeedGap(int $from,int $to): void
    {
        if($to<=$from)return;
        $this->core->prepare("UPDATE p2k_g_state SET stage='seed_deep_scan',deep_scan_from=?,deep_scan_to=?,deep_scan_cursor=? WHERE club_slug=?")->execute([$from,$to,$from,$this->clubSlug]);
    }

    public function deepDiscovered(int $id): void
    {
        $this->core->prepare("INSERT INTO p2k_g_matches(match_id,api_url,status,discovery_deep,created_at,updated_at) VALUES(?,?,'unknown',1,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE discovery_deep=1,updated_at=UTC_TIMESTAMP()")->execute([$id,'https://api.chess.com/pub/match/'.$id]);
    }

    public function startInvocation(?int $cycleNo,?string $mode,?string $stage): int
    {
        $q=$this->core->prepare("INSERT INTO p2k_g_invocations(cycle_no,mode,stage_start,status,started_at) VALUES(?,?,?,'running',UTC_TIMESTAMP())");
        $q->execute([$cycleNo,$mode,$stage]);return (int)$this->core->lastInsertId();
    }

    public function finishInvocation(int $id,string $status,float $startedAt,array $summary=[]): void
    {
        $state=$this->state();$finish=(array)($summary['finish_state']??[]);unset($summary['finish_state']);$runtime=max(0,(int)round((microtime(true)-$startedAt)*1000));
        $requestCount=(int)($summary['request_count']??0);$cycle=(int)($finish['cycle_no']??$state['cycle_no']??0);$mode=(string)($finish['mode']??$state['mode']??'');$stage=(string)($finish['stage']??$state['stage']??'');
        $this->core->prepare('UPDATE p2k_g_invocations SET cycle_no=COALESCE(cycle_no,?),mode=COALESCE(mode,?),stage_finish=?,status=?,completed_at=UTC_TIMESTAMP(),runtime_ms=?,request_count=?,summary_json=? WHERE invocation_id=?')->execute([$cycle,$mode,$stage,$status,$runtime,$requestCount,json_encode($summary,JSON_UNESCAPED_SLASHES),$id]);
    }

    public function cycleMetrics(int $cycleNo): array
    {
        if($cycleNo<=0)return [];
        $q=$this->core->prepare('SELECT request_type,source,outcome,request_count FROM p2k_g_request_metrics WHERE cycle_no=? ORDER BY request_type,source,outcome');$q->execute([$cycleNo]);return $q->fetchAll()?:[];
    }

    public function progressSnapshot(): array
    {
        $s=$this->state();$mode=(string)$s['mode'];$stage=(string)$s['stage'];$cycleStart=(string)($s['cycle_started_at']??'');
        $matches=$this->core->query("SELECT COUNT(*) total,SUM(status='unknown') unknown,SUM(status='registered') registered,SUM(status='in_progress') in_progress,SUM(status='finished') finished,SUM(status='cancelled') cancelled,SUM(status='not_club') not_club,SUM(status='unavailable') unavailable FROM p2k_g_matches")->fetch()?:[];
        $boards=$this->core->query("SELECT COUNT(*) total,SUM(needs_refresh=1) pending,SUM(state='unknown') unknown,SUM(state='in_progress') in_progress,SUM(state='finished') finished,SUM(state='unavailable') unavailable FROM p2k_g_boards")->fetch()?:[];
        $players=$this->core->query("SELECT COUNT(*) total,SUM(current_member=1) current_members,SUM(current_member=1 AND profile_checked_at IS NULL) profiles_pending,SUM(current_member=1 AND stats_checked_at IS NULL) initial_stats_pending FROM p2k_g_players")->fetch()?:[];
        $findings=(int)$this->core->query('SELECT COUNT(*) FROM p2k_g_findings')->fetchColumn();
        $activeRemaining=0;if($cycleStart!==''){$q=$this->core->prepare("SELECT COUNT(*) FROM p2k_g_matches WHERE status='in_progress' AND (last_verified_at IS NULL OR last_verified_at<?)");$q->execute([$cycleStart]);$activeRemaining=(int)$q->fetchColumn();}
        $deepRemaining=$this->deepRemaining();$quickBoardCycle=[];$quickProfileProgress=[];$quickStatsProgress=[];
        if($mode==='quick'&&(int)($s['cycle_no']??0)>0){$quickBoardCycle=$stage==='quick_boards'?$this->ensureQuickBoardCycle((int)$s['cycle_no']):$this->quickBoardCycleState((int)$s['cycle_no']);}
        if($mode==='quick'&&$stage==='quick_profiles')$quickProfileProgress=$this->quickProfileProgress();
        if($mode==='quick'&&$stage==='quick_stats'&&$cycleStart!=='')$quickStatsProgress=$this->quickStatsProgress(max(3600,(int)($this->config['app']['stats_refresh_seconds']??259200)),$cycleStart);
        $percent=null;
        if($mode==='seeding'){
            $weights=['seed_index_roster'=>2,'seed_deep_scan'=>8,'seed_matches'=>38,'seed_boards'=>78,'seed_profiles'=>90,'seed_stats'=>96];$base=$weights[$stage]??0;
            if($stage==='seed_matches' && (int)$matches['total']>0)$percent=38+40*(1-((int)$matches['unknown']/max(1,(int)$matches['total'])));
            elseif($stage==='seed_boards' && (int)$boards['total']>0)$percent=78+12*(1-((int)$boards['pending']/max(1,(int)$boards['total'])));
            elseif($stage==='seed_profiles' && (int)$players['current_members']>0)$percent=90+6*(1-((int)$players['profiles_pending']/max(1,(int)$players['current_members'])));
            elseif($stage==='seed_stats' && (int)$players['current_members']>0)$percent=96+4*(1-((int)$players['initial_stats_pending']/max(1,(int)$players['current_members'])));
            else $percent=(float)$base;
        }elseif($mode==='quick'&&$stage==='quick_boards'){$percent=(float)($quickBoardCycle['percent']??100.0);}
        elseif($mode==='quick'&&$stage==='quick_profiles'){$percent=(float)($quickProfileProgress['percent']??0.0);}
        elseif($mode==='quick'&&$stage==='quick_stats'){$percent=(float)($quickStatsProgress['percent']??0.0);}
        elseif($mode==='deep'){$from=(int)($s['deep_scan_from']??0);$to=(int)($s['deep_scan_to']??0);$cur=(int)($s['deep_scan_cursor']??$from);$percent=$to>$from?max(0,min(100,100*($cur-$from)/($to-$from))):0;}
        return ['mode'=>$mode,'stage'=>$stage,'progress_percent'=>$percent,'findings'=>$findings,'matches'=>$matches,'boards'=>$boards,'players'=>$players,'quick_board_cycle'=>$quickBoardCycle,'quick_profile_progress'=>$quickProfileProgress,'quick_stats_progress'=>$quickStatsProgress,'active_matches_remaining_this_cycle'=>$activeRemaining,'deep_remaining_ids'=>$deepRemaining];
    }


    public function observationMetric(int $cycleNo,string $source,string $type,int $accepted=0,int $ignored=0,int $changed=0,int $avoided=0): void
    {
        if($cycleNo<=0)return;
        $q=$this->core->prepare("INSERT INTO p2k_g_observation_metrics(cycle_no,source,object_type,accepted,ignored_stale,changed,worker_requests_avoided) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE accepted=accepted+VALUES(accepted),ignored_stale=ignored_stale+VALUES(ignored_stale),changed=changed+VALUES(changed),worker_requests_avoided=worker_requests_avoided+VALUES(worker_requests_avoided)");
        $q->execute([$cycleNo,substr($source,0,32),substr($type,0,32),$accepted,$ignored,$changed,$avoided]);
    }

    public function observationMetrics(int $cycleNo): array
    {
        if($cycleNo<=0)return [];$q=$this->core->prepare('SELECT source,object_type,accepted,ignored_stale,changed,worker_requests_avoided FROM p2k_g_observation_metrics WHERE cycle_no=? ORDER BY source,object_type');$q->execute([$cycleNo]);return $q->fetchAll()?:[];
    }

    /**
     * Lightweight browser-stage handoff used by the rolling migration accelerator.
     * It never fetches data and never forces a transition while authoritative work
     * for the current stage is still due.
     */
    public function advanceBrowserStageIfDrained(): array
    {
        $s=$this->state();
        $from=(string)($s['stage']??'');
        $to=$from;
        $advanced=false;
        $reason='stage_not_browser_advanceable';

        if($from==='seed_matches'){
            if($this->hasSeedMatch())$reason='seed_matches_still_due';
            else{$to='seed_boards';$this->stage($to);$advanced=true;$reason='seed_matches_drained';}
        }elseif($from==='seed_boards'){
            if($this->boardCountPending()>0)$reason='seed_boards_still_due';
            else{$to='seed_profiles';$this->stage($to);$advanced=true;$reason='seed_boards_drained';}
        }elseif($from==='quick_discovered'){
            if($this->hasUnknownMatch())$reason='quick_discovered_still_due';
            else{$to='quick_matches';$this->stage($to);$advanced=true;$reason='quick_discovered_drained';}
        }elseif($from==='quick_boards'){
            $gqac=$this->ensureQuickBoardCycle((int)($s['cycle_no']??0));
            if((int)($gqac['pending']??0)>0)$reason='quick_boards_cycle_still_due';
            else{$to='quick_profiles';$this->stage($to);$advanced=true;$reason='quick_boards_cycle_drained';}
        }

        return [
            'advanced'=>$advanced,
            'from_stage'=>$from,
            'to_stage'=>$to,
            'reason'=>$reason,
            'cycle_no'=>(int)($s['cycle_no']??0),
            'mode'=>(string)($s['mode']??''),
        ];
    }

    /**
     * Browser planner diagnostics for the initial seed match lane.
     * Counts intentionally separate due work from active leases so the UI can
     * distinguish a drained stage from a temporarily claimed backlog.
     */
    public function seedMatchPlannerState(string $owner=''): array
    {
        $dueSql="SELECT COUNT(*) FROM p2k_g_matches WHERE status='unknown' AND payload_hash IS NULL AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP())";
        $due=(int)$this->core->query($dueSql)->fetchColumn();
        $claimedSql="SELECT COUNT(*) FROM p2k_g_matches m JOIN p2k_g_work_claims c ON c.object_type='match_detail' AND CAST(c.object_key AS UNSIGNED)=m.match_id AND c.claim_until>UTC_TIMESTAMP() WHERE m.status='unknown' AND m.payload_hash IS NULL AND (m.retry_after IS NULL OR m.retry_after<=UTC_TIMESTAMP())";
        $claimed=(int)$this->core->query($claimedSql)->fetchColumn();
        $eligible=max(0,$due-$claimed);
        $owned=0;
        if($owner!==''&&strpos($owner,'browser-')===0){
            $q=$this->core->prepare("SELECT COUNT(*) FROM p2k_g_matches m JOIN p2k_g_work_claims c ON c.object_type='match_detail' AND CAST(c.object_key AS UNSIGNED)=m.match_id AND c.claim_until>UTC_TIMESTAMP() WHERE m.status='unknown' AND m.payload_hash IS NULL AND (m.retry_after IS NULL OR m.retry_after<=UTC_TIMESTAMP()) AND c.claimed_by=?");
            $q->execute([$owner]);$owned=(int)$q->fetchColumn();
        }
        return ['planner_kind'=>'matches','due_unhydrated'=>$due,'claimed_active'=>$claimed,'eligible_now'=>$eligible,'claimed_by_owner'=>$owned];
    }


    /** Browser planner diagnostics for board hydration. */
    public function boardPlannerState(string $owner=''): array
    {
        $s=$this->state();if((string)($s['mode']??'')==='quick'&&(string)($s['stage']??'')==='quick_boards'){$this->ensureQuickBoardCycle((int)($s['cycle_no']??0));return $this->quickBoardCycleState((int)($s['cycle_no']??0),$owner);}
        $where="b.needs_refresh=1 AND (b.retry_after IS NULL OR b.retry_after<=UTC_TIMESTAMP()) AND m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress','finished')";
        $due=(int)$this->core->query("SELECT COUNT(*) FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id WHERE {$where}")->fetchColumn();
        $claimed=(int)$this->core->query("SELECT COUNT(*) FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id JOIN p2k_g_work_claims c ON c.object_type='board_detail' AND c.object_key=CONCAT(b.match_id,':',b.board_no) AND c.claim_until>UTC_TIMESTAMP() WHERE {$where}")->fetchColumn();
        $eligible=max(0,$due-$claimed);$owned=0;
        if($owner!==''&&strpos($owner,'browser-')===0){
            $q=$this->core->prepare("SELECT COUNT(*) FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id JOIN p2k_g_work_claims c ON c.object_type='board_detail' AND c.object_key=CONCAT(b.match_id,':',b.board_no) AND c.claim_until>UTC_TIMESTAMP() WHERE {$where} AND c.claimed_by=?");
            $q->execute([$owner]);$owned=(int)$q->fetchColumn();
        }
        return ['planner_kind'=>'boards','due_unhydrated'=>$due,'claimed_active'=>$claimed,'eligible_now'=>$eligible,'claimed_by_owner'=>$owned];
    }

    /**
     * A user-started migration accelerator is the single browser owner of the
     * Green match seed lane.  Taking over clears browser-only leases left by a
     * stopped/reloaded accelerator; worker/CRON leases are never touched.
     */
    public function takeBrowserClaimLane(string $owner): int
    {
        if(strpos($owner,'browser-')!==0)throw new RuntimeException('Invalid browser claim owner.');
        $q=$this->core->prepare("DELETE FROM p2k_g_work_claims WHERE object_type IN ('match_detail','board_detail') AND claimed_by LIKE 'browser-%'");$q->execute();return (int)$q->rowCount();
    }

    public function releaseBrowserClaims(string $owner): int
    {
        if(strpos($owner,'browser-')!==0)return 0;
        $q=$this->core->prepare("DELETE FROM p2k_g_work_claims WHERE object_type IN ('match_detail','board_detail') AND claimed_by=?");$q->execute([$owner]);return (int)$q->rowCount();
    }

    public function recordPhaseProgress(string $phaseKey,string $label,string $status='running',int $completed=0,int $total=0,array $detail=[]): void
    {
        $s=$this->state();$cycle=(int)($s['cycle_no']??0);if($cycle<=0)return;
        if(!in_array($status,['pending','running','completed','error'],true))$status='running';
        $q=$this->core->prepare("INSERT INTO p2k_g_phase_progress(cycle_no,phase_key,label,status,completed_units,total_units,started_at,completed_at,last_update_at,detail_json) VALUES(?,?,?,?,?,?,CASE WHEN ?='running' THEN UTC_TIMESTAMP() ELSE NULL END,CASE WHEN ?='completed' THEN UTC_TIMESTAMP() ELSE NULL END,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE label=VALUES(label),status=VALUES(status),completed_units=VALUES(completed_units),total_units=VALUES(total_units),started_at=CASE WHEN VALUES(status)='running' THEN COALESCE(started_at,UTC_TIMESTAMP()) ELSE started_at END,completed_at=CASE WHEN VALUES(status)='completed' THEN UTC_TIMESTAMP() ELSE completed_at END,last_update_at=UTC_TIMESTAMP(),detail_json=VALUES(detail_json)");
        $json=$detail?json_encode($detail,JSON_UNESCAPED_SLASHES):null;$q->execute([$cycle,$phaseKey,$label,$status,max(0,$completed),max(0,$total),$status,$status,$json]);
    }

    public function phaseProgressSnapshot(?int $cycleNo=null): array
    {
        if($cycleNo===null)$cycleNo=(int)($this->state()['cycle_no']??0);if($cycleNo<=0)return [];
        $q=$this->core->prepare('SELECT cycle_no,phase_key,label,status,completed_units,total_units,started_at,completed_at,last_update_at,detail_json FROM p2k_g_phase_progress WHERE cycle_no=? ORDER BY started_at IS NULL,started_at,phase_key');$q->execute([$cycleNo]);return $q->fetchAll()?:[];
    }

    /** GFFL: factorized match-level freshness debt. Higher priority values win. */
    public function armGfflMatch(int $matchId,string $reason='freshness_due',int $priority=40,bool $hot=false): void
    {
        if($matchId<=0)return;$s=$this->state();if((int)($s['gffl_enabled']??1)!==1)return;
        $priority=max(1,min(100,$hot?max(90,$priority):$priority));$reason=substr(trim($reason),0,80);if($reason==='')$reason='freshness_due';
        $target=max(60,(int)($s['gffl_target_freshness_seconds']??1200));
        $q=$this->core->prepare("SELECT last_verified_at,status,club_verified,time_class,is_void,last_http_status FROM p2k_g_matches WHERE match_id=? LIMIT 1");$q->execute([$matchId]);$m=$q->fetch();if(!is_array($m)|| (int)($m['club_verified']??0)!==1 || (string)($m['time_class']??'')!=='daily' || (int)($m['is_void']??0)===1)return;
        // v2.10.6.20 terminal-debt rule: 404/410 is terminal for the current freshness
        // obligation, including a legitimate registration cancellation. Reappearance in the
        // authoritative club index clears last_http_status before this method is called again.
        if(in_array((int)($m['last_http_status']??0),[404,410],true))return;
        if(!in_array((string)($m['status']??''),['registered','in_progress','finished'],true))return;
        $due=$hot?gmdate('Y-m-d H:i:s'):gmdate('Y-m-d H:i:s',max(time(),(($m['last_verified_at']??null)?(strtotime((string)$m['last_verified_at'].' UTC')?:time()):time())+$target));
        $json=json_encode([$reason],JSON_UNESCAPED_SLASHES);
        $sql="INSERT INTO p2k_g_gffl_match_debt(match_id,priority,reasons_json,obligation_count,coalesced_count,status,detected_at,due_at,updated_at) VALUES(?,?,?,1,0,'pending',UTC_TIMESTAMP(),?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE priority=GREATEST(priority,VALUES(priority)),reasons_json=CASE WHEN JSON_CONTAINS(COALESCE(reasons_json,'[]'),JSON_QUOTE(?),'$') THEN reasons_json ELSE JSON_ARRAY_APPEND(COALESCE(reasons_json,'[]'),'$',?) END,coalesced_count=coalesced_count+IF(status='pending',1,0),obligation_count=obligation_count+1,due_at=CASE WHEN status='completed' THEN VALUES(due_at) ELSE LEAST(due_at,VALUES(due_at)) END,status='pending',completed_at=NULL,updated_at=UTC_TIMESTAMP()";
        $this->core->prepare($sql)->execute([$matchId,$priority,$json,$due,$reason,$reason]);
    }

    public function completeGfflMatch(int $matchId): void
    {
        if($matchId<=0)return;$this->core->prepare("UPDATE p2k_g_gffl_match_debt SET status='completed',last_served_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE match_id=? AND status='pending'")->execute([$matchId]);
    }

    /**
     * Close debt that can no longer be serviced. This is deliberately idempotent and does
     * not delete trusted match/game/point facts. It also repairs debt stranded by versions
     * before v2.10.6.20, where terminal or browser-served observations could leave pending
     * GFFL rows behind indefinitely.
     */
    private function retireIneligibleGfflDebt(): int
    {
        $sql="UPDATE p2k_g_gffl_match_debt d LEFT JOIN p2k_g_matches m ON m.match_id=d.match_id SET d.status='completed',d.completed_at=UTC_TIMESTAMP(),d.updated_at=UTC_TIMESTAMP() WHERE d.status='pending' AND (m.match_id IS NULL OR m.last_http_status IN (404,410) OR COALESCE(m.club_verified,0)<>1 OR COALESCE(m.time_class,'')<>'daily' OR COALESCE(m.is_void,0)=1 OR COALESCE(m.status,'') NOT IN ('registered','in_progress','finished'))";
        return (int)$this->core->exec($sql);
    }

    public function gfflPlan(int $limit=24): array
    {
        $this->retireIneligibleGfflDebt();
        $limit=max(1,min(72,$limit));$q=$this->core->query("SELECT d.match_id,d.priority,d.reasons_json,d.obligation_count,d.coalesced_count,d.detected_at,d.due_at,m.api_url FROM p2k_g_gffl_match_debt d JOIN p2k_g_matches m ON m.match_id=d.match_id WHERE d.status='pending' AND d.due_at<=UTC_TIMESTAMP() AND m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress','finished') AND COALESCE(m.last_http_status,0) NOT IN (404,410) AND (m.retry_after IS NULL OR m.retry_after<=UTC_TIMESTAMP()) ORDER BY d.priority DESC,d.due_at,d.match_id DESC LIMIT {$limit}");return $q->fetchAll()?:[];
    }

    public function gfflSnapshot(): array
    {
        try{$this->retireIneligibleGfflDebt();$r=$this->core->query("SELECT COUNT(*) total,COALESCE(SUM(d.status='pending'),0) pending,COALESCE(SUM(d.status='pending' AND d.due_at<=UTC_TIMESTAMP()),0) due,COALESCE(SUM(d.status='pending' AND d.priority>=90),0) hot,COALESCE(SUM(d.coalesced_count),0) coalesced,COALESCE(SUM(d.obligation_count),0) obligations,MIN(CASE WHEN d.status='pending' THEN d.due_at END) oldest_due,MAX(d.last_served_at) last_served_at,COALESCE(SUM(d.status='completed' AND m.last_http_status IN (404,410)),0) terminal_closed FROM p2k_g_gffl_match_debt d LEFT JOIN p2k_g_matches m ON m.match_id=d.match_id")->fetch()?:[];return ['enabled'=>(int)($this->state()['gffl_enabled']??1)===1,'target_seconds'=>(int)($this->state()['gffl_target_freshness_seconds']??1200),'total'=>(int)($r['total']??0),'pending'=>(int)($r['pending']??0),'due'=>(int)($r['due']??0),'hot'=>(int)($r['hot']??0),'coalesced'=>(int)($r['coalesced']??0),'obligations'=>(int)($r['obligations']??0),'oldest_due'=>$r['oldest_due']??null,'last_served_at'=>$r['last_served_at']??null,'terminal_closed'=>(int)($r['terminal_closed']??0),'fetches_saved'=>(int)($r['coalesced']??0)];}catch(\Throwable $e){return ['enabled'=>false,'error'=>$e->getMessage()];}
    }

    /**
     * Historical opponent-heatmap backfill. Historical match-detail payloads do not
     * contain ratings, so v2.10.6.19 queues only board resources still lacking a
     * stored game with a valid rating pair. Existing Green games are consumed locally
     * by GreenCompatibility before any Chess.com request is needed.
     */
    public function startHeatmapBackfill(bool $restart=false): array
    {
        // v2.10.6.18 used the wrong resource. Retire that obsolete ledger safely.
        $this->core->exec("DELETE FROM p2k_g_gab_external_work WHERE kind='heatmap_match_detail'");
        if($restart)$this->core->exec("DELETE FROM p2k_g_gab_external_work WHERE kind='heatmap_board_detail'");
        else $this->core->exec("UPDATE p2k_g_gab_external_work SET status='pending',attempts=0,retry_after=NULL,last_error=NULL WHERE kind='heatmap_board_detail' AND status='failed'");
        $sql="INSERT IGNORE INTO p2k_g_gab_external_work(work_key,kind,url,status)
              SELECT CONCAT('heatmap:board:',gm.match_id,':',gb.board_no),'heatmap_board_detail',gb.board_url,'pending'
                FROM p2k_g_matches gm
                JOIN p2k_g_boards gb ON gb.match_id=gm.match_id
                LEFT JOIN p2k_tp_match_metadata cm ON cm.club_slug=? AND cm.match_id=gm.match_id
               WHERE gm.club_verified=1 AND gm.time_class='daily' AND gm.status='finished' AND gm.is_void=0
                 AND gb.board_url IS NOT NULL AND gb.board_url<>''
                 AND (cm.match_id IS NULL OR COALESCE(cm.p2k_avg_rating,0)<=0 OR COALESCE(cm.opponent_avg_rating,0)<=0 OR COALESCE(cm.rated_board_count,0)<=0)
                 AND NOT EXISTS(SELECT 1 FROM p2k_g_games gg WHERE gg.match_id=gb.match_id AND gg.board_no=gb.board_no AND COALESCE(gg.white_rating,0)>0 AND COALESCE(gg.black_rating,0)>0)";
        $q=$this->core->prepare($sql);$q->execute([$this->clubSlug]);
        return ['queued_new'=>(int)$q->rowCount(),'restart'=>$restart,'status'=>$this->heatmapBackfillSnapshot()];
    }

    public function heatmapBackfillPlan(int $limit=36): array
    {
        $limit=max(1,min(72,$limit));$q=$this->core->query("SELECT work_key,kind,url FROM p2k_g_gab_external_work WHERE kind='heatmap_board_detail' AND status='pending' AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP()) ORDER BY attempts,CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(work_key,':',-2),':',1) AS UNSIGNED) DESC,CAST(SUBSTRING_INDEX(work_key,':',-1) AS UNSIGNED) LIMIT {$limit}");return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    private function heatmapWorkKeyFromUrl(string $url): ?string
    {
        $path=(string)(parse_url($url,PHP_URL_PATH)?:'');
        if(!preg_match('~/pub/match/(\d+)/(\d+)$~i',rtrim($path,'/'),$m))return null;
        return 'heatmap:board:'.(int)$m[1].':'.(int)$m[2];
    }

    public function completeHeatmapBackfill(string $url,int $httpStatus=200,string $error=''): void
    {
        $key=$this->heatmapWorkKeyFromUrl($url);if($key===null)return;
        if(in_array($httpStatus,[404,410,422],true)){$this->core->prepare("UPDATE p2k_g_gab_external_work SET status='failed',attempts=attempts+1,last_http_status=?,retry_after=NULL,last_error=? WHERE work_key=? AND kind='heatmap_board_detail'")->execute([$httpStatus,substr($error!==''?$error:'HTTP '.$httpStatus,0,1000),$key]);return;}
        $this->core->prepare("UPDATE p2k_g_gab_external_work SET status='completed',attempts=attempts+1,last_http_status=200,retry_after=NULL,last_error=NULL WHERE work_key=? AND kind='heatmap_board_detail'")->execute([$key]);
    }

    public function failHeatmapBackfill(string $url,int $status,string $error=''): void
    {
        $key=$this->heatmapWorkKeyFromUrl($url);if($key===null)return;$delay=$status===429?300:180;
        $this->core->prepare("UPDATE p2k_g_gab_external_work SET attempts=attempts+1,last_http_status=?,last_error=?,retry_after=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),status=CASE WHEN attempts>=5 THEN 'failed' ELSE 'pending' END WHERE work_key=? AND kind='heatmap_board_detail'")->execute([$status,substr($error,0,1000),$delay,$key]);
    }

    public function heatmapBackfillSnapshot(): array
    {
        try{
            $q=$this->core->query("SELECT COUNT(*) total,COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='completed'),0) completed,COALESCE(SUM(status='failed'),0) failed,MIN(CASE WHEN status='pending' THEN updated_at END) oldest_pending,MAX(updated_at) last_activity FROM p2k_g_gab_external_work WHERE kind='heatmap_board_detail'");$w=$q->fetch()?:[];
            $c=$this->core->prepare("SELECT COUNT(*) finished_matches,COALESCE(SUM(COALESCE(cm.p2k_avg_rating,0)>0 AND COALESCE(cm.opponent_avg_rating,0)>0 AND COALESCE(cm.rated_board_count,0)>0),0) paired_rating_matches FROM p2k_g_matches gm LEFT JOIN p2k_tp_match_metadata cm ON cm.club_slug=? AND cm.match_id=gm.match_id WHERE gm.club_verified=1 AND gm.time_class='daily' AND gm.status='finished' AND gm.is_void=0");$c->execute([$this->clubSlug]);$cov=$c->fetch()?:[];$all=(int)($cov['finished_matches']??0);$paired=(int)($cov['paired_rating_matches']??0);
            return ['total'=>(int)($w['total']??0),'pending'=>(int)($w['pending']??0),'completed'=>(int)($w['completed']??0),'failed'=>(int)($w['failed']??0),'oldest_pending'=>$w['oldest_pending']??null,'last_activity'=>$w['last_activity']??null,'finished_matches'=>$all,'paired_rating_matches'=>$paired,'paired_match_percent'=>$all>0?round(100*$paired/$all,1):0,'active'=>(int)($w['pending']??0)>0,'unit'=>'boards'];
        }catch(\Throwable $e){return ['total'=>0,'pending'=>0,'completed'=>0,'failed'=>0,'finished_matches'=>0,'paired_rating_matches'=>0,'paired_match_percent'=>0,'active'=>false,'unit'=>'boards','error'=>$e->getMessage()];}
    }

    public function feedPlan(int $limit=48,string $browserOwner=''): array
    {
        $limit=max(1,min(96,$limit));$s=$this->state();$stage=(string)$s['stage'];$cycleStart=(string)($s['cycle_started_at']??gmdate('Y-m-d H:i:s'));$urls=[];$seenUrls=[];
        $push=static function(array &$urls,string $kind,string $url,array $extra=[]) use($limit,&$seenUrls): void { $url=trim($url);if($url===''||isset($seenUrls[$url])||count($urls)>=$limit)return;$urls[]=['kind'=>$kind,'url'=>$url]+$extra;$seenUrls[$url]=true; };
        $finiteStages=['seed_index_roster','seed_deep_scan','seed_matches','seed_boards','seed_profiles','seed_stats','quick_index_roster','quick_discovered','quick_matches','quick_cold_verify','quick_boards','quick_profiles','quick_stats','deep_scan','deep_hydrate'];
        $finiteReserve=in_array($stage,$finiteStages,true)?max(1,min(self::ACCELERATOR_FINITE_RESERVE_MAX,(int)ceil($limit/3))):0;
        $sidecarLimit=max(0,$limit-$finiteReserve);
        $priorityLane='normal';
        // GAB remains the first background accelerator lane, but continuous
        // sidecars may not consume the whole batch while a finite Green cycle is
        // active.  At least one third (capped at 16) stays available to the finite
        // stage, preventing GAB/GFFL from starving Quick/Seed/Deep completion.
        if((string)($s['gab_status']??'not_started')==='running' && $sidecarLimit>0){
            try{$gab=new GreenAnalyticsBootstrap($this);foreach($gab->planExternal($sidecarLimit,'browser-gab') as $r)$push($urls,(string)$r['kind'],(string)$r['url'],['gab'=>true,'work_key'=>(string)$r['work_key']]);if($urls)$priorityLane='gab';}catch(\Throwable $ignored){}
        }
        // Historical heatmap backfill is browser-native and deliberately targets only
        // already-known finished P2K matches missing paired-rating provenance. It runs
        // after any real GAB external work, before GFFL bulk debt, while the finite-cycle
        // reserve still guarantees forward progress.
        if(count($urls)<$sidecarLimit){foreach($this->heatmapBackfillPlan($sidecarLimit-count($urls)) as $r){$push($urls,'heatmap_board_detail',(string)$r['url'],['heatmap'=>true,'work_key'=>(string)$r['work_key']]);if($priorityLane==='normal')$priorityLane='heatmap';}}
        // GFFL hot/due matches follow within the bounded sidecar share. One match fetch
        // still satisfies every coalesced freshness obligation.
        if(count($urls)<$sidecarLimit && (int)($s['gffl_enabled']??1)===1){foreach($this->gfflPlan($sidecarLimit-count($urls)) as $r){$push($urls,'gffl_match_detail',(string)$r['api_url'],['match_id'=>(int)$r['match_id'],'priority'=>(int)$r['priority'],'coalesced'=>(int)$r['coalesced_count']]);if($priorityLane==='normal')$priorityLane='gffl';}}
        $ordinaryLimit=max(0,$limit-count($urls));
        if($ordinaryLimit>0 && in_array($stage,['seed_index_roster','quick_index_roster'],true)){
            $push($urls,'club_index','https://api.chess.com/pub/club/'.rawurlencode($this->clubSlug).'/matches');
            $push($urls,'club_roster','https://api.chess.com/pub/club/'.rawurlencode($this->clubSlug).'/members');
        }
        if($ordinaryLimit>0 && in_array($stage,['seed_matches','quick_discovered','deep_hydrate'],true)){
            $seedOnly=$stage==='seed_matches';
            $owner=(strpos($browserOwner,'browser-')===0)?$browserOwner:'browser-'.(int)($s['cycle_no']??0).'-'.substr(hash('sha256',microtime(true).'-'.mt_rand()),0,12);
            $rows=$this->claimMatchRows($ordinaryLimit,$seedOnly,$owner);
            foreach($rows as $r)$push($urls,'match_detail',(string)$r['api_url'],['match_id'=>(int)$r['match_id']]);
        }elseif($ordinaryLimit>0 && $stage==='quick_matches'){
            $q=$this->core->prepare("SELECT match_id,api_url FROM p2k_g_matches WHERE status='in_progress' AND (last_verified_at IS NULL OR last_verified_at<?) AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP()) ORDER BY match_id DESC LIMIT {$ordinaryLimit}");$q->execute([$cycleStart]);
            foreach($q->fetchAll()?:[] as $r)$push($urls,'match_detail',(string)$r['api_url'],['match_id'=>(int)$r['match_id']]);
        }elseif($ordinaryLimit>0 && $stage==='quick_cold_verify'){
            $cfg=GreenConfig::load();$seconds=max(86400,(int)($cfg['app']['historical_match_recheck_seconds']??2592000));$cut=gmdate('Y-m-d H:i:s',time()-$seconds);
            $q=$this->core->prepare("SELECT match_id,api_url FROM p2k_g_matches WHERE trusted_legacy=0 AND club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0 AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP()) AND (last_verified_at IS NULL OR last_verified_at<?) AND (last_verified_at IS NULL OR last_verified_at<?) ORDER BY COALESCE(last_verified_at,'1970-01-01'),match_id LIMIT {$ordinaryLimit}");$q->execute([$cut,$cycleStart]);
            foreach($q->fetchAll()?:[] as $r)$push($urls,'cold_match_detail',(string)$r['api_url'],['match_id'=>(int)$r['match_id']]);
        }elseif($ordinaryLimit>0 && in_array($stage,['seed_boards','quick_boards'],true)){
            $owner=(strpos($browserOwner,'browser-')===0)?$browserOwner:'browser-board-'.(int)($s['cycle_no']??0).'-'.substr(hash('sha256',microtime(true).'-'.mt_rand()),0,12);
            $rows=$stage==='quick_boards'?$this->claimQuickBoardRows($ordinaryLimit,$owner,(int)($s['cycle_no']??0)):$this->claimBoardRows($ordinaryLimit,$owner);
            foreach($rows as $r)$push($urls,'board_detail',(string)$r['board_url'],['match_id'=>(int)$r['match_id'],'board_no'=>(int)$r['board_no']]);
        }elseif($ordinaryLimit>0 && in_array($stage,['seed_profiles','quick_profiles'],true)){
            $q=$this->core->query("SELECT username FROM p2k_g_players WHERE current_member=1 AND profile_checked_at IS NULL ORDER BY username_key LIMIT ".$ordinaryLimit);
            foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $u)$push($urls,'player_profile','https://api.chess.com/pub/player/'.rawurlencode((string)$u),['username'=>(string)$u]);
        }elseif($ordinaryLimit>0 && $stage==='seed_stats'){
            $q=$this->core->query("SELECT username FROM p2k_g_players WHERE current_member=1 AND stats_checked_at IS NULL ORDER BY username_key LIMIT ".$ordinaryLimit);
            foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $u)$push($urls,'player_stats','https://api.chess.com/pub/player/'.rawurlencode((string)$u).'/stats',['username'=>(string)$u]);
        }elseif($ordinaryLimit>0 && $stage==='quick_stats'){
            $seconds=max(3600,(int)(GreenConfig::load()['app']['stats_refresh_seconds']??259200));$cut=gmdate('Y-m-d H:i:s',time()-$seconds);
            $q=$this->core->prepare("SELECT username FROM p2k_g_players WHERE current_member=1 AND (stats_checked_at IS NULL OR stats_checked_at<?) AND (stats_checked_at IS NULL OR stats_checked_at<?) ORDER BY COALESCE(stats_checked_at,'1970-01-01'),username_key LIMIT {$ordinaryLimit}");$q->execute([$cut,$cycleStart]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $u)$push($urls,'player_stats','https://api.chess.com/pub/player/'.rawurlencode((string)$u).'/stats',['username'=>(string)$u]);
        }
        // If the finite stage could not use its reserved share, backfill the
        // remaining accelerator capacity with GAB first and then GFFL, preserving
        // throughput without sacrificing the finite-cycle reservation.
        if(count($urls)<$limit && (string)($s['gab_status']??'not_started')==='running'){
            try{$gabBackfill=new GreenAnalyticsBootstrap($this);$seen=array_fill_keys(array_map(static fn($x)=>(string)($x['url']??''),$urls),true);foreach($gabBackfill->planExternal($limit,'browser-gab') as $r){$u=(string)$r['url'];if(isset($seen[$u]))continue;$push($urls,(string)$r['kind'],$u,['gab'=>true,'work_key'=>(string)$r['work_key']]);$seen[$u]=true;if(count($urls)>=$limit)break;}}catch(\Throwable $ignored){}
        }
        if(count($urls)<$limit){$seen=array_fill_keys(array_map(static fn($x)=>(string)($x['url']??''),$urls),true);foreach($this->heatmapBackfillPlan($limit) as $r){$u=(string)$r['url'];if(isset($seen[$u]))continue;$push($urls,'heatmap_board_detail',$u,['heatmap'=>true,'work_key'=>(string)$r['work_key']]);$seen[$u]=true;if(count($urls)>=$limit)break;}}
        if(count($urls)<$limit && (int)($s['gffl_enabled']??1)===1){$seen=array_fill_keys(array_map(static fn($x)=>(string)($x['url']??''),$urls),true);foreach($this->gfflPlan($limit) as $r){$u=(string)$r['api_url'];if(isset($seen[$u]))continue;$push($urls,'gffl_match_detail',$u,['match_id'=>(int)$r['match_id'],'priority'=>(int)$r['priority'],'coalesced'=>(int)$r['coalesced_count']]);$seen[$u]=true;if(count($urls)>=$limit)break;}}
        $planner=$stage==='seed_matches'?$this->seedMatchPlannerState($browserOwner):($stage==='quick_boards'?$this->quickBoardCycleState((int)($s['cycle_no']??0),$browserOwner):($stage==='seed_boards'?$this->boardPlannerState($browserOwner):[]));
        if(in_array($stage,['seed_profiles','quick_profiles'],true)){
            $due=(int)$this->core->query("SELECT COUNT(*) FROM p2k_g_players WHERE current_member=1 AND profile_checked_at IS NULL")->fetchColumn();
            $planner=['planner_kind'=>'profiles','due_unhydrated'=>$due,'claimed_active'=>0,'eligible_now'=>$due,'claimed_by_owner'=>0];
        }elseif($stage==='seed_stats'){
            $due=(int)$this->core->query("SELECT COUNT(*) FROM p2k_g_players WHERE current_member=1 AND stats_checked_at IS NULL")->fetchColumn();
            $planner=['planner_kind'=>'stats','due_unhydrated'=>$due,'claimed_active'=>0,'eligible_now'=>$due,'claimed_by_owner'=>0];
        }
        return ['cycle_no'=>(int)($s['cycle_no']??0),'mode'=>(string)$s['mode'],'stage'=>$stage,'tasks'=>$urls,'planner'=>$planner,'priority_lane'=>$priorityLane,'gab_status'=>(string)($s['gab_status']??'not_started'),'gffl'=>$this->gfflSnapshot(),'deep_browser_feed_supported'=>false];
    }

    public function ingestObservation(string $url,array $payload,string $source='migration_browser'): array
    {
        $state=$this->state();$cycle=(int)($state['cycle_no']??0);$path=(string)(parse_url($url,PHP_URL_PATH)?:'');$path=rtrim(strtolower($path),'/');$type='unknown';$changed=0;$result=[];
        if(preg_match('~/pub/club/[^/]+/matches$~',$path)){$type='club_index';$result=$this->upsertIndex($payload);$changed=(int)($result['added']??0);}
        elseif(preg_match('~/pub/club/[^/]+/members$~',$path)){$type='club_roster';$result=$this->upsertRoster($payload);$changed=(int)($result['new']??0)+(int)($result['left']??0);}
        elseif(preg_match('~/pub/match/(\d+)/(\d+)$~',$path,$m)){$type='board_detail';$matchId=(int)$m[1];$boardNo=(int)$m[2];$result=$this->storeBoard($matchId,$boardNo,$payload);$result['match_id']=$matchId;$result['board_no']=$boardNo;$changed=!empty($result['changed'])?1:0;}
        elseif(preg_match('~/pub/match/(\d+)$~',$path,$m)){$type='match_detail';$matchId=(int)$m[1];$result=$this->storeMatch($matchId,$payload,200);$result['match_id']=$matchId;$changed=!empty($result['changed'])?1:0;}
        elseif(preg_match('~/pub/player/([^/]+)/stats$~',$path,$m)){$type='player_stats';$username=rawurldecode($m[1]);$this->storeStats($username,$payload);$changed=1;$result=['username'=>$username];}
        elseif(preg_match('~/pub/player/([^/]+)$~',$path,$m)){$type='player_profile';$username=rawurldecode($m[1]);$result=$this->storeProfile($username,$payload);$result['username']=$username;$changed=1;}
        else throw new RuntimeException('Observation URL is not a supported Green migration resource.');
        $this->metric($cycle,$type,$source,'accepted',1);$this->observationMetric($cycle,$source,$type,1,0,$changed,1);if($changed)$this->changed($cycle,$changed);
        return ['accepted'=>true,'type'=>$type,'changed'=>$changed,'result'=>$result];
    }

    /** GICL bounded/idempotent convergence sweep. It never creates queue rows;
     * it only re-arms canonical board debt, so repeated runs cannot duplicate work. */
    public function armIntegrityConvergence(int $limit=32): array
    {
        $limit=max(1,min(200,$limit));$armed=0;$playerEvent=0;
        $q=$this->core->query("SELECT b.match_id,b.board_no FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id WHERE m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status='finished' AND b.state IN ('unknown','in_progress') AND b.needs_refresh=0 ORDER BY m.match_id DESC,b.board_no LIMIT {$limit}");
        $rows=$q->fetchAll()?:[];
        if($rows){$u=$this->core->prepare("UPDATE p2k_g_boards SET needs_refresh=1,retry_after=NULL,updated_at=UTC_TIMESTAMP() WHERE match_id=? AND board_no=? AND needs_refresh=0");foreach($rows as $r){$u->execute([(int)$r['match_id'],(int)$r['board_no']]);$armed+=$u->rowCount();}}
        $left=max(0,$limit-$armed);
        if($left>0){
            $sql="SELECT b.match_id,b.board_no FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id LEFT JOIN (SELECT match_id,board_no,COUNT(*) c FROM p2k_g_point_events GROUP BY match_id,board_no) e ON e.match_id=b.match_id AND e.board_no=b.board_no WHERE m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status='finished' AND b.state='finished' AND b.finished_game_count>COALESCE(e.c,0) AND b.needs_refresh=0 ORDER BY m.match_id DESC,b.board_no LIMIT {$left}";
            $rows=$this->core->query($sql)->fetchAll()?:[];
            if($rows){$u=$this->core->prepare("UPDATE p2k_g_boards SET needs_refresh=1,retry_after=NULL,updated_at=UTC_TIMESTAMP() WHERE match_id=? AND board_no=? AND needs_refresh=0");foreach($rows as $r){$u->execute([(int)$r['match_id'],(int)$r['board_no']]);$playerEvent+=$u->rowCount();}}
        }
        return ['incomplete_boards_armed'=>$armed,'player_event_repairs_armed'=>$playerEvent,'total_armed'=>$armed+$playerEvent];
    }

    public function recentInvocations(int $limit=20): array
    {
        $limit=max(1,min(100,$limit));return $this->core->query('SELECT invocation_id,cycle_no,mode,stage_start,stage_finish,status,started_at,completed_at,runtime_ms,request_count,summary_json FROM p2k_g_invocations ORDER BY invocation_id DESC LIMIT '.$limit)->fetchAll()?:[];
    }

    public function recentCycleDurations(int $limit=10): array
    {
        $limit=max(1,min(50,$limit));
        $rows=$this->core->query("SELECT cycle_no,cycle_kind,mode,started_at,completed_at,request_total,changed_objects,TIMESTAMPDIFF(MICROSECOND,started_at,completed_at)/1000000 duration_seconds FROM p2k_g_cycles WHERE status='completed' AND completed_at IS NOT NULL ORDER BY cycle_no DESC LIMIT ".$limit)->fetchAll()?:[];
        $durations=[];
        foreach($rows as &$row){$row['duration_seconds']=max(0,(float)($row['duration_seconds']??0));$durations[]=$row['duration_seconds'];}
        unset($row);
        return [
            'last_cycle_no'=>$rows?(int)$rows[0]['cycle_no']:null,
            'last_duration_seconds'=>$durations?(float)$durations[0]:null,
            'average_duration_seconds'=>$durations?array_sum($durations)/count($durations):null,
            'sample_size'=>count($durations),
            'cycles'=>$rows,
            'timing_model'=>'cycle_wall_clock',
            'historical_boundary_warning'=>'Cycles recorded before v2.10.6.16 may include work performed by the historical post-completion tail-pass before the next cycle timer started.',
        ];
    }

    public function maybeRebuildAnalytics(int $cycleNo,int $minimumSeconds=30): bool
    {
        $q=$this->core->prepare('SELECT last_analytics_rebuild FROM p2k_g_state WHERE club_slug=?');$q->execute([$this->clubSlug]);$last=$q->fetchColumn();
        if($last!==false&&$last!==null&&$last!==''&&strtotime((string)$last)>time()-max(30,$minimumSeconds))return false;
        $this->rebuildAnalytics($cycleNo);
        $this->core->prepare('UPDATE p2k_g_state SET last_analytics_rebuild=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
        return true;
    }

    public function rebuildAnalytics(int $cycleNo): void
    {
        $this->analytics->beginTransaction();
        try{
            $this->analytics->exec('DELETE FROM p2k_g_player_totals');
            $rows=$this->core->query("SELECT COALESCE(i.canonical_username_key,e.username_key) username_key,MAX(COALESCE(i.canonical_username,e.username)) username,SUM(e.points) points,COUNT(*) games,MAX(e.event_epoch) latest FROM p2k_g_point_events e JOIN p2k_g_matches m ON m.match_id=e.match_id LEFT JOIN p2k_g_identity_map i ON i.username_key=e.username_key JOIN p2k_g_players p ON p.username_key=COALESCE(i.canonical_username_key,e.username_key) AND p.current_member=1 WHERE m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress','finished') GROUP BY COALESCE(i.canonical_username_key,e.username_key)")->fetchAll()?:[];
            $ins=$this->analytics->prepare('INSERT INTO p2k_g_player_totals(username_key,username,points,finished_games,latest_event_epoch,source_cycle_no,updated_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())');
            foreach($rows as $r)$ins->execute([$r['username_key'],$r['username'],(float)$r['points'],(int)$r['games'],$r['latest']!==null?(int)$r['latest']:null,$cycleNo]);
            $m=$this->core->query("SELECT COUNT(*) known,SUM(club_verified=1 AND time_class='daily' AND status='registered') registered,SUM(club_verified=1 AND time_class='daily' AND status='in_progress') inprog,SUM(club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0) finished,SUM(club_verified=1 AND time_class='daily' AND status='cancelled') cancelled,SUM(status='unknown') unknown,COALESCE(SUM(CASE WHEN scoring_eligible=1 THEN competition_points ELSE 0 END),0) points FROM p2k_g_matches")->fetch()?:[];
            $b=$this->core->query("SELECT COUNT(*) total,SUM(b.state='finished') finished,SUM(b.state='unknown') unknown FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id WHERE m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress','finished')")->fetch()?:[];
            $members=(int)$this->core->query('SELECT COUNT(*) FROM p2k_g_players WHERE current_member=1')->fetchColumn();$pwp=count($rows);
            $q=$this->analytics->prepare('INSERT INTO p2k_g_club_totals(club_slug,current_members,players_with_points,known_matches,registered_matches,in_progress_matches,finished_matches,cancelled_matches,unknown_matches,total_boards,finished_boards,unknown_boards,club_points,source_cycle_no,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE current_members=VALUES(current_members),players_with_points=VALUES(players_with_points),known_matches=VALUES(known_matches),registered_matches=VALUES(registered_matches),in_progress_matches=VALUES(in_progress_matches),finished_matches=VALUES(finished_matches),cancelled_matches=VALUES(cancelled_matches),unknown_matches=VALUES(unknown_matches),total_boards=VALUES(total_boards),finished_boards=VALUES(finished_boards),unknown_boards=VALUES(unknown_boards),club_points=VALUES(club_points),source_cycle_no=VALUES(source_cycle_no),updated_at=UTC_TIMESTAMP()');
            $q->execute([$this->clubSlug,$members,$pwp,(int)($m['known']??0),(int)($m['registered']??0),(int)($m['inprog']??0),(int)($m['finished']??0),(int)($m['cancelled']??0),(int)($m['unknown']??0),(int)($b['total']??0),(int)($b['finished']??0),(int)($b['unknown']??0),(float)($m['points']??0),$cycleNo]);
            $this->analytics->prepare('INSERT INTO p2k_g_analytics_meta(club_slug,source_cycle_no,rebuilt_at) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE source_cycle_no=VALUES(source_cycle_no),rebuilt_at=UTC_TIMESTAMP()')->execute([$this->clubSlug,$cycleNo]);
            $this->analytics->commit();$this->core->prepare('UPDATE p2k_g_state SET last_analytics_rebuild=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
        }catch(\Throwable $e){if($this->analytics->inTransaction())$this->analytics->rollBack();throw $e;}
    }

    public function integritySnapshot(): array
    {
        $r=$this->core->query("SELECT COALESCE(SUM(club_verified=1 AND time_class<>'daily' AND competition_points<>0),0) non_daily_point_rows,COALESCE(SUM(club_verified=0 AND competition_points<>0),0) unverified_point_rows,COALESCE(SUM(scoring_eligible=0 AND competition_points<>0),0) ineligible_point_rows,COALESCE(SUM(trusted_legacy=1),0) trusted_legacy_rows,COALESCE(SUM(trusted_legacy=1 AND is_void=0),0) trusted_legacy_valid,COALESCE(SUM(trusted_legacy=1 AND is_void=1),0) trusted_legacy_void,COALESCE(SUM(CASE WHEN trusted_legacy=1 AND is_void=0 THEN competition_points ELSE 0 END),0) trusted_legacy_points,COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0),0) daily_finished FROM p2k_g_matches")->fetch()?:[];
        $events=(int)$this->core->query("SELECT COUNT(*) FROM p2k_g_point_events e JOIN p2k_g_matches m ON m.match_id=e.match_id WHERE m.club_verified<>1 OR m.time_class<>'daily' OR m.is_void=1 OR m.status IN ('cancelled','not_club','unavailable')")->fetchColumn();
        $state=$this->state();$gfflTarget=max(60,min(3600,(int)($state['gffl_target_freshness_seconds']??1200)));
        return ['non_daily_point_rows'=>(int)($r['non_daily_point_rows']??0),'unverified_point_rows'=>(int)($r['unverified_point_rows']??0),'ineligible_point_rows'=>(int)($r['ineligible_point_rows']??0),'excluded_point_events'=>$events,'trusted_legacy_rows'=>(int)($r['trusted_legacy_rows']??0),'trusted_legacy_valid'=>(int)($r['trusted_legacy_valid']??0),'trusted_legacy_void'=>(int)($r['trusted_legacy_void']??0),'trusted_legacy_points'=>(int)($r['trusted_legacy_points']??0),'daily_finished'=>(int)($r['daily_finished']??0),'current_maintenance_due'=>$this->currentMaintenanceCount(300),'current_maintenance_over_slo'=>$this->currentMaintenanceCount($gfflTarget),'gffl_target_freshness_seconds'=>$gfflTarget];
    }

    public function databaseSizes(): array
    {
        $out=[];foreach(['core'=>$this->core,'analytics'=>$this->analytics] as $key=>$pdo){$db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();$q=$pdo->prepare('SELECT COALESCE(SUM(data_length+index_length),0) bytes,COUNT(*) tables FROM information_schema.tables WHERE table_schema=?');$q->execute([$db]);$r=$q->fetch()?:[];$out[$key]=['database'=>$db,'estimated_bytes'=>(int)($r['bytes']??0),'table_count'=>(int)($r['tables']??0),'quota_bytes'=>2147483648,'quota_percent'=>round(((int)($r['bytes']??0))/2147483648*100,2)];}return $out;
    }

    public function liveTotals(): array
    {
        $m=$this->core->query("SELECT COUNT(*) known_matches,COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='registered' AND is_void=0),0) registered_matches,COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='in_progress' AND is_void=0),0) in_progress_matches,COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0),0) finished_matches,COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='cancelled'),0) cancelled_matches,COALESCE(SUM(status='unknown'),0) unknown_matches,COALESCE(SUM(CASE WHEN scoring_eligible=1 THEN competition_points ELSE 0 END),0) club_points,MAX(updated_at) matches_updated_at FROM p2k_g_matches")->fetch(PDO::FETCH_ASSOC)?:[];
        $b=$this->core->query("SELECT COUNT(*) total_boards,COALESCE(SUM(b.state='finished'),0) finished_boards,COALESCE(SUM(b.state='unknown'),0) unknown_boards,MAX(b.updated_at) boards_updated_at FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id WHERE m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress','finished')")->fetch(PDO::FETCH_ASSOC)?:[];
        $members=(int)$this->core->query('SELECT COUNT(*) FROM p2k_g_players WHERE current_member=1')->fetchColumn();
        $players=(int)$this->core->query("SELECT COUNT(DISTINCT COALESCE(i.canonical_username_key,e.username_key)) FROM p2k_g_point_events e JOIN p2k_g_matches m ON m.match_id=e.match_id LEFT JOIN p2k_g_identity_map i ON i.username_key=e.username_key JOIN p2k_g_players p ON p.username_key=COALESCE(i.canonical_username_key,e.username_key) AND p.current_member=1 WHERE m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress','finished')")->fetchColumn();
        $cycle=(int)($this->state()['cycle_no']??0);$updatedCandidates=array_values(array_filter([(string)($m['matches_updated_at']??''),(string)($b['boards_updated_at']??'')],static fn($v)=>$v!==''));$updated=$updatedCandidates?max($updatedCandidates):gmdate('Y-m-d H:i:s');
        return ['club_slug'=>$this->clubSlug,'current_members'=>$members,'players_with_points'=>$players,'known_matches'=>(int)($m['known_matches']??0),'registered_matches'=>(int)($m['registered_matches']??0),'in_progress_matches'=>(int)($m['in_progress_matches']??0),'finished_matches'=>(int)($m['finished_matches']??0),'cancelled_matches'=>(int)($m['cancelled_matches']??0),'unknown_matches'=>(int)($m['unknown_matches']??0),'total_boards'=>(int)($b['total_boards']??0),'finished_boards'=>(int)($b['finished_boards']??0),'unknown_boards'=>(int)($b['unknown_boards']??0),'club_points'=>(float)($m['club_points']??0),'source_cycle_no'=>$cycle,'updated_at'=>$updated?:gmdate('Y-m-d H:i:s'),'source'=>'green_core_live'];
    }

    public function greenSummary(): array
    {
        $total=$this->analytics->prepare('SELECT * FROM p2k_g_club_totals WHERE club_slug=?');$total->execute([$this->clubSlug]);$analyticsTotals=$total->fetch()?:[];$r=$this->liveTotals();
        $s=$this->state();
        $cycle=[];if((int)$s['cycle_no']>0){$q=$this->core->prepare('SELECT * FROM p2k_g_cycles WHERE cycle_no=?');$q->execute([(int)$s['cycle_no']]);$cycle=$q->fetch()?:[];}
        $metrics=[];if((int)$s['cycle_no']>0){$q=$this->core->prepare('SELECT request_type,source,outcome,request_count FROM p2k_g_request_metrics WHERE cycle_no=? ORDER BY request_type,source,outcome');$q->execute([(int)$s['cycle_no']]);$metrics=$q->fetchAll()?:[];}
        $identity=['mappings'=>0,'trusted_mappings'=>0,'edges'=>0];
        try{$mi=$this->core->query('SELECT COUNT(*) mappings,COALESCE(SUM(trusted=1),0) trusted_mappings FROM p2k_g_identity_map')->fetch()?:[];$identity['mappings']=(int)($mi['mappings']??0);$identity['trusted_mappings']=(int)($mi['trusted_mappings']??0);$identity['edges']=(int)$this->core->query('SELECT COUNT(*) FROM p2k_g_identity_edges')->fetchColumn();}catch(\Throwable $ignored){}
        $gqac=[];if((string)($s['mode']??'')==='quick'&&(int)($s['cycle_no']??0)>0){$gqac=(string)($s['stage']??'')==='quick_boards'?$this->ensureQuickBoardCycle((int)$s['cycle_no']):$this->quickBoardCycleState((int)$s['cycle_no']);}
        $memberEventCounts=[];try{$memberEventCounts=$this->core->query("SELECT event_type,COUNT(*) count FROM p2k_g_member_events GROUP BY event_type")->fetchAll()?:[];}catch(\Throwable $ignored){}
        $gab=[];try{$gab=(new GreenAnalyticsBootstrap($this))->status();}catch(\Throwable $e){$gab=['status'=>(string)($s['gab_status']??'not_started'),'error'=>$e->getMessage()];}
        return ['totals'=>$r,'analytics_totals'=>$analyticsTotals,'state'=>$s,'cycle'=>$cycle,'request_metrics'=>$metrics,'observation_metrics'=>$this->observationMetrics((int)($s['cycle_no']??0)),'progress'=>$this->progressSnapshot(),'gqac'=>$gqac,'gab'=>$gab,'gffl'=>$this->gfflSnapshot(),'heatmap_backfill'=>$this->heatmapBackfillSnapshot(),'phase_progress'=>$this->phaseProgressSnapshot((int)($s['cycle_no']??0)),'identity'=>$identity,'member_event_counts'=>$memberEventCounts,'integrity'=>$this->integritySnapshot(),'database_sizes'=>$this->databaseSizes(),'recent_invocations'=>$this->recentInvocations(24),'cycle_durations'=>$this->recentCycleDurations(10)];
    }

    public function greenTop(int $limit=10): array
    {
        $limit=max(1,min(200,$limit));$sql="SELECT COALESCE(i.canonical_username_key,e.username_key) username_key,MAX(COALESCE(i.canonical_username,e.username)) username,COALESCE(SUM(e.points),0) points,COUNT(*) finished_games FROM p2k_g_point_events e JOIN p2k_g_matches m ON m.match_id=e.match_id LEFT JOIN p2k_g_identity_map i ON i.username_key=e.username_key JOIN p2k_g_players p ON p.username_key=COALESCE(i.canonical_username_key,e.username_key) AND p.current_member=1 WHERE m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress','finished') GROUP BY COALESCE(i.canonical_username_key,e.username_key) ORDER BY points DESC,username_key LIMIT {$limit}";return $this->core->query($sql)->fetchAll()?:[];
    }
}
