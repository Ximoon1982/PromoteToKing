<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

/**
 * Read-only v2.14.0 release-state projection.
 *
 * No method in this class creates, modifies, renames or deletes release state.
 * Later v2.14.x increments may add a separate mutation service after candidate
 * slots and atomic activation have been qualified.
 */
final class ReleaseControlState
{
    public const STATE_SCHEMA = 1;

    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $version = $this->installedVersion();
        $runtime = $this->runtimeDir();
        $statePath = $runtime . '/release-control/state.json';
        [$state, $stateStatus] = $this->readState($statePath);
        $identity = $this->buildIdentity($version);

        $mode = (string)($state['mode'] ?? 'direct-root');
        if (!in_array($mode, ['direct-root', 'slots'], true)) $mode = 'invalid';

        return [
            'schema_version' => self::STATE_SCHEMA,
            'installed_version' => $version,
            'mode' => $mode,
            'public_release' => $state['public_release'] ?? ($version !== '' ? $version . ' (direct root)' : null),
            'previous_public_release' => $state['previous_public_release'] ?? null,
            'candidate_release' => $state['candidate_release'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
            'updated_by' => $state['updated_by'] ?? null,
            'state_file_present' => is_file($statePath),
            'state_status' => $stateStatus,
            'release_slots_enabled' => $mode === 'slots',
            'runtime_status' => [
                'exists' => is_dir($runtime),
                'readable' => is_readable($runtime),
                'writable' => is_writable($runtime),
                'display_path' => $this->displayPath($runtime),
            ],
            'build_identity' => $identity,
            'health' => $this->health($version, $mode, $stateStatus, $identity, $runtime),
            'capabilities' => [
                'candidate_install' => false,
                'personal_preview' => false,
                'promotion' => false,
                'rollback' => false,
                'state_mutation' => false,
            ],
        ];
    }

    private function installedVersion(): string
    {
        $path = $this->root . '/VERSION';
        if (!is_file($path)) return '';
        return trim((string)@file_get_contents($path));
    }

    private function runtimeDir(): string
    {
        if ($this->runtimeOverride !== null && trim($this->runtimeOverride) !== '') {
            return rtrim($this->runtimeOverride, '/\\');
        }

        $runtime = '';
        $configPath = $this->root . '/server/team-points/config/config.local.php';
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
        return $runtime !== '' ? $runtime : $this->root . '/data/runtime-v280';
    }

    /** @return array{0:array<string,mixed>,1:string} */
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

    /** @return array<string,mixed> */
    private function buildIdentity(string $version): array
    {
        $htmlPath = $this->root . '/ui-v2.html';
        $key = '';
        if (is_file($htmlPath)) {
            $raw = (string)@file_get_contents($htmlPath);
            if (preg_match('/p2k-([0-9.]+)-([0-9a-f]{12})-([0-9a-f]{16})/i', $raw, $m)) {
                $key = $m[0];
                return [
                    'available' => true,
                    'cache_key' => $key,
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

    /** @return list<array{status:string,label:string,detail:string}> */
    private function health(string $version, string $mode, string $stateStatus, array $identity, string $runtime): array
    {
        $checks = [];
        $checks[] = [
            'status' => $version !== '' ? 'ok' : 'error',
            'label' => 'Installed VERSION',
            'detail' => $version !== '' ? $version : 'VERSION file is missing or unreadable.',
        ];
        $checks[] = [
            'status' => is_dir($runtime) && is_readable($runtime) ? 'ok' : 'warning',
            'label' => 'Protected runtime storage',
            'detail' => is_dir($runtime) ? (is_readable($runtime) ? 'Runtime directory is readable.' : 'Runtime directory is not readable.') : 'Runtime directory does not exist yet.',
        ];
        $stateOk = in_array($stateStatus, ['valid', 'not_initialized'], true);
        $checks[] = [
            'status' => $stateOk ? 'ok' : 'warning',
            'label' => 'Release state',
            'detail' => $stateStatus === 'not_initialized'
                ? 'Not initialized: expected while v2.14.0 remains in direct-root mode.'
                : 'State status: ' . $stateStatus . '.',
        ];
        $checks[] = [
            'status' => $mode === 'direct-root' ? 'ok' : ($mode === 'slots' ? 'info' : 'warning'),
            'label' => 'Routing mode',
            'detail' => $mode === 'direct-root'
                ? 'Legacy direct-root serving remains active; v2.14.0 changes no public routing.'
                : ($mode === 'slots' ? 'Release-slot mode is declared by state.' : 'Release state contains an unsupported routing mode.'),
        ];
        $checks[] = [
            'status' => !empty($identity['available']) && !empty($identity['matches_installed_version']) ? 'ok' : 'info',
            'label' => 'Build identity',
            'detail' => !empty($identity['available'])
                ? (!empty($identity['matches_installed_version']) ? 'Build cache identity matches VERSION.' : 'A build cache identity exists but does not match VERSION; source checkout or unstamped overlay is likely.')
                : 'No stamped build identity was found; this is expected in an unstamped source checkout.',
        ];
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
