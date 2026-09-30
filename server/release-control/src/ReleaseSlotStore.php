<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleaseSlotStore
{
    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
    }

    public function runtimeDir(): string
    {
        if ($this->runtimeOverride !== null && trim($this->runtimeOverride) !== '') {
            return rtrim($this->runtimeOverride, '/\\');
        }
        $runtime = '';
        $configPath = rtrim($this->root, '/\\') . '/server/team-points/config/config.local.php';
        if (is_file($configPath)) {
            try {
                $config = require $configPath;
                if (is_array($config)) {
                    $storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
                    $runtime = rtrim((string)($storage['runtime_dir'] ?? ''), '/\\');
                }
            } catch (\Throwable) {
                $runtime = '';
            }
        }
        return $runtime !== '' ? $runtime : rtrim($this->root, '/\\') . '/data/runtime-v280';
    }

    public function controlDir(): string
    {
        return $this->runtimeDir() . '/release-control';
    }

    public function slotsDir(): string
    {
        return $this->controlDir() . '/releases';
    }

    public function filesystemCapabilities(): ?array
    {
        $path = $this->controlDir() . '/filesystem-capabilities.json';
        if (!is_file($path)) return null;
        try {
            $decoded = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function listSlots(): array
    {
        $dir = $this->slotsDir();
        if (!is_dir($dir)) return [];
        $slots = [];
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            if (str_starts_with($entry, '.')) continue;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D', $entry)) continue;
            if (!is_dir($dir . '/' . $entry)) continue;
            $slots[] = $this->inspectSlot($entry, false);
        }
        usort($slots, static function (array $a, array $b): int {
            return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
        });
        return $slots;
    }

    public function inspectSlot(string $releaseId, bool $fullHashes = false): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D', $releaseId)) {
            return $this->invalidSlot($releaseId, ['invalid release id']);
        }
        $base = $this->slotsDir() . '/' . $releaseId;
        $metaDir = $base . '/meta';
        $appDir = $base . '/app';
        $slotPath = $metaDir . '/slot.json';
        $manifestPath = $metaDir . '/manifest.tsv';
        $sealedPath = $metaDir . '/SEALED';
        $errors = [];
        $meta = [];

        if (!is_dir($base)) return $this->invalidSlot($releaseId, ['slot directory missing']);
        if (!is_dir($appDir)) $errors[] = 'application tree missing';
        if (!is_file($slotPath)) $errors[] = 'slot metadata missing';
        if (!is_file($manifestPath)) $errors[] = 'manifest missing';
        if (!is_file($sealedPath)) $errors[] = 'seal marker missing';

        if (is_file($slotPath)) {
            try {
                $decoded = json_decode((string)file_get_contents($slotPath), true, 128, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) $meta = $decoded;
                else $errors[] = 'slot metadata has invalid shape';
            } catch (\Throwable) {
                $errors[] = 'slot metadata is invalid JSON';
            }
        }

        $manifestDigest = is_file($manifestPath) ? hash_file('sha256', $manifestPath) : '';
        $expectedDigest = strtolower(trim((string)($meta['manifest_sha256'] ?? '')));
        if ($expectedDigest !== '' && $manifestDigest !== '' && !hash_equals($expectedDigest, $manifestDigest)) {
            $errors[] = 'manifest digest mismatch';
        }

        $fileCount = 0;
        $logicalBytes = 0;
        $hashMismatches = 0;
        $sharedViolations = 0;
        $missing = 0;
        $manifestReadable = is_file($manifestPath) ? @fopen($manifestPath, 'rb') : false;
        if ($manifestReadable !== false) {
            while (($line = fgets($manifestReadable)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') continue;
                $parts = explode("\t", $line, 4);
                if (count($parts) !== 4) { $errors[] = 'manifest row has invalid shape'; continue; }
                [$sha, $bytesRaw, $method, $path] = $parts;
                $path = ReleaseSlotPolicy::normalizeRelativePath($path);
                if ($path === '' || !ReleaseSlotPolicy::isReleaseOwnedPath($path)) {
                    $sharedViolations++;
                    continue;
                }
                $fileCount++;
                $logicalBytes += max(0, (int)$bytesRaw);
                $file = $appDir . '/' . $path;
                if (!is_file($file) || is_link($file)) {
                    $missing++;
                    continue;
                }
                if ($fullHashes) {
                    $got = hash_file('sha256', $file);
                    if (!is_string($got) || !hash_equals(strtolower($sha), strtolower($got))) $hashMismatches++;
                }
                if (!in_array($method, ['hardlink', 'copy'], true)) $errors[] = 'manifest contains unknown materialization method';
            }
            fclose($manifestReadable);
        } elseif (is_file($manifestPath)) {
            $errors[] = 'manifest unreadable';
        }

        if ($missing > 0) $errors[] = $missing . ' manifest files are missing';
        if ($sharedViolations > 0) $errors[] = $sharedViolations . ' shared/recovery paths leaked into the slot';
        if ($fullHashes && $hashMismatches > 0) $errors[] = $hashMismatches . ' file hash mismatches';
        $expectedCount = (int)($meta['file_count'] ?? -1);
        if ($expectedCount >= 0 && $expectedCount !== $fileCount) $errors[] = 'manifest file count differs from metadata';
        $expectedBytes = (int)($meta['logical_bytes'] ?? -1);
        if ($expectedBytes >= 0 && $expectedBytes !== $logicalBytes) $errors[] = 'manifest byte count differs from metadata';
        if (($meta['release_id'] ?? $releaseId) !== $releaseId) $errors[] = 'release id differs from directory name';
        if ((int)($meta['schema_version'] ?? 0) !== 1) $errors[] = 'unsupported slot metadata schema';
        if (empty($meta['sealed'])) $errors[] = 'slot metadata is not sealed';

        return [
            'release_id' => $releaseId,
            'version' => (string)($meta['version'] ?? ''),
            'source_head' => (string)($meta['source_head'] ?? ''),
            'source_head_short' => (string)($meta['source_head_short'] ?? ''),
            'cache_key' => (string)($meta['cache_key'] ?? ''),
            'created_at' => (string)($meta['created_at'] ?? ''),
            'strategy' => (string)($meta['strategy'] ?? ''),
            'file_count' => $fileCount,
            'logical_bytes' => $logicalBytes,
            'additional_bytes_at_creation' => (int)($meta['additional_bytes_at_creation'] ?? 0),
            'hardlinked_files' => (int)($meta['hardlinked_files'] ?? 0),
            'copied_files' => (int)($meta['copied_files'] ?? 0),
            'manifest_sha256' => $manifestDigest,
            'sealed' => is_file($sealedPath) && !empty($meta['sealed']),
            'full_hash_verified_at' => (string)($meta['full_hash_verified_at'] ?? ''),
            'full_hash_checked_now' => $fullHashes,
            'routing_enabled' => !empty($meta['routing_enabled']),
            'candidate' => !empty($meta['candidate']),
            'base_release_id' => (string)($meta['base_release_id'] ?? ''),
            'build_id' => (string)($meta['build_id'] ?? ''),
            'qualification_workflow' => (string)($meta['qualification_workflow'] ?? ''),
            'candidate_payload_manifest_sha256' => (string)($meta['candidate_payload_manifest_sha256'] ?? ''),
            'integrity_status' => $errors === [] ? 'valid' : 'invalid',
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function invalidSlot(string $id, array $errors): array
    {
        return [
            'release_id' => $id,
            'version' => '',
            'source_head' => '',
            'source_head_short' => '',
            'cache_key' => '',
            'created_at' => '',
            'strategy' => '',
            'file_count' => 0,
            'logical_bytes' => 0,
            'additional_bytes_at_creation' => 0,
            'hardlinked_files' => 0,
            'copied_files' => 0,
            'manifest_sha256' => '',
            'sealed' => false,
            'full_hash_verified_at' => '',
            'full_hash_checked_now' => false,
            'routing_enabled' => false,
            'candidate' => false,
            'base_release_id' => '',
            'build_id' => '',
            'qualification_workflow' => '',
            'candidate_payload_manifest_sha256' => '',
            'integrity_status' => 'invalid',
            'errors' => $errors,
        ];
    }
}
