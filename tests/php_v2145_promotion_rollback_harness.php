<?php
declare(strict_types=1);

$src = dirname(__DIR__) . '/server/release-control/src';
foreach ([
    'ReleaseSlotPolicy.php','ReleaseSlotFilesystemProbe.php','ReleaseSlotStore.php','ReleaseSlotMaterializer.php',
    'ReleaseCandidatePackage.php','ReleaseStateStore.php','ReleaseRuntimeTree.php','ReleaseDeploymentManager.php',
    'ReleaseCandidateInstaller.php'
] as $file) require_once $src . '/' . $file;

use P2K\ReleaseControl\ReleaseCandidateInstaller;
use P2K\ReleaseControl\ReleaseDeploymentManager;
use P2K\ReleaseControl\ReleaseRuntimeTree;
use P2K\ReleaseControl\ReleaseSlotMaterializer;
use P2K\ReleaseControl\ReleaseSlotStore;

function fail2145(string $message): never { throw new RuntimeException($message); }
function rrmdir2145(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    @chmod($path, 0700);
    foreach (array_diff(scandir($path) ?: [], ['.','..']) as $entry) rrmdir2145($path . '/' . $entry);
    @rmdir($path);
}
function makePackage2145(string $dir, string $version, string $head, string $key, string $baseVersion, string $baseHead, string $indexText): void {
    @mkdir($dir . '/payload', 0700, true);
    file_put_contents($dir . '/payload/VERSION', $version . "\n");
    file_put_contents($dir . '/payload/ui-v2.html', '<script src="x.js?v=' . $key . '"></script>');
    file_put_contents($dir . '/payload/index.html', $indexText);
    $manifest = '';
    foreach (['VERSION','index.html','ui-v2.html'] as $path) {
        $manifest .= hash_file('sha256', $dir . '/payload/' . $path) . "  ./" . $path . "\n";
    }
    file_put_contents($dir . '/CANDIDATE_PAYLOAD.sha256', $manifest);
    file_put_contents($dir . '/CANDIDATE_REMOVED_PATHS.txt', '');
    file_put_contents($dir . '/CANDIDATE_RELEASE.json', json_encode([
        'schema_version'=>1,
        'version'=>$version,
        'source_head'=>$head,
        'cache_key'=>$key,
        'build_id'=>'v' . str_replace('.', '', $version) . '-transition-test',
        'qualification_workflow'=>'P2K v' . $version . ' qualification',
        'payload_manifest_sha256'=>hash_file('sha256', $dir . '/CANDIDATE_PAYLOAD.sha256'),
        'accepted_public_builds'=>[['version'=>$baseVersion,'source_head'=>$baseHead]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$tmp = sys_get_temp_dir() . '/p2k-v2145-' . bin2hex(random_bytes(5));
$root = $tmp . '/root';
$runtime = $tmp . '/runtime';
$pkg = $tmp . '/candidate';
@mkdir($root . '/assets', 0700, true);
@mkdir($root . '/data', 0700, true);
@mkdir($root . '/logs', 0700, true);
@mkdir($root . '/storage', 0700, true);
@mkdir($runtime, 0700, true);

$baseHead = str_repeat('a', 40);
$targetHead = str_repeat('b', 40);
$baseKey = 'p2k-2.14.2-' . substr($baseHead, 0, 12) . '-0123456789abcdef';
$targetKey = 'p2k-2.14.5-' . substr($targetHead, 0, 12) . '-fedcba9876543210';

file_put_contents($root . '/VERSION', "2.14.2\n");
file_put_contents($root . '/ui-v2.html', '<script src="x.js?v=' . $baseKey . '"></script>');
file_put_contents($root . '/index.html', "public-2142\n");
file_put_contents($root . '/assets/app.js', "shared-app\n");
file_put_contents($root . '/data/live.json', "{\"release\":\"shared\"}\n");

$base = (new ReleaseSlotMaterializer($root, $runtime))->materialize(
    ['VERSION','ui-v2.html','index.html','assets/app.js'],
    '2.14.2',
    $baseHead,
    $baseKey
);
if (($base['integrity_status'] ?? '') !== 'valid') fail2145('base slot invalid');

makePackage2145($pkg, '2.14.5', $targetHead, $targetKey, '2.14.2', $baseHead, "candidate-2145\n");
$install = (new ReleaseCandidateInstaller($root, $runtime))->install($pkg, 'ximoon');
$candidate = $install['candidate'] ?? [];
if (($candidate['integrity_status'] ?? '') !== 'valid') fail2145('candidate invalid');
$candidateId = (string)($candidate['release_id'] ?? '');
$baseId = (string)($base['release_id'] ?? '');

$rootBefore = [
    'VERSION'=>hash_file('sha256', $root . '/VERSION'),
    'ui-v2.html'=>hash_file('sha256', $root . '/ui-v2.html'),
    'index.html'=>hash_file('sha256', $root . '/index.html'),
];

$manager = new ReleaseDeploymentManager($root, $runtime);
$promoted = $manager->promote('ximoon');
$state = $promoted['state'] ?? [];
if (($state['mode'] ?? '') !== 'slots') fail2145('promotion did not enable slot routing');
if (($state['public_release'] ?? '') !== $candidateId) fail2145('candidate not selected as public');
if (($state['previous_public_release'] ?? '') !== $baseId) fail2145('rollback target not preserved');
if (!empty($state['candidate_release'])) fail2145('promoted candidate remained registered');

$runtimeTree = new ReleaseRuntimeTree($root, $runtime);
$publicRuntime = $runtimeTree->describeExisting($candidateId, (string)($state['public_manifest_sha256'] ?? ''));
$rollbackRuntime = $runtimeTree->describeExisting($baseId, (string)($state['previous_public_manifest_sha256'] ?? ''));
if (!is_array($publicRuntime) || !is_array($rollbackRuntime)) fail2145('promotion runtime trees missing');
if ((string)file_get_contents($publicRuntime['app_root'] . '/index.html') !== "candidate-2145\n") fail2145('promoted runtime content mismatch');
if ((string)file_get_contents($rollbackRuntime['app_root'] . '/index.html') !== "public-2142\n") fail2145('rollback runtime content mismatch');
if (!is_link($publicRuntime['app_root'] . '/data')) fail2145('shared data was not linked into public runtime');

foreach ($rootBefore as $path => $hash) {
    if (hash_file('sha256', $root . '/' . $path) !== $hash) fail2145('promotion changed physical root: ' . $path);
}

file_put_contents($root . '/index.html', "physical-root-can-change-without-changing-routed-slot\n");
if ((string)file_get_contents($publicRuntime['app_root'] . '/index.html') !== "candidate-2145\n") fail2145('routed release depends on physical root content');

$rolled = $manager->rollback('ximoon');
$state = $rolled['state'] ?? [];
if (($state['mode'] ?? '') !== 'slots') fail2145('rollback left slot mode');
if (($state['public_release'] ?? '') !== $baseId) fail2145('rollback did not restore previous public');
if (($state['candidate_release'] ?? '') !== $candidateId) fail2145('rolled-back release was not restored as candidate');
if (!empty($state['previous_public_release'])) fail2145('rollback target should be consumed');

$repromoted = $manager->promote('ximoon');
$state = $repromoted['state'] ?? [];
if (($state['public_release'] ?? '') !== $candidateId) fail2145('re-promotion failed');
if (($state['previous_public_release'] ?? '') !== $baseId) fail2145('re-promotion did not restore rollback target');
if ((int)($state['transition_sequence'] ?? 0) !== 3) fail2145('transition sequence mismatch');
if (($state['last_transition']['action'] ?? '') !== 'promote') fail2145('last transition not recorded');

$store = new ReleaseSlotStore($root, $runtime);
foreach ([$baseId,$candidateId] as $id) {
    if (($store->inspectSlot($id, true)['integrity_status'] ?? '') !== 'valid') fail2145('slot integrity lost after transitions: ' . $id);
}

// Candidate installation after the first promotion must use the routed public slot,
// not the untouched physical 2.14.2 root, as its base identity.
$futurePkg = $tmp . '/future-candidate';
$futureHead = str_repeat('c', 40);
$futureKey = 'p2k-2.14.6-' . substr($futureHead, 0, 12) . '-0011223344556677';
makePackage2145($futurePkg, '2.14.6', $futureHead, $futureKey, '2.14.5', $targetHead, "future-2146\n");
$future = (new ReleaseCandidateInstaller($root, $runtime))->install($futurePkg, 'ximoon');
if (($future['candidate']['base_release_id'] ?? '') !== $candidateId) fail2145('post-promotion candidate used physical root instead of routed public slot');
if (($future['state']['candidate_release'] ?? '') !== '2.14.6-' . substr($futureHead, 0, 12)) fail2145('future candidate registration failed in slot mode');

rrmdir2145($tmp);
echo "v2.14.5 promotion/rollback lifecycle harness passed\n";
