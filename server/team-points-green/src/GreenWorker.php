<?php
declare(strict_types=1);

namespace P2K\Green;

use RuntimeException;

final class GreenHttp
{
    private float $lastAt=0.0;
    private int $spacingMs;
    private int $timeoutSeconds;

    public function __construct(int $spacingMs=650,int $timeoutSeconds=18)
    {
        $this->spacingMs = $spacingMs;
        $this->timeoutSeconds=max(4,min(18,$timeoutSeconds));
    }

    public function get(string $url): array
    {
        $now=microtime(true);$wait=($this->spacingMs/1000)-($now-$this->lastAt);if($wait>0)usleep((int)round($wait*1_000_000));
        $ch=curl_init($url);if($ch===false)throw new RuntimeException('Unable to initialize cURL.');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>min(6,$this->timeoutSeconds),CURLOPT_TIMEOUT=>$this->timeoutSeconds,CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: PromoteToKing/2.10.4 GreenMigration (+https://www.promotetoking.org/)']]);
        $body=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$this->lastAt=microtime(true);
        if($body===false)return ['status'=>$status?:0,'json'=>null,'body'=>'','error'=>$err?:'network'];
        $json=null;try{$decoded=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);if(is_array($decoded))$json=$decoded;}catch(\Throwable $ignored){}
        return ['status'=>$status,'json'=>$json,'body'=>(string)$body,'error'=>''];
    }
}

final class GreenWorker
{
    private GreenRepository $repo;
    private GreenHttp $http;
    private array $config;
    private float $deadline;
    private float $hardDeadline;
    private int $softTargetSeconds;
    private int $hardBudgetSeconds;
    private array $context;
    private int $cycle=0;
    private int $requestCount=0;
    private array $achieved=[];
    private array $timingsMs=[];
    private ?int $coreLockRuntimeMs=null;

    public function __construct(?GreenRepository $repo=null,?int $hardBudgetSeconds=null,?int $softTargetSeconds=null,array $context=[])
    {
        $this->repo=$repo??GreenRepository::open();$this->config=GreenConfig::load();
        $app=(array)($this->config['app']??[]);
        $hard=max(15,min(55,$hardBudgetSeconds??(int)($app['worker_budget_seconds']??42)));
        $soft=$softTargetSeconds===null?$hard:max(10,min($hard,$softTargetSeconds));
        $now=microtime(true);$this->softTargetSeconds=$soft;$this->hardBudgetSeconds=$hard;$this->deadline=$now+$soft;$this->hardDeadline=$now+$hard;$this->context=$context;
        $timeout=$soft<$hard?max(4,min(18,$hard-$soft)):50;
        $this->http=new GreenHttp(max(250,(int)($app['request_spacing_ms']??650)),$timeout);
    }

    private function timeLeft(float $reserve=2.0): bool { return microtime(true)<min($this->deadline,$this->hardDeadline)-$reserve; }
    private function sameActiveCycle(array $state,int $cycleNo): bool { return $cycleNo>0&&(int)($state['cycle_no']??0)===$cycleNo&&$state['cycle_started_at']!==null; }
    private function timed(string $key,callable $fn){$started=microtime(true);try{return $fn();}finally{$this->timingsMs[$key]=(int)($this->timingsMs[$key]??0)+(int)round((microtime(true)-$started)*1000);}}
    private function inc(string $key,int $n=1): void {$this->achieved[$key]=(int)($this->achieved[$key]??0)+$n;}
    private function outcome(int $status,string $error=''): string { if($error!=='')return 'network';if($status===200)return '200';if($status===304)return '304';if($status===404)return '404';if($status===410)return '410';if($status===429)return '429';if($status>=500)return '5xx';return 'other'; }

    private function request(string $type,string $url): array
    {
        $r=$this->http->get($url);$this->requestCount++;$metricSource=(string)($this->context['metric_source']??'worker');$this->repo->metric($this->cycle,$type,$metricSource,$this->outcome((int)$r['status'],(string)$r['error']));return $r;
    }

    private function ensureCycle(array $state): array
    {
        // quick_complete is a transition marker, never a runnable phase. Self-heal any
        // historical/stale state before deciding whether a new Quick cycle is required.
        if((string)($state['mode']??'')==='quick'&&(string)($state['stage']??'')==='quick_complete'){
            $this->repo->recoverQuickCompleteTransition();$state=$this->repo->state();
        }
        if($state['cycle_started_at']!==null){$this->cycle=(int)$state['cycle_no'];return $state;}
        $mode=(string)$state['mode'];$stage=(string)$state['stage'];$kind=$mode==='quick'?'quick':($mode==='deep'?'deep':'seed');
        if($stage==='not_started'&&$mode==='seeding')return $state;
        $this->cycle=$this->repo->startCycle($kind,$mode,$stage);return $this->repo->state();
    }

    private function fetchIndex()
    {
        $r=$this->request('club_index','https://api.chess.com/pub/club/'.rawurlencode($this->repo->clubSlug).'/matches');
        if((int)$r['status']!==200||!is_array($r['json']))return false;$x=$this->repo->upsertIndex($r['json']);$this->inc('index_matches_added',(int)$x['added']);return $x;
    }

    private function fetchRoster(): bool
    {
        $r=$this->request('club_members','https://api.chess.com/pub/club/'.rawurlencode($this->repo->clubSlug).'/members');
        if((int)$r['status']!==200||!is_array($r['json']))return false;$x=$this->repo->upsertRoster($r['json']);$this->inc('members_observed',(int)$x['observed']);$this->inc('members_new',(int)$x['new']);try{(new GreenCompatibility($this->repo))->projectMembers();}catch(\Throwable $e){$this->inc('compat_projection_errors');}return true;
    }

    private function fetchMatch(int $id,string $requestType='match_detail',bool $deepProbe=false): array
    {
        $r=$this->request($requestType,'https://api.chess.com/pub/match/'.$id);$status=(int)$r['status'];
        if($status===200&&is_array($r['json'])){
            if($deepProbe&&!$this->containsClub($r['json']))return ['status'=>'other_club','changed'=>false];
            if($deepProbe)$this->repo->deepDiscovered($id);
            $x=$this->repo->storeMatch($id,$r['json'],$status);$this->repo->completeGfflMatch($id);if(!empty($x['changed'])){$this->repo->changed($this->cycle);$this->inc('matches_changed');}if(($x['status']??'')==='cancelled')$this->inc('matches_cancelled');if(($x['status']??'')==='not_club')$this->inc('matches_not_club');try{$c=new GreenCompatibility($this->repo);$c->projectMatch($id,false);$this->inc('compat_matches_projected');}catch(\Throwable $e){$this->inc('compat_projection_errors');}return $x;
        }
        if(!$deepProbe){$this->repo->markMatchHttp($id,$status,(string)$r['error']);if(in_array($status,[404,410],true)){$this->repo->completeGfflMatch($id);try{(new GreenCompatibility($this->repo))->projectMatch($id,false);$this->inc('compat_matches_projected');}catch(\Throwable $e){$this->inc('compat_projection_errors');}}}
        return ['status'=>'http_'.$status,'changed'=>false];
    }

    private function containsClub(array $match): bool
    {
        return $this->repo->containsExactClub($match);
    }

    private function maintainCurrentMatches(string $stage,int $cap=8): void
    {
        if(!$this->timeLeft(10.0))return;
        $state=$this->repo->state();$last=(string)($state['last_index_fetch']??'');$lastTs=$last!==''?strtotime($last.' UTC'):false;
        if(!in_array($stage,['seed_index_roster','quick_index_roster'],true)&&($lastTs===false||$lastTs<time()-300)){
            $this->fetchIndex();
        }
        // GICL: bounded canonical debt repair before normal current-match maintenance.
        // Repeated invocations are idempotent because only needs_refresh=0 rows can be armed.
        if($this->timeLeft(8.0)){ $gicl=$this->repo->armIntegrityConvergence(24); if(($gicl['total_armed']??0)>0)$this->inc('gicl_boards_armed',(int)$gicl['total_armed']); }
        $state=$this->repo->state();
        $maintenanceStale=(int)($state['gffl_enabled']??1)===1
            ? max(60,min(3600,(int)($state['gffl_target_freshness_seconds']??1200)))
            : 300;
        $cap=max(0,min(8,$cap));
        while($cap>0&&$this->timeLeft(7.0)&&($id=$this->repo->nextCurrentMaintenanceDue($maintenanceStale))!==null){
            // GFFL is serviced first. This fallback shares the same freshness target so
            // it repairs uncovered/transition debt without refetching current matches at
            // an independent five-minute cadence. fetchMatch() also retires GFFL debt.
            $this->fetchMatch($id,'current_match_maintenance');$this->inc('current_matches_maintained');$cap--;
        }
    }

    private function fetchBoard(array $b): void
    {
        $r=$this->request('board_detail',(string)$b['board_url']);$status=(int)$r['status'];if($status===200&&is_array($r['json'])){$x=$this->repo->storeBoard((int)$b['match_id'],(int)$b['board_no'],$r['json'],$status);if(!empty($x['changed'])){$this->repo->changed($this->cycle);$this->inc('boards_changed');$this->repo->armGfflMatch((int)$b['match_id'],'board_changed',95,true);try{(new GreenCompatibility($this->repo))->projectMatch((int)$b['match_id'],false);}catch(\Throwable $e){$this->inc('compat_projection_errors');}}if(($x['state']??'')==='finished')$this->inc('boards_finished');}else{$this->repo->markBoardHttp((int)$b['match_id'],(int)$b['board_no'],$status);if(in_array($status,[404,410],true)){try{(new GreenCompatibility($this->repo))->projectMatch((int)$b['match_id'],false);}catch(\Throwable $e){$this->inc('compat_projection_errors');}}elseif($this->repo->deferQuickBoardTransientIfExhausted((int)$b['match_id'],(int)$b['board_no'],$status))$this->inc('gqac_deferred_transient');}
    }

    /**
     * Return true when the current username is resolved for this seed stage.
     * A terminal 404/410 is a resolved "no profile" observation and must be
     * checkpointed; a transient/network/429/5xx response returns false so the
     * invocation stops rather than hammering the same username repeatedly.
     */
    private function projectMemberIfReady(string $username): void
    {
        try{(new GreenCompatibility($this->repo))->projectMember($username);}
        catch(\Throwable $e){$this->inc('compat_projection_errors');}
    }

    private function fetchProfile(string $username): bool
    {
        $r=$this->request('player_profile','https://api.chess.com/pub/player/'.rawurlencode($username));
        $status=(int)$r['status'];
        if($status===200&&is_array($r['json'])){
            $x=$this->repo->storeProfile($username,$r['json']);$this->inc('profiles_updated');
            if(!empty($x['rename']))$this->inc('renames_detected');
            $this->projectMemberIfReady($username);
            return true;
        }
        if(in_array($status,[404,410],true)){
            if($this->repo->markProfileHttp($username,$status))$this->inc('profiles_terminal_unavailable');
            $this->projectMemberIfReady($username);
            return true;
        }
        $this->inc('profiles_transient_failures');
        return false;
    }

    private function fetchDepartureProfile(string $username): bool
    {
        $r=$this->request('player_profile','https://api.chess.com/pub/player/'.rawurlencode($username));$status=(int)$r['status'];
        if($status===200&&is_array($r['json'])){$this->repo->storeProfile($username,$r['json']);$this->repo->markDepartureProfileResult($username,$status,$r['json']);$this->projectMemberIfReady($username);$this->inc('departure_profiles_checked');return true;}
        if(in_array($status,[404,410],true)){$this->repo->markDepartureProfileResult($username,$status,null);$this->projectMemberIfReady($username);$this->inc('departure_profiles_closed');return true;}
        $this->inc('departure_profiles_transient_failures');return false;
    }

    private function fetchStats(string $username): bool
    {
        $r=$this->request('player_stats','https://api.chess.com/pub/player/'.rawurlencode($username).'/stats');
        $status=(int)$r['status'];
        if($status===200&&is_array($r['json'])){
            $this->repo->storeStats($username,$r['json']);$this->projectMemberIfReady($username);$this->inc('ratings_updated');
            return true;
        }
        if(in_array($status,[404,410],true)){
            if($this->repo->markStatsHttp($username,$status))$this->inc('stats_terminal_unavailable');
            $this->projectMemberIfReady($username);
            return true;
        }
        $this->inc('stats_transient_failures');
        return false;
    }

    private function runSeeding(array $state): void
    {
        $stage=(string)$state['stage'];
        if($stage==='seed_index_roster'){
            $index=$this->fetchIndex();if($index===false||!$this->timeLeft())return;if(!$this->fetchRoster())return;
            $from=(int)($index['previous_watermark']??0);$to=(int)($index['highest']??0);
            if($from>0&&$to>$from&&!($index['watermark_present']??false)){$this->repo->configureSeedGap($from,$to);$stage='seed_deep_scan';}
            else{$this->repo->stage('seed_matches');$stage='seed_matches';}
        }
        if($stage==='seed_deep_scan'){
            while($this->timeLeft()&&($id=$this->repo->nextDeepId())!==null){$x=$this->fetchMatch($id,'deep_probe',true);if(($x['status']??'')!=='other_club'&&strpos((string)($x['status']??''),'http_') !== 0)$this->inc('deep_p2k_matches');$this->inc('deep_ids_probed');}
            if($this->repo->deepRemaining()>0)return;
            $this->repo->core->prepare("UPDATE p2k_g_state SET stage='seed_matches',deep_scan_from=NULL,deep_scan_to=NULL,deep_scan_cursor=NULL WHERE club_slug=?")->execute([$this->repo->clubSlug]);$stage='seed_matches';
        }
        if($stage==='seed_matches'){
            while($this->timeLeft()&&($id=$this->repo->nextUnknownMatch())!==null){$this->fetchMatch($id);}
            if($this->repo->unknownMatchCount()>0)return;$this->repo->stage('seed_boards');$stage='seed_boards';
        }
        if($stage==='seed_boards'){
            while($this->timeLeft()&&($b=$this->repo->nextBoardNeedingRefresh())!==null)$this->fetchBoard($b);
            if($this->repo->boardCountPending()>0)return;$this->repo->stage('seed_profiles');$stage='seed_profiles';
        }
        if($stage==='seed_profiles'){
            while($this->timeLeft()&&($u=$this->repo->nextProfileDue())!==null){if(!$this->fetchProfile($u))break;}
            if($this->repo->nextProfileDue()!==null)return;$this->repo->stage('seed_stats');$stage='seed_stats';
        }
        if($stage==='seed_stats'){
            // During the first seed every current member has stats_checked_at NULL, so this naturally performs the one-time complete stats pass.
            while($this->timeLeft()&&($u=$this->repo->nextStatsUnseen())!==null){if(!$this->fetchStats($u))break;}
            if($this->repo->nextStatsUnseen()!==null)return;
            $this->repo->core->prepare("UPDATE p2k_g_state SET mode='quick',stage='quick_index_roster',seed_completed_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([$this->repo->clubSlug]);
            $this->repo->completeCycle($this->achieved);
        }
    }

    private function runQuick(array $state): void
    {
        $stage=(string)$state['stage'];$cycleStart=(string)($state['cycle_started_at']??gmdate('Y-m-d H:i:s'));
        if($stage==='quick_index_roster'){
            $index=$this->fetchIndex();if($index===false||!$this->timeLeft())return;if(!$this->fetchRoster())return;
            if((int)($index['previous_watermark']??0)>0&&(int)($index['highest']??0)>(int)($index['previous_watermark']??0)&&!($index['watermark_present']??false)){
                $this->repo->configureDeep((int)$index['previous_watermark'],(int)$index['highest']);return;
            }
            $this->repo->stage('quick_discovered');$stage='quick_discovered';$state=$this->repo->state();$cycleStart=(string)$state['cycle_started_at'];
        }
        if($stage==='quick_discovered'){
            while($this->timeLeft()&&($id=$this->repo->nextUnknownMatch())!==null)$this->fetchMatch($id);
            if($this->repo->unknownMatchCount()>0)return;$this->repo->stage('quick_matches');$stage='quick_matches';
        }
        if($stage==='quick_matches'){
            while($this->timeLeft()&&($id=$this->repo->nextActiveDueForCycle($cycleStart))!==null)$this->fetchMatch($id);
            if($this->repo->nextActiveDueForCycle($cycleStart)!==null)return;$this->repo->stage('quick_cold_verify');$stage='quick_cold_verify';
        }
        if($stage==='quick_cold_verify'){
            $seconds=max(86400,(int)($this->config['app']['historical_match_recheck_seconds']??2592000));
            $cap=max(1,min(50,(int)($this->config['app']['historical_match_rechecks_per_cycle']??25)));
            while($this->timeLeft()&&$this->repo->metricCount($this->cycle,'cold_match_detail')<$cap&&($id=$this->repo->nextFinishedColdDueForCycle($seconds,$cycleStart))!==null)$this->fetchMatch($id,'cold_match_detail');
            $this->repo->stage('quick_boards');$stage='quick_boards';
        }
        if($stage==='quick_boards'){
            $this->repo->ensureQuickBoardCycle($this->cycle);
            while($this->timeLeft()&&($b=$this->repo->nextQuickBoardNeedingRefresh($this->cycle))!==null)$this->fetchBoard($b);
            $gqac=$this->repo->quickBoardCycleState($this->cycle);
            $this->achieved['gqac_total']=(int)($gqac['total']??0);$this->achieved['gqac_completed']=(int)($gqac['completed']??0);$this->achieved['gqac_pending']=(int)($gqac['pending']??0);$this->achieved['gqac_requeued']=(int)($gqac['requeued_for_next']??0);$this->achieved['gqac_deferred_transient']=(int)($gqac['deferred_transient']??0);$this->achieved['gqac_retired_ineligible']=(int)($gqac['retired_ineligible']??0);
            $this->repo->recordPhaseProgress('quick_boards','Quick Boards',(int)($gqac['pending']??0)>0?'running':'completed',(int)($gqac['completed']??0),(int)($gqac['total']??0),['eligible_now'=>(int)($gqac['eligible_now']??0),'claimed_active'=>(int)($gqac['claimed_active']??0),'deferred_transient'=>(int)($gqac['deferred_transient']??0)]);
            if((int)($gqac['pending']??0)>0)return;$this->repo->stage('quick_profiles');$stage='quick_profiles';
        }
        if($stage==='quick_profiles'){
            $pp=$this->repo->quickProfileProgress();$this->repo->recordPhaseProgress('quick_profiles','Quick Profiles','running',(int)$pp['completed'],(int)$pp['total'],['due'=>(int)$pp['due'],'departure_due'=>(int)$pp['departure_due']]);
            while($this->timeLeft()&&($u=$this->repo->nextProfileDue())!==null){if(!$this->fetchProfile($u))break;}
            $pp=$this->repo->quickProfileProgress();$this->repo->recordPhaseProgress('quick_profiles','Quick Profiles','running',(int)$pp['completed'],(int)$pp['total'],['due'=>(int)$pp['due'],'departure_due'=>(int)$pp['departure_due']]);
            if($this->repo->nextProfileDue()!==null)return;
            $leaveChecks=8;while($leaveChecks>0&&$this->timeLeft()&&($u=$this->repo->nextDepartureProfileCheck())!==null){if(!$this->fetchDepartureProfile($u))break;$leaveChecks--;}
            if($this->repo->nextDepartureProfileCheck()!==null&&!$this->timeLeft())return;
            $this->repo->stage('quick_stats');$stage='quick_stats';
        }
        if($stage==='quick_stats'){
            $seconds=max(3600,(int)(($this->config['app']['stats_refresh_seconds']??259200)));
            $sp=$this->repo->quickStatsProgress($seconds,$cycleStart);$this->repo->recordPhaseProgress('quick_stats','Quick Stats','running',(int)$sp['completed'],(int)$sp['total'],['due'=>(int)$sp['due'],'cutoff'=>(string)$sp['cutoff']]);
            while($this->timeLeft()&&($u=$this->repo->nextStatsDueForCycle($seconds,$cycleStart))!==null){if(!$this->fetchStats($u))break;}
            $sp=$this->repo->quickStatsProgress($seconds,$cycleStart);$this->repo->recordPhaseProgress('quick_stats','Quick Stats','running',(int)$sp['completed'],(int)$sp['total'],['due'=>(int)$sp['due'],'cutoff'=>(string)$sp['cutoff']]);
            if($this->repo->nextStatsDueForCycle($seconds,$cycleStart)!==null)return;
            $this->repo->completeCycle($this->achieved,'quick_index_roster');
        }
    }

    private function runDeep(array $state): void
    {
        $stage=(string)$state['stage'];
        if($stage==='deep_scan'){
            while($this->timeLeft()&&($id=$this->repo->nextDeepId())!==null){$x=$this->fetchMatch($id,'deep_probe',true);if(($x['status']??'')!=='other_club'&&strpos((string)($x['status']??''),'http_') !== 0)$this->inc('deep_p2k_matches');$this->inc('deep_ids_probed');}
            if($this->repo->deepRemaining()>0)return;$this->repo->stage('deep_hydrate');$stage='deep_hydrate';
        }
        if($stage==='deep_hydrate'){
            while($this->timeLeft()&&($id=$this->repo->nextUnknownMatch())!==null)$this->fetchMatch($id);
            if($this->repo->unknownMatchCount()>0)return;
            while($this->timeLeft()&&($b=$this->repo->nextBoardNeedingRefresh())!==null)$this->fetchBoard($b);
            if($this->repo->boardCountPending()>0)return;
            $this->repo->core->prepare("UPDATE p2k_g_state SET mode='quick',stage='quick_index_roster',deep_scan_from=NULL,deep_scan_to=NULL,deep_scan_cursor=NULL WHERE club_slug=?")->execute([$this->repo->clubSlug]);$this->repo->completeCycle($this->achieved);
        }
    }

    private function resultEnvelope(array $result,float $startedAt,string $leaseResult): array
    {
        $runtimeMs=(int)round((microtime(true)-$startedAt)*1000);
        $coreMs=$this->coreLockRuntimeMs??$runtimeMs;
        $result['telemetry']=[
            'feeder_id'=>(string)($this->context['feeder_id']??'worker'),
            'schedule_minute'=>$this->context['schedule_minute']??null,
            'lease_result'=>$leaseResult,
            'runtime_ms'=>$runtimeMs,
            'core_lock_runtime_ms'=>$coreMs,
            'post_lock_maintenance_ms'=>max(0,$runtimeMs-$coreMs),
            'timings_ms'=>$this->timingsMs,
            'request_count'=>$this->requestCount,
            'batch_counts'=>$this->achieved,
            'soft_target_seconds'=>$this->softTargetSeconds,
            'hard_budget_seconds'=>$this->hardBudgetSeconds,
            'soft_target_exceeded'=>$coreMs>($this->softTargetSeconds*1000),
            'hard_budget_exceeded'=>$coreMs>($this->hardBudgetSeconds*1000),
        ];
        return $result;
    }

    private function runPostLockAnalytics(): void
    {
        $got=0;try{$got=(int)$this->repo->core->query("SELECT GET_LOCK('p2k_green_analytics_maintenance',0)")->fetchColumn();}catch(\Throwable $ignored){$got=0;}
        if($got!==1){$this->inc('analytics_maintenance_skipped_busy');return;}
        try{
            // v2.10.6.16: the 30-second cadence introduced by v2.10.6.11 is too
            // aggressive for rebuilds that can take several minutes. Keep live Green
            // object projection immediate, but throttle full analytics materialization
            // to a five-minute minimum and never let an opportunistic rebuild fail the
            // finite Green worker after its lock has already been released.
            try{$rebuilt=$this->timed('green_analytics',fn()=>$this->repo->maybeRebuildAnalytics($this->cycle,300));if($rebuilt)$this->inc('green_analytics_rebuilds');}catch(\Throwable $e){$this->inc('green_analytics_errors');}
            try{$rebuilt=$this->timed('compat_analytics',fn()=>(new GreenCompatibility($this->repo))->maybeRebuildAnalytics(300));if($rebuilt)$this->inc('compat_analytics_rebuilds');}catch(\Throwable $e){$this->inc('compat_analytics_errors');}
        }finally{try{$this->repo->core->query("SELECT RELEASE_LOCK('p2k_green_analytics_maintenance')");}catch(\Throwable $ignored){}}
    }

    public function run(): array
    {
        $invStarted=microtime(true);$invocation=0;$finalStatus='success';$leaseResult='not_attempted';$lockHeld=false;
        $state=$this->repo->state();$target=(string)$state['worker_target'];
        if(!in_array($target,['green','both'],true))return $this->resultEnvelope(['ok'=>true,'status'=>'disabled','reason'=>'Green worker target is '.$target,'state'=>$state],$invStarted,'disabled');
        $lock=$this->repo->core->query("SELECT GET_LOCK('p2k_green_worker',0)")->fetchColumn();if((int)$lock!==1)return $this->resultEnvelope(['ok'=>true,'status'=>'busy','state'=>$state],$invStarted,'busy');$leaseResult='acquired';$lockHeld=true;
        try{
            $this->repo->core->prepare("UPDATE p2k_g_state SET last_worker_start=UTC_TIMESTAMP(),last_worker_status='running',last_error=NULL WHERE club_slug=?")->execute([$this->repo->clubSlug]);
            $state=$this->ensureCycle($this->repo->state());
            $repair=$this->repo->repairQuickBoardCycleInvariant();if(!empty($repair['repaired'])){$this->inc('quick_cycle_invariant_repairs');$state=$this->repo->state();}
            $this->cycle=(int)($state['cycle_no']??0);
            $invocation=$this->repo->startInvocation($this->cycle,(string)($state['mode']??''),(string)($state['stage']??''));
            if($state['cycle_started_at']===null&&$state['stage']==='not_started'){
                $finalStatus='waiting';$this->repo->core->prepare("UPDATE p2k_g_state SET last_worker_finish=UTC_TIMESTAMP(),last_worker_status='waiting' WHERE club_slug=?")->execute([$this->repo->clubSlug]);
                $result=['ok'=>true,'status'=>'waiting_for_findings','state'=>$this->repo->state()];
                $result=$this->resultEnvelope($result,$invStarted,$leaseResult);$this->repo->finishInvocation($invocation,$finalStatus,$invStarted,['request_count'=>0,'achieved'=>[],'gscf'=>$result['telemetry']]);return $result;
            }
            $mode=(string)$state['mode'];
            $forced=(string)($this->repo->state()['force_mode']??'auto');if($forced!=='auto'&&$forced!==$mode){$this->repo->forceStage($forced);$state=$this->ensureCycle($this->repo->state());$mode=$forced;$this->cycle=(int)$state['cycle_no'];}
            // v2.10.6 finite-cycle fairness. Continuous GAB/GFFL/current-match
            // sidecars must never consume the whole server invocation before the finite
            // Seed/Quick/Deep phase gets a chance to converge. Reserve ~70% of the soft
            // budget for the finite cycle first, then use the remaining budget for
            // sidecars, then give any tail capacity back to the finite cycle. GAB keeps
            // first priority in the browser accelerator; this server reservation is the
            // complementary anti-starvation rule.
            $globalDeadline=$this->deadline;
            $coreSlice=max(12.0,min((float)$this->softTargetSeconds-10.0,(float)$this->softTargetSeconds*0.70));
            $this->deadline=min($globalDeadline,microtime(true)+$coreSlice);
            $beforeCore=$this->requestCount;
            $this->timed('finite_cycle',function() use($mode){if($mode==='seeding')$this->runSeeding($this->repo->state());elseif($mode==='deep')$this->runDeep($this->repo->state());else $this->runQuick($this->repo->state());});
            $this->inc('finite_cycle_requests',$this->requestCount-$beforeCore);
            $this->deadline=$globalDeadline;

            // GAB server work is CPU/DB only; external HTTP enrichment remains
            // accelerator-fed. Do not add GAB/GFFL pseudo-phases to the finite cycle
            // progress list: each has its own dedicated telemetry panel.
            $gabState=(string)($this->repo->state()['gab_status']??'not_started');
            if(in_array($gabState,['running','error'],true)){$gabBootstrap=new GreenAnalyticsBootstrap($this->repo);if($gabState==='error')$gabBootstrap->resumeTransientErrorIfSafe();if((string)($this->repo->state()['gab_status']??'not_started')==='running'&&$this->timeLeft(8.0)){$this->timed('gab',fn()=>$gabBootstrap->runSlice(min(5.0,max(1.0,$this->softTargetSeconds/10))));$this->inc('gab_slices');}}
            $beforeGffl=$this->requestCount;$this->timed('gffl',function(){foreach($this->repo->gfflPlan(4) as $debt){if(!$this->timeLeft(7.0))break;$this->fetchMatch((int)$debt['match_id'],'gffl_match_detail');$this->inc('gffl_matches_served');}});$this->inc('gffl_requests',$this->requestCount-$beforeGffl);
            $beforeMaintenance=$this->requestCount;$this->timed('current_maintenance',fn()=>$this->maintainCurrentMatches((string)($this->repo->state()['stage']??''),4));$this->inc('current_maintenance_requests',$this->requestCount-$beforeMaintenance);

            // Tail pass: if the finite phase returned early because work was momentarily
            // blocked, sidecar observations may have retired/unblocked debt. Use any
            // remaining soft budget for the finite cycle again.
            $tailState=$this->repo->state();
            if($this->timeLeft(3.0)&&$this->sameActiveCycle($tailState,$this->cycle)){$beforeTail=$this->requestCount;$this->timed('finite_cycle_tail',function() use($mode){if($mode==='seeding')$this->runSeeding($this->repo->state());elseif($mode==='deep')$this->runDeep($this->repo->state());else $this->runQuick($this->repo->state());});$this->inc('finite_cycle_tail_requests',$this->requestCount-$beforeTail);}
            elseif(!$this->sameActiveCycle($tailState,$this->cycle))$this->inc('finite_cycle_tail_skipped_cycle_completed');

            // Finish the finite Green worker while still holding its global lock, then
            // release that lock before expensive analytics maintenance.  This keeps a
            // slow AnalyticsBuilder run from collapsing five staggered CRON entries into
            // one 4-8 minute serialized cadence.
            $finishState=$this->repo->state();$this->repo->core->prepare("UPDATE p2k_g_state SET last_worker_finish=UTC_TIMESTAMP(),last_worker_status='success' WHERE club_slug=?")->execute([$this->repo->clubSlug]);
            $this->coreLockRuntimeMs=(int)round((microtime(true)-$invStarted)*1000);
            try{$this->repo->core->query("SELECT RELEASE_LOCK('p2k_green_worker')");$lockHeld=false;}catch(\Throwable $ignored){}
            $this->runPostLockAnalytics();
            $result=$this->resultEnvelope(['ok'=>true,'status'=>'success','achieved'=>$this->achieved,'state'=>$this->repo->state()],$invStarted,$leaseResult);
            $this->repo->finishInvocation($invocation,'success',$invStarted,['request_count'=>$this->requestCount,'achieved'=>$this->achieved,'timings_ms'=>$this->timingsMs,'finish_state'=>$finishState,'gscf'=>$result['telemetry']]);
            return $result;
        }catch(\Throwable $e){
            $finalStatus='error';$this->repo->core->prepare("UPDATE p2k_g_state SET last_worker_finish=UTC_TIMESTAMP(),last_worker_status='error',last_error=? WHERE club_slug=?")->execute([substr($e->getMessage(),0,2000),$this->repo->clubSlug]);
            if($invocation>0){try{$this->repo->finishInvocation($invocation,'error',$invStarted,['request_count'=>$this->requestCount,'error'=>$e->getMessage(),'achieved'=>$this->achieved,'timings_ms'=>$this->timingsMs]);}catch(\Throwable $ignored){}}
            return $this->resultEnvelope(['ok'=>false,'status'=>'error','error'=>$e->getMessage(),'state'=>$this->repo->state()],$invStarted,$leaseResult);
        }
        finally{if($lockHeld){try{$this->repo->core->query("SELECT RELEASE_LOCK('p2k_green_worker')");}catch(\Throwable $ignored){}}}
    }

}
