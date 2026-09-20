<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = sys_get_temp_dir() . '/p2k-oauth-isolation-' . bin2hex(random_bytes(6));
$legacy = $base . '/legacy';
$runtime = $base . '/runtime';
if (!mkdir($legacy, 0700, true) || !mkdir($runtime, 0700, true)) {
    fwrite(STDERR, "Unable to create test directories.\n");
    exit(1);
}
putenv('P2K_TEST_RUNTIME=' . $runtime);

function p2k_tp_config(): array
{
    return ['storage'=>['runtime_dir'=>(string)getenv('P2K_TEST_RUNTIME')]];
}

function fail_test(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        if ($entry->isDir()) @rmdir($entry->getPathname());
        else @unlink($entry->getPathname());
    }
    @rmdir($path);
}

session_save_path($legacy);
ini_set('session.gc_maxlifetime', '28800');
ini_set('session.gc_probability', '0');
$_SERVER['HTTPS'] = 'on';

$legacyId = 'p2klegacy' . bin2hex(random_bytes(12));
session_name('P2KOAUTH');
session_id($legacyId);
if (!session_start()) fail_test('Unable to create legacy OAuth session.');
$_SESSION['oauth_csrf'] = 'legacy-csrf';
$_SESSION['migration_probe'] = 'preserved';
session_write_close();

$_COOKIE['P2KOAUTH'] = $legacyId;

require_once $root . '/server/team-points/src/OAuthSession.php';

\P2K\TeamPoints\OAuthSession::start();
$isolated = $runtime . '/sessions/oauth';
if (session_save_path() !== $isolated) fail_test('P2KOAUTH did not switch to its isolated session store.');
if ((int)ini_get('session.gc_maxlifetime') !== 604800) fail_test('P2KOAUTH did not apply the seven-day GC lifetime.');
if (($_SESSION['migration_probe'] ?? '') !== 'preserved') fail_test('Existing legacy OAuth session state was not migrated.');
if (!is_file($isolated . '/sess_' . $legacyId)) fail_test('Migrated OAuth session file is missing from isolated storage.');

$username = \P2K\TeamPoints\OAuthSession::authenticatedUsername(true);
if ($username !== '') fail_test('Unauthenticated test session unexpectedly resolved a username.');
if (session_status() === PHP_SESSION_ACTIVE) fail_test('OAuth bridge left P2KOAUTH open.');
if (session_save_path() !== $legacy) fail_test('OAuth bridge did not restore the caller session.save_path.');
if ((int)ini_get('session.gc_maxlifetime') !== 28800) fail_test('OAuth bridge did not restore the caller GC lifetime.');

session_name('SHORTAPP');
session_id('shortapp' . bin2hex(random_bytes(12)));
if (!session_start()) fail_test('Unable to start short-lived application session after OAuth bridge.');
$_SESSION['ok'] = true;
session_write_close();

if (!is_file($isolated . '/sess_' . $legacyId)) fail_test('Short-lived application session interfered with isolated P2KOAUTH storage.');

remove_tree($base);
echo "Validated P2KOAUTH isolated storage, legacy migration and caller runtime restoration.\n";
