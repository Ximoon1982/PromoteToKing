<?php
declare(strict_types=1);
require_once __DIR__.'/../server/events-showcase/src/EventsShowcaseStore.php';
use P2K\EventsShowcase\EventsShowcaseStore;
use P2K\EventsShowcase\RevisionConflict;
function ok(bool $v,string $m): void { if(!$v) throw new RuntimeException($m); echo "PASS $m\n"; }
$root=sys_get_temp_dir().'/p2k-es-'.bin2hex(random_bytes(5));mkdir($root.'/data',0777,true);
try{
 $s=new EventsShowcaseStore($root);
 $a=$s->read();ok($a['schemaVersion']===4,'empty state schema 4');ok($a['revision']===0,'empty revision zero');
 $legacy=['schemaVersion'=>4,'revision'=>7,'items'=>[['matchId'=>'1234','enabled'=>true,'urgent'=>false]],'arenas'=>[['active'=>true,'name'=>'Later','link'=>'https://www.chess.com/play/arena/later/','date'=>'2099-01-02','time'=>'19:00','timeControl'=>'3+2','duration'=>'2h'],['active'=>true,'name'=>'Earlier','link'=>'https://chess.com/play/arena/earlier','date'=>'2099-01-01','time'=>'19:00','timeControl'=>'3+2','duration'=>'90m'],['active'=>true,'name'=>'Duplicate','link'=>'https://www.chess.com/play/arena/earlier/','date'=>'2099-01-03','time'=>'19:00','duration'=>'1h']]];
 file_put_contents($root.'/data/priority-matches.json',json_encode($legacy));@unlink($root.'/data/events-showcase.json');
 $s=new EventsShowcaseStore($root);$m=$s->read();ok(is_file($root.'/data/priority-matches.json'),'legacy state preserved');ok(is_file($root.'/data/events-showcase.json'),'native state created from legacy');ok(($m['migration']['legacyPreserved']??false)===true,'migration marker preserved');ok(count($m['arenas'])===2,'legacy duplicate arena removed');ok($m['arenas'][0]['name']==='Earlier','arenas sorted by start time');
 $rev=$m['revision'];
 $saved=$s->save(['items'=>[['matchId'=>'5555','enabled'=>true,'urgent'=>true]],'arenas'=>$m['arenas']],$rev);ok($saved['revision']===$rev+1,'save increments revision');ok($saved['items'][0]['urgent']===true,'priority marker persists');
 try{$s->save(['items'=>[],'arenas'=>[]],$rev);ok(false,'revision conflict rejected');}catch(RevisionConflict){ok(true,'revision conflict rejected');}
 $dupe=$saved['arenas'];$dupe[]=$dupe[0];try{$s->save(['items'=>$saved['items'],'arenas'=>$dupe],$saved['revision']);ok(false,'duplicate URL rejected on save');}catch(InvalidArgumentException){ok(true,'duplicate URL rejected on save');}
 $bad=$saved['arenas'];$bad[0]['link']='http://evil.example/arena';try{$s->save(['items'=>$saved['items'],'arenas'=>$bad],$saved['revision']);ok(false,'non chess.com URL rejected');}catch(InvalidArgumentException){ok(true,'non chess.com URL rejected');}
 $expired=['active'=>true,'name'=>'Past','link'=>'https://chess.com/play/arena/past','date'=>'2020-01-01','time'=>'00:00','duration'=>'1h'];$now=$s->save(['items'=>$saved['items'],'arenas'=>[$expired]],$saved['revision']);ok(count($now['arenas'])===0,'expired arena removed on save');
 $metrics=$s->metrics();ok($metrics['dailyMatches']['total']===1&&$metrics['dailyMatches']['enabled']===1,'daily metrics correct');ok($metrics['arenas']['total']===0,'arena metrics correct after expiry');
}finally{if(is_dir($root)){system('rm -rf '.escapeshellarg($root));}}
