<?php
declare(strict_types=1);
require __DIR__.'/../TeamFrance/Leaderboard/src/Refresh.php';
use TeamFrance\Leaderboard\Store;
use TeamFrance\Leaderboard\Refresh;
use TeamFrance\Leaderboard\Transport;
use function TeamFrance\Leaderboard\matchResult;
use function TeamFrance\Leaderboard\arenaResult;
use function TeamFrance\Leaderboard\eventRef;
use function TeamFrance\Leaderboard\rawPoints;
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function rejects(callable $f,string $message): void {try{$f();}catch(Throwable){return;}throw new RuntimeException($message);}
check(rawPoints('win')===100&&rawPoints('agreed')===50&&rawPoints('resigned')===0&&rawPoints('')===null,'Daily scoring');
check(eventRef('daily','https://www.chess.com/club/matches/team-france/20201111')[0]==='20201111','Daily URL');
check(eventRef('arena','https://www.chess.com/tournament/live/arena/french-arena-31195524')[0]==='31195524','Arena URL');
rejects(fn()=>eventRef('daily','https://www.chess.com/club/matches/live/31195524'),'Live match accepted');
rejects(fn()=>eventRef('arena','https://evil.example/tournament/live/arena/31195524'),'External URL accepted');
check(Transport::playerRedirect('https://api.chess.com/pub/player/new-name')&&!Transport::playerRedirect('https://evil.example/pub/player/new-name'),'Safe redirect');
$match=['@id'=>'https://api.chess.com/pub/match/20201111','name'=>'Team France test','status'=>'in_progress','boards'=>2,'settings'=>['time_class'=>'daily'],'teams'=>[
'team1'=>['@id'=>'https://api.chess.com/pub/club/team-france','score'=>1.5,'players'=>[
['username'=>'Alice','board'=>'https://api.chess.com/pub/match/20201111/1','results'=>['played_as_white'=>'win','played_as_black'=>'agreed']],
['username'=>'Bob','board'=>'https://api.chess.com/pub/match/20201111/2','results'=>['played_as_white'=>'resigned','played_as_black'=>'']]]],
'team2'=>['@id'=>'https://api.chess.com/pub/club/other','players'=>[
['username'=>'OpponentA','board'=>'https://api.chess.com/pub/match/20201111/board/1'],['username'=>'OpponentB','board'=>2]]]
]];
$m=matchResult($match,'20201111');
check($m['rows']['alice']['points']===150&&$m['rows']['bob']['points']===0,'Raw match points');
$corrected=$match;$corrected['teams']['team2']['fair_play_removals']=['OpponentA'];$corrected['teams']['team1']['score']=2;
$c=matchResult($corrected,'20201111',$m['evidence']);
check($c['rows']['alice']['points']===200,'Opponent fair play correction');
$own=$corrected;$own['teams']['team1']['fair_play_removals']=[['username'=>'Alice']];$own['teams']['team1']['score']=0;
$o=matchResult($own,'20201111',$c['evidence']);check($o['rows']['alice']['points']===0,'Own removal has precedence');
$missing=$match;unset($missing['teams']['team1']['players'][0]);rejects(fn()=>matchResult($missing,'20201111',$m['evidence']),'Partial lineup accepted');
$wrong=$match;$wrong['teams']['team1']['score']=9;rejects(fn()=>matchResult($wrong,'20201111'),'Score mismatch accepted');
$scheduled=$match;$scheduled['status']='registered';check(matchResult($scheduled,'20201111')['rows']===[],'Upcoming event points');
$finished=$match;$finished['status']='finished';rejects(fn()=>matchResult($finished,'20201111'),'Incomplete finished game accepted');
$csv="Rank,Federation,Title,Username,Name,Club,Score,Longest streak,Most wins\n1,FRA,,Alice,,Team France,42,8,15\n2,FRA,,ALICE,,Team France,8,4,3\n3,FRA,,Bob,,Team France,12,4,5\n4,FRA,,Other,,Other Club,100,12,18\n";
$a=arenaResult($csv);check($a['rows']['alice']['points']===5000&&$a['rows']['bob']['points']===1200&&!isset($a['rows']['other']),'Official Score and attribution');
rejects(fn()=>arenaResult('<html>error</html>'),'HTML accepted as CSV');
rejects(fn()=>arenaResult("Username,Club,Score\nAlice,Team France,invalid\n"),'Invalid score accepted');
$dir=sys_get_temp_dir().'/tf-leaderboard-test-'.bin2hex(random_bytes(6));$s=new Store($dir);$refresh=new Refresh($s);
$s->saveSeason(['kind'=>'daily','name'=>'Daily test']);$s->saveSeason(['kind'=>'arena','name'=>'Arena test']);$s->saveSeason(['kind'=>'daily','name'=>'Historical']);
$daily=(int)$s->one("SELECT id FROM seasons WHERE name='Daily test'")['id'];$arena=(int)$s->one("SELECT id FROM seasons WHERE name='Arena test'")['id'];$history=(int)$s->one("SELECT id FROM seasons WHERE name='Historical'")['id'];
check((int)$s->one('SELECT is_default FROM seasons WHERE id=?',[$daily])['is_default']===1,'First default season');
$s->assign(['season_id'=>$daily,'references'=>"20201111\n20201111"]);check((int)$s->run('SELECT COUNT(*) FROM events')->fetchColumn()===1,'Event deduplication');
rejects(fn()=>$s->assign(['season_id'=>$history,'references'=>'20201111']),'Event assigned twice');
$s->assign(['season_id'=>$arena,'references'=>'https://www.chess.com/tournament/live/arena/test-31195524']);
$de=$s->one("SELECT * FROM events WHERE kind='daily'");$ae=$s->one("SELECT * FROM events WHERE kind='arena'");
$refresh->daily($de,$match);$refresh->daily($s->one('SELECT * FROM events WHERE id=?',[$de['id']]),$match);
check($s->leaderboard($daily)[0]['points']==1.5,'Refresh idempotency');
$refresh->importCsv((int)$ae['id'],$csv);$refresh->importCsv((int)$ae['id'],$csv);
check($s->leaderboard($arena)[0]['points']==50,'Arena reimport idempotency');
$s->saveProfile('alice',['username'=>'Alice','player_id'=>100,'status'=>'basic']);
$s->saveProfile('bob',['username'=>'Bob','player_id'=>200,'status'=>'basic']);
$s->saveProfile('alice',['username'=>'Alice-Renamed','player_id'=>100,'status'=>'premium']);
check($s->leaderboard($arena)[0]['username']==='Alice-Renamed'&&$s->lookup('Alice-Renamed')==='id:100','Verified rename');
rejects(fn()=>$s->saveProfile('bob',['username'=>'SomeoneElse','player_id'=>900,'status'=>'basic']),'Conflicting identity accepted');
$s->move((int)$de['id'],$history);check($s->leaderboard($daily)===[]&&$s->leaderboard($history)[0]['points']==1.5,'Move recomputes season');
$s->saveSeason(['id'=>$history,'kind'=>'daily','name'=>'Historical','archived'=>true]);
$s->saveProfile('alice',['username'=>'Alice-Renamed','player_id'=>100,'status'=>'closed:fair_play_violations']);
check($s->leaderboard($history)===[]&&$s->leaderboard($arena)[0]['username']==='Bob'&&$s->lookup('alice')===null,'Global Fair Play exclusion');
$s->saveProfile('alice',['username'=>'Alice-Renamed','player_id'=>100,'status'=>'premium']);
check($s->leaderboard($history)[0]['points']==1.5,'Reversible account correction');
$s->fail('daily',(string)$de['id'],'HTTP 500');
check($s->leaderboard($history)[0]['points']==1.5,'Failure preserves results');
$job=$refresh->start('admin');check($job['job']['state']==='running','Job start');$refresh->control('pause','admin');$s2=new Store($dir);$rr=new Refresh($s2);check($rr->progress()['job']['state']==='paused','Checkpoint persists');$rr->control('resume','admin');$rr->control('stop','admin');
echo "PASS: Daily scoring, Fair Play reconciliation, arena score, identity, seasons, SQLite persistence, idempotency and acquisition state\n";
