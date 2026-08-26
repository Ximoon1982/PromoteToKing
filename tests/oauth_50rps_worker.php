<?php
declare(strict_types=1);
$root=dirname(__DIR__); require $root.'/server/team-points/src/bootstrap.php';
use P2K\TeamPoints\OAuthSession;
if($argc<5){fwrite(STDERR,"usage: worker base page count kind [token]\n");exit(2);} 
$base=rtrim($argv[1],'/');$page=$argv[2];$count=(int)$argv[3];$kind=$argv[4];$token=$argv[5]??'shared-fake-oauth-token';
$ref=new ReflectionClass(OAuthSession::class);$method=$ref->getMethod('multiGet');$method->setAccessible(true);
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
$started=microtime(true);$rows=[];$statusCounts=[];$rates=[];$safe=[];$unsafe=[];$launchCps=[];$done=0;
while($done<$count){$n=min(32,$count-$done);$requests=[];for($j=0;$j<$n;$j++){$i=$done+$j;$requests[]=['id'=>$page.'-'.$i,'url'=>urlFor($base,$kind,$i,$page),'headers'=>[]];}
  $batch=$method->invoke(null,$requests,$token,32,120.0,'foreground');
  foreach($batch['results'] as $r){$s=(int)$r['status'];$statusCounts[$s]=($statusCounts[$s]??0)+1;}
  $rates[]=(float)($batch['controller']['rate_target_cps']??$batch['rate_cps']??0);$safe[]=(float)($batch['controller']['safe_rate_cps']??0);$unsafe[]=(float)($batch['controller']['unsafe_rate_cps']??0);$launchCps[]=(float)($batch['launch_cps']??0);
  $rows[]=['n'=>$n,'elapsed_ms'=>$batch['elapsed_ms'],'rate'=>$rates[array_key_last($rates)],'safe'=>$safe[array_key_last($safe)],'unsafe'=>$unsafe[array_key_last($unsafe)],'launch_cps'=>$launchCps[array_key_last($launchCps)],'429'=>$batch['rate_429']];
  $done+=$n;
}
$out=['page'=>$page,'kind'=>$kind,'count'=>$count,'elapsed_s'=>microtime(true)-$started,'statuses'=>$statusCounts,'final_rate'=>end($rates)?:0,'final_safe'=>end($safe)?:0,'final_unsafe'=>end($unsafe)?:0,'median_launch_cps'=>$launchCps?($launchCps[(int)floor(count($launchCps)/2)]??0):0,'batches'=>$rows];
echo json_encode($out,JSON_UNESCAPED_SLASHES)."\n";
