<?php
declare(strict_types=1);
require __DIR__.'/../TeamFrance/Leaderboard/src/ArenaDiscovery.php';
use TeamFrance\Leaderboard\ArenaIndexParser;
use TeamFrance\Leaderboard\ArenaDiscovery;
use TeamFrance\Leaderboard\Store;
function verify(bool $b,string $label):void {if(!$b)throw new RuntimeException($label);}
function bad(callable $f):void {try{$f();}catch(Throwable){return;}throw new RuntimeException('Expected rejection');}
function row(int $id,string $name,string $date):string {return '<tr><td><a href="/tournament/live/arena/test-'.$id.'">'.$name.'</a></td><td>'.$date.'</td></tr>';}
$html='<table>'.row(31100001,'January event','Jan 1, 2026').row(31100002,'Old event','Dec 31, 2025').row(31100003,'February event','Feb 28, 2026').row(31100004,'March event','Mar 1, 2026').row(31100005,'Undated event','No date').row(31100003,'Duplicate','Feb 28, 2026').'</table><a href="?type=multi&page=2">Next</a>';
$p=ArenaIndexParser::parse($html,1);
verify(count($p['events'])===5&&$p['has_next'],'Row identity dedupe and explicit next');
$r=ArenaDiscovery::classify($p['events'],'2026-01-01','2026-02-28',['31100003'=>['season'=>'Existing','season_id'=>4]]);
verify(count($r['events'])===2&&$r['unknown_dates']===1&&$r['outside_range']===2,'Inclusive range, out-of-order old row and unknown date');
verify($r['events'][0]['name']==='January event'&&$r['events'][1]['assigned']['season']==='Existing','Real names and assigned metadata');
$full='<table>';for($i=1;$i<=25;$i++)$full.=row(31101000+$i,'Arena '.$i,'Feb 1, 2026');$full.='</table>';
$p2=ArenaIndexParser::parse($full,2);
verify(count($p2['events'])===25&&$p2['has_next']&&$p2['page_fingerprint']===ArenaIndexParser::parse($full,3)['page_fingerprint'],'Implicit pagination and repeat fingerprint');
$next=ArenaIndexParser::parse('<table>'.row(31102001,'Later index page','Feb 2, 2026').'</table>',3);
verify(count(ArenaDiscovery::classify($next['events'],'2026-01-01','2026-02-28')['events'])===1&&!$next['has_next'],'Never stop merely because an older event was found');
verify(ArenaDiscovery::organizer('https://www.chess.com/club/team-france')==='team-france'&&ArenaDiscovery::organizer('TEAM-FRANCE')==='team-france','Organizer inputs');
bad(fn()=>ArenaDiscovery::organizer('https://evil.example/club/team-france'));
bad(fn()=>ArenaDiscovery::organizer('https://www.chess.com/club/team-france/../../x'));
if(in_array('sqlite',PDO::getAvailableDrivers(),true)){
 $s=new Store(sys_get_temp_dir().'/tf-arena-discovery-'.bin2hex(random_bytes(6)));
 $s->saveSeason(['kind'=>'arena','name'=>'Test']);
 $id=(int)$s->seasons()[0]['id'];
 bad(fn()=>(new ArenaDiscovery($s))->page($id,'team-france',1,'',''));
 $s->saveSeason(['id'=>$id,'kind'=>'arena','name'=>'Test','start_date'=>'2026-01-01','end_date'=>'2026-02-28']);
 bad(fn()=>(new ArenaDiscovery($s))->page($id,'team-france',1,'2026-01-01','2026-03-01'));
 $refs=implode("\n",array_map(fn($i)=>(string)(31110000+$i),range(1,600)));
 $s->assign(['season_id'=>$id,'references'=>$refs,'name_hints'=>['31110001'=>'Discovered arena']]);
 verify((int)$s->run('SELECT COUNT(*) FROM events')->fetchColumn()===600,'Select all supports >500 events');
 verify($s->one("SELECT name FROM events WHERE event_id='31110001'")['name']==='Discovered arena','Name hints stored');
 $s->assign(['season_id'=>$id,'references'=>'31110001','name_hints'=>['31110001'=>'Changed']]);
 verify($s->one("SELECT name FROM events WHERE event_id='31110001'")['name']==='Discovered arena','Existing event name preserved');
}
echo "PASS: CWL index parsing, full-page pagination, repeated-page identity, inclusive season dates, names, duplicate detection, bulk assignment.\n";
