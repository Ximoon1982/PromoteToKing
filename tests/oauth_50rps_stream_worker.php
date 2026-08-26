<?php
declare(strict_types=1);
$root=dirname(__DIR__); require $root.'/server/team-points/src/bootstrap.php';
use P2K\TeamPoints\OAuthRateCoordinator;
if($argc<5){fwrite(STDERR,"usage: worker base page count kind [token]\n");exit(2);} 
$base=rtrim($argv[1],'/');$page=$argv[2];$count=(int)$argv[3];$kind=$argv[4];$token=$argv[5]??'shared-fake-oauth-token';
$coordinator=new OAuthRateCoordinator($token);
function urlFor(string $base,string $kind,int $i,string $page):string{
  $q='?page='.rawurlencode($page);if($i%17===0)$q.='&permanent404=1';
  return match($kind){
    'match'=>"$base/pub/match/".(100000+$i).$q,
    'club-index'=>"$base/pub/club/promote-to-king/matches$q",
    'roster'=>"$base/pub/club/promote-to-king/members$q",
    'club-profile'=>"$base/pub/club/club-$i$q",
    'player-stats'=>"$base/pub/player/player-$i/stats$q",
    'player-matches'=>"$base/pub/player/player-$i/matches$q",
    'archive'=>"$base/pub/player/player-$i/games/2026/07$q",
    default=>"$base/pub/player/player-$i$q",
  };
}
function statusFromHeaders(array $headers):int{foreach($headers as $h)if(preg_match('~^HTTP/\S+\s+(\d{3})~',$h,$m))return (int)$m[1];return 0;}
$started=microtime(true);$statusCounts=[];$batchStatuses=[];$batchLatencies=[];$batchRates=[];$feedbackRows=[];$rates=[];
for($i=0;$i<$count;$i++){
  $slot=$coordinator->waitForLaunch('foreground');$batchRates[]=(float)($slot['rate_target_cps']??0);
  $url=urlFor($base,$kind,$i,$page);$t=microtime(true);$ctx=stream_context_create(['http'=>['timeout'=>5,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nConnection: close\r\n"]]);$body=@file_get_contents($url,false,$ctx);$elapsed=(microtime(true)-$t)*1000;$status=statusFromHeaders($http_response_header??[]);
  $statusCounts[$status]=($statusCounts[$status]??0)+1;
  if($status===429){$attempt=(float)($slot['rate_target_cps']??8.0);$fb=$coordinator->feedback([429],[$elapsed],$kind==='match'?'match-detail':$kind,$attempt,1);$rates[]=(float)$fb['rate_target_cps'];$feedbackRows[]=$fb;$batchStatuses=[];$batchLatencies=[];$batchRates=[];}
  else{$batchStatuses[]=$status;$batchLatencies[]=$elapsed;if(count($batchStatuses)>=8||$i===$count-1){sort($batchRates,SORT_NUMERIC);$attempt=$batchRates[(int)floor((count($batchRates)-1)*.5)]??8.0;$fb=$coordinator->feedback($batchStatuses,$batchLatencies,$kind==='match'?'match-detail':$kind,$attempt,0);$rates[]=(float)$fb['rate_target_cps'];$feedbackRows[]=$fb;$batchStatuses=[];$batchLatencies=[];$batchRates=[];}}
}
$out=['page'=>$page,'kind'=>$kind,'count'=>$count,'elapsed_s'=>microtime(true)-$started,'statuses'=>$statusCounts,'final_rate'=>$rates?end($rates):0,'controller'=>$coordinator->snapshot(),'feedback'=>$feedbackRows];
echo json_encode($out,JSON_UNESCAPED_SLASHES)."\n";
