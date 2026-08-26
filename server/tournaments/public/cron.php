<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/api/_common.php';
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/shared/ScheduledTaskLogger.php';

use P2K\Shared\ScheduledTaskLogger;
use P2K\Shared\TaskRegistry;
use P2K\TeamPoints\Database;
use P2K\Tournaments\TournamentService;

$startedAt = utc_stamp();
$start = microtime(true);
$runId = ScheduledTaskLogger::runId('tournaments');
$authenticated = false;
$registry = null;
try {
    $config = read_json_file(root_dir().'/data/server-config.json', []);
    $expected = trim((string)($config['cronToken'] ?? ''));
    $provided = trim((string)($_SERVER['HTTP_X_P2K_CRON_TOKEN'] ?? ($_GET['token'] ?? '')));
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        $authCode = $expected === '' ? 'CRON_TOKEN_NOT_CONFIGURED' : 'CRON_AUTH_FAILED';
        $authMessage = $expected === '' ? 'Tournament CRON token is not configured.' : 'Invalid CRON token.';
        ScheduledTaskLogger::append([
            'taskType'=>'tournaments-update','taskId'=>'tournament-status-refresh','runId'=>$runId,'source'=>'cron','status'=>'failed',
            'startedAt'=>$startedAt,'endedAt'=>utc_stamp(),'processedItems'=>0,'failedItems'=>1,
            'durationMs'=>(int)round((microtime(true)-$start)*1000),'message'=>$authMessage,
            'details'=>['authentication'=>$authCode],
        ]);
        api_error($expected === '' ? 503 : 403, $authCode, $authMessage);
    }
    $authenticated = true;
    $registry = new TaskRegistry(Database::connection());
    if ($registry->isPaused('tournaments')) {
        $registry->log('tournaments', 'warning', 'cron', 'Tournament CRON invocation skipped because the task is paused.', [], $runId);
        json_response(200, ['ok'=>true,'status'=>'paused','message'=>'Tournament maintenance is paused. Resume it from the unified task control page.']);
    }
    if (!$registry->beginRun('tournaments', 'cron', $runId)) {
        $reason = $registry->lastBeginReason();
        json_response(200, [
            'ok'=>true,
            'status'=>$reason === 'paused' ? 'paused' : 'scheduler_busy',
            'message'=>$reason === 'paused' ? 'Tournament maintenance is paused.' : 'Another tournament maintenance execution is already active.',
        ]);
    }

    // Tournament refreshes remain batch-resumable and use the shared gateway.
    @set_time_limit(55);
    // IONOS web cron calls are terminated at 60 seconds. Stop at 45 seconds
    // so the current stage can persist its cursor and release the shared lock.
    $result = (new TournamentService())->refresh('cron', 45);
    $status = !empty($result['deadlineCheckpoint']) ? 'partial' : ($result['errors'] ? ($result['checked'] ? 'partial' : 'failed') : 'success');
    $registry->finishRun('tournaments', $runId, $status, [
        'processed'=>(int)$result['checked'],
        'updated'=>(int)$result['updated'],
        'failed'=>count($result['errors']),
    ], 'Tournament discovery/status refresh completed.', [
        'discovered'=>(int)($result['discovered']??0),
        'requests'=>(int)($result['requests']??0),
        'cacheHits'=>(int)($result['cacheHits']??0),
        'batchRemaining'=>(int)($result['batchRemaining']??0),
        'sharedGateway'=>true,
        'expectedIntervalSeconds'=>600,
    ]);
    ScheduledTaskLogger::append([
        'taskType'=>'tournaments-update','taskId'=>'tournament-status-refresh','runId'=>$runId,'source'=>'cron','status'=>$status,
        'startedAt'=>$startedAt,'endedAt'=>utc_stamp(),'processedItems'=>$result['checked'],'updatedItems'=>$result['updated'],
        'failedItems'=>count($result['errors']),'excludedItems'=>$result['excluded'],'durationMs'=>(int)round((microtime(true)-$start)*1000),
        'message'=>'Tournament discovery/status CRON completed.','details'=>['discovered'=>(int)($result['discovered']??0),'requests'=>$result['requests'],'cacheHits'=>$result['cacheHits'],'batchRemaining'=>(int)($result['batchRemaining']??0),'sharedGateway'=>true],
    ]);
    json_response($status==='failed'?500:200, ['ok'=>$status!=='failed','status'=>$status,'message'=>'Tournament discovery/status refresh completed.','result'=>$result,'shared_gateway'=>true]);
} catch (Throwable $error) {
    if ($registry instanceof TaskRegistry) {
        try { $registry->finishRun('tournaments', $runId, 'failed', ['failed'=>1], $error->getMessage(), ['exception'=>get_class($error)]); } catch (Throwable) {}
    }
    if ($authenticated) {
        try { ScheduledTaskLogger::append([
            'taskType'=>'tournaments-update','taskId'=>'tournament-status-refresh','runId'=>$runId,'source'=>'cron','status'=>'failed',
            'startedAt'=>$startedAt,'endedAt'=>utc_stamp(),'processedItems'=>0,'failedItems'=>1,'durationMs'=>(int)round((microtime(true)-$start)*1000),
            'message'=>$error->getMessage(),'details'=>['exception'=>get_class($error)],
        ]); } catch (Throwable $logError) { error_log('P2K tournament CRON log: '.$logError->getMessage()); }
    }
    error_log('P2K tournament CRON: '.$error);
    api_error(500, 'TOURNAMENT_CRON_FAILED', $error->getMessage());
}
