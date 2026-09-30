<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleasePreviewTree
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
        if (($slot['integrity_status'] ?? '') !== 'valid' || empty($slot['candidate'])) {
            throw new \RuntimeException('Preview requires a fully verified candidate slot.');
        }
        if (!empty($slot['routing_enabled'])) {
            throw new \RuntimeException('Candidate slot unexpectedly enables public routing.');
        }

        $fs = $store->filesystemCapabilities();
        if (!is_array($fs) || empty($fs['symlink_supported']) || empty($fs['atomic_rename_supported'])) {
            throw new \RuntimeException('Preview requires validated symlink and atomic-rename support.');
        }

        $base = $store->controlDir() . '/previews';
        $this->ensureDirectory($base, 0700);
        $target = $base . '/' . $releaseId;
        $metaPath = $target . '/meta/preview.json';
        if (is_file($metaPath)) {
            try {
                $meta = json_decode((string)file_get_contents($metaPath), true, 32, JSON_THROW_ON_ERROR);
                if (is_array($meta)
                    && ($meta['release_id'] ?? '') === $releaseId
                    && ($meta['candidate_manifest_sha256'] ?? '') === ($slot['manifest_sha256'] ?? '')
                    && is_dir($target . '/app')) {
                    return $this->describe($target, $slot, $meta);
                }
            } catch (\Throwable) {
            }
            $this->removeTree($target);
        }

        $tmp = $base . '/.preview-' . getmypid() . '-' . bin2hex(random_bytes(5));
        $app = $tmp . '/app';
        $metaDir = $tmp . '/meta';
        $this->ensureDirectory($app, 0755);
        $this->ensureDirectory($metaDir, 0700);

        $slotRoot = $store->slotsDir() . '/' . $releaseId;
        $manifestPath = $slotRoot . '/meta/manifest.tsv';
        $linked = 0;
        $copied = 0;
        try {
            $fh = @fopen($manifestPath, 'rb');
            if ($fh === false) throw new \RuntimeException('Candidate manifest is unavailable.');
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') continue;
                $parts = explode("\t", $line, 4);
                if (count($parts) !== 4) throw new \RuntimeException('Candidate manifest row is invalid.');
                $path = ReleaseSlotPolicy::normalizeRelativePath($parts[3]);
                if ($path === '' || !ReleaseSlotPolicy::isReleaseOwnedPath($path)) {
                    throw new \RuntimeException('Candidate preview manifest contains shared/recovery path.');
                }
                $source = $slotRoot . '/app/' . $path;
                if (!is_file($source) || is_link($source)) throw new \RuntimeException('Candidate preview source file is missing or unsafe.');
                $dest = $app . '/' . $path;
                $this->ensureDirectory(dirname($dest), 0755);
                if (function_exists('link') && @link($source, $dest)) {
                    $linked++;
                } else {
                    if (!@copy($source, $dest)) throw new \RuntimeException('Unable to materialize candidate preview file: ' . $path);
                    $copied++;
                }
            }
            fclose($fh);

            $sharedLinks = [];
            foreach (['data', 'logs', 'storage'] as $name) {
                $source = rtrim($this->root, '/\\') . '/' . $name;
                if (!is_dir($source)) continue;
                $dest = $app . '/' . $name;
                if (!@symlink($source, $dest)) throw new \RuntimeException('Unable to link shared preview directory: ' . $name);
                $sharedLinks[] = $name . '/';
            }

            foreach ($this->hostLocalFiles() as $relative => $source) {
                $dest = $app . '/' . $relative;
                $this->ensureDirectory(dirname($dest), 0755);
                if (file_exists($dest) || is_link($dest)) @unlink($dest);
                if (!@symlink($source, $dest)) throw new \RuntimeException('Unable to link host-local preview config: ' . $relative);
                $sharedLinks[] = $relative;
            }

            sort($sharedLinks, SORT_STRING);
            $meta = [
                'schema_version'=>1,
                'release_id'=>$releaseId,
                'candidate_manifest_sha256'=>(string)($slot['manifest_sha256'] ?? ''),
                'created_at'=>gmdate('c'),
                'linked_files'=>$linked,
                'copied_files'=>$copied,
                'shared_links'=>$sharedLinks,
                'public_root'=>rtrim($this->root, '/\\'),
            ];
            if (file_put_contents($metaDir . '/preview.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write candidate preview metadata.');
            }
            $this->sealDirectories($tmp);

            if (is_dir($target)) {
                $this->removeTree($tmp);
            } elseif (!@rename($tmp, $target)) {
                $this->unsealDirectories($tmp);
                throw new \RuntimeException('Unable to publish candidate preview tree atomically.');
            }
            return $this->describe($target, $slot, $meta);
        } catch (\Throwable $e) {
            if (isset($fh) && is_resource($fh)) fclose($fh);
            $this->removeTree($tmp);
            throw $e;
        }
    }

    public function describeExisting(string $releaseId): ?array
    {
        $store = new ReleaseSlotStore($this->root, $this->runtimeOverride);
        $target = $store->controlDir() . '/previews/' . $releaseId;
        $metaPath = $target . '/meta/preview.json';
        if (!is_file($metaPath) || !is_dir($target . '/app')) return null;
        try {
            $meta = json_decode((string)file_get_contents($metaPath), true, 32, JSON_THROW_ON_ERROR);
            $slot = $store->inspectSlot($releaseId, false);
            if (!is_array($meta)
                || ($slot['integrity_status'] ?? '') !== 'valid'
                || ($meta['release_id'] ?? '') !== $releaseId
                || ($meta['candidate_manifest_sha256'] ?? '') !== ($slot['manifest_sha256'] ?? '')) {
                return null;
            }
            return $this->describe($target, $slot, $meta);
        } catch (\Throwable) {
            return null;
        }
    }

    private function describe(string $target, array $slot, array $meta): array
    {
        return [
            'release_id'=>(string)($slot['release_id'] ?? ''),
            'app_root'=>$target . '/app',
            'created_at'=>(string)($meta['created_at'] ?? ''),
            'linked_files'=>(int)($meta['linked_files'] ?? 0),
            'copied_files'=>(int)($meta['copied_files'] ?? 0),
            'shared_links'=>is_array($meta['shared_links'] ?? null) ? $meta['shared_links'] : [],
            'valid'=>true,
        ];
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
            throw new \RuntimeException('Unable to prepare preview directory.');
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
