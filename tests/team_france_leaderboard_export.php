<?php
declare(strict_types=1);
require __DIR__.'/../TeamFrance/Leaderboard/src/LeaderboardExport.php';
use TeamFrance\Leaderboard\Store;
use TeamFrance\Leaderboard\LeaderboardExport;
function verify(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);}
function records(array $export):array {
 $fp=fopen('php://temp','w+');fwrite($fp,$export['csv']);rewind($fp);$rows=[];
 while(($r=fgetcsv($fp,0,',','"',''))!==false)$rows[]=$r;
 fclose($fp);return $rows;
}
$s=new Store(sys_get_temp_dir().'/tf-export-'.bin2hex(random_bytes(6)));
$s->saveSeason(['kind'=>'daily','name'=>'2026']);
$s->saveSeason(['kind'=>'daily','name'=>'2025']);
$s->saveSeason(['kind'=>'arena','name'=>'2026']);
$s->saveSeason(['kind'=>'arena','name'=>'2025']);
$s->saveSeason(['kind'=>'daily','name'=>'Empty']);
$seasons=$s->rows('SELECT * FROM seasons ORDER BY id');[$d1,$d2,$a1,$a2,$empty]=array_map(fn($r)=>(int)$r['id'],$seasons);
foreach([[$d1,'1001,1002'],[$d2,'1003'],[$a1,'2001'],[$a2,'2002']] as [$id,$refs])$s->assign(['season_id'=>$id,'references'=>$refs]);
function result(Store $s,string $kind,string $id,array $players):void{
 $e=$s->one('SELECT * FROM events WHERE kind=? AND event_id=?',[$kind,$id]);$rows=[];
 foreach($players as $u=>$points)$rows[strtolower($u)]=['username'=>$u,'points'=>$points];
 $s->saveEvent($e,['name'=>$id,'status'=>'finished','rows'=>$rows,'evidence'=>[]],$id);
}
result($s,'daily','1001',['Alice'=>150,'Alice-Old'=>100,'Bob'=>0,'Excluded'=>900]);
result($s,'daily','1002',['Alice'=>0,'Carol'=>100]);
result($s,'daily','1003',['Alice-Old'=>250,'Bob'=>50]);
result($s,'arena','2001',['Alice'=>1200,'Carol'=>500,'Excluded'=>9000]);
result($s,'arena','2002',['Alice-Old'=>300,'Bob'=>0,'-safe'=>100]);
foreach(['alice','alice-old'] as $u)$s->saveProfile($u,['username'=>$u,'player_id'=>100,'status'=>'basic']);
$s->saveProfile('alice-old',['username'=>'Alice','player_id'=>100,'status'=>'basic']);
$s->saveProfile('excluded',['username'=>'Excluded','player_id'=>200,'status'=>'closed:fair_play_violations']);
$s->saveSeason(['id'=>$d2,'kind'=>'daily','name'=>'2025','archived'=>true]);
$s->saveSeason(['id'=>$a2,'kind'=>'arena','name'=>'2025','archived'=>true]);
$season=LeaderboardExport::build($s,'daily',$d1);$r=records($season);
verify($r===[['rank','username','points','matches'],['1','Alice','1.5','2'],['2','Carol','1','1'],['','Bob','0','1']],'Season ranking, aliases, zero points and excluded');
verify(str_contains($season['filename'],'daily-season-'.$d1.'-2026-')&&str_ends_with($season['filename'],'Z.csv'),'Season filename');
$r=records(LeaderboardExport::build($s,'daily',null));
verify($r===[['rank','username','points','matches'],['1','Alice','4','3'],['2','Carol','1','1'],['3','Bob','0.5','2']],'All Daily includes archived, isolates arenas');
$r=records(LeaderboardExport::build($s,'arena',null));
verify($r===[['rank','username','points','arenas'],['1','Alice','15','2'],['2','Carol','5','1'],['3',"'-safe",'1','1'],['','Bob','0','1']],'All Arena independent, safe formula cell');
verify(records(LeaderboardExport::build($s,'daily',$empty))===[['rank','username','points','matches']],'Empty season header only');
foreach([['arena',$d1],['daily',999999],['bad',null]] as [$k,$id]){
 $rejected=false;try{LeaderboardExport::build($s,$k,$id);}catch(RuntimeException){$rejected=true;}
 verify($rejected,'Invalid scope rejected');
}
echo "PASS: season/category CSV, archive, aliases, counts, Fair Play, zero points, empty export, filename, safe CSV cells.\n";
