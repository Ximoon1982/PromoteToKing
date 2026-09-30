<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleaseStateStore
{
    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
    }

    public function path(): string
    {
        return $this->controlDir() . '/state.json';
    }

    public function registerCandidate(array $slot, array $package, string $actor, bool $replace = false): array
    {
        $releaseId = trim((string)($slot['release_id'] ?? ''));
        if ($releaseId === '' || ($slot['integrity_status'] ?? '') !== 'valid') {
            throw new \RuntimeException('Only a verified release slot can be registered as candidate.');
        }
        if (!empty($slot['routing_enabled'])) {
            throw new \RuntimeException('Candidate slot unexpectedly enables routing.');
        }

        $actor = strtolower(trim($actor));
        if ($actor === '') $actor = 'cli';
        if (!preg_match('/^[a-z0-9_.@-]{1,100}$/D', $actor)) {
            throw new \InvalidArgumentException('Candidate registration actor is invalid.');
        }

        $control = $this->controlDir();
        $this->ensureDirectory($control, 0700);
        $lockPath = $control . '/state.lock';
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new \RuntimeException('Unable to lock release-control state.');
        }

        try {
            $state = $this->read();
            $existing = trim((string)($state['candidate_release'] ?? ''));
            if ($existing !== '' && $existing !== $releaseId && !$replace) {
                throw new \RuntimeException(
                    'A different candidate is already registered (' . $existing . '). Use explicit replacement to change it.'
                );
            }

            $version = $this->installedVersion();
            $now = gmdate('c');
            $state = array_merge([
                'schema_version' => 1,
                'mode' => 'direct-root',
                'public_release' => $version !== '' ? $version . ' (direct root)' : null,
                'previous_public_release' => null,
                'candidate_release' => null,
                'updated_at' => null,
                'updated_by' => null,
            ], $state);

            if ((int)($state['schema_version'] ?? 0) !== 1) {
                throw new \RuntimeException('Release-control state schema is unsupported.');
            }
            if ((string)($state['mode'] ?? 'direct-root') !== 'direct-root') {
                throw new \RuntimeException('v2.14.2 candidate registration requires direct-root public serving.');
            }

            $state['candidate_release'] = $releaseId;
            $state['candidate_source_head'] = (string)($slot['source_head'] ?? '');
            $state['candidate_cache_key'] = (string)($slot['cache_key'] ?? '');
            $state['candidate_registered_at'] = $now;
            $state['candidate_registered_by'] = $actor;
            $state['candidate_package_manifest_sha256'] = (string)($package['payload_manifest_sha256'] ?? '');
            $state['candidate_build_id'] = (string)($package['build_id'] ?? '');
            $state['candidate_qualification_workflow'] = (string)($package['qualification_workflow'] ?? '');
            $state['updated_at'] = $now;
            $state['updated_by'] = $actor;

            $this->writeAtomic($state);
            return $state;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new \RuntimeException('Release-control state is unreadable.');
        }
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new \RuntimeException('Release-control state has invalid shape.');
        return $decoded;
    }

    private function writeAtomic(array $state): void
    {
        $path = $this->path();
        $tmp = $path . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to stage release-control state.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to publish release-control state atomically.');
        }
    }

    private function controlDir(): string
    {
        return $this->runtimeDir() . '/release-control';
    }

    private function runtimeDir(): string
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

    private function installedVersion(): string
    {
        $path = rtrim($this->root, '/\\') . '/VERSION';
        return is_file($path) ? trim((string)@file_get_contents($path)) : '';
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to prepare release-control runtime directory.');
        }
        @chmod($path, $mode);
    }
}
