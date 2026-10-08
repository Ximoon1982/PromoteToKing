<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

/**
 * Persisted, incremental, read-only full-tree accounting.
 *
 * The job is deliberately separated from cleanup authority. It can inspect the
 * complete PromoteToKing tree but never authorizes deletion.
 */
final class FilesystemAuditManager
{
    private const STATE_VERSION = 2;
    private const MAX_FINDINGS = 250;
    private const DEFAULT_BATCH_ENTRIES = 750;
    private const MAX_BATCH_SECONDS = 0.75;

    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {}

    public function start(bool $restart = false): array
    {
        if (!$restart) {
            $existing = $this->status();
            if (in_array((string)($existing['status'] ?? ''), ['running','paused'], true)) return $existing;
        }

        $root = rtrim($this->root, '/\\');
        if (!is_dir($root)) throw new \RuntimeException('PromoteToKing root is unavailable.');

        $cleanupRows = $this->cleanupRows();
        $baseline = $this->physicalBaselineTopLevelPaths();
        $names = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $names[] = $name;
        }
        natcasesort($names);
        $names = array_values($names);

        $areas = [];
        foreach ($names as $name) {
            $path = $root . '/' . $name;
            $areas[$name] = $this->classifyTopLevel($name, $path, isset($baseline[$name]), $cleanupRows)
                + ['relative_path'=>$name,'status'=>'pending','stats'=>$this->emptyStats()];
        }

        $stack = [];
        foreach (array_reverse($names) as $name) $stack[] = ['relative_path'=>$name,'top_level'=>$name];

        $now = gmdate('c');
        $state = [
            'schema'=>self::STATE_VERSION,
            'scope'=>'recursive-read-only-project-aware-audit',
            'status'=>'running',
            'started_at'=>$now,
            'updated_at'=>$now,
            'completed_at'=>null,
            'paused_at'=>null,
            'root'=>$root,
            'processed_entries'=>0,
            'current_path'=>'',
            'stack'=>$stack,
            'areas'=>$areas,
            'area_open_counts'=>array_fill_keys($names, 1),
            'global_inodes'=>[],
            'area_inodes'=>[],
            'totals'=>$this->emptyStats(),
            'maintenance_findings'=>[],
            'maintenance_findings_truncated'=>false,
            'last_error'=>'',
        ];
        $this->writeState($state);
        return $this->publicState($state);
    }

    public function pause(): array
    {
        $state = $this->readState();
        if ($state === null) return $this->idleState();
        if (($state['status'] ?? '') === 'running') {
            $state['status'] = 'paused';
            $state['paused_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->writeState($state);
        }
        return $this->publicState($state);
    }

    public function resume(): array
    {
        $state = $this->readState();
        if ($state === null) return $this->start();
        if (($state['status'] ?? '') === 'paused') {
            $state['status'] = 'running';
            $state['paused_at'] = null;
            $state['updated_at'] = gmdate('c');
            $this->writeState($state);
        }
        return $this->publicState($state);
    }

    public function status(): array
    {
        $state = $this->readState();
        return $state === null ? $this->idleState() : $this->publicState($state);
    }

    public function step(int $entryBudget = self::DEFAULT_BATCH_ENTRIES): array
    {
        $state = $this->readState();
        if ($state === null) $this->start();
        $state = $this->readState();
        if ($state === null) throw new \RuntimeException('Filesystem audit state could not be initialized.');
        if (($state['status'] ?? '') !== 'running') return $this->publicState($state);

        $entryBudget = max(50, min(5000, $entryBudget));
        $started = microtime(true);
        $processed = 0;

        try {
            while (!empty($state['stack']) && $processed < $entryBudget && microtime(true) - $started < self::MAX_BATCH_SECONDS) {
                $item = array_pop($state['stack']);
                $relative = ReleaseSlotPolicy::normalizeRelativePath((string)($item['relative_path'] ?? ''));
                $top = (string)($item['top_level'] ?? '');
                if ($relative === '' || $top === '' || !isset($state['areas'][$top])) continue;

                $path = rtrim($this->root, '/\\') . '/' . $relative;
                $state['current_path'] = $relative;
                $state['areas'][$top]['status'] = 'scanning';

                $st = @lstat($path);
                if (!is_array($st)) {
                    $state['areas'][$top]['stats']['scan_errors'][] = $relative . ': lstat failed';
                    $this->closeItem($state, $top);
                    $processed++;
                    $state['processed_entries']++;
                    continue;
                }

                $this->countStat($st, $state['areas'][$top]['stats'], $state['area_inodes'][$top], $state['global_inodes']);
                $mode = (int)($st['mode'] ?? 0) & 0170000;

                if ($mode === 0040000 && !is_link($path)) {
                    $children = @scandir($path);
                    if ($children === false) {
                        $state['areas'][$top]['stats']['scan_errors'][] = $relative . ': directory read failed';
                    } else {
                        $toPush = [];
                        foreach ($children as $child) {
                            if ($child === '.' || $child === '..') continue;
                            $childRel = $relative . '/' . $child;
                            $toPush[] = ['relative_path'=>$childRel,'top_level'=>$top];
                        }
                        natcasesort($children);
                        foreach (array_reverse($toPush) as $childItem) {
                            $state['stack'][] = $childItem;
                            $state['area_open_counts'][$top] = (int)($state['area_open_counts'][$top] ?? 0) + 1;
                        }
                    }
                }

                $name = basename($relative);
                if (count($state['maintenance_findings']) < self::MAX_FINDINGS + 1
                    && $this->isMaintenanceLooking($name, $mode === 0040000 && !is_link($path))
                    && substr_count($relative, '/') >= 1) {
                    $classification = $state['areas'][$top];
                    $state['maintenance_findings'][] = [
                        'relative_path'=>$relative,
                        'category'=>'nested P2K maintenance-looking artifact',
                        'protected'=>true,
                        'deletion_authorized'=>false,
                        'reason'=>'Strict P2K installer/package naming matched inside a recursively scanned area.',
                        'top_level_category'=>(string)($classification['category'] ?? ''),
                        'protection_reason'=>(string)($classification['protection_reason'] ?? 'Nested findings are review-only.'),
                    ];
                }

                $this->closeItem($state, $top);
                $processed++;
                $state['processed_entries']++;
            }

            if (empty($state['stack'])) {
                $state['status'] = 'complete';
                $state['completed_at'] = gmdate('c');
                $state['current_path'] = '';
                $this->rebuildTotals($state);
            }
            $state['maintenance_findings_truncated'] = count($state['maintenance_findings']) > self::MAX_FINDINGS;
            if ($state['maintenance_findings_truncated']) {
                $state['maintenance_findings'] = array_slice($state['maintenance_findings'],0,self::MAX_FINDINGS);
            }
            $state['updated_at'] = gmdate('c');
            $this->writeState($state);
            return $this->publicState($state);
        } catch (\Throwable $e) {
            $state['status'] = 'paused';
            $state['last_error'] = $e->getMessage();
            $state['updated_at'] = gmdate('c');
            $this->writeState($state);
            throw $e;
        }
    }

    /** Backward-compatible synchronous entry point used only by tests/tools. */
    public function inventory(): array
    {
        $this->start(true);
        do {
            $status = $this->step(5000);
        } while (($status['status'] ?? '') === 'running');
        return $status;
    }

    private function closeItem(array &$state, string $top): void
    {
        $state['area_open_counts'][$top] = max(0, (int)($state['area_open_counts'][$top] ?? 1) - 1);
        if ($state['area_open_counts'][$top] === 0) {
            $state['areas'][$top]['status'] = 'complete';
            $this->finalizeStats($state['areas'][$top]['stats'], (array)($state['area_inodes'][$top] ?? []));
            unset($state['area_inodes'][$top]);
            $this->rebuildTotals($state);
        }
    }

    private function rebuildTotals(array &$state): void
    {
        $totals = $this->emptyStats();
        foreach ((array)$state['areas'] as $area) {
            $stats = (array)($area['stats'] ?? []);
            foreach (['file_entries','directory_entries','symlink_entries','inode_entries','apparent_bytes'] as $key) {
                $totals[$key] += (int)($stats[$key] ?? 0);
            }
            foreach ((array)($stats['scan_errors'] ?? []) as $error) $totals['scan_errors'][] = $error;
        }
        $this->finalizeStats($totals, (array)$state['global_inodes']);
        $state['totals'] = $totals;
    }

    private function publicState(array $state): array
    {
        $areas = array_values((array)($state['areas'] ?? []));
        $complete = 0;
        foreach ($areas as $area) if (($area['status'] ?? '') === 'complete') $complete++;
        $totalAreas = count($areas);
        $progress = $totalAreas === 0 ? (($state['status'] ?? '') === 'complete' ? 100 : 0) : (int)floor(100 * $complete / $totalAreas);
        if (($state['status'] ?? '') === 'complete') $progress = 100;

        $diskTotal = @disk_total_space(rtrim($this->root, '/\\'));
        $diskFree = @disk_free_space(rtrim($this->root, '/\\'));

        usort($areas, static function(array $a,array $b): int {
            $aDone = ($a['status'] ?? '') === 'complete';
            $bDone = ($b['status'] ?? '') === 'complete';
            if ($aDone !== $bDone) return $aDone ? -1 : 1;
            $bytes = ((int)($b['stats']['apparent_bytes'] ?? 0)) <=> ((int)($a['stats']['apparent_bytes'] ?? 0));
            return $bytes !== 0 ? $bytes : strcasecmp((string)($a['relative_path'] ?? ''),(string)($b['relative_path'] ?? ''));
        });

        return [
            'scope'=>'recursive-read-only-project-aware-audit',
            'status'=>(string)($state['status'] ?? 'idle'),
            'started_at'=>$state['started_at'] ?? null,
            'updated_at'=>$state['updated_at'] ?? null,
            'completed_at'=>$state['completed_at'] ?? null,
            'paused_at'=>$state['paused_at'] ?? null,
            'processed_entries'=>(int)($state['processed_entries'] ?? 0),
            'current_path'=>(string)($state['current_path'] ?? ''),
            'pending_entries'=>count((array)($state['stack'] ?? [])),
            'completed_top_level'=>$complete,
            'total_top_level'=>$totalAreas,
            'progress_percent'=>$progress,
            'root'=>rtrim($this->root, '/\\'),
            'filesystem'=>[
                'total_bytes'=>is_float($diskTotal)?(int)$diskTotal:null,
                'free_bytes'=>is_float($diskFree)?(int)$diskFree:null,
                'used_bytes'=>is_float($diskTotal)&&is_float($diskFree)?max(0,(int)$diskTotal-(int)$diskFree):null,
                'note'=>'Host filesystem values may differ from provider account quota accounting.',
            ],
            'totals'=>(array)($state['totals'] ?? $this->emptyStats()),
            'top_level'=>$areas,
            'maintenance_findings'=>array_values((array)($state['maintenance_findings'] ?? [])),
            'maintenance_findings_truncated'=>!empty($state['maintenance_findings_truncated']),
            'last_error'=>(string)($state['last_error'] ?? ''),
            'resumable'=>true,
            'rules'=>$this->rules(),
        ];
    }

    private function idleState(): array
    {
        return [
            'scope'=>'recursive-read-only-project-aware-audit','status'=>'idle','started_at'=>null,'updated_at'=>null,
            'completed_at'=>null,'paused_at'=>null,'processed_entries'=>0,'current_path'=>'','pending_entries'=>0,
            'completed_top_level'=>0,'total_top_level'=>0,'progress_percent'=>0,'root'=>rtrim($this->root,'/\\'),
            'filesystem'=>[],'totals'=>$this->emptyStats(),'top_level'=>[],'maintenance_findings'=>[],
            'maintenance_findings_truncated'=>false,'last_error'=>'','resumable'=>true,'rules'=>$this->rules(),
        ];
    }

    private function rules(): array
    {
        return [
            'The audit is persisted and resumable; bounded batches survive refreshes and request interruption.',
            'The audit recursively scans all PromoteToKing top-level areas without following symbolic links.',
            'Independent project markers and .p2k-preserve markers are reported as protected, never cleanup-authorized.',
            'Unknown top-level content is protected by default.',
            'Nested P2K-looking installer/package leftovers are review findings only; they are not automatically deletable.',
            'Only paths separately classified by FilesystemCleanupManager can expose a deletion action.',
            'Hard-linked regular files are deduplicated by device/inode for unique allocated-byte estimates.',
        ];
    }

    private function cleanupRows(): array
    {
        $rows = [];
        foreach (((new FilesystemCleanupManager($this->root,$this->runtimeOverride))->inventory()['candidates'] ?? []) as $row) {
            $rows[(string)($row['relative_path'] ?? '')] = $row;
        }
        return $rows;
    }

    private function classifyTopLevel(string $name,string $path,bool $baseline,array $cleanupRows): array
    {
        if(isset($cleanupRows[$name])) return ['category'=>'recognized maintenance artifact','protected'=>false,'deletion_authorized'=>!empty($cleanupRows[$name]['cleanup_ready']),'protection_reason'=>''];
        if($baseline||in_array($name,['data','logs','storage','server','assets','.htaccess','ReleaseControl.php','PreviewRouter.php','PublicRouter.php','VERSION','ui-v2.html'],true))
            return ['category'=>'P2K public/recovery/shared','protected'=>true,'deletion_authorized'=>false,'protection_reason'=>'Physical P2K baseline or shared mutable/recovery path.'];
        if(is_dir($path)&&!is_link($path)){
            if(is_file($path.'/.p2k-preserve')) return ['category'=>'preserved project/data','protected'=>true,'deletion_authorized'=>false,'protection_reason'=>'.p2k-preserve marker present.'];
            $markers=$this->projectMarkers($path);
            if($markers!==[]) return ['category'=>'sibling project','protected'=>true,'deletion_authorized'=>false,'protection_reason'=>'Independent project marker(s): '.implode(', ',$markers).'.'];
        }
        if(is_link($path)) return ['category'=>'symbolic link','protected'=>true,'deletion_authorized'=>false,'protection_reason'=>'Symbolic links are never followed or cleanup-authorized.'];
        return ['category'=>'unknown / protected','protected'=>true,'deletion_authorized'=>false,'protection_reason'=>'Unknown top-level content is protected by default.'];
    }

    private function projectMarkers(string $path): array
    {
        $out=[]; foreach(['.git','composer.json','package.json','pyproject.toml','requirements.txt','Cargo.toml','go.mod'] as $marker)
            if(file_exists($path.'/'.$marker)||is_link($path.'/'.$marker))$out[]=$marker;
        return $out;
    }

    private function countStat(array $st,array &$stats,array &$areaInodes,array &$globalInodes): void
    {
        $stats['inode_entries']++;
        $mode=(int)($st['mode']??0)&0170000;
        if($mode===0120000){$stats['symlink_entries']++;return;}
        if($mode===0040000){$stats['directory_entries']++;return;}
        $stats['file_entries']++;
        if($mode!==0100000)return;
        $size=max(0,(int)($st['size']??0));$stats['apparent_bytes']+=$size;
        $key=(string)($st['dev']??0).':'.(string)($st['ino']??0);
        $allocated=isset($st['blocks'])&&(int)$st['blocks']>0?(int)$st['blocks']*512:$size;
        $inode=['nlink'=>max(1,(int)($st['nlink']??1)),'occurrences'=>1,'allocated_bytes'=>$allocated,'apparent_bytes'=>$size];
        if(!isset($areaInodes[$key]))$areaInodes[$key]=$inode;else$areaInodes[$key]['occurrences']++;
        if(!isset($globalInodes[$key]))$globalInodes[$key]=$inode;else$globalInodes[$key]['occurrences']++;
    }

    private function finalizeStats(array &$stats,array $inodes): void
    {
        $stats['unique_file_inodes']=count($inodes);$stats['unique_allocated_bytes']=0;$stats['estimated_reclaimable_bytes']=0;
        $stats['hardlink_preserved_bytes']=0;$stats['hardlink_reference_entries']=0;
        foreach($inodes as $inode){$allocated=(int)$inode['allocated_bytes'];$occ=(int)$inode['occurrences'];$n=max(1,(int)$inode['nlink']);
            $stats['unique_allocated_bytes']+=$allocated;if($n>1)$stats['hardlink_reference_entries']+=$occ;
            if($occ>=$n)$stats['estimated_reclaimable_bytes']+=$allocated;else$stats['hardlink_preserved_bytes']+=$allocated;}
        $stats['scan_errors']=array_values(array_unique((array)$stats['scan_errors']));
    }

    private function emptyStats(): array
    {
        return ['file_entries'=>0,'directory_entries'=>0,'symlink_entries'=>0,'inode_entries'=>0,'unique_file_inodes'=>0,
            'apparent_bytes'=>0,'unique_allocated_bytes'=>0,'estimated_reclaimable_bytes'=>0,'hardlink_preserved_bytes'=>0,
            'hardlink_reference_entries'=>0,'scan_errors'=>[]];
    }

    private function isMaintenanceLooking(string $name,bool $directory): bool
    {
        if($directory)return preg_match('/^PromoteToKing[-_]v\d+(?:\.\d+)+(?:[-_].*)?$/i',$name)===1||preg_match('/^p2k-(?:install|installer|package|extract|release)-/i',$name)===1;
        return preg_match('/^PromoteToKing[-_].*\.(?:zip|tgz|tar|tar\.gz|run)$/i',$name)===1;
    }

    private function physicalBaselineTopLevelPaths(): array
    {
        $root=rtrim($this->root,'/\\');$versionPath=$root.'/VERSION';$uiPath=$root.'/ui-v2.html';
        if(!is_file($versionPath)||!is_file($uiPath))return[];
        $version=trim((string)@file_get_contents($versionPath));$ui=(string)@file_get_contents($uiPath);
        if($version===''||!preg_match('/p2k-([0-9.]+)-([0-9a-f]{12})-[0-9a-f]{16}/i',$ui,$m)||!hash_equals($version,$m[1]))return[];
        $manifest=$this->controlDir().'/releases/'.$version.'-'.strtolower($m[2]).'/meta/manifest.tsv';if(!is_file($manifest))return[];
        $protected=[];$fh=@fopen($manifest,'rb');if($fh===false)return[];
        while(($line=fgets($fh))!==false){$parts=explode("\t",rtrim($line,"\r\n"),4);if(count($parts)!==4)continue;
            $relative=ReleaseSlotPolicy::normalizeRelativePath($parts[3]);if($relative==='')continue;$first=explode('/',$relative,2)[0];if($first!=='')$protected[$first]=true;}
        fclose($fh);return$protected;
    }

    private function statePath(): string
    {
        return $this->controlDir().'/filesystem-audit-v2.json';
    }

    private function readState(): ?array
    {
        $path=$this->statePath();if(!is_file($path))return null;
        $raw=@file_get_contents($path);if($raw===false)return null;$state=json_decode($raw,true);
        return is_array($state)&&($state['schema']??null)===self::STATE_VERSION?$state:null;
    }

    private function writeState(array $state): void
    {
        $path=$this->statePath();$dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new \RuntimeException('Unable to create filesystem audit state directory.');
        $tmp=$path.'.tmp-'.bin2hex(random_bytes(4));$json=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(@file_put_contents($tmp,$json."\n",LOCK_EX)===false)throw new \RuntimeException('Unable to persist filesystem audit state.');
        if(!@rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Unable to activate filesystem audit state.');}
    }

    private function controlDir(): string { return $this->runtimeDir().'/release-control'; }
    private function runtimeDir(): string
    {
        if($this->runtimeOverride!==null&&trim($this->runtimeOverride)!=='')return rtrim($this->runtimeOverride,'/\\');
        $configPath=rtrim($this->root,'/\\').'/server/team-points/config/config.local.php';
        if(is_file($configPath)){try{$config=require $configPath;$runtime=is_array($config)?rtrim((string)(($config['storage']['runtime_dir']??'')),'/\\'):'';if($runtime!=='')return$runtime;}catch(\Throwable){}}
        return rtrim($this->root,'/\\').'/data/runtime-v280';
    }
}
