<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\OAuthSession;
use P2K\TeamPoints\Repository;
use P2K\Shared\SharedChessGateway;
use P2K\Shared\TaskRegistry;

try {
    @set_time_limit(40);
    header('Cache-Control: no-store');
    Http::method('POST');
    Auth::enforceSameOrigin();
    // v2.10.4.2: prefer the short-lived assertion emitted by oauth.php?action=session.
    // It is HMAC-signed server-side and proves the real OAuth username without
    // requiring this second request to reopen P2KOAUTH. This restores reliable
    // reload/iframe propagation while never trusting a raw browser-posted username.
    $body = Http::body();
    $assertion = trim((string)($body['bootstrap_assertion'] ?? ''));
    $username = $assertion !== '' ? OAuthSession::verifyAdminBootstrapAssertion($assertion) : '';
    if ($assertion !== '' && $username === '') {
        throw new ApiException('The OAuth administrator bootstrap assertion is invalid or expired.', 401, 'OAUTH_BOOTSTRAP_INVALID');
    }
    // Compatibility fallback for callers that still have a directly recoverable
    // P2KOAUTH server session. This path is no longer required for normal reloads.
    if ($username === '') $username = OAuthSession::authenticatedUsername(true);
    if ($username === '' || !preg_match('/^[a-z0-9_-]{1,80}$/i', $username)) {
        throw new ApiException('Log in with Chess.com OAuth before opening an administrator session.', 401, 'OAUTH_SESSION_REQUIRED');
    }

    $config = p2k_tp_config();
    $clubSlug = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
    $pdo = Database::connection();
    $repository = new Repository($pdo);
    $beforeSchemaVersion = $repository->schemaVersion();
    if (!$repository->schemaInstalled()) {
        if ($beforeSchemaVersion <= 0) {
            $repository->installSchema(__DIR__ . '/../sql/schema.sql');
        } else {
            $repository->upgradeExistingSchema(__DIR__ . '/../sql/schema.sql');
        }
    }
    if (!$repository->schemaInstalled()) {
        throw new ApiException('The Team Points schema could not be prepared automatically.', 503, 'SCHEMA_INSTALL_REQUIRED');
    }
    // Administrator login is also the automatic database/bootstrap point
    // for the shared gateway and unified scheduled-task registry.
    $gateway = new SharedChessGateway($pdo, $config['app'] ?? []);
    $tasks = new TaskRegistry($pdo);
    $profile = $gateway->json('https://api.chess.com/pub/club/' . rawurlencode($clubSlug), [
        'consumer' => 'admin-authentication',
        'allow_stale_on_error' => true,
        'max_stale_seconds' => max(300, (int)($config['app']['admin_gateway_stale_seconds'] ?? 86400)),
        'cache_ttl_seconds' => 21600,
    ]);
    if (!is_array($profile) || !Auth::clubProfileHasAdmin($profile, $username)) {
        throw new ApiException('The authenticated Chess.com account is not a current club administrator.', 403, 'ADMIN_AUTH_FAILED');
    }

    $csrf = Auth::createAdminSession($username);
    Http::json([
        'ok' => true,
        'username' => $username,
        'csrf' => $csrf,
        'expires_in' => Auth::sessionLifetime(),
        'authentication' => 'secure_same_origin_session',
        'schema_version' => $repository->schemaVersion(),
        'schema_upgraded' => $beforeSchemaVersion < $repository->schemaVersion(),
        'database_connected' => true,
        'gateway' => $gateway->status(),
        'scheduled_tasks' => $tasks->list(),
    ]);
} catch (ApiException $exception) {
    Http::json(['ok' => false, 'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], $exception->httpStatus);
} catch (Throwable $exception) {
    error_log('P2K Team Points session: ' . $exception);
    Http::json(['ok' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'Unable to establish the secured Team Points session.']], 500);
}
