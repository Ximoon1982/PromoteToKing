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
use P2K\ReleaseControl\ReleaseStateStore;

function fail_same(string $m): never { throw new RuntimeException($m); }
function rrmdir_same(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    @chmod($path, 0700);
    foreach (array_diff(scandir($path) ?: [], ['.','..']) as $entry) rrmdir_same($path . '/' . $entry);
    @rmdir($path);
}

$tmp = sys_get_temp_dir() . '/p2k-v2147-same-' . bin2hex(random_bytes(5));
$root = $tmp . '/root';
$runtime = $tmp . '/runtime';
$pkg = $tmp . '/candidate';
@mkdir($root . '/assets', 0700, true);
@mkdir($runtime, 0700, true);
@mkdir($pkg . '/payload/assets', 0700, true);

$baseHead = str_repeat('a', 40);
$targetHead = str_repeat('b', 40);
$baseKey = 'p2k-2.14.7-' . substr($baseHead, 0, 12) . '-0123456789abcdef';
$targetKey = 'p2k-2.14.7-' . substr($targetHead, 0, 12) . '-fedcba9876543210';

file_put_contents($root . '/VERSION', "2.14.7\n");
file_put_contents($root . '/ui-v2.html', '<script src="x.js?v=' . $baseKey . '"></script>');
file_put_contents($root . '/index.html', "old-public\n");
file_put_contents($root . '/assets/app.js', "unchanged\n");

$base = (new ReleaseSlotMaterializer($root, $runtime))->materialize(
    ['VERSION','ui-v2.html','index.html','assets/app.js'],
    '2.14.7',
    $baseHead,
    $baseKey
);
if (($base['integrity_status'] ?? '') !== 'valid') fail_same('base slot invalid');

$stateStore = new ReleaseStateStore($root, $runtime);
$statePath = $stateStore->path();
@mkdir(dirname($statePath), 0700, true);
file_put_contents($statePath, json_encode([
    'schema_version'=>1,
    'mode'=>'slots',
    'public_release'=>$base['release_id'],
    'public_version'=>'2.14.7',
    'public_source_head'=>$baseHead,
    'public_cache_key'=>$baseKey,
    'previous_public_release'=>null,
    'candidate_release'=>null,
    'transition_sequence'=>1,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

file_put_contents($pkg . '/payload/VERSION', "2.14.7\n");
file_put_contents($pkg . '/payload/ui-v2.html', '<script src="x.js?v=' . $targetKey . '"></script>');
file_put_contents($pkg . '/payload/index.html', "corrected-public\n");
$payloadPaths=['VERSION','index.html','ui-v2.html'];
$manifest='';
foreach($payloadPaths as $path) $manifest .= hash_file('sha256',$pkg.'/payload/'.$path)."  ./".$path."\n";
file_put_contents($pkg . '/CANDIDATE_PAYLOAD.sha256',$manifest);
file_put_contents($pkg . '/CANDIDATE_REMOVED_PATHS.txt','');
$manifestSha=hash_file('sha256',$pkg.'/CANDIDATE_PAYLOAD.sha256');
$meta=[
    'schema_version'=>1,
    'version'=>'2.14.7',
    'source_head'=>$targetHead,
    'cache_key'=>$targetKey,
    'build_id'=>'v2147-same-version-test',
    'qualification_workflow'=>'P2K v2.14.7 qualification',
    'payload_manifest_sha256'=>$manifestSha,
    'accepted_public_builds'=>[
        ['version'=>'2.14.7','source_head'=>$baseHead],
    ],
];
file_put_contents($pkg.'/CANDIDATE_RELEASE.json',json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

$installer = new ReleaseCandidateInstaller($root,$runtime);
$out=$installer->install($pkg,'ximoon',true);
$candidate=$out['candidate']??[];
if(($candidate['release_id']??'')!=='2.14.7-'.substr($targetHead,0,12)) fail_same('same-version candidate not installed');
if(($candidate['base_release_id']??'')!==($base['release_id']??'')) fail_same('same-version base identity lost');
if((string)file_get_contents($runtime.'/release-control/releases/'.$candidate['release_id'].'/app/index.html')!=="corrected-public\n") fail_same('same-version overlay missing');
$state=json_decode((string)file_get_contents($statePath),true);
if(($state['public_release']??'')!==($base['release_id']??'')) fail_same('same-version candidate changed public release');
if(($state['candidate_release']??'')!==($candidate['release_id']??'')) fail_same('same-version candidate not registered');

$meta['accepted_public_builds']=[['version'=>'2.14.7','source_head'=>str_repeat('c',40)]];
file_put_contents($pkg.'/CANDIDATE_RELEASE.json',json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$rejected=false;
try{$installer->install($pkg,'ximoon',true);}catch(RuntimeException$e){$rejected=str_contains($e->getMessage(),'does not accept current public build');}
if(!$rejected) fail_same('unknown same-version public build was accepted');

$meta['version']='2.14.6';
$meta['accepted_public_builds']=[['version'=>'2.14.7','source_head'=>$baseHead]];
file_put_contents($pkg.'/payload/VERSION',"2.14.6\n");
$oldKey='p2k-2.14.6-'.substr($targetHead,0,12).'-fedcba9876543210';
$meta['cache_key']=$oldKey;
file_put_contents($pkg.'/payload/ui-v2.html','<script src="x.js?v='.$oldKey.'"></script>');
$manifest='';
foreach($payloadPaths as $path) $manifest .= hash_file('sha256',$pkg.'/payload/'.$path)."  ./".$path."\n";
file_put_contents($pkg.'/CANDIDATE_PAYLOAD.sha256',$manifest);
$meta['payload_manifest_sha256']=hash_file('sha256',$pkg.'/CANDIDATE_PAYLOAD.sha256');
file_put_contents($pkg.'/CANDIDATE_RELEASE.json',json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$rejected=false;
try{$installer->install($pkg,'ximoon',true);}catch(RuntimeException$e){$rejected=str_contains($e->getMessage(),'must not be older');}
if(!$rejected) fail_same('older semantic version was accepted');

rrmdir_same($tmp);
echo "v2.14.7 same-version candidate replacement harness passed\n";
