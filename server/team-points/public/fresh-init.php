<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\AnalyticsBuilder;
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
use P2K\Shared\FilesystemCache;

function init_header(string $name): string { return trim((string)($_SERVER['HTTP_'.strtoupper(str_replace('-','_',$name))]??'')); }
function init_body(): array {
    $raw=file_get_contents('php://input'); if($raw===false||$raw==='')throw new ApiException('Empty request body.',400,'INIT_EMPTY_BODY');
    if(strtolower(trim((string)($_SERVER['HTTP_CONTENT_ENCODING']??'')))==='gzip'){$decoded=gzdecode($raw);if($decoded===false)throw new ApiException('Invalid gzip request body.',400,'INIT_INVALID_GZIP');$raw=$decoded;}
    $data=json_decode($raw,true);if(!is_array($data))throw new ApiException('Invalid JSON request body.',400,'INIT_INVALID_JSON');return [$data,$raw];
}
function init_runtime(array $config): string {
    $storage=is_array($config['storage']??null)?$config['storage']:[];$dir=FilesystemCache::runtimeRoot($storage).'/fresh-init';FilesystemCache::ensureProtectedDirectory($dir);return $dir;
}
function init_auth(array $config,array $payload,string $plain): void {
    $ts=init_header('X-P2K-Init-Timestamp');$nonce=init_header('X-P2K-Init-Nonce');$sig=strtolower(init_header('X-P2K-Init-Signature'));$runHeader=init_header('X-P2K-Init-Run');$runId=trim((string)($payload['run_id']??''));
    if($ts===''||$nonce===''||$sig===''||$runId===''||$runHeader!==$runId)throw new ApiException('Missing initializer authentication headers.',401,'INIT_AUTH_REQUIRED');
    if(!preg_match('/^[a-f0-9]{32}$/',$nonce)||!preg_match('/^[a-f0-9]{64}$/',$sig)||!preg_match('/^[a-f0-9-]{36}$/i',$runId))throw new ApiException('Malformed initializer authentication.',401,'INIT_AUTH_INVALID');
    if(abs(time()-(int)$ts)>300)throw new ApiException('Initializer request expired.',401,'INIT_AUTH_EXPIRED');
    $secret=(string)($config['app']['init_token']??'');if($secret==='')$secret=(string)($config['app']['admin_token']??'');
    if($secret===''||str_starts_with($secret,'CHANGE_'))throw new ApiException('Initializer token is not configured.',503,'INIT_TOKEN_MISSING');
    if($secret!==trim($secret))throw new ApiException('Initializer token in config.local.php contains leading or trailing whitespace.',503,'INIT_TOKEN_WHITESPACE');
    $expected=hash_hmac('sha256',$ts."\n".$nonce."\n".hash('sha256',$plain),$secret);if(!hash_equals($expected,$sig))throw new ApiException('Initializer signature is invalid.',403,'INIT_AUTH_FAILED');
    $dir=init_runtime($config).'/nonces';FilesystemCache::ensureProtectedDirectory($dir);$path=$dir.'/'.$nonce;
    if(is_file($path))throw new ApiException('Initializer nonce was already used.',409,'INIT_REPLAY_BLOCKED');
    if(@file_put_contents($path,(string)time(),LOCK_EX)===false)throw new ApiException('Unable to persist initializer replay protection.',503,'INIT_RUNTIME_UNWRITABLE');
    foreach(glob($dir.'/*')?:[] as $old){if(is_file($old)&&@filemtime($old)<time()-86400)@unlink($old);}
}
function init_sort(mixed $v): mixed {if(!is_array($v))return $v;$list=array_keys($v)===range(0,count($v)-1);if($list)return array_map('init_sort',$v);ksort($v);foreach($v as $k=>$x)$v[$k]=init_sort($x);return $v;}
function init_verify_manifest(array $m): void {
    if(($m['format']??'')!=='p2k-team-points-seed-v1')throw new ApiException('Unsupported initializer manifest.',422,'INIT_MANIFEST_FORMAT');$provided=strtolower(trim((string)($m['manifest_sha256']??'')));if(!preg_match('/^[a-f0-9]{64}$/',$provided))throw new ApiException('Manifest checksum is missing.',422,'INIT_MANIFEST_CHECKSUM');
    $copy=$m;unset($copy['manifest_sha256']);$canonical=json_encode(init_sort($copy),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($canonical)||!hash_equals($provided,hash('sha256',$canonical)))throw new ApiException('Manifest checksum mismatch.',422,'INIT_MANIFEST_CHECKSUM');
    $c=is_array($m['counts']??null)?$m['counts']:[];foreach(['members','matches','finished_matches','boards','events'] as $k)if((int)($c[$k]??0)<=0)throw new ApiException("Manifest count {$k} must be positive.",422,'INIT_MANIFEST_COUNTS');
}
function init_state_path(array $config): string {return init_runtime($config).'/state.json';}
function init_read_state(array $config): array {$p=init_state_path($config);if(!is_file($p))return [];$x=json_decode((string)@file_get_contents($p),true);return is_array($x)?$x:[];}
function init_write_state(array $config,array $state): void {$state['updated_at']=gmdate('c');if(@file_put_contents(init_state_path($config),json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new ApiException('Unable to write initializer state.',503,'INIT_RUNTIME_UNWRITABLE');}
function init_coverage_dir(array $config,string $runId): string {$dir=init_runtime($config).'/coverage/'.$runId;FilesystemCache::ensureProtectedDirectory($dir);return $dir;}
function init_reset_coverage(array $config,string $runId): void {
    $dir=init_coverage_dir($config,$runId);foreach(['members','matches','boards'] as $kind){$p=$dir.'/'.$kind.'.jsonl';if(is_file($p)&&!@unlink($p))throw new ApiException('Unable to reset initializer coverage receipts.',503,'INIT_RUNTIME_UNWRITABLE');}
}
function init_record_coverage(array $config,string $runId,string $kind,array $rows): void {
    $path=init_coverage_dir($config,$runId).'/'.$kind.'.jsonl';$lines=[];
    foreach($rows as $r){
        if($kind==='members'){$key=strtolower(trim((string)($r['username_key']??'')));if($key!=='')$lines[]=json_encode([$key],JSON_UNESCAPED_SLASHES);}
        elseif($kind==='matches'){$id=(int)($r['match_id']??0);if($id>0)$lines[]=json_encode([$id,(string)($r['status']??'unknown'),!empty($r['is_void'])?1:0,max(0,(int)($r['competition_points']??0))],JSON_UNESCAPED_SLASHES);}
        elseif($kind==='boards'){$mid=(int)($r['match_id']??0);$url=(string)($r['board_url']??'');if($mid>0&&$url!=='')$lines[]=json_encode([$mid,init_board_no($url),max(0,min(2,(int)($r['finished_count']??0)))],JSON_UNESCAPED_SLASHES);}
    }
    if($lines&&@file_put_contents($path,implode("\n",$lines)."\n",FILE_APPEND|LOCK_EX)===false)throw new ApiException('Unable to persist initializer coverage receipts.',503,'INIT_RUNTIME_UNWRITABLE');
}
function init_read_coverage_file(string $path): array {if(!is_file($path))return [];$out=[];$fh=@fopen($path,'rb');if(!$fh)return [];while(($line=fgets($fh))!==false){$row=json_decode(trim($line),true);if(is_array($row))$out[]=$row;}fclose($fh);return $out;}
function init_coverage_summary(array $config,string $runId): array {
    $dir=init_coverage_dir($config,$runId);
    $members=[];foreach(init_read_coverage_file($dir.'/members.jsonl') as $r){$k=(string)($r[0]??'');if($k!=='')$members[$k]=1;}
    $matches=[];foreach(init_read_coverage_file($dir.'/matches.jsonl') as $r){$id=(int)($r[0]??0);if($id>0)$matches[$id]=['status'=>(string)($r[1]??'unknown'),'is_void'=>(int)($r[2]??0),'competition_points'=>(int)($r[3]??0)];}
    $boards=[];foreach(init_read_coverage_file($dir.'/boards.jsonl') as $r){$mid=(int)($r[0]??0);$no=(int)($r[1]??0);if($mid>0&&$no>0)$boards[$mid.':'.$no]=max(0,min(2,(int)($r[2]??0)));}
    $finished=0;$void=0;$points=0;foreach($matches as $m){if($m['status']==='finished'){$finished++;if($m['is_void'])$void++;else $points+=(int)$m['competition_points'];}}
    return ['members'=>count($members),'matches'=>count($matches),'finished_matches'=>$finished,'void_matches'=>$void,'boards'=>count($boards),'events'=>array_sum($boards),'club_points'=>$points];
}
function init_validate_snapshot_coverage(array $coverage,array $manifest): void {$c=$manifest['counts']??[];$errors=[];foreach(['members','matches','finished_matches','void_matches','boards','events','club_points'] as $k){if((int)($coverage[$k]??-1)!==(int)($c[$k]??-2))$errors[]="{$k}: expected ".($c[$k]??'missing').", received ".($coverage[$k]??'missing');}if($errors)throw new ApiException('Initializer snapshot coverage failed: '.implode('; ',$errors),422,'INIT_COVERAGE_MISMATCH');}
function init_validate_live_floor(array $actual,array $manifest): void {$c=$manifest['counts']??[];$errors=[];foreach(['members','matches','finished_matches','void_matches','boards','events','club_points'] as $k){if((int)($actual[$k]??-1)<(int)($c[$k]??PHP_INT_MAX))$errors[]="{$k}: snapshot minimum ".($c[$k]??'missing').", live DB has ".($actual[$k]??'missing');}if($errors)throw new ApiException('Initializer live-data validation failed: '.implode('; ',$errors),422,'INIT_LIVE_FLOOR_MISMATCH');}
function init_pause_team_points(PDO $core): void {try{$q=$core->prepare("UPDATE p2k_control_tasks SET status='paused',pause_requested=1,last_message='Fresh v2.8.0 initialization in progress.',updated_at=UTC_TIMESTAMP() WHERE task_key='team-points'");$q->execute();}catch(Throwable){/* Table may not exist before first schema installation. */}}
function init_table_count(PDO $pdo): int {return (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();}
function init_database_objects(PDO $pdo): array {
    $q=$pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name');
    $rows=$q->fetchAll(PDO::FETCH_COLUMN)?:[];$out=[];foreach($rows as $name){$name=(string)$name;if($name!=='')$out[]=$name;}return $out;
}
function init_expected_schema_objects(string $path): array {
    $sql=@file_get_contents($path);if($sql===false)throw new ApiException('Unable to read v2.8 schema definition.',500,'INIT_SCHEMA_READ');
    preg_match_all('/CREATE\s+(?:TABLE\s+IF\s+NOT\s+EXISTS|VIEW)\s+`?([A-Za-z0-9_]+)`?/i',$sql,$matches);
    $names=array_values(array_unique(array_map('strval',$matches[1]??[])));sort($names,SORT_STRING);return $names;
}
function init_exact_v280_schema(PDO $pdo,string $schemaPath): bool {
    $actual=init_database_objects($pdo);$expected=init_expected_schema_objects($schemaPath);
    // information_schema ORDER BY follows the database collation, while the schema parser
    // uses PHP bytewise sorting. Object identity must therefore be compared as sets, not
    // as collation-dependent ordered arrays.
    sort($actual,SORT_STRING);sort($expected,SORT_STRING);return $actual===$expected;
}
function init_schema_diff(PDO $pdo,string $schemaPath): array {
    $actual=init_database_objects($pdo);$expected=init_expected_schema_objects($schemaPath);
    return ['actual'=>count($actual),'expected'=>count($expected),'missing'=>array_values(array_diff($expected,$actual)),'extra'=>array_values(array_diff($actual,$expected))];
}
function init_already_done(PDO $core): bool {try{return (int)$core->query('SELECT COUNT(*) FROM p2k_core_initialization')->fetchColumn()>0;}catch(Throwable){return false;}}
function init_counts(PDO $core,string $club): array {
    $one=function(string $sql,array $args=[])use($core):int{$q=$core->prepare($sql);$q->execute($args);return(int)$q->fetchColumn();};
    return [
      'members'=>$one('SELECT COUNT(*) FROM p2k_tp_members WHERE club_slug=? AND current_member=1',[$club]),
      'member_identities'=>$one('SELECT COUNT(*) FROM p2k_tp_members WHERE club_slug=?',[$club]),
      'historical_only_members'=>$one('SELECT COUNT(*) FROM p2k_tp_members WHERE club_slug=? AND current_member=0',[$club]),
      'matches'=>$one('SELECT COUNT(*) FROM p2k_tp_match_metadata WHERE club_slug=?',[$club]),
      'finished_matches'=>$one("SELECT COUNT(*) FROM p2k_tp_match_metadata WHERE club_slug=? AND status='finished'",[$club]),
      'void_matches'=>$one("SELECT COUNT(*) FROM p2k_tp_match_metadata WHERE club_slug=? AND status='finished' AND is_void=1",[$club]),
      'boards'=>$one('SELECT COUNT(*) FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=?',[$club]),
      'events'=>$one('SELECT COUNT(*) FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=?',[$club]),
      'club_points'=>$one("SELECT COALESCE(SUM(competition_points),0) FROM p2k_tp_match_metadata WHERE club_slug=? AND status='finished' AND is_void=0",[$club]),
      'finished'=>$one("SELECT COUNT(*) FROM p2k_tp_match_metadata WHERE club_slug=? AND status='finished'",[$club]),
      'in_progress'=>$one("SELECT COUNT(*) FROM p2k_tp_match_metadata WHERE club_slug=? AND status='in_progress'",[$club]),
      'registered'=>$one("SELECT COUNT(*) FROM p2k_tp_match_metadata WHERE club_slug=? AND status='registered'",[$club]),
      'unknown'=>$one("SELECT COUNT(*) FROM p2k_tp_match_metadata WHERE club_slug=? AND status='unknown'",[$club]),
    ];
}
function init_sql_time(int $epoch): ?string {return $epoch>0?gmdate('Y-m-d H:i:s',$epoch):null;}
function init_board_no(string $url): int {if(preg_match('~/match/\d+/(\d+)/?$~i',$url,$m))return max(1,(int)$m[1]);throw new ApiException('Invalid board URL in initializer payload.',422,'INIT_BOARD_URL');}
function init_points_x2(mixed $points): int {return max(0,min(2,(int)round(((float)$points)*2)));}
function init_upload_members(PDO $core,string $club,array $rows): void {
    $q=$core->prepare("INSERT INTO p2k_tp_members(club_slug,username_key,username,current_member,joined_at,first_seen_at,last_seen_at) VALUES(?,?,?,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),current_member=1,joined_at=COALESCE(VALUES(joined_at),joined_at),last_seen_at=UTC_TIMESTAMP()");
    foreach($rows as $r){$key=strtolower(trim((string)($r['username_key']??'')));$user=trim((string)($r['username']??''));if($key===''||$user==='')throw new ApiException('Initializer member row is malformed.',422,'INIT_MEMBER_ROW');$q->execute([$club,$key,$user,init_sql_time((int)($r['joined_epoch']??0))]);}
}
function init_upload_matches(PDO $core,string $club,array $rows): void {
    $opp=$core->prepare("INSERT INTO p2k_tp_opponents(club_slug,opponent_slug,display_name,club_url,first_seen_at,last_seen_at) VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),club_url=COALESCE(VALUES(club_url),club_url),last_seen_at=UTC_TIMESTAMP()");
    $q=$core->prepare("INSERT INTO p2k_tp_match_metadata(club_slug,match_id,match_name,match_url,status,rules,time_control,is_league,start_time,end_time,board_count,p2k_score,opponent_score,result,competition_points,is_void,opponent_slug,opponent_name,opponent_url,discovery_source,last_verified_at,last_index_seen_at,next_detail_check_at,finalized_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'fresh_init',UTC_TIMESTAMP(),UTC_TIMESTAMP(),?, ?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE match_name=VALUES(match_name),match_url=VALUES(match_url),status=VALUES(status),rules=VALUES(rules),time_control=VALUES(time_control),is_league=VALUES(is_league),start_time=VALUES(start_time),end_time=VALUES(end_time),board_count=VALUES(board_count),p2k_score=VALUES(p2k_score),opponent_score=VALUES(opponent_score),result=VALUES(result),competition_points=VALUES(competition_points),is_void=VALUES(is_void),opponent_slug=VALUES(opponent_slug),opponent_name=VALUES(opponent_name),opponent_url=VALUES(opponent_url),last_verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()");
    foreach($rows as $r){$id=(int)($r['match_id']??0);if($id<=0)throw new ApiException('Initializer match row has no match ID.',422,'INIT_MATCH_ROW');$slug=strtolower(trim((string)($r['opponent_slug']??'')));if($slug!=='')$opp->execute([$club,$slug,(string)($r['opponent_name']??$slug),$r['opponent_url']??null]);$status=(string)($r['status']??'unknown');$start=init_sql_time((int)($r['start_epoch']??0));$end=init_sql_time((int)($r['end_epoch']??0));$next=$status==='finished'?null:gmdate('Y-m-d H:i:s');$final=$status==='finished'?$end:null;$q->execute([$club,$id,(string)($r['match_name']??''),$r['match_url']??null,$status,$r['rules']??null,$r['time_control']??null,!empty($r['is_league'])?1:0,$start,$end,max(0,(int)($r['board_count']??0)),(float)($r['p2k_score']??0),(float)($r['opponent_score']??0),(string)($r['result']??'unknown'),max(0,(int)($r['competition_points']??0)),!empty($r['is_void'])?1:0,$slug!==''?$slug:null,$r['opponent_name']??null,$r['opponent_url']??null,$next,$final]);}
}
function init_upload_boards(PDO $core,string $club,array $rows): void {
    $member=$core->prepare('SELECT member_id FROM p2k_tp_members WHERE club_slug=? AND username_key=? LIMIT 1');
    $historicalMember=$core->prepare("INSERT INTO p2k_tp_members(club_slug,username_key,username,current_member,joined_at,first_seen_at,last_seen_at) VALUES(?,?,?,0,NULL,?,?) ON DUPLICATE KEY UPDATE username=VALUES(username),first_seen_at=LEAST(first_seen_at,VALUES(first_seen_at)),last_seen_at=GREATEST(last_seen_at,VALUES(last_seen_at))");
    $match=$core->prepare('SELECT status,start_time FROM p2k_tp_match_metadata WHERE club_slug=? AND match_id=? LIMIT 1');
    // Return the board_id atomically for both a fresh insert and an idempotent replay.
    // match_id + board_no is the canonical Chess.com board identity; member_id + match_id
    // remains a uniqueness invariant for the P2K participant attached to that board.
    $board=$core->prepare("INSERT INTO p2k_tp_boards(member_id,match_id,board_no,board_url_override,source_bucket,state,finished_game_count,first_discovered_at,last_discovered_at,last_checked_at,next_check_at,completed_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,?) ON DUPLICATE KEY UPDATE board_id=LAST_INSERT_ID(board_id),source_bucket=VALUES(source_bucket),state=VALUES(state),finished_game_count=VALUES(finished_game_count),last_discovered_at=UTC_TIMESTAMP(),last_checked_at=UTC_TIMESTAMP(),next_check_at=VALUES(next_check_at),completed_at=VALUES(completed_at)");
    $findBoardCanonical=$core->prepare('SELECT board_id,member_id FROM p2k_tp_boards WHERE match_id=? AND board_no=? LIMIT 1');
    $repairBoardOwner=$core->prepare('UPDATE p2k_tp_boards SET member_id=? WHERE board_id=?');
    $game=$core->prepare("INSERT INTO p2k_tp_games(board_id,sequence_no,game_id,game_url_override,game_end_utc,result_code,points_x2,source_hash,verified_at,is_seed) VALUES(?,?,NULL,NULL,?,?,?,UNHEX(SHA2(?,256)),UTC_TIMESTAMP(),1) ON DUPLICATE KEY UPDATE game_end_utc=VALUES(game_end_utc),result_code=VALUES(result_code),points_x2=VALUES(points_x2),source_hash=VALUES(source_hash),verified_at=UTC_TIMESTAMP(),is_seed=1");
    foreach($rows as $r){
        $key=strtolower(trim((string)($r['username_key']??'')));
        $username=trim((string)($r['username']??$key));
        $mid=(int)($r['match_id']??0);
        $url=(string)($r['board_url']??'');
        if($key===''||$username==='')throw new ApiException('Board row has no player identity.',422,'INIT_BOARD_MEMBER');
        $match->execute([$club,$mid]);
        $mr=$match->fetch(PDO::FETCH_ASSOC);
        if(!is_array($mr))throw new ApiException("Board row references unknown match {$mid}.",422,'INIT_BOARD_MATCH');
        $startEpochs=array_values(array_filter([(int)($r['start1']??0),(int)($r['start2']??0)],fn($x)=>$x>0));
        $endEpochs=array_values(array_filter([(int)($r['end1']??0),(int)($r['end2']??0)],fn($x)=>$x>0));
        $firstEpoch=$startEpochs?min($startEpochs):0;
        $lastEpoch=$endEpochs?max($endEpochs):($startEpochs?max($startEpochs):0);
        $first=init_sql_time($firstEpoch)??($mr['start_time']??null)??gmdate('Y-m-d H:i:s');
        $last=init_sql_time($lastEpoch)??$first;
        $member->execute([$club,$key]);
        $memberId=(int)($member->fetchColumn()?:0);
        if($memberId<=0){
            // Historical participants may no longer be in the current club roster.
            // Preserve them as canonical identities without falsely marking them current.
            $historicalMember->execute([$club,$key,$username,$first,$last]);
            $member->execute([$club,$key]);
            $memberId=(int)($member->fetchColumn()?:0);
        }
        if($memberId<=0)throw new ApiException("Unable to create historical member {$key}.",500,'INIT_BOARD_MEMBER_CREATE');
        $finished=max(0,min(2,(int)($r['finished_count']??0)));
        $status=(string)($mr['status']??'unknown');
        $state=$finished>=2?'complete_immutable':($finished===1?'potentially_incomplete':'recent_in_progress');
        $completeEpoch=max((int)($r['end1']??0),(int)($r['end2']??0));
        $complete=$finished>=2?init_sql_time($completeEpoch):null;
        $next=$finished>=2?null:gmdate('Y-m-d H:i:s',time()+21600);
        $boardNo=init_board_no($url);
        $board->execute([$memberId,$mid,$boardNo,null,(string)($r['source_bucket']??$status),$state,$finished,$first,$next,$complete]);
        $bid=(int)$core->lastInsertId();
        $storedMemberId=$memberId;
        if($bid<=0){
            // Some MariaDB/PDO combinations may not expose LAST_INSERT_ID() after an
            // idempotent duplicate-key replay. Resolve by the canonical board key.
            $findBoardCanonical->execute([$mid,$boardNo]);
            $br=$findBoardCanonical->fetch(PDO::FETCH_ASSOC);
            if(is_array($br)){$bid=(int)($br['board_id']??0);$storedMemberId=(int)($br['member_id']??0);}
        } else {
            $findBoardCanonical->execute([$mid,$boardNo]);
            $br=$findBoardCanonical->fetch(PDO::FETCH_ASSOC);
            if(is_array($br))$storedMemberId=(int)($br['member_id']??$memberId);
        }
        if($bid<=0)throw new ApiException("Unable to resolve inserted board {$mid}/{$boardNo} for {$key}.",500,'INIT_BOARD_INSERT');
        if($storedMemberId!==$memberId){
            // The embedded snapshot is prevalidated to contain exactly one P2K member
            // per (match, board) and one board per (member, match). Therefore a mismatch
            // can only be stale partial-import state and is safe to reconcile.
            try{$repairBoardOwner->execute([$memberId,$bid]);}
            catch(Throwable $e){throw new ApiException("Unable to reconcile board {$mid}/{$boardNo} owner for {$key}: ".$e->getMessage(),500,'INIT_BOARD_OWNER');}
        }
        for($n=1;$n<=2;$n++){
            $end=(int)($r['end'.$n]??0);$res=strtolower(trim((string)($r['result'.$n]??'')));
            if($end<=0||$res==='')continue;
            $pointsX2=init_points_x2($r['points'.$n]??0);
            $identity=implode('|',[$club,$key,$mid,init_board_no($url),$n,$end,$res,$pointsX2]);
            $game->execute([$bid,$n,init_sql_time($end),$res,$pointsX2,$identity]);
        }
    }
}

try {
    header('Content-Type: application/json; charset=utf-8');if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'))!=='POST')throw new ApiException('POST is required.',405,'METHOD_NOT_ALLOWED');
    [$payload,$plain]=init_body();$config=p2k_tp_config();init_auth($config,$payload,$plain);$runId=trim((string)($payload['run_id']??''));$action=strtolower(trim((string)($payload['action']??'status')));$club=strtolower(trim((string)($config['app']['club_slug']??'promote-to-king')));
    $core=Database::core();$analytics=Database::analytics();$repo=new Repository($core,$analytics);$state=init_read_state($config);init_pause_team_points($core);
    if($action==='begin'){
        $manifest=is_array($payload['manifest']??null)?$payload['manifest']:[];init_verify_manifest($manifest);
        if(init_already_done($core))throw new ApiException('These Core/Analytics databases have already been initialized. v2.8.0 initialization is fresh-only.',409,'INIT_ALREADY_DONE');
        if(!$state){
            $root=dirname(__DIR__);$coreSchema=$root.'/sql/core-schema.sql';$analyticsSchema=$root.'/sql/analytics-schema.sql';
            $coreTables=init_table_count($core);$analyticsTables=init_table_count($analytics);
            $emptyPair=($coreTables===0&&$analyticsTables===0);
            $exactExisting=(!$emptyPair&&init_exact_v280_schema($core,$coreSchema)&&init_exact_v280_schema($analytics,$analyticsSchema));
            if(!$emptyPair&&!$exactExisting){
                $cd=init_schema_diff($core,$coreSchema);$ad=init_schema_diff($analytics,$analyticsSchema);
                $detail='Core '.$cd['actual'].'/'.$cd['expected'].' objects; Analytics '.$ad['actual'].'/'.$ad['expected'].' objects.';
                if($cd['extra'])$detail.=' Core unexpected: '.implode(', ',array_slice($cd['extra'],0,8)).'.';
                if($ad['extra'])$detail.=' Analytics unexpected: '.implode(', ',array_slice($ad['extra'],0,8)).'.';
                if($cd['missing'])$detail.=' Core missing: '.implode(', ',array_slice($cd['missing'],0,8)).'.';
                if($ad['missing'])$detail.=' Analytics missing: '.implode(', ',array_slice($ad['missing'],0,8)).'.';
                throw new ApiException('Fresh initialization requires either two empty databases or the exact unsealed v2.8.0 schema created by an interrupted initializer. '.$detail,409,'INIT_DATABASE_NOT_EMPTY');
            }
            // Exact object identity is sufficient for recovery even when an interrupted DDL run
            // created the version tables but did not yet insert their version rows. installSchema()
            // below is idempotent and will complete those markers before we continue.
            // Persist recovery state BEFORE DDL. A timeout during schema creation can
            // therefore resume using this manifest instead of stranding partial DDL.
            $state=['run_id'=>$runId,'manifest'=>$manifest,'manifest_sha256'=>$manifest['manifest_sha256'],'started_at'=>gmdate('c'),'tool_version'=>(string)($manifest['tool_version']??'unknown'),'phase'=>'schema_pending','adopted_existing_schema'=>$exactExisting];
            if($exactExisting){$state['adopted_at']=gmdate('c');$state['adopted_core_objects']=$coreTables;$state['adopted_analytics_objects']=$analyticsTables;}
            init_write_state($config,$state);
            $repo->installSchema();
            if(!$repo->schemaInstalled())throw new ApiException('v2.8.0 schema installation did not complete.',500,'INIT_SCHEMA_INCOMPLETE');
            $state['phase']='schema_ready';init_write_state($config,$state);
        }else{
            if(($state['manifest_sha256']??'')!==($manifest['manifest_sha256']??''))throw new ApiException('Another initialization snapshot is already associated with these databases.',409,'INIT_RUN_MISMATCH');
            // A local retry creates a fresh transport run UUID. For the identical
            // checksum-verified snapshot, adopt that UUID and let idempotent Core
            // upserts resume from whatever batches reached the server previously.
            if(($state['run_id']??'')!==$runId){$state['previous_run_id']=$state['run_id']??null;$state['run_id']=$runId;$state['resumed_at']=gmdate('c');init_write_state($config,$state);}
            if(!$repo->schemaInstalled())$repo->installSchema();
        }
        init_pause_team_points($core);
        init_reset_coverage($config,$runId);
        Http::json(['ok'=>true,'architecture'=>'core+analytics+filesystem','team_points_paused'=>true,'core_schema'=>$repo->schemaVersion(),'analytics_schema'=>$repo->analyticsSchemaVersion(),'counts'=>init_counts($core,$club)]);
    }
    if(!$state||($state['run_id']??'')!==$runId)throw new ApiException('Unknown initializer run. Start with begin.',404,'INIT_RUN_NOT_FOUND');if(init_already_done($core)&&$action!=='status')throw new ApiException('Initialization has already completed.',409,'INIT_ALREADY_DONE');
    $manifest=is_array($state['manifest']??null)?$state['manifest']:[];
    if($action==='status'){Http::json(['ok'=>true,'initialized'=>init_already_done($core),'counts'=>init_counts($core,$club),'core_schema'=>$repo->schemaVersion(),'analytics_schema'=>$repo->analyticsSchemaVersion()]);}
    if($action==='upload'){$kind=strtolower(trim((string)($payload['kind']??'')));$rows=is_array($payload['rows']??null)?$payload['rows']:[];if(!in_array($kind,['members','matches','boards'],true)||!$rows)throw new ApiException('Valid kind and non-empty rows are required.',400,'INIT_UPLOAD_INVALID');if(count($rows)>1000)throw new ApiException('Initializer batch exceeds 1000 rows.',413,'INIT_BATCH_TOO_LARGE');$core->beginTransaction();try{if($kind==='members')init_upload_members($core,$club,$rows);elseif($kind==='matches')init_upload_matches($core,$club,$rows);else init_upload_boards($core,$club,$rows);$core->commit();}catch(Throwable $e){if($core->inTransaction())$core->rollBack();throw $e;}init_record_coverage($config,$runId,$kind,$rows);Http::json(['ok'=>true,'kind'=>$kind,'received'=>count($rows)]);}
    if($action==='validate'){$counts=init_counts($core,$club);$coverage=init_coverage_summary($config,$runId);init_validate_snapshot_coverage($coverage,$manifest);init_validate_live_floor($counts,$manifest);Http::json(['ok'=>true,'counts'=>$counts,'snapshot_coverage'=>$coverage,'live_drift'=>['members'=>$counts['members']-$coverage['members'],'matches'=>$counts['matches']-$coverage['matches'],'boards'=>$counts['boards']-$coverage['boards'],'events'=>$counts['events']-$coverage['events']]]);}
    if($action==='finalize'){
        if((string)($payload['confirm']??'')!=='INITIALIZE-P2K-2.8.0')throw new ApiException('Final confirmation phrase is missing.',409,'INIT_CONFIRM_REQUIRED');$counts=init_counts($core,$club);$coverage=init_coverage_summary($config,$runId);init_validate_snapshot_coverage($coverage,$manifest);init_validate_live_floor($counts,$manifest);
        $builder=new AnalyticsBuilder($core,$analytics);$build=$builder->rebuildAll($club);$initId=$runId;$snapshot=max(1,(int)($manifest['snapshot_epoch']??time()));$sha=(string)$manifest['manifest_sha256'];$tool=(string)($manifest['tool_version']??'unknown');
        // Analytics is rebuildable. Make its audit insert idempotent so a failed
        // Core-side finalization can be retried safely.
        $qa=$analytics->prepare('INSERT INTO p2k_analytics_initialization(initialization_id,snapshot_epoch,manifest_sha256,source_core_watermark,initialized_at,tool_version) VALUES(?,?,?,?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE snapshot_epoch=VALUES(snapshot_epoch),manifest_sha256=VALUES(manifest_sha256),source_core_watermark=VALUES(source_core_watermark),initialized_at=UTC_TIMESTAMP(),tool_version=VALUES(tool_version)');$qa->execute([$initId,$snapshot,$sha,$build['watermark']??null,$tool]);
        $jobId=p2k_tp_uuid();$unknown=$core->prepare("SELECT match_id FROM p2k_tp_match_metadata WHERE club_slug=? AND status='unknown' ORDER BY match_id");$unknown->execute([$club]);$ids=array_map('intval',$unknown->fetchAll(PDO::FETCH_COLUMN)?:[]);$total=2+count($ids);
        // Seal Core only after the paused catch-up job is created. All Core-side
        // finalization writes share one transaction, so a failure before commit
        // leaves the initialization resumable.
        $core->beginTransaction();
        try {
            $core->prepare("UPDATE p2k_control_tasks SET status='paused',pause_requested=1,last_message='Fresh v2.8.0 initialization complete; resume after validation.',updated_at=UTC_TIMESTAMP() WHERE task_key='team-points'")->execute();
            $core->prepare("INSERT INTO p2k_tp_jobs(id,club_slug,job_type,status,stop_requested,processed_items,total_items,created_at,updated_at) VALUES(?,?,'incremental_sync','paused',0,0,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$jobId,$club,$total]);
            $item=$core->prepare("INSERT INTO p2k_tp_job_items(job_id,item_type,item_key,payload_json,status,available_at,updated_at) VALUES(?,?,?,?, 'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $item->execute([$jobId,'sync_club_matches','post-init:club-matches',json_encode(['club_slug'=>$club,'post_init'=>true],JSON_UNESCAPED_SLASHES)]);
            $item->execute([$jobId,'sync_members','post-init:members',json_encode(['club_slug'=>$club,'post_init'=>true],JSON_UNESCAPED_SLASHES)]);
            foreach($ids as $id)$item->execute([$jobId,'sync_match','post-init:unknown:'.$id,json_encode(['match_id'=>$id,'source'=>'init_unknown_repair'],JSON_UNESCAPED_SLASHES)]);
            $q=$core->prepare('INSERT INTO p2k_core_initialization(initialization_id,snapshot_epoch,manifest_sha256,members,matches,boards,games,club_points,initialized_at,tool_version) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),?)');
            $mc=is_array($manifest['counts']??null)?$manifest['counts']:[];$q->execute([$initId,$snapshot,$sha,(int)($mc['members']??0),(int)($mc['matches']??0),(int)($mc['boards']??0),(int)($mc['events']??0),(int)($mc['club_points']??0),$tool]);
            $core->commit();
        } catch (Throwable $e) {
            if($core->inTransaction())$core->rollBack();
            throw $e;
        }
        $state['completed_at']=gmdate('c');$state['counts']=$counts;$state['snapshot_coverage']=$coverage;$state['job_id']=$jobId;init_write_state($config,$state);Http::json(['ok'=>true,'counts'=>$counts,'snapshot_coverage'=>$coverage,'analytics'=>$build,'job_id'=>$jobId,'unknown_match_repairs_queued'=>$ids,'team_points_paused'=>true]);
    }
    throw new ApiException('Unknown initializer action.',404,'INIT_UNKNOWN_ACTION');
} catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}catch(Throwable $e){error_log('P2K v2.8 fresh init: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'INIT_SERVER_ERROR','message'=>$e->getMessage()]],500);}
