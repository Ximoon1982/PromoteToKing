<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\LiveRanksService;
use P2K\TeamPoints\Repository;
try {
    Http::method('GET');
    $pdo = PublicReadDatabase::core();
    $analytics = PublicReadDatabase::analytics();
    $repo = new Repository($pdo, $analytics);
    if (!$repo->schemaInstalled()) throw new ApiException('The Team Points database is not installed or upgraded.', 503, 'SCHEMA_NOT_INSTALLED');
    $service = new LiveRanksService($analytics, $repo, new ChessApi($repo));
    Http::json(['ok' => true, 'source' => 'database'] + $service->publicPayload());
} catch (ApiException $e) {
    Http::json(['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K Live ranks public: ' . $e);
    Http::json(['ok' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => $e->getMessage()]], 500);
}
