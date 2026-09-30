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
require_once $src . '/ReleasePreviewTree.php';
require_once $src . '/ReleasePreviewSession.php';

use P2K\ReleaseControl\ReleaseCandidateInstaller;
use P2K\ReleaseControl\ReleasePreviewSession;
use P2K\ReleaseControl\ReleaseSlotMaterializer;
use P2K\ReleaseControl\ReleaseSlotStore;

$tmp = sys_get_temp_dir() . '/p2k-v2143-' . bin2hex(random_bytes(5));
$root = $tmp . '/root';
$runtime = $tmp . '/runtime';
$pkg = $tmp . '/candidate';
@mkdir($root . '/assets', 0700, true);
@mkdir($root . '/data', 0700, true);
@mkdir($root . '/logs', 0700, true);
@mkdir($root . '/server/team-points/config', 0700, true);
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
file_put_contents($root . '/data/shared.json', '{"shared":true}');
file_put_contents($root . '/server/team-points/config/config.local.php', "<?php return ['storage'=>['runtime_dir'=>'" . addslashes($runtime) . "']];\n");

$materializer = new ReleaseSlotMaterializer($root, $runtime);
$base = $materializer->materialize(
    ['VERSION', 'ui-v2.html', 'index.html', 'assets/app.js'],
    '2.14.2',
    $baseHead,
    $baseKey
);
if (($base['integrity_status'] ?? '') !== 'valid') throw new RuntimeException('base slot invalid');

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
file_put_contents($pkg . '/CANDIDATE_RELEASE.json', json_encode([
    'schema_version'=>1,
    'version'=>'2.14.3',
    'source_head'=>$targetHead,
    'cache_key'=>$targetKey,
    'build_id'=>'v2143-preview-harness',
    'qualification_workflow'=>'P2K v2.14.3 qualification',
    'payload_manifest_sha256'=>$manifestSha,
    'accepted_public_builds'=>[
        ['version'=>'2.14.2', 'source_head'=>$baseHead],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$result = (new ReleaseCandidateInstaller($root, $runtime))->install($pkg, 'ximoon');
$candidate = $result['candidate'] ?? [];
if (($candidate['integrity_status'] ?? '') !== 'valid' || empty($candidate['candidate'])) throw new RuntimeException('candidate invalid');
if (!empty($candidate['routing_enabled'])) throw new RuntimeException('candidate unexpectedly public-routable');

$_COOKIE = [];
$prepared = (new \P2K\ReleaseControl\ReleasePreviewTree($root, $runtime))->prepare((string)$candidate['release_id']);
if (empty($prepared['valid'])) throw new RuntimeException('preview tree preparation failed');
$preview = new ReleasePreviewSession($root, $runtime);
$status = $preview->enable('ximoon');
if (empty($status['enabled'])) throw new RuntimeException('preview enable failed');
if (($status['release_id'] ?? '') !== ($candidate['release_id'] ?? '')) throw new RuntimeException('preview candidate mismatch');

$live = $preview->status('ximoon');
if (empty($live['enabled'])) throw new RuntimeException('signed preview session did not validate');
$appRoot = (string)($live['preview_tree']['app_root'] ?? '');
if ($appRoot === '' || !is_dir($appRoot)) throw new RuntimeException('preview tree missing');
if ((string)file_get_contents($appRoot . '/index.html') !== "candidate-new\n") throw new RuntimeException('candidate HTML missing from preview tree');
if ((string)file_get_contents($appRoot . '/assets/app.js') !== "unchanged\n") throw new RuntimeException('unchanged base file missing from preview tree');
if (!is_link($appRoot . '/data')) throw new RuntimeException('shared data directory is not linked');
if (realpath($appRoot . '/data') !== realpath($root . '/data')) throw new RuntimeException('preview data link points away from public shared state');
if (!is_link($appRoot . '/server/team-points/config/config.local.php')) throw new RuntimeException('host-local config was not linked');
if (realpath($appRoot . '/server/team-points/config/config.local.php') !== realpath($root . '/server/team-points/config/config.local.php')) throw new RuntimeException('host-local config link mismatch');

$store = new ReleaseSlotStore($root, $runtime);
$verified = $store->inspectSlot((string)$candidate['release_id'], true);
if (($verified['integrity_status'] ?? '') !== 'valid') throw new RuntimeException('candidate slot changed while creating preview tree');

$preview->disable();
$disabled = $preview->status('ximoon');
if (!empty($disabled['enabled'])) throw new RuntimeException('preview disable failed');

function rrmdir(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    @chmod($path, 0700);
    foreach (array_diff(scandir($path) ?: [], ['.','..']) as $entry) rrmdir($path . '/' . $entry);
    @rmdir($path);
}
rrmdir($tmp);
echo "v2.14.3 candidate preview harness passed\n";
