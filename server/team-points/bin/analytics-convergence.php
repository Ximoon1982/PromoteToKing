<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"Analytics convergence requires PHP CLI.\n"); exit(2); }
require_once dirname(__DIR__).'/src/bootstrap.php';
use P2K\TeamPoints\AnalyticsBuilder;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\Repository;

$seconds=max(12,min(45,(int)($argv[1]??35)));
$started=microtime(true);
$deadline=$started+$seconds-2.0;
$core=Database::core();$analytics=Database::analytics();
$repo=new Repository($core,$analytics);
if(!$repo->schemaInstalled())$repo->upgradeExistingSchema();
if(!$repo->schemaInstalled())throw new RuntimeException('Team Points schema is not ready.');
$cfg=p2k_tp_config();$club=strtolower((string)($cfg['app']['club_slug']??'promote-to-king'));
$lock='p2k_tp_analytics_convergence_'.substr(preg_replace('/[^a-z0-9_-]+/i','-',$club)?:'club',0,48);
$q=$core->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
if((int)$q->fetchColumn()!==1){fwrite(STDOUT,json_encode(['ok'=>true,'busy'=>true,'version'=>'2.10.9.7']).PHP_EOL);exit(0);}
try{
    $budget=max(1.0,$deadline-microtime(true)-1.0);$value=number_format(min(30.0,$budget),3,'.','');
    foreach([$core,$analytics] as $pdo){try{$pdo->exec('SET SESSION max_statement_time='.$value);}catch(Throwable){}}
    $result=(new AnalyticsBuilder($core,$analytics))->refreshIfDue($club,300,$deadline);
    fwrite(STDOUT,json_encode(['ok'=>true,'version'=>'2.10.9.7','elapsed_ms'=>(int)round((microtime(true)-$started)*1000),'result'=>$result],JSON_UNESCAPED_SLASHES).PHP_EOL);
} finally {
    foreach([$core,$analytics] as $pdo){try{$pdo->exec('SET SESSION max_statement_time=0');}catch(Throwable){}}
    try{$r=$core->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lock]);}catch(Throwable){}
}
