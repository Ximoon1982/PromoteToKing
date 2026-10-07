<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class FilesystemCleanupManager
{
    private const BACKUP_MIN_AGE = 604800; // 7 days
    private const STAGING_MIN_AGE = 86400; // 24 hours
    private const KEEP_NEWEST_BACKUPS = 3;

    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
    }

    public function inventory(): array
    {
        $protected = $this->physicalBaselineTopLevelPaths();
        $rows = [];
        foreach ($this->topLevelCandidates($protected) as $row) $rows[$row['relative_path']] = $row;
        foreach ($this->backupCandidates() as $row) $rows[$row['relative_path']] = $row;
        foreach ($this->stagingCandidates() as $row) $rows[$row['relative_path']] = $row;
        ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);

        $totals = [
            'candidate_count'=>count($rows),
            'file_entries'=>0,
            'directory_entries'=>0,
            'inode_entries'=>0,
            'apparent_bytes'=>0,
            'estimated_reclaimable_bytes'=>0,
        ];
        foreach ($rows as $row) {
            $stats = (array)($row['stats'] ?? []);
            foreach (['file_entries','directory_entries','inode_entries','apparent_bytes','estimated_reclaimable_bytes'] as $key) {
                $totals[$key] += (int)($stats[$key] ?? 0);
            }
        }

        return [
            'scope'=>'conservative-p2k-maintenance-artifacts-only',
            'root'=>$this->root,
            'candidates'=>array_values($rows),
            'totals'=>$totals,
            'rules'=>[
                'Unknown top-level files and directories are ignored and can never be deleted by this tool.',
                'Physical recovery-baseline paths are protected from cleanup.',
                'Other projects are outside cleanup scope unless their name exactly matches a P2K installer/extraction pattern.',
                'Release-control backups are eligible only after 7 days and the newest three backups are retained.',
                'Release-control staging leftovers are eligible only after 24 hours.',
            ],
        ];
    }

    public function describe(string $relativePath): ?array
    {
        $relativePath = $this->normalizeRelative($relativePath);
        if ($relativePath === '') throw new \InvalidArgumentException('Filesystem cleanup path is invalid.');
        foreach ($this->inventory()['candidates'] as $row) {
            if (hash_equals((string)$row['relative_path'], $relativePath)) return $row;
        }
        return null;
    }

    public function delete(string $relativePath, string $actor): array
    {
        $relativePath = $this->normalizeRelative($relativePath);
        if ($relativePath === '') throw new \InvalidArgumentException('Filesystem cleanup path is invalid.');
        $actor = strtolower(trim($actor));
        if ($actor === '') $actor = 'web';
        if (!preg_match('/^[a-z0-9_.@-]{1,100}$/D', $actor)) throw new \InvalidArgumentException('Filesystem cleanup actor is invalid.');

        // Reclassify immediately before deletion. A path that no longer satisfies the
        // conservative rules is refused rather than trusting a stale page render.
        $row = $this->describe($relativePath);
        if (!is_array($row) || empty($row['cleanup_ready'])) throw new \RuntimeException('Filesystem cleanup target is no longer eligible for safe deletion.');
        $path = $this->absoluteFromRelative($relativePath);
        if (is_link($path)) throw new \RuntimeException('Filesystem cleanup refuses symbolic-link targets.');
        $before = (array)($row['stats'] ?? []);
        $this->removeTree($path);
        if (file_exists($path) || is_link($path)) throw new \RuntimeException('Filesystem cleanup target could not be fully removed.');
        $this->appendAudit([
            'schema_version'=>1,'action'=>'delete','path'=>$relativePath,'category'=>$row['category'] ?? '',
            'actor'=>$actor,'at'=>gmdate('c'),'stats'=>$before,
        ]);
        return ['ok'=>true,'relative_path'=>$relativePath,'category'=>$row['category'] ?? '','stats'=>$before];
    }

    /** @return list<array<string,mixed>> */
    private function topLevelCandidates(array $protected): array
    {
        $root = rtrim($this->root, '/\\');
        $out = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || isset($protected[$name])) continue;
            $path = $root . '/' . $name;
            if (is_link($path)) continue;
            $kind = null;
            $reason = '';
            if (is_file($path) && $this->isInstallerArchiveName($name)) {
                $kind = 'installer archive';
                $reason = 'Top-level P2K installer/package archive not owned by the physical recovery baseline.';
            } elseif (is_dir($path) && $this->isInstallerExtractionName($name)) {
                $kind = 'installer extraction';
                $reason = 'Top-level extracted P2K installer/package directory not owned by the physical recovery baseline.';
            }
            if ($kind === null) continue;
            $out[] = $this->row($path, $kind, $reason, true);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function backupCandidates(): array
    {
        $base = rtrim($this->root, '/\\') . '/storage/release-backups';
        if (!is_dir($base) || is_link($base)) return [];
        $eligibleNames = [];
        foreach (scandir($base) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $base . '/' . $name;
            if (!is_dir($path) || is_link($path) || !$this->isReleaseBackupName($name)) continue;
            $eligibleNames[] = ['name'=>$name,'mtime'=>(int)(filemtime($path) ?: 0)];
        }
        usort($eligibleNames, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        $keep = [];
        foreach (array_slice($eligibleNames, 0, self::KEEP_NEWEST_BACKUPS) as $item) $keep[$item['name']] = true;
        $now = time();
        $out = [];
        foreach ($eligibleNames as $item) {
            if (isset($keep[$item['name']])) continue;
            if ($item['mtime'] <= 0 || $now - $item['mtime'] < self::BACKUP_MIN_AGE) continue;
            $path = $base . '/' . $item['name'];
            $out[] = $this->row($path, 'release backup', 'Release-control recovery backup older than 7 days; the newest three backups are retained.', true);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function stagingCandidates(): array
    {
        $control = $this->controlDir();
        $locations = [
            [$control . '/uploads', ['.incoming-','.extract-'], 'package staging'],
            [$control . '/releases', ['.candidate-'], 'candidate staging'],
            [$control . '/previews', ['.preview-'], 'preview staging'],
            [$control . '/runtime-trees', ['.runtime-'], 'runtime staging'],
        ];
        $now = time();
        $out = [];
        foreach ($locations as [$base,$prefixes,$category]) {
            if (!is_dir($base) || is_link($base) || !$this->isWithinRoot($base)) continue;
            foreach (scandir($base) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                $matches = false;
                foreach ($prefixes as $prefix) if (str_starts_with($name, $prefix)) { $matches = true; break; }
                if (!$matches) continue;
                $path = $base . '/' . $name;
                if (is_link($path) || (!is_file($path) && !is_dir($path))) continue;
                $mtime = (int)(filemtime($path) ?: 0);
                if ($mtime <= 0 || $now - $mtime < self::STAGING_MIN_AGE) continue;
                $out[] = $this->row($path, $category, 'Stale release-control temporary/staging artifact older than 24 hours.', true);
            }
        }
        return $out;
    }

    private function row(string $path, string $category, string $reason, bool $ready): array
    {
        $mtime = (int)(filemtime($path) ?: 0);
        $stats = $this->scanPath($path);
        return [
            'relative_path'=>$this->relative($path),
            'category'=>$category,
            'reason'=>$reason,
            'modified_at'=>$mtime > 0 ? gmdate('c', $mtime) : null,
            'age_seconds'=>$mtime > 0 ? max(0, time() - $mtime) : null,
            'cleanup_ready'=>$ready && !is_link($path) && empty($stats['scan_errors']),
            'stats'=>$stats,
        ];
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
            $path = ReleaseSlotPolicy::normalizeRelativePath($parts[3]);
            if ($path === '') continue;
            $first = explode('/', $path, 2)[0];
            if ($first !== '') $protected[$first] = true;
        }
        fclose($fh);
        foreach (['data','logs','storage','server','assets','.htaccess','ReleaseControl.php','PreviewRouter.php','PublicRouter.php','VERSION','ui-v2.html'] as $always) {
            $protected[$always] = true;
        }
        return $protected;
    }

    private function isInstallerArchiveName(string $name): bool
    {
        if (!preg_match('/^PromoteToKing[_-]/i', $name)) return false;
        $lower = strtolower($name);
        if (preg_match('/\.(zip|tgz|tar|tar\.gz)$/i', $name)) return true;
        return str_ends_with($lower, '.run') && (str_contains($lower, 'installer') || str_contains($lower, 'incremental'));
    }

    private function isInstallerExtractionName(string $name): bool
    {
        if (preg_match('/^PromoteToKing_v\d+(?:\.\d+)+(?:[_-].*)?$/i', $name)) return true;
        if (preg_match('/^PromoteToKing-v\d+(?:\.\d+)+(?:[_-].*)?$/i', $name)) return true;
        return preg_match('/^p2k-(?:install|installer|package|extract|release)-/i', $name) === 1;
    }

    private function isReleaseBackupName(string $name): bool
    {
        return preg_match('/^(?:v\d+(?:\.\d+)+-|preview-infra-v\d+(?:\.\d+)+-|web-upload-v\d+(?:\.\d+)+-)/i', $name) === 1;
    }

    private function scanPath(string $path): array
    {
        $stats = ['file_entries'=>0,'directory_entries'=>0,'inode_entries'=>0,'apparent_bytes'=>0,'estimated_reclaimable_bytes'=>0,'hardlink_preserved_bytes'=>0,'scan_errors'=>[]];
        $inodes = [];
        try {
            $this->countEntry($path, $stats, $inodes);
            if (is_dir($path) && !is_link($path)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
                foreach ($it as $item) $this->countEntry($item->getPathname(), $stats, $inodes);
            }
        } catch (\Throwable $e) {
            $stats['scan_errors'][] = $e->getMessage();
        }
        foreach ($inodes as $inode) {
            $nlink = max(1, (int)$inode['nlink']);
            $occurrences = (int)$inode['occurrences'];
            $bytes = (int)$inode['physical_bytes'];
            if ($occurrences >= $nlink) $stats['estimated_reclaimable_bytes'] += $bytes;
            else $stats['hardlink_preserved_bytes'] += $bytes;
        }
        $stats['inode_entries'] = $stats['file_entries'] + $stats['directory_entries'];
        return $stats;
    }

    private function countEntry(string $path, array &$stats, array &$inodes): void
    {
        if (is_link($path)) throw new \RuntimeException('Cleanup candidate contains a symbolic link and must be reviewed manually: ' . $this->relative($path));
        $st = @lstat($path);
        if (!is_array($st)) throw new \RuntimeException('Unable to stat cleanup candidate: ' . $this->relative($path));
        if (is_dir($path)) { $stats['directory_entries']++; return; }
        if (!is_file($path)) return;
        $stats['file_entries']++;
        $size = max(0, (int)($st['size'] ?? 0));
        $stats['apparent_bytes'] += $size;
        $key = (string)($st['dev'] ?? 0) . ':' . (string)($st['ino'] ?? 0);
        $physical = isset($st['blocks']) ? max(0, (int)$st['blocks']) * 512 : $size;
        if (!isset($inodes[$key])) $inodes[$key] = ['nlink'=>max(1,(int)($st['nlink'] ?? 1)),'occurrences'=>0,'physical_bytes'=>$physical];
        $inodes[$key]['occurrences']++;
    }

    private function appendAudit(array $row): void
    {
        $dir = $this->controlDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return;
        @file_put_contents($dir . '/filesystem-cleanup.log', json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function normalizeRelative(string $relative): string
    {
        $relative = ReleaseSlotPolicy::normalizeRelativePath($relative);
        if ($relative === '') return '';
        return $relative;
    }

    private function absoluteFromRelative(string $relative): string
    {
        $relative = $this->normalizeRelative($relative);
        if ($relative === '') throw new \InvalidArgumentException('Filesystem cleanup path is invalid.');
        return rtrim($this->root, '/\\') . '/' . $relative;
    }

    private function isWithinRoot(string $path): bool
    {
        $root = rtrim($this->root, '/\\');
        $path = rtrim($path, '/\\');
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function relative(string $path): string
    {
        $root = rtrim($this->root, '/\\');
        $prefix = $root . '/';
        if ($path === $root) return '.';
        if (!str_starts_with($path, $prefix)) throw new \RuntimeException('Filesystem cleanup path escaped the PromoteToKing root.');
        return str_replace('\\', '/', substr($path, strlen($prefix)));
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
            } catch (\Throwable) {
            }
        }
        return rtrim($this->root, '/\\') . '/data/runtime-v280';
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) throw new \RuntimeException('Filesystem cleanup refuses symbolic links.');
        if (is_file($path)) {
            if (!@unlink($path)) throw new \RuntimeException('Unable to remove cleanup file.');
            return;
        }
        if (!is_dir($path)) return;
        @chmod($path, 0700);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            $p = $item->getPathname();
            if ($item->isLink()) throw new \RuntimeException('Cleanup candidate gained a symbolic link and deletion was refused.');
            if ($item->isDir()) { @chmod($p, 0700); if (!@rmdir($p)) throw new \RuntimeException('Unable to remove cleanup directory: ' . $this->relative($p)); }
            elseif (!@unlink($p)) throw new \RuntimeException('Unable to remove cleanup file: ' . $this->relative($p));
        }
        if (!@rmdir($path)) throw new \RuntimeException('Unable to remove cleanup directory: ' . $this->relative($path));
    }
}
