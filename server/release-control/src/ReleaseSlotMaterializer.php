<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleaseSlotMaterializer
{
    private readonly ReleaseSlotStore $store;

    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
        $this->store = new ReleaseSlotStore($root, $runtimeOverride);
    }

    public function materialize(
        iterable $paths,
        string $version,
        string $sourceHead = '',
        string $cacheKey = ''
    ): array {
        $version = trim($version);
        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?$/D', $version)) {
            throw new \InvalidArgumentException('Release slot VERSION is invalid.');
        }
        $sourceHead = strtolower(trim($sourceHead));
        if ($sourceHead !== '' && !preg_match('/^[0-9a-f]{12,64}$/D', $sourceHead)) {
            throw new \InvalidArgumentException('Release slot source HEAD is invalid.');
        }
        $cacheKey = trim($cacheKey);
        $paths = ReleaseSlotPolicy::normalizeReleasePaths($paths);
        if ($paths === []) throw new \InvalidArgumentException('Release slot file list is empty.');

        $runtime = $this->store->runtimeDir();
        $control = $this->store->controlDir();
        $slots = $this->store->slotsDir();
        $this->ensureDirectory($runtime, 0700);
        $this->ensureDirectory($control, 0700);
        $this->ensureDirectory($slots, 0700);

        $probe = (new ReleaseSlotFilesystemProbe($this->root, $runtime))->probe(true);
        if (empty($probe['atomic_rename_supported'])) {
            throw new \RuntimeException('Release slots require atomic rename support in protected runtime storage.');
        }
        $preferred = (string)($probe['selected_strategy'] ?? 'copy');
        if ((string)(getenv('P2K_RELEASE_SLOT_FORCE_COPY') ?: '') === '1') $preferred = 'copy';
        if (!in_array($preferred, ['hardlink', 'copy'], true)) $preferred = 'copy';

        $identity = $this->detectBuildIdentity();
        if ($sourceHead === '') $sourceHead = (string)($identity['source_head_short'] ?? '');
        if ($cacheKey === '') $cacheKey = (string)($identity['cache_key'] ?? '');
        $sourceShort = $sourceHead !== '' ? substr($sourceHead, 0, 12) : (string)($identity['source_head_short'] ?? '');

        $tmp = $slots . '/.tmp-' . getmypid() . '-' . bin2hex(random_bytes(5));
        $app = $tmp . '/app';
        $metaDir = $tmp . '/meta';
        $this->ensureDirectory($app, 0755);
        $this->ensureDirectory($metaDir, 0700);

        $manifestRows = [];
        $hardlinked = 0;
        $copied = 0;
        $logicalBytes = 0;
        $additionalBytes = 0;
        $rootReal = realpath($this->root);
        if ($rootReal === false) {
            $this->removeTree($tmp);
            throw new \RuntimeException('Release source root is unavailable.');
        }
        $rootPrefix = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';

        try {
            foreach ($paths as $path) {
                $source = rtrim($this->root, '/\\') . '/' . $path;
                if (is_link($source)) throw new \RuntimeException('Release-owned source path is a symlink: ' . $path);
                if (!is_file($source)) throw new \RuntimeException('Release-owned source file is missing: ' . $path);
                $sourceReal = realpath($source);
                if ($sourceReal === false || !str_starts_with(str_replace('\\', '/', $sourceReal), $rootPrefix)) {
                    throw new \RuntimeException('Release-owned source resolves outside the application root: ' . $path);
                }
                $sha = hash_file('sha256', $source);
                if (!is_string($sha) || $sha === '') throw new \RuntimeException('Unable to hash release file: ' . $path);
                $bytes = filesize($source);
                if ($bytes === false) throw new \RuntimeException('Unable to size release file: ' . $path);
                $logicalBytes += $bytes;

                $dest = $app . '/' . $path;
                $this->ensureDirectory(dirname($dest), 0755);
                $method = 'copy';
                if ($preferred === 'hardlink' && function_exists('link') && @link($source, $dest)) {
                    $method = 'hardlink';
                    $hardlinked++;
                } else {
                    if (!@copy($source, $dest)) throw new \RuntimeException('Unable to copy release file into slot: ' . $path);
                    $mode = @fileperms($source);
                    if (is_int($mode)) @chmod($dest, $mode & 0777);
                    $copied++;
                    $additionalBytes += $bytes;
                }
                $destSha = hash_file('sha256', $dest);
                if (!is_string($destSha) || !hash_equals($sha, $destSha)) {
                    throw new \RuntimeException('Release slot copy/hash verification failed: ' . $path);
                }
                $manifestRows[] = $sha . "\t" . $bytes . "\t" . $method . "\t" . $path;
            }

            sort($manifestRows, SORT_STRING);
            $manifest = implode("\n", $manifestRows) . "\n";
            $manifestPath = $metaDir . '/manifest.tsv';
            if (file_put_contents($manifestPath, $manifest, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write release slot manifest.');
            }
            $manifestSha = hash('sha256', $manifest);
            if ($sourceShort === '') $sourceShort = substr($manifestSha, 0, 12);
            $releaseId = $version . '-' . $sourceShort;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D', $releaseId)) {
                throw new \RuntimeException('Derived release slot id is invalid.');
            }
            $final = $slots . '/' . $releaseId;
            $createdAt = gmdate('c');
            $actualStrategy = $hardlinked > 0 && $copied > 0 ? 'mixed' : ($hardlinked > 0 ? 'hardlink' : 'copy');
            $slotMeta = [
                'schema_version' => 1,
                'policy_version' => ReleaseSlotPolicy::POLICY_VERSION,
                'release_id' => $releaseId,
                'version' => $version,
                'source_head' => strlen($sourceHead) >= 40 ? $sourceHead : '',
                'source_head_short' => $sourceShort,
                'cache_key' => $cacheKey,
                'created_at' => $createdAt,
                'strategy' => $actualStrategy,
                'preferred_strategy' => $preferred,
                'file_count' => count($manifestRows),
                'logical_bytes' => $logicalBytes,
                'additional_bytes_at_creation' => $additionalBytes,
                'hardlinked_files' => $hardlinked,
                'copied_files' => $copied,
                'manifest_sha256' => $manifestSha,
                'sealed' => true,
                'full_hash_verified_at' => $createdAt,
                'routing_enabled' => false,
                'shared_paths_external' => ReleaseSlotPolicy::sharedPathDescriptions(),
                'filesystem_probe' => [
                    'same_device' => $probe['same_device'] ?? null,
                    'hardlink_supported' => (bool)($probe['hardlink_supported'] ?? false),
                    'hardlink_snapshot_isolation' => (bool)($probe['hardlink_snapshot_isolation'] ?? false),
                    'symlink_supported' => (bool)($probe['symlink_supported'] ?? false),
                    'atomic_rename_supported' => (bool)($probe['atomic_rename_supported'] ?? false),
                ],
            ];
            $slotJson = json_encode($slotMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($metaDir . '/slot.json', $slotJson, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write release slot metadata.');
            }
            if (file_put_contents($metaDir . '/SEALED', $manifestSha . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write release slot seal.');
            }

            if (is_dir($final)) {
                $this->removeTree($tmp);
                $existing = $this->store->inspectSlot($releaseId, true);
                if (($existing['integrity_status'] ?? '') !== 'valid') {
                    throw new \RuntimeException('Existing release slot is invalid and will not be overwritten: ' . $releaseId);
                }
                if ((string)($existing['version'] ?? '') !== $version
                    || (string)($existing['source_head_short'] ?? '') !== $sourceShort
                    || ($cacheKey !== '' && (string)($existing['cache_key'] ?? '') !== $cacheKey)) {
                    throw new \RuntimeException('Existing release slot identity does not match requested release: ' . $releaseId);
                }
                $existing['materialization'] = 'existing_valid';
                $existing['filesystem_capabilities'] = $probe;
                return $existing;
            }

            $this->sealDirectories($tmp);
            if (!@rename($tmp, $final)) {
                $this->unsealDirectories($tmp);
                throw new \RuntimeException('Unable to publish release slot atomically: ' . $releaseId);
            }
            $verified = $this->store->inspectSlot($releaseId, true);
            if (($verified['integrity_status'] ?? '') !== 'valid') {
                $this->removeTree($final);
                throw new \RuntimeException('Published release slot failed full integrity verification: ' . $releaseId);
            }
            $verified['materialization'] = 'created';
            $verified['filesystem_capabilities'] = $probe;
            return $verified;
        } catch (\Throwable $e) {
            $this->removeTree($tmp);
            throw $e;
        }
    }

    private function detectBuildIdentity(): array
    {
        $html = rtrim($this->root, '/\\') . '/ui-v2.html';
        if (!is_file($html)) return ['cache_key'=>'', 'source_head_short'=>''];
        $raw = (string)@file_get_contents($html);
        if (preg_match('/p2k-[0-9.]+-([0-9a-f]{12})-[0-9a-f]{16}/i', $raw, $m)) {
            if (preg_match('/p2k-[0-9.]+-[0-9a-f]{12}-[0-9a-f]{16}/i', $raw, $full)) {
                return ['cache_key'=>$full[0], 'source_head_short'=>strtolower($m[1])];
            }
        }
        return ['cache_key'=>'', 'source_head_short'=>''];
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (is_dir($path)) return;
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to prepare release slot directory: ' . $path);
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
