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
        $rootVersion = $this->installedVersion();
        $slotStore = new ReleaseSlotStore($this->root, $this->runtimeOverride);
        $runtime = $slotStore->runtimeDir();
        $statePath = $runtime . '/release-control/state.json';
        [$state, $stateStatus] = $this->readState($statePath);
        $rootIdentity = $this->buildIdentity($rootVersion);
        $slots = $slotStore->listSlots();
        $filesystem = $slotStore->filesystemCapabilities();
        $byId = [];
        foreach ($slots as $slot) $byId[(string)($slot['release_id'] ?? '')] = $slot;

        $mode = (string)($state['mode'] ?? 'direct-root');
        if (!in_array($mode, ['direct-root', 'slots'], true)) $mode = 'invalid';

        $candidateId = trim((string)($state['candidate_release'] ?? ''));
        $publicId = $mode === 'slots' ? trim((string)($state['public_release'] ?? '')) : '';
        $previousId = $mode === 'slots' ? trim((string)($state['previous_public_release'] ?? '')) : '';
        $candidateSlot = $candidateId !== '' ? ($byId[$candidateId] ?? null) : null;
        $publicSlot = $publicId !== '' ? ($byId[$publicId] ?? null) : null;
        $previousSlot = $previousId !== '' ? ($byId[$previousId] ?? null) : null;

        $publicVersion = $mode === 'slots' && is_array($publicSlot)
            ? (string)($publicSlot['version'] ?? '')
            : $rootVersion;
        $publicIdentity = $mode === 'slots' && is_array($publicSlot)
            ? [
                'available'=>true,
                'cache_key'=>(string)($publicSlot['cache_key'] ?? ''),
                'version'=>(string)($publicSlot['version'] ?? ''),
                'source_head_short'=>(string)($publicSlot['source_head_short'] ?? ''),
                'source_head'=>(string)($publicSlot['source_head'] ?? ''),
                'build_token'=>null,
                'matches_installed_version'=>true,
              ]
            : $rootIdentity;

        $runtimeTree = new ReleaseRuntimeTree($this->root, $this->runtimeOverride);
        $publicRuntime = $publicId !== ''
            ? $runtimeTree->describeExisting($publicId, (string)($state['public_manifest_sha256'] ?? ''))
            : null;
        $previousRuntime = $previousId !== ''
            ? $runtimeTree->describeExisting($previousId, (string)($state['previous_public_manifest_sha256'] ?? ''))
            : null;

        $invalidSlots = array_values(array_filter($slots, static fn(array $slot): bool => ($slot['integrity_status'] ?? '') !== 'valid'));
        $candidateValid = is_array($candidateSlot) && ($candidateSlot['integrity_status'] ?? '') === 'valid';
        $publicValid = $mode !== 'slots' || (is_array($publicSlot) && ($publicSlot['integrity_status'] ?? '') === 'valid' && is_array($publicRuntime));
        $previousValid = $previousId !== '' && is_array($previousSlot) && ($previousSlot['integrity_status'] ?? '') === 'valid' && is_array($previousRuntime);

        return [
            'schema_version'=>self::STATE_SCHEMA,
            'installed_version'=>$rootVersion,
            'root_build_identity'=>$rootIdentity,
            'public_version'=>$publicVersion,
            'mode'=>$mode,
            'public_release'=>$state['public_release'] ?? ($rootVersion !== '' ? $rootVersion . ' (direct root)' : null),
            'public_slot'=>$publicSlot,
            'public_runtime'=>$publicRuntime,
            'previous_public_release'=>$state['previous_public_release'] ?? null,
            'previous_public_slot'=>$previousSlot,
            'previous_public_runtime'=>$previousRuntime,
            'candidate_release'=>$state['candidate_release'] ?? null,
            'candidate_registered_at'=>$state['candidate_registered_at'] ?? null,
            'candidate_registered_by'=>$state['candidate_registered_by'] ?? null,
            'candidate_build_id'=>$state['candidate_build_id'] ?? null,
            'candidate_qualification_workflow'=>$state['candidate_qualification_workflow'] ?? null,
            'candidate_slot'=>$candidateSlot,
            'transition_sequence'=>(int)($state['transition_sequence'] ?? 0),
            'last_transition'=>is_array($state['last_transition'] ?? null) ? $state['last_transition'] : null,
            'updated_at'=>$state['updated_at'] ?? null,
            'updated_by'=>$state['updated_by'] ?? null,
            'state_file_present'=>is_file($statePath),
            'state_status'=>$stateStatus,
            'release_slots_enabled'=>$mode === 'slots',
            'slot_storage_ready'=>$filesystem !== null && $slots !== [] && $invalidSlots === [],
            'runtime_status'=>[
                'exists'=>is_dir($runtime),
                'readable'=>is_readable($runtime),
                'writable'=>is_writable($runtime),
                'display_path'=>$this->displayPath($runtime),
            ],
            'slot_storage'=>[
                'display_path'=>$this->displayPath($slotStore->slotsDir()),
                'exists'=>is_dir($slotStore->slotsDir()),
                'slot_count'=>count($slots),
                'invalid_slot_count'=>count($invalidSlots),
                'filesystem_capabilities'=>$filesystem,
                'slots'=>$slots,
                'shared_paths_external'=>ReleaseSlotPolicy::sharedPathDescriptions(),
            ],
            'build_identity'=>$publicIdentity,
            'health'=>$this->health(
                $rootVersion, $publicVersion, $mode, $stateStatus, $rootIdentity,
                $runtime, $slots, $filesystem, $candidateId, $candidateSlot,
                $publicId, $publicSlot, $publicRuntime, $previousId, $previousSlot, $previousRuntime
            ),
            'capabilities'=>[
                'slot_materialization' => true,
                'candidate_install' => true,
                'candidate_registration' => true,
                'personal_preview' => true,
                'candidate_side_effect_isolation' => true,
                'candidate_database_mode' => 'read-only',
                'candidate_runtime_sandbox' => true,
                'candidate_session_sandbox' => true,
                'promotion'=>$candidateValid && $publicValid,
                'rollback'=>$mode === 'slots' && $candidateId === '' && $publicValid && $previousValid,
                'state_mutation'=>true,
                'web_state_mutation'=>true,
                'web_state_mutation_scope'=>'preview-session,promotion,rollback',
                'public_slot_routing'=>true,
                'public_slot_routing_active'=>$mode === 'slots',
                'candidate_cron' => false,
                'public_cron_follows_release'=>true,
            ],
        ];
    }

    private function installedVersion(): string
    {
        $path = $this->root . '/VERSION';
        return is_file($path) ? trim((string)@file_get_contents($path)) : '';
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
                    'available'=>true,
                    'cache_key'=>$m[0],
                    'version'=>$m[1],
                    'source_head_short'=>strtolower($m[2]),
                    'source_head'=>null,
                    'build_token'=>strtolower($m[3]),
                    'matches_installed_version'=>$version !== '' && hash_equals($version, $m[1]),
                ];
            }
        }
        return [
            'available'=>false,'cache_key'=>null,'version'=>null,'source_head_short'=>null,
            'source_head'=>null,'build_token'=>null,'matches_installed_version'=>false,
        ];
    }

    private function health(
        string $rootVersion,
        string $publicVersion,
        string $mode,
        string $stateStatus,
        array $rootIdentity,
        string $runtime,
        array $slots,
        ?array $filesystem,
        string $candidateId,
        ?array $candidateSlot,
        string $publicId,
        ?array $publicSlot,
        ?array $publicRuntime,
        string $previousId,
        ?array $previousSlot,
        ?array $previousRuntime
    ): array {
        $checks = [];
        $checks[] = ['status'=>$rootVersion !== '' ? 'ok' : 'error','label'=>'Direct-root baseline','detail'=>$rootVersion !== '' ? 'Physical root VERSION: ' . $rootVersion . '.' : 'VERSION file is missing or unreadable.'];
        $checks[] = ['status'=>is_dir($runtime) && is_readable($runtime) ? 'ok' : 'warning','label'=>'Protected runtime storage','detail'=>is_dir($runtime) ? (is_readable($runtime) ? 'Runtime directory is readable.' : 'Runtime directory is not readable.') : 'Runtime directory does not exist yet.'];
        $stateOk = in_array($stateStatus, ['valid','not_initialized'], true);
        $checks[] = ['status'=>$stateOk ? 'ok' : 'error','label'=>'Release state','detail'=>$stateStatus === 'not_initialized' ? 'Not initialized; public serving is direct-root.' : 'State status: ' . $stateStatus . '.'];
        $checks[] = [
            'status'=>$mode === 'direct-root' ? 'ok' : ($mode === 'slots' ? 'ok' : 'error'),
            'label'=>'Routing mode',
            'detail'=>$mode === 'direct-root'
                ? 'PublicRouter serves the physical direct-root baseline until promotion.'
                : ($mode === 'slots' ? 'PublicRouter serves the atomically selected release slot.' : 'Release state contains an unsupported routing mode.'),
        ];
        if ($mode === 'slots') {
            $valid = is_array($publicSlot) && ($publicSlot['integrity_status'] ?? '') === 'valid' && is_array($publicRuntime);
            $checks[] = [
                'status'=>$valid ? 'ok' : 'error',
                'label'=>'Public routed release',
                'detail'=>$valid ? $publicId . ' is sealed with a prepared runtime tree; VERSION ' . $publicVersion . '.' : 'Public release slot/runtime is missing or invalid.',
            ];
        } else {
            $checks[] = [
                'status'=>!empty($rootIdentity['available']) && !empty($rootIdentity['matches_installed_version']) ? 'ok' : 'info',
                'label'=>'Direct-root build identity',
                'detail'=>!empty($rootIdentity['available'])
                    ? (!empty($rootIdentity['matches_installed_version']) ? 'Build cache identity matches physical VERSION.' : 'Build cache identity differs from physical VERSION.')
                    : 'No stamped build identity was found.',
            ];
        }

        if ($filesystem === null) {
            $checks[] = ['status'=>'warning','label'=>'Release-slot filesystem probe','detail'=>'No filesystem capability record exists yet.'];
        } else {
            $checks[] = [
                'status'=>!empty($filesystem['atomic_rename_supported']) && !empty($filesystem['symlink_supported']) ? 'ok' : 'error',
                'label'=>'Release-slot filesystem',
                'detail'=>'Strategy: ' . (string)($filesystem['selected_strategy'] ?? 'unknown')
                    . '; hard links: ' . (!empty($filesystem['hardlink_supported']) ? 'supported' : 'not available')
                    . '; symlinks: ' . (!empty($filesystem['symlink_supported']) ? 'supported' : 'not available')
                    . '; atomic rename: ' . (!empty($filesystem['atomic_rename_supported']) ? 'supported' : 'not available') . '.',
            ];
        }

        $invalid = array_values(array_filter($slots, static fn(array $slot): bool => ($slot['integrity_status'] ?? '') !== 'valid'));
        $checks[] = ['status'=>$slots === [] ? 'warning' : ($invalid === [] ? 'ok' : 'error'),'label'=>'Installed release slots','detail'=>$slots === [] ? 'No release slot has been materialized yet.' : count($slots) . ' slot(s) discovered; ' . count($invalid) . ' invalid.'];

        if ($candidateId !== '') {
            $valid = is_array($candidateSlot) && ($candidateSlot['integrity_status'] ?? '') === 'valid';
            $checks[] = ['status'=>$valid ? 'ok' : 'error','label'=>'Registered candidate','detail'=>$valid ? $candidateId . ' is sealed and valid.' : $candidateId . ' is registered but invalid or missing.'];
        } else {
            $checks[] = ['status'=>'info','label'=>'Registered candidate','detail'=>'No candidate is registered.'];
        }

        if ($previousId !== '') {
            $valid = is_array($previousSlot) && ($previousSlot['integrity_status'] ?? '') === 'valid' && is_array($previousRuntime);
            $checks[] = ['status'=>$valid ? 'ok' : 'error','label'=>'Rollback target','detail'=>$valid ? $previousId . ' is verified and runtime-ready.' : 'Recorded rollback target is invalid or not runtime-ready.'];
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
