<?php
declare(strict_types=1);
require __DIR__.'/../TeamFrance/Leaderboard/src/Refresh.php';
use TeamFrance\Leaderboard\Transport;
use function TeamFrance\Leaderboard\arenaResult;
$url='https://www.chess.com/tournament/live/arena/international-premier-league-2026-q3w4-rapid--31337321/download-results';
$r=Transport::get($url,18);$csv=$r['body'];
echo "Effective URL: ".$r['url']."; bytes: ".strlen($csv)."; SHA256: ".hash('sha256',$csv)."\n";
try{$x=arenaResult($csv,true);echo "Existing parser success: ".json_encode($x['evidence'])."\n";}catch(Throwable $e){echo "Existing parser failed: ".$e->getMessage()."\n";}
$fp=fopen('php://temp','r+');fwrite($fp,$csv);rewind($fp);$i=0;$header=null;$widths=[];
while(($r=fgetcsv($fp,0,',','"',''))!==false){
 $i++;$h=array_map(fn($v)=>strtolower(trim((string)$v)),$r);$n=count($r);
 if(in_array('username',$h,true)&&in_array('score',$h,true)){$header=array_flip($h);echo "Player header record $i: ".json_encode($r)."\n";continue;}
 if(in_array('club',$h,true)&&(in_array('points',$h,true)||in_array('score',$h,true))&&!in_array('username',$h,true)){$header=null;echo "Club header record $i: ".json_encode($r)."\n";continue;}
 $widths[$n]=($widths[$n]??0)+1;
 if($header!==null&&$n<=max($header['username'],$header['club'],$header['score'])&&trim(implode('',array_map('strval',$r)))!=='')echo "Short record $i: ".json_encode($r,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE)."\n";
 if($i<=5||$n<=3)echo "Record $i ($n fields): ".json_encode($r,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE)."\n";
}
fclose($fp);echo "Widths: ".json_encode($widths)."\n";
file_put_contents('tf-arena-31337321.csv',$csv);
