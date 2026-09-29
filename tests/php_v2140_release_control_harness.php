<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/release-control/src/ReleaseControlState.php';

use P2K\ReleaseControl\ReleaseControlState;

$tmp = sys_get_temp_dir() . '/p2k-v2140-' . bin2hex(random_bytes(5));
$root = $tmp . '/root';
$runtime = $tmp . '/runtime';
@mkdir($root, 0700, true);
@mkdir($runtime, 0700, true);
file_put_contents($root . '/VERSION', "2.14.0\n");
file_put_contents($root . '/ui-v2.html', '<script src="x.js?v=p2k-2.14.0-123456789abc-0123456789abcdef"></script>');

$service = new ReleaseControlState($root, $runtime);
$first = $service->snapshot();
if (($first['installed_version'] ?? '') !== '2.14.0') throw new RuntimeException('VERSION projection failed');
if (($first['mode'] ?? '') !== 'direct-root') throw new RuntimeException('direct-root fallback failed');
if (($first['state_status'] ?? '') !== 'not_initialized') throw new RuntimeException('missing state must be non-fatal');
if (($first['build_identity']['source_head_short'] ?? '') !== '123456789abc') throw new RuntimeException('build identity parsing failed');
if (($first['capabilities']['rollback'] ?? true) !== false) throw new RuntimeException('rollback must remain disabled');

@mkdir($runtime . '/release-control', 0700, true);
file_put_contents($runtime . '/release-control/state.json', json_encode([
    'schema_version'=>1,
    'mode'=>'direct-root',
    'public_release'=>'2.14.0-test',
    'previous_public_release'=>'2.13.5-test',
    'candidate_release'=>null,
    'updated_at'=>'2026-09-29T00:00:00Z',
    'updated_by'=>'ximoon',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$second = $service->snapshot();
if (($second['state_status'] ?? '') !== 'valid') throw new RuntimeException('valid state rejected');
if (($second['previous_public_release'] ?? '') !== '2.13.5-test') throw new RuntimeException('state projection failed');
if (($second['release_slots_enabled'] ?? true) !== false) throw new RuntimeException('slots must remain disabled in direct-root mode');

file_put_contents($runtime . '/release-control/state.json', '{broken');
$third = $service->snapshot();
if (($third['state_status'] ?? '') !== 'invalid_json') throw new RuntimeException('invalid state not detected');

function rrmdir(string $path): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (array_diff(scandir($path) ?: [], ['.','..']) as $entry) rrmdir($path . '/' . $entry);
    @rmdir($path);
}
rrmdir($tmp);
echo "v2.14.0 release-control PHP harness passed\n";
