<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;
use RuntimeException;

/** v2.10.6.6 explicit MCA-only Blue -> Green snapshot synchronization. */
final class McaBlueGreenSync
{
    private const TABLES = [
        'p2k_lr_files','p2k_lr_players','p2k_lr_processing_state','p2k_lr_sync_state',
        'p2k_lr_sync_queue','p2k_lr_arena_stats','p2k_lr_source_rows','p2k_lr_attributions',
    ];

    public static function run(): array
    {
        $config=\p2k_tp_config();$club=strtolower(trim((string)($config['app']['club_slug']??'promote-to-king')));
        $blue=Database::analytics();
        $greenConfigPath=dirname(__DIR__,2).'/team-points-green/src/GreenConfig.php';
        if(!is_file($greenConfigPath))throw new RuntimeException('Green configuration support is unavailable.');
        require_once $greenConfigPath;
        $green=\P2K\Green\GreenConfig::analytics();
        $blueDb=(string)$blue->query('SELECT DATABASE()')->fetchColumn();$greenDb=(string)$green->query('SELECT DATABASE()')->fetchColumn();
        if($blueDb===''||$greenDb==='')throw new RuntimeException('Unable to identify the Blue/Green Analytics databases.');
        if(strcasecmp($blueDb,$greenDb)===0)throw new RuntimeException('Blue and Green Analytics resolve to the same database; MCA copy was refused.');

        $storageDir=trim((string)($config['app']['live_ranks_upload_dir']??''));
        if($storageDir==='')$storageDir=dirname(__DIR__,3).'/data/live-ranks/uploads';
        $fq=$blue->prepare('SELECT stored_name,sha256 FROM p2k_lr_files WHERE club_slug=? ORDER BY id');$fq->execute([$club]);$files=$fq->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($files as $file){
            $path=rtrim($storageDir,'/\\').'/'.basename((string)$file['stored_name']);
            if(!is_file($path))throw new RuntimeException('MCA Blue -> Green refused: stored source file is missing: '.basename($path));
            $expected=strtolower(trim((string)$file['sha256']));$actual=hash_file('sha256',$path);
            if(!is_string($actual)||$expected===''||!hash_equals($expected,strtolower($actual)))throw new RuntimeException('MCA Blue -> Green refused: source-file checksum mismatch: '.basename($path));
        }

        $columns=[];$sourceCounts=[];
        foreach(self::TABLES as $table){
            $sourceColumns=self::columns($blue,$table);$targetColumns=self::columns($green,$table);
            if($sourceColumns!==$targetColumns)throw new RuntimeException('MCA Blue -> Green schema mismatch for '.$table.'.');
            $columns[$table]=$sourceColumns;
            $q=$blue->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE club_slug=?');$q->execute([$club]);$sourceCounts[$table]=(int)$q->fetchColumn();
        }

        $lockName='p2k:mca-blue-green:'.substr($club,0,40);$l=$green->prepare('SELECT GET_LOCK(?,0)');$l->execute([$lockName]);
        if((int)$l->fetchColumn()!==1)throw new RuntimeException('Another MCA Blue -> Green synchronization is already active.');
        $copied=[];
        try{
            $green->beginTransaction();
            try{
                foreach(self::TABLES as $table){
                    $del=$green->prepare('DELETE FROM `'.$table.'` WHERE club_slug=?');$del->execute([$club]);
                    $copied[$table]=self::copyTable($blue,$green,$table,$columns[$table],$club);
                }
                foreach(self::TABLES as $table){
                    $q=$green->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE club_slug=?');$q->execute([$club]);$target=(int)$q->fetchColumn();
                    if($target!==$sourceCounts[$table]||$copied[$table]!==$sourceCounts[$table])throw new RuntimeException('MCA Blue -> Green row-count validation failed for '.$table.'.');
                }
                $green->commit();
            }catch(\Throwable $e){if($green->inTransaction())$green->rollBack();throw $e;}
        }finally{try{$r=$green->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lockName]);}catch(\Throwable){}}
        return ['source'=>'blue','target'=>'green','blue_database'=>$blueDb,'green_database'=>$greenDb,'source_files_verified'=>count($files),'tables'=>$copied,'rows_copied'=>array_sum($copied),'completed_at'=>gmdate('Y-m-d H:i:s')];
    }

    private static function columns(PDO $pdo,string $table): array
    {
        if(!in_array($table,self::TABLES,true))throw new RuntimeException('Invalid MCA table.');
        $rows=$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)?:[];
        $columns=array_values(array_map(static fn(array $row):string=>(string)$row['Field'],$rows));
        if($columns===[]||!in_array('club_slug',$columns,true))throw new RuntimeException('MCA table '.$table.' is unavailable or invalid.');
        return $columns;
    }

    private static function copyTable(PDO $source,PDO $target,string $table,array $columns,string $club): int
    {
        $quoted=implode(',',array_map(static fn(string $column):string=>'`'.str_replace('`','``',$column).'`',$columns));
        $select=$source->prepare('SELECT '.$quoted.' FROM `'.$table.'` WHERE club_slug=?');$select->execute([$club]);$count=0;$batch=[];
        while($row=$select->fetch(PDO::FETCH_ASSOC)){
            $batch[]=$row;if(count($batch)>=100){$count+=self::insertBatch($target,$table,$columns,$batch);$batch=[];}
        }
        if($batch!==[])$count+=self::insertBatch($target,$table,$columns,$batch);
        return $count;
    }

    private static function insertBatch(PDO $pdo,string $table,array $columns,array $rows): int
    {
        $quoted=implode(',',array_map(static fn(string $column):string=>'`'.str_replace('`','``',$column).'`',$columns));
        $one='('.implode(',',array_fill(0,count($columns),'?')).')';$sql='INSERT INTO `'.$table.'` ('.$quoted.') VALUES '.implode(',',array_fill(0,count($rows),$one));
        $values=[];foreach($rows as $row)foreach($columns as $column)$values[]=$row[$column]??null;
        $stmt=$pdo->prepare($sql);$stmt->execute($values);return count($rows);
    }
}
