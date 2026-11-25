<?php
declare(strict_types=1);
require __DIR__.'/../TeamFrance/Leaderboard/src/Refresh.php';
use TeamFrance\Leaderboard\Transport;
use function TeamFrance\Leaderboard\matchResult;
use function TeamFrance\Leaderboard\clubKey;
use function TeamFrance\Leaderboard\userKey;
use function TeamFrance\Leaderboard\rawPoints;
use function TeamFrance\Leaderboard\boardKey;
use function TeamFrance\Leaderboard\removalKeys;
$id='1809308';$p=Transport::json('https://api.chess.com/pub/match/'.$id);
file_put_contents('tf-match-1809308.json',json_encode($p,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(array_diff_key($p,['teams'=>true]),JSON_UNESCAPED_SLASHES)."\n";
foreach($p['teams'] as $t){
 $raw=0;$missing=0;$removed=removalKeys((array)($t['fair_play_removals']??[]));$boards=[];
 foreach($t['players'] as $v){$points=0;foreach(['played_as_white','played_as_black'] as $side){$n=rawPoints($v[$side]??$v['results'][$side]??'');if($n===null)$missing++;else $points+=$n;}$raw+=$points;$boards[boardKey($v)]=$v;}
 echo json_encode(['team'=>$t['@id'],'score'=>$t['score'],'players'=>count($t['players']),'raw_points'=>$raw/100,'missing'=>$missing,'removals'=>$removed],JSON_UNESCAPED_SLASHES)."\n";
}
try{$r=matchResult($p,$id);echo json_encode(['result'=>'pass','evidence'=>$r['evidence']['official_delta_x100']])."\n";}catch(Throwable $x){echo 'Parser: '.$x->getMessage()."\n";}
$teams=array_values($p['teams']);if(clubKey($teams[0]['@id'])!=='team-france')$teams=array_reverse($teams);
$a=removalKeys((array)($teams[0]['fair_play_removals']??[]));$b=removalKeys((array)($teams[1]['fair_play_removals']??[]));$op=[];foreach($teams[1]['players'] as $v)$op[boardKey($v)]=$v;
$total=0;
foreach($teams[0]['players'] as $v){$board=boardKey($v);$o=$op[$board]??[];$own=in_array(userKey($v['username']),$a,true);$opp=in_array(userKey($o['username']??''),$b,true);$raw=0;$effective=0;foreach(['played_as_white','played_as_black'] as $side){$n=rawPoints($v[$side]??$v['results'][$side]??'');if($n!==null){$raw+=$n;$effective+=$own?0:($opp?100:$n);}}$total+=$effective;
 if($own||$opp){echo json_encode(['board'=>$board,'ours'=>$v,'other'=>$o,'own_removed'=>$own,'other_removed'=>$opp,'raw'=>$raw/100,'effective'=>$effective/100],JSON_UNESCAPED_SLASHES)."\n";try{$bp=Transport::json('https://api.chess.com/pub/match/'.$id.'/'.$board);file_put_contents('tf-match-1809308-board-'.$board.'.json',json_encode($bp,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo "Board $board: ".json_encode($bp,JSON_UNESCAPED_SLASHES)."\n";}catch(Throwable $x){echo "Board $board: ".$x->getMessage()."\n";}sleep(1);}
}
echo "Reconstructed: ".($total/100)."; official: ".$teams[0]['score']."; delta: ".($teams[0]['score']-$total/100)."\n";
