<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\StorageMetricsService;

try {
    Http::method('GET');
    Auth::requireAdmin();
    $core = null; $analytics = null;
    try { $core = Database::core(); } catch (Throwable) {}
    try { $analytics = Database::analytics(); } catch (Throwable) {}
    $service = new StorageMetricsService($core, $analytics);
    Http::json(['ok'=>true,'storage'=>$service->snapshot(true)]);
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K storage metrics: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'STORAGE_METRICS_ERROR','message'=>'Storage metrics are temporarily unavailable.']],500);
}
