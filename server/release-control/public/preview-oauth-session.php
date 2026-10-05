<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use P2K\ReleaseControl\ReleaseControlAuth;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-P2K-Preview-OAuth-Session: recovery-plane');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    if ($method !== 'HEAD') {
        echo json_encode([
            'ok'=>false,
            'error'=>[
                'code'=>'METHOD_NOT_ALLOWED',
                'message'=>'Preview OAuth session status is read-only.',
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

try {
    $root = dirname(__DIR__, 3);
    $payload = (new ReleaseControlAuth($root))->oauthSessionStatus(false);
    if ($method !== 'HEAD') {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
} catch (Throwable $exception) {
    error_log('P2K preview OAuth status: ' . $exception);
    http_response_code(503);
    if ($method !== 'HEAD') {
        echo json_encode([
            'ok'=>false,
            'error'=>[
                'code'=>'PREVIEW_OAUTH_STATUS_UNAVAILABLE',
                'message'=>'Unable to read the current OAuth session.',
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
}
