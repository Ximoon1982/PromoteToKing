<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleaseCandidateInstaller
{
    private readonly ReleaseSlotStore $store;

    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
        $this->store = new ReleaseSlotStore($root, $runtimeOverride);
    }

    public function install(string $packageDir, string $actor = 'cli', bool $replaceCandidate = false): array
    {
        $package = new ReleaseCandidatePackage($packageDir);
        $meta = $package->validate();

        $current = $this->currentPublicIdentity();
        if (version_compare((string)$meta['version'], (string)$current['version'], '<=')) {
            throw new \RuntimeException('Candidate VERSION must be newer than the current public VERSION.');
        }
        $accepted = false;
        foreach ((array)$meta['accepted_public_builds'] as $row) {
            if (($row['version'] ?? '') === $current['version']
                && ($row['source_head'] ?? '') === $current['source_head']) {
                $accepted = true;
                break;
            }
        }
        if (!$accepted) {
            throw new \RuntimeException(
                'Candidate package does not accept current public build '
                . $current['version'] . ' ' . $current['source_head'] . '.'
            );
        }

        $baseReleaseId = $current['version'] . '-' . substr($current['source_head'], 0, 12);
        $base = $this->store->inspectSlot($baseReleaseId, true);
        if (($base['integrity_status'] ?? '') !== 'valid') {
            throw new \RuntimeException('The exact current public release slot is missing or invalid: ' . $baseReleaseId);
        }
        if ((string)($base['source_head'] ?? '') !== $current['source_head']) {
            throw new \RuntimeException('Current public slot source identity does not match the live build.');
        }

        $releaseId = (string)$meta['release_id'];
        $existing = $this->store->inspectSlot($releaseId, true);
        if (($existing['integrity_status'] ?? '') === 'valid') {
            if ((string)($existing['version'] ?? '') !== (string)$meta['version']
                || (string)($existing['source_head'] ?? '') !== (string)$meta['source_head']
                || (string)($existing['cache_key'] ?? '') !== (string)$meta['cache_key']) {
                throw new \RuntimeException('Existing candidate slot identity differs from requested package.');
            }
            $existing['materialization'] = 'existing_valid';
            $state = (new ReleaseStateStore($this->root, $this->runtimeOverride))
                ->registerCandidate($existing, $meta, $actor, $replaceCandidate);
            return ['candidate'=>$existing, 'state'=>$state, 'package'=>$meta];
        }

        $runtime = $this->store->runtimeDir();
        $probe = (new ReleaseSlotFilesystemProbe($this->root, $runtime))->probe(true);
        if (empty($probe['atomic_rename_supported'])) {
            throw new \RuntimeException('Candidate installation requires atomic rename support.');
        }
        $preferred = (string)($probe['selected_strategy'] ?? 'copy');
        if ((string)(getenv('P2K_RELEASE_SLOT_FORCE_COPY') ?: '') === '1') $preferred = 'copy';
        if (!in_array($preferred, ['hardlink', 'copy'], true)) $preferred = 'copy';

        $baseManifest = $this->readSlotManifest($baseReleaseId);
        $overlay = $package->overlay();
        $removed = $package->removed();
        $targetPaths = [];
        foreach (array_keys($baseManifest) as $path) {
            if (ReleaseSlotPolicy::isReleaseOwnedPath($path) && !isset($removed[$path])) $targetPaths[$path] = true;
        }
        foreach (array_keys($overlay) as $path) $targetPaths[$path] = true;
        foreach (array_keys($removed) as $path) unset($targetPaths[$path]);
        if (!isset($targetPaths['VERSION'])) throw new \RuntimeException('Candidate target does not contain VERSION.');

        $slots = $this->store->slotsDir();
        $this->ensureDirectory($slots, 0700);
        $tmp = $slots . '/.candidate-' . getmypid() . '-' . bin2hex(random_bytes(5));
        $app = $tmp . '/app';
        $metaDir = $tmp . '/meta';
        $this->ensureDirectory($app, 0755);
        $this->ensureDirectory($metaDir, 0700);

        $rows = [];
        $hardlinked = 0;
        $copied = 0;
        $logicalBytes = 0;
        $additionalBytes = 0;

        try {
            $paths = array_keys($targetPaths);
            sort($paths, SORT_STRING);
            foreach ($paths as $path) {
                if (!ReleaseSlotPolicy::isReleaseOwnedPath($path)) {
                    throw new \RuntimeException('Candidate target unexpectedly contains shared/recovery path: ' . $path);
                }

                $dest = $app . '/' . $path;
                $this->ensureDirectory(dirname($dest), 0755);
                $method = 'copy';

                if (isset($overlay[$path])) {
                    $source = $package->payloadPath($path);
                    if (!@copy($source, $dest)) throw new \RuntimeException('Unable to copy candidate overlay file: ' . $path);
                    $copied++;
                } else {
                    $source = $slots . '/' . $baseReleaseId . '/app/' . $path;
                    if (!is_file($source) || is_link($source)) {
                        throw new \RuntimeException('Base slot file is missing or unsafe: ' . $path);
                    }
                    if ($preferred === 'hardlink' && function_exists('link') && @link($source, $dest)) {
                        $method = 'hardlink';
                        $hardlinked++;
                    } else {
                        if (!@copy($source, $dest)) throw new \RuntimeException('Unable to copy unchanged candidate file: ' . $path);
                        $copied++;
                    }
                }

                $sha = hash_file('sha256', $dest);
                $bytes = filesize($dest);
                if (!is_string($sha) || $bytes === false) throw new \RuntimeException('Unable to verify candidate file: ' . $path);
                if (isset($overlay[$path]) && !hash_equals((string)$overlay[$path], strtolower($sha))) {
                    throw new \RuntimeException('Candidate overlay changed while materializing: ' . $path);
                }
                $logicalBytes += $bytes;
                if ($method === 'copy') $additionalBytes += $bytes;
                $rows[] = strtolower($sha) . "\t" . $bytes . "\t" . $method . "\t" . $path;
            }

            sort($rows, SORT_STRING);
            $manifest = implode("\n", $rows) . "\n";
            $manifestSha = hash('sha256', $manifest);
            if (file_put_contents($metaDir . '/manifest.tsv', $manifest, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write candidate slot manifest.');
            }

            $createdAt = gmdate('c');
            $strategy = $hardlinked > 0 && $copied > 0 ? 'mixed' : ($hardlinked > 0 ? 'hardlink' : 'copy');
            $slotMeta = [
                'schema_version'=>1,
                'policy_version'=>ReleaseSlotPolicy::POLICY_VERSION,
                'release_id'=>$releaseId,
                'version'=>(string)$meta['version'],
                'source_head'=>(string)$meta['source_head'],
                'source_head_short'=>substr((string)$meta['source_head'], 0, 12),
                'cache_key'=>(string)$meta['cache_key'],
                'created_at'=>$createdAt,
                'strategy'=>$strategy,
                'preferred_strategy'=>$preferred,
                'file_count'=>count($rows),
                'logical_bytes'=>$logicalBytes,
                'additional_bytes_at_creation'=>$additionalBytes,
                'hardlinked_files'=>$hardlinked,
                'copied_files'=>$copied,
                'manifest_sha256'=>$manifestSha,
                'sealed'=>true,
                'full_hash_verified_at'=>$createdAt,
                'routing_enabled'=>false,
                'candidate'=>true,
                'base_release_id'=>$baseReleaseId,
                'build_id'=>(string)$meta['build_id'],
                'qualification_workflow'=>(string)($meta['qualification_workflow'] ?? ''),
                'candidate_payload_manifest_sha256'=>(string)$meta['payload_manifest_sha256'],
                'shared_paths_external'=>ReleaseSlotPolicy::sharedPathDescriptions(),
                'filesystem_probe'=>[
                    'hardlink_supported'=>(bool)($probe['hardlink_supported'] ?? false),
                    'hardlink_snapshot_isolation'=>(bool)($probe['hardlink_snapshot_isolation'] ?? false),
                    'atomic_rename_supported'=>(bool)($probe['atomic_rename_supported'] ?? false),
                ],
            ];
            if (file_put_contents($metaDir . '/slot.json', json_encode($slotMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write candidate slot metadata.');
            }
            if (file_put_contents($metaDir . '/SEALED', $manifestSha . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write candidate slot seal.');
            }

            $final = $slots . '/' . $releaseId;
            if (is_dir($final)) {
                $this->removeTree($tmp);
                throw new \RuntimeException('Candidate slot appeared concurrently and was not overwritten.');
            }
            $this->sealDirectories($tmp);
            if (!@rename($tmp, $final)) {
                $this->unsealDirectories($tmp);
                throw new \RuntimeException('Unable to publish candidate slot atomically.');
            }

            $verified = $this->store->inspectSlot($releaseId, true);
            if (($verified['integrity_status'] ?? '') !== 'valid') {
                $this->removeTree($final);
                throw new \RuntimeException('Published candidate slot failed full integrity verification.');
            }
            $verified['materialization'] = 'created';
            $state = (new ReleaseStateStore($this->root, $this->runtimeOverride))
                ->registerCandidate($verified, $meta, $actor, $replaceCandidate);
            return ['candidate'=>$verified, 'state'=>$state, 'package'=>$meta];
        } catch (\Throwable $e) {
            $this->removeTree($tmp);
            throw $e;
        }
    }

    private function currentPublicIdentity(): array
    {
        $versionPath = rtrim($this->root, '/\\') . '/VERSION';
        $uiPath = rtrim($this->root, '/\\') . '/ui-v2.html';
        if (!is_file($versionPath) || !is_file($uiPath)) {
            throw new \RuntimeException('Current public build identity files are unavailable.');
        }
        $version = trim((string)file_get_contents($versionPath));
        $ui = (string)file_get_contents($uiPath);
        if (!preg_match('/p2k-([0-9.]+)-([0-9a-f]{12})-([0-9a-f]{16})/i', $ui, $m)) {
            throw new \RuntimeException('Current public build cache identity is unavailable.');
        }
        if (!hash_equals($version, $m[1])) {
            throw new \RuntimeException('Current public VERSION and build cache identity differ.');
        }
        $short = strtolower($m[2]);
        $slot = $this->store->inspectSlot($version . '-' . $short, true);
        $full = strtolower(trim((string)($slot['source_head'] ?? '')));
        if (($slot['integrity_status'] ?? '') !== 'valid' || !preg_match('/^[0-9a-f]{40}$/D', $full) || !str_starts_with($full, $short)) {
            throw new \RuntimeException('Current public build does not have an exact verified release slot.');
        }
        return ['version'=>$version, 'source_head'=>$full, 'source_head_short'=>$short, 'cache_key'=>$m[0]];
    }

    private function readSlotManifest(string $releaseId): array
    {
        $path = $this->store->slotsDir() . '/' . $releaseId . '/meta/manifest.tsv';
        $fh = @fopen($path, 'rb');
        if ($fh === false) throw new \RuntimeException('Unable to read base slot manifest.');
        $out = [];
        while (($line = fgets($fh)) !== false) {
            $parts = explode("\t", rtrim($line, "\r\n"), 4);
            if (count($parts) !== 4) { fclose($fh); throw new \RuntimeException('Base slot manifest row is invalid.'); }
            [$sha, $bytes, $method, $pathName] = $parts;
            $pathName = ReleaseSlotPolicy::normalizeRelativePath($pathName);
            if ($pathName === '' || !ReleaseSlotPolicy::isReleaseOwnedPath($pathName)) {
                fclose($fh); throw new \RuntimeException('Base slot manifest contains invalid path.');
            }
            $out[$pathName] = ['sha'=>$sha, 'bytes'=>(int)$bytes, 'method'=>$method];
        }
        fclose($fh);
        return $out;
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to prepare candidate slot directory.');
        }
        @chmod($path, $mode);
    }

    private function sealDirectories(string $root): void
    {
        $dirs = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) if ($item->isDir() && !$item->isLink()) $dirs[] = $item->getPathname();
        foreach ($dirs as $dir) @chmod($dir, 0555);
        @chmod($root, 0555);
    }

    private function unsealDirectories(string $root): void
    {
        if (!is_dir($root)) return;
        @chmod($root, 0700);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) if ($item->isDir() && !$item->isLink()) @chmod($item->getPathname(), 0700);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        $this->unsealDirectories($path);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
