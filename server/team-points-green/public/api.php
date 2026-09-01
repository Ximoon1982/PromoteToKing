<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\Green\GreenAnalyticsBootstrap;
use P2K\Green\GreenComparison;
use P2K\Green\GreenCompatibility;
use P2K\Green\GreenConfig;
use P2K\Green\GreenLegacyFacts;
use P2K\Green\GreenRepository;
use P2K\TeamPoints\PublicReadDatabase;

try {
    GreenConfig::authorizeAdmin();
    $repo = GreenRepository::open();
    $cmp = new GreenComparison($repo);
    $action = strtolower(trim((string)($_GET['action'] ?? 'status')));

    $operationalValidation = static function (?array $summary = null) use ($repo): array {
        $s = $summary ?? $repo->greenSummary();
        $st = is_array($s['state'] ?? null) ? $s['state'] : [];
        $p = is_array($s['progress'] ?? null) ? $s['progress'] : [];
        $i = is_array($s['integrity'] ?? null) ? $s['integrity'] : [];
        $checks = [
            'mode_quick' => (string)($st['mode'] ?? '') === 'quick',
            'seed_completed' => !empty($st['seed_completed_at']),
            'no_unknown_matches' => (int)($p['matches']['unknown'] ?? 0) === 0,
            'no_pending_boards' => (int)($p['boards']['pending'] ?? 0) === 0,
            'current_profiles_complete' => (int)($p['players']['profiles_pending'] ?? 0) === 0,
            'initial_stats_complete' => (int)($p['players']['initial_stats_pending'] ?? 0) === 0,
            'index_seen' => !empty($st['last_index_fetch']),
            'roster_seen' => !empty($st['last_roster_fetch']),
            'no_non_daily_points' => (int)($i['non_daily_point_rows'] ?? 0) === 0,
            'no_unverified_points' => (int)($i['unverified_point_rows'] ?? 0) === 0,
            'no_ineligible_points' => (int)($i['ineligible_point_rows'] ?? 0) === 0,
            'no_excluded_member_events' => (int)($i['excluded_point_events'] ?? 0) === 0,
            'trusted_legacy_rows' => (int)($i['trusted_legacy_rows'] ?? 0) === GreenLegacyFacts::EXPECTED_ROWS,
            'trusted_legacy_valid' => (int)($i['trusted_legacy_valid'] ?? 0) === GreenLegacyFacts::EXPECTED_VALID_FINISHED,
            'trusted_legacy_void' => (int)($i['trusted_legacy_void'] ?? 0) === GreenLegacyFacts::EXPECTED_VOID,
            'trusted_legacy_points' => (int)($i['trusted_legacy_points'] ?? 0) === GreenLegacyFacts::EXPECTED_POINTS,
            'current_match_facts_within_gffl_slo' => (int)($i['current_maintenance_over_slo'] ?? 0) === 0,
        ];
        return ['healthy' => !in_array(false, $checks, true), 'checks' => $checks];
    };

    $technicalReadiness = static function () use ($repo): array {
        $checks = ['green_core_schema_17'=>false,'green_analytics_schema_9'=>false,'green_state_available'=>false];
        $errors = [];
        try { $checks['green_core_schema_17'] = (int)$repo->core->query('SELECT COALESCE(MAX(version),0) FROM p2k_core_schema_version')->fetchColumn() >= 17; }
        catch (Throwable $e) { $errors['green_core_schema_17'] = $e->getMessage(); }
        try { $checks['green_analytics_schema_9'] = (int)$repo->analytics->query('SELECT COALESCE(MAX(version),0) FROM p2k_analytics_schema_version')->fetchColumn() >= 9; }
        catch (Throwable $e) { $errors['green_analytics_schema_9'] = $e->getMessage(); }
        try { $checks['green_state_available'] = !empty($repo->state()); }
        catch (Throwable $e) { $errors['green_state_available'] = $e->getMessage(); }
        return ['ready'=>!in_array(false,$checks,true),'checks'=>$checks,'errors'=>$errors];
    };

    $compatibilityStatus = static function (bool $runSmoke = false) use ($repo): array {
        $gab = (new GreenAnalyticsBootstrap($repo))->status();
        $smoke = null;
        if ($runSmoke) {
            try { $smoke = (new GreenCompatibility($repo))->smokeTest(); }
            catch (Throwable $e) { $smoke = ['ready'=>false,'error'=>$e->getMessage()]; }
        }
        return [
            'production_gate' => false,
            'gab' => $gab,
            'smoke' => $smoke,
            'note' => 'Compatibility/GAB work is technical debt only and does not gate Green production in 2.11.0.',
        ];
    };

    if ($action === 'runtime-health') {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            GreenConfig::json(['ok'=>false,'error'=>'Use GET for runtime health.'],405);
        }
        $state = $repo->state();
        $invocations = $repo->recentInvocations(1);
        $last = $invocations[0] ?? null;
        PublicReadDatabase::reset();
        $lastAt = is_array($last) ? (string)($last['completed_at'] ?? $last['started_at'] ?? '') : '';
        $lastEpoch = $lastAt !== '' ? strtotime($lastAt.' UTC') : false;
        $age = $lastEpoch === false ? null : max(0,time()-$lastEpoch);
        $status = is_array($last) ? strtolower((string)($last['status'] ?? 'unknown')) : 'unknown';
        $healthy = in_array($status,['running','success'],true) && ($age===null || $age<=180);
        GreenConfig::json([
            'ok'=>true,
            'effective_public_source'=>PublicReadDatabase::source(),
            'production_backend'=>'green',
            'blue_mode'=>'recovery_only',
            'state'=>[
                'cycle_no'=>(int)($state['cycle_no']??0),
                'mode'=>(string)($state['mode']??''),
                'stage'=>(string)($state['stage']??''),
                'last_worker_start'=>$state['last_worker_start']??null,
                'last_worker_finish'=>$state['last_worker_finish']??null,
                'last_worker_status'=>$state['last_worker_status']??null,
                'compat_analytics_rebuilt_at'=>$state['compat_analytics_rebuilt_at']??null,
            ],
            'last_invocation'=>$last,
            'age_seconds'=>$age,
            'healthy'=>$healthy,
        ]);
    }

    if ($action === 'status') {
        $summary = $repo->greenSummary();
        $comparison = null;
        $comparisonError = null;
        try { $comparison = $cmp->summary(); }
        catch (Throwable $e) { $comparisonError = $e->getMessage(); }
        PublicReadDatabase::reset();
        GreenConfig::json([
            'ok'=>true,
            'release'=>'2.11.0',
            'panel_build'=>'2.11.0',
            'production_backend'=>'green',
            'effective_public_source'=>PublicReadDatabase::source(),
            'blue_mode'=>'recovery_only',
            'technical'=>$technicalReadiness(),
            'operational'=>$operationalValidation($summary),
            'compatibility'=>$compatibilityStatus(false),
            'green'=>$summary,
            'blue_reference'=>$comparison,
            'blue_reference_error'=>$comparisonError,
        ]);
    }

    if ($action === 'comparison') {
        GreenConfig::json(['ok'=>true,'reference_only'=>true,'comparison'=>$cmp->summary()]);
    }
    if ($action === 'member-events') {
        $limit=max(1,min(1000,(int)($_GET['limit']??250)));
        $filters=['event_type'=>(string)($_GET['event_type']??''),'member'=>(string)($_GET['member']??''),'from'=>(string)($_GET['from']??''),'to'=>(string)($_GET['to']??'')];
        GreenConfig::json(['ok'=>true,'events'=>$repo->memberEvents($limit,$filters),'filters'=>$filters]);
    }
    if ($action === 'member-lookup') {
        $username=trim((string)($_GET['username']??''));
        if ($username==='') GreenConfig::json(['ok'=>false,'error'=>'A username is required.'],400);
        GreenConfig::json(['ok'=>true,'lookup'=>$repo->memberLookup($username)]);
    }
    if ($action === 'feed-plan') {
        $limit=max(1,min(96,(int)($_GET['limit']??48)));
        $owner=trim((string)($_GET['owner']??''));
        GreenConfig::json(['ok'=>true,'plan'=>$repo->feedPlan($limit,$owner)]);
    }

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        GreenConfig::json(['ok'=>false,'error'=>'Use POST for control actions.'],405);
    }
    $body = GreenConfig::body();

    $retired = [
        'switch-green-reads','make-green-primary','rollback-blue-reads','set-migration-phase',
        'set-public-read-target','set-worker-target','set-client-target','start-seeding',
        'start-heatmap-backfill','report-heatmap-failure',
    ];
    if (in_array($action,$retired,true)) {
        GreenConfig::json([
            'ok'=>false,
            'error'=>'This Blue/Green migration control was retired in Promote to King 2.11.0. Green is the sole production backend; Blue is recovery/reference only.',
            'code'=>'MIGRATION_CONTROL_RETIRED',
        ],410);
    }

    if ($action === 'advance-browser-stage') {
        GreenConfig::json(['ok'=>true]+$repo->advanceBrowserStageIfDrained());
    }
    if ($action === 'take-browser-claim-lane') {
        $owner=trim((string)($body['owner']??''));
        $released=$repo->takeBrowserClaimLane($owner);
        GreenConfig::json(['ok'=>true,'owner'=>$owner,'released_browser_claims'=>$released,'planner'=>$repo->seedMatchPlannerState($owner)]);
    }
    if ($action === 'release-browser-claims') {
        $owner=trim((string)($body['owner']??''));
        $released=$repo->releaseBrowserClaims($owner);
        GreenConfig::json(['ok'=>true,'owner'=>$owner,'released_browser_claims'=>$released]);
    }
    if ($action === 'set-force-mode') {
        $mode=strtolower(trim((string)($body['mode']??'auto')));
        if ($mode==='deep' && isset($body['from'],$body['to'])) $repo->configureDeep((int)$body['from'],(int)$body['to'],'manual_control');
        $state=$repo->setControl(['force_mode'=>$mode]);
        GreenConfig::json(['ok'=>true,'state'=>$state]);
    }
    if ($action === 'start-gab') {
        $restart=!empty($body['restart']);
        $gab=(new GreenAnalyticsBootstrap($repo))->start($restart);
        // Green remains the only runtime target. Compatibility work never re-enables Blue.
        $state=$repo->setControl(['worker_target'=>'green','client_ingest_target'=>'green']);
        GreenConfig::json(['ok'=>true,'production_gate'=>false,'gab'=>$gab,'state'=>$state]);
    }
    if ($action === 'run-gab-now') {
        $gab=(new GreenAnalyticsBootstrap($repo))->runSlice(max(1,min(20,(float)($body['seconds']??8))));
        GreenConfig::json(['ok'=>true,'production_gate'=>false,'result'=>$gab,'green'=>$repo->greenSummary()]);
    }
    if ($action === 'report-gab-failure') {
        $url=trim((string)($body['url']??''));
        $status=(int)($body['status']??0);
        $error=trim((string)($body['error']??'fetch failed'));
        if ($url==='') throw new RuntimeException('GAB failure report requires a URL.');
        (new GreenAnalyticsBootstrap($repo))->markExternalFailure($url,$status,$error);
        GreenConfig::json(['ok'=>true,'production_gate'=>false,'gab'=>(new GreenAnalyticsBootstrap($repo))->status()]);
    }
    if ($action === 'set-gffl') {
        $changes=[];
        if (array_key_exists('enabled',$body)) $changes['gffl_enabled']=!empty($body['enabled'])?'1':'0';
        $state=$changes?$repo->setControl($changes):$repo->state();
        if (isset($body['target_seconds'])) $state=$repo->setGfflTargetSeconds((int)$body['target_seconds']);
        GreenConfig::json(['ok'=>true,'state'=>$state,'gffl'=>$repo->gfflSnapshot()]);
    }
    if ($action === 'run-green-now') {
        require_once dirname(__DIR__).'/src/GreenWorker.php';
        $worker=new \P2K\Green\GreenWorker($repo);
        GreenConfig::json($worker->run());
    }
    if ($action === 'validate-green') {
        GreenConfig::json([
            'ok'=>true,
            'production_gate'=>false,
            'production_backend'=>'green',
            'technical'=>$technicalReadiness(),
            'operational'=>$operationalValidation(),
            'compatibility'=>$compatibilityStatus(true),
        ]);
    }

    GreenConfig::json(['ok'=>false,'error'=>'Unknown Green administration action.'],404);
} catch (Throwable $e) {
    GreenConfig::json(['ok'=>false,'error'=>$e->getMessage()],500);
}
