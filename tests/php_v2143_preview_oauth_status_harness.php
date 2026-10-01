<?php
declare(strict_types=1);

require_once __DIR__ . '/../server/release-control/src/bootstrap.php';

use P2K\ReleaseControl\ReleaseControlAuth;

function fail(string $message): never {
    fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
    exit(1);
}
function ok(bool $condition, string $message): void {
    if (!$condition) fail($message);
}
function b64d(string $value): string {
    $value = strtr($value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) $value .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode($value, true);
    return $decoded === false ? '' : $decoded;
}
function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) rrmdir($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

$tmp = sys_get_temp_dir() . '/p2k-v2143-preview-oauth-' . bin2hex(random_bytes(5));
$runtime = $tmp . '/runtime';
$oauthDir = $runtime . '/sessions/oauth';
$configDir = $tmp . '/server/team-points/config';
@mkdir($oauthDir, 0700, true);
@mkdir($configDir, 0700, true);

$adminToken = 'unit-test-admin-token-2143';
file_put_contents($configDir . '/config.local.php', '<?php return ' . var_export([
    'storage'=>['runtime_dir'=>$runtime],
    'app'=>['admin_token'=>$adminToken, 'cron_token'=>'unit-test-cron-token'],
], true) . ';');
file_put_contents($configDir . '/oauth.local.php', '<?php return ' . var_export([
    'name'=>'Unit Test OAuth',
    'client_id'=>'unit-test-client',
    'redirect_url'=>'https://example.invalid/auth/callback',
    'token_url'=>'https://oauth.chess.com/token',
], true) . ';');

$originalPath = (string)session_save_path();
$originalName = (string)session_name();
$originalId = (string)session_id();

$id = 'previewoauth' . bin2hex(random_bytes(8));
@session_save_path($oauthDir);
session_name('P2KOAUTH');
session_id($id);
ok(session_start(), 'unable to create OAuth fixture session');
$_SESSION = [
    'oauth_csrf'=>'csrf-unit-test',
    'oauth_access'=>[
        'access_token'=>'SECRET_ACCESS_TOKEN_MUST_NOT_LEAK',
        'refresh_token'=>'SECRET_REFRESH_TOKEN_MUST_NOT_LEAK',
        'expires_at'=>time()+3600,
    ],
    'oauth_user'=>[
        'username'=>'ximoon',
        'subject'=>'subject-123',
        'authenticated_at'=>time(),
        'expires_at'=>time()+3600,
    ],
    'oauth_claims'=>[
        'preferred_username'=>'ximoon',
        'country_code'=>'LU',
        'membership'=>'diamond',
    ],
    'oauth_profile'=>[
        'username'=>'ximoon',
        'avatar'=>'https://images.chesscomfiles.com/test.png',
        'url'=>'https://www.chess.com/member/ximoon',
        'name'=>'Ximoon',
        'title'=>'',
        'followers'=>123,
        'joined'=>1700000000,
        'last_online'=>1800000000,
    ],
];
session_write_close();

session_name($originalName);
session_id($originalId);
@session_save_path($originalPath);

$_COOKIE['P2KOAUTH'] = $id;
$_SERVER['HTTPS'] = 'on';

$beforePath = (string)session_save_path();
$beforeName = (string)session_name();
$beforeId = (string)session_id();

$auth = new ReleaseControlAuth($tmp);
$status = $auth->oauthSessionStatus();

ok(($status['ok'] ?? false) === true, 'status not ok');
ok(($status['enabled'] ?? false) === true, 'OAuth should be enabled');
ok(($status['authenticated'] ?? false) === true, 'fixture should be authenticated');
ok((string)($status['profile']['username'] ?? '') === 'ximoon', 'username missing');
ok((string)($status['profile']['countryCode'] ?? '') === 'LU', 'profile claims missing');
ok((string)($status['csrf'] ?? '') === 'csrf-unit-test', 'csrf missing');

$json = json_encode($status, JSON_UNESCAPED_SLASHES);
ok(is_string($json), 'status JSON failed');
ok(!str_contains($json, 'SECRET_ACCESS_TOKEN_MUST_NOT_LEAK'), 'access token leaked');
ok(!str_contains($json, 'SECRET_REFRESH_TOKEN_MUST_NOT_LEAK'), 'refresh token leaked');

$assertion = (string)($status['admin_bootstrap'] ?? '');
$parts = explode('.', $assertion);
ok(count($parts) === 2, 'admin bootstrap assertion missing');
$claims = json_decode(b64d($parts[0]), true);
ok(is_array($claims), 'admin bootstrap payload invalid');
ok((string)($claims['aud'] ?? '') === 'p2k-team-points-admin', 'admin bootstrap audience invalid');
ok((string)($claims['u'] ?? '') === 'ximoon', 'admin bootstrap username invalid');
$key = hash('sha256', "p2k-oauth-admin-bootstrap-v1\0" . $adminToken, true);
$expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0], $key, true)), '+/', '-_'), '=');
ok(hash_equals($expectedSig, $parts[1]), 'admin bootstrap signature invalid');

ok((string)session_save_path() === $beforePath, 'session save path leaked');
ok((string)session_name() === $beforeName, 'session name leaked');
ok((string)session_id() === $beforeId, 'session id leaked');

rrmdir($tmp);
echo "Validated recovery-plane preview OAuth session status and runtime isolation." . PHP_EOL;
