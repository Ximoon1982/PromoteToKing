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
    ensure(count($start['widgets'])===6,'Six widget defaults');
    ensure(($start['widgets']['club-pulse']['period']??'')==='7d','Pulse defaults');
    $revision=$start['revision'];
    $change=$store->get('club-pulse');$change['title']='Club Points — P2K';$change['period']='30d';
    $saved=$store->save('club-pulse',$change,$revision);
    ensure($saved['revision']===$revision+1,'Revision increment');
    ensure($store->get('club-pulse')['title']==='Club Points — P2K','Persist Unicode title');
    ensure($store->get('around-the-world')['metric']==='matches','Independent settings preserved');
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
