<?php
declare(strict_types=1);

require_once __DIR__ . '/server/release-control/src/bootstrap.php';

use P2K\ReleaseControl\ReleaseControlAuth;
use P2K\ReleaseControl\ReleasePreviewSession;
use P2K\ReleaseControl\ReleaseSlotPolicy;

/**
 * Return the browser's original request target even if Apache/FastCGI updates
 * REQUEST_URI after internally rewriting the request to PreviewRouter.php.
 *
 * Apache THE_REQUEST is the original HTTP request line and is not rewritten by
 * mod_rewrite. The rewrite environment value is a secondary transport. A true
 * direct request for PreviewRouter.php therefore remains distinguishable.
 */
function p2k_preview_transport(array $server): array
{
    $rawQuery = (string)($server['QUERY_STRING'] ?? '');
    $transportPath = null;
    $publicParts = [];
    foreach (explode('&', $rawQuery) as $part) {
        if (str_starts_with($part, '__p2k_preview_path=')) {
            if ($transportPath === null) {
                $transportPath = substr($part, strlen('__p2k_preview_path='));
            }
            continue;
        }
        if ($part !== '') $publicParts[] = $part;
    }

    return [
        'path'=>$transportPath,
        'query'=>implode('&', $publicParts),
    ];
}

function p2k_preview_original_uri(array $server): string
{
    $transport = p2k_preview_transport($server);
    if ($transport['path'] !== null) {
        $path = '/' . ltrim((string)$transport['path'], '/');
        return $path . ($transport['query'] !== '' ? '?' . $transport['query'] : '');
    }

    $theRequest = trim((string)($server['THE_REQUEST'] ?? ''));
    if ($theRequest !== '' && preg_match('~^[A-Z]+\\s+(\\S+)\\s+HTTP/[0-9.]+$~iD', $theRequest, $m)) {
        return (string)$m[1];
    }

    foreach (['P2K_PREVIEW_ORIGINAL_PATH', 'REDIRECT_P2K_PREVIEW_ORIGINAL_PATH'] as $key) {
        if (array_key_exists($key, $server)) {
            $path = '/' . ltrim((string)$server[$key], '/');
            $query = trim((string)($server['QUERY_STRING'] ?? ''));
            return $path . ($query !== '' ? '?' . $query : '');
        }
    }

    return (string)($server['REQUEST_URI'] ?? '/');
}

if (defined('P2K_PREVIEW_ROUTER_HELPER_ONLY') && P2K_PREVIEW_ROUTER_HELPER_ONLY === true) {
    return;
}

$root = __DIR__;
$auth = new ReleaseControlAuth($root);
$username = $auth->currentUsername();
$preview = new ReleasePreviewSession($root);
$status = $username !== '' && $auth->isSuperAdmin($username)
    ? $preview->status($username)
    : ['enabled'=>false,'reason'=>'unauthorized'];

$transport = p2k_preview_transport($_SERVER);
$originalUri = p2k_preview_original_uri($_SERVER);
$pathPart = (string)(parse_url($originalUri, PHP_URL_PATH) ?? '/');
if ($transport['path'] !== null) {
    $_SERVER['QUERY_STRING'] = (string)$transport['query'];
    unset($_GET['__p2k_preview_path']);
}
if ($pathPart === '/PreviewRouter.php' || $pathPart === 'PreviewRouter.php') {
    http_response_code(404);
    exit('Not found');
}

if (empty($status['enabled'])) {
    $preview->clearInvalidCookie();
    if (in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET','HEAD'], true)) {
        header('Cache-Control: no-store');
        header('Location: ' . ($originalUri !== '' ? $originalUri : '/'), true, 302);
        exit;
    }
    http_response_code(409);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Candidate preview session is no longer valid. Reload the page to return to the public release.');
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET','HEAD'], true)) {
    http_response_code(409);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok'=>false,
        'error'=>[
            'code'=>'CANDIDATE_PREVIEW_WRITE_BLOCKED',
            'message'=>'Candidate preview writes are disabled until the side-effect-isolation increment.',
        ],
        'candidate_release'=>$status['release_id'] ?? null,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$decoded = rawurldecode($pathPart);
$relative = ltrim($decoded, '/');
if ($relative === '') $relative = 'index.html';
if (str_ends_with($relative, '/')) $relative .= 'index.html';
$relative = ReleaseSlotPolicy::normalizeRelativePath($relative);
if ($relative === '' || !ReleaseSlotPolicy::isReleaseOwnedPath($relative)) {
    http_response_code(404);
    exit('Not found');
}

$appRoot = (string)($status['preview_tree']['app_root'] ?? '');
if ($appRoot === '' || !is_dir($appRoot)) {
    http_response_code(503);
    exit('Candidate preview tree is unavailable.');
}

$file = $appRoot . '/' . $relative;
if (is_dir($file)) {
    $file = rtrim($file, '/\\') . '/index.html';
    $relative = rtrim($relative, '/') . '/index.html';
}
if (!is_file($file) || is_link($file)) {
    http_response_code(404);
    exit('Not found');
}

$releaseId = (string)($status['release_id'] ?? '');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-P2K-Candidate-Preview: ' . $releaseId);
header('X-Content-Type-Options: nosniff');

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($extension === 'php') {
    $allowed = false;
    if (!str_contains($relative, '/')) {
        $allowed = in_array($relative, ['MaxRatingBackfill.php','OAuthTest.php'], true);
    } elseif (preg_match('~^api/[^/]+/index\.php$~D', $relative)) {
        $allowed = true;
    } elseif (preg_match('~^server/[A-Za-z0-9._-]+/public/[A-Za-z0-9._-]+\.php$~D', $relative)) {
        $allowed = true;
    }
    if (!$allowed) {
        http_response_code(404);
        exit('Not found');
    }

    putenv('P2K_PREVIEW_ACTIVE=1');
    putenv('P2K_PREVIEW_RELEASE=' . $releaseId);
    $sharedConfig = $root . '/server/team-points/config/config.local.php';
    if (is_file($sharedConfig)) putenv('P2K_TP_CONFIG=' . $sharedConfig);
    $_SERVER['P2K_PREVIEW_ACTIVE'] = '1';
    $_SERVER['P2K_PREVIEW_RELEASE'] = $releaseId;
    $_SERVER['REQUEST_URI'] = $originalUri;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    chdir(dirname($file));
    require $file;
    exit;
}

$types = [
    'html'=>'text/html; charset=utf-8',
    'htm'=>'text/html; charset=utf-8',
    'js'=>'text/javascript; charset=utf-8',
    'css'=>'text/css; charset=utf-8',
    'json'=>'application/json; charset=utf-8',
    'svg'=>'image/svg+xml',
    'png'=>'image/png',
    'jpg'=>'image/jpeg',
    'jpeg'=>'image/jpeg',
    'gif'=>'image/gif',
    'webp'=>'image/webp',
    'ico'=>'image/x-icon',
    'txt'=>'text/plain; charset=utf-8',
    'zip'=>'application/zip',
];
header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));

if ($method === 'HEAD') exit;

$body = file_get_contents($file);
if ($body === false) {
    http_response_code(500);
    exit('Unable to read candidate preview file.');
}
if (in_array($extension, ['html','htm'], true)) {
    $label = htmlspecialchars('Candidate preview · ' . $releaseId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $banner = '<div id="p2k-candidate-preview-banner" style="position:fixed;z-index:2147483647;top:0;left:50%;transform:translateX(-50%);padding:5px 12px;border-radius:0 0 8px 8px;background:#f3bd55;color:#17110a;font:700 12px/1.3 system-ui,sans-serif;box-shadow:0 2px 10px #0008">' . $label . '</div>';
    $pos = stripos($body, '</body>');
    $body = $pos === false ? $banner . $body : substr($body, 0, $pos) . $banner . substr($body, $pos);
}
echo $body;
