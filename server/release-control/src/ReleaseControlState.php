<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleaseControlState
{
    public const STATE_SCHEMA = 1;

    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
    }

    public function snapshot(): array
    {
        $version = $this->installedVersion();
        $slotStore = new ReleaseSlotStore($this->root, $this->runtimeOverride);
        $runtime = $slotStore->runtimeDir();
        $statePath = $runtime . '/release-control/state.json';
        [$state, $stateStatus] = $this->readState($statePath);
        $identity = $this->buildIdentity($version);
        $slots = $slotStore->listSlots();
        $filesystem = $slotStore->filesystemCapabilities();

        $mode = (string)($state['mode'] ?? 'direct-root');
        if (!in_array($mode, ['direct-root', 'slots'], true)) $mode = 'invalid';
        $invalidSlots = array_values(array_filter($slots, static fn(array $slot): bool => ($slot['integrity_status'] ?? '') !== 'valid'));
        $candidateId = trim((string)($state['candidate_release'] ?? ''));
        $candidateSlot = null;
        if ($candidateId !== '') {
            foreach ($slots as $slot) {
                if (($slot['release_id'] ?? '') === $candidateId) { $candidateSlot = $slot; break; }
            }
        }

        return [
            'schema_version' => self::STATE_SCHEMA,
            'installed_version' => $version,
            'mode' => $mode,
            'public_release' => $state['public_release'] ?? ($version !== '' ? $version . ' (direct root)' : null),
            'previous_public_release' => $state['previous_public_release'] ?? null,
            'candidate_release' => $state['candidate_release'] ?? null,
            'candidate_registered_at' => $state['candidate_registered_at'] ?? null,
            'candidate_registered_by' => $state['candidate_registered_by'] ?? null,
            'candidate_build_id' => $state['candidate_build_id'] ?? null,
            'candidate_qualification_workflow' => $state['candidate_qualification_workflow'] ?? null,
            'candidate_slot' => $candidateSlot,
            'updated_at' => $state['updated_at'] ?? null,
            'updated_by' => $state['updated_by'] ?? null,
            'state_file_present' => is_file($statePath),
            'state_status' => $stateStatus,
            'release_slots_enabled' => $mode === 'slots',
            'slot_storage_ready' => $filesystem !== null && $slots !== [] && $invalidSlots === [],
            'runtime_status' => [
                'exists' => is_dir($runtime),
                'readable' => is_readable($runtime),
                'writable' => is_writable($runtime),
                'display_path' => $this->displayPath($runtime),
            ],
            'slot_storage' => [
                'display_path' => $this->displayPath($slotStore->slotsDir()),
                'exists' => is_dir($slotStore->slotsDir()),
                'slot_count' => count($slots),
                'invalid_slot_count' => count($invalidSlots),
                'filesystem_capabilities' => $filesystem,
                'slots' => $slots,
                'shared_paths_external' => ReleaseSlotPolicy::sharedPathDescriptions(),
            ],
            'build_identity' => $identity,
            'health' => $this->health($version, $mode, $stateStatus, $identity, $runtime, $slots, $filesystem, $candidateId, $candidateSlot),
            'capabilities' => [
                'slot_materialization' => true,
                'candidate_install' => true,
                'candidate_registration' => true,
                'personal_preview' => false,
                'promotion' => false,
                'rollback' => false,
                'state_mutation' => true,
                'web_state_mutation' => false,
                'public_slot_routing' => false,
            ],
        ];
    }

    private function installedVersion(): string
    {
        $path = $this->root . '/VERSION';
        if (!is_file($path)) return '';
        return trim((string)@file_get_contents($path));
    }

    private function readState(string $path): array
    {
        if (!is_file($path)) return [[], 'not_initialized'];
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') return [[], 'unreadable'];
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [[], 'invalid_json'];
        }
        if (!is_array($decoded)) return [[], 'invalid_shape'];
        if ((int)($decoded['schema_version'] ?? 0) !== self::STATE_SCHEMA) return [$decoded, 'unsupported_schema'];
        return [$decoded, 'valid'];
    }

    private function buildIdentity(string $version): array
    {
        $htmlPath = $this->root . '/ui-v2.html';
        if (is_file($htmlPath)) {
            $raw = (string)@file_get_contents($htmlPath);
            if (preg_match('/p2k-([0-9.]+)-([0-9a-f]{12})-([0-9a-f]{16})/i', $raw, $m)) {
                return [
                    'available' => true,
                    'cache_key' => $m[0],
                    'version' => $m[1],
                    'source_head_short' => strtolower($m[2]),
                    'build_token' => strtolower($m[3]),
                    'matches_installed_version' => $version !== '' && hash_equals($version, $m[1]),
                ];
            }
        }
        return [
            'available' => false,
            'cache_key' => null,
            'version' => null,
            'source_head_short' => null,
            'build_token' => null,
            'matches_installed_version' => false,
        ];
    }

    private function health(string $version, string $mode, string $stateStatus, array $identity, string $runtime, array $slots, ?array $filesystem, string $candidateId, ?array $candidateSlot): array
    {
        $checks = [];
        $checks[] = ['status'=>$version !== '' ? 'ok' : 'error','label'=>'Installed VERSION','detail'=>$version !== '' ? $version : 'VERSION file is missing or unreadable.'];
        $checks[] = ['status'=>is_dir($runtime) && is_readable($runtime) ? 'ok' : 'warning','label'=>'Protected runtime storage','detail'=>is_dir($runtime) ? (is_readable($runtime) ? 'Runtime directory is readable.' : 'Runtime directory is not readable.') : 'Runtime directory does not exist yet.'];
        $stateOk = in_array($stateStatus, ['valid', 'not_initialized'], true);
        $checks[] = [
            'status'=>$stateOk ? 'ok' : 'warning',
            'label'=>'Release state',
            'detail'=>$stateStatus === 'not_initialized' ? 'Not initialized: expected while public serving remains direct-root.' : 'State status: ' . $stateStatus . '.',
        ];
        $checks[] = [
            'status'=>$mode === 'direct-root' ? 'ok' : ($mode === 'slots' ? 'info' : 'warning'),
            'label'=>'Routing mode',
            'detail'=>$mode === 'direct-root'
                ? 'Direct-root serving remains active; v2.14.2 does not route public traffic through release slots.'
                : ($mode === 'slots' ? 'Release-slot routing is declared by state.' : 'Release state contains an unsupported routing mode.'),
        ];
        $checks[] = [
            'status'=>!empty($identity['available']) && !empty($identity['matches_installed_version']) ? 'ok' : 'info',
            'label'=>'Build identity',
            'detail'=>!empty($identity['available'])
                ? (!empty($identity['matches_installed_version']) ? 'Build cache identity matches VERSION.' : 'A build cache identity exists but does not match VERSION; source checkout or unstamped overlay is likely.')
                : 'No stamped build identity was found; this is expected in an unstamped source checkout.',
        ];
        if ($filesystem === null) {
            $checks[] = ['status'=>'warning','label'=>'Release-slot filesystem probe','detail'=>'No filesystem capability record exists yet. Installing v2.14.2 should preserve/create it before changing production files.'];
        } else {
            $strategy = (string)($filesystem['selected_strategy'] ?? 'unknown');
            $checks[] = [
                'status'=>!empty($filesystem['atomic_rename_supported']) ? 'ok' : 'error',
                'label'=>'Release-slot filesystem',
                'detail'=>'Selected strategy: ' . $strategy
                    . '; hard links: ' . (!empty($filesystem['hardlink_supported']) ? 'supported' : 'not available')
                    . '; symlinks: ' . (!empty($filesystem['symlink_supported']) ? 'supported (not used for snapshots)' : 'not available')
                    . '; atomic rename: ' . (!empty($filesystem['atomic_rename_supported']) ? 'supported' : 'not available') . '.',
            ];
        }
        $invalid = array_values(array_filter($slots, static fn(array $slot): bool => ($slot['integrity_status'] ?? '') !== 'valid'));
        $checks[] = [
            'status'=>$slots === [] ? 'warning' : ($invalid === [] ? 'ok' : 'error'),
            'label'=>'Installed release slots',
            'detail'=>$slots === [] ? 'No release slot has been materialized yet.' : (count($slots) . ' slot(s) discovered; ' . count($invalid) . ' invalid.'),
        ];
        if ($candidateId !== '') {
            $candidateValid = is_array($candidateSlot)
                && ($candidateSlot['integrity_status'] ?? '') === 'valid'
                && !empty($candidateSlot['candidate'])
                && empty($candidateSlot['routing_enabled']);
            $checks[] = [
                'status'=>$candidateValid ? 'ok' : 'error',
                'label'=>'Registered candidate',
                'detail'=>$candidateValid
                    ? $candidateId . ' is sealed, valid and not routed.'
                    : $candidateId . ' is registered but its candidate slot is missing, invalid or unexpectedly routable.',
            ];
        } else {
            $checks[] = ['status'=>'info','label'=>'Registered candidate','detail'=>'No candidate is registered.'];
        }
        return $checks;
    }

    private function displayPath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->root), '/');
        $normalized = str_replace('\\', '/', $path);
        if ($root !== '' && str_starts_with($normalized, $root . '/')) {
            return './' . substr($normalized, strlen($root) + 1);
        }
        return '[protected runtime]';
    }
}
