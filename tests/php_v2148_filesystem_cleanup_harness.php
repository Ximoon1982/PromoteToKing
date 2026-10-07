<?php
declare(strict_types=1);

$src = dirname(__DIR__) . '/server/release-control/src';
require_once $src . '/ReleaseSlotPolicy.php';
require_once $src . '/FilesystemCleanupManager.php';

use P2K\ReleaseControl\FilesystemCleanupManager;

function fail2148clean(string $m): never { throw new RuntimeException($m); }
function mkdir2148clean(string $p): void { if (!is_dir($p) && !mkdir($p, 0777, true) && !is_dir($p)) fail2148clean('mkdir failed: '.$p); }
function write2148clean(string $p,string $v='x'): void { mkdir2148clean(dirname($p)); file_put_contents($p,$v); }
function rrmdir2148clean(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (!is_dir($p)) return;
    @chmod($p,0700);
    foreach(array_diff(scandir($p)?:[],['.','..']) as $e) rrmdir2148clean($p.'/'.$e);
    @rmdir($p);
}

$tmp=sys_get_temp_dir().'/p2k-v2148-clean-'.bin2hex(random_bytes(5));
$runtime=$tmp.'/data/runtime-v280';
$control=$runtime.'/release-control';
mkdir2148clean($control.'/releases/2.14.7-aaaaaaaaaaaa/meta');
write2148clean($tmp.'/VERSION',"2.14.7\n");
write2148clean($tmp.'/ui-v2.html','<script src="x.js?v=p2k-2.14.7-aaaaaaaaaaaa-1111111111111111"></script>');

// Physical recovery-baseline manifest protects this otherwise installer-looking top-level path.
$protected='PromoteToKing_v2.14.6_INCREMENTAL_FROM_2.13.x.zip';
write2148clean($control.'/releases/2.14.7-aaaaaaaaaaaa/meta/manifest.tsv',
    str_repeat('a',64)."\t1\t1\t".$protected."\n");
write2148clean($tmp.'/'.$protected,'baseline-owned');

$zip='PromoteToKing_v2.14.7_INCREMENTAL_FROM_2.13.x.zip';
$dir='PromoteToKing_v2.14.7_INCREMENTAL_FROM_2.13.x';
write2148clean($tmp.'/'.$zip,str_repeat('z',2048));
write2148clean($tmp.'/'.$dir.'/payload/a.txt',str_repeat('a',4096));
write2148clean($tmp.'/TrophyEngraver/keep.txt','other project');

// Nested symlink candidate must be listed as blocked, never cleanup-ready.
$symlinkDir='PromoteToKing_v2.14.5_INCREMENTAL_FROM_2.13.x';
write2148clean($tmp.'/'.$symlinkDir.'/payload/a.txt','x');
@symlink($tmp.'/TrophyEngraver/keep.txt',$tmp.'/'.$symlinkDir.'/payload/link');

// Five old release backups: newest three are retained, oldest two may be candidates.
$backupBase=$tmp.'/storage/release-backups';
mkdir2148clean($backupBase);
$old=time()-10*86400;
for($i=1;$i<=5;$i++){
    $name='preview-infra-v2.14.'.$i.'-20260901T000000Z-'.$i;
    write2148clean($backupBase.'/'.$name.'/marker.txt',(string)$i);
    touch($backupBase.'/'.$name,$old+$i);
    touch($backupBase.'/'.$name.'/marker.txt',$old+$i);
}

// One stale staging leftover and one fresh staging leftover.
write2148clean($control.'/uploads/.incoming-old.zip','old');
touch($control.'/uploads/.incoming-old.zip',time()-2*86400);
write2148clean($control.'/uploads/.incoming-fresh.zip','fresh');

$manager=new FilesystemCleanupManager($tmp,$runtime);
$inventory=$manager->inventory();
$rows=[];
foreach($inventory['candidates'] as $row) $rows[$row['relative_path']]=$row;

if(isset($rows[$protected])) fail2148clean('physical baseline path became cleanup candidate');
if(!isset($rows[$zip]) || empty($rows[$zip]['cleanup_ready'])) fail2148clean('installer ZIP not cleanup-ready');
if(!isset($rows[$dir]) || empty($rows[$dir]['cleanup_ready'])) fail2148clean('installer extraction not cleanup-ready');
if(isset($rows['TrophyEngraver'])) fail2148clean('unrelated project was considered cleanup candidate');
if(!isset($rows[$symlinkDir]) || !empty($rows[$symlinkDir]['cleanup_ready'])) fail2148clean('nested symlink candidate was not blocked');
if(empty($rows[$symlinkDir]['stats']['scan_errors'])) fail2148clean('nested symlink scan error missing');
if(!isset($rows['data/runtime-v280/release-control/uploads/.incoming-old.zip'])) fail2148clean('stale staging file missing');
if(isset($rows['data/runtime-v280/release-control/uploads/.incoming-fresh.zip'])) fail2148clean('fresh staging file should be retained');

$backupCandidates=array_filter($rows,fn($r)=>($r['category']??'')==='release backup');
if(count($backupCandidates)!==2) fail2148clean('expected exactly two old backup cleanup candidates, got '.count($backupCandidates));

$result=$manager->delete($zip,'ximoon');
if(empty($result['ok']) || file_exists($tmp.'/'.$zip)) fail2148clean('installer ZIP deletion failed');
if(!is_file($tmp.'/TrophyEngraver/keep.txt')) fail2148clean('unrelated project was altered');

$rejected=false;
try{$manager->delete('../escape','ximoon');}catch(InvalidArgumentException){$rejected=true;}
if(!$rejected) fail2148clean('path traversal was accepted');

$rejected=false;
try{$manager->delete($symlinkDir,'ximoon');}catch(RuntimeException){$rejected=true;}
if(!$rejected || !is_dir($tmp.'/'.$symlinkDir)) fail2148clean('blocked symlink candidate deletion was not refused');

rrmdir2148clean($tmp);
echo "v2.14.8 filesystem cleanup harness passed\n";
