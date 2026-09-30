<?php
declare(strict_types=1);

$src = dirname(__DIR__) . '/server/release-control/src';
require_once $src . '/ReleaseSlotPolicy.php';
require_once $src . '/ReleaseSlotFilesystemProbe.php';
require_once $src . '/ReleaseSlotStore.php';
require_once $src . '/ReleaseSlotMaterializer.php';

use P2K\ReleaseControl\ReleaseSlotMaterializer;
use P2K\ReleaseControl\ReleaseSlotPolicy;
use P2K\ReleaseControl\ReleaseSlotStore;

$tmp = sys_get_temp_dir() . '/p2k-v2141-' . bin2hex(random_bytes(5));
$root = $tmp . '/root';
$runtime = $tmp . '/runtime';
@mkdir($root . '/assets', 0700, true);
@mkdir($root . '/data', 0700, true);
@mkdir($runtime, 0700, true);
file_put_contents($root . '/.htaccess', "Options -Indexes\n");
file_put_contents($root . '/VERSION', "2.14.0\n");
file_put_contents($root . '/ui-v2.html', '<script src="x.js?v=p2k-2.14.0-123456789abc-0123456789abcdef"></script>');
file_put_contents($root . '/index.html', "old-index\n");
file_put_contents($root . '/assets/app.js', "shared-unchanged\n");
file_put_contents($root . '/data/secret.json', '{"do_not_copy":true}');

$paths = ['VERSION', 'ui-v2.html', 'index.html', 'assets/app.js'];
$materializer = new ReleaseSlotMaterializer($root, $runtime);
$old = $materializer->materialize($paths, '2.14.0', '123456789abcdef123456789abcdef123456789a');
if (($old['integrity_status'] ?? '') !== 'valid') throw new RuntimeException('old slot invalid');
if (($old['release_id'] ?? '') !== '2.14.0-123456789abc') throw new RuntimeException('old slot id mismatch');
if (file_exists($runtime . '/release-control/releases/' . $old['release_id'] . '/app/data/secret.json')) throw new RuntimeException('shared data leaked');

$replacement = $root . '/index.html.next';
file_put_contents($replacement, "new-index\n");
rename($replacement, $root . '/index.html');
file_put_contents($root . '/VERSION.next', "2.14.1\n");
rename($root . '/VERSION.next', $root . '/VERSION');
file_put_contents($root . '/ui-v2.html.next', '<script src="x.js?v=p2k-2.14.1-fedcba987654-fedcba9876543210"></script>');
rename($root . '/ui-v2.html.next', $root . '/ui-v2.html');

$oldIndex = $runtime . '/release-control/releases/2.14.0-123456789abc/app/index.html';
if ((string)file_get_contents($oldIndex) !== "old-index\n") throw new RuntimeException('old slot changed after atomic root replacement');

$new = $materializer->materialize($paths, '2.14.1', 'fedcba9876543210fedcba9876543210fedcba98');
if (($new['integrity_status'] ?? '') !== 'valid') throw new RuntimeException('new slot invalid');
if (($new['release_id'] ?? '') !== '2.14.1-fedcba987654') throw new RuntimeException('new slot id mismatch');
$newIndex = $runtime . '/release-control/releases/' . $new['release_id'] . '/app/index.html';
if ((string)file_get_contents($newIndex) !== "new-index\n") throw new RuntimeException('new slot content mismatch');

$store = new ReleaseSlotStore($root, $runtime);
$slots = $store->listSlots();
if (count($slots) !== 2) throw new RuntimeException('slot discovery count mismatch');
foreach ($slots as $slot) if (($slot['integrity_status'] ?? '') !== 'valid') throw new RuntimeException('quick slot validation failed');
$verified = $store->inspectSlot($new['release_id'], true);
if (($verified['integrity_status'] ?? '') !== 'valid') throw new RuntimeException('full slot hash verification failed');

$badRejected = false;
try {
    $materializer->materialize(['VERSION', 'data/secret.json'], '2.14.1', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
} catch (InvalidArgumentException) {
    $badRejected = true;
}
if (!$badRejected) throw new RuntimeException('shared path was not rejected');

if (ReleaseSlotPolicy::isReleaseOwnedPath('.htaccess')) throw new RuntimeException('stable recovery .htaccess admitted into current slot policy');
if (!ReleaseSlotPolicy::isReleaseOwnedPathForVersion('.htaccess', 1)) throw new RuntimeException('legacy slot policy no longer recognizes historical .htaccess');
if (ReleaseSlotPolicy::normalizeRelativePath('.htaccess') !== '.htaccess') throw new RuntimeException('root dotfile normalization changed its name');
if (ReleaseSlotPolicy::normalizeRelativePath('./VERSION') !== 'VERSION') throw new RuntimeException('explicit relative prefix normalization failed');
if (ReleaseSlotPolicy::normalizeRelativePath('../VERSION') !== '') throw new RuntimeException('parent traversal was accepted');
if (ReleaseSlotPolicy::normalizeRelativePath('/VERSION') !== '') throw new RuntimeException('absolute path was accepted');
if (!ReleaseSlotPolicy::isReleaseOwnedPath('assets/app.js')) throw new RuntimeException('owned path rejected');
if (ReleaseSlotPolicy::isReleaseOwnedPath('ReleaseControl.php')) throw new RuntimeException('recovery page admitted into slot');
if (ReleaseSlotPolicy::isReleaseOwnedPath('server/release-control/src/bootstrap.php')) throw new RuntimeException('recovery plane admitted into slot');
if (ReleaseSlotPolicy::isReleaseOwnedPath('server/team-points/config/config.local.php')) throw new RuntimeException('host-local config admitted into slot');

function rrmdir(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    @chmod($path, 0700);
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) rrmdir($path . '/' . $entry);
    @rmdir($path);
}
rrmdir($tmp);
echo "v2.14.1 release-slot harness passed\n";
