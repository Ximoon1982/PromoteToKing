<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/trophy-gallery/src/TrophyGalleryStore.php';

use P2K\TrophyGallery\TrophyGalleryStore;

$base = sys_get_temp_dir().'/p2k-trophy-route-'.bin2hex(random_bytes(5));
$physical = $base.'/physical';
$slot = $base.'/slot';
@mkdir($physical,0777,true);
@mkdir($slot,0777,true);
putenv('P2K_PUBLIC_ROOT='.$physical);
putenv('P2K_PREVIEW_PUBLIC_ROOT');

try {
    $store = new TrophyGalleryStore();
    $saved = $store->save([
        'id'=>'routed-test-trophy',
        'status'=>'draft',
        'league'=>'Test League',
        'competition'=>'Routing',
        'title'=>'Routed Trophy',
        'award_date'=>'2026-10-08',
        'description_md'=>'',
        'award_page'=>'',
        'competition_page'=>'',
        'result_table_url'=>'',
        'vignette_url'=>'',
        'modal_url'=>'',
        'matches'=>[],
        'migration_review'=>[],
    ], null);
    if (($saved['value']['id'] ?? '') !== 'routed-test-trophy') throw new RuntimeException('save failed');
    $catalog = $physical.'/data/trophy-gallery/catalog.json';
    if (!is_file($catalog)) throw new RuntimeException('shared physical catalogue not used');
    if (is_dir($slot.'/data/trophy-gallery')) throw new RuntimeException('slot-local mutable data was created');
    echo "v2.14.9 routed Trophy Gallery shared-data harness passed\n";
} finally {
    putenv('P2K_PUBLIC_ROOT');
    $it = is_dir($base) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) : null;
    if ($it) foreach ($it as $item) { $p=$item->getPathname(); if($item->isDir()&&!$item->isLink()) @rmdir($p); else @unlink($p); }
    @rmdir($base);
}
