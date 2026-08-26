<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/api/_common.php';
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/shared/ScheduledTaskLogger.php';

use P2K\Shared\ScheduledTaskLogger;
use P2K\Shared\TaskRegistry;
use P2K\TeamPoints\Database;
use P2K\Tournaments\TournamentService;

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $service = new TournamentService();
    if ($method === 'GET') {
        $payload = ['ok' => true, 'archive' => $service->archive()];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new RuntimeException('Unable to encode tournament archive.');
        $etag = '"' . hash('sha256', $json) . '"';
        $archivePath = p2k_tournament_archive_path();
        $modified = is_file($archivePath) ? (int)@filemtime($archivePath) : time();
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=120, stale-while-revalidate=600');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', max(1, $modified)) . ' GMT');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        http_response_code(200);
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }
    if ($method !== 'POST') api_error(405, 'METHOD_NOT_ALLOWED', 'GET or POST required.');

    require_admin_write('tournaments-update');
    $body = body_json();
    $mode = strtolower(trim((string)($body['mode'] ?? '')));
    if (!in_array($mode, ['client-import', 'status', 'reinitialize', 'manual-add', 'enqueue-update'], true)) {
        api_error(400, 'INVALID_MODE', 'mode must be client-import, status, reinitialize, manual-add or enqueue-update.');
    }

    $taskId = $mode === 'client-import' ? 'tournament-client-import' : ($mode === 'reinitialize' ? 'tournament-full-reinitialize' : ($mode === 'manual-add' ? 'tournament-manual-add' : ($mode === 'enqueue-update' ? 'tournament-update-queued' : 'tournament-status-refresh')));
    $startedAt = utc_stamp();
    $start = microtime(true);
    $runId = ScheduledTaskLogger::runId($taskId);
    $registry = new TaskRegistry(Database::connection());
    if (!$registry->beginRun('tournaments', 'manual', $runId)) {
        $paused = $registry->lastBeginReason() === 'paused';
        api_error(409, $paused ? 'TASK_PAUSED' : 'TASK_BUSY', $paused
            ? 'Tournament maintenance is paused in the unified task controller.'
            : 'Another tournament maintenance execution is already active.');
    }
    try {
        $result = $mode === 'client-import'
            ? $service->importClientScan($body)
            : ($mode === 'reinitialize' ? $service->reinitializeArchive($body) : ($mode === 'manual-add' ? $service->manualAdd($body) : ($mode === 'enqueue-update' ? $service->enqueueUpdate($body) : $service->refresh('status'))));
        $errorCount = max(count(is_array($result['errors'] ?? null) ? $result['errors'] : []), max(0, (int)($result['errorCount'] ?? 0)));
        $processed = max(0, (int)($result['checked'] ?? 0));
        $changed = max(0, (int)($result['updated'] ?? 0)) + max(0, (int)($result['imported'] ?? 0));
        $status = $errorCount > 0 ? ($processed > 0 || $changed > 0 ? 'partial' : 'failed') : 'success';
        $message = $mode === 'client-import'
            ? 'Browser tournament scan imported.'
            : ($mode === 'reinitialize' ? 'Tournament archive and podiums reinitialized.' : ($mode === 'manual-add' ? 'Tournament added or refreshed manually.' : ($mode === 'enqueue-update' ? 'Tournament discovery and missing-medalist work queued.' : 'Tournament statuses refreshed.')));
        $registry->finishRun('tournaments', $runId, $status, [
            'processed' => $processed,
            'updated' => $changed,
            'failed' => $errorCount,
        ], $message, [
            'mode' => $mode,
            'imported' => max(0, (int)($result['imported'] ?? 0)),
            'discovered' => max(0, (int)($result['discovered'] ?? 0)),
            'requests' => max(0, (int)($result['requests'] ?? 0)),
            'cache_hits' => max(0, (int)($result['cacheHits'] ?? 0)),
            'batch_remaining' => max(0, (int)($result['batchRemaining'] ?? 0)),
        ]);
        ScheduledTaskLogger::append([
            'taskType' => 'tournaments-update',
            'taskId' => $taskId,
            'runId' => $runId,
            'source' => 'manual',
            'status' => $status,
            'startedAt' => $startedAt,
            'endedAt' => utc_stamp(),
            'processedItems' => $processed,
            'updatedItems' => $changed,
            'failedItems' => $errorCount,
            'excludedItems' => max(0, (int)($result['excluded'] ?? 0)),
            'durationMs' => (int)round((microtime(true) - $start) * 1000),
            'message' => $message,
            'details' => [
                'mode' => $mode,
                'imported' => max(0, (int)($result['imported'] ?? 0)),
                'discovered' => max(0, (int)($result['discovered'] ?? 0)),
                'requests' => max(0, (int)($result['requests'] ?? 0)),
                'cacheHits' => max(0, (int)($result['cacheHits'] ?? 0)),
                'batchRemaining' => max(0, (int)($result['batchRemaining'] ?? 0)),
            ],
        ]);
        json_response($status === 'failed' ? 500 : 200, [
            'ok' => $status !== 'failed',
            'complete' => true,
            'status' => $status,
            'message' => $message,
            'progress' => ['percent' => 100, 'label' => 'Completed'],
            'archive' => $result['archive'] ?? $service->archive(),
            'result' => $result,
        ]);
    } catch (Throwable $error) {
        $registry->finishRun('tournaments', $runId, 'failed', ['failed' => 1], $error->getMessage(), [
            'mode' => $mode,
            'exception' => get_class($error),
        ]);
        ScheduledTaskLogger::append([
            'taskType' => 'tournaments-update',
            'taskId' => $taskId,
            'runId' => $runId,
            'source' => 'manual',
            'status' => 'failed',
            'startedAt' => $startedAt,
            'endedAt' => utc_stamp(),
            'processedItems' => 0,
            'failedItems' => 1,
            'durationMs' => (int)round((microtime(true) - $start) * 1000),
            'message' => $error->getMessage(),
            'details' => ['mode' => $mode, 'exception' => get_class($error)],
        ]);
        throw $error;
    }
} catch (\P2K\TeamPoints\ApiException $error) {
    api_error($error->httpStatus, $error->errorCode, $error->getMessage(), $error->details);
} catch (InvalidArgumentException $error) {
    api_error(400, 'INVALID_REQUEST', $error->getMessage());
} catch (Throwable $error) {
    error_log('P2K tournament endpoint: ' . $error);
    api_error(500, 'TOURNAMENT_UPDATE_FAILED', $error->getMessage());
}
