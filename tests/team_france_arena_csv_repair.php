<?php
declare(strict_types=1);
require __DIR__.'/../TeamFrance/Leaderboard/src/Refresh.php';
use TeamFrance\Leaderboard\Store;
use TeamFrance\Leaderboard\Refresh;
use function TeamFrance\Leaderboard\arenaResult;
function verify(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function reject(callable $f,string $m):void{try{$f();}catch(RuntimeException){return;}throw new RuntimeException($m);}
$header="Rank,Federation,Title,Username,Name,Club,Score,Longest streak,Most wins,Status\n";
$clubs="\nClub,Total Participants,Points,Wins,Losses,Longest Streaks\nTeam France,2,12,4,1,2,\nOther Club,1,8,2,0,2,\n";
$first="1,FRA,,Alice,Alice Example,Team France,9,2,3,registered,\n";
$broken="2,FRA,,Bob,First name\nSecond name,Team France,3,1,1,registered,\n";
$other="3,MEX,,Other,Example,Other Club,8,2,2,registered,\n";
$x=arenaResult($header.$first.$broken.$other.$clubs,true);
verify($x['evidence']['repaired_player_rows']===1&&$x['evidence']['player_rows']===3&&$x['rows']['bob']['points']===300&&$x['rows']['alice']['points']===900,'Recover actual split Name without losing points');
$quoted=str_replace("First name\nSecond name",'"First name'."\n".'Second name"',$broken);
$q=arenaResult($header.$first.$quoted.$other.$clubs,true);
verify($q['evidence']['repaired_player_rows']===0&&$q['rows']===$x['rows'],'Valid quoted multiline remains intact');
foreach(["2,FRA,,Bob,First name\n","2,FRA,,Bob,First name\nSecond name,Team France,\n","2,FRA,,Bob,First name\nSecond name,Team France,bad,1,1,registered,\n","2,FRA,,Bob\n"] as $bad)reject(fn()=>arenaResult($header.$first.$bad.$other.$clubs,true),'Unrecoverable incomplete record accepted');
reject(fn()=>arenaResult($header.$first."2,FRA,,Bob,First name",true),'Truncated final record accepted');
if(in_array('--live',$argv,true)){
 $url='https://www.chess.com/tournament/live/arena/international-premier-league-2026-q3w4-rapid--31337321';
 $s=new Store(sys_get_temp_dir().'/tf-arena-name-repair-'.bin2hex(random_bytes(6)));$s->saveSeason(['kind'=>'arena','name'=>'2026']);$s->assign(['season_id'=>1,'references'=>$url]);
 $refresh=new Refresh($s);$refresh->arena($s->one('SELECT * FROM events'));
 $e=$s->one('SELECT * FROM events');$evidence=json_decode($e['evidence'],true);$points=$s->run('SELECT SUM(points)/100.0 FROM results')->fetchColumn();
 verify($e['status']==='finished'&&$evidence['player_rows']===691&&$evidence['official_club_rows']===75&&$evidence['club_rows']===18&&$points==227,'Actual arena 31337321 expected complete totals');
 verify(hash_file('sha256',$s->dir.'/sources/'.$e['source_hash'])===$e['source_hash'],'Original raw CSV preserved');
 echo 'Actual arena 31337321: '.json_encode($evidence+['points'=>$points,'status'=>$e['status'],'sha256'=>$e['source_hash']],JSON_UNESCAPED_SLASHES)."\n";
}
echo "PASS: verified split-name recovery, valid quoted multiline, rejection of real truncation and actual arena scoring.\n";
