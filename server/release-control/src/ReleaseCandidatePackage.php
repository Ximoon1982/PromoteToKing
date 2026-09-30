<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleaseCandidatePackage
{
    private array $metadata = [];
    private array $overlay = [];
    private array $removed = [];

    public function __construct(private readonly string $directory)
    {
    }

    public function validate(): array
    {
        $base = realpath($this->directory);
        if ($base === false || !is_dir($base)) {
            throw new \InvalidArgumentException('Candidate package directory is unavailable.');
        }

        $metaPath = $base . '/CANDIDATE_RELEASE.json';
        $manifestPath = $base . '/CANDIDATE_PAYLOAD.sha256';
        $payloadDir = $base . '/payload';
        if (!is_file($metaPath) || !is_file($manifestPath) || !is_dir($payloadDir)) {
            throw new \RuntimeException('Candidate package contract files are incomplete.');
        }

        $metadata = json_decode((string)file_get_contents($metaPath), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || (int)($metadata['schema_version'] ?? 0) !== 1) {
            throw new \RuntimeException('Candidate package schema is unsupported.');
        }

        $version = trim((string)($metadata['version'] ?? ''));
        $sourceHead = strtolower(trim((string)($metadata['source_head'] ?? '')));
        $cacheKey = trim((string)($metadata['cache_key'] ?? ''));
        $buildId = trim((string)($metadata['build_id'] ?? ''));
        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?$/D', $version)) {
            throw new \RuntimeException('Candidate package VERSION is invalid.');
        }
        if (!preg_match('/^[0-9a-f]{40}$/D', $sourceHead)) {
            throw new \RuntimeException('Candidate package source HEAD must be an exact 40-character commit SHA.');
        }
        if ($cacheKey === '' || $buildId === '') {
            throw new \RuntimeException('Candidate package build identity is incomplete.');
        }

        $manifestSha = hash_file('sha256', $manifestPath);
        $expectedManifestSha = strtolower(trim((string)($metadata['payload_manifest_sha256'] ?? '')));
        if (!is_string($manifestSha) || !preg_match('/^[0-9a-f]{64}$/D', $expectedManifestSha)
            || !hash_equals($expectedManifestSha, strtolower($manifestSha))) {
            throw new \RuntimeException('Candidate payload manifest identity mismatch.');
        }

        $overlay = $this->parseShaManifest($manifestPath, $payloadDir);
        if ($overlay === []) throw new \RuntimeException('Candidate overlay is empty.');
        if (!isset($overlay['VERSION'])) throw new \RuntimeException('Candidate overlay must contain VERSION.');
        $candidateVersion = trim((string)file_get_contents($payloadDir . '/VERSION'));
        if (!hash_equals($version, $candidateVersion)) {
            throw new \RuntimeException('Candidate payload VERSION differs from candidate metadata.');
        }
        if (!isset($overlay['ui-v2.html'])) {
            throw new \RuntimeException('Candidate overlay must contain stamped ui-v2.html build identity.');
        }
        $ui = (string)file_get_contents($payloadDir . '/ui-v2.html');
        if (!preg_match('/p2k-([0-9.]+)-([0-9a-f]{12})-([0-9a-f]{16})/i', $ui, $build)) {
            throw new \RuntimeException('Candidate payload build cache identity is unavailable.');
        }
        if (!hash_equals($version, $build[1])
            || !hash_equals(substr($sourceHead, 0, 12), strtolower($build[2]))
            || !hash_equals($cacheKey, $build[0])) {
            throw new \RuntimeException('Candidate payload build identity differs from candidate metadata.');
        }

        $removed = [];
        $removedPath = $base . '/CANDIDATE_REMOVED_PATHS.txt';
        if (is_file($removedPath)) {
            $raw = preg_split('/\r?\n/', trim((string)file_get_contents($removedPath))) ?: [];
            foreach ($raw as $entry) {
                $path = ReleaseSlotPolicy::normalizeRelativePath(trim((string)$entry));
                if ($path === '' || !ReleaseSlotPolicy::isReleaseOwnedPath($path)) {
                    throw new \RuntimeException('Candidate removal list contains an invalid/shared path.');
                }
                if (isset($overlay[$path])) {
                    throw new \RuntimeException('Candidate path cannot be both overlaid and removed: ' . $path);
                }
                $removed[$path] = true;
            }
        }
        if (isset($removed['VERSION'])) throw new \RuntimeException('Candidate removal list cannot remove VERSION.');

        $accepted = $metadata['accepted_public_builds'] ?? null;
        if (!is_array($accepted) || $accepted === []) {
            throw new \RuntimeException('Candidate package does not declare an accepted public build.');
        }
        $normalizedAccepted = [];
        foreach ($accepted as $row) {
            if (!is_array($row)) continue;
            $baseVersion = trim((string)($row['version'] ?? ''));
            $baseHead = strtolower(trim((string)($row['source_head'] ?? '')));
            if (!preg_match('/^\d+\.\d+\.\d+$/D', $baseVersion) || !preg_match('/^[0-9a-f]{40}$/D', $baseHead)) {
                throw new \RuntimeException('Candidate accepted-public-build identity is invalid.');
            }
            $normalizedAccepted[] = ['version'=>$baseVersion, 'source_head'=>$baseHead];
        }
        if ($normalizedAccepted === []) throw new \RuntimeException('Candidate accepted-public-build list is empty after validation.');

        $metadata['accepted_public_builds'] = $normalizedAccepted;
        $metadata['payload_manifest_sha256'] = strtolower($manifestSha);
        $metadata['release_id'] = $version . '-' . substr($sourceHead, 0, 12);
        $metadata['overlay_file_count'] = count($overlay);
        $metadata['removed_file_count'] = count($removed);

        $this->metadata = $metadata;
        $this->overlay = $overlay;
        $this->removed = $removed;
        return $metadata;
    }

    public function metadata(): array
    {
        if ($this->metadata === []) $this->validate();
        return $this->metadata;
    }

    public function overlay(): array
    {
        if ($this->metadata === []) $this->validate();
        return $this->overlay;
    }

    public function removed(): array
    {
        if ($this->metadata === []) $this->validate();
        return $this->removed;
    }

    public function payloadPath(string $path): string
    {
        $path = ReleaseSlotPolicy::normalizeRelativePath($path);
        if ($path === '' || !isset($this->overlay()[$path])) {
            throw new \InvalidArgumentException('Candidate payload path is not part of the verified overlay.');
        }
        return rtrim((string)realpath($this->directory), '/\\') . '/payload/' . $path;
    }

    private function parseShaManifest(string $manifestPath, string $payloadDir): array
    {
        $payloadReal = realpath($payloadDir);
        if ($payloadReal === false) throw new \RuntimeException('Candidate payload directory is unavailable.');
        $prefix = rtrim(str_replace('\\', '/', $payloadReal), '/') . '/';
        $out = [];
        foreach (preg_split('/\r?\n/', trim((string)file_get_contents($manifestPath))) ?: [] as $line) {
            if ($line === '') continue;
            if (!preg_match('/^([0-9a-fA-F]{64})\s+\*?\.\/(.+)$/D', $line, $m)) {
                throw new \RuntimeException('Candidate payload manifest row is invalid.');
            }
            $sha = strtolower($m[1]);
            $path = ReleaseSlotPolicy::normalizeRelativePath($m[2]);
            if ($path === '' || !ReleaseSlotPolicy::isReleaseOwnedPath($path)) {
                throw new \RuntimeException('Candidate payload contains shared/recovery path: ' . $m[2]);
            }
            if (isset($out[$path])) throw new \RuntimeException('Candidate payload manifest contains a duplicate path: ' . $path);
            $file = $payloadDir . '/' . $path;
            if (!is_file($file) || is_link($file)) throw new \RuntimeException('Candidate payload file is missing or is a symlink: ' . $path);
            $real = realpath($file);
            if ($real === false || !str_starts_with(str_replace('\\', '/', $real), $prefix)) {
                throw new \RuntimeException('Candidate payload file resolves outside payload: ' . $path);
            }
            $got = hash_file('sha256', $file);
            if (!is_string($got) || !hash_equals($sha, strtolower($got))) {
                throw new \RuntimeException('Candidate payload hash mismatch: ' . $path);
            }
            $out[$path] = $sha;
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
