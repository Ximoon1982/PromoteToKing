<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$host=getenv('P2K_TEST_DB_HOST')?:'127.0.0.1';
$port=(int)(getenv('P2K_TEST_DB_PORT')?:3306);
$user=getenv('P2K_TEST_DB_USER')?:'root';
$pass=getenv('P2K_TEST_DB_PASS')?:'root';
$coreName='p2k_v2132_core';
$analyticsName='p2k_v2132_analytics';

function failTest(string $message): never { fwrite(STDERR,"FAIL: {$message}\n"); exit(1); }
function ok(bool $condition,string $message): void { if(!$condition)failTest($message); echo "OK: {$message}\n"; }
function splitSql(string $sql): array {
    $out=[];$buf='';
    foreach(preg_split('/\R/',$sql)?:[] as $line){
        if(preg_match('/^\s*--/',$line))continue;
        $buf.=$line."\n";
        if(substr(rtrim($line),-1)===';'){$s=trim($buf);if($s!=='')$out[]=rtrim($s,";\r\n ");$buf='';}
    }
    if(trim($buf)!=='')$out[]=trim($buf);
    return $out;
}
function execSchema(PDO $pdo,string $sql): void { foreach(splitSql($sql) as $statement)$pdo->exec($statement); }
function columnExists(PDO $pdo,string $table,string $column): bool {
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $q->execute([$table,$column]);return (int)$q->fetchColumn()>0;
}
function indexExists(PDO $pdo,string $table,string $index): bool {
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
    $q->execute([$table,$index]);return (int)$q->fetchColumn()>0;
}
function row(PDO $pdo,int $matchId): array {
    $q=$pdo->prepare('SELECT match_id,max_rating,max_rating_state FROM p2k_g_matches WHERE match_id=?');
    $q->execute([$matchId]);return $q->fetch(PDO::FETCH_ASSOC)?:[];
}
function payload(int $matchId,array $settings=[],string $status='finished'): array {
    return [
        'url'=>'https://www.chess.com/club/matches/'.$matchId,
        'name'=>'v2.13.2 rating-cap test '.$matchId,
        'status'=>$status,
        'boards'=>2,
        'settings'=>['time_class'=>'daily','rules'=>'chess','time_control'=>'1/86400']+$settings,
        'teams'=>[
            ['@id'=>'https://api.chess.com/pub/club/promote-to-king','url'=>'https://www.chess.com/club/promote-to-king','name'=>'Promote to King','score'=>2],
            ['@id'=>'https://api.chess.com/pub/club/schema-test-opponent','url'=>'https://www.chess.com/club/schema-test-opponent','name'=>'Schema Test Opponent','score'=>0],
        ],
    ];
}

$admin=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach([$coreName,$analyticsName] as $db){$admin->exec("DROP DATABASE IF EXISTS `{$db}`");$admin->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");}
$core=new PDO("mysql:host={$host};port={$port};dbname={$coreName};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$analytics=new PDO("mysql:host={$host};port={$port};dbname={$analyticsName};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

$greenCore=file_get_contents($root.'/server/team-points-green/sql/core-schema.sql');
$greenAnalytics=file_get_contents($root.'/server/team-points-green/sql/analytics-schema.sql');
if($greenCore===false||$greenAnalytics===false)failTest('unable to read Green schemas');
// Reconstruct the qualified v2.13.1 shape: max_rating exists, provenance state/index do not.
$greenCore=str_replace("  max_rating_state ENUM('unknown','capped','open','unavailable') NOT NULL DEFAULT 'unknown',\n",'', $greenCore);
$greenCore=str_replace("  KEY idx_g_match_discovered (verified_club_slug,club_verified,created_at,match_id),\n  KEY idx_g_match_rating_cap (max_rating_state,club_verified,time_class,status,match_id)\n","  KEY idx_g_match_discovered (verified_club_slug,club_verified,created_at,match_id)\n",$greenCore);
execSchema($core,$greenCore);execSchema($analytics,$greenAnalytics);
ok(!columnExists($core,'p2k_g_matches','max_rating_state'),'v2.13.1 fixture omits provenance state');
ok(!indexExists($core,'p2k_g_matches','idx_g_match_rating_cap'),'v2.13.1 fixture omits rating-cap index');
$core->exec("INSERT INTO p2k_g_matches(match_id,api_url,status,time_class,club_verified,verified_club_slug,is_void,max_rating,created_at,updated_at) VALUES(991000,'https://api.chess.com/pub/match/991000','finished','daily',1,'promote-to-king',0,1600,UTC_TIMESTAMP(),UTC_TIMESTAMP())");

$blueConfig=tempnam(sys_get_temp_dir(),'p2k-v2132-blue-');
if($blueConfig===false)failTest('unable to create temporary Team Points config');
file_put_contents($blueConfig,"<?php\nreturn ".var_export(['app'=>['club_slug'=>'promote-to-king'],'storage'=>[]],true).";\n");
putenv('P2K_TP_CONFIG='.$blueConfig);
$greenConfigPath=$root.'/server/team-points-green/config/green.local.php';
$greenConfigDir=dirname($greenConfigPath);if(!is_dir($greenConfigDir))mkdir($greenConfigDir,0700,true);
$priorGreenConfig=is_file($greenConfigPath)?file_get_contents($greenConfigPath):null;
$greenConfig=['databases'=>[
    'core'=>['host'=>$host,'port'=>$port,'name'=>$coreName,'user'=>$user,'password'=>$pass,'charset'=>'utf8mb4','connect_timeout_seconds'=>5],
    'analytics'=>['host'=>$host,'port'=>$port,'name'=>$analyticsName,'user'=>$user,'password'=>$pass,'charset'=>'utf8mb4','connect_timeout_seconds'=>5],
],'app'=>['cron_token'=>'test']];
file_put_contents($greenConfigPath,"<?php\ndeclare(strict_types=1);\nreturn ".var_export($greenConfig,true).";\n");

try{
    require_once $root.'/server/team-points-green/src/bootstrap.php';
    $green=\P2K\Green\GreenRepository::open();
    ok(columnExists($green->core,'p2k_g_matches','max_rating_state'),'normal Green open adds provenance state');
    ok(indexExists($green->core,'p2k_g_matches','idx_g_match_rating_cap'),'normal Green open adds rating-cap index');
    $legacy=row($green->core,991000);
    ok((int)$legacy['max_rating']===1600 && $legacy['max_rating_state']==='capped','existing v2.13.1 numeric caps migrate to capped state');

    foreach([991001,991002,991003,991004] as $id){
        $q=$green->core->prepare("INSERT INTO p2k_g_matches(match_id,api_url,status,time_class,club_verified,verified_club_slug,is_void,created_at,updated_at) VALUES(?,?,'finished','daily',1,'promote-to-king',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $q->execute([$id,'https://api.chess.com/pub/match/'.$id]);
    }

    $r=$green->storeMaxRatingBackfill(991001,payload(991001,['max_rating'=>1600]));
    ok($r['state']==='capped' && (int)$r['max_rating']===1600,'explicit positive max_rating becomes capped');
    $r=$green->storeMaxRatingBackfill(991001,payload(991001));
    ok($r['state']==='capped' && (int)$r['max_rating']===1600,'later payload omitting max_rating preserves authoritative capped value');

    $r=$green->storeMaxRatingBackfill(991002,payload(991002,['max_rating'=>0]));
    ok($r['state']==='open' && $r['max_rating']===null,'explicit nonpositive max_rating becomes authoritative open');
    $r=$green->storeMaxRatingBackfill(991002,payload(991002));
    ok($r['state']==='open' && $r['max_rating']===null,'later missing field preserves authoritative open state');

    $r=$green->storeMaxRatingBackfill(991003,payload(991003));
    ok($r['state']==='unavailable' && $r['max_rating']===null,'missing field on previously unknown historical payload becomes unavailable, not open');

    $candidates=$green->maxRatingBackfillCandidates(1000);
    $candidateIds=array_map(static fn(array $x):int=>(int)$x['match_id'],$candidates);
    ok(in_array(991004,$candidateIds,true),'unknown row remains resumable candidate');
    ok(!in_array(991001,$candidateIds,true)&&!in_array(991002,$candidateIds,true)&&!in_array(991003,$candidateIds,true),'resolved states disappear from candidate queue');

    $changed=$green->markMaxRatingUnavailable([991004]);
    ok($changed===1 && row($green->core,991004)['max_rating_state']==='unavailable','terminal failure marker resolves one unknown row as unavailable');

    // Normal storeMatch uses the same parser and must preserve a known cap when a later API phase omits it.
    $green->core->exec("INSERT INTO p2k_g_matches(match_id,api_url,status,created_at,updated_at) VALUES(991005,'https://api.chess.com/pub/match/991005','registered',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $green->storeMatch(991005,payload(991005,['max_rating'=>1400],'registered'),200);
    $first=row($green->core,991005);
    ok((int)$first['max_rating']===1400 && $first['max_rating_state']==='capped','normal storeMatch records registration cap');
    $green->storeMatch(991005,payload(991005,[],'finished'),200);
    $later=row($green->core,991005);
    ok((int)$later['max_rating']===1400 && $later['max_rating_state']==='capped','finished payload omission cannot erase previously captured cap');

    $snapshot=$green->maxRatingBackfillSnapshot();
    ok((int)$snapshot['unknown']===0,'test fixture ends with no unknown rating-cap rows');
    echo "PASS: v2.13.2 rating-cap provenance and backfill runtime test\n";
} finally {
    if($priorGreenConfig===null)@unlink($greenConfigPath);else file_put_contents($greenConfigPath,$priorGreenConfig);
    @unlink($blueConfig);
    foreach([$coreName,$analyticsName] as $db){try{$admin->exec("DROP DATABASE IF EXISTS `{$db}`");}catch(Throwable){}}
}
