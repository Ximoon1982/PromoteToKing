<?php
declare(strict_types=1);
require __DIR__.'/../TeamFrance/Leaderboard/src/Refresh.php';
use TeamFrance\Leaderboard\Store;
use function TeamFrance\Leaderboard\avatarUrl;
use function TeamFrance\Leaderboard\matchResult;
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$avatar='https://images.chesscomfiles.com/uploads/v1/user/123.avatar.png';
verify(avatarUrl($avatar)===$avatar,'Approved avatar');
foreach(['https://evil.example/avatar.png','http://images.chesscomfiles.com/x','https://images.chesscomfiles.com.evil.example/x','https://images.chesscomfiles.com@evil.example/x','https://evil.example@images.chesscomfiles.com/x','javascript:alert(1)'] as $bad)verify(avatarUrl($bad)==='','Rejected avatar: '.$bad);
$s=new Store(sys_get_temp_dir().'/tf-profile-'.bin2hex(random_bytes(6)));
$s->saveSeason(['kind'=>'daily','name'=>'Daily']);$s->saveSeason(['kind'=>'arena','name'=>'Arena']);$s->saveSeason(['kind'=>'daily','name'=>'Other']);
$d=(int)$s->one("SELECT id FROM seasons WHERE name='Daily'")['id'];$a=(int)$s->one("SELECT id FROM seasons WHERE name='Arena'")['id'];$o=(int)$s->one("SELECT id FROM seasons WHERE name='Other'")['id'];
foreach([[$d,'1001,1002,1003'],[$a,'2001,2002'],[$o,'3001']] as [$season,$refs])$s->assign(['season_id'=>$season,'references'=>$refs]);
function result(Store $s,string $kind,string $id,array $rows):void{
 $e=$s->one('SELECT * FROM events WHERE kind=? AND event_id=?',[$kind,$id]);
 $s->saveEvent($e,['name'=>'Test '.$id,'status'=>'finished','rows'=>$rows,'evidence'=>[]],$id);
}
result($s,'daily','1001',['alice'=>['username'=>'Alice','points'=>150],'alice-old'=>['username'=>'Alice-Old','points'=>100],'bob'=>['username'=>'Bob','points'=>0]]);
result($s,'daily','1002',['alice'=>['username'=>'Alice','points'=>0],'bob'=>['username'=>'Bob','points'=>0]]);
result($s,'daily','1003',['alice-old'=>['username'=>'Alice-Old','points'=>50]]);
result($s,'arena','2001',['alice'=>['username'=>'Alice','points'=>1200]]);
result($s,'arena','2002',['alice-old'=>['username'=>'Alice-Old','points'=>0]]);
result($s,'daily','3001',['alice'=>['username'=>'Alice','points'=>500]]);
$s->saveProfile('alice',['username'=>'Alice','player_id'=>123,'status'=>'basic','avatar'=>$avatar]);
$s->saveProfile('alice-old',['username'=>'Alice-Old','player_id'=>123,'status'=>'basic','avatar'=>$avatar]);
$s->saveProfile('alice-old',['username'=>'Alice','player_id'=>123,'status'=>'basic','avatar'=>$avatar]);
$rows=$s->leaderboard($d);verify(count($rows)===1&&$rows[0]['points']==2&&$rows[0]['events']===3&&$rows[0]['avatar']===$avatar,'Alias-deduped event count, unchanged ranking and score');
verify($s->leaderboard($a)[0]['events']===2&&$s->leaderboard($a)[0]['points']==12,'Independent arena count');
verify($s->leaderboard($o)[0]['events']===1,'Season isolation');
$all=$s->leaderboard($d,true);verify(count($all)===2&&$all[1]['rank']===null&&$all[1]['points']==0&&$all[1]['events']===2,'Zero-point participation visible in profile, not ranked');
verify($s->publicPlayer('id:123')['username']==='Alice'&&$s->publicPlayer('id:123')['avatar']===$avatar,'Canonical public profile');
$s->saveProfile('alice',['username'=>'Alice','player_id'=>123,'status'=>'closed:fair_play_violations','avatar'=>$avatar]);
verify($s->leaderboard($d)===[]&&$s->publicPlayer('id:123')===null,'Global exclusion retained');
$m=['@id'=>'https://api.chess.com/pub/match/555','name'=>'Ongoing test','status'=>'in_progress','boards'=>1,'teams'=>['team1'=>['@id'=>'https://api.chess.com/pub/club/team-france','score'=>0,'players'=>[['username'=>'Alice','board'=>1]]],'team2'=>['@id'=>'https://api.chess.com/pub/club/other','score'=>0,'players'=>[['username'=>'Other','board'=>1]]]]];
verify(matchResult($m,'555')['rows']['alice']['points']===0,'Paired ongoing board counts before completion');
$m['status']='registered';verify(matchResult($m,'555')['rows']===[],'Upcoming events excluded');
echo "PASS: safe cached avatars, unique participation counts, aliases, score and rank preservation, zero-point profiles, season isolation and Fair Play.\n";
