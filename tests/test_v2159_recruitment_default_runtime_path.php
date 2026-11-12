<?php
declare(strict_types=1);

// Run the real production store from a temporary replica of the P2K tree.
// Avoid creating any history under the real web checkout during this test.
$source=dirname(__DIR__).'/server/team-points/src/RecruitmentHistoryStore.php';
$root=sys_get_temp_dir().'/p2k-history-runtime-path-'.bin2hex(random_bytes(6));
$src=$root.'/server/team-points/src';
if(!mkdir($src,0700,true)) throw new RuntimeException('Cannot create test fixture');
try{
    if(!copy($source,$src.'/RecruitmentHistoryStore.php')) throw new RuntimeException('Cannot copy source into fixture');
    define('P2K_TP_ROOT',$src.'/..'); // exact bootstrap spelling (unresolved /src/..)
    require $src.'/RecruitmentHistoryStore.php';

    $expected=$root.'/data/runtime-v280/recruitment-history';
    $incorrect=$root.'/server/team-points/data/runtime-v280/recruitment-history';
    $store=\P2K\TeamPoints\RecruitmentHistoryStore::fromConfig(['storage'=>['runtime_dir'=>'']]);
    if(!is_dir($expected)) throw new RuntimeException('Default history path is not under shared site data');
    if(is_dir($incorrect)) throw new RuntimeException('Legacy incorrect path was created');
    $record=$store->archive(2019306,'Match P2K',"username,rating\nalpha,1400\n");
    if(!is_file($store->pathFor($record))) throw new RuntimeException('Default archive CSV was not saved');
    if($store->page(1)['total']!==1) throw new RuntimeException('Default archive history is not readable');

    $override=$root.'/explicit-runtime';
    $configured=\P2K\TeamPoints\RecruitmentHistoryStore::fromConfig(['storage'=>['runtime_dir'=>$override]]);
    if(!is_dir($override.'/recruitment-history') || $configured->page(1)['total']!==0){
        throw new RuntimeException('Explicit configured runtime directory not respected');
    }
    $e=new \P2K\TeamPoints\RecruitmentHistoryException('RECRUITMENT_DIRECTORY_UNAVAILABLE','Test safe message');
    if($e->getMessage()!=='Test safe message')throw new RuntimeException('Safe message missing');
    $endpoint=file_get_contents(dirname(__DIR__).'/server/team-points/public/recruitment-history.php');
    if(!is_string($endpoint)||!str_contains($endpoint,"'message'=>\$e->getMessage()")){
        throw new RuntimeException('HTTP error response does not surface the safe message');
    }
    echo "PASS correct default recruitment history path, save/list, config override, safe 503 message\n";
}finally{
    $cleanup=static function(string $path)use(&$cleanup):void{
        if(!file_exists($path)&&!is_link($path))return;
        if(is_dir($path)&&!is_link($path)){
            foreach(scandir($path)?:[] as $entry)if($entry!=='.'&&$entry!=='..')$cleanup($path.'/'.$entry);
            @rmdir($path);
        }else @unlink($path);
    };
    $cleanup($root);
}
