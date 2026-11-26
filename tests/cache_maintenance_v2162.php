<?php
declare(strict_types=1);
require dirname(__DIR__).'/server/shared/FilesystemCache.php';
use P2K\Shared\FilesystemCache;
$root=sys_get_temp_dir().'/p2k-cache-test-'.bin2hex(random_bytes(6));
function check(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
try {
 $c=new FilesystemCache(['cache_dir'=>$root,'cache_cleanup_probability_percent'=>100,'cache_expired_grace_seconds'=>0]);
 $c->put('old',200,'{}',null,null,-1);
 sleep(2);
 $c->put('new',200,'{"ok":true}',null,null,120,'oauth-attested-v2917');
 check($c->get('old')!==null,'Foreground write must not purge');
 check($c->get('new')['consumer']==='oauth-attested-v2917','Attestation must survive');
 $lock=fopen($root.'/.purge.lock','c+');flock($lock,LOCK_EX);
 check($c->purge(100)['busy']===true,'Concurrent purge must defer');fclose($lock);
 check($c->purge(100,microtime(true)-1)['deadline_reached']===true,'Expired deadline must defer');
 $r=$c->purge(100,microtime(true)+10);check($r['removed_files']===1,'Scheduled purge must delete expired entry');
 check($c->get('old')===null,'Expired entry still exists');check($c->get('new')!==null,'Fresh entry lost');
 echo "PASS foreground writes, cache attestation, exclusive purge and deadline\n";
}finally{if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);}}
