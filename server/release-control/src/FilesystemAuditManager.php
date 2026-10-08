<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

/**
 * Read-only full-tree accounting for the PromoteToKing host directory.
 *
 * This scanner deliberately separates visibility from deletion authority:
 * it may recursively inspect unknown/sibling-project trees for accounting,
 * but it never grants deletion rights. FilesystemCleanupManager remains the
 * only authority for conservative maintenance deletion.
 */
final class FilesystemAuditManager
{
    private const MAX_FINDINGS = 250;

    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {}

    public function inventory(): array
    {
        $root = rtrim($this->root, '/\\');
        if (!is_dir($root)) throw new \RuntimeException('PromoteToKing root is unavailable.');

        $cleanup = new FilesystemCleanupManager($this->root, $this->runtimeOverride);
        $cleanupRows = [];
        foreach (($cleanup->inventory()['candidates'] ?? []) as $row) {
            $cleanupRows[(string)($row['relative_path'] ?? '')] = $row;
        }

        $baseline = $this->physicalBaselineTopLevelPaths();
        $rows = [];
        $findings = [];
        $globalInodes = [];
        $totals = $this->emptyStats();

        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $root . '/' . $name;
            $classification = $this->classifyTopLevel($name, $path, isset($baseline[$name]), $cleanupRows);
            $rowInodes = [];
            $stats = $this->emptyStats();
            $this->scanTree($path, $stats, $rowInodes, $globalInodes, $findings, $classification);
            $this->finalizeStats($stats, $rowInodes);
            $rows[] = $classification + ['relative_path'=>$name,'stats'=>$stats];
            foreach (['file_entries','directory_entries','symlink_entries','inode_entries','apparent_bytes'] as $key) {
                $totals[$key] += (int)($stats[$key] ?? 0);
            }
        }
        $this->finalizeStats($totals, $globalInodes);

        usort($rows, static function (array $a, array $b): int {
            $bytes = ((int)($b['stats']['apparent_bytes'] ?? 0)) <=> ((int)($a['stats']['apparent_bytes'] ?? 0));
            return $bytes !== 0 ? $bytes : strcasecmp((string)$a['relative_path'], (string)$b['relative_path']);
        });
        usort($findings, static fn(array $a, array $b): int => strnatcasecmp((string)$a['relative_path'], (string)$b['relative_path']));

        $diskTotal = @disk_total_space($root);
        $diskFree = @disk_free_space($root);
        return [
            'scope'=>'recursive-read-only-project-aware-audit',
            'scanned_at'=>gmdate('c'),
            'root'=>$root,
            'filesystem'=>[
                'total_bytes'=>is_float($diskTotal) ? (int)$diskTotal : null,
                'free_bytes'=>is_float($diskFree) ? (int)$diskFree : null,
                'used_bytes'=>is_float($diskTotal) && is_float($diskFree) ? max(0,(int)$diskTotal-(int)$diskFree) : null,
                'note'=>'Host filesystem values may differ from provider account quota accounting.',
            ],
            'totals'=>$totals,
            'top_level'=>$rows,
            'maintenance_findings'=>array_slice($findings,0,self::MAX_FINDINGS),
            'maintenance_findings_truncated'=>count($findings)>self::MAX_FINDINGS,
            'rules'=>[
                'The audit recursively scans all PromoteToKing top-level areas without following symbolic links.',
                'Independent project markers and .p2k-preserve markers are reported as protected, never cleanup-authorized.',
                'Unknown top-level content is protected by default.',
                'Nested P2K-looking installer/package leftovers are review findings only; they are not automatically deletable.',
                'Only paths separately classified by FilesystemCleanupManager can expose a deletion action.',
                'Hard-linked regular files are deduplicated by device/inode for unique allocated-byte estimates.',
            ],
        ];
    }

    private function classifyTopLevel(string $name, string $path, bool $baseline, array $cleanupRows): array
    {
        if (isset($cleanupRows[$name])) {
            return [
                'category'=>'recognized maintenance artifact',
                'protected'=>false,
                'deletion_authorized'=>!empty($cleanupRows[$name]['cleanup_ready']),
                'protection_reason'=>'',
            ];
        }
        if ($baseline || in_array($name, ['data','logs','storage','server','assets','.htaccess','ReleaseControl.php','PreviewRouter.php','PublicRouter.php','VERSION','ui-v2.html'], true)) {
            return [
                'category'=>'P2K public/recovery/shared',
                'protected'=>true,
                'deletion_authorized'=>false,
                'protection_reason'=>'Physical P2K baseline or shared mutable/recovery path.',
            ];
        }
        if (is_dir($path) && !is_link($path)) {
            if (is_file($path . '/.p2k-preserve')) {
                return [
                    'category'=>'preserved project/data',
                    'protected'=>true,
                    'deletion_authorized'=>false,
                    'protection_reason'=>'.p2k-preserve marker present.',
                ];
            }
            $markers = $this->projectMarkers($path);
            if ($markers !== []) {
                return [
                    'category'=>'sibling project',
                    'protected'=>true,
                    'deletion_authorized'=>false,
                    'protection_reason'=>'Independent project marker(s): ' . implode(', ', $markers) . '.',
                ];
            }
        }
        if (is_link($path)) {
            return [
                'category'=>'symbolic link',
                'protected'=>true,
                'deletion_authorized'=>false,
                'protection_reason'=>'Symbolic links are never followed or cleanup-authorized.',
            ];
        }
        return [
            'category'=>'unknown / protected',
            'protected'=>true,
            'deletion_authorized'=>false,
            'protection_reason'=>'Unknown top-level content is protected by default.',
        ];
    }

    private function projectMarkers(string $path): array
    {
        $out = [];
        foreach (['.git','composer.json','package.json','pyproject.toml','requirements.txt','Cargo.toml','go.mod'] as $marker) {
            if (file_exists($path . '/' . $marker) || is_link($path . '/' . $marker)) $out[] = $marker;
        }
        return $out;
    }

    private function scanTree(string $path, array &$stats, array &$rowInodes, array &$globalInodes, array &$findings, array $classification): void
    {
        $this->countEntry($path, $stats, $rowInodes, $globalInodes);
        if (!is_dir($path) || is_link($path)) return;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                $itemPath = $item->getPathname();
                $this->countEntry($itemPath, $stats, $rowInodes, $globalInodes);
                if (count($findings) < self::MAX_FINDINGS + 1 && $this->isMaintenanceLooking($item->getFilename(), $item->isDir() && !$item->isLink())) {
                    $relative = $this->relative($itemPath);
                    if (substr_count($relative, '/') >= 1) {
                        $findings[] = [
                            'relative_path'=>$relative,
                            'category'=>'nested P2K maintenance-looking artifact',
                            'protected'=>true,
                            'deletion_authorized'=>false,
                            'reason'=>'Strict P2K installer/package naming matched inside a recursively scanned area.',
                            'top_level_category'=>(string)($classification['category'] ?? ''),
                            'protection_reason'=>(string)($classification['protection_reason'] ?? 'Nested findings are review-only.'),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $stats['scan_errors'][] = $this->relative($path) . ': ' . $e->getMessage();
        }
    }

    private function countEntry(string $path, array &$stats, array &$rowInodes, array &$globalInodes): void
    {
        $st = @lstat($path);
        if (!is_array($st)) {
            $stats['scan_errors'][] = $this->relative($path) . ': lstat failed';
            return;
        }
        $stats['inode_entries']++;
        $mode = (int)($st['mode'] ?? 0) & 0170000;
        if ($mode === 0120000) {
            $stats['symlink_entries']++;
            return;
        }
        if ($mode === 0040000) {
            $stats['directory_entries']++;
            return;
        }
        $stats['file_entries']++;
        if ($mode !== 0100000) return;

        $size = max(0,(int)($st['size'] ?? 0));
        $stats['apparent_bytes'] += $size;
        $key = (string)($st['dev'] ?? 0) . ':' . (string)($st['ino'] ?? 0);
        $allocated = isset($st['blocks']) && (int)$st['blocks'] > 0 ? (int)$st['blocks'] * 512 : $size;
        $inode = [
            'nlink'=>max(1,(int)($st['nlink'] ?? 1)),
            'occurrences'=>1,
            'allocated_bytes'=>$allocated,
            'apparent_bytes'=>$size,
        ];
        if (!isset($rowInodes[$key])) $rowInodes[$key] = $inode;
        else $rowInodes[$key]['occurrences']++;
        if (!isset($globalInodes[$key])) $globalInodes[$key] = $inode;
        else $globalInodes[$key]['occurrences']++;
    }

    private function finalizeStats(array &$stats, array $inodes): void
    {
        $stats['unique_file_inodes'] = count($inodes);
        $stats['unique_allocated_bytes'] = 0;
        $stats['estimated_reclaimable_bytes'] = 0;
        $stats['hardlink_preserved_bytes'] = 0;
        $stats['hardlink_reference_entries'] = 0;
        foreach ($inodes as $inode) {
            $allocated = (int)$inode['allocated_bytes'];
            $occurrences = (int)$inode['occurrences'];
            $nlink = max(1,(int)$inode['nlink']);
            $stats['unique_allocated_bytes'] += $allocated;
            if ($nlink > 1) $stats['hardlink_reference_entries'] += $occurrences;
            if ($occurrences >= $nlink) $stats['estimated_reclaimable_bytes'] += $allocated;
            else $stats['hardlink_preserved_bytes'] += $allocated;
        }
        $stats['scan_errors'] = array_values(array_unique($stats['scan_errors']));
    }

    private function emptyStats(): array
    {
        return [
            'file_entries'=>0,'directory_entries'=>0,'symlink_entries'=>0,'inode_entries'=>0,
            'unique_file_inodes'=>0,'apparent_bytes'=>0,'unique_allocated_bytes'=>0,
            'estimated_reclaimable_bytes'=>0,'hardlink_preserved_bytes'=>0,'hardlink_reference_entries'=>0,
            'scan_errors'=>[],
        ];
    }

    private function isMaintenanceLooking(string $name, bool $directory): bool
    {
        if ($directory) {
            return preg_match('/^PromoteToKing[-_]v\d+(?:\.\d+)+(?:[-_].*)?$/i', $name) === 1
                || preg_match('/^p2k-(?:install|installer|package|extract|release)-/i', $name) === 1;
        }
        return preg_match('/^PromoteToKing[-_].*\.(?:zip|tgz|tar|tar\.gz|run)$/i', $name) === 1;
    }

    /** @return array<string,true> */
    private function physicalBaselineTopLevelPaths(): array
    {
        $root = rtrim($this->root, '/\\');
        $versionPath = $root . '/VERSION';
        $uiPath = $root . '/ui-v2.html';
        if (!is_file($versionPath) || !is_file($uiPath)) return [];
        $version = trim((string)@file_get_contents($versionPath));
        $ui = (string)@file_get_contents($uiPath);
        if ($version === '' || !preg_match('/p2k-([0-9.]+)-([0-9a-f]{12})-[0-9a-f]{16}/i', $ui, $m) || !hash_equals($version, $m[1])) return [];
        $releaseId = $version . '-' . strtolower($m[2]);
        $manifest = $this->controlDir() . '/releases/' . $releaseId . '/meta/manifest.tsv';
        if (!is_file($manifest)) return [];
        $protected = [];
        $fh = @fopen($manifest, 'rb');
        if ($fh === false) return [];
        while (($line = fgets($fh)) !== false) {
            $parts = explode("\t", rtrim($line, "\r\n"), 4);
            if (count($parts) !== 4) continue;
            $relative = ReleaseSlotPolicy::normalizeRelativePath($parts[3]);
            if ($relative === '') continue;
            $first = explode('/', $relative, 2)[0];
            if ($first !== '') $protected[$first] = true;
        }
        fclose($fh);
        return $protected;
    }

    private function relative(string $path): string
    {
        $root = rtrim($this->root, '/\\');
        if ($path === $root) return '.';
        $prefix = $root . '/';
        return str_starts_with($path, $prefix) ? str_replace('\\','/',substr($path,strlen($prefix))) : $path;
    }

    private function controlDir(): string
    {
        return $this->runtimeDir() . '/release-control';
    }

    private function runtimeDir(): string
    {
        if ($this->runtimeOverride !== null && trim($this->runtimeOverride) !== '') return rtrim($this->runtimeOverride, '/\\');
        $configPath = rtrim($this->root, '/\\') . '/server/team-points/config/config.local.php';
        if (is_file($configPath)) {
            try {
                $config = require $configPath;
                $runtime = is_array($config) ? rtrim((string)(($config['storage']['runtime_dir'] ?? '')), '/\\') : '';
                if ($runtime !== '') return $runtime;
            } catch (\Throwable) {}
        }
        return rtrim($this->root, '/\\') . '/data/runtime-v280';
    }
}
