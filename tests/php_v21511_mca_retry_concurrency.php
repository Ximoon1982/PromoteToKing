<?php
declare(strict_types=1);

// Run against a disposable MariaDB instance, never production.
// This executes the exact SQL from McaResultsCronService::retryHydrationErrors()
// while another connection owns the durable MCA synchronization lock.
$dsn = getenv('P2K_TEST_MARIADB_DSN') ?: '';
if ($dsn === '') throw new RuntimeException('Set P2K_TEST_MARIADB_DSN to an isolated test database.');
$user = getenv('P2K_TEST_MARIADB_USER') ?: 'root';
$pass = getenv('P2K_TEST_MARIADB_PASS') ?: '';
$pdo = new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$holder = new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP TABLE IF EXISTS p2k_lr_files');
$pdo->exec('DROP TABLE IF EXISTS p2k_lr_arena_acquisition');
$pdo->exec('CREATE TABLE p2k_lr_arena_acquisition (
  club_slug VARCHAR(80) NOT NULL, arena_id BIGINT NOT NULL,
  status VARCHAR(20) NOT NULL, priority INT NOT NULL,
  stage VARCHAR(30) NOT NULL, players_next_page INT NOT NULL,
  attempts INT NOT NULL, last_error TEXT NULL, updated_at DATETIME NULL,
  PRIMARY KEY(club_slug,arena_id)
)');
$pdo->exec('CREATE TABLE p2k_lr_files (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  club_slug VARCHAR(80) NOT NULL,arena_id BIGINT NOT NULL,
  status VARCHAR(20) NOT NULL,row_count INT NOT NULL
)');
$insert=$pdo->prepare('INSERT INTO p2k_lr_arena_acquisition(club_slug,arena_id,status,priority,stage,players_next_page,attempts,last_error) VALUES(?,?,?,?,?,?,?,?)');
foreach([
  [31168164,'error',100,'players',23,62,'Player Results page 23 contained no rows.'],
  [31120600,'error',100,'players',17,114,'Player Results page 17 contained no rows.'],
  [31000001,'error',40,'pairings',11,9,'Pairings unavailable.'],
  [31000002,'running',100,'players',7,1,''],
  [31000003,'pending',80,'clubs',1,0,''],
] as [$id,$status,$priority,$stage,$page,$attempts,$err]){
  $insert->execute(['promote-to-king',$id,$status,$priority,$stage,$page,$attempts,$err]);
}
$pdo->exec("INSERT INTO p2k_lr_files (club_slug,arena_id,status,row_count) VALUES ('promote-to-king',31000001,'processed',30)");
$src=file_get_contents(__DIR__.'/../server/team-points/src/McaResultsCronService.php');
if(!is_string($src))throw new RuntimeException('No MCA source to check.');
if(!preg_match('~public function retryHydrationErrors\(\): array\s*\{(.*?)\n    \}~s',$src,$match))throw new RuntimeException('Retry method not found.');
$body=$match[1];
if(str_contains($body,'withGlobalLock')||str_contains($body,'GET_LOCK'))throw new RuntimeException('Retry must not take the busy scrape lock.');
if(!str_contains($src,'IS_USED_LOCK(?)')||!str_contains($src,"'worker_lock_held'"))throw new RuntimeException('MCA lock diagnostic must remain read-only and available to admin.');
if(!preg_match('~\$q=\$this->pdo->prepare\("(UPDATE p2k_lr_arena_acquisition a.*?)"\);~s',$body,$sqlMatch))throw new RuntimeException('Exact retry SQL not found.');
$sql=$sqlMatch[1];
$key='p2k:mca-sync:promote-to-king';
$lock=$holder->prepare('SELECT GET_LOCK(?,0)');
$lock->execute([$key]);
if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Could not simulate active worker.');
try{
  $start=microtime(true);
  $q=$pdo->prepare($sql);
  $q->execute(['promote-to-king']);
  $duration=microtime(true)-$start;
  if($duration>2)throw new RuntimeException('Admin retry blocked by active worker: '.$duration);
  if($q->rowCount()!==3)throw new RuntimeException('Expected three error rows requeued, got '.$q->rowCount());
  $rows=$pdo->query('SELECT * FROM p2k_lr_arena_acquisition ORDER BY arena_id')->fetchAll(PDO::FETCH_ASSOC);
  $byId=[];foreach($rows as $row)$byId[(int)$row['arena_id']]=$row;
  foreach([31168164=>[23,62],31120600=>[17,114]] as $id=>[$page,$attempts]){
    $row=$byId[$id];
    if($row['status']!=='pending'||(int)$row['priority']<150||$row['last_error']!==null
       ||(int)$row['players_next_page']!==$page||(int)$row['attempts']!==$attempts
       ||$row['stage']!=='players')throw new RuntimeException('Missing-Results retry checkpoint or priority changed: '.json_encode($row));
  }
  if((int)$byId[31000001]['priority']!==40||$byId[31000001]['status']!=='pending')throw new RuntimeException('Enrichment should not steal Missing Results priority.');
  if($byId[31000002]['status']!=='running'||(int)$byId[31000002]['players_next_page']!==7)throw new RuntimeException('An active worker row must be left alone.');
  if($byId[31000003]['status']!=='pending')throw new RuntimeException('Already pending arena must be preserved.');
  $q->execute(['promote-to-king']);
  if($q->rowCount()!==0)throw new RuntimeException('Requeue must be idempotent on repeated clicks.');
  echo "PASS MariaDB lock contention; 2 missing Results + 1 enrichment queued, priorities preserved, current worker untouched, exact page checkpoints retained, idempotent.\n";
}finally{
  $unlock=$holder->prepare('SELECT RELEASE_LOCK(?)');$unlock->execute([$key]);
}
