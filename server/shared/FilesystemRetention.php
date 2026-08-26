<?php
declare(strict_types=1);

namespace P2K\Shared;

/** Generic bounded-retention helpers for runtime files/directories. */
final class FilesystemRetention
{
    /** @return array{removed_files:int,removed_bytes:int,remaining:int,scanned:int} */
    public static function pruneFiles(string $dir,string $pattern,int $retentionDays,int $maxFiles,int $scanLimit=50000):array
    {
        if(!is_dir($dir))return ['removed_files'=>0,'removed_bytes'=>0,'remaining'=>0,'scanned'=>0];
        $files=glob(rtrim($dir,'/\\').'/'.$pattern)?:[];$rows=[];$scanned=0;
        foreach($files as $path){if($scanned++>=max(1,$scanLimit))break;if(is_file($path))$rows[]=['path'=>$path,'mtime'=>(int)(@filemtime($path)?:0),'bytes'=>(int)(@filesize($path)?:0)];}
        usort($rows,static fn(array $a,array $b):int=>$b['mtime']<=>$a['mtime']);
        $cut=time()-max(1,$retentionDays)*86400;$cap=max(1,$maxFiles);$removed=0;$bytes=0;$remaining=0;
        foreach($rows as $i=>$row){$delete=$i>=$cap||$row['mtime']<$cut;if($delete){if(@unlink($row['path'])){$removed++;$bytes+=$row['bytes'];continue;}}$remaining++;}
        return ['removed_files'=>$removed,'removed_bytes'=>$bytes,'remaining'=>$remaining,'scanned'=>$scanned];
    }

    /** @return array{removed_directories:int,removed_files:int,removed_bytes:int,remaining:int,scanned:int} */
    public static function pruneDirectories(string $root,int $retentionDays,int $maxDirectories,int $scanLimit=5000):array
    {
        if(!is_dir($root))return ['removed_directories'=>0,'removed_files'=>0,'removed_bytes'=>0,'remaining'=>0,'scanned'=>0];
        $rows=[];$scanned=0;
        try{
            foreach(new \DirectoryIterator($root) as $item){if($item->isDot()||!$item->isDir())continue;if($scanned++>=max(1,$scanLimit))break;$rows[]=['path'=>$item->getPathname(),'mtime'=>$item->getMTime()];}
        }catch(\Throwable){return ['removed_directories'=>0,'removed_files'=>0,'removed_bytes'=>0,'remaining'=>0,'scanned'=>$scanned];}
        usort($rows,static fn(array $a,array $b):int=>$b['mtime']<=>$a['mtime']);$cut=time()-max(1,$retentionDays)*86400;$cap=max(1,$maxDirectories);
        $removedDirs=0;$removedFiles=0;$removedBytes=0;$remaining=0;
        foreach($rows as $i=>$row){$delete=$i>=$cap||$row['mtime']<$cut;if(!$delete){$remaining++;continue;}$stats=self::removeTree($row['path']);if($stats['removed_directory']){$removedDirs++;$removedFiles+=$stats['removed_files'];$removedBytes+=$stats['removed_bytes'];}else{$remaining++;}}
        return ['removed_directories'=>$removedDirs,'removed_files'=>$removedFiles,'removed_bytes'=>$removedBytes,'remaining'=>$remaining,'scanned'=>$scanned];
    }

    /** @return array{removed_directory:bool,removed_files:int,removed_bytes:int} */
    private static function removeTree(string $dir):array
    {
        if(!is_dir($dir))return ['removed_directory'=>false,'removed_files'=>0,'removed_bytes'=>0];$files=0;$bytes=0;
        try{
            $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as $item){$path=$item->getPathname();if($item->isDir()){@rmdir($path);continue;}$size=(int)$item->getSize();if(@unlink($path)){$files++;$bytes+=$size;}}
            $ok=@rmdir($dir);return ['removed_directory'=>(bool)$ok,'removed_files'=>$files,'removed_bytes'=>$bytes];
        }catch(\Throwable){return ['removed_directory'=>false,'removed_files'=>$files,'removed_bytes'=>$bytes];}
    }
}
