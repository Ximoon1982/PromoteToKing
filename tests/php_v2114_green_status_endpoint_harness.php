<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$temporary=sys_get_temp_dir().'/p2k-v2114-green-status-'.bin2hex(random_bytes(6));
if(!mkdir($temporary.'/public',0700,true)||!mkdir($temporary.'/src',0700,true))exit(1);
register_shutdown_function(static function()use($temporary):void{
    foreach(glob($temporary.'/public/*')?:[] as $path)@unlink($path);
    foreach(glob($temporary.'/src/*')?:[] as $path)@unlink($path);
    @rmdir($temporary.'/public');@rmdir($temporary.'/src');@rmdir($temporary);
});
if(!copy($root.'/server/team-points-green/public/api.php',$temporary.'/public/api.php'))exit(2);
if(!copy(__DIR__.'/fixtures/green_status_endpoint_bootstrap.php',$temporary.'/src/bootstrap.php'))exit(3);

$cases=[
    'completed'=>[
        'lane'=>['lane_key'=>'read_parity','status'=>'completed','completed_at'=>'2026-09-04 08:00:00','cursor_json'=>json_encode(['ready'=>true,'checks'=>['matches'=>true,'members'=>true]])],
        'ready'=>true,'state'=>'complete','lane_status'=>'completed','mismatches'=>[],'last_error'=>null,
    ],
    'pending'=>[
        'lane'=>['lane_key'=>'read_parity','status'=>'running','updated_at'=>'2026-09-04 08:01:00','cursor_json'=>json_encode(['ready'=>true,'checks'=>['matches'=>true]])],
        'ready'=>false,'state'=>'pending','lane_status'=>'running','mismatches'=>[],'last_error'=>null,
    ],
    'failed'=>[
        'lane'=>['lane_key'=>'read_parity','status'=>'error','updated_at'=>'2026-09-04 08:02:00','last_error'=>'fixture parity failed','cursor_json'=>json_encode(['ready'=>false,'checks'=>['matches'=>false]])],
        'ready'=>false,'state'=>'failed','lane_status'=>'error','mismatches'=>['matches'],'last_error'=>'fixture parity failed',
    ],
    'mismatch'=>[
        'lane'=>['lane_key'=>'read_parity','status'=>'completed','completed_at'=>'2026-09-04 08:03:00','cursor_json'=>json_encode(['ready'=>true,'checks'=>['matches'=>true,'member_summary'=>false,'club_totals'=>false]])],
        'ready'=>true,'state'=>'complete','lane_status'=>'completed','mismatches'=>['member_summary','club_totals'],'last_error'=>null,
    ],
];

foreach($cases as $label=>$case){
    $environment=array_merge($_ENV,['P2K_PARITY_FIXTURE'=>json_encode($case['lane'],JSON_THROW_ON_ERROR)]);
    $pipes=[];$process=proc_open([PHP_BINARY,'-d','display_errors=1','-d','error_reporting=-1',$temporary.'/public/api.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,$root,$environment);
    if(!is_resource($process))exit(10);
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    if($code!==0||trim($stderr)!=='')throw new RuntimeException($label.' endpoint emitted an error: '.$stderr);
    $payload=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);$parity=$payload['compatibility']['parity']??null;
    if(!is_array($parity))throw new RuntimeException($label.' response omitted compatibility.parity');
    foreach(['ready','state','lane_status','mismatches','last_error'] as $key)if(!array_key_exists($key,$parity)||$parity[$key]!==$case[$key])throw new RuntimeException($label.' parity '.$key.' mismatch: '.json_encode($parity));
    if(($payload['green_public_adapter_ready']??null)!==$case['ready'])throw new RuntimeException($label.' adapter readiness mismatch');
}

echo "v2.11.4 Green status endpoint parity runtime passed.\n";
