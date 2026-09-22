<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$host=getenv('P2K_TEST_DB_HOST')?:'127.0.0.1';
$port=(int)(getenv('P2K_TEST_DB_PORT')?:3306);
$user=getenv('P2K_TEST_DB_USER')?:'root';
$pass=getenv('P2K_TEST_DB_PASS')?:'root';
$coreName='p2k_v2131_core';
$analyticsName='p2k_v2131_analytics';

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

$admin=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach([$coreName,$analyticsName] as $db){$admin->exec("DROP DATABASE IF EXISTS \`{$db}\`");$admin->exec("CREATE DATABASE \`{$db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");}

$core=new PDO("mysql:host={$host};port={$port};dbname={$coreName};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$analytics=new PDO("mysql:host={$host};port={$port};dbname={$analyticsName};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

$greenCore=file_get_contents($root.'/server/team-points-green/sql/core-schema.sql');
$greenAnalytics=file_get_contents($root.'/server/team-points-green/sql/analytics-schema.sql');
if($greenCore===false||$greenAnalytics===false)failTest('unable to read Green schemas');
$greenCore=str_replace("  max_rating SMALLINT UNSIGNED NULL,\n",'',$greenCore);
$greenCore=str_replace("  KEY idx_g_match_eligibility (club_verified,time_class,scoring_eligible,status),\n  KEY idx_g_match_discovered (verified_club_slug,club_verified,created_at,match_id)\n","  KEY idx_g_match_eligibility (club_verified,time_class,scoring_eligible,status)\n",$greenCore);
execSchema($core,$greenCore);execSchema($analytics,$greenAnalytics);
ok(!columnExists($core,'p2k_g_matches','max_rating'),'pre-v2.13.1 fixture omits Green max_rating');
ok(!indexExists($core,'p2k_g_matches','idx_g_match_discovered'),'pre-v2.13.1 fixture omits Green discovery index');

$blueConfig=tempnam(sys_get_temp_dir(),'p2k-v2131-blue-');
if($blueConfig===false)failTest('unable to create temporary Team Points config');
file_put_contents($blueConfig,"<?php\nreturn ".var_export(['app'=>['club_slug'=>'promote-to-king'],'storage'=>[]],true).";\n");
putenv('P2K_TP_CONFIG='.$blueConfig);

$greenConfigPath=$root.'/server/team-points-green/config/green.local.php';
$greenConfigDir=dirname($greenConfigPath);
if(!is_dir($greenConfigDir))mkdir($greenConfigDir,0700,true);
$priorGreenConfig=is_file($greenConfigPath)?file_get_contents($greenConfigPath):null;
$greenConfig=['databases'=>[
    'core'=>['host'=>$host,'port'=>$port,'name'=>$coreName,'user'=>$user,'password'=>$pass,'charset'=>'utf8mb4','connect_timeout_seconds'=>5],
    'analytics'=>['host'=>$host,'port'=>$port,'name'=>$analyticsName,'user'=>$user,'password'=>$pass,'charset'=>'utf8mb4','connect_timeout_seconds'=>5],
],'app'=>['cron_token'=>'test']];
file_put_contents($greenConfigPath,"<?php\ndeclare(strict_types=1);\nreturn ".var_export($greenConfig,true).";\n");

try{
    require_once $root.'/server/team-points-green/src/bootstrap.php';

    $green=\P2K\Green\GreenRepository::open();
    ok(columnExists($green->core,'p2k_g_matches','max_rating'),'normal Green open repairs missing max_rating');
    ok(indexExists($green->core,'p2k_g_matches','idx_g_match_discovered'),'normal Green open repairs missing discovery index');

    $green->core->exec("INSERT INTO p2k_g_matches(match_id,api_url,status,created_at,updated_at) VALUES(990001,'https://api.chess.com/pub/match/990001','registered',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $payload=[
        'url'=>'https://www.chess.com/club/matches/990001',
        'name'=>'v2.13.1 schema convergence test',
        'status'=>'registered',
        'boards'=>2,
        'settings'=>['time_class'=>'daily','rules'=>'chess','time_control'=>'1/86400','max_rating'=>1400],
        'teams'=>[
            ['@id'=>'https://api.chess.com/pub/club/promote-to-king','url'=>'https://www.chess.com/club/promote-to-king','name'=>'Promote to King','score'=>0],
            ['@id'=>'https://api.chess.com/pub/club/schema-test-opponent','url'=>'https://www.chess.com/club/schema-test-opponent','name'=>'Schema Test Opponent','score'=>0],
        ],
    ];
    $green->storeMatch(990001,$payload,200);
    $max=(int)$green->core->query('SELECT max_rating FROM p2k_g_matches WHERE match_id=990001')->fetchColumn();
    ok($max===1400,'match write succeeds after runtime convergence');

    $legacy=new \P2K\TeamPoints\Repository($green->core,$green->analytics);
    $legacy->installSchema();
    ok($legacy->schemaInstalled(),'compatibility schema version markers are current before drift simulation');
    $green->core->exec('ALTER TABLE p2k_tp_match_metadata DROP INDEX idx_tp_match_discovered');
    $green->core->exec('ALTER TABLE p2k_tp_match_metadata DROP COLUMN max_rating');
    ok($legacy->schemaInstalled(),'version markers remain current while physical compatibility schema is deliberately incomplete');
    ok(!columnExists($green->core,'p2k_tp_match_metadata','max_rating'),'compatibility max_rating drift reproduced');
    ok(!indexExists($green->core,'p2k_tp_match_metadata','idx_tp_match_discovered'),'compatibility discovery-index drift reproduced');

    $compat=new \P2K\Green\GreenCompatibility($green);
    $schema=$compat->ensureSchema();
    ok(($schema['physical_verified']??false)===true,'compatibility schema is physically verified');
    ok(columnExists($green->core,'p2k_tp_match_metadata','max_rating'),'compatibility max_rating repaired despite current version marker');
    ok(indexExists($green->core,'p2k_tp_match_metadata','idx_tp_match_discovered'),'compatibility discovery index repaired despite current version marker');
    $projection=$compat->projectMatch(990001,false);
    ok(($projection['projected']??false)===true,'Green compatibility projection succeeds after convergence');
    $compatMax=(int)$green->core->query('SELECT max_rating FROM p2k_tp_match_metadata WHERE match_id=990001')->fetchColumn();
    ok($compatMax===1400,'projected compatibility match preserves max_rating');

    $green->analytics->exec('ALTER TABLE p2k_lr_sync_state DROP COLUMN last_index_page_fingerprint');
    ok(!columnExists($green->analytics,'p2k_lr_sync_state','last_index_page_fingerprint'),'MCA drift fixture omits last_index_page_fingerprint');
    $mca=new \P2K\TeamPoints\McaResultsCronService($green->analytics,$legacy);
    $rm=new ReflectionMethod($mca,'ensureState');$rm->setAccessible(true);$rm->invoke($mca);
    ok(columnExists($green->analytics,'p2k_lr_sync_state','last_index_page_fingerprint'),'MCA ensureState repairs and verifies missing sync column');

    echo "PASS: v2.13.1 schema convergence runtime test\n";
} finally {
    if($priorGreenConfig===null)@unlink($greenConfigPath);else file_put_contents($greenConfigPath,$priorGreenConfig);
    @unlink($blueConfig);
    foreach([$coreName,$analyticsName] as $db){try{$admin->exec("DROP DATABASE IF EXISTS \`{$db}\`");}catch(Throwable){}}
}
