<?php
declare(strict_types=1);
require __DIR__.'/../server/team-points/src/RecruitmentHistoryStore.php';
use P2K\TeamPoints\RecruitmentHistoryStore;
use P2K\TeamPoints\RecruitmentHistoryException;
$root=sys_get_temp_dir().'/p2k-recruitment-diag-'.bin2hex(random_bytes(5));
if (!mkdir($root,0700,true)) throw new RuntimeException('Test setup failed');
try {
    // A regular file in the path must not be silently bypassed with a different
    // archive location (which would hide or orphan existing archives).
    file_put_contents($root.'/blocked','not a directory');
    try {
        new RecruitmentHistoryStore($root.'/blocked/recruitment-history');
        throw new RuntimeException('Expected unavailable storage failure');
    } catch (RecruitmentHistoryException $e) {
        if ($e->safeCode !== 'RECRUITMENT_DIRECTORY_UNAVAILABLE') throw $e;
    }
    $store=new RecruitmentHistoryStore($root.'/history');
    if ($store->page(1)['total'] !== 0) throw new RuntimeException('Initial listing failed');
    // Force an index lock error while leaving the directory itself writable.
    mkdir($root.'/history/.index.lock');
    try {
        $store->archive(2019306, 'P2K match', "username,rating\nalpha,1400\n");
        throw new RuntimeException('Expected lock failure');
    } catch (RecruitmentHistoryException $e) {
        if ($e->safeCode !== 'RECRUITMENT_INDEX_LOCK_UNAVAILABLE') throw $e;
    }
    $csvFiles=glob($root.'/history/*.csv')?:[];
    if ($csvFiles!==[]) throw new RuntimeException('Failed archive retained orphaned CSV');
    rmdir($root.'/history/.index.lock');
    $entry=$store->archive(2019306,'P2K match',"username,rating\nalpha,1400\n");
    if (!is_file($store->pathFor($entry))) throw new RuntimeException('Valid archival failed');
    if ($store->page(1)['total'] !== 1) throw new RuntimeException('Valid archive was not indexed');
    echo "PASS explicit storage errors, no invisible directory fallback, no orphan CSV, normal archive\n";
} finally {
    foreach(glob($root.'/history/*')?:[] as $f) if(is_file($f)) @unlink($f);
    @rmdir($root.'/history');
    @unlink($root.'/blocked');
    @rmdir($root);
}
