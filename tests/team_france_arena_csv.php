<?php
declare(strict_types=1);
require __DIR__.'/../TeamFrance/Leaderboard/src/Refresh.php';
use TeamFrance\Leaderboard\Store;
use TeamFrance\Leaderboard\Refresh;
use TeamFrance\Leaderboard\Transport;
use function TeamFrance\Leaderboard\arenaResult;
function verify(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function reject(callable $f,string $m):void{try{$f();}catch(Throwable){return;}throw new RuntimeException($m);}
$url='https://www.chess.com/tournament/live/arena/2026-5th-edition-of-chessaroundtheearth-31107518';
$players="Rank,Federation,Title,Username,Name,Club,Score,Longest streak,Most wins\n1,FRA,,Alice,,Team France,12,2,4\n2,FRA,,Bob,,Other Club,20,3,5\n3,FRA,,Carol,,Team France,0,0,0\n";
$clubs="Club,Total Participants,Points\nTeam France,2,12\nOther Club,1,20\n";
$csv="Player Results\n".$players."\nClub Results\n".$clubs;
foreach([$csv,"Club Results\n".$clubs."\nPlayer Results\n".$players] as $body){$x=arenaResult($body,true);verify($x['evidence']['official_club_rows']===2&&$x['rows']['alice']['points']===1200&&isset($x['rows']['carol'])&&!isset($x['rows']['bob']),'Both official table orders');}
reject(fn()=>arenaResult($players,true),'Player-only source accepted as official');
reject(fn()=>arenaResult('<html>not finished</html>',true),'HTML accepted');
$s=new Store(sys_get_temp_dir().'/tf-arena-csv-'.bin2hex(random_bytes(6)));
$s->saveSeason(['kind'=>'arena','name'=>'2026']);$s->assign(['season_id'=>1,'references'=>$url]);
$e=$s->one('SELECT * FROM events');$calls=[];
$r=new Refresh($s,function($u,$timeout)use(&$calls,$url,$csv){$calls[]=$u;return ['url'=>$u,'body'=>$u===$url?'<html><title>Finished arena without anchor</title></html>':$csv];});
$r->arena($e);verify($calls===[$url,$url.'/download-results'],'No-anchor fallback');verify($s->leaderboard(1)[0]['points']==12,'Official Score saved');
$e=$s->one('SELECT * FROM events');verify($e['status']==='finished'&&$e['error']===''&&is_file($s->dir.'/sources/'.$e['source_hash']),'Raw CSV persisted');
$bad=new Refresh($s,fn($u,$t)=>['url'=>$u,'body'=>$u===$url?'<html>arena</html>':'<html>error</html>']);
reject(fn()=>$bad->arena($e),'Bad CSV accepted');verify($s->leaderboard(1)[0]['points']==12,'Prior points preserved');
$wrong=new Refresh($s,fn($u,$t)=>['url'=>$u===$url?$url:'https://www.chess.com/tournament/live/arena/wrong-31107519/download-results','body'=>$csv]);
reject(fn()=>$wrong->arena($e),'Wrong arena accepted');
$pending=new Refresh($s,fn($u,$t)=>['url'=>$url,'body'=>'<div data-tournament-status="in_progress"></div>']);
reject(fn()=>$pending->arena($e),'Finished status regression accepted');
if(in_array('--live',$argv,true)){
 $s2=new Store(sys_get_temp_dir().'/tf-arena-csv-live-'.bin2hex(random_bytes(6)));$s2->saveSeason(['kind'=>'arena','name'=>'Live diagnostic']);$s2->assign(['season_id'=>1,'references'=>$url]);
 $page=Transport::get($url,18);echo 'Original href detector: '.(preg_match('~href=["\x27]([^"\x27]+/download-results)(?:\?[^"\x27]*)?["\x27]~i',$page['body'])?'found':'missing')."\n";
 (new Refresh($s2))->arena($s2->one('SELECT * FROM events'));
 $ev=$s2->one('SELECT * FROM events');$evidence=json_decode($ev['evidence'],true);verify($ev['status']==='finished'&&$evidence['official_club_rows']>0&&$evidence['club_rows']>0,'Actual arena import');
 echo 'Actual arena 31107518: '.json_encode(['status'=>$ev['status'],'source'=>$evidence['source_url'],'player_rows'=>$evidence['player_rows'],'club_rows'=>$evidence['official_club_rows'],'team_france_rows'=>$evidence['club_rows'],'points'=>$s2->run('SELECT SUM(points)/100.0 FROM results')->fetchColumn(),'csv_sha256'=>$ev['source_hash']],JSON_UNESCAPED_SLASHES)."\n";
}
echo "PASS: canonical CSV fallback, two-table parsing, identity, non-final guard, persistence and previous results preservation.\n";
