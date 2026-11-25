<?php
declare(strict_types=1);
require __DIR__.'/../TeamFrance/Leaderboard/src/Refresh.php';
use TeamFrance\Leaderboard\Transport;
use TeamFrance\Leaderboard\Store;
use TeamFrance\Leaderboard\Refresh;
use TeamFrance\Leaderboard\FairPlaySummaryMismatch;
use function TeamFrance\Leaderboard\matchResult;
function verify(bool $b,string $m):void{if(!$b)throw new RuntimeException($m);}
function rejected(callable $f):void{try{$f();}catch(RuntimeException){return;}throw new RuntimeException('Unexpected acceptance');}
$id='1809308';
if(in_array('--live',$argv,true)){
 $p=Transport::json('https://api.chess.com/pub/match/'.$id);
 $s=new Store(sys_get_temp_dir().'/tf-match-proof-'.bin2hex(random_bytes(6)));
 $s->saveSeason(['kind'=>'daily','name'=>'Test']);$s->assign(['season_id'=>1,'references'=>$id]);
 (new Refresh($s))->daily($s->one('SELECT * FROM events'),$p);
 $e=$s->one('SELECT * FROM events');$v=json_decode($e['evidence'],true);
 verify($e['status']==='finished'&&count($v['verified_summary_mismatch_boards'])===2&&$v['official_delta_x100']===200,'Actual board-proven summary mismatch');
 verify((float)$s->run('SELECT SUM(points)/100.0 FROM results')->fetchColumn()===107.5,'Actual score 107.5');
 foreach($v['verified_summary_mismatch_boards'] as $b)verify(is_file($s->dir.'/sources/'.$b['source_hash']),'Board evidence archived');
 echo "PASS: actual 1809308, 97 boards, 107.5 points, official summary delta +2, board evidence archived.\n";
}else{
 $p=['@id'=>'https://api.chess.com/pub/match/'.$id,'status'=>'finished','boards'=>2,'teams'=>[
 ['@id'=>'https://api.chess.com/pub/club/team-france','score'=>4,'fair_play_removals'=>['Own'],'players'=>[['username'=>'Own','board'=>1,'played_as_white'=>'win','played_as_black'=>'win'],['username'=>'Alice','board'=>2,'played_as_white'=>'resigned','played_as_black'=>'checkmated']]],
 ['@id'=>'https://api.chess.com/pub/club/other','score'=>0,'fair_play_removals'=>['Opp'],'players'=>[['username'=>'Bob','board'=>1],['username'=>'Opp','board'=>2]]]
 ]];
 try{matchResult($p,$id);throw new LogicException('No proof requested');}catch(FairPlaySummaryMismatch $x){verify($x->boards===[1,2],'Required board list');}
 $game=static fn($w,$b,$wr,$br)=>['match'=>'https://api.chess.com/pub/match/'.$id,'time_class'=>'daily','white'=>['username'=>$w,'result'=>$wr],'black'=>['username'=>$b,'result'=>$br]];
 $proof=[1=>['board_scores'=>['Own'=>0,'Bob'=>2],'games'=>[$game('Own','Bob','win','resigned'),$game('Bob','Own','resigned','win')]],2=>['board_scores'=>['Alice'=>2,'Opp'=>0],'games'=>[$game('Alice','Opp','resigned','win'),$game('Opp','Alice','win','checkmated')]]];
 $r=matchResult($p,$id,[],$proof);verify($r['rows']['own']['points']===0&&$r['rows']['alice']['points']===200,'Board proof preserves scoring');
 $bad=$proof;$bad[1]['board_scores']['Own']=2;rejected(fn()=>matchResult($p,$id,[],$bad));
 rejected(fn()=>matchResult($p,$id,[],[1=>$proof[1]]));
 $bad=$proof;$bad[2]['games'][0]['match']='https://api.chess.com/pub/match/999';rejected(fn()=>matchResult($p,$id,[],$bad));
 $p['teams'][0]['score']=3;$p['teams'][1]['score']=1;rejected(fn()=>matchResult($p,$id,[],$proof));
 echo "PASS: bounded proof fallback and unrelated mismatches rejected.\n";
}
