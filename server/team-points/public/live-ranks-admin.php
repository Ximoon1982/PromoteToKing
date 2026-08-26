<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\LiveRanksService;
use P2K\TeamPoints\McaBlueGreenSync;
use P2K\TeamPoints\OAuthSession;
use P2K\TeamPoints\Repository;
try {
    Auth::requireAdmin();
    $pdo = PublicReadDatabase::core();
    $analytics = PublicReadDatabase::analytics();
    $repo = new Repository($pdo, $analytics);
    if (!$repo->schemaInstalled()) $repo->upgradeExistingSchema(__DIR__ . '/../sql/schema.sql');
    if (!$repo->schemaInstalled()) throw new ApiException('The Team Points database schema could not be upgraded.', 503, 'SCHEMA_NOT_INSTALLED');
    $service = new LiveRanksService($analytics, $repo, new ChessApi($repo));
    $action = strtolower(trim((string)($_GET['action'] ?? 'status')));
    if ($action === 'status') {
        Http::method('GET');
        Http::json(['ok' => true] + $service->statusPayload());
    }
    if ($action === 'upload') {
        Http::method('POST');
        if (!isset($_FILES['files'])) throw new ApiException('No CSV files were received.', 400, 'FILES_REQUIRED');
        Http::json(['ok' => true] + $service->uploadFiles($_FILES['files']), 201);
    }
    if ($action === 'sync_start') {
        Http::method('POST');
        $body=Http::body();
        Http::json(['ok'=>true,'sync'=>$service->startAutoSync(!empty($body['force']))] + $service->statusPayload());
    }
    if ($action === 'sync_step') {
        Http::method('POST');
        Http::json(['ok'=>true,'sync'=>$service->autoSyncStep()] + $service->statusPayload());
    }
    if ($action === 'sync_ack_rebuild') {
        Http::method('POST');
        Http::json(['ok'=>true,'sync'=>$service->acknowledgeAutoSyncRebuild()] + $service->statusPayload());
    }
    if ($action === 'sync_retry_errors') {
        Http::method('POST');
        Http::json(['ok'=>true,'sync'=>$service->retryAutoSyncErrors()] + $service->statusPayload());
    }
    if ($action === 'sync_blue_to_green') {
        Http::method('POST');
        Http::json(['ok'=>true,'blue_to_green'=>McaBlueGreenSync::run()] + $service->statusPayload());
    }
    if ($action === 'process_start') {
        Http::method('POST');
        Http::json(['ok' => true] + $service->startProcessing());
    }
    if ($action === 'event_date') {
        Http::method('POST');
        $body=Http::body();
        $row=$service->setEventDate((int)($body['file_id']??0),isset($body['event_date'])?(string)$body['event_date']:null);
        Http::json(['ok'=>true,'file'=>$row,'files'=>$service->fileRows()]);
    }
    if ($action === 'process_step') {
        Http::method('POST');
        $body = Http::body();
        $transport = null;
        $requestedConcurrency = max(1, min(256, (int)($body['oauth_concurrency'] ?? 8)));
        $requestedRate = max(0.5, min(120.0, (float)($body['oauth_rate_cps'] ?? 8)));
        // MCA profile verification must fit comfortably inside the hosting request
        // window even when the adaptive OAuth controller has backed off. Bound the
        // batch by both an absolute cap and a launch-time budget derived from the
        // caller's learned CPS. The client applies the same budget, but the server
        // remains authoritative if an older/stale page submits a larger limit.
        $requestedLimit = max(1, min(256, (int)($body['limit'] ?? 8)));
        $pacedLimit = max(1, (int)floor($requestedRate * 12.0));
        $boundedLimit = min($requestedLimit, 32, $pacedLimit);
        $fetcher = static function(array $rows) use (&$transport, $requestedConcurrency, $requestedRate): ?array {
            if ($rows === []) return [];
            $requests = [];
            foreach ($rows as $index => $row) {
                $key = strtolower(trim((string)($row['username_key'] ?? '')));
                if ($key === '') continue;
                $requests[] = ['id' => $key, 'url' => 'https://api.chess.com/pub/player/' . rawurlencode($key), 'headers' => []];
            }
            $batch = OAuthSession::batchForAuthorizedRequest($requests, $requestedConcurrency, $requestedRate);
            if ($batch === null) return null;
            $transport = $batch; unset($transport['results']);
            $out = [];
            foreach ((array)($batch['results'] ?? []) as $result) {
                $key = strtolower(trim((string)($result['id'] ?? ''))); if ($key === '') continue;
                $status = (int)($result['status'] ?? 0);
                $out[$key] = [
                    'status' => $status,
                    'profile' => is_array($result['json'] ?? null) ? $result['json'] : null,
                    'error' => $status === 429 ? 'Chess.com rate limit; retry scheduled.' : (($status === 0 || $status >= 500) ? 'Temporary Chess.com transport/server failure; retry scheduled.' : ''),
                ];
            }
            return $out;
        };
        $payload = ['ok' => true] + $service->processProfileBatch($boundedLimit, $fetcher);
        $payload['mca_batch_limit'] = $boundedLimit;
        if (is_array($transport)) $payload['oauth_transport'] = $transport;
        Http::json($payload);
    }
    if ($action === 'export_corrections') {
        Http::method('GET');
        $service->exportCorrectionsCsv();
        exit;
    }
    throw new ApiException('Unknown Live ranks action.', 404, 'UNKNOWN_ACTION');
} catch (ApiException $e) {
    Http::json(['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K Live ranks admin: ' . $e);
    Http::json(['ok' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => $e->getMessage()]], 500);
}
