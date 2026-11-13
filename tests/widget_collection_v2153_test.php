<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/widgets/src/WidgetConfigStore.php';
use P2K\Widgets\WidgetConfigStore;
use P2K\Widgets\RevisionConflict;
function ensure(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL '.$label);}
$tmp=sys_get_temp_dir().'/p2k-widget-store-'.bin2hex(random_bytes(6));
try {
    $store=new WidgetConfigStore($tmp);
    $start=$store->read();
    ensure(count($start['widgets'])===10,'Ten widget defaults');
    ensure(($start['widgets']['club-pulse']['period']??'')==='7d','Pulse defaults');
    foreach (WidgetConfigStore::IDS as $id) ensure(($start['widgets'][$id]['theme']??'')==='auto','Auto default theme: '.$id);
    ensure(($start['widgets']['player-spotlight']['limit']??0)===5,'Player Spotlight top-five defaults');
    ensure(($start['widgets']['tournament-winners']['medalPageSize']??0)===3,'Tournament Winners three-medalist pages');
    ensure(($start['widgets']['hall-of-fame']['medalPageSize']??0)===3,'Hall of Fame shares tournament medal pagination defaults');
    ensure(($start['widgets']['hall-of-fame']['title']??'')==='Hall of Fame','Hall of Fame has independent title');
    ensure(($start['widgets']['competition']['title']??'')==='Competition','Competition defaults to independent published title');
    ensure(($start['widgets']['club-progress']['title']??'')==='Grinding the leaderboard','Progress default heading');
    ensure(WidgetConfigStore::normalize('club-progress',['title'=>'Club Progress'])['title']==='Grinding the leaderboard','Previous default heading migrates in memory');
    ensure(WidgetConfigStore::normalize('club-progress',['title'=>'My custom progress'])['title']==='My custom progress','User-customized progress headings are preserved');
    ensure(($start['widgets']['competition']['theme']??'')==='auto','Competition uses inherited automatic theme');
    ensure(!array_key_exists('limit',$start['widgets']['competition']),'Combined Competition has no artificial item limit');
    ensure(!array_key_exists('limit',$start['widgets']['tournament-winners']),'No completed tournament browse cap');
    ensure(!array_key_exists('limit',$start['widgets']['trophy-spotlight']),'No published trophy cap');
    $thresholds=WidgetConfigStore::normalize('club-progress',[
        'lowerReferencePoints'=>'395000','lowerReferenceLabel'=>'Current #10',
        'upperReferencePoints'=>'435000','upperReferenceLabel'=>'Target position #9'
    ]);
    ensure(($thresholds['upperReferencePoints']??'')==='435000','Reference values and labels supported');
    ensure(($thresholds['upperReferenceLabel']??'')==='Target position #9','Custom reference label survives');
    try{
        WidgetConfigStore::normalize('club-progress',['lowerReferencePoints'=>'450000','upperReferencePoints'=>'400000']);
        throw new RuntimeException('Missing validation for inverted references');
    }catch(InvalidArgumentException){}
    try{
        WidgetConfigStore::normalize('club-progress',['upperReferencePoints'=>'not-a-number']);
        throw new RuntimeException('Missing validation for non-numeric references');
    }catch(InvalidArgumentException){}

    $revision=$start['revision'];
    $change=$store->get('club-pulse');$change['title']='Club Points — P2K';$change['period']='30d';
    $saved=$store->save('club-pulse',$change,$revision);
    ensure($saved['revision']===$revision+1,'Revision increment');
    ensure($store->get('club-pulse')['title']==='Club Points — P2K','Persist Unicode title');
    ensure($store->get('around-the-world')['metric']==='matches','Independent settings preserved');
    ensure($store->get('player-spotlight')['showArena']===true,'Old settings retain new widget defaults');
    ensure($store->get('tournament-winners')['medalPageSize']===3,'Independent tournament settings preserved');
    try{$store->save('club-pulse',$change,$revision);throw new RuntimeException('Expected conflict');}
    catch(RevisionConflict $error){ensure($error->current['revision']===$saved['revision'],'Conflict returns current revision');}
    foreach ([['title'=>''],['theme'=>'invalid'],['enabled'=>'true'],['title'=>'<script>'.str_repeat('a',64)]] as $invalid){
        try{WidgetConfigStore::normalize('club-pulse',$invalid);throw new RuntimeException('Missing validation for '.json_encode($invalid));}
        catch(InvalidArgumentException){}
    }
    try{WidgetConfigStore::normalize('recruitment-radar',['limit'=>0]);throw new RuntimeException('Missing limit validation');}catch(InvalidArgumentException){}
    ensure(is_file($tmp.'/data/widgets/config.json'),'Created separate persistent widget state');
    $now=$store->read();ensure($now['revision']===1,'Invalid writes never change revision');
    echo "PASS: widget store default, persistence, isolation, concurrency, input validation\n";
}finally{
    foreach (glob($tmp.'/data/widgets/*')?:[] as $p)@unlink($p);
    @rmdir($tmp.'/data/widgets');@rmdir($tmp.'/data');@rmdir($tmp);
}
