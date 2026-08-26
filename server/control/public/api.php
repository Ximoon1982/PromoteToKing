<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/team-points/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tournaments/src/bootstrap.php';

use P2K\Shared\GatewayRetryableException;
use P2K\Shared\SharedChessGateway;
use P2K\Shared\TaskRegistry;
use P2K\TeamPoints\AcamrClaimStore;
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\FreshPointsReconstruction;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\PlayerMatchesFallbackState;
use P2K\TeamPoints\Worker;
use P2K\Tournaments\TournamentService;

function p2k_control_json_file(string $path, array $default = []): array
{
    if (!is_file($path)) return $default;
    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $default;
}


function p2k_control_acdm_snapshot(Repository $repository, string $clubSlug, ?array $playerRefresh = null): array
{
    if ($playerRefresh === null) {
        $coverage = $repository->synchronizationCoverage($clubSlug);
        $playerRefresh = is_array($coverage['player_refresh'] ?? null) ? $coverage['player_refresh'] : [];
    }
    $lane = static function (?array $job): array {
        $queue = is_array($job['queue'] ?? null) ? $job['queue'] : [];
        $active = (int)($queue['active_canonical'] ?? 0);
        if ($active <= 0) $active = (int)($queue['pending'] ?? 0) + (int)($queue['running'] ?? 0) + (int)($queue['retry'] ?? 0);
        return [
            'active_canonical' => max(0, $active),
            'pending' => max(0, (int)($queue['pending'] ?? 0)),
            'running' => max(0, (int)($queue['running'] ?? 0)),
            'retry' => max(0, (int)($queue['retry'] ?? 0)),
            'failed' => max(0, (int)($queue['failed'] ?? 0)),
            'status' => (string)($job['status'] ?? 'none'),
        ];
    };
    $clubJob = $repository->jobDetails($repository->latestJob($clubSlug, 'club'));
    $playerJob = $repository->jobDetails($repository->latestJob($clubSlug, 'player'));
    $club = $lane($clubJob);
    $player = $lane($playerJob);
    $canonicalPlayerDue = max(0, (int)($playerRefresh['canonical_due_now'] ?? ((int)($playerRefresh['matches_due'] ?? 0) + (int)($playerRefresh['stats_due'] ?? 0))));
    // Queue work and freshness checks overlap substantially, so use the larger debt
    // rather than double-counting the same member verification in both views.
    $clubDebt = $club['active_canonical'];
    $playerDebt = max($player['active_canonical'], $canonicalPlayerDue);
    $state = $repository->state($clubSlug);
    $age = static function ($value): ?int {
        $text = trim((string)$value); if ($text === '') return null;
        $epoch = strtotime($text . (str_contains($text, 'T') || str_contains($text, '+') || str_ends_with($text, 'Z') ? '' : ' UTC'));
        return $epoch === false ? null : max(0, time() - $epoch);
    };
    $clubIndexAge = $age($state['club_index_last_verified_at'] ?? null);
    $rosterAge = $age($state['members_last_verified_at'] ?? null);
    $clubUrgent = $clubIndexAge === null || $clubIndexAge >= 3600;
    $playerUrgent = $rosterAge === null || $rosterAge >= 3600;
    $clubEffective = max($clubDebt, $clubUrgent ? 1 : 0);
    $playerEffective = max($playerDebt, $playerUrgent ? 1 : 0);
    $clubScore = $clubEffective + ($clubUrgent ? max(25, min(1000, (int)round($clubEffective * .2) + 100)) : 0);
    $playerScore = $playerEffective + ($playerUrgent ? max(25, min(1000, (int)round($playerEffective * .2) + 100)) : 0);
    $recommended = $playerScore > $clubScore ? 'player' : 'club';
    if ($clubEffective <= 0 && $playerEffective > 0) $recommended = 'player';
    if ($playerEffective <= 0 && $clubEffective > 0) $recommended = 'club';
    $total = $clubEffective + $playerEffective;
    $quota = $total >= 10000 ? 64 : ($total >= 5000 ? 56 : ($total >= 1000 ? 48 : ($total >= 100 ? 32 : ($total >= 20 ? 16 : 8))));
    $seconds = $quota >= 48 ? 34 : ($quota >= 32 ? 30 : ($quota >= 16 ? 24 : 16));
    $delay = $total >= 100 ? 250 : ($total >= 20 ? 500 : 1200);
    return [
        'mode' => $total > 0 ? 'canonical-drain' : 'idle',
        'total_debt' => $total,
        'recommended_lane' => $recommended,
        'club' => $club + ['debt'=>$clubEffective,'queue_debt'=>$clubDebt,'urgency_score'=>$clubScore,'club_index_age_seconds'=>$clubIndexAge,'freshness_urgent'=>$clubUrgent],
        'player' => $player + ['debt'=>$playerEffective,'queue_debt'=>$player['active_canonical'],'canonical_checks_due'=>$canonicalPlayerDue,'urgency_score'=>$playerScore,'roster_age_seconds'=>$rosterAge,'freshness_urgent'=>$playerUrgent],
        'suggested_quota' => $quota,
        'suggested_max_seconds' => $seconds,
        'suggested_next_delay_ms' => $delay,
        'server_authoritative' => true,
        'yield_to_foreground' => true,
    ];
}

function p2k_control_cron_shell_status(string $key): array
{
    $config = p2k_tp_config();
    $storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
    $root = \P2K\Shared\FilesystemCache::runtimeRoot($storage) . '/cron-shell';
    $path = $root . '/last-' . preg_replace('/[^a-z0-9_-]+/i', '-', $key) . '.json';
    $row = p2k_control_json_file($path, []);
    if (!$row) return ['observed'=>false,'status'=>'never_observed','last_started_at'=>null,'last_completed_at'=>null,'http_status'=>0,'exit_code'=>null];
    $last = trim((string)($row['last_started_at'] ?? ''));
    $epoch = $last !== '' ? strtotime($last) : false;
    return $row + ['observed'=>true,'age_seconds'=>$epoch === false ? null : max(0,time()-$epoch)];
}

function p2k_control_work_details(string $key, Repository $repository, string $clubSlug): array
{
    $siteRoot = dirname(__DIR__, 3);
    if (in_array($key, ['team-points-club','team-points-player','team-points'], true)) {
        $lane = $key === 'team-points-player' ? 'player' : ($key === 'team-points-club' ? 'club' : null);
        $pdo = $repository->core();
        // v2.9.22.10 telemetry restoration: selected-card details remain lane-local and
        // cheap, but they must still explain freshness, durable debt and the latest
        // useful state even when the current controller invocation has no runnable item.
        $jobRaw = $repository->latestJob($clubSlug, $lane ?? 'combined');
        if (!is_array($jobRaw) && $lane === null) $jobRaw = $repository->latestJob($clubSlug);
        // Repository::summary is intentionally not called here: task-detail must stay lane-local.
        $job = null;
        if (is_array($jobRaw)) {
            $job = $jobRaw;
            $jobId = (string)$jobRaw['id'];
            // Only active/retry/failure rows are counted. Historical done/skipped rows
            // stay represented by the job's maintained total/processed counters.
            $activeQ=$pdo->prepare("SELECT status,COUNT(*) n,COALESCE(SUM(coalesced_count),0) coalesced FROM p2k_tp_job_items FORCE INDEX (idx_tp_job_queue) WHERE job_id=? AND status IN ('pending','running','retry','failed') GROUP BY status");
            $activeQ->execute([$jobId]);
            $queue=['pending'=>0,'running'=>0,'retry'=>0,'failed'=>0,'done'=>0,'skipped'=>0,'coalesced_requests'=>0,'active_canonical'=>0];
            foreach($activeQ->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$st=(string)$row['status'];$queue[$st]=(int)$row['n'];$queue['coalesced_requests']+=(int)$row['coalesced'];}
            $queue['total']=(int)($jobRaw['total_items']??0);
            $queue['committed']=min($queue['total'],(int)($jobRaw['processed_items']??0));
            $queue['remaining_backlog']=max(0,$queue['total']-$queue['committed']);
            $queue['active_canonical']=$queue['pending']+$queue['running']+$queue['retry'];
            $job['queue']=$queue;
            $job['task_breakdown']=[];
            $job['task_breakdown_deferred']=true;
            $job['current_item']=$repository->currentItem($jobId);
            $job['next_retry_at']=$repository->nextRetryAt($jobId);
        }

        $age = static function(mixed $value): ?int {
            $text=trim((string)$value); if($text==='')return null;
            $epoch=strtotime($text.(str_contains($text,'T')||str_contains($text,'+')||str_ends_with($text,'Z')?'':' UTC'));
            return $epoch===false?null:max(0,time()-$epoch);
        };
        $stateQ=$pdo->prepare('SELECT core_generation,members_last_observed_at,members_last_verified_at,members_last_observed_count,members_count_observed_at,club_index_last_observed_at,club_index_last_verified_at,club_index_registered_observed,club_index_in_progress_observed,club_index_finished_observed,updated_at FROM p2k_tp_state WHERE club_slug=? LIMIT 1');
        $stateQ->execute([$clubSlug]);$freshState=$stateQ->fetch(PDO::FETCH_ASSOC)?:[];
        $metrics=['detail_mode'=>'lane-local-telemetry-v292210','core_generation'=>(int)($freshState['core_generation']??0),'state_updated_at'=>$freshState['updated_at']??null];

        if($lane==='club'){
            $mq=$pdo->prepare("SELECT COUNT(*) known_matches,
                SUM(status='registered') registered_matches,
                SUM(status='in_progress') in_progress_matches,
                SUM(status='finished' AND is_void=0) finished_matches,
                SUM(status='finished' AND is_void=1) void_matches,
                COALESCE(SUM(CASE WHEN status='finished' AND is_void=0 THEN competition_points ELSE 0 END),0) club_points,
                SUM(status IN ('registered','in_progress') AND (next_detail_check_at IS NULL OR next_detail_check_at<=UTC_TIMESTAMP())) active_detail_checks_due
                FROM p2k_tp_match_metadata WHERE club_slug=?");
            $mq->execute([$clubSlug]);$m=$mq->fetch(PDO::FETCH_ASSOC)?:[];
            $metrics += [
                'current_members_observed'=>(int)($freshState['members_last_observed_count']??0),
                'member_count_observed_at'=>$freshState['members_count_observed_at']??($freshState['members_last_observed_at']??null),
                'member_count_observed_age_seconds'=>$age($freshState['members_count_observed_at']??($freshState['members_last_observed_at']??null)),
                'known_matches'=>(int)($m['known_matches']??0),
                'registered_matches'=>(int)($m['registered_matches']??0),
                'in_progress_matches'=>(int)($m['in_progress_matches']??0),
                'finished_matches'=>(int)($m['finished_matches']??0),
                'void_matches'=>(int)($m['void_matches']??0),
                'club_points'=>(int)($m['club_points']??0),
                'active_detail_checks_due'=>(int)($m['active_detail_checks_due']??0),
                'club_index_observed_at'=>$freshState['club_index_last_observed_at']??null,
                'club_index_observed_age_seconds'=>$age($freshState['club_index_last_observed_at']??null),
                'club_index_verified_at'=>$freshState['club_index_last_verified_at']??null,
                'club_index_verified_age_seconds'=>$age($freshState['club_index_last_verified_at']??null),
                'index_registered_observed'=>(int)($freshState['club_index_registered_observed']??0),
                'index_in_progress_observed'=>(int)($freshState['club_index_in_progress_observed']??0),
                'index_finished_observed'=>(int)($freshState['club_index_finished_observed']??0),
                'algorithm'=>'fast club-points lane',
            ];
        } elseif($lane==='player') {
            $cfg=p2k_tp_config();$app=is_array($cfg['app']??null)?$cfg['app']:[];
            $matchesCutoff=gmdate('Y-m-d H:i:s',time()-max(86400,(int)($app['player_reconcile_matches_refresh_seconds']??604800)));
            $statsCutoff=gmdate('Y-m-d H:i:s',time()-max(86400,(int)($app['player_reconcile_stats_refresh_seconds']??259200)));
            $rq=$pdo->prepare("SELECT COUNT(*) current_members,
                SUM(player_matches_checked_at IS NULL OR player_matches_checked_at<?) matches_verified_due,
                SUM(GREATEST(COALESCE(player_matches_checked_at,'1970-01-01'),COALESCE(player_matches_observed_at,'1970-01-01'))<?) matches_operational_due,
                SUM(stats_checked_at IS NULL OR stats_checked_at<?) stats_verified_due,
                SUM(GREATEST(COALESCE(stats_checked_at,'1970-01-01'),COALESCE(stats_observed_at,'1970-01-01'))<?) stats_operational_due
                FROM p2k_tp_members WHERE club_slug=? AND current_member=1");
            $rq->execute([$matchesCutoff,$matchesCutoff,$statsCutoff,$statsCutoff,$clubSlug]);$refresh=$rq->fetch(PDO::FETCH_ASSOC)?:[];$n=max(1,(int)($refresh['current_members']??0));
            $knownQ=$pdo->prepare('SELECT COUNT(*) FROM p2k_tp_members WHERE club_slug=?');$knownQ->execute([$clubSlug]);
            $boardsQ=$pdo->prepare('SELECT COUNT(*) FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=?');$boardsQ->execute([$clubSlug]);
            $gamesQ=$pdo->prepare('SELECT COUNT(*) FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=?');$gamesQ->execute([$clubSlug]);
            $pointsQ=$pdo->prepare('SELECT COALESCE(SUM(g.points_x2),0)/2 FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=?');$pointsQ->execute([$clubSlug]);
            $mv=(int)($refresh['matches_verified_due']??0);$mo=(int)($refresh['matches_operational_due']??0);$sv=(int)($refresh['stats_verified_due']??0);$so=(int)($refresh['stats_operational_due']??0);
            $metrics += [
                'current_members'=>(int)($refresh['current_members']??0),
                'known_members'=>(int)$knownQ->fetchColumn(),
                'boards'=>(int)$boardsQ->fetchColumn(),
                'games'=>(int)$gamesQ->fetchColumn(),
                'points'=>(float)$pointsQ->fetchColumn(),
                'player_matches_operational_fresh_percent'=>round(100*max(0,$n-$mo)/$n,1),
                'player_matches_server_verified_percent'=>round(100*max(0,$n-$mv)/$n,1),
                'player_matches_operational_due'=>$mo,
                'player_matches_server_due'=>$mv,
                'player_stats_operational_fresh_percent'=>round(100*max(0,$n-$so)/$n,1),
                'player_stats_server_verified_percent'=>round(100*max(0,$n-$sv)/$n,1),
                'player_stats_operational_due'=>$so,
                'player_stats_server_due'=>$sv,
                // Compatibility names consumed by the task-card progress calculation.
                'refresh_matches_operational_fresh_percent'=>round(100*max(0,$n-$mo)/$n,1),
                'refresh_matches_fresh_percent'=>round(100*max(0,$n-$mv)/$n,1),
                'refresh_stats_operational_fresh_percent'=>round(100*max(0,$n-$so)/$n,1),
                'refresh_stats_fresh_percent'=>round(100*max(0,$n-$sv)/$n,1),
                'members_observed_at'=>$freshState['members_last_observed_at']??null,
                'members_observed_age_seconds'=>$age($freshState['members_last_observed_at']??null),
                'members_verified_at'=>$freshState['members_last_verified_at']??null,
                'members_verified_age_seconds'=>$age($freshState['members_last_verified_at']??null),
                'algorithm'=>'player reconciliation lane',
            ];
        } else {
            $metrics['algorithm']='seeded incremental';
        }

        if($job){
            $q=$job['queue'];
            $metrics += [
                'job_status'=>(string)($job['status']??'unknown'),
                'job_updated_at'=>$job['updated_at']??null,
                'job_age_seconds'=>$age($job['updated_at']??null),
                'durable_total'=>(int)($q['total']??$job['total_items']??0),
                'durable_committed'=>(int)($q['committed']??$job['processed_items']??0),
                'durable_remaining'=>(int)($q['remaining_backlog']??0),
                'queue_pending'=>(int)($q['pending']??0),
                'queue_running'=>(int)($q['running']??0),
                'queue_retry'=>(int)($q['retry']??0),
                'queue_failed'=>(int)($q['failed']??0),
                'active_canonical'=>(int)($q['active_canonical']??0),
                'duplicate_requests_active'=>(int)($q['coalesced_requests']??0),
                'queue_coalesced_requests'=>(int)($q['coalesced_requests']??0),
                'next_retry_at'=>$job['next_retry_at']??null,
                'current_item_type'=>is_array($job['current_item']??null)?($job['current_item']['item_type']??null):null,
                'current_item_key'=>is_array($job['current_item']??null)?($job['current_item']['item_key']??null):null,
            ];
        } else {
            $metrics += ['job_status'=>'none','durable_total'=>0,'durable_committed'=>0,'durable_remaining'=>0,'queue_pending'=>0,'queue_running'=>0,'queue_retry'=>0,'queue_failed'=>0,'active_canonical'=>0];
        }

        $queueReport='';
        if($job){
            $q=$job['queue'];$pending=(int)($q['pending']??0);$running=(int)($q['running']??0);$retry=(int)($q['retry']??0);$failed=(int)($q['failed']??0);$coalesced=(int)($q['coalesced_requests']??0);$activeCanonical=(int)($q['active_canonical']??0);$total=(int)($q['total']??$job['total_items']??0);$committed=(int)($q['committed']??$job['processed_items']??0);$remaining=(int)($q['remaining_backlog']??max(0,$total-$committed));
            $queueReport=sprintf('%s %s job: %d of %d durable queue items committed; %d remaining/backlog; %d pending; %d running; %d retry; %d failed. Active canonical work: %d. Duplicate requests observed on active rows: %d.',ucfirst((string)($lane??'combined')),(string)($job['status']??'Unknown'),$committed,$total,$remaining,$pending,$running,$retry,$failed,$activeCanonical,$coalesced);
        }
        if($lane==='club'){
            $fresh=sprintf(' Club index: observed %s (%s s old), server-verified %s (%s s old); %d registered + %d in-progress currently observed; %d active detail check(s) due.',
                (string)($metrics['club_index_observed_at']??'never'),$metrics['club_index_observed_age_seconds']===null?'n/a':(string)$metrics['club_index_observed_age_seconds'],
                (string)($metrics['club_index_verified_at']??'never'),$metrics['club_index_verified_age_seconds']===null?'n/a':(string)$metrics['club_index_verified_age_seconds'],
                (int)($metrics['index_registered_observed']??0),(int)($metrics['index_in_progress_observed']??0),(int)($metrics['active_detail_checks_due']??0));
            $queueReport=($queueReport!==''?$queueReport:'No Club Points durable job exists.') . $fresh;
        } elseif($lane==='player') {
            $fresh=sprintf(' Member Points convergence: %.1f%% player-match operational freshness (%.1f%% server-verified) and %.1f%% stats operational freshness (%.1f%% server-verified) across %d current members; %d player-match operational checks / %d canonical checks and %d stats operational checks / %d canonical checks are due.',
                (float)($metrics['player_matches_operational_fresh_percent']??0),(float)($metrics['player_matches_server_verified_percent']??0),(float)($metrics['player_stats_operational_fresh_percent']??0),(float)($metrics['player_stats_server_verified_percent']??0),(int)($metrics['current_members']??0),(int)($metrics['player_matches_operational_due']??0),(int)($metrics['player_matches_server_due']??0),(int)($metrics['player_stats_operational_due']??0),(int)($metrics['player_stats_server_due']??0));
            $fresh .= ' Browser-observed freshness can suppress duplicate discovery calls, while canonical facts remain server-verified. Queue items are recurring operational work and are not used as the convergence percentage.';
            $queueReport=$fresh . ($queueReport!==''?' '.$queueReport:' No Member Points durable job exists.');
        } elseif($queueReport==='') $queueReport='No Team Points incremental collection job exists yet.';
        return ['summary'=>$metrics,'seed'=>null,'job'=>$job,'work_report'=>$queueReport];
    }
    if ($key === 'match-tracking') {
        $registryPath = $siteRoot . '/data/match-tracking/index.json';
        $registry = p2k_control_json_file($registryPath, ['matches'=>[],'revision'=>0]);
        $backup = p2k_control_json_file($registryPath . '.bak', ['matches'=>[],'revision'=>0]);
        $primaryMatches = is_array($registry['matches'] ?? null) ? $registry['matches'] : [];
        $backupMatches = is_array($backup['matches'] ?? null) ? $backup['matches'] : [];
        if (count($backupMatches) > count($primaryMatches) || (int)($backup['revision'] ?? 0) > (int)($registry['revision'] ?? 0)) { $registry = $backup + ['recoverySource'=>'index.json.bak']; }
        $matches = is_array($registry['matches'] ?? null) ? $registry['matches'] : [];
        if (!$matches) {
            foreach (glob($siteRoot . '/data/match-tracking/matches/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $id = basename($dir); if (!preg_match('/^\d+$/',$id)) continue;
                $matches[$id] = ['id'=>$id,'followed'=>false,'status'=>'unknown','recoveredFromHistory'=>true];
            }
            if ($matches) $registry['recoverySource']='history-directories';
        }
        $statuses = ['registration'=>0,'ongoing'=>0,'finished'=>0];
        $phases = ['first-24-hours'=>0,'within-48-hours'=>0,'within-96-hours'=>0,'standard'=>0];
        $followed = 0; $due = 0;
        $now = time();
        foreach ($matches as $match) {
            if (!is_array($match)) continue;
            if (($match['followed'] ?? false) === true) $followed++;
            $status = strtolower((string)($match['status'] ?? ''));
            if (isset($statuses[$status])) $statuses[$status]++;
            $added = !empty($match['addedAt']) ? strtotime((string)$match['addedAt']) : false;
            $last = !empty($match['lastCapturedAt']) ? strtotime((string)$match['lastCapturedAt']) : false;
            $start = !empty($match['startTime']) ? (int)$match['startTime'] : null;
            $age = $added === false ? 0 : max(0, $now - $added);
            $until = $start ? $start - $now : null;
            if ($added === false || $age < 86400) { $interval = 3600; $phase = 'first-24-hours'; }
            elseif ($until !== null && $until > 0 && $until <= 172800) { $interval = 3600; $phase = 'within-48-hours'; }
            elseif ($until !== null && $until > 0 && $until <= 345600) { $interval = 21600; $phase = 'within-96-hours'; }
            else { $interval = 43200; $phase = 'standard'; }
            $phases[$phase]++;
            if ($last === false || $last + $interval <= $now) $due++;
        }
        return [
            'summary' => [
                'known_matches'=>count($matches),
                'followed_matches'=>$followed,
                'due_now'=>$due,
                'hourly_first_day'=>$phases['first-24-hours'],
                'hourly_within_48h'=>$phases['within-48-hours'],
                'six_hourly_within_96h'=>$phases['within-96-hours'],
                'twelve_hourly_standard'=>$phases['standard'],
                'registration'=>$statuses['registration'],
                'ongoing'=>$statuses['ongoing'],
                'finished'=>$statuses['finished'],
                'registry_revision'=>(int)($registry['revision'] ?? 0),
                'updated_at'=>$registry['updatedAt'] ?? null,
                'recovery_source'=>$registry['recoverySource'] ?? null,
            ],
            'work_report' => sprintf('%d tracked matches; %d currently due. The CRON checks hourly, while each match is sampled hourly for its first 24 hours, every 12 hours normally, every 6 hours inside 96 hours of the start, and hourly inside 48 hours.', count($matches), $due),
        ];
    }
    if ($key === 'tournaments') {
        $archive = (new TournamentService())->archive();
        $tournaments = is_array($archive['tournaments'] ?? null) ? $archive['tournaments'] : [];
        $counts = ['finished'=>0,'in_progress'=>0,'registration'=>0,'unknown'=>0];
        foreach ($tournaments as $tournament) {
            $status = strtolower((string)($tournament['status'] ?? 'unknown'));
            if (str_contains($status, 'finish') || str_contains($status, 'complete')) $counts['finished']++;
            elseif (str_contains($status, 'progress') || str_contains($status, 'active')) $counts['in_progress']++;
            elseif (str_contains($status, 'register') || str_contains($status, 'upcoming')) $counts['registration']++;
            else $counts['unknown']++;
        }
        $scan = is_array($archive['scanState'] ?? null) ? $archive['scanState'] : [];
        return [
            'summary' => [
                'known_tournaments'=>count($tournaments),
                'finished'=>$counts['finished'],
                'in_progress'=>$counts['in_progress'],
                'registration'=>$counts['registration'],
                'unknown'=>$counts['unknown'],
                'archive_generated_at'=>$archive['generatedAt'] ?? null,
                'last_status_refresh'=>$archive['lastStatusRefresh'] ?? null,
                'batch_remaining'=>(int)($scan['serverBatch']['batchRemaining'] ?? $scan['batchRemaining'] ?? 0),
                'recovery_source'=>$archive['recoverySource'] ?? null,
            ],
            'work_report' => sprintf('%d tournaments are stored in the archive. The resumable discovery, status and podium stages are advanced every ten minutes.', count($tournaments)),
        ];
    }
    return ['summary'=>[], 'work_report'=>'No domain adapter is registered.'];
}

try {
    $config = p2k_tp_config();
    $origin = trim((string)($config['app']['allowed_origin'] ?? ''));
    if ($origin !== '') { header('Access-Control-Allow-Origin: ' . rtrim($origin, '/')); header('Vary: Origin'); }
    header('Access-Control-Allow-Headers: Content-Type, X-P2K-Admin-Token, X-P2K-CSRF');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') { http_response_code(204); exit; }

    Auth::requireAdmin();
    $pdo = Database::connection();
    $repository = new Repository($pdo);
    $before = $repository->schemaVersion();
    if (!$repository->schemaInstalled()) {
        if ($before <= 0) $repository->installSchema(dirname(__DIR__, 2) . '/team-points/sql/schema.sql');
        else $repository->upgradeExistingSchema(dirname(__DIR__, 2) . '/team-points/sql/schema.sql');
    }
    if (!$repository->schemaInstalled()) throw new ApiException('The shared control database schema could not be prepared.', 503, 'SCHEMA_INSTALL_REQUIRED');

    $registry = new TaskRegistry($pdo);
    $gateway = new SharedChessGateway($pdo, $config['app'] ?? []);
    $clubSlug = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
    $action = strtolower(trim((string)($_GET['action'] ?? 'status')));

    if ($action === 'status') {
        Http::method('GET');
        $tasks = [];
        // v2.9.22.5: keep the task-grid bootstrap intentionally cheap. Large
        // durable queues made the old status call compute every Club/Player work
        // report before returning any card. Detailed work is now lazy per task.
        foreach ($registry->list() as $task) {
            $key = (string)$task['task_key'];
            $task['work'] = ['summary'=>[], 'deferred'=>true];
            $task['cron_shell'] = p2k_control_cron_shell_status($key);
            $task['work']['summary']['cron_shell_status'] = (string)($task['cron_shell']['status'] ?? 'never_observed');
            $task['work']['summary']['cron_shell_last_invocation'] = $task['cron_shell']['last_started_at'] ?? null;
            $task['work']['summary']['cron_shell_http_status'] = (int)($task['cron_shell']['http_status'] ?? 0);
            $tasks[] = $task;
        }
        Http::json([
            'ok'=>true,
            'server_utc'=>p2k_tp_utc_now()->format(DATE_ATOM),
            'database_connected'=>true,
            'schema_version'=>$repository->schemaVersion(),
            'gateway'=>$gateway->status(),
            'reconstruction'=>null,
            'tasks'=>$tasks,
            'thresholds'=>[
                'team-points-club'=>300,
                'team-points-player'=>600,
                'match-tracking'=>3600,
                'tournaments'=>600,
            ],
            'compatibility'=>[
                'legacy_endpoints_preserved'=>true,
                'team_points_club'=>'server/team-points/public/cron-club.php',
                'team_points_player'=>'server/team-points/public/cron-player.php',
                'team_points_legacy'=>'server/team-points/public/cron.php',
                'match_tracking'=>'api/track-upcoming-league-matches/',
                'tournaments'=>'server/tournaments/public/cron.php',
            ],
        ]);
    }

    if ($action === 'task-detail') {
        Http::method('GET');
        $taskKey = strtolower(trim((string)($_GET['task'] ?? '')));
        if (!isset(TaskRegistry::DEFINITIONS[$taskKey]) || $taskKey === 'team-points') throw new ApiException('Unknown scheduled task.', 404, 'UNKNOWN_TASK');
        $task = $registry->task($taskKey);
        if (!is_array($task)) throw new ApiException('Scheduled task is unavailable.', 404, 'UNKNOWN_TASK');
        $task['work'] = p2k_control_work_details($taskKey, $repository, $clubSlug);
        $task['cron_shell'] = p2k_control_cron_shell_status($taskKey);
        if (is_array($task['work']['summary'] ?? null)) {
            $task['work']['summary']['cron_shell_status'] = (string)($task['cron_shell']['status'] ?? 'never_observed');
            $task['work']['summary']['cron_shell_last_invocation'] = $task['cron_shell']['last_started_at'] ?? null;
            $task['work']['summary']['cron_shell_http_status'] = (int)($task['cron_shell']['http_status'] ?? 0);
        }
        Http::json(['ok'=>true,'server_utc'=>p2k_tp_utc_now()->format(DATE_ATOM),'task'=>$task]);
    }

    if ($action === 'client-refresh-worker-pulse') {
        Http::method('POST');
        $body = Http::body();
        $lane = strtolower(trim((string)($body['lane'] ?? 'club')));
        if (!in_array($lane, ['club','player'], true)) throw new ApiException('Invalid ACSR worker lane.', 400, 'INVALID_ACSR_LANE');
        $config = p2k_tp_config();
        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        // ACDC: gateway lock/connect/request waits now share one absolute deadline.
        // Browser pulses no longer need to pre-reserve a worst-case 8s lock + full
        // request timeout before they can make useful canonical progress.
        $minimumViableSeconds = 8;
        $maxSeconds = max($minimumViableSeconds, min(36, (int)($body['max_seconds'] ?? 34)));
        $absoluteDeadlineAt = microtime(true) + $maxSeconds - 2.0;
        $canonicalQuota = max(1,min(64,(int)($body['canonical_quota'] ?? 16)));
        $taskKey = $lane === 'player' ? 'team-points-player' : 'team-points-club';
        if ($registry->isPaused($taskKey)) {
            Http::json(['ok'=>true,'status'=>'paused','lane'=>$lane,'processed_items'=>0,'message'=>'ACSR respected the operator pause; no authoritative worker pulse ran.']);
        }
        $job = $repository->latestJob($clubSlug, $lane);
        if (!is_array($job) || !in_array((string)($job['status'] ?? ''), ['new','running'], true)) {
            $job = $repository->createOrGetActiveJob($clubSlug, $lane);
        }
        $runId = 'acsr-' . $lane . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        if (!$registry->beginRun($taskKey, 'acsr-browser', $runId)) {
            Http::json(['ok'=>true,'status'=>'busy','lane'=>$lane,'processed_items'=>0,'message'=>'Another authoritative execution already owns this lane; ACSR pulse coalesced.']);
        }
        try {
            $worker = new Worker($pdo, $repository, new ChessApi($repository), $lane);
            $result = $worker->run((string)$job['id'], 'acsr-browser', $maxSeconds, $absoluteDeadlineAt, $canonicalQuota);
            $processed = max(0, (int)($result['processed_items'] ?? $result['processed'] ?? 0));
            $failed = max(0, (int)($result['failed_items'] ?? $result['failed'] ?? 0));
            $workerStatus = strtolower((string)($result['status'] ?? 'partial'));
            $postCoverage = $repository->synchronizationCoverage($clubSlug);
            $postRefresh = is_array($postCoverage['player_refresh'] ?? null) ? $postCoverage['player_refresh'] : [];
            $drain = p2k_control_acdm_snapshot($repository, $clubSlug, $postRefresh);
            $final = $workerStatus === 'failed' ? 'failed' : ($failed > 0 ? 'partial' : ($processed > 0 ? 'success' : 'partial'));
            $message = (string)($result['message'] ?? 'Bounded ACSR authoritative worker pulse completed.');
            $registry->finishRun($taskKey, $runId, $final, ['processed'=>$processed,'updated'=>$processed,'failed'=>$failed], $message, [
                'mode'=>'acsr_bounded_browser_pulse','lane'=>$lane,'max_seconds'=>$maxSeconds,'minimum_viable_seconds'=>$minimumViableSeconds,'canonical_quota'=>$canonicalQuota,'server_authoritative'=>true,'canonical_queue_coalescing'=>true,'absolute_deadline_control'=>true,'acdm'=>true,'remaining_canonical_debt'=>(int)($drain['total_debt']??0),
                'worker_status'=>$workerStatus,'worker_processed_items'=>$processed,'worker_idle_reason'=>$result['idle_reason']??null,'worker_job_status'=>$result['job_status']??($result['job']['status']??null),'worker_next_retry_at'=>$result['next_retry_at']??null,
            ]);
            Http::json(['ok'=>true,'status'=>$workerStatus,'lane'=>$lane,'processed_items'=>$processed,'execution_attempted_items'=>(int)($result['execution_attempted_items']??$processed),'terminal_committed_items'=>(int)($result['terminal_committed_items']??0),'failed_items'=>$failed,'message'=>$message,'max_seconds'=>$maxSeconds,'minimum_viable_seconds'=>$minimumViableSeconds,'canonical_quota'=>$canonicalQuota,'server_authoritative'=>true,'absolute_deadline_control'=>true,'acdm'=>true,'productive'=>$processed>0,'canonical_drain'=>$drain]);
        } catch (Throwable $exception) {
            $registry->finishRun($taskKey, $runId, 'failed', ['failed'=>1], $exception->getMessage(), ['mode'=>'acsr_bounded_browser_pulse','lane'=>$lane]);
            throw $exception;
        }
    }

    if ($action === 'client-refresh-plan') {
        Http::method('POST');
        $body = Http::body();
        $cursor = max(0, (int)($body['cursor'] ?? 0));
        $maxTasks = max(4, min(512, (int)($body['max_tasks'] ?? 48)));
        $config = p2k_tp_config();
        $storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        $runtime = \P2K\Shared\FilesystemCache::runtimeRoot($storage) . '/acamr';
        \P2K\Shared\FilesystemCache::ensureProtectedDirectory($runtime);
        $claimTtl = max(300, min(3600, (int)($app['acamr_claim_ttl_seconds'] ?? 1200)));
        // Manual continuous refresh uses exactly the same bounded shard ledgers
        // as ACAMR. It can no longer generate one file per member or claim.
        $claimStore = new AcamrClaimStore($storage);
        $claimStore->cleanup(100,100,max(3600,$claimTtl*4),8);
        // ACDC/PMAF: browser acquisition respects the authoritative fallback cooldown.
        // While /matches is known endpoint-specifically unusable, the planner must not
        // keep hammering it; the server Worker owns the scheduled primary re-probe.
        $pmafState = new PlayerMatchesFallbackState($storage,$app);
        $pmafSuppressedMatches = 0;
        $claimMember = static fn(array $row): bool => $claimStore->claimMember($row,$claimTtl,'task-control','client_refresh');
        $issueToken = static fn(?string $username,array $tasks): string => $claimStore->issue($username,$tasks,$claimTtl,'task-control','client_refresh');
        $claimPeriodic = static function(string $name, int $seconds) use($runtime): bool {
            $path = $runtime . '/client-refresh-' . preg_replace('/[^a-z0-9_-]+/i','-',$name) . '.json';
            $handle = @fopen($path, 'c+'); if (!$handle) return false;
            try {
                if (!flock($handle, LOCK_EX)) return false;
                rewind($handle); $raw = stream_get_contents($handle); $previous = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                $last = is_array($previous) ? (int)($previous['claimed_at'] ?? 0) : 0;
                if ($last > 0 && time() - $last < $seconds) return false;
                ftruncate($handle, 0); rewind($handle); fwrite($handle, json_encode(['claimed_at'=>time()], JSON_UNESCAPED_SLASHES)); fflush($handle); return true;
            } finally { @flock($handle, LOCK_UN); @fclose($handle); }
        };

        $tasks = []; $nextCursor = $cursor; $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        // Archives are a fallback accelerator, not the primary convergence path.
        // ACAMR and Continuous Refresh share one fixed-window budget so neither
        // producer can recreate a current-month archive storm.
        $archiveWindowSeconds = 600; $archiveWindowCap = 12;
        $claimArchiveSlot = static fn(): bool => $claimStore->claimArchiveSlot($archiveWindowSeconds,$archiveWindowCap);
        if ($claimPeriodic('club-index', 600) && count($tasks) < $maxTasks) {
            $rows = [['kind'=>'club_index','url'=>'https://api.chess.com/pub/club/' . rawurlencode($clubSlug) . '/matches']];
            $token = $issueToken(null, $rows); foreach ($rows as $task) $tasks[] = $task + ['claim_token'=>$token,'priority'=>90];
        }
        if ($claimPeriodic('roster', 1800) && count($tasks) < $maxTasks) {
            $rows = [['kind'=>'roster','url'=>'https://api.chess.com/pub/club/' . rawurlencode($clubSlug) . '/members']];
            $token = $issueToken(null, $rows); foreach ($rows as $task) $tasks[] = $task + ['claim_token'=>$token,'priority'=>80];
        }

        $scanLimit = max(80, min(1600, $maxTasks * 6));
        $members = $repository->acamrCandidateMembers($clubSlug, $cursor, $scanLimit);
        if ($members === [] && $cursor > 0) { $nextCursor = 0; $members = $repository->acamrCandidateMembers($clubSlug, 0, $scanLimit); }
        foreach ($members as $member) {
            if (count($tasks) >= $maxTasks) break;
            $memberId = (int)($member['member_id'] ?? 0);
            $username = trim((string)($member['username'] ?? ''));
            if ($username === '') { $nextCursor = max($nextCursor, $memberId); continue; }
            $encoded = rawurlencode($username); $memberTasks = [];
            if (!empty($member['matches_due'])) {
                $fallbackEntry=$pmafState->entry($username);
                if(is_array($fallbackEntry)&&!empty($fallbackEntry['active'])&&!$pmafState->primaryProbeDue($fallbackEntry)){
                    $pmafSuppressedMatches++;
                }else{
                    $memberTasks[] = ['kind'=>'matches','url'=>"https://api.chess.com/pub/player/{$encoded}/matches"];
                }
            }
            if (!empty($member['stats_due'])) $memberTasks[] = ['kind'=>'stats','url'=>"https://api.chess.com/pub/player/{$encoded}/stats"];
            if ($memberTasks === [] && ((int)($member['incomplete_boards'] ?? 0) > 0 || (int)($member['in_progress_boards'] ?? 0) > 0) && $claimArchiveSlot()) {
                // Only use an archive when no cheaper due matches/stats request exists,
                // and only within the shared global archive budget. Previous-month
                // guessing is intentionally removed; exact historical board repair is
                // server-authoritative queue work.
                $memberTasks[] = ['kind'=>'archive','url'=>"https://api.chess.com/pub/player/{$encoded}/games/" . $now->format('Y/m')];
            }
            if ($memberTasks === []) { $nextCursor = max($nextCursor, $memberId); continue; }
            // Never partially claim a member: otherwise the shared 20-minute member
            // lease could postpone the member's omitted task classes until expiry.
            if (count($tasks) + count($memberTasks) > $maxTasks) break;
            if (!$claimMember($member)) { $nextCursor = max($nextCursor, $memberId); continue; }
            $nextCursor = max($nextCursor, $memberId);
            $token = $issueToken($username, $memberTasks);
            foreach ($memberTasks as $task) {
                $priority = $task['kind'] === 'matches' ? 70 : ($task['kind'] === 'stats' ? 60 : 50);
                $tasks[] = $task + ['claim_token'=>$token,'username'=>$username,'priority'=>$priority];
            }
        }
        $coverage = $repository->synchronizationCoverage($clubSlug);
        $refresh = is_array($coverage['player_refresh'] ?? null) ? $coverage['player_refresh'] : [];
        $claimableByClass=[];foreach($tasks as $task){$kind=(string)($task['kind']??'unknown');$claimableByClass[$kind]=(int)($claimableByClass[$kind]??0)+1;}
        $canonicalDue=(int)($refresh['canonical_due_now']??((int)($refresh['matches_due']??0)+(int)($refresh['stats_due']??0)));
        $canonicalDrain=p2k_control_acdm_snapshot($repository,$clubSlug,$refresh);
        Http::json([
            'ok'=>true,'tasks'=>$tasks,'next_cursor'=>$nextCursor,'coverage'=>$refresh,
            // FFSD: browser acquisition eligibility and canonical server backlog are
            // deliberately separate concepts. A zero-task plan does not imply that
            // the authoritative queue has converged.
            'planner'=>['browser_claimable_now'=>count($tasks),'browser_claimable_by_class'=>$claimableByClass,'canonical_server_checks_due'=>$canonicalDue,'canonical_scheduled_later'=>(int)($refresh['canonical_scheduled_later']??0),'canonical_total_debt'=>(int)($canonicalDrain['total_debt']??0),'canonical_recommended_lane'=>(string)($canonicalDrain['recommended_lane']??'club'),'pmaf_matches_suppressed_by_cooldown'=>$pmafSuppressedMatches],
            'canonical_drain'=>$canonicalDrain,
            'policy'=>['next_batch_delay_ms'=>350,'empty_retry_ms'=>10000,'max_tasks'=>$maxTasks,'claim_ttl_seconds'=>$claimTtl,
                'mode'=>'acsr-canonical-drain','acsr_pack'=>true,'acdm'=>true,'planner'=>'debt-and-urgency-aware','browser_role'=>'acquisition-accelerator+canonical-drain-trigger',
                'api_concurrency_controlled_elsewhere'=>true,'default_client_task_enabled'=>false,'canonical_facts_server_verified'=>true,
                'canonical_queue_coalescing'=>true,'cron_authoritative_fallback'=>true,'canonical_pulse_quota'=>16,'idle_db_backoff'=>true,
                'archive_fallback_only'=>true,'archive_window_seconds'=>$archiveWindowSeconds,'archive_window_cap'=>$archiveWindowCap]
        ]);
    }

    if ($action === 'reconstruction-status') {
        Http::method('GET');
        $service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($_GET['run_id']??''));
        Http::json(['ok'=>true,'reconstruction'=>$service->snapshot($runId!==''?$runId:null)]);
    }

    if ($action === 'reconstruction-start') {
        Http::method('POST');$body=Http::body();$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $snapshot=$service->create(!empty($body['club_points']),!empty($body['player_points']));
        Http::json(['ok'=>true,'message'=>'Fresh Points Reconstruction created. Browser acquisition may start immediately.','reconstruction'=>$snapshot],201);
    }

    if ($action === 'reconstruction-command') {
        Http::method('POST');$body=Http::body();$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($body['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        $snapshot=$service->command($runId,(string)($body['command']??''));
        Http::json(['ok'=>true,'reconstruction'=>$snapshot]);
    }

    if ($action === 'reconstruction-progress') {
        Http::method('POST');$body=Http::body();$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($body['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        Http::json(['ok'=>true,'reconstruction'=>$service->progress($runId,$body)]);
    }

    if ($action === 'reconstruction-ingest') {
        Http::method('POST');$body=Http::body();$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($body['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        $rows=is_array($body['rows']??null)?$body['rows']:[];
        Http::json($service->ingest($runId,(string)($body['kind']??''),$rows));
    }

    if ($action === 'reconstruction-work') {
        Http::method('GET');$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($_GET['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        $rows=$service->work($runId,(string)($_GET['kind']??''),(int)($_GET['limit']??500),(string)($_GET['state']??''));
        Http::json(['ok'=>true,'rows'=>$rows]);
    }

    if ($action === 'reconstruction-review') {
        Http::method('POST');$body=Http::body();$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($body['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        Http::json(['ok'=>true,'review'=>$service->review($runId,true),'reconstruction'=>$service->snapshot($runId)]);
    }

    if ($action === 'reconstruction-recalculate-club') {
        Http::method('POST');$body=Http::body();$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($body['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        Http::json($service->recalculateClub($runId));
    }

    if ($action === 'reconstruction-club-issues') {
        Http::method('GET');$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($_GET['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        Http::json(['ok'=>true,'rows'=>$service->clubIssues($runId,(int)($_GET['limit']??200))]);
    }

    if ($action === 'reconstruction-differences') {
        Http::method('GET');$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($_GET['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        Http::json(['ok'=>true,'reconciliation'=>$service->differences($runId,(string)($_GET['scope']??'club'),(int)($_GET['limit']??100),(int)($_GET['offset']??0),(string)($_GET['sort']??'point_delta'),(string)($_GET['direction']??'desc'),(string)($_GET['effect']??'all'))]);
    }

    if ($action === 'reconstruction-actions') {
        Http::method('GET');$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($_GET['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        Http::json(['ok'=>true,'rows'=>$service->actions($runId,(string)($_GET['scope']??'club'),(int)($_GET['limit']??100))]);
    }

    if ($action === 'reconstruction-apply-difference') {
        Http::method('POST');$body=Http::body();$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($body['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        $result=$service->applyDifference($runId,(string)($body['scope']??''),(string)($body['entity_key']??''),trim((string)($body['applied_by']??''))?:null);
        Http::json($result);
    }

    if ($action === 'reconstruction-finalize') {
        Http::method('POST');$body=Http::body();$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($body['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        Http::json($service->finalizeReconciliation($runId,(string)($body['scope']??''),trim((string)($body['applied_by']??''))?:null));
    }

    if ($action === 'reconstruction-apply') {
        Http::method('POST');$body=Http::body();$service=new FreshPointsReconstruction($pdo,$repository,$clubSlug);
        $runId=trim((string)($body['run_id']??''));if($runId==='')throw new ApiException('Missing reconstruction run ID.',400,'MISSING_RECONSTRUCTION_RUN');
        $result=$service->apply($runId,(string)($body['scope']??'both'),!empty($body['force']));
        Http::json($result);
    }

    if ($action === 'logs') {
        Http::method('GET');
        $task = strtolower(trim((string)($_GET['task'] ?? '')));
        $level = strtolower(trim((string)($_GET['level'] ?? '')));
        $requestedLimit = max(1, min(250, (int)($_GET['limit'] ?? 50)));
        $rows = $registry->logs($requestedLimit + 1, $task, $level, (int)($_GET['before_id'] ?? 0));
        $hasMore = count($rows) > $requestedLimit;
        if ($hasMore) array_pop($rows);
        $nextBeforeId = $rows ? (int)($rows[count($rows)-1]['id'] ?? 0) : 0;
        Http::json(['ok'=>true,'logs'=>$rows,'has_more'=>$hasMore,'next_before_id'=>$nextBeforeId,'runs'=>$registry->runs(100, $task)]);
    }

    if ($action === 'command') {
        Http::method('POST');
        $body = Http::body();
        $key = strtolower(trim((string)($body['task'] ?? '')));
        $command = strtolower(trim((string)($body['command'] ?? 'refresh')));
        if (!isset(TaskRegistry::DEFINITIONS[$key])) throw new ApiException('Unknown scheduled task.', 404, 'TASK_NOT_FOUND');
        if (!in_array($command, ['start','resume','pause','refresh'], true)) throw new ApiException('Unsupported task command.', 400, 'INVALID_TASK_COMMAND');

        if (in_array($key, ['team-points-club','team-points-player','team-points'], true)) {
            $lane = $key === 'team-points-player' ? 'player' : ($key === 'team-points-club' ? 'club' : null);
            $job = $repository->latestJob($clubSlug,$lane);
            if (in_array($command, ['start','resume'], true)) {
                $job = $repository->createOrGetActiveJob($clubSlug,$lane ?? 'combined');
                if (($job['status'] ?? '') === 'paused' || $command === 'resume') {
                    $repository->resumeJob((string)$job['id']);
                }
            } elseif ($command === 'pause' && is_array($job)) {
                $repository->pauseJob((string)$job['id']);
            }
        }
        $task = $registry->command($key, $command);
        $task['work'] = p2k_control_work_details($key, $repository, $clubSlug);
        Http::json(['ok'=>true,'task'=>$task,'message'=>$command === 'refresh' ? 'Task status refreshed.' : ucfirst($command) . ' request accepted.']);
    }

    if ($action === 'team-points-maintenance') {
        Http::method('POST');
        $body = Http::body();
        $mode = strtolower(trim((string)($body['mode'] ?? 'routine')));
        if ($mode === 'routine') {
            $result = $repository->queuePriorityDiscovery($clubSlug);
            Http::json(['ok'=>true,'message'=>'Incremental club-index and roster refresh queued.'] + $result, 201);
        }
        if ($mode === 'full-member-history') {
            $result = $repository->queueFullMemberHistoryRepair($clubSlug);
            Http::json(['ok'=>true,'message'=>'Explicit full current-member match-history repair queued.'] + $result, 201);
        }
        if ($mode === 'raw-match-ids') {
            $lower = max(1,(int)($body['lower'] ?? 0));
            $upper = max(0,(int)($body['upper'] ?? 0));
            if ($upper < $lower) throw new ApiException('Enter a valid lower and upper match ID.',400,'INVALID_RAW_RANGE');
            $result = $repository->queueRawHistoryRepair($clubSlug,$lower,$upper);
            Http::json(['ok'=>true,'message'=>'Explicit raw match-ID repair queued.'] + $result, 201);
        }
        throw new ApiException('Unknown Team Points maintenance mode.',400,'INVALID_MAINTENANCE_MODE');
    }

    if ($action === 'gateway-probe') {
        Http::method('POST');
        try {
            $profile = $gateway->json('https://api.chess.com/pub/club/' . rawurlencode($clubSlug), [
                'consumer'=>'gateway-health',
                'force'=>true,
                'health_probe'=>true,
                'cache_ttl_seconds'=>300,
            ]);
            Http::json(['ok'=>is_array($profile),'gateway'=>$gateway->status(),'message'=>'Shared Chess.com gateway health probe completed.']);
        } catch (GatewayRetryableException $exception) {
            Http::json(['ok'=>false,'gateway'=>$gateway->status(),'error'=>['code'=>'GATEWAY_UNHEALTHY','message'=>$exception->getMessage()]], 503);
        }
    }

    throw new ApiException('Unknown control action.', 404, 'UNKNOWN_ACTION');
} catch (ApiException $exception) {
    Http::json(['ok'=>false,'error'=>['code'=>$exception->errorCode,'message'=>$exception->getMessage(),'details'=>$exception->details]], $exception->httpStatus);
} catch (Throwable $exception) {
    error_log('P2K unified task control API: ' . $exception);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>$exception->getMessage()]], 500);
}
