<?php
declare(strict_types=1);

require __DIR__.'/../server/team-points/src/RecruitmentHistoryStore.php';

use P2K\TeamPoints\RecruitmentHistoryStore;

$root=sys_get_temp_dir().'/p2k-recruitment-history-'.bin2hex(random_bytes(4));
@mkdir($root,0700,true);
$store=new RecruitmentHistoryStore($root);

$csv="\xEF\xBB\xBF\"username\",\"rating\"\r\n\"alpha\",\"1500\"\r\n";
$ids=[];
for($i=1;$i<=12;$i++){
    $entry=$store->archive(1000000+$i,'Match '.$i,$csv.$i."\r\n");
    if((int)$entry['match_id']!==1000000+$i) throw new RuntimeException('match id mismatch');
    $ids[]=(string)$entry['id'];
}
$p1=$store->page(1,10);
$p2=$store->page(2,10);
if($p1['total']!==12||$p1['pages']!==2||count($p1['items'])!==10) throw new RuntimeException('page 1 pagination failed: '.json_encode($p1));
if(count($p2['items'])!==2) throw new RuntimeException('page 2 pagination failed: '.json_encode($p2));
$entry=$store->get($ids[0]);
if(!is_array($entry)) throw new RuntimeException('archive lookup failed');
$contents=file_get_contents($store->pathFor($entry));
if($contents!==$csv."1\r\n") throw new RuntimeException('archived CSV bytes changed');
if(!$store->delete($ids[0])) throw new RuntimeException('delete returned false');
if($store->get($ids[0])!==null) throw new RuntimeException('deleted archive still indexed');
if($store->page(1,10)['total']!==11) throw new RuntimeException('delete did not update total');

$files=glob($root.'/*')?:[];
foreach($files as $file)@unlink($file);
@rmdir($root);
echo "PASS recruitment history archive, pagination, byte preservation and delete\n";
