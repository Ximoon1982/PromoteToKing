<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\StorageMetricsService;
use P2K\TeamPoints\Worker;
use P2K\Shared\SharedChessGateway;
use P2K\Shared\TaskRegistry;

try {
    $config = p2k_tp_config();
    $origin = trim((string)($config['app']['allowed_origin'] ?? ''));
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . rtrim($origin, '/'));
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Headers: Content-Type, X-P2K-Admin-Token, X-P2K-CSRF');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    Auth::requireAdmin();
    $pdo = Database::core();
    $analytics = Database::analytics();
    $repository = new Repository($pdo, $analytics);
    if (!$repository->schemaInstalled()) {
        $repository->upgradeExistingSchema(__DIR__ . '/../sql/schema.sql');
    }
    if (!$repository->schemaInstalled()) {
        throw new ApiException('The Team Points database schema is not installed yet.', 503, 'SCHEMA_INSTALL_REQUIRED');
    }
    $clubSlug = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
    $taskRegistry = new TaskRegistry($pdo);
    $sharedGateway = new SharedChessGateway(null, $config['app'] ?? []);
    $action = strtolower(trim((string)($_GET['action'] ?? 'status')));

    $queryOptions = static function (bool $withLimit = true): array {
        $limit = $withLimit ? max(1, min(1000, (int)($_GET['limit'] ?? 500))) : null;
        return [
            trim((string)($_GET['member'] ?? '')),
            trim((string)($_GET['sort_by'] ?? '')),
            strtolower(trim((string)($_GET['sort_dir'] ?? ''))),
            $limit,
        ];
    };

    switch ($action) {
        case 'status':
            Http::method('GET');
            $summary = $repository->summary($clubSlug);
            if (is_array($summary['job'] ?? null)) {
                $summary['job']['issues'] = $repository->recentJobItems((string)$summary['job']['id']);
            }
            Http::json([
                'ok' => true,
                'club_slug' => $clubSlug,
                'server_utc' => p2k_tp_utc_now()->format(DATE_ATOM),
                'schema_version' => $repository->schemaVersion(),
                'manual_updates_supported' => true,
                'manual_update_mode' => 'server_controlled_external_cron_with_optional_immediate_segment',
                'server_controlled' => true,
                'scheduled_task' => $taskRegistry->task('team-points-club'),
                'scheduled_tasks' => ['club'=>$taskRegistry->task('team-points-club'),'player'=>$taskRegistry->task('team-points-player')],
                'gateway' => $sharedGateway->status(),
                'algorithm' => 'hybrid_compact_incremental_v2',
                'architecture' => 'core_analytics_filesystem',
                'seed' => $repository->latestSeedRun($clubSlug),
            ] + $summary);

        case 'start':
            Http::method('POST');
            $clubJob = $repository->createOrGetActiveJob($clubSlug,'club');
            $playerJob = $repository->createOrGetActiveJob($clubSlug,'player');
            foreach ([$clubJob,$playerJob] as $job) if (($job['status'] ?? '') === 'paused') $repository->resumeJob((string)$job['id']);
            $taskRegistry->command('team-points-club', 'start', 'Club Points fast lane queued.');
            $taskRegistry->command('team-points-player', 'start', 'Member Points reconciliation lane queued.');
            Http::json([
                'ok' => true,
                'jobs' => ['club'=>$repository->job((string)$clubJob['id']),'player'=>$repository->job((string)$playerJob['id'])],
                'message' => 'Club and Member Points lanes are queued independently.',
            ], 201);

        case 'stop':
            Http::method('POST');
            $body = Http::body();
            $jobId = trim((string)($body['job_id'] ?? ''));
            if ($jobId === '') {
                throw new ApiException('job_id is required.', 400, 'JOB_ID_REQUIRED');
            }
            $job=$repository->job($jobId); $lane=$repository->laneForJob($job);
            $repository->pauseJob($jobId);
            $taskRegistry->command($lane==='player'?'team-points-player':'team-points-club', 'pause', ucfirst($lane).' Points safe pause requested.');
            Http::json(['ok' => true, 'job' => $repository->job($jobId), 'message' => 'Safe pause requested. The active item will finish and the next server invocation will mark the job paused.']);

        case 'resume':
            Http::method('POST');
            $body = Http::body();
            $jobId = trim((string)($body['job_id'] ?? ''));
            if ($jobId === '') {
                throw new ApiException('job_id is required.', 400, 'JOB_ID_REQUIRED');
            }
            $job=$repository->job($jobId); $lane=$repository->laneForJob($job);
            $repository->resumeJob($jobId);
            $taskRegistry->command($lane==='player'?'team-points-player':'team-points-club', 'resume', ucfirst($lane).' Points resumed for server-side CRON processing.');
            Http::json(['ok' => true, 'job' => $repository->job($jobId), 'message' => 'Job resumed.']);

        case 'prioritize_discovery':
            Http::method('POST');
            $result = $repository->queuePriorityDiscovery($clubSlug);
            Http::json(['ok' => true, 'message' => 'Fresh club-index and member-roster discovery was placed at the front of the queue; routine mode does not scan every member history or raw global match IDs.'] + $result, 201);

        case 'repair_member_history':
            Http::method('POST');
            Http::json(['ok'=>true,'message'=>'Explicit full current-member match-history repair queued.'] + $repository->queueFullMemberHistoryRepair($clubSlug), 201);

        case 'repair_raw_match_ids':
            Http::method('POST');
            $body = Http::body();
            $lower = max(1,(int)($body['lower'] ?? 0));
            $upper = max(0,(int)($body['upper'] ?? 0));
            if ($upper < $lower) throw new ApiException('Enter a valid lower and upper match ID.',400,'INVALID_RAW_RANGE');
            Http::json(['ok'=>true,'message'=>'Explicit raw match-ID repair queued.'] + $repository->queueRawHistoryRepair($clubSlug,$lower,$upper), 201);

        case 'run':
            Http::method('POST');
            $body = Http::body();
            $jobId = trim((string)($body['job_id'] ?? '')) ?: null;
            $lane = strtolower(trim((string)($body['lane'] ?? $_GET['lane'] ?? 'club'))); if(!in_array($lane,['club','player'],true))$lane='club';
            $taskKey = $lane==='player'?'team-points-player':'team-points-club';
            if($jobId===null)$jobId=(string)$repository->createOrGetActiveJob($clubSlug,$lane)['id'];
            $runId = 'team-points-'.$lane.'-manual-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
            if (!$taskRegistry->beginRun($taskKey, 'manual', $runId)) {
                $reason = $taskRegistry->lastBeginReason();
                Http::json([
                    'ok' => false,
                    'status' => $reason === 'paused' ? 'paused' : 'scheduler_busy',
                    'message' => $reason === 'paused'
                        ? ucfirst($lane) . ' Points is paused in the unified task controller.'
                        : 'Another ' . ucfirst($lane) . ' Points execution is already active.',
                    'scheduled_task' => $taskRegistry->task($taskKey),
                'scheduled_tasks' => ['club'=>$taskRegistry->task('team-points-club'),'player'=>$taskRegistry->task('team-points-player')],
                ], 409);
            }
            try {
                $worker = new Worker($pdo, $repository, new ChessApi($repository),$lane);
                $result = $worker->run($jobId, 'manual');
                $processed = max(0, (int)($result['processed_items'] ?? $result['processed'] ?? 0));
                $failed = max(0, (int)($result['failed_items'] ?? $result['failed'] ?? 0));
                $workerStatus = strtolower((string)($result['status'] ?? 'partial'));
                $status = match ($workerStatus) {
                    'completed', 'success' => 'success',
                    'failed' => 'failed',
                    default => $failed > 0 ? ($processed > 0 ? 'partial' : 'failed') : 'partial',
                };
                $message = (string)($result['message'] ?? 'One compatibility worker segment completed; the external CRON remains the normal server-side controller.');
                $taskRegistry->finishRun($taskKey, $runId, $status, [
                    'processed' => $processed,
                    'updated' => max(0, (int)($result['updated_items'] ?? $result['updated'] ?? $processed)),
                    'failed' => $failed,
                ], $message, ['mode' => 'manual_compatibility_segment', 'result' => $result]);
                Http::json($result + ['scheduled_task' => $taskRegistry->task($taskKey)]);
            } catch (Throwable $exception) {
                $taskRegistry->finishRun($taskKey, $runId, 'failed', ['failed' => 1], $exception->getMessage(), [
                    'mode' => 'manual_compatibility_segment',
                    'exception' => get_class($exception),
                ]);
                throw $exception;
            }

        case 'storage':
            Http::method('GET');
            $service = new StorageMetricsService($pdo, $analytics, $config);
            Http::json(['ok'=>true,'storage'=>$service->snapshot(true)]);

        case 'results':
            Http::method('GET');
            $startMonth = trim((string)($_GET['start_month'] ?? ''));
            $endMonth = trim((string)($_GET['end_month'] ?? ''));
            $currentOnly = !isset($_GET['current_only']) || (string)$_GET['current_only'] !== '0';
            $shape = (string)($_GET['shape'] ?? 'totals');
            if (!in_array($shape, ['totals', 'monthly', 'events'], true)) {
                throw new ApiException('shape must be totals, monthly or events.', 400, 'INVALID_RESULT_SHAPE');
            }
            [$member, $sortBy, $sortDir, $limit] = $queryOptions(true);
            $result = $shape === 'events'
                ? $repository->eventRows($clubSlug, $startMonth, $endMonth, $currentOnly, $member, $sortBy, $sortDir, $limit)
                : $repository->monthlyResults($clubSlug, $startMonth, $endMonth, $currentOnly, $shape, $member, $sortBy, $sortDir, $limit);
            Http::json(['ok' => true] + $result);

        case 'export':
            Http::method('GET');
            $startMonth = trim((string)($_GET['start_month'] ?? ''));
            $endMonth = trim((string)($_GET['end_month'] ?? ''));
            $currentOnly = !isset($_GET['current_only']) || (string)$_GET['current_only'] !== '0';
            $shape = (string)($_GET['shape'] ?? 'totals');
            if (!in_array($shape, ['totals', 'monthly', 'events'], true)) {
                throw new ApiException('shape must be totals, monthly or events.', 400, 'INVALID_EXPORT_SHAPE');
            }
            [$member, $sortBy, $sortDir] = $queryOptions(false);
            $result = $shape === 'events'
                ? $repository->eventRows($clubSlug, $startMonth, $endMonth, $currentOnly, $member, $sortBy, $sortDir, null)
                : $repository->monthlyResults($clubSlug, $startMonth, $endMonth, $currentOnly, $shape, $member, $sortBy, $sortDir, null);
            $rows = $result['rows'];
            $headers = match ($shape) {
                'events' => ['username','match_id','board_url','game_url','game_end_utc','month','result_code','points','current_member'],
                'monthly' => ['month','username','points','matches','games','wins','draws','losses'],
                default => ['username','points','matches','games','wins','draws','losses'],
            };
            $suffix = $member === '' ? '' : '-member-' . preg_replace('/[^a-z0-9_-]+/i', '-', $member);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="p2k-team-points-' . $shape . '-' . $startMonth . '-to-' . $endMonth . $suffix . '.csv"');
            header('Cache-Control: no-store');
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers);
            foreach ($rows as $row) {
                fputcsv($stream, array_map(static fn(string $key) => $row[$key] ?? '', $headers));
            }
            fclose($stream);
            exit;

        default:
            throw new ApiException('Unknown action.', 404, 'UNKNOWN_ACTION');
    }
} catch (ApiException $exception) {
    Http::json([
        'ok' => false,
        'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage(), 'details' => $exception->details],
    ], $exception->httpStatus);
} catch (Throwable $exception) {
    error_log('P2K Team Points API: ' . $exception);
    Http::json([
        'ok' => false,
        'error' => ['code' => 'SERVER_ERROR', 'message' => $exception->getMessage()],
    ], 500);
}
