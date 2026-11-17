<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/release-control/src/bootstrap.php';
use P2K\ReleaseControl\ReleaseVersionManager;
use P2K\ReleaseControl\FilesystemCleanupManager;
function ensure21510(string $path):void{if(!is_dir($path)&&!mkdir($path,0777,true)&&!is_dir($path))throw new RuntimeException('Cannot create '.$path);}
function put21510(string $path,string $value='x'):void{ensure21510(dirname($path));file_put_contents($path,$value);}
function remove21510(string $path):void{
    if(is_link($path)||is_file($path)){@unlink($path);return;}
    if(!is_dir($path))return;
    foreach(array_diff(scandir($path)?:[],['.','..']) as $n)remove21510($path.'/'.$n);
    @rmdir($path);
}
function must21510(bool $ok,string $description):void{if(!$ok)throw new RuntimeException('FAIL: '.$description);echo "PASS ".$description."\n";}
$tmp=sys_get_temp_dir().'/p2k-public-ops-batch-'.bin2hex(random_bytes(5));
$runtime=$tmp.'/data/runtime-v280';$control=$runtime.'/release-control';
ensure21510($control.'/releases');ensure21510($control.'/previews');ensure21510($control.'/runtime-trees');
put21510($tmp.'/VERSION',"2.15.10\n");
put21510($tmp.'/ui-v2.html','<meta name="p2k-build" content="p2k-2.15.10-cccccccccccc-1111111111111111">');
put21510($control.'/state.json',json_encode(['schema_version'=>1,'mode'=>'slots','public_release'=>'2.15.10-public','previous_public_release'=>'2.15.10-rollback','candidate_release'=>'2.15.10-candidate']));
foreach(['2.15.10-public','2.15.10-rollback','2.15.10-candidate','2.15.10-cccccccccccc','2.15.10-old-one','2.15.10-old-two'] as $id){
    put21510($control.'/releases/'.$id.'/app/record.txt',$id);
    put21510($control.'/releases/'.$id.'/meta/slot.txt','release');
}
try {
    $versions=new ReleaseVersionManager($tmp,$runtime);
    $ids=['2.15.10-old-one','2.15.10-old-two'];
    try{$versions->deleteReleases([$ids[0],'2.15.10-public'],'ximoon');throw new RuntimeException('protected batch unexpectedly accepted');}
    catch(RuntimeException $e){must21510(str_contains($e->getMessage(),'protected'),'protected public release rejects entire batch');}
    must21510(is_dir($control.'/releases/'.$ids[0]),'batch preflight kept unprotected first item');
    try{$versions->deleteReleases([$ids[0],'2.15.10-rollback'],'ximoon');throw new RuntimeException('rollback unexpectedly accepted');}
    catch(RuntimeException $e){must21510(str_contains($e->getMessage(),'protected'),'rollback target never selectable');}
    try{$versions->deleteReleases([$ids[0],'2.15.10-candidate'],'ximoon');throw new RuntimeException('candidate unexpectedly accepted');}
    catch(RuntimeException $e){must21510(str_contains($e->getMessage(),'protected'),'candidate remains protected');}
    try{$versions->deleteReleases([$ids[0],'2.15.10-cccccccccccc'],'ximoon');throw new RuntimeException('physical baseline unexpectedly accepted');}
    catch(RuntimeException $e){must21510(str_contains($e->getMessage(),'protected'),'physical recovery baseline stays protected');}
    $result=$versions->deleteReleases($ids,'ximoon');
    must21510(($result['deleted_count']??0)===2,'two old releases deleted in one batch');
    foreach($ids as $id)must21510(!is_dir($control.'/releases/'.$id),'deleted obsolete release '.$id);
    try{$versions->deleteReleases([$ids[0],$ids[0]],'ximoon');throw new RuntimeException('duplicate selection accepted');}
    catch(InvalidArgumentException $e){must21510(true,'duplicate release selection refused');}
    $zip1='PromoteToKing_v2.14.1_INCREMENTAL_FROM_2.13.x.zip';
    $zip2='PromoteToKing_v2.14.2_INCREMENTAL_FROM_2.13.x.zip';
    $baseline='PromoteToKing_v2.14.3_INCREMENTAL_FROM_2.13.x.zip';
    put21510($tmp.'/'.$zip1,'old1');put21510($tmp.'/'.$zip2,'old2');
    put21510($control.'/releases/2.15.10-cccccccccccc/meta/manifest.tsv',str_repeat('f',64)."\t1\t1\t".$baseline."\n");
    put21510($tmp.'/'.$baseline,'protected');
    $fs=new FilesystemCleanupManager($tmp,$runtime);
    try{$fs->deleteMany([$zip1,$baseline],'ximoon');throw new RuntimeException('protected filesystem batch accepted');}
    catch(RuntimeException $e){must21510(str_contains($e->getMessage(),'safe cleanup'),'recovery-baseline file blocks batch');}
    must21510(file_exists($tmp.'/'.$zip1),'failed preflight did not delete first safe file');
    $r=$fs->deleteMany([$zip1,$zip2],'ximoon');
    must21510(($r['deleted_count']??0)===2,'two maintenance ZIPs deleted in one batch');
    must21510(file_exists($tmp.'/'.$baseline),'protected baseline file survives batch');
    try{$fs->deleteMany(['../bad'],'ximoon');throw new RuntimeException('path traversal batch accepted');}
    catch(InvalidArgumentException $e){must21510(true,'batch path traversal rejected');}
    $fresh=$control.'/uploads/.incoming-now.zip';put21510($fresh,'new');
    try{$fs->deleteMany(['data/runtime-v280/release-control/uploads/.incoming-now.zip'],'ximoon');throw new RuntimeException('fresh staging deletion accepted');}
    catch(RuntimeException $e){must21510(file_exists($fresh),'recent staging remains protected');}
} finally {remove21510($tmp);}
echo "PASS batch release and filesystem safety suite\n";
