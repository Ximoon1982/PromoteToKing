<?php
declare(strict_types=1);
require __DIR__.'/../TeamFrance/Leaderboard/src/Refresh.php';
require __DIR__.'/../TeamFrance/Leaderboard/src/LeaderboardExport.php';
use TeamFrance\Leaderboard\Store;
use TeamFrance\Leaderboard\Refresh;
use TeamFrance\Leaderboard\LeaderboardExport;
function verify(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function reject(callable $f):void{try{$f();}catch(RuntimeException){return;}throw new RuntimeException('Expected rejection');}
$s=new Store(sys_get_temp_dir().'/tf-frozen-'.bin2hex(random_bytes(6)));$r=new Refresh($s);
foreach(['Active','History'] as $n)$s->saveSeason(['kind'=>'daily','name'=>$n]);
$s->saveSeason(['kind'=>'arena','name'=>'Arena']);
$s->assign(['season_id'=>1,'references'=>'1001']);$s->assign(['season_id'=>2,'references'=>'1002']);$s->assign(['season_id'=>3,'references'=>'2001']);
function result(Store $s,int $id,array $names):void{
 $e=$s->one('SELECT * FROM events WHERE id=?',[$id]);$rows=[];foreach($names as $u=>$p)$rows[strtolower($u)]=['username'=>$u,'points'=>$p];
 $s->saveEvent($e,['name'=>'Test','status'=>'finished','rows'=>$rows,'evidence'=>[]],json_encode($names));
}
result($s,1,['Alice'=>100]);result($s,2,['Alice'=>200,'Bob'=>100]);result($s,3,['Carol'=>300]);
$s->saveProfile('alice',['username'=>'Alice','player_id'=>10,'status'=>'basic']);
$s->saveProfile('bob',['username'=>'Bob','player_id'=>11,'status'=>'basic']);
$s->saveSeason(['id'=>2,'kind'=>'daily','name'=>'History','archived'=>true]);
$frozen=$s->leaderboard(2,true);$csv=LeaderboardExport::build($s,'daily',2)['csv'];
$s->saveProfile('alice',['username'=>'Alice','player_id'=>10,'status'=>'closed:fair_play_violations']);
verify($s->leaderboard(1)===[]&&$s->leaderboard(2,true)===$frozen,'Active changes, frozen ranking exact');
verify(LeaderboardExport::build($s,'daily',2)['csv']===$csv,'Frozen export unchanged');
verify($s->categoryLeaderboard('daily')[0]['points']==2,'All-season export keeps frozen contribution');
verify($s->frozenProfileMatch($frozen,'Alice')['points']==2,'Frozen profile still searchable');
reject(fn()=>$s->assign(['season_id'=>2,'references'=>'1003']));reject(fn()=>$s->move(2,1));reject(fn()=>$s->assertEditableEvent(2));
$p=$r->start('admin');verify(!$p['include_archived']&&(int)$s->run("SELECT COUNT(*) FROM tasks")->fetchColumn()===2,'Default excludes archived event');
$r->control('pause','admin');$r->control('resume','admin');verify(!$r->progress()['include_archived'],'Scope persists');
$s->run("UPDATE tasks SET state='done'");$r->step('admin');
verify(!$s->one("SELECT 1 FROM tasks WHERE kind='player' AND item='bob'"),'Historical-only player not acquired');
$s->run("UPDATE tasks SET state='done'");$r->step('admin');verify($s->leaderboard(2,true)===$frozen,'Ordinary job does not publish archive');
$p=$r->start('admin',true);verify($p['include_archived']&&(int)$s->run("SELECT COUNT(*) FROM tasks")->fetchColumn()===3,'Explicit scope includes archives');
$r->control('pause','admin');$rr=new Refresh(new Store($s->dir));verify($rr->progress()['include_archived'],'Scope survives reload');$rr->control('resume','admin');
$s->run("UPDATE tasks SET state='done'");$rr->step('admin');verify((bool)$s->one("SELECT 1 FROM tasks WHERE kind='player' AND item='bob'"),'Opt-in includes historical players');
$s->run("UPDATE tasks SET state='done'");$s->run("UPDATE tasks SET state='error' WHERE kind='player' AND item='bob'");$rr->step('admin');
verify($rr->progress()['archive_publication']==='retained_due_to_errors'&&$s->leaderboard(2,true)===$frozen,'Errors do not publish partial snapshot');
$rr->start('admin',true);$s->run("UPDATE tasks SET state='done'");$rr->step('admin');$s->run("UPDATE tasks SET state='done'");$rr->step('admin');
verify($rr->progress()['archive_publication']==='published'&&count($s->leaderboard(2))===1&&$s->leaderboard(2)[0]['username']==='Bob','Successful opt-in republishes archive');
$s->run("DELETE FROM meta WHERE key='frozen:season:2'");$legacy=new Store($s->dir);verify($legacy->leaderboard(2)[0]['username']==='Bob','Legacy archived capture');
$s->saveSeason(['id'=>2,'kind'=>'daily','name'=>'History','archived'=>false]);verify($s->meta('frozen:season:2')==='','Unarchive removes snapshot');
$s->assign(['season_id'=>2,'references'=>'1003']);
echo "PASS: freeze, default and explicit scopes, resumability, historical profiles and exports, failure retention, legacy capture and unarchive.\n";
