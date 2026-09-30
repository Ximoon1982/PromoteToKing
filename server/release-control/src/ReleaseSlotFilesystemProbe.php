<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleaseSlotFilesystemProbe
{
    public function __construct(
        private readonly string $root,
        private readonly string $runtime
    ) {
    }

    public function probe(bool $persist = true): array
    {
        $control = rtrim($this->runtime, '/\\') . '/release-control';
        $this->ensureDirectory($control, 0700);
        $probe = $control . '/.filesystem-probe-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->ensureDirectory($probe, 0700);

        $hardlinkCrossRoot = false;
        $snapshotIsolation = false;
        $symlinkSupported = false;
        $atomicRename = false;
        $rootDevice = null;
        $runtimeDevice = null;
        $sameDevice = null;

        try {
            $versionPath = rtrim($this->root, '/\\') . '/VERSION';
            $versionStat = @stat($versionPath);
            $runtimeStat = @stat($control);
            if (is_array($versionStat)) $rootDevice = $versionStat['dev'] ?? null;
            if (is_array($runtimeStat)) $runtimeDevice = $runtimeStat['dev'] ?? null;
            if ($rootDevice !== null && $runtimeDevice !== null) $sameDevice = ((string)$rootDevice === (string)$runtimeDevice);

            if (is_file($versionPath) && function_exists('link')) {
                $hard = $probe . '/root-version-hardlink';
                $hardlinkCrossRoot = @link($versionPath, $hard)
                    && is_file($hard)
                    && hash_file('sha256', $versionPath) === hash_file('sha256', $hard);
            }

            if (function_exists('link')) {
                $source = $probe . '/snapshot-source';
                $snapshot = $probe . '/snapshot-link';
                file_put_contents($source, "before\n", LOCK_EX);
                if (@link($source, $snapshot)) {
                    $replacement = $probe . '/snapshot-replacement';
                    file_put_contents($replacement, "after\n", LOCK_EX);
                    if (@rename($replacement, $source)) {
                        $snapshotIsolation = ((string)@file_get_contents($snapshot) === "before\n")
                            && ((string)@file_get_contents($source) === "after\n");
                    }
                }
            }

            if (function_exists('symlink') && is_file($versionPath)) {
                $symlink = $probe . '/root-version-symlink';
                $symlinkSupported = @symlink($versionPath, $symlink)
                    && is_link($symlink)
                    && @file_get_contents($symlink) === @file_get_contents($versionPath);
            }

            $renameSource = $probe . '/rename-source';
            $renameTarget = $probe . '/rename-target';
            file_put_contents($renameSource, "rename-ok\n", LOCK_EX);
            $atomicRename = @rename($renameSource, $renameTarget)
                && is_file($renameTarget)
                && ((string)@file_get_contents($renameTarget) === "rename-ok\n");

            $selected = ($hardlinkCrossRoot && $snapshotIsolation && $atomicRename) ? 'hardlink' : 'copy';
            $result = [
                'schema_version' => 1,
                'probed_at' => gmdate('c'),
                'root_device' => $rootDevice,
                'runtime_device' => $runtimeDevice,
                'same_device' => $sameDevice,
                'hardlink_supported' => $hardlinkCrossRoot,
                'hardlink_snapshot_isolation' => $snapshotIsolation,
                'symlink_supported' => $symlinkSupported,
                'symlink_snapshot_safe' => false,
                'atomic_rename_supported' => $atomicRename,
                'selected_strategy' => $selected,
            ];
            if ($persist && $atomicRename) $this->persist($control . '/filesystem-capabilities.json', $result);
            return $result;
        } finally {
            $this->removeTree($probe);
        }
    }

    private function persist(string $path, array $payload): void
    {
        $tmp = $path . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to stage release filesystem capabilities.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to publish release filesystem capabilities atomically.');
        }
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (is_dir($path)) return;
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to prepare release-control runtime directory.');
        }
        @chmod($path, $mode);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        @chmod($path, 0700);
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
