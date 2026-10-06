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
        $actor = $this->normalizeActor($actor);

        return $this->withLock(function () use ($slot, $package, $actor, $replace, $releaseId): array {
            $state = $this->normalizedState($this->read());
            $existing = trim((string)($state['candidate_release'] ?? ''));
            if ($existing !== '' && $existing !== $releaseId && !$replace) {
                throw new \RuntimeException(
                    'A different candidate is already registered (' . $existing . '). Use explicit replacement to change it.'
                );
            }

            $mode = (string)($state['mode'] ?? 'direct-root');
            if (!in_array($mode, ['direct-root', 'slots'], true)) {
                throw new \RuntimeException('Release-control serving mode is unsupported.');
            }

            $now = gmdate('c');
            $state['candidate_release'] = $releaseId;
            $state['candidate_source_head'] = (string)($slot['source_head'] ?? '');
            $state['candidate_cache_key'] = (string)($slot['cache_key'] ?? '');
            $state['candidate_manifest_sha256'] = (string)($slot['manifest_sha256'] ?? '');
            $state['candidate_registered_at'] = $now;
            $state['candidate_registered_by'] = $actor;
            $state['candidate_package_manifest_sha256'] = (string)($package['payload_manifest_sha256'] ?? '');
            $state['candidate_build_id'] = (string)($package['build_id'] ?? '');
            $state['candidate_qualification_workflow'] = (string)($package['qualification_workflow'] ?? '');
            $state['updated_at'] = $now;
            $state['updated_by'] = $actor;

            $this->writeAtomic($state);
            return $state;
        });
    }

    public function promoteCandidate(array $candidate, array $currentPublic, string $actor): array
    {
        $this->assertVerifiedSlot($candidate, 'candidate');
        $this->assertVerifiedSlot($currentPublic, 'current public');
        $actor = $this->normalizeActor($actor);
        $candidateId = (string)$candidate['release_id'];
        $currentId = (string)$currentPublic['release_id'];

        return $this->withLock(function () use ($candidate, $currentPublic, $candidateId, $currentId, $actor): array {
            $state = $this->normalizedState($this->read());
            if (!hash_equals($candidateId, trim((string)($state['candidate_release'] ?? '')))) {
                throw new \RuntimeException('Candidate changed while promotion was being prepared.');
            }
            $mode = (string)($state['mode'] ?? 'direct-root');
            if ($mode === 'slots' && !hash_equals($currentId, trim((string)($state['public_release'] ?? '')))) {
                throw new \RuntimeException('Public release changed while promotion was being prepared.');
            }
            if (!in_array($mode, ['direct-root', 'slots'], true)) {
                throw new \RuntimeException('Release-control serving mode is unsupported.');
            }

            $now = gmdate('c');
            $sequence = max(0, (int)($state['transition_sequence'] ?? 0)) + 1;
            $state['mode'] = 'slots';
            $state['previous_public_release'] = $currentId;
            $state['previous_public_version'] = (string)($currentPublic['version'] ?? '');
            $state['previous_public_source_head'] = (string)($currentPublic['source_head'] ?? '');
            $state['previous_public_cache_key'] = (string)($currentPublic['cache_key'] ?? '');
            $state['previous_public_manifest_sha256'] = (string)($currentPublic['manifest_sha256'] ?? '');
            $state['public_release'] = $candidateId;
            $state['public_version'] = (string)($candidate['version'] ?? '');
            $state['public_source_head'] = (string)($candidate['source_head'] ?? '');
            $state['public_cache_key'] = (string)($candidate['cache_key'] ?? '');
            $state['public_manifest_sha256'] = (string)($candidate['manifest_sha256'] ?? '');
            $this->clearCandidate($state);
            $state['last_promotion_at'] = $now;
            $state['last_promotion_by'] = $actor;
            $state['transition_sequence'] = $sequence;
            $state['last_transition'] = [
                'sequence'=>$sequence,
                'action'=>'promote',
                'from'=>$currentId,
                'to'=>$candidateId,
                'at'=>$now,
                'by'=>$actor,
            ];
            $state['updated_at'] = $now;
            $state['updated_by'] = $actor;
            $this->writeAtomic($state);
            return $state;
        });
    }

    public function rollbackPublic(array $currentPublic, array $previousPublic, string $actor): array
    {
        $this->assertVerifiedSlot($currentPublic, 'current public');
        $this->assertVerifiedSlot($previousPublic, 'rollback');
        $actor = $this->normalizeActor($actor);
        $currentId = (string)$currentPublic['release_id'];
        $previousId = (string)$previousPublic['release_id'];

        return $this->withLock(function () use ($currentPublic, $previousPublic, $currentId, $previousId, $actor): array {
            $state = $this->normalizedState($this->read());
            if (($state['mode'] ?? '') !== 'slots') {
                throw new \RuntimeException('Rollback requires public release-slot routing.');
            }
            if (!hash_equals($currentId, trim((string)($state['public_release'] ?? '')))
                || !hash_equals($previousId, trim((string)($state['previous_public_release'] ?? '')))) {
                throw new \RuntimeException('Public or rollback release changed while rollback was being prepared.');
            }
            if (trim((string)($state['candidate_release'] ?? '')) !== '') {
                throw new \RuntimeException('Rollback requires no separately registered candidate.');
            }

            $now = gmdate('c');
            $sequence = max(0, (int)($state['transition_sequence'] ?? 0)) + 1;
            $state['public_release'] = $previousId;
            $state['public_version'] = (string)($previousPublic['version'] ?? '');
            $state['public_source_head'] = (string)($previousPublic['source_head'] ?? '');
            $state['public_cache_key'] = (string)($previousPublic['cache_key'] ?? '');
            $state['public_manifest_sha256'] = (string)($previousPublic['manifest_sha256'] ?? '');
            $state['previous_public_release'] = null;
            $state['previous_public_version'] = null;
            $state['previous_public_source_head'] = null;
            $state['previous_public_cache_key'] = null;
            $state['previous_public_manifest_sha256'] = null;

            // The release we just rolled back from becomes the verified candidate,
            // enabling the v2.14.6 rollback -> verify -> re-promote lifecycle.
            $state['candidate_release'] = $currentId;
            $state['candidate_source_head'] = (string)($currentPublic['source_head'] ?? '');
            $state['candidate_cache_key'] = (string)($currentPublic['cache_key'] ?? '');
            $state['candidate_manifest_sha256'] = (string)($currentPublic['manifest_sha256'] ?? '');
            $state['candidate_registered_at'] = $now;
            $state['candidate_registered_by'] = $actor;
            $state['candidate_package_manifest_sha256'] = (string)($currentPublic['candidate_payload_manifest_sha256'] ?? '');
            $state['candidate_build_id'] = (string)($currentPublic['build_id'] ?? '');
            $state['candidate_qualification_workflow'] = (string)($currentPublic['qualification_workflow'] ?? '');

            $state['last_rollback_at'] = $now;
            $state['last_rollback_by'] = $actor;
            $state['transition_sequence'] = $sequence;
            $state['last_transition'] = [
                'sequence'=>$sequence,
                'action'=>'rollback',
                'from'=>$currentId,
                'to'=>$previousId,
                'at'=>$now,
                'by'=>$actor,
            ];
            $state['updated_at'] = $now;
            $state['updated_by'] = $actor;
            $this->writeAtomic($state);
            return $state;
        });
    }

    /** Execute a release maintenance operation under the same lock as state transitions. */
    public function withExclusiveLock(callable $callback): array
    {
        return $this->withLock($callback);
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

    private function normalizedState(array $state): array
    {
        $version = $this->installedVersion();
        $state = array_merge([
            'schema_version'=>1,
            'mode'=>'direct-root',
            'public_release'=>$version !== '' ? $version . ' (direct root)' : null,
            'previous_public_release'=>null,
            'candidate_release'=>null,
            'transition_sequence'=>0,
            'updated_at'=>null,
            'updated_by'=>null,
        ], $state);
        if ((int)($state['schema_version'] ?? 0) !== 1) {
            throw new \RuntimeException('Release-control state schema is unsupported.');
        }
        return $state;
    }

    private function clearCandidate(array &$state): void
    {
        foreach ([
            'candidate_release','candidate_source_head','candidate_cache_key','candidate_manifest_sha256',
            'candidate_registered_at','candidate_registered_by','candidate_package_manifest_sha256',
            'candidate_build_id','candidate_qualification_workflow'
        ] as $key) {
            $state[$key] = null;
        }
    }

    private function assertVerifiedSlot(array $slot, string $label): void
    {
        $id = trim((string)($slot['release_id'] ?? ''));
        if ($id === '' || ($slot['integrity_status'] ?? '') !== 'valid') {
            throw new \RuntimeException('The ' . $label . ' release slot is not fully verified.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D', $id)) {
            throw new \RuntimeException('The ' . $label . ' release id is invalid.');
        }
    }

    private function normalizeActor(string $actor): string
    {
        $actor = strtolower(trim($actor));
        if ($actor === '') $actor = 'cli';
        if (!preg_match('/^[a-z0-9_.@-]{1,100}$/D', $actor)) {
            throw new \InvalidArgumentException('Release transition actor is invalid.');
        }
        return $actor;
    }

    private function withLock(callable $callback): array
    {
        $control = $this->controlDir();
        $this->ensureDirectory($control, 0700);
        $lockPath = $control . '/state.lock';
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new \RuntimeException('Unable to lock release-control state.');
        }
        try {
            $result = $callback();
            if (!is_array($result)) throw new \RuntimeException('Release state mutation returned invalid data.');
            return $result;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
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
