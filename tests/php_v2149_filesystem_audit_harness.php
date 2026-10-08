<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/release-control/src/bootstrap.php';

use P2K\ReleaseControl\FilesystemAuditManager;

$root=sys_get_temp_dir().'/p2k-v2149-audit-'.bin2hex(random_bytes(5));
$runtime=$root.'/runtime';
@mkdir($root.'/SiblingProject/cache',0777,true);
@mkdir($root.'/Unknown/nested',0777,true);
@mkdir($root.'/Preserved',0777,true);
@mkdir($runtime,0777,true);
file_put_contents($root.'/SiblingProject/package.json',"{}\n");
file_put_contents($root.'/SiblingProject/cache/PromoteToKing_v2.1.0_old.zip','leftover');
file_put_contents($root.'/Unknown/nested/file.dat',str_repeat('x',4096));
file_put_contents($root.'/Preserved/.p2k-preserve',"keep\n");
file_put_contents($root.'/shared.bin',str_repeat('z',8192));
if (function_exists('link')) @link($root.'/shared.bin',$root.'/Unknown/shared-hardlink.bin');

try {
    $audit=(new FilesystemAuditManager($root,$runtime))->inventory();
    if (($audit['scope']??'')!=='recursive-read-only-project-aware-audit') throw new RuntimeException('scope');
    $rows=[];foreach(($audit['top_level']??[]) as $row)$rows[$row['relative_path']]=$row;
    if (empty($rows['SiblingProject']['protected']) || ($rows['SiblingProject']['category']??'')!=='sibling project') throw new RuntimeException('sibling project protection');
    if (empty($rows['Preserved']['protected']) || !str_contains((string)$rows['Preserved']['protection_reason'],'.p2k-preserve')) throw new RuntimeException('preserve marker');
    if (empty($rows['Unknown']['protected'])) throw new RuntimeException('unknown protection');
    foreach($rows as $row) if(!empty($row['deletion_authorized'])) throw new RuntimeException('audit granted deletion authority');
    $found=false;foreach(($audit['maintenance_findings']??[]) as $row){if(str_contains((string)$row['relative_path'],'PromoteToKing_v2.1.0_old.zip')){$found=true;if(!empty($row['deletion_authorized']))throw new RuntimeException('finding delete');}}
    if(!$found)throw new RuntimeException('nested finding missing');
    if((int)($audit['totals']['inode_entries']??0)<6)throw new RuntimeException('entry count');
    if((int)($audit['totals']['unique_file_inodes']??0)<3)throw new RuntimeException('inode count');
    echo "v2.14.9 filesystem audit harness passed\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){$p=$item->getPathname();if($item->isDir()&&!$item->isLink())@rmdir($p);else@unlink($p);}
    @rmdir($root);
}
