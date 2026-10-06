<?php
declare(strict_types=1);

require_once __DIR__ . '/server/release-control/src/bootstrap.php';

use P2K\ReleaseControl\ReleaseRuntimeTree;
use P2K\ReleaseControl\ReleaseSlotPolicy;
use P2K\ReleaseControl\ReleaseStateStore;

function p2k_public_original_uri(array $server): string
{
    $theRequest = trim((string)($server['THE_REQUEST'] ?? ''));
    if ($theRequest !== '' && preg_match('~^[A-Z]+\\s+(\\S+)\\s+HTTP/[0-9.]+$~iD', $theRequest, $m)) {
        return (string)$m[1];
    }
    foreach (['P2K_PUBLIC_ORIGINAL_PATH', 'REDIRECT_P2K_PUBLIC_ORIGINAL_PATH'] as $key) {
        if (array_key_exists($key, $server)) {
            $path = '/' . ltrim((string)$server[$key], '/');
            $query = trim((string)($server['QUERY_STRING'] ?? ''));
            return $path . ($query !== '' ? '?' . $query : '');
        }
    }
    return (string)($server['REQUEST_URI'] ?? '/');
}

function p2k_public_relative_path(string $pathPart): string
{
    $decoded = rawurldecode($pathPart);
    $relative = ltrim($decoded, '/');
    if ($relative === '') $relative = 'index.html';
    if (preg_match('~^api/[^/]+/?$~D', $relative)) {
        $relative = rtrim($relative, '/') . '/index.php';
    } elseif (str_ends_with($relative, '/')) {
        $relative .= 'index.html';
    }
    return ReleaseSlotPolicy::normalizeRelativePath($relative);
}

function p2k_public_php_allowed(string $relative): bool
{
    if (!str_contains($relative, '/')) {
        return in_array($relative, ['MaxRatingBackfill.php', 'OAuthTest.php'], true);
    }
    if ($relative === 'auth/callback.php' || $relative === 'config/oauth-test.php') return true;
    if (preg_match('~^api/[^/]+/index\\.php$~D', $relative)) return true;
    return (bool)preg_match('~^server/[A-Za-z0-9._-]+/public/[A-Za-z0-9._-]+\\.php$~D', $relative);
}

if (defined('P2K_PUBLIC_ROUTER_HELPER_ONLY') && P2K_PUBLIC_ROUTER_HELPER_ONLY === true) {
    return;
}

$root = __DIR__;
$originalUri = p2k_public_original_uri($_SERVER);
$pathPart = (string)(parse_url($originalUri, PHP_URL_PATH) ?? '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($pathPart === '/PublicRouter.php' || $pathPart === 'PublicRouter.php') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-P2K-Public-Error: direct-router-request');
    exit('P2K public routing error: direct-router-request');
}

$relative = p2k_public_relative_path($pathPart);
if ($relative === '' || !ReleaseSlotPolicy::isReleaseOwnedPath($relative)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-P2K-Public-Error: path-not-release-owned');
    exit('P2K public routing error: path-not-release-owned; path=' . $relative);
}

try {
    $stateStore = new ReleaseStateStore($root);
    $state = $stateStore->read();
    $mode = (string)($state['mode'] ?? 'direct-root');
    $releaseId = '';
    $appRoot = $root;

    if ($mode === 'slots') {
        $releaseId = trim((string)($state['public_release'] ?? ''));
        $expectedManifest = trim((string)($state['public_manifest_sha256'] ?? ''));
        if ($releaseId === '' || $expectedManifest === '') {
            throw new RuntimeException('Public release-slot state is incomplete.');
        }
        $runtime = (new ReleaseRuntimeTree($root))->describeExisting($releaseId, $expectedManifest);
        if (!is_array($runtime) || empty($runtime['valid'])) {
            throw new RuntimeException('The routed public runtime tree is unavailable or invalid.');
        }
        $appRoot = (string)$runtime['app_root'];
    } elseif ($mode === 'direct-root') {
        $versionPath = $root . '/VERSION';
        $releaseId = is_file($versionPath) ? trim((string)file_get_contents($versionPath)) . ' (direct root)' : 'direct-root';
    } else {
        throw new RuntimeException('Release-control serving mode is unsupported.');
    }
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-P2K-Public-Error: routing-state-unavailable');
    exit('P2K public routing unavailable: ' . $e->getMessage());
}

$file = rtrim($appRoot, '/\\') . '/' . $relative;
if (is_dir($file)) {
    $candidate = rtrim($file, '/\\') . '/index.html';
    if (is_file($candidate)) {
        $file = $candidate;
        $relative = rtrim($relative, '/') . '/index.html';
    }
}
if (!is_file($file) || is_link($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-P2K-Public-Error: release-file-missing');
    exit('P2K public routing error: release-file-missing; path=' . $relative);
}

header('X-P2K-Public-Mode: ' . $mode);
header('X-P2K-Public-Release: ' . $releaseId);
header('X-Content-Type-Options: nosniff');

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($extension === 'php') {
    if (!p2k_public_php_allowed($relative)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-P2K-Public-Error: php-path-not-allowed');
        exit('P2K public routing error: php-path-not-allowed; path=' . $relative);
    }

    putenv('P2K_PUBLIC_ROUTED=1');
    putenv('P2K_PUBLIC_RELEASE=' . $releaseId);
    putenv('P2K_PUBLIC_ROOT=' . $root);
    $sharedConfig = $root . '/server/team-points/config/config.local.php';
    if (is_file($sharedConfig)) putenv('P2K_TP_CONFIG=' . $sharedConfig);
    $_SERVER['P2K_PUBLIC_ROUTED'] = '1';
    $_SERVER['P2K_PUBLIC_RELEASE'] = $releaseId;
    $_SERVER['P2K_PUBLIC_ROOT'] = $root;
    $_SERVER['REQUEST_URI'] = $originalUri;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    $_SERVER['SCRIPT_NAME'] = '/' . $relative;
    $_SERVER['PHP_SELF'] = '/' . $relative;
    chdir(dirname($file));
    require $file;
    exit;
}

$types = [
    'html'=>'text/html; charset=utf-8',
    'htm'=>'text/html; charset=utf-8',
    'js'=>'text/javascript; charset=utf-8',
    'mjs'=>'text/javascript; charset=utf-8',
    'css'=>'text/css; charset=utf-8',
    'json'=>'application/json; charset=utf-8',
    'xml'=>'application/xml; charset=utf-8',
    'svg'=>'image/svg+xml',
    'png'=>'image/png',
    'jpg'=>'image/jpeg',
    'jpeg'=>'image/jpeg',
    'gif'=>'image/gif',
    'webp'=>'image/webp',
    'ico'=>'image/x-icon',
    'woff'=>'font/woff',
    'woff2'=>'font/woff2',
    'ttf'=>'font/ttf',
    'txt'=>'text/plain; charset=utf-8',
    'csv'=>'text/csv; charset=utf-8',
    'zip'=>'application/zip',
];
header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
if (in_array($extension, ['html','htm','json'], true)) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
} elseif (in_array($extension, ['js','mjs','css','woff','woff2','ttf'], true)) {
    header('Cache-Control: public, max-age=31536000, immutable');
} elseif (in_array($extension, ['png','jpg','jpeg','webp','gif','svg','ico'], true)) {
    header('Cache-Control: public, max-age=2592000, stale-while-revalidate=604800');
}

if ($method === 'HEAD') exit;
if (!readfile($file)) {
    http_response_code(500);
    exit('Unable to read public release file.');
}
