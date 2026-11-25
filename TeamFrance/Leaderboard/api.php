<?php
declare(strict_types=1);
require_once __DIR__.'/src/Refresh.php';
require_once __DIR__.'/src/ArenaDiscovery.php';
require_once __DIR__.'/src/LeaderboardExport.php';
use TeamFrance\Leaderboard\Store;
use TeamFrance\Leaderboard\Refresh;
use TeamFrance\Leaderboard\Transport;
use function TeamFrance\Leaderboard\userKey;
use const TeamFrance\Leaderboard\BASE;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function reply(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function sameOrigin(): void {
    $origin=(string)($_SERVER['HTTP_ORIGIN']??'');$referer=(string)($_SERVER['HTTP_REFERER']??'');
    $host=strtolower(preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??'')));
    $candidate=$origin?:$referer;
    if($candidate===''||strtolower((string)parse_url($candidate,PHP_URL_HOST))!==$host)reply(['ok'=>false,'error'=>'Same-origin request required.'],403);
}
function admin(bool $write): array {
    require_once dirname(__DIR__).'/MatchAnalyzer/auth.php';
    $a=team_france_authorize();
    if(!$a['ok'])reply(['ok'=>false,'error'=>$a['code']],(int)$a['status']);
    if($write){
        sameOrigin();$csrf=(string)($a['csrf']??'');
        if($csrf===''||!hash_equals($csrf,(string)($_SERVER['HTTP_X_TF_CSRF']??'')))reply(['ok'=>false,'error'=>'CSRF verification failed.'],403);
    }
    return $a;
}
try {
    $action=(string)($_GET['action']??'public');$method=$_SERVER['REQUEST_METHOD']??'GET';
    $public=in_array($action,['public','public-refresh'],true);
    $write=!in_array($action,['public','admin','club-index','export-csv'],true);
    if(($write&&$method!=='POST')||(!$write&&$method!=='GET'))reply(['ok'=>false,'error'=>'Method not allowed.'],405);
    $a=$public?null:admin($write);
    if($action==='public-refresh')sameOrigin();
    $s=new Store(__DIR__.'/data');$r=new Refresh($s);
    $p=$write&&$action!=='import-csv'?json_decode(file_get_contents('php://input')?:'{}',true,512,JSON_THROW_ON_ERROR):[];
    if(!is_array($p))throw new RuntimeException('Invalid request.');
    if($action==='export-csv'){
        $kind=$_GET['kind']??'';
        $season=$_GET['season']??null;
        if(!is_string($kind)||($season!==null&&(!is_string($season)||!preg_match('/^[1-9][0-9]{0,17}$/D',$season))))reply(['ok'=>false,'error'=>'Invalid export selection.'],400);
        $export=\TeamFrance\Leaderboard\LeaderboardExport::build($s,$kind,$season===null?null:(int)$season);
        header('Content-Type: text/csv; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('Content-Disposition: attachment; filename="'.$export['filename'].'"');
        echo $export['csv'];exit;
    }
    if($action==='public'){
        $seasons=$s->seasons();$out=[];$requested=(string)($_GET['username']??'');$identity=$s->lookup($requested);$player=$identity!==null?$s->publicPlayer($identity):null;if(!$player)$identity=null;$profile=[];
        foreach(['daily','arena'] as $kind){
            $options=array_values(array_filter($seasons,static fn($x)=>$x['kind']===$kind));
            $selected=(int)($_GET[$kind]??0);$season=null;
            foreach($options as $x)if((int)$x['id']===$selected){$season=$x;break;}
            $season??=$options[0]??null;$all=$season?$s->leaderboard((int)$season['id'],true):[];$rows=array_values(array_filter($all,static fn($row)=>$row['rank']!==null));
            $total=count($rows);$size=!empty($_GET[$kind.'_expanded'])?25:5;$pages=max(1,(int)ceil($total/$size));$page=max(1,min($pages,(int)($_GET[$kind.'_page']??1)));
            $found=null;
            if($season&&!empty($season['archived']))$found=$s->frozenProfileMatch($all,$requested);
            else foreach($all as $row)if($identity!==null&&$row['identity']===$identity){$found=$row;break;}
            if(!$player&&$found)$player=array_intersect_key($found,array_flip(['username','avatar']));
            $profile[$kind]=$found?array_intersect_key($found,array_flip(['username','points','rank','events','avatar'])):null;
            $out[$kind]=['seasons'=>$options,'season'=>$season,'rows'=>array_map(static fn($x)=>array_intersect_key($x,array_flip(['username','points','rank','events','avatar'])),array_slice($rows,($page-1)*$size,$size)),'page'=>$page,'pages'=>$pages,'total'=>$total];
        }
        reply(['ok'=>true,'sections'=>$out,'profile'=>$player!==null?$profile:null,'player'=>$player,'updated'=>(int)$s->meta('updated','0')]);
    }
    if($action==='public-refresh')reply(['ok'=>true]+$r->publicStep());
    if($action==='admin'){
        $kind=(string)($_GET['kind']??'daily');$season=(int)($_GET['season']??0);$q=trim((string)($_GET['q']??''));$page=max(1,(int)($_GET['page']??1));$size=25;
        if($kind==='players'){
            $args=['%'.$q.'%','%'.$q.'%'];$where=' WHERE (username_key LIKE ? OR display LIKE ?)';
            $count=(int)$s->run('SELECT COUNT(*) FROM players'.$where,$args)->fetchColumn();$page=min($page,max(1,(int)ceil($count/$size)));
            $rows=$s->rows('SELECT username_key,display,stable_id,account_status,verified,due,error FROM players'.$where.' ORDER BY username_key LIMIT 25 OFFSET '.(($page-1)*$size),$args);
        }else{
            if(!in_array($kind,['daily','arena'],true))$kind='daily';
            $where=' WHERE e.kind=? AND (e.name LIKE ? OR e.event_id LIKE ?)';$args=[$kind,'%'.$q.'%','%'.$q.'%'];
            if($season){$where.=' AND e.season_id=?';$args[]=$season;}
            $count=(int)$s->run('SELECT COUNT(*) FROM events e'.$where,$args)->fetchColumn();$page=min($page,max(1,(int)ceil($count/$size)));
            $rows=$s->rows('SELECT e.id,e.event_id,e.name,e.kind,e.season_id,e.url,e.status,e.checked,e.due,e.error,e.source_hash,s.name season,(SELECT COUNT(*) FROM results r WHERE r.event_id=e.id) participants,(SELECT COALESCE(SUM(points),0)/100.0 FROM results r WHERE r.event_id=e.id) points FROM events e JOIN seasons s ON s.id=e.season_id'.$where.' ORDER BY e.id DESC LIMIT 25 OFFSET '.(($page-1)*$size),$args);
        }
        reply(['ok'=>true,'csrf'=>$a['csrf'],'username'=>$a['username'],'seasons'=>$s->seasons(),'rows'=>$rows,'total'=>$count,'page'=>$page,'pages'=>max(1,(int)ceil($count/$size)),'progress'=>$r->progress(),'counts'=>['events'=>(int)$s->run('SELECT COUNT(*) FROM events')->fetchColumn(),'players'=>(int)$s->run('SELECT COUNT(*) FROM players')->fetchColumn(),'excluded'=>(int)$s->run("SELECT COUNT(*) FROM players WHERE account_status IN ('closed:fair_play_violations','closed:fair_play','closed:cheating')")->fetchColumn()]]);
    }
    if($action==='arena-index')reply(['ok'=>true]+(new \TeamFrance\Leaderboard\ArenaDiscovery($s))->page((int)($p['season_id']??0),(string)($p['organizer']??''),(int)($p['page']??1),(string)($p['start_date']??''),(string)($p['end_date']??'')));
    if($action==='club-index'){
        $batch=Transport::oauth([['id'=>'index','url'=>BASE.'club/team-france/matches','headers'=>['accept'=>'application/json']]]);
        $result=array_values($batch['results']??[])[0]??null;
        if(!is_array($result)||!is_array($result['json']??null)||(int)$result['status']!==200)throw new RuntimeException('Club match index unavailable.');
        $rows=[];
        foreach(['registered','in_progress','finished'] as $status)foreach($result['json'][$status]??[] as $row){
            if(!is_array($row)||($row['time_class']??'')!=='daily')continue;
            try{[$id,$url]=\TeamFrance\Leaderboard\eventRef('daily',(string)($row['@id']??''));$rows[$id]=['id'=>$id,'name'=>$row['name']??$id,'status'=>$status,'url'=>$url];}catch(Throwable){}
        }
        $assigned=[];
        foreach($s->rows("SELECT e.event_id,s.name season,e.season_id FROM events e JOIN seasons s ON s.id=e.season_id WHERE e.kind='daily'") as $x)$assigned[$x['event_id']]=['season'=>$x['season'],'season_id'=>(int)$x['season_id']];
        foreach($rows as $id=>&$row)$row['assigned']=$assigned[$id]??null;unset($row);
        reply(['ok'=>true,'matches'=>array_values($rows)]);
    }
    if($action==='start')reply(['ok'=>true]+$r->start($a['username'],($p['include_archived']??false)===true));
    if(in_array($action,['pause','resume','stop'],true))reply(['ok'=>true]+$r->control($action,$a['username']));
    if($action==='step')reply(['ok'=>true]+$r->step($a['username']));
    $result=$s->lock(function()use($action,$s,$r,$p){
        if($s->one("SELECT 1 FROM jobs WHERE state='running' AND updated>?",[time()-180]))throw new RuntimeException('Pause the active refresh before editing.');
        switch($action){
            case 'save-season':$s->saveSeason($p);break;
            case 'assign':$s->assign($p);break;
            case 'move':$s->move((int)($p['event']??0),(int)($p['season']??0));break;
            case 'remove':$s->assertEditableEvent((int)($p['event']??0));$s->run('DELETE FROM events WHERE id=?',[(int)($p['event']??0)]);break;
            case 'refresh-event':
                $s->assertEditableEvent((int)($p['event']??0));
                $e=$s->one('SELECT * FROM events WHERE id=?',[(int)($p['event']??0)]);if(!$e)throw new RuntimeException('Event not found.');
                if($e['kind']==='arena')$r->arena($e);else{$b=Transport::oauth([['id'=>'event','url'=>BASE.'match/'.$e['event_id'],'headers'=>['accept'=>'application/json']]]);$x=array_values($b['results']??[])[0]??[];if((int)($x['status']??0)!==200||!is_array($x['json']??null))throw new RuntimeException('OAuth match request failed.');$r->daily($e,$x['json'],true);}break;
            case 'verify-player':
                $key=userKey($p['username']??'');if($key==='')throw new RuntimeException('Invalid username.');
                $b=Transport::oauth([['id'=>'player','url'=>BASE.'player/'.rawurlencode($key),'headers'=>['accept'=>'application/json']]]);$x=array_values($b['results']??[])[0]??[];if((int)($x['status']??0)!==200||!is_array($x['json']??null))throw new RuntimeException('OAuth player request failed.');$s->saveProfile($key,$x['json']);break;
            case 'import-csv':
                $s->assertEditableEvent((int)($_POST['event']??0));
                if(($_POST['confirmed']??'')!=='1'||!isset($_FILES['csv'])||$_FILES['csv']['error']!==UPLOAD_ERR_OK||$_FILES['csv']['size']>12000000||!is_uploaded_file($_FILES['csv']['tmp_name']))throw new RuntimeException('Confirm completed arena and select a valid official Results CSV.');
                $r->importCsv((int)($_POST['event']??0),(string)file_get_contents($_FILES['csv']['tmp_name']));break;
            case 'recalculate': // standings are derived directly from durable results; invalidate revision
                $s->put('updated',(string)time());break;
            default:throw new RuntimeException('Unknown action.');
        }
        $s->put('updated',(string)time());return ['saved'=>true];
    });
    reply(['ok'=>true]+$result);
}catch(Throwable $e){
    error_log('Team France leaderboard: '.$e->getMessage());
    // Public endpoints do not disclose storage paths or database diagnostics.
    reply(['ok'=>false,'error'=>!empty($public)?'Classement temporairement indisponible.':($e instanceof PDOException?'Storage operation failed.':$e->getMessage())],400);
}
