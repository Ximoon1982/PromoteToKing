<?php
declare(strict_types=1);
// Test the standalone bridge against synthetic release metadata and distinct
// public/physical trees. No hosting configuration, OAuth credentials or API traffic.
$helper=realpath(__DIR__.'/../TeamFrance/MatchRecruitment/compat/ActiveOAuthRuntime.php');
if($helper===false)throw new RuntimeException('Missing runtime bridge.');
foreach(['TGP/MatchRecruitment','TeamFrance/MatchAnalyzer'] as $app){
    if(hash_file('sha256',__DIR__.'/../'.$app.'/compat/ActiveOAuthRuntime.php')!==hash_file('sha256',$helper))throw new RuntimeException('Standalone runtime bridges differ.');
}
if(($argv[1]??'')==='child'){
    $root=$argv[2];$case=$argv[3];require $helper;
    if($case==='preview')$_COOKIE['P2KRC_PREVIEW']='fixture';
    try{recruitment_active_oauth_runtime($root);
        if(!in_array($case,['slots','direct'],true))throw new LogicException('Expected fail closed.');
        if(getenv('P2K_TP_CONFIG')!==$root.'/server/team-points/config/config.local.php')throw new LogicException('Wrong host config.');
        if(CORE_FIXTURE!==($case==='slots'?'selected-slot':'physical-root'))throw new LogicException('Wrong core selected.');
        echo "PASS $case\n";
    }catch(RuntimeException $e){if(in_array($case,['slots','direct'],true))throw $e;echo "PASS $case rejected\n";}
    exit;
}
$root=sys_get_temp_dir().'/p2k-inc3-'.bin2hex(random_bytes(6));
function put(string $path,string $s):void{if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,$s);}
function clean(string $root):void{if(!is_dir($root))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);}
try{
    put($root.'/server/release-control/src/bootstrap.php',<<<'PHPFIX'
<?php
namespace P2K\ReleaseControl;
final class ReleaseStateStore {
    public function __construct(private string $root){}
    public function read():array{return json_decode(file_get_contents($this->root.'/state.json'),true,512,JSON_THROW_ON_ERROR);}
}
final class ReleaseRuntimeTree {
    public function __construct(private string $root){}
    public function describeExisting(string $id,string $hash):?array{return $id==='active'&&$hash==='valid'?['valid'=>true,'app_root'=>$this->root.'/selected']:null;}
}
PHPFIX);
    put($root.'/server/team-points/config/config.local.php','<?php return [];');
    foreach([''=>'physical-root','/selected'=>'selected-slot'] as $suffix=>$label){
        put($root.$suffix.'/server/team-points/src/bootstrap.php','<?php namespace {define("CORE_FIXTURE",'.var_export($label,true).');define("P2K_TP_ROOT",__DIR__."/..");} namespace P2K\\TeamPoints {final class OAuthRateCoordinator {public static function policy():array{return ["maximum_rate_cps"=>30];}}}');
    }
    foreach(['slots'=>['mode'=>'slots','public_release'=>'active','public_manifest_sha256'=>'valid'],'direct'=>['mode'=>'direct-root'],'invalid'=>['mode'=>'slots','public_release'=>'active','public_manifest_sha256'=>'wrong'],'preview'=>['mode'=>'slots','public_release'=>'active','public_manifest_sha256'=>'valid']] as $case=>$state){
        put($root.'/state.json',json_encode($state,JSON_THROW_ON_ERROR));$cmd=[PHP_BINARY,__FILE__,'child',$root,$case];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||!str_contains($out,"PASS $case"))throw new RuntimeException($case.': '.$out.$err);echo $out;
    }
}finally{clean($root);}
