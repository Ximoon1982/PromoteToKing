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
$preview = new ReleasePreviewSession($root);
$originalUri = p2k_preview_original_uri($_SERVER);
$pathPart = (string)(parse_url($originalUri, PHP_URL_PATH) ?? '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($pathPart === '/PreviewRouter.php' || $pathPart === 'PreviewRouter.php') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-P2K-Preview-Error: direct-router-request');
    exit('P2K preview routing error: direct-router-request');
}

$username = $auth->currentUsername();
if ($username === '' && !empty($_COOKIE[ReleasePreviewSession::COOKIE])) {
    if (in_array($method, ['GET','HEAD'], true)) {
        $query = [];
        $rawQuery = (string)(parse_url($originalUri, PHP_URL_QUERY) ?? '');
        if ($rawQuery !== '') parse_str($rawQuery, $query);
        if (strtolower(trim((string)($query['oauth_result'] ?? ''))) === 'fail') {
            header('Cache-Control: no-store');
            header('Location: /ReleaseControl.php?oauth_result=fail', true, 302);
            exit;
        }
        header('Cache-Control: no-store');
        header('Location: ' . $auth->loginUrl($originalUri !== '' ? $originalUri : '/'), true, 302);
        exit;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok'=>false,
        'error'=>[
            'code'=>'CANDIDATE_PREVIEW_REAUTH_REQUIRED',
            'message'=>'Re-authenticate with Chess.com before continuing this candidate preview.',
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$status = $username !== '' && $auth->isSuperAdmin($username)
    ? $preview->status($username)
    : ['enabled'=>false,'reason'=>'unauthorized'];

if (empty($status['enabled'])) {
    $preview->clearInvalidCookie();
    if (in_array($method, ['GET','HEAD'], true)) {
        header('Cache-Control: no-store');
        header('Location: ' . ($originalUri !== '' ? $originalUri : '/'), true, 302);
        exit;
    }
    http_response_code(409);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Candidate preview session is no longer valid. Reload the page to return to the public release.');
}
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
    header('Content-Type: text/plain; charset=utf-8');
    header('X-P2K-Preview-Error: path-not-release-owned');
    exit('P2K preview routing error: path-not-release-owned; path=' . $relative);
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
    header('Content-Type: text/plain; charset=utf-8');
    header('X-P2K-Preview-Error: candidate-file-missing');
    exit('P2K preview routing error: candidate-file-missing; path=' . $relative);
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
        header('Content-Type: text/plain; charset=utf-8');
        header('X-P2K-Preview-Error: php-path-not-allowed');
        exit('P2K preview routing error: php-path-not-allowed; path=' . $relative);
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
    $assetKey = rawurlencode($releaseId);
    $rewritten = preg_replace_callback(
        '~\\b(src|href)=(["\\'])(?!https?:|//|data:|#)([^"\\']+\\.(?:js|css|png|jpe?g|gif|webp|svg|ico|woff2?|ttf)(?:\\?[^"\\']*)?)\\2~i',
        static function (array $match) use ($assetKey): string {
            $url = $match[3];
            $separator = str_contains($url, '?') ? '&amp;' : '?';
            return $match[1] . '=' . $match[2] . $url . $separator . '__p2k_preview=' . $assetKey . $match[2];
        },
        $body
    );
    if (is_string($rewritten)) $body = $rewritten;

    $bootstrap = $auth->previewAuthBootstrap($username);
    if (is_array($bootstrap)) {
        $encoded = base64_encode((string)json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $meta = '<meta name="p2k-preview-auth-bootstrap" content="' . htmlspecialchars($encoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        $headPos = stripos($body, '</head>');
        $body = $headPos === false ? $meta . $body : substr($body, 0, $headPos) . $meta . substr($body, $headPos);
    }

    $label = htmlspecialchars('Candidate preview · ' . $releaseId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $banner = '<div id="p2k-candidate-preview-banner" style="position:fixed;z-index:2147483647;top:0;left:50%;transform:translateX(-50%);padding:5px 12px;border-radius:0 0 8px 8px;background:#f3bd55;color:#17110a;font:700 12px/1.3 system-ui,sans-serif;box-shadow:0 2px 10px #0008">' . $label . '</div>';
    $pos = stripos($body, '</body>');
    $body = $pos === false ? $banner . $body : substr($body, 0, $pos) . $banner . substr($body, $pos);
}
echo $body;
