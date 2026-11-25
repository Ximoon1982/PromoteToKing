<?php
declare(strict_types=1);
require __DIR__.'/../TeamFrance/Leaderboard/src/Core.php';
use TeamFrance\Leaderboard\Store;
function verify(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$s=new Store(sys_get_temp_dir().'/tf-recap-'.bin2hex(random_bytes(6)));
foreach([['daily','Active'],['daily','History'],['arena','Arena'],['daily','Empty']] as [$kind,$name])$s->saveSeason(['kind'=>$kind,'name'=>$name]);
$s->assign(['season_id'=>1,'references'=>'1001,1002,1003,1004,1005,1006']);
$s->assign(['season_id'=>2,'references'=>'2001,2002']);
$s->assign(['season_id'=>3,'references'=>'3001']);
foreach(['1001'=>'in_progress','1002'=>'in_progress','1003'=>'finished','1004'=>'registered','1005'=>'cancelled','2001'=>'in_progress','2002'=>'finished','3001'=>'in_progress'] as $id=>$status)$s->run('UPDATE events SET status=? WHERE event_id=?',[$status,(string)$id]);
$s->saveSeason(['id'=>2,'kind'=>'daily','name'=>'History','archived'=>true]);
$rows=array_column($s->seasons(),null,'name');
verify((int)$rows['Active']['event_count']===6&&(int)$rows['Active']['ongoing_count']===2,'Only verified in-progress Daily matches counted');
verify((int)$rows['History']['ongoing_count']===1&&(int)$rows['History']['archived']===1,'Archived count reflects stored statuses');
verify($rows['Arena']['ongoing_count']===null,'Arena not applicable');
verify((int)$rows['Empty']['ongoing_count']===0,'Empty Daily season zero');
$s->run("UPDATE events SET status='finished' WHERE event_id='1001'");
verify((int)array_column($s->seasons(),null,'name')['Active']['ongoing_count']===1,'Count follows saved status');
echo "PASS: season ongoing counts, status filtering, archive, arena N/A and zero.\n";
