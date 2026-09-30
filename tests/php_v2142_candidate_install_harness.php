<?php
declare(strict_types=1);

$src = dirname(__DIR__) . '/server/release-control/src';
require_once $src . '/ReleaseSlotPolicy.php';
require_once $src . '/ReleaseSlotFilesystemProbe.php';
require_once $src . '/ReleaseSlotStore.php';
require_once $src . '/ReleaseSlotMaterializer.php';
require_once $src . '/ReleaseCandidatePackage.php';
require_once $src . '/ReleaseStateStore.php';
require_once $src . '/ReleaseCandidateInstaller.php';

use P2K\ReleaseControl\ReleaseCandidateInstaller;
use P2K\ReleaseControl\ReleaseSlotMaterializer;
use P2K\ReleaseControl\ReleaseSlotStore;

$tmp = sys_get_temp_dir() . '/p2k-v2142-' . bin2hex(random_bytes(5));
$root = $tmp . '/root';
$runtime = $tmp . '/runtime';
$pkg = $tmp . '/candidate';
@mkdir($root . '/assets', 0700, true);
@mkdir($runtime, 0700, true);
@mkdir($pkg . '/payload', 0700, true);

$baseHead = str_repeat('a', 40);
$targetHead = str_repeat('b', 40);
$baseKey = 'p2k-2.14.2-' . substr($baseHead, 0, 12) . '-0123456789abcdef';
$targetKey = 'p2k-2.14.3-' . substr($targetHead, 0, 12) . '-fedcba9876543210';

file_put_contents($root . '/VERSION', "2.14.2\n");
file_put_contents($root . '/ui-v2.html', '<script src="x.js?v=' . $baseKey . '"></script>');
file_put_contents($root . '/index.html', "public-old\n");
file_put_contents($root . '/assets/app.js', "unchanged\n");

$base = (new ReleaseSlotMaterializer($root, $runtime))->materialize(
    ['VERSION', 'ui-v2.html', 'index.html', 'assets/app.js'],
    '2.14.2',
    $baseHead,
    $baseKey
);
if (($base['integrity_status'] ?? '') !== 'valid') throw new RuntimeException('base slot invalid');

@mkdir($pkg . '/payload/assets', 0700, true);
file_put_contents($pkg . '/payload/VERSION', "2.14.3\n");
file_put_contents($pkg . '/payload/ui-v2.html', '<script src="x.js?v=' . $targetKey . '"></script>');
file_put_contents($pkg . '/payload/index.html', "candidate-new\n");

$payloadPaths = ['VERSION', 'index.html', 'ui-v2.html'];
$manifest = '';
foreach ($payloadPaths as $path) {
    $manifest .= hash_file('sha256', $pkg . '/payload/' . $path) . "  ./" . $path . "\n";
}
file_put_contents($pkg . '/CANDIDATE_PAYLOAD.sha256', $manifest);
file_put_contents($pkg . '/CANDIDATE_REMOVED_PATHS.txt', '');
$manifestSha = hash_file('sha256', $pkg . '/CANDIDATE_PAYLOAD.sha256');
$meta = [
    'schema_version'=>1,
    'version'=>'2.14.3',
    'source_head'=>$targetHead,
    'cache_key'=>$targetKey,
    'build_id'=>'v2143-test-candidate',
    'qualification_workflow'=>'P2K v2.14.3 qualification',
    'payload_manifest_sha256'=>$manifestSha,
    'accepted_public_builds'=>[
        ['version'=>'2.14.2', 'source_head'=>$baseHead],
    ],
];
file_put_contents($pkg . '/CANDIDATE_RELEASE.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$before = [];
foreach (['VERSION', 'ui-v2.html', 'index.html', 'assets/app.js'] as $path) {
    $before[$path] = hash_file('sha256', $root . '/' . $path);
}

$installer = new ReleaseCandidateInstaller($root, $runtime);
$result = $installer->install($pkg, 'ximoon');
$candidate = $result['candidate'] ?? [];
if (($candidate['release_id'] ?? '') !== '2.14.3-' . substr($targetHead, 0, 12)) throw new RuntimeException('candidate id mismatch');
if (($candidate['integrity_status'] ?? '') !== 'valid') throw new RuntimeException('candidate slot invalid');
if (empty($candidate['candidate'])) throw new RuntimeException('candidate marker missing');
if (!empty($candidate['routing_enabled'])) throw new RuntimeException('candidate routing unexpectedly enabled');
if (($candidate['base_release_id'] ?? '') !== ($base['release_id'] ?? '')) throw new RuntimeException('candidate base mismatch');

foreach ($before as $path => $hash) {
    if (hash_file('sha256', $root . '/' . $path) !== $hash) throw new RuntimeException('direct-root changed: ' . $path);
}

$slotRoot = $runtime . '/release-control/releases/' . $candidate['release_id'] . '/app';
if ((string)file_get_contents($slotRoot . '/index.html') !== "candidate-new\n") throw new RuntimeException('candidate overlay missing');
if ((string)file_get_contents($slotRoot . '/assets/app.js') !== "unchanged\n") throw new RuntimeException('candidate did not inherit unchanged base file');

$statePath = $runtime . '/release-control/state.json';
$state = json_decode((string)file_get_contents($statePath), true);
if (($state['mode'] ?? '') !== 'direct-root') throw new RuntimeException('candidate registration changed serving mode');
if (($state['public_release'] ?? '') !== '2.14.2 (direct root)') throw new RuntimeException('public release state changed incorrectly');
if (($state['candidate_release'] ?? '') !== $candidate['release_id']) throw new RuntimeException('candidate was not registered');
if (($state['candidate_registered_by'] ?? '') !== 'ximoon') throw new RuntimeException('candidate actor missing');

$again = $installer->install($pkg, 'ximoon');
if (($again['candidate']['materialization'] ?? '') !== 'existing_valid') throw new RuntimeException('same candidate install was not idempotent');

file_put_contents($pkg . '/payload/index.html', "tampered\n");
$rejected = false;
try {
    $installer->install($pkg, 'ximoon');
} catch (RuntimeException) {
    $rejected = true;
}
if (!$rejected) throw new RuntimeException('tampered candidate package was accepted');

$store = new ReleaseSlotStore($root, $runtime);
$verified = $store->inspectSlot($candidate['release_id'], true);
if (($verified['integrity_status'] ?? '') !== 'valid') throw new RuntimeException('candidate slot damaged after rejected reinstall');

function rrmdir(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    @chmod($path, 0700);
    foreach (array_diff(scandir($path) ?: [], ['.','..']) as $entry) rrmdir($path . '/' . $entry);
    @rmdir($path);
}
rrmdir($tmp);
echo "v2.14.2 candidate installation harness passed\n";
