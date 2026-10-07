<?php
declare(strict_types=1);

$src = dirname(__DIR__) . '/server/release-control/src';
foreach ([
    'ReleaseSlotPolicy.php','ReleaseSlotFilesystemProbe.php','ReleaseSlotStore.php','ReleaseSlotMaterializer.php',
    'ReleaseCandidatePackage.php','ReleaseStateStore.php','ReleaseCandidateInstaller.php','ReleasePreviewTree.php',
    'ReleasePackageUploadInstaller.php'
] as $file) require_once $src . '/' . $file;

use P2K\ReleaseControl\ReleasePackageUploadInstaller;
use P2K\ReleaseControl\ReleaseSlotMaterializer;

function fail2148zip(string $m): never { throw new RuntimeException($m); }
function mkdir2148zip(string $p): void { if (!is_dir($p) && !mkdir($p,0777,true) && !is_dir($p)) fail2148zip('mkdir failed: '.$p); }
function write2148zip(string $p,string $v): void { mkdir2148zip(dirname($p)); file_put_contents($p,$v); }
function rrmdir2148zip(string $p): void {
    if (is_link($p)||is_file($p)){@unlink($p);return;}
    if(!is_dir($p))return;@chmod($p,0700);
    foreach(array_diff(scandir($p)?:[],['.','..']) as $e)rrmdir2148zip($p.'/'.$e);
    @rmdir($p);
}
function zip2148(string $source,string $zipPath,string $prefix='v2148-package'): void {
    $zip=new ZipArchive();
    if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)fail2148zip('zip create failed');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);
    foreach($it as $item){
        if(!$item->isFile()||$item->isLink())continue;
        $rel=str_replace('\\','/',substr($item->getPathname(),strlen($source)+1));
        if(!$zip->addFile($item->getPathname(),$prefix.'/'.$rel))fail2148zip('zip add failed: '.$rel);
    }
    $zip->close();
}

if(!class_exists('ZipArchive'))fail2148zip('ZipArchive unavailable in test runtime');

$repo=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/p2k-v2148-zip-'.bin2hex(random_bytes(5));
$root=$tmp.'/root';$runtime=$root.'/data/runtime-v280';$pkg=$tmp.'/pkg';
mkdir2148zip($root.'/assets');mkdir2148zip($runtime);mkdir2148zip($pkg.'/payload');

$baseHead=str_repeat('a',40);$targetHead=str_repeat('b',40);
$baseKey='p2k-2.14.7-'.substr($baseHead,0,12).'-0123456789abcdef';
$targetKey='p2k-2.14.8-'.substr($targetHead,0,12).'-fedcba9876543210';
write2148zip($root.'/VERSION',"2.14.7\n");
write2148zip($root.'/ui-v2.html','<script src="x.js?v='.$baseKey.'"></script>');
write2148zip($root.'/index.html',"public-unchanged\n");
write2148zip($root.'/assets/app.js',"base-asset\n");

$base=(new ReleaseSlotMaterializer($root,$runtime))->materialize(
    ['VERSION','ui-v2.html','index.html','assets/app.js'],'2.14.7',$baseHead,$baseKey
);
if(($base['integrity_status']??'')!=='valid')fail2148zip('base slot invalid');
$statePath=$runtime.'/release-control/state.json';
write2148zip($statePath,json_encode([
    'schema_version'=>1,'mode'=>'slots','public_release'=>$base['release_id'],
    'public_version'=>'2.14.7','public_source_head'=>$baseHead,'public_cache_key'=>$baseKey,
    'previous_public_release'=>null,'candidate_release'=>null,'transition_sequence'=>1,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");

write2148zip($pkg.'/payload/VERSION',"2.14.8\n");
write2148zip($pkg.'/payload/ui-v2.html','<script src="x.js?v='.$targetKey.'"></script>');
write2148zip($pkg.'/payload/index.html',"candidate-v2148\n");
$candidatePaths=['VERSION','index.html','ui-v2.html'];
$manifest='';
foreach($candidatePaths as $path)$manifest.=hash_file('sha256',$pkg.'/payload/'.$path)."  ./".$path."\n";
write2148zip($pkg.'/CANDIDATE_PAYLOAD.sha256',$manifest);
write2148zip($pkg.'/CANDIDATE_REMOVED_PATHS.txt','');
$manifestSha=hash_file('sha256',$pkg.'/CANDIDATE_PAYLOAD.sha256');
write2148zip($pkg.'/CANDIDATE_RELEASE.json',json_encode([
    'schema_version'=>1,'version'=>'2.14.8','source_head'=>$targetHead,'cache_key'=>$targetKey,
    'build_id'=>'v2148-zip-test','qualification_workflow'=>'P2K v2.14.8 qualification',
    'payload_manifest_sha256'=>$manifestSha,
    'accepted_public_builds'=>[['version'=>'2.14.7','source_head'=>$baseHead]],
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");

$recoveryFiles=[
    '.htaccess','ReleaseControl.php','PreviewRouter.php','PublicRouter.php',
    'server/release-control/config/.htaccess','server/release-control/config/config.example.php',
    'server/release-control/public/preview-oauth-session.php',
    'server/release-control/src/bootstrap.php','server/release-control/src/ReleaseControlAuth.php',
    'server/release-control/src/ReleaseControlState.php','server/release-control/src/ReleaseVersionManager.php',
    'server/release-control/src/ReleasePackageUploadInstaller.php','server/release-control/src/FilesystemCleanupManager.php',
    'server/release-control/src/ReleaseSlotPolicy.php','server/release-control/src/ReleaseSlotFilesystemProbe.php',
    'server/release-control/src/ReleaseSlotStore.php','server/release-control/src/ReleaseSlotMaterializer.php',
    'server/release-control/src/ReleaseCandidatePackage.php','server/release-control/src/ReleaseStateStore.php',
    'server/release-control/src/ReleaseRuntimeTree.php','server/release-control/src/ReleaseDeploymentManager.php',
    'server/release-control/src/ReleaseCandidateInstaller.php','server/release-control/src/ReleasePreviewTree.php',
    'server/release-control/src/ReleasePreviewSession.php',
    'server/release-control/tools/materialize-current-slot.php','server/release-control/tools/install-candidate.php',
    'server/release-control/tools/prepare-preview.php','server/release-control/tools/verify-slot.php',
    'server/release-control/tools/release-slot-paths.py','server/release-control/README.md',
];
$recoveryManifest='';
foreach($recoveryFiles as $path){
    $source=$repo.'/'.$path;
    if(!is_file($source))fail2148zip('missing test recovery source: '.$path);
    $dest=$pkg.'/payload/'.$path;mkdir2148zip(dirname($dest));copy($source,$dest);
    $recoveryManifest.=hash_file('sha256',$dest)."  ./".$path."\n";
}
write2148zip($pkg.'/RECOVERY_PLANE.sha256',$recoveryManifest);

$zipPath=$tmp.'/release.zip';zip2148($pkg,$zipPath);
$beforeVersion=hash_file('sha256',$root.'/VERSION');
$beforeUi=hash_file('sha256',$root.'/ui-v2.html');
$installer=new ReleasePackageUploadInstaller($root,$runtime);
$result=$installer->installZipPath($zipPath,'ximoon','release.zip');
if(empty($result['ok'])||($result['release_id']??'')!=='2.14.8-'.substr($targetHead,0,12))fail2148zip('browser package candidate install failed');
if(!empty($result['public_changed']))fail2148zip('browser package install claims public changed');
if(hash_file('sha256',$root.'/VERSION')!==$beforeVersion||hash_file('sha256',$root.'/ui-v2.html')!==$beforeUi)fail2148zip('browser package install changed direct-root public identity');
$state=json_decode((string)file_get_contents($statePath),true);
if(($state['public_release']??'')!==($base['release_id']??''))fail2148zip('public release pointer changed');
if(($state['candidate_release']??'')!==$result['release_id'])fail2148zip('candidate release not registered');
if(!is_dir($runtime.'/release-control/previews/'.$result['release_id'].'/app'))fail2148zip('candidate preview tree not prepared');
if(!is_file($root.'/server/release-control/src/ReleasePackageUploadInstaller.php'))fail2148zip('recovery plane not activated');
if(!is_dir((string)($result['recovery_backup']??'')))fail2148zip('recovery backup missing');

// Traversal archive is rejected before extraction.
$badZip=$tmp.'/bad.zip';$z=new ZipArchive();$z->open($badZip,ZipArchive::CREATE|ZipArchive::OVERWRITE);$z->addFromString('../escape.txt','bad');$z->close();
$rejected=false;
try{$installer->installZipPath($badZip,'ximoon','bad.zip');}catch(RuntimeException $e){$rejected=str_contains(strtolower($e->getMessage()),'unsafe');}
if(!$rejected)fail2148zip('traversal ZIP was accepted');
if(file_exists($tmp.'/escape.txt'))fail2148zip('traversal ZIP escaped staging');

// Recovery manifest tampering is rejected.
$tampered=$tmp.'/tampered';mkdir2148zip($tampered);
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pkg,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $item){
    $rel=substr($item->getPathname(),strlen($pkg)+1);$dst=$tampered.'/'.$rel;
    if($item->isDir())mkdir2148zip($dst);else{mkdir2148zip(dirname($dst));copy($item->getPathname(),$dst);}
}
file_put_contents($tampered.'/payload/ReleaseControl.php',"<?php echo 'tampered';\n");
$tamperedZip=$tmp.'/tampered.zip';zip2148($tampered,$tamperedZip);
$rejected=false;
try{$installer->installZipPath($tamperedZip,'ximoon','tampered.zip');}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'Recovery-plane payload hash mismatch');}
if(!$rejected)fail2148zip('tampered recovery plane was accepted');

rrmdir2148zip($tmp);
echo "v2.14.8 release ZIP upload harness passed\n";
