<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleaseDeploymentManager
{
    private readonly ReleaseSlotStore $slots;
    private readonly ReleaseStateStore $state;
    private readonly ReleaseRuntimeTree $runtimeTrees;

    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
        $this->slots = new ReleaseSlotStore($root, $runtimeOverride);
        $this->state = new ReleaseStateStore($root, $runtimeOverride);
        $this->runtimeTrees = new ReleaseRuntimeTree($root, $runtimeOverride);
    }

    public function currentPublicSlot(bool $fullHashes = true): array
    {
        $state = $this->state->read();
        $mode = (string)($state['mode'] ?? 'direct-root');
        if ($mode === 'slots') {
            $releaseId = trim((string)($state['public_release'] ?? ''));
            if ($releaseId === '') throw new \RuntimeException('Release state does not identify the public slot.');
            $slot = $this->slots->inspectSlot($releaseId, $fullHashes);
            if (($slot['integrity_status'] ?? '') !== 'valid') {
                throw new \RuntimeException('The currently routed public release slot is invalid: ' . $releaseId);
            }
            return $slot;
        }
        if ($mode !== 'direct-root') throw new \RuntimeException('Release-control serving mode is unsupported.');
        return $this->directRootSlot($fullHashes);
    }

    public function promote(string $actor): array
    {
        $state = $this->state->read();
        $candidateId = trim((string)($state['candidate_release'] ?? ''));
        if ($candidateId === '') throw new \RuntimeException('No candidate is registered for promotion.');

        $candidate = $this->slots->inspectSlot($candidateId, true);
        if (($candidate['integrity_status'] ?? '') !== 'valid' || empty($candidate['candidate'])) {
            throw new \RuntimeException('Promotion requires a fully verified candidate slot.');
        }
        $current = $this->currentPublicSlot(true);
        if (hash_equals((string)$current['release_id'], (string)$candidate['release_id'])) {
            throw new \RuntimeException('Candidate is already the public release.');
        }

        // Build and verify both routable trees before the pointer changes.
        $candidateRuntime = $this->runtimeTrees->prepare((string)$candidate['release_id']);
        $currentRuntime = $this->runtimeTrees->prepare((string)$current['release_id']);
        if (empty($candidateRuntime['valid']) || empty($currentRuntime['valid'])) {
            throw new \RuntimeException('Public runtime preparation did not verify.');
        }

        $next = $this->state->promoteCandidate($candidate, $current, $actor);
        return [
            'action'=>'promote',
            'from'=>(string)$current['release_id'],
            'to'=>(string)$candidate['release_id'],
            'state'=>$next,
            'public_runtime'=>$candidateRuntime,
            'rollback_runtime'=>$currentRuntime,
        ];
    }

    public function rollback(string $actor): array
    {
        $state = $this->state->read();
        if (($state['mode'] ?? '') !== 'slots') {
            throw new \RuntimeException('Rollback is available only after a slot promotion.');
        }
        $currentId = trim((string)($state['public_release'] ?? ''));
        $previousId = trim((string)($state['previous_public_release'] ?? ''));
        if ($currentId === '' || $previousId === '') {
            throw new \RuntimeException('No rollback target is recorded.');
        }
        if (trim((string)($state['candidate_release'] ?? '')) !== '') {
            throw new \RuntimeException('Rollback is blocked while another candidate is registered.');
        }

        $current = $this->slots->inspectSlot($currentId, true);
        $previous = $this->slots->inspectSlot($previousId, true);
        if (($current['integrity_status'] ?? '') !== 'valid' || ($previous['integrity_status'] ?? '') !== 'valid') {
            throw new \RuntimeException('Rollback requires both public and previous release slots to verify.');
        }

        $currentRuntime = $this->runtimeTrees->prepare($currentId);
        $previousRuntime = $this->runtimeTrees->prepare($previousId);
        if (empty($currentRuntime['valid']) || empty($previousRuntime['valid'])) {
            throw new \RuntimeException('Rollback runtime preparation did not verify.');
        }

        $next = $this->state->rollbackPublic($current, $previous, $actor);
        return [
            'action'=>'rollback',
            'from'=>$currentId,
            'to'=>$previousId,
            'state'=>$next,
            'public_runtime'=>$previousRuntime,
            'candidate_runtime'=>$currentRuntime,
        ];
    }

    private function directRootSlot(bool $fullHashes): array
    {
        $versionPath = rtrim($this->root, '/\\') . '/VERSION';
        $uiPath = rtrim($this->root, '/\\') . '/ui-v2.html';
        if (!is_file($versionPath) || !is_file($uiPath)) {
            throw new \RuntimeException('Current direct-root build identity files are unavailable.');
        }
        $version = trim((string)file_get_contents($versionPath));
        $ui = (string)file_get_contents($uiPath);
        if (!preg_match('/p2k-([0-9.]+)-([0-9a-f]{12})-([0-9a-f]{16})/i', $ui, $m)) {
            throw new \RuntimeException('Current direct-root build cache identity is unavailable.');
        }
        if (!hash_equals($version, $m[1])) {
            throw new \RuntimeException('Current direct-root VERSION and build cache identity differ.');
        }
        $releaseId = $version . '-' . strtolower($m[2]);
        $slot = $this->slots->inspectSlot($releaseId, $fullHashes);
        if (($slot['integrity_status'] ?? '') !== 'valid') {
            throw new \RuntimeException('The exact direct-root public release slot is missing or invalid: ' . $releaseId);
        }
        $full = strtolower(trim((string)($slot['source_head'] ?? '')));
        if (!preg_match('/^[0-9a-f]{40}$/D', $full) || !str_starts_with($full, strtolower($m[2]))) {
            throw new \RuntimeException('Direct-root public slot source identity does not match the live build.');
        }
        return $slot;
    }
}
