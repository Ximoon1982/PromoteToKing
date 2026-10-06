<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

/**
 * Materializes a routable runtime tree for a sealed release slot.
 *
 * Release-owned files stay immutable (hard-linked when supported); mutable data,
 * logs, storage and host-local configuration are linked from the stable public
 * installation. Runtime trees are prepared and verified before a state transition,
 * so the public switch itself is only an atomic state-file replacement.
 */
final class ReleaseRuntimeTree
{
    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
    }

    public function prepare(string $releaseId): array
    {
        $store = new ReleaseSlotStore($this->root, $this->runtimeOverride);
        $slot = $store->inspectSlot($releaseId, true);
        if (($slot['integrity_status'] ?? '') !== 'valid') {
            throw new \RuntimeException('Public runtime requires a fully verified release slot.');
        }

        $fs = $store->filesystemCapabilities();
        if (!is_array($fs) || empty($fs['symlink_supported']) || empty($fs['atomic_rename_supported'])) {
            throw new \RuntimeException('Public runtime requires validated symlink and atomic-rename support.');
        }

        $base = $store->controlDir() . '/runtime-trees';
        $this->ensureDirectory($base, 0700);
        $target = $base . '/' . $releaseId;
        $existing = $this->describeExisting($releaseId, (string)($slot['manifest_sha256'] ?? ''));
        if (is_array($existing)) return $existing;
        if (is_dir($target)) $this->removeTree($target);

        $tmp = $base . '/.runtime-' . getmypid() . '-' . bin2hex(random_bytes(5));
        $app = $tmp . '/app';
        $metaDir = $tmp . '/meta';
        $this->ensureDirectory($app, 0755);
        $this->ensureDirectory($metaDir, 0700);

        $slotRoot = $store->slotsDir() . '/' . $releaseId;
        $manifestPath = $slotRoot . '/meta/manifest.tsv';
        $linked = 0;
        $copied = 0;
        $fh = null;

        try {
            $fh = @fopen($manifestPath, 'rb');
            if ($fh === false) throw new \RuntimeException('Release manifest is unavailable.');
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') continue;
                $parts = explode("\t", $line, 4);
                if (count($parts) !== 4) throw new \RuntimeException('Release manifest row is invalid.');
                $path = ReleaseSlotPolicy::normalizeRelativePath($parts[3]);
                if ($path === '' || !ReleaseSlotPolicy::isReleaseOwnedPathForVersion($path, (int)($slot['policy_version'] ?? ReleaseSlotPolicy::POLICY_VERSION))) {
                    throw new \RuntimeException('Release runtime manifest contains shared/recovery path.');
                }

                $source = $slotRoot . '/app/' . $path;
                if (!is_file($source) || is_link($source)) throw new \RuntimeException('Release runtime source file is missing or unsafe: ' . $path);
                $dest = $app . '/' . $path;
                $this->ensureDirectory(dirname($dest), 0755);
                if (function_exists('link') && @link($source, $dest)) {
                    $linked++;
                } else {
                    if (!@copy($source, $dest)) throw new \RuntimeException('Unable to materialize release runtime file: ' . $path);
                    $copied++;
                }
            }
            fclose($fh);
            $fh = null;

            $sharedLinks = [];
            foreach (['data', 'logs', 'storage'] as $name) {
                $source = rtrim($this->root, '/\\') . '/' . $name;
                if (!is_dir($source)) continue;
                $dest = $app . '/' . $name;
                if (!@symlink($source, $dest)) throw new \RuntimeException('Unable to link shared public runtime directory: ' . $name);
                $sharedLinks[] = $name . '/';
            }

            foreach ($this->hostLocalFiles() as $relative => $source) {
                $dest = $app . '/' . $relative;
                $this->ensureDirectory(dirname($dest), 0755);
                if (file_exists($dest) || is_link($dest)) @unlink($dest);
                if (!@symlink($source, $dest)) throw new \RuntimeException('Unable to link host-local public runtime config: ' . $relative);
                $sharedLinks[] = $relative;
            }

            sort($sharedLinks, SORT_STRING);
            $meta = [
                'schema_version'=>1,
                'release_id'=>$releaseId,
                'slot_manifest_sha256'=>(string)($slot['manifest_sha256'] ?? ''),
                'created_at'=>gmdate('c'),
                'linked_files'=>$linked,
                'copied_files'=>$copied,
                'shared_links'=>$sharedLinks,
                'public_root'=>rtrim($this->root, '/\\'),
            ];
            $raw = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($metaDir . '/runtime.json', $raw, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write public runtime metadata.');
            }
            $this->sealDirectories($tmp);

            if (is_dir($target)) {
                $this->removeTree($tmp);
            } elseif (!@rename($tmp, $target)) {
                $this->unsealDirectories($tmp);
                throw new \RuntimeException('Unable to publish release runtime tree atomically.');
            }

            $result = $this->describeExisting($releaseId, (string)($slot['manifest_sha256'] ?? ''));
            if (!is_array($result)) {
                $this->removeTree($target);
                throw new \RuntimeException('Published release runtime tree failed verification.');
            }
            return $result;
        } catch (\Throwable $e) {
            if (is_resource($fh)) fclose($fh);
            $this->removeTree($tmp);
            throw $e;
        }
    }

    public function describeExisting(string $releaseId, string $expectedManifest = ''): ?array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D', $releaseId)) return null;
        $store = new ReleaseSlotStore($this->root, $this->runtimeOverride);
        $target = $store->controlDir() . '/runtime-trees/' . $releaseId;
        $runtimeMeta = $target . '/meta/runtime.json';
        $slotMetaPath = $store->slotsDir() . '/' . $releaseId . '/meta/slot.json';
        $sealPath = $store->slotsDir() . '/' . $releaseId . '/meta/SEALED';
        if (!is_file($runtimeMeta) || !is_file($slotMetaPath) || !is_file($sealPath) || !is_dir($target . '/app')) return null;

        try {
            $meta = json_decode((string)file_get_contents($runtimeMeta), true, 32, JSON_THROW_ON_ERROR);
            $slot = json_decode((string)file_get_contents($slotMetaPath), true, 64, JSON_THROW_ON_ERROR);
            $seal = strtolower(trim((string)file_get_contents($sealPath)));
            $manifest = strtolower(trim((string)($slot['manifest_sha256'] ?? '')));
            $runtimeManifest = strtolower(trim((string)($meta['slot_manifest_sha256'] ?? '')));
            $expectedManifest = strtolower(trim($expectedManifest));
            if (!is_array($meta) || !is_array($slot)
                || ($meta['release_id'] ?? '') !== $releaseId
                || ($slot['release_id'] ?? '') !== $releaseId
                || empty($slot['sealed'])
                || $manifest === '' || !hash_equals($manifest, $seal)
                || !hash_equals($manifest, $runtimeManifest)
                || ($expectedManifest !== '' && !hash_equals($manifest, $expectedManifest))) {
                return null;
            }
            return [
                'release_id'=>$releaseId,
                'version'=>(string)($slot['version'] ?? ''),
                'source_head'=>(string)($slot['source_head'] ?? ''),
                'cache_key'=>(string)($slot['cache_key'] ?? ''),
                'manifest_sha256'=>$manifest,
                'app_root'=>$target . '/app',
                'created_at'=>(string)($meta['created_at'] ?? ''),
                'linked_files'=>(int)($meta['linked_files'] ?? 0),
                'copied_files'=>(int)($meta['copied_files'] ?? 0),
                'shared_links'=>is_array($meta['shared_links'] ?? null) ? $meta['shared_links'] : [],
                'valid'=>true,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function hostLocalFiles(): array
    {
        $root = rtrim($this->root, '/\\');
        $out = [];
        foreach (glob($root . '/.env*') ?: [] as $path) {
            if (is_file($path)) $out[basename($path)] = $path;
        }

        $server = $root . '/server';
        if (!is_dir($server)) return $out;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($server, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) continue;
            $full = $item->getPathname();
            $relative = str_replace('\\', '/', substr($full, strlen($root) + 1));
            if (str_starts_with(strtolower($relative), 'server/release-control/')) continue;
            $name = strtolower($item->getFilename());
            if (($name === '.env' || str_starts_with($name, '.env.') || str_contains($name, '.local.'))
                && !str_contains($name, '.example.')) {
                $out[$relative] = $full;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to prepare public runtime directory.');
        }
        @chmod($path, $mode);
    }

    private function sealDirectories(string $root): void
    {
        $dirs = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) if ($item->isDir() && !$item->isLink()) $dirs[] = $item->getPathname();
        foreach ($dirs as $dir) @chmod($dir, 0555);
        @chmod($root, 0555);
    }

    private function unsealDirectories(string $root): void
    {
        if (!is_dir($root)) return;
        @chmod($root, 0700);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) if ($item->isDir() && !$item->isLink()) @chmod($item->getPathname(), 0700);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        $this->unsealDirectories($path);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
