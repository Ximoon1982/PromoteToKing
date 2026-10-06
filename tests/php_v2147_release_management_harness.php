<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/release-control/src/bootstrap.php';

use P2K\ReleaseControl\ReleaseVersionManager;

function fail2147(string $message): never { throw new RuntimeException($message); }
function mkdir2147(string $path): void { if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) fail2147('mkdir failed'); }
function write2147(string $path, string $data): void { mkdir2147(dirname($path)); file_put_contents($path, $data); }

$tmp = sys_get_temp_dir() . '/p2k-v2147-release-management-' . bin2hex(random_bytes(5));
$runtime = $tmp . '/runtime';
$control = $runtime . '/release-control';
mkdir2147($control . '/releases');
mkdir2147($control . '/previews');
mkdir2147($control . '/runtime-trees');

write2147($tmp . '/VERSION', "2.14.2\n");
write2147($tmp . '/ui-v2.html', '<script src="x.js?v=p2k-2.14.2-aaaaaaaaaaaa-1111111111111111"></script>');
write2147($control . '/state.json', json_encode([
    'schema_version'=>1,
    'mode'=>'slots',
    'public_release'=>'2.14.6-public',
    'previous_public_release'=>'2.14.5-rollback',
    'candidate_release'=>'2.14.7-candidate',
], JSON_PRETTY_PRINT));

$ids = [
    '2.14.6-public',
    '2.14.5-rollback',
    '2.14.7-candidate',
    '2.14.2-aaaaaaaaaaaa',
    '2.14.4-obsolete',
];
foreach ($ids as $id) {
    write2147($control . '/releases/' . $id . '/app/file.txt', str_repeat($id, 4));
    write2147($control . '/releases/' . $id . '/meta/junk.txt', 'test');
}
write2147($control . '/previews/2.14.4-obsolete/app/preview.txt', 'preview');
write2147($control . '/runtime-trees/2.14.4-obsolete/app/runtime.txt', 'runtime');
write2147($control . '/previews/2.14.3-orphan/app/orphan.txt', 'orphan');

$manager = new ReleaseVersionManager($tmp, $runtime);
$inventory = $manager->inventory();
$rows = [];
foreach ($inventory['releases'] as $row) $rows[$row['release_id']] = $row;

foreach ([
    '2.14.6-public'=>'current public',
    '2.14.5-rollback'=>'rollback target',
    '2.14.7-candidate'=>'candidate',
    '2.14.2-aaaaaaaaaaaa'=>'physical recovery baseline',
] as $id=>$role) {
    if (empty($rows[$id]['protected']) || !in_array($role, $rows[$id]['roles'], true)) {
        fail2147($id . ' was not protected as ' . $role);
    }
    try {
        $manager->deleteRelease($id, 'ximoon');
        fail2147('protected release deletion unexpectedly succeeded: ' . $id);
    } catch (RuntimeException $expected) {
    }
}

$obsolete = $rows['2.14.4-obsolete'] ?? null;
if (!is_array($obsolete) || empty($obsolete['cleanup_ready'])) fail2147('obsolete release not cleanup-ready');
if (($obsolete['stats']['inode_entries'] ?? 0) < 6) fail2147('obsolete release stats did not include all managed trees');
if (($inventory['totals']['removable_count'] ?? 0) < 2) fail2147('obsolete/orphan releases not counted');

$result = $manager->deleteRelease('2.14.4-obsolete', 'ximoon');
if (empty($result['ok'])) fail2147('obsolete release deletion failed');
foreach (['releases','previews','runtime-trees'] as $kind) {
    if (file_exists($control . '/' . $kind . '/2.14.4-obsolete')) fail2147('managed artifact remained: ' . $kind);
}

$orphan = $manager->describeRelease('2.14.3-orphan');
if (!is_array($orphan) || empty($orphan['cleanup_ready'])) fail2147('orphan preview was not cleanup-ready');
$manager->deleteRelease('2.14.3-orphan', 'ximoon');
if (file_exists($control . '/previews/2.14.3-orphan')) fail2147('orphan preview remained');

try {
    $manager->deleteRelease('../escape', 'ximoon');
    fail2147('path traversal release id unexpectedly accepted');
} catch (InvalidArgumentException $expected) {
}

echo "v2.14.7 release/version management harness passed\n";
