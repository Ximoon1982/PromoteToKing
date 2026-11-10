<?php
declare(strict_types=1);
// Extract pure roster validation helpers without executing the HTTP endpoint or auth bootstrap.
$src=file_get_contents(__DIR__.'/../TGP/MatchRecruitment/api/index.php');
$first=strpos($src,'function recruitment_normalize_members(');
$last=strpos($src,'function recruitment_save_snapshot(', $first);
if($first===false||$last===false)throw new RuntimeException('Missing roster helpers');
eval(substr($src,$first,$last-$first));
$payload=['weekly'=>[['username'=>'Alice','joined'=>1700000000],['username'=>'Bob','joined'=>1700000100]], 'monthly'=>[['username'=>'ALICE','joined'=>1700000000]], 'all_time'=>[]];
$members=recruitment_normalize_members($payload);
if(count($members)!==2||$members[0]['joined']!==1700000000||$members[0]['username']!=='ALICE')throw new RuntimeException('Roster join timestamp was not preserved');
$file=tempnam(sys_get_temp_dir(),'tgp-roster-');
try{
 file_put_contents($file,json_encode(['schemaVersion'=>1,'clubSlug'=>'the-golden-phoenix','fetchedAt'=>'2026-10-01T10:00:00Z','memberCount'=>2,'members'=>['Alice','Bob']]));
 $old=recruitment_valid_snapshot($file);
 if($old===null||count($old['members'])!==2||$old['members'][0]['joined']!==null)throw new RuntimeException('Legacy snapshot not supported');
 file_put_contents($file,json_encode(['schemaVersion'=>2,'clubSlug'=>'the-golden-phoenix','fetchedAt'=>'2026-11-10T10:00:00Z','memberCount'=>2,'members'=>$members]));
 $new=recruitment_valid_snapshot($file);
 if($new===null||$new['members'][0]['joined']!==1700000000)throw new RuntimeException('Joined date lost in snapshot');
 echo "PASS TGP roster joined dates and legacy snapshot compatibility\n";
}finally{unlink($file);}
