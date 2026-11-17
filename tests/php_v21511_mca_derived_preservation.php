<?php
declare(strict_types=1);

require_once __DIR__.'/../server/team-points/src/McaResultsCronService.php';
require_once __DIR__.'/../server/team-points/src/LiveRanksService.php';

use P2K\TeamPoints\McaResultsCronService;
use P2K\TeamPoints\LiveRanksService;

$dsn=getenv('P2K_TEST_MARIADB_DSN')?:'';
if($dsn==='')throw new RuntimeException('Run with isolated P2K_TEST_MARIADB_DSN.');
$pdo=new PDO($dsn,getenv('P2K_TEST_MARIADB_USER')?:'root',
    getenv('P2K_TEST_MARIADB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['p2k_lr_sync_state','p2k_lr_processing_state','p2k_lr_players','p2k_lr_arena_stats','p2k_lr_source_rows'] as $table)
    $pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec('CREATE TABLE p2k_lr_sync_state (
    club_slug VARCHAR(80) PRIMARY KEY,status VARCHAR(40),phase VARCHAR(40),
    rebuild_required INT DEFAULT 0,updated_at DATETIME NULL)');
$pdo->exec('CREATE TABLE p2k_lr_processing_state (
    club_slug VARCHAR(80) PRIMARY KEY,status VARCHAR(40),phase VARCHAR(40),
    total_players INT NOT NULL DEFAULT 0,identity_map_generation INT NOT NULL DEFAULT 0,
    updated_at DATETIME NULL,last_error TEXT NULL)');
$pdo->exec('CREATE TABLE p2k_lr_players(
    club_slug VARCHAR(80),username_key VARCHAR(60),total_points DOUBLE,account_state VARCHAR(40),
    PRIMARY KEY(club_slug,username_key))');
$pdo->exec('CREATE TABLE p2k_lr_arena_stats(
    club_slug VARCHAR(80),file_id INT,total_points DOUBLE,PRIMARY KEY(club_slug,file_id))');
$pdo->exec('CREATE TABLE p2k_lr_source_rows(
    club_slug VARCHAR(80),file_id INT,source_row_no INT,raw_username_key VARCHAR(60))');
$pdo->exec("INSERT INTO p2k_lr_sync_state VALUES ('promote-to-king','completed','discovery_complete',0,UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO p2k_lr_processing_state VALUES ('promote-to-king','completed','complete',2,36,UTC_TIMESTAMP(),NULL)");
$pdo->exec("INSERT INTO p2k_lr_players VALUES ('promote-to-king','alice',145.5,'current_member'),('promote-to-king','bob',19.5,'possible_renamed')");
$pdo->exec("INSERT INTO p2k_lr_arena_stats VALUES ('promote-to-king',22,165.0)");
$pdo->exec("INSERT INTO p2k_lr_source_rows VALUES ('promote-to-king',22,1,'alice')");
$fixture=static function()use($pdo):array{
    $players=$pdo->query("SELECT username_key,total_points,account_state FROM p2k_lr_players ORDER BY username_key")
        ->fetchAll(PDO::FETCH_ASSOC);
    $stats=$pdo->query("SELECT file_id,total_points FROM p2k_lr_arena_stats")
        ->fetchAll(PDO::FETCH_ASSOC);
    $source=$pdo->query("SELECT source_row_no,raw_username_key FROM p2k_lr_source_rows")
        ->fetchAll(PDO::FETCH_ASSOC);
    return [$players,$stats,$source];
};
$before=$fixture();
$create=function(string $type)use($pdo):object {
    $rc=new ReflectionClass($type);
    $object=$rc->newInstanceWithoutConstructor();
    $pdoProperty=$rc->getProperty('pdo');$pdoProperty->setValue($object,$pdo);
    $slug=$rc->getProperty('clubSlug');$slug->setValue($object,'promote-to-king');
    return $object;
};
$invoke=static function(object $object,string $method,...$args):mixed {
    return (new ReflectionMethod($object,$method))->invoke($object,...$args);
};
$cron=$create(McaResultsCronService::class);
$manual=$create(LiveRanksService::class);
$invoke($cron,'invalidateDerivedMca');
if($fixture()!==$before)throw new RuntimeException('Automatic CSV ingestion deleted previously published player, arena or MIAC source data.');
$r=$pdo->query("SELECT status,phase,total_players,identity_map_generation FROM p2k_lr_processing_state")->fetch(PDO::FETCH_ASSOC);
if($r!==['status'=>'pending','phase'=>'files_changed','total_players'=>2,'identity_map_generation'=>36])throw new RuntimeException('Automatic CSV invalidation corrupted derived state: '.json_encode($r));
if((int)$pdo->query('SELECT rebuild_required FROM p2k_lr_sync_state')->fetchColumn()!==1)throw new RuntimeException('Automatic CSV must queue a rebuild.');
$pdo->exec("UPDATE p2k_lr_sync_state SET rebuild_required=0");
$pdo->exec("UPDATE p2k_lr_processing_state SET status='completed',phase='complete'");
$invoke($manual,'invalidateProcessingAfterUpload');
if($fixture()!==$before)throw new RuntimeException('Admin CSV upload deleted previously published player, arena or MIAC source data.');
if((int)$pdo->query('SELECT rebuild_required FROM p2k_lr_sync_state')->fetchColumn()!==1)throw new RuntimeException('Manual CSV must queue a rebuild.');
$pdo->exec("UPDATE p2k_lr_processing_state SET status='pending',phase='rebuild_retry',updated_at=UTC_TIMESTAMP(),last_error='test deadline'");
$invoke($cron,'runPendingDerivedRebuild',microtime(true)+50.0);
if($fixture()!==$before)throw new RuntimeException('Backoff must not clear previously published data.');
if((int)$pdo->query('SELECT rebuild_required FROM p2k_lr_sync_state')->fetchColumn()!==1)throw new RuntimeException('Backoff must preserve pending flag.');
$cronSrc=file_get_contents(__DIR__.'/../server/team-points/src/McaResultsCronService.php');
$liveSrc=file_get_contents(__DIR__.'/../server/team-points/src/LiveRanksService.php');
if(!is_string($cronSrc)||!is_string($liveSrc))throw new RuntimeException('Missing PHP sources');
$posBuild=strpos($cronSrc,'$this->runPendingDerivedRebuild($deadline);');
$posAcquire=strpos($cronSrc,'while(microtime(true)<$deadline-6.0){$item=$this->nextAcquisitionItem()');
if($posBuild===false||$posAcquire===false||$posBuild>=$posAcquire)throw new RuntimeException('Automatic rebuild must get a chance before the acquisition queue.');
if(!str_contains($liveSrc,'MCA_REBUILD_SOURCE_CHANGED')||!str_contains($liveSrc,'MCA_REBUILD_SOURCE_ERROR'))
    throw new RuntimeException('Full-source validation and concurrency guard required.');
if(!str_contains($liveSrc,'SELECT * FROM p2k_lr_files WHERE club_slug=? FOR UPDATE'))
    throw new RuntimeException('Rebuild publication must prevent concurrent source replacement.');
if(!preg_match("~UPDATE p2k_lr_sync_state SET rebuild_required=0,updated_at=UTC_TIMESTAMP\\(\\)~",$liveSrc))
    throw new RuntimeException('Successful transactional rebuild must clear dirty flag.');
echo "PASS automatic and manual CSV changes preserve published MCA points and MIAC review; rebuild queued; retries back off; rebuild precedes scraping.\n";
