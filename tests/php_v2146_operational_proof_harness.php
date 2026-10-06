<?php
declare(strict_types=1);

$src = dirname(__DIR__) . '/server/release-control/src';
foreach ([
    'ReleaseSlotPolicy.php','ReleaseSlotFilesystemProbe.php','ReleaseSlotStore.php','ReleaseSlotMaterializer.php',
    'ReleaseCandidatePackage.php','ReleaseStateStore.php','ReleasePreviewTree.php','ReleaseRuntimeTree.php','ReleaseDeploymentManager.php',
    'ReleaseCandidateInstaller.php'
] as $file) require_once $src . '/' . $file;

use P2K\ReleaseControl\ReleaseCandidateInstaller;
use P2K\ReleaseControl\ReleaseDeploymentManager;
use P2K\ReleaseControl\ReleasePreviewTree;
use P2K\ReleaseControl\ReleaseRuntimeTree;
use P2K\ReleaseControl\ReleaseSlotMaterializer;
use P2K\ReleaseControl\ReleaseSlotStore;
use P2K\ReleaseControl\ReleaseStateStore;

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
$preview = (new ReleasePreviewTree($root, $runtime))->prepare($candidateId);
if (empty($preview['valid'])) fail2145('candidate preview did not prepare');

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

$rootReplacement = $root . '/index.html.atomic-next';
file_put_contents($rootReplacement, "physical-root-atomic-replacement\n");
if (!rename($rootReplacement, $root . '/index.html')) fail2145('unable to atomically replace physical root test file');
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

// v2.14.6 must install on the routed v2.14.5 public slot rather than the
// untouched physical root, then survive failed transitions without pointer drift.
$futurePkg = $tmp . '/future-candidate';
$futureHead = str_repeat('c', 40);
$futureKey = 'p2k-2.14.6-' . substr($futureHead, 0, 12) . '-0011223344556677';
makePackage2145($futurePkg, '2.14.6', $futureHead, $futureKey, '2.14.5', $targetHead, "future-2146\n");
$future = (new ReleaseCandidateInstaller($root, $runtime))->install($futurePkg, 'ximoon');
$futureCandidate = $future['candidate'] ?? [];
$futureId = (string)($futureCandidate['release_id'] ?? '');
if (($futureCandidate['base_release_id'] ?? '') !== $candidateId) fail2145('v2.14.6 candidate used physical root instead of routed v2.14.5');
if ($futureId !== '2.14.6-' . substr($futureHead, 0, 12)) fail2145('v2.14.6 candidate registration failed in slot mode');
$futurePreview = (new ReleasePreviewTree($root, $runtime))->prepare($futureId);
if (empty($futurePreview['valid'])) fail2145('v2.14.6 preview did not prepare');

// A process dying after staging but before atomic rename can leave a state.json.tmp-*.
// Such debris must never become the active pointer.
$stateStore = new ReleaseStateStore($root, $runtime);
$statePath = $stateStore->path();
$beforeStale = $stateStore->read();
$stateHashBeforeStale = hash_file('sha256', $statePath);
$stalePath = $statePath . '.tmp-crash-simulation';
file_put_contents($stalePath, json_encode([
    'schema_version'=>1,
    'mode'=>'slots',
    'public_release'=>$futureId,
    'public_version'=>'2.14.6',
    'candidate_release'=>null,
], JSON_PRETTY_PRINT));
$afterStale = $stateStore->read();
if (($afterStale['public_release'] ?? '') !== ($beforeStale['public_release'] ?? '')) fail2145('stale staged state changed active public pointer');
if (($afterStale['candidate_release'] ?? '') !== $futureId) fail2145('stale staged state changed registered candidate');
if (hash_file('sha256', $statePath) !== $stateHashBeforeStale) fail2145('reading with stale staged state changed canonical state');

// Corrupt candidate integrity before promotion. Promotion must fail before the
// atomic pointer switch and preserve the exact state file.
$futureIndex = $store->slotsDir() . '/' . $futureId . '/app/index.html';
$futureIndexOriginal = (string)file_get_contents($futureIndex);
@chmod($futureIndex, 0644);
file_put_contents($futureIndex, "corrupt-future-slot\n");
if (($store->inspectSlot($futureId, true)['integrity_status'] ?? '') === 'valid') fail2145('candidate corruption was not detected');
$stateHashBeforeFailedPromote = hash_file('sha256', $statePath);
$failedPromote = false;
try { $manager->promote('ximoon'); } catch (Throwable) { $failedPromote = true; }
if (!$failedPromote) fail2145('promotion unexpectedly accepted corrupt v2.14.6 candidate');
if (hash_file('sha256', $statePath) !== $stateHashBeforeFailedPromote) fail2145('failed promotion changed release pointer state');
$state = $stateStore->read();
if (($state['public_release'] ?? '') !== $candidateId || ($state['candidate_release'] ?? '') !== $futureId) {
    fail2145('failed promotion did not preserve v2.14.5 public + v2.14.6 candidate');
}
file_put_contents($futureIndex, $futureIndexOriginal);
@chmod($futureIndex, 0444);
if (($store->inspectSlot($futureId, true)['integrity_status'] ?? '') !== 'valid') fail2145('restored v2.14.6 candidate did not re-verify');

$promoted2146 = $manager->promote('ximoon');
$state = $promoted2146['state'] ?? [];
if (($state['public_release'] ?? '') !== $futureId) fail2145('v2.14.6 promotion failed');
if (($state['previous_public_release'] ?? '') !== $candidateId) fail2145('v2.14.6 promotion did not retain v2.14.5 rollback target');
if (!empty($state['candidate_release'])) fail2145('v2.14.6 remained candidate after promotion');

// Repeat the fail-closed proof for rollback: an invalid rollback slot must not
// move the public pointer away from v2.14.6.
$rollbackIndex = $store->slotsDir() . '/' . $candidateId . '/app/index.html';
$rollbackOriginal = (string)file_get_contents($rollbackIndex);
@chmod($rollbackIndex, 0644);
file_put_contents($rollbackIndex, "corrupt-rollback-slot\n");
if (($store->inspectSlot($candidateId, true)['integrity_status'] ?? '') === 'valid') fail2145('rollback corruption was not detected');
$stateHashBeforeFailedRollback = hash_file('sha256', $statePath);
$failedRollback = false;
try { $manager->rollback('ximoon'); } catch (Throwable) { $failedRollback = true; }
if (!$failedRollback) fail2145('rollback unexpectedly accepted corrupt v2.14.5 target');
if (hash_file('sha256', $statePath) !== $stateHashBeforeFailedRollback) fail2145('failed rollback changed release pointer state');
$state = $stateStore->read();
if (($state['public_release'] ?? '') !== $futureId || ($state['previous_public_release'] ?? '') !== $candidateId) {
    fail2145('failed rollback did not preserve v2.14.6 public + v2.14.5 rollback target');
}
file_put_contents($rollbackIndex, $rollbackOriginal);
@chmod($rollbackIndex, 0444);
if (($store->inspectSlot($candidateId, true)['integrity_status'] ?? '') !== 'valid') fail2145('restored v2.14.5 rollback slot did not re-verify');

$rolled2146 = $manager->rollback('ximoon');
$state = $rolled2146['state'] ?? [];
if (($state['public_release'] ?? '') !== $candidateId) fail2145('v2.14.6 rollback did not restore v2.14.5');
if (($state['candidate_release'] ?? '') !== $futureId) fail2145('rolled-back v2.14.6 was not restored as candidate');

$repromoted2146 = $manager->promote('ximoon');
$state = $repromoted2146['state'] ?? [];
if (($state['public_release'] ?? '') !== $futureId) fail2145('v2.14.6 re-promotion failed');
if (($state['previous_public_release'] ?? '') !== $candidateId) fail2145('v2.14.6 re-promotion did not restore v2.14.5 rollback target');
if ((int)($state['transition_sequence'] ?? 0) !== 6) fail2145('v2.14.6 operational transition sequence mismatch');
if (($state['last_transition']['action'] ?? '') !== 'promote') fail2145('v2.14.6 final transition is not promotion');

@unlink($stalePath);
rrmdir2145($tmp);
echo "v2.14.6 operational proof and interruption recovery harness passed\n";
