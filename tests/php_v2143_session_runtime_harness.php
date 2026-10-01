<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/release-control/src/bootstrap.php';

use P2K\ReleaseControl\ReleaseControlAuth;

function fail(string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
function ok(bool $condition, string $message): void {
    if (!$condition) fail($message);
}

$tmp = sys_get_temp_dir() . '/p2k-v2143-session-' . bin2hex(random_bytes(6));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) fail('unable to create temp session directory');

$originalPath = (string)session_save_path();
$originalName = (string)session_name();
$originalId = (string)session_id();

try {
    session_save_path($tmp);

    $_COOKIE['P2KTPSESSID'] = 'tp-session-2143';
    session_name('P2KTPSESSID');
    session_id($_COOKIE['P2KTPSESSID']);
    ok(session_start(), 'unable to create Team Points session');
    $_SESSION['p2k_tp_admin_username'] = 'ximoon';
    $_SESSION['p2k_tp_csrf'] = 'tp-csrf';
    $_SESSION['p2k_tp_expires'] = time() + 3600;
    session_write_close();

    $_COOKIE['P2KOAUTH'] = 'oauth-session-2143';
    session_name('P2KOAUTH');
    session_id($_COOKIE['P2KOAUTH']);
    ok(session_start(), 'unable to create OAuth session');
    $_SESSION['oauth_user'] = ['username'=>'ximoon','expires_at'=>time()+3600];
    $_SESSION['oauth_access'] = ['access_token'=>'test-token','expires_at'=>time()+3600];
    $_SESSION['oauth_csrf'] = 'oauth-csrf';
    session_write_close();

    session_name('P2KBASELINE');
    session_id('baseline-session-2143');
    $baselineName = session_name();
    $baselineId = session_id();

    $auth = new ReleaseControlAuth(dirname(__DIR__));
    $method = new ReflectionMethod($auth, 'readSession');

    $tp = $method->invoke($auth, 'P2KTPSESSID', $tmp);
    ok(($tp['p2k_tp_admin_username'] ?? '') === 'ximoon', 'Team Points session was not read');
    ok(session_status() === PHP_SESSION_NONE, 'manual inspection left a session active');
    ok(session_name() === $baselineName, 'session_name leaked after Team Points inspection');
    ok(session_id() === $baselineId, 'session_id leaked after Team Points inspection');

    $oauth = $method->invoke($auth, 'P2KOAUTH', $tmp);
    ok(($oauth['oauth_user']['username'] ?? '') === 'ximoon', 'OAuth session was not read');
    ok(session_name() === $baselineName, 'session_name leaked after OAuth inspection');
    ok(session_id() === $baselineId, 'session_id leaked after OAuth inspection');

    echo "Validated Release Control session-runtime isolation across P2KTPSESSID and P2KOAUTH.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @session_save_path($originalPath);
    @session_name($originalName);
    @session_id($originalId);
    foreach (glob($tmp . '/*') ?: [] as $path) @unlink($path);
    @rmdir($tmp);
}
