<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/release-control/src/ReleaseControlAuth.php';

use P2K\ReleaseControl\ReleaseControlAuth;

function fail2146(string $message): never { throw new RuntimeException($message); }
function rrmdir2146(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (array_diff(scandir($path) ?: [], ['.','..']) as $entry) rrmdir2146($path . '/' . $entry);
    @rmdir($path);
}

$tmp = sys_get_temp_dir() . '/p2k-v2146-csrf-' . bin2hex(random_bytes(5));
@mkdir($tmp . '/data/runtime-v280/release-control', 0700, true);
$auth = new ReleaseControlAuth($tmp);

$token = $auth->controlCsrfToken('ximoon');
if ($token === '') fail2146('control token was not issued');
if (!$auth->validateControlCsrfToken('ximoon', $token)) fail2146('fresh control token did not validate');

// Simulate the dashboard/preview replacing both application session cookies after
// ReleaseControl.php rendered its form. The recovery-plane token must not depend
// on either application session id or its rotating CSRF value.
$_COOKIE['P2KTPSESSID'] = 'regenerated-team-points-session';
$_COOKIE['P2KOAUTH'] = 'regenerated-oauth-session';
if (!$auth->validateControlCsrfToken('ximoon', $token)) fail2146('application session regeneration invalidated Release Control token');

if ($auth->validateControlCsrfToken('someone-else', $token)) fail2146('control token was not identity-bound');
$tampered = substr($token, 0, -1) . (substr($token, -1) === 'A' ? 'B' : 'A');
if ($auth->validateControlCsrfToken('ximoon', $tampered)) fail2146('tampered control token validated');
if (!is_file($tmp . '/data/runtime-v280/release-control/action-csrf.key')) fail2146('dedicated control secret was not persisted');

rrmdir2146($tmp);
echo "v2.14.6 Release Control CSRF stability harness passed\n";
