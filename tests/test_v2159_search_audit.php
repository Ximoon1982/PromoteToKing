<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/shared/NameSearchUsage.php';
use P2K\Shared\NameSearchUsage as Searches;
function ensureSearch(bool $yes,string $why):void{
    if(!$yes)throw new RuntimeException('FAIL '.$why);
}
$base=sys_get_temp_dir().'/p2k-name-search-fixture-'.bin2hex(random_bytes(5));
mkdir($base,0700,true);
try{
    $a=Searches::record($base,'Ximoon','match-assistant');
    $b=Searches::record($base,'Ximoon','recruitment-radar');
    $c=Searches::record($base,'Other_Chess-Player','recruitment-radar');
    ensureSearch($a['username']==='ximoon','Normalize valid Chess.com usernames');
    ensureSearch($a['source']==='match-assistant','Match assistant recorded separately');
    ensureSearch($b['source']==='recruitment-radar','Radar source recorded');
    ensureSearch($a['timestamp']===gmdate('Y-m-d\TH:i:s\Z'),'UTC server timestamps');
    ensureSearch(is_file($base.'/logs/name-search/'.gmdate('Y-m-d').'.jsonl'),'Dedicated private log created');
    $report=Searches::report($base,7);
    ensureSearch($report['summary']['total']===3,'Count all valid searches, including repeats');
    ensureSearch($report['summary']['match_assistant']===1,'Count Match Assistant');
    ensureSearch($report['summary']['recruitment_radar']===2,'Count Recruitment Radar');
    ensureSearch($report['summary']['distinct_usernames']===2,'Distinct searched names');
    ensureSearch(count($report['entries'])===3,'All timestamped individual searches returned');
    ensureSearch($report['users'][0]['username']==='ximoon'&&$report['users'][0]['total']===2,'Repeated username aggregated');
    ensureSearch(array_sum(array_column($report['daily'],'total'))===3,'Daily report covers all events');
    foreach(['name with spaces','https://www.chess.com','x!',''] as $bad){
        try{Searches::record($base,$bad,'recruitment-radar');throw new RuntimeException('Unsafe username accepted: '.$bad);}
        catch(InvalidArgumentException){}
    }
    try{Searches::record($base,'Ximoon','unknown-source');throw new RuntimeException('Unapproved source accepted');}
    catch(InvalidArgumentException){}
    ensureSearch(Searches::report($base,7)['summary']['total']===3,'Invalid searches never written');
    $root=dirname(__DIR__);
    $router=file_get_contents($root.'/api/router.php');
    $radar=file_get_contents($root.'/server/widgets/public/api.php');
    $ui=file_get_contents($root.'/assets/js/pages/find-match.js');
    $admin=file_get_contents($root.'/assets/js/pages/club-intelligence.js');
    $intel=file_get_contents($root.'/server/team-points/public/intelligence.php');
    ensureSearch(str_contains($router,"'name-search'"),'Existing Match Assistant log endpoint accepts search audit event');
    ensureSearch(str_contains($radar,"NameSearchUsage::record(root_dir(),\$name,'recruitment-radar')"),'Radar lookup logs valid name once');
    ensureSearch(str_contains($ui,'logValidUsernameSearch(username, synchronized);'),'Explicit Match Assistant searches logged before API analysis');
    ensureSearch(str_contains($intel,"'name_search'")&&str_contains($admin,'renderNameSearchActivity'),'Traffic/Visitors exposes new search history');
    echo "PASS UTC username search audit, both sources, repeats, invalid input, and Traffic/Visitors integration\n";
}finally{
    $dir=$base.'/logs/name-search';foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
    @rmdir($dir);@rmdir($base.'/logs');@rmdir($base);
}
