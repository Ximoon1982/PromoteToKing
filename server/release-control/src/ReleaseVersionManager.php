<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

/**
 * Inventories and removes only release-control-owned immutable artifacts.
 *
 * Scope is deliberately limited to release-control/{releases,previews,runtime-trees}.
 * Shared data/logs/storage, the physical recovery root and unrelated projects are
 * never cleanup targets.
 */
final class ReleaseVersionManager
{
    private readonly ReleaseSlotStore $slots;
    private readonly ReleaseStateStore $state;

    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
        $this->slots = new ReleaseSlotStore($root, $runtimeOverride);
        $this->state = new ReleaseStateStore($root, $runtimeOverride);
    }

    public function inventory(): array
    {
        $state = $this->state->read();
        return $this->inventoryFromState($state);
    }

    public function describeRelease(string $releaseId): ?array
    {
        $releaseId = $this->normalizeReleaseId($releaseId);
        $state = $this->state->read();
        $row = $this->describeFromState($releaseId, $state);
        return !empty($row['exists']) ? $row : null;
    }

    public function deleteRelease(string $releaseId, string $actor): array
    {
        $releaseId = $this->normalizeReleaseId($releaseId);
        $actor = strtolower(trim($actor));
        if ($actor === '') $actor = 'cli';
        if (!preg_match('/^[a-z0-9_.@-]{1,100}$/D', $actor)) {
            throw new \InvalidArgumentException('Release cleanup actor is invalid.');
        }

        return $this->state->withExclusiveLock(function () use ($releaseId, $actor): array {
            // Re-read under the same lock used by promote/rollback. A release that
            // acquired a protected role after page render must never be deleted.
            $state = $this->state->read();
            $row = $this->describeFromState($releaseId, $state);
            if (empty($row['exists'])) {
                throw new \RuntimeException('Release cleanup target no longer exists: ' . $releaseId);
            }
            if (!empty($row['protected'])) {
                throw new \RuntimeException(
                    'Release cleanup refused because ' . $releaseId . ' is protected as: '
                    . implode(', ', (array)$row['roles']) . '.'
                );
            }
            if (empty($row['cleanup_ready'])) {
                throw new \RuntimeException('Release cleanup refused because the managed artifact scan is incomplete or unsafe.');
            }

            $paths = $this->artifactPaths($releaseId);
            // Derived trees first, immutable slot last. If an interruption occurs,
            // the authoritative slot survives until the final deletion phase.
            foreach (['preview','runtime','slot'] as $kind) {
                $path = $paths[$kind];
                if (!file_exists($path) && !is_link($path)) continue;
                $this->removeManagedTree($path, $this->managedRoots()[$kind]);
            }
            foreach ($paths as $path) {
                if (file_exists($path) || is_link($path)) {
                    throw new \RuntimeException('Release cleanup did not fully remove managed artifacts for ' . $releaseId . '.');
                }
            }

            return [
                'ok'=>true,
                'action'=>'delete-release',
                'release_id'=>$releaseId,
                'actor'=>$actor,
                'deleted_at'=>gmdate('c'),
                'removed_artifacts'=>(array)$row['artifacts'],
                'removed_stats'=>(array)$row['stats'],
            ];
        });
    }

    private function inventoryFromState(array $state): array
    {
        $ids = [];
        foreach ($this->managedRoots() as $base) {
            if (!is_dir($base)) continue;
            foreach (array_diff(scandir($base) ?: [], ['.','..']) as $entry) {
                if (!$this->validReleaseId($entry)) continue;
                $path = $base . '/' . $entry;
                if (is_dir($path) || is_link($path)) $ids[$entry] = true;
            }
        }
        ksort($ids, SORT_NATURAL);

        $rows = [];
        $totals = [
            'managed_release_count'=>0,
            'protected_count'=>0,
            'removable_count'=>0,
            'removable_file_entries'=>0,
            'removable_directory_entries'=>0,
            'removable_inode_entries'=>0,
            'removable_apparent_bytes'=>0,
            'estimated_reclaimable_bytes'=>0,
        ];
        foreach (array_keys($ids) as $id) {
            $row = $this->describeFromState($id, $state);
            if (empty($row['exists'])) continue;
            $rows[] = $row;
            $totals['managed_release_count']++;
            if (!empty($row['protected'])) {
                $totals['protected_count']++;
            } elseif (!empty($row['cleanup_ready'])) {
                $totals['removable_count']++;
                $stats = (array)$row['stats'];
                $totals['removable_file_entries'] += (int)($stats['file_entries'] ?? 0);
                $totals['removable_directory_entries'] += (int)($stats['directory_entries'] ?? 0);
                $totals['removable_inode_entries'] += (int)($stats['inode_entries'] ?? 0);
                $totals['removable_apparent_bytes'] += (int)($stats['apparent_bytes'] ?? 0);
                $totals['estimated_reclaimable_bytes'] += (int)($stats['estimated_reclaimable_bytes'] ?? 0);
            }
        }

        usort($rows, static function (array $a, array $b): int {
            if ((bool)$a['protected'] !== (bool)$b['protected']) return $a['protected'] ? -1 : 1;
            return strnatcasecmp((string)$b['release_id'], (string)$a['release_id']);
        });

        return [
            'scope'=>'release-control-managed-artifacts-only',
            'managed_roots'=>array_map(fn(string $p): string => $this->displayPath($p), $this->managedRoots()),
            'releases'=>$rows,
            'totals'=>$totals,
        ];
    }

    private function describeFromState(string $releaseId, array $state): array
    {
        $paths = $this->artifactPaths($releaseId);
        $artifacts = [];
        foreach ($paths as $kind=>$path) {
            if (file_exists($path) || is_link($path)) $artifacts[] = $kind;
        }
        $exists = $artifacts !== [];
        $rolesById = $this->protectedRoles($state);
        $roles = $rolesById[$releaseId] ?? [];
        $stats = $exists ? $this->scanPaths(array_values(array_filter(
            $paths,
            static fn(string $path): bool => file_exists($path) || is_link($path)
        ))) : $this->emptyStats();

        $slotPath = $paths['slot'];
        if (is_dir($slotPath) && !is_link($slotPath)) {
            $slot = $this->slots->inspectSlot($releaseId, false);
        } else {
            $slot = [
                'release_id'=>$releaseId,'version'=>'','source_head'=>'','source_head_short'=>'',
                'created_at'=>'','integrity_status'=>'invalid','errors'=>['release slot missing or unsafe'],
            ];
        }

        return [
            'release_id'=>$releaseId,
            'version'=>(string)($slot['version'] ?? ''),
            'source_head_short'=>(string)($slot['source_head_short'] ?? ''),
            'created_at'=>(string)($slot['created_at'] ?? ''),
            'integrity_status'=>(string)($slot['integrity_status'] ?? 'invalid'),
            'integrity_errors'=>array_values((array)($slot['errors'] ?? [])),
            'roles'=>$roles,
            'protected'=>$roles !== [],
            'removable'=>$roles === [],
            'cleanup_ready'=>$exists && $roles === [] && empty($stats['scan_errors']),
            'exists'=>$exists,
            'artifacts'=>$artifacts,
            'artifact_paths'=>array_map(fn(string $p): string => $this->displayPath($p), $paths),
            'stats'=>$stats,
        ];
    }

    /** @return array<string,list<string>> */
    private function protectedRoles(array $state): array
    {
        $roles = [];
        $add = static function (array &$target, string $id, string $role): void {
            $id = trim($id);
            if ($id === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D', $id)) return;
            if (!isset($target[$id])) $target[$id] = [];
            if (!in_array($role, $target[$id], true)) $target[$id][] = $role;
        };

        $mode = (string)($state['mode'] ?? 'direct-root');
        if ($mode === 'slots') {
            $add($roles, (string)($state['public_release'] ?? ''), 'current public');
            $add($roles, (string)($state['previous_public_release'] ?? ''), 'rollback target');
        }
        $add($roles, (string)($state['candidate_release'] ?? ''), 'candidate');

        $rootId = $this->directRootReleaseId();
        if ($rootId !== '') {
            $add($roles, $rootId, $mode === 'direct-root' ? 'current public (direct root)' : 'physical recovery baseline');
        }
        return $roles;
    }

    private function directRootReleaseId(): string
    {
        $root = rtrim($this->root, '/\\');
        $versionPath = $root . '/VERSION';
        $uiPath = $root . '/ui-v2.html';
        if (!is_file($versionPath) || !is_file($uiPath)) return '';
        $version = trim((string)@file_get_contents($versionPath));
        $ui = (string)@file_get_contents($uiPath);
        if ($version === '' || !preg_match('/p2k-([0-9.]+)-([0-9a-f]{12})-[0-9a-f]{16}/i', $ui, $m)) return '';
        if (!hash_equals($version, $m[1])) return '';
        return $version . '-' . strtolower($m[2]);
    }

    /** @return array{slot:string,preview:string,runtime:string} */
    private function artifactPaths(string $releaseId): array
    {
        $roots = $this->managedRoots();
        return [
            'slot'=>$roots['slot'] . '/' . $releaseId,
            'preview'=>$roots['preview'] . '/' . $releaseId,
            'runtime'=>$roots['runtime'] . '/' . $releaseId,
        ];
    }

    /** @return array{slot:string,preview:string,runtime:string} */
    private function managedRoots(): array
    {
        $control = $this->slots->controlDir();
        return [
            'slot'=>$control . '/releases',
            'preview'=>$control . '/previews',
            'runtime'=>$control . '/runtime-trees',
        ];
    }

    private function scanPaths(array $paths): array
    {
        $stats = $this->emptyStats();
        $inodes = [];
        foreach ($paths as $path) {
            try {
                $this->scanOne($path, $stats, $inodes);
            } catch (\Throwable $e) {
                $stats['scan_errors'][] = $this->displayPath($path) . ': ' . $e->getMessage();
            }
        }

        foreach ($inodes as $inode) {
            $nlink = max(1, (int)$inode['nlink']);
            $occurrences = (int)$inode['occurrences'];
            $bytes = (int)$inode['physical_bytes'];
            if ($occurrences >= $nlink) $stats['estimated_reclaimable_bytes'] += $bytes;
            else $stats['hardlink_preserved_bytes'] += $bytes;
        }
        $stats['scan_errors'] = array_values(array_unique($stats['scan_errors']));
        return $stats;
    }

    private function scanOne(string $path, array &$stats, array &$inodes): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        $this->countEntry($path, $stats, $inodes);
        if (!is_dir($path) || is_link($path)) return;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) $this->countEntry($item->getPathname(), $stats, $inodes);
    }

    private function countEntry(string $path, array &$stats, array &$inodes): void
    {
        $st = @lstat($path);
        if (!is_array($st)) {
            $stats['scan_errors'][] = $this->displayPath($path) . ': lstat failed';
            return;
        }
        $stats['inode_entries']++;
        $mode = (int)($st['mode'] ?? 0) & 0170000;
        if ($mode === 0040000) {
            $stats['directory_entries']++;
            return;
        }
        $stats['file_entries']++;
        if ($mode !== 0100000) return;

        $size = max(0, (int)($st['size'] ?? 0));
        $stats['apparent_bytes'] += $size;
        $dev = (string)($st['dev'] ?? '');
        $ino = (string)($st['ino'] ?? '');
        $key = $dev . ':' . $ino;
        $physical = isset($st['blocks']) && (int)$st['blocks'] > 0 ? (int)$st['blocks'] * 512 : $size;
        if (!isset($inodes[$key])) {
            $inodes[$key] = [
                'occurrences'=>0,
                'nlink'=>max(1, (int)($st['nlink'] ?? 1)),
                'physical_bytes'=>$physical,
            ];
        }
        $inodes[$key]['occurrences']++;
        $inodes[$key]['nlink'] = max((int)$inodes[$key]['nlink'], max(1, (int)($st['nlink'] ?? 1)));
        $inodes[$key]['physical_bytes'] = max((int)$inodes[$key]['physical_bytes'], $physical);
    }

    private function emptyStats(): array
    {
        return [
            'file_entries'=>0,
            'directory_entries'=>0,
            'inode_entries'=>0,
            'apparent_bytes'=>0,
            'estimated_reclaimable_bytes'=>0,
            'hardlink_preserved_bytes'=>0,
            'scan_errors'=>[],
        ];
    }

    private function removeManagedTree(string $path, string $expectedBase): void
    {
        $expected = rtrim($expectedBase, '/\\') . '/';
        if (!str_starts_with($path, $expected) || dirname($path) !== rtrim($expectedBase, '/\\')) {
            throw new \RuntimeException('Release cleanup target escaped its managed root.');
        }
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) throw new \RuntimeException('Unable to remove managed release artifact.');
            return;
        }
        if (!is_dir($path)) return;

        // Release/preview/runtime trees are sealed read-only. Make every managed
        // directory writable before unlinking children; do not follow symlinks.
        @chmod($path, 0700);
        $unseal = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($unseal as $item) {
            if ($item->isDir() && !$item->isLink()) @chmod($item->getPathname(), 0700);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                if (!@rmdir($itemPath) && is_dir($itemPath)) {
                    throw new \RuntimeException('Unable to remove managed release directory.');
                }
            } elseif (!@unlink($itemPath) && (file_exists($itemPath) || is_link($itemPath))) {
                throw new \RuntimeException('Unable to remove managed release file.');
            }
        }
        if (!@rmdir($path) && is_dir($path)) {
            throw new \RuntimeException('Unable to remove managed release root.');
        }
    }

    private function normalizeReleaseId(string $releaseId): string
    {
        $releaseId = trim($releaseId);
        if (!$this->validReleaseId($releaseId)) {
            throw new \InvalidArgumentException('Release cleanup id is invalid.');
        }
        return $releaseId;
    }

    private function validReleaseId(string $releaseId): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D', $releaseId) === 1;
    }

    private function displayPath(string $path): string
    {
        $root = rtrim($this->root, '/\\');
        if (str_starts_with($path, $root . '/')) return './' . substr($path, strlen($root) + 1);
        return $path;
    }
}
