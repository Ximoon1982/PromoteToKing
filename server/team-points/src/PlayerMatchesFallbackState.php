<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use P2K\Shared\FilesystemCache;

/**
 * Small, bounded, protected PMAF state ledger.
 *
 * One JSON ledger is shared by all players so a broken Chess.com /matches endpoint
 * cannot create one filesystem object per member and pressure the hosting inode cap.
 * This state is operational only; canonical chess facts remain in Core after normal
 * authoritative verification.
 */
final class PlayerMatchesFallbackState
{
    private string $path;
    private string $lockPath;
    private int $reprobeSeconds;
    private int $retentionSeconds;
    private int $maxEntries;

    public function __construct(array $storage = [], array $app = [])
    {
        $root = FilesystemCache::runtimeRoot($storage) . '/player-matches-fallback';
        FilesystemCache::ensureProtectedDirectory($root);
        $this->path = $root . '/state.json';
        $this->lockPath = $root . '/state.lock';
        $this->reprobeSeconds = max(3600, (int)($app['player_matches_fallback_reprobe_seconds'] ?? 604800));
        $this->retentionSeconds = max(86400, (int)($app['player_matches_fallback_state_retention_seconds'] ?? 15552000));
        $this->maxEntries = max(100, min(10000, (int)($app['player_matches_fallback_max_entries'] ?? 6000)));
    }

    /** @return array<string,mixed>|null */
    public function entry(string $username): ?array
    {
        $key = \p2k_tp_username_key($username);
        if ($key === '') return null;
        return $this->withState(function (array &$state) use ($key): ?array {
            $this->prune($state);
            $row = $state['players'][$key] ?? null;
            return is_array($row) ? $row : null;
        }, false);
    }

    /** @return array<string,mixed> */
    public function activate(string $username, string $reason): array
    {
        $key = \p2k_tp_username_key($username);
        return $this->withState(function (array &$state) use ($key, $username, $reason): array {
            $this->prune($state);
            $now = time();
            $row = is_array($state['players'][$key] ?? null) ? $state['players'][$key] : [];
            $row['username'] = trim($username);
            $row['active'] = true;
            $row['reason'] = substr(trim($reason), 0, 500);
            $row['activated_at'] = $row['activated_at'] ?? gmdate(DATE_ATOM, $now);
            $row['last_failure_at'] = gmdate(DATE_ATOM, $now);
            $row['next_primary_probe_at'] = gmdate(DATE_ATOM, $now + $this->reprobeSeconds);
            $row['updated_at'] = gmdate(DATE_ATOM, $now);
            $row['generation'] = max(1, (int)($row['generation'] ?? 0) + 1);
            $state['players'][$key] = $row;
            return $row;
        });
    }

    public function clear(string $username): void
    {
        $key = \p2k_tp_username_key($username);
        if ($key === '') return;
        $this->withState(function (array &$state) use ($key): bool {
            unset($state['players'][$key]);
            return true;
        });
    }

    public function primaryProbeDue(?array $entry): bool
    {
        if (!is_array($entry) || empty($entry['active'])) return true;
        $next = !empty($entry['next_primary_probe_at']) ? (strtotime((string)$entry['next_primary_probe_at']) ?: 0) : 0;
        return $next <= time();
    }

    /**
     * Replace/update the authoritative archive index while preserving already scheduled
     * historical months. The current/latest month is deliberately eligible again after
     * each primary-endpoint failure generation because it can still be changing.
     *
     * @param list<string> $months
     * @return array<string,mixed>
     */
    public function recordArchiveIndex(string $username, array $months): array
    {
        $key = \p2k_tp_username_key($username);
        $normalized = [];
        foreach ($months as $month) {
            $month = trim((string)$month);
            if (preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $month)) $normalized[$month] = true;
        }
        $months = array_keys($normalized);
        rsort($months, SORT_STRING);
        $signature = hash('sha256', implode('|', $months));
        return $this->withState(function (array &$state) use ($key, $username, $months, $signature): array {
            $this->prune($state);
            $now = time();
            $row = is_array($state['players'][$key] ?? null) ? $state['players'][$key] : [];
            $generation = max(1, (int)($row['generation'] ?? 1));
            // ACDC: verification is generation-scoped. A new fallback generation must
            // not inherit "scheduled" as if it meant "successfully verified". The
            // archive set is rare fallback work, so correctness wins over silently
            // carrying uncertain historical scheduling state forward.
            $verificationGeneration = (int)($row['verification_generation'] ?? 0);
            if ($verificationGeneration !== $generation) {
                $row['verification_generation'] = $generation;
                $row['required_months'] = array_slice($months, 0, 360);
                $row['scheduled_months'] = [];
                $row['successful_months'] = [];
                $row['failed_months'] = [];
                $row['failure_details'] = [];
                $pending = array_slice($months, 0, 360);
            } else {
                $scheduled = array_values(array_filter((array)($row['scheduled_months'] ?? []), static fn($m): bool => is_string($m) && preg_match('/^20\d{2}-\d{2}$/', $m) === 1));
                $successful = array_values(array_filter((array)($row['successful_months'] ?? []), static fn($m): bool => is_string($m) && preg_match('/^20\d{2}-\d{2}$/', $m) === 1));
                $knownMap = array_fill_keys(array_merge($scheduled, $successful), true);
                $pending = [];
                foreach ($months as $month) if (!isset($knownMap[$month])) $pending[] = $month;
            }
            $row['username'] = trim($username);
            $row['active'] = true;
            $row['archive_signature'] = $signature;
            $row['archive_months'] = array_slice($months, 0, 360);
            $row['pending_months'] = array_slice($pending, 0, 360);
            $row['archive_index_checked_at'] = gmdate(DATE_ATOM, $now);
            $row['archive_index_generation'] = max(1, (int)($row['generation'] ?? 1));
            $row['updated_at'] = gmdate(DATE_ATOM, $now);
            $state['players'][$key] = $row;
            return $row;
        });
    }

    /** @return list<string> */
    public function takePendingMonths(string $username, int $limit): array
    {
        $key = \p2k_tp_username_key($username);
        $limit = max(1, min(24, $limit));
        return $this->withState(function (array &$state) use ($key, $limit): array {
            $row = is_array($state['players'][$key] ?? null) ? $state['players'][$key] : [];
            $pending = array_values(array_filter((array)($row['pending_months'] ?? []), static fn($m): bool => is_string($m) && preg_match('/^20\d{2}-\d{2}$/', $m) === 1));
            return array_slice($pending, 0, $limit);
        }, false);
    }

    /** @param list<string> $months */
    public function markMonthsScheduled(string $username, array $months): array
    {
        $key = \p2k_tp_username_key($username);
        $months = array_values(array_unique(array_filter($months, static fn($m): bool => is_string($m) && preg_match('/^20\d{2}-\d{2}$/', $m) === 1)));
        return $this->withState(function (array &$state) use ($key, $months): array {
            $now = time();
            $row = is_array($state['players'][$key] ?? null) ? $state['players'][$key] : [];
            $scheduled = array_values(array_unique(array_merge((array)($row['scheduled_months'] ?? []), $months)));
            $pending = array_values(array_diff((array)($row['pending_months'] ?? []), $months));
            $row['scheduled_months'] = array_slice($scheduled, -360);
            $row['pending_months'] = array_slice($pending, 0, 360);
            $row['scheduling_complete_at'] = $pending === [] ? gmdate(DATE_ATOM, $now) : null;
            $row['updated_at'] = gmdate(DATE_ATOM, $now);
            $state['players'][$key] = $row;
            return $row;
        });
    }

    public function markMonthSucceeded(string $username, string $month, int $generation): array
    {
        return $this->markMonthResult($username, $month, $generation, true, '');
    }

    public function markMonthFailed(string $username, string $month, int $generation, string $error): array
    {
        return $this->markMonthResult($username, $month, $generation, false, $error);
    }

    /** @return array<string,mixed> */
    public function verificationStatus(string $username): array
    {
        $entry = $this->entry($username) ?? [];
        $required = array_values(array_unique((array)($entry['required_months'] ?? [])));
        $success = array_values(array_unique((array)($entry['successful_months'] ?? [])));
        $failed = array_values(array_unique((array)($entry['failed_months'] ?? [])));
        $pending = array_values(array_unique((array)($entry['pending_months'] ?? [])));
        $unresolved = array_values(array_diff($required, $success));
        return [
            'generation'=>(int)($entry['verification_generation'] ?? $entry['generation'] ?? 0),
            'required'=>count($required),'succeeded'=>count($success),'failed'=>count($failed),
            'pending_schedule'=>count($pending),'unresolved'=>count($unresolved),
            'complete'=>$required === [] || $unresolved === [],
            'failed_months'=>$failed,'unresolved_months'=>array_slice($unresolved,0,24),
        ];
    }

    private function markMonthResult(string $username, string $month, int $generation, bool $success, string $error): array
    {
        $key = \p2k_tp_username_key($username);
        return $this->withState(function (array &$state) use ($key, $month, $generation, $success, $error): array {
            $row = is_array($state['players'][$key] ?? null) ? $state['players'][$key] : [];
            if ((int)($row['verification_generation'] ?? 0) !== $generation) return $row;
            $ok = array_values(array_unique((array)($row['successful_months'] ?? [])));
            $failed = array_values(array_unique((array)($row['failed_months'] ?? [])));
            if ($success) {
                if (!in_array($month,$ok,true)) $ok[]=$month;
                $failed=array_values(array_diff($failed,[$month]));
                if (is_array($row['failure_details'] ?? null)) unset($row['failure_details'][$month]);
            } else {
                if (!in_array($month,$failed,true)) $failed[]=$month;
                $details=is_array($row['failure_details']??null)?$row['failure_details']:[];
                $details[$month]=['at'=>gmdate(DATE_ATOM),'error'=>substr($error,0,500)];
                $row['failure_details']=$details;
            }
            $row['successful_months']=array_slice($ok,-360);
            $row['failed_months']=array_slice($failed,-360);
            $required=array_values(array_unique((array)($row['required_months']??[])));
            if ($required !== [] && array_diff($required,$row['successful_months']) === []) $row['verification_complete_at']=gmdate(DATE_ATOM);
            $row['updated_at']=gmdate(DATE_ATOM);
            $state['players'][$key]=$row;
            return $row;
        });
    }

    private function prune(array &$state): void
    {
        if (!isset($state['players']) || !is_array($state['players'])) $state['players'] = [];
        $cutoff = time() - $this->retentionSeconds;
        foreach ($state['players'] as $key => $row) {
            if (!is_array($row)) { unset($state['players'][$key]); continue; }
            $updated = !empty($row['updated_at']) ? (strtotime((string)$row['updated_at']) ?: 0) : 0;
            if ($updated > 0 && $updated < $cutoff) unset($state['players'][$key]);
        }
        if (count($state['players']) > $this->maxEntries) {
            uasort($state['players'], static function ($a, $b): int {
                $ta = is_array($a) && !empty($a['updated_at']) ? (strtotime((string)$a['updated_at']) ?: 0) : 0;
                $tb = is_array($b) && !empty($b['updated_at']) ? (strtotime((string)$b['updated_at']) ?: 0) : 0;
                return $tb <=> $ta;
            });
            $state['players'] = array_slice($state['players'], 0, $this->maxEntries, true);
        }
    }

    private function readUnlocked(): array
    {
        if (!is_file($this->path)) return ['version' => 1, 'players' => []];
        $raw = @file_get_contents($this->path);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : ['version' => 1, 'players' => []];
    }

    private function writeUnlocked(array $state): void
    {
        $state['version'] = 1;
        $state['updated_at'] = gmdate(DATE_ATOM);
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $tmp = $this->path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to persist Player Matches fallback state.');
        }
        @chmod($this->path, 0600);
    }

    private function withState(callable $callback, bool $write = true): mixed
    {
        $lock = @fopen($this->lockPath, 'c+');
        if ($lock === false) throw new \RuntimeException('Unable to open Player Matches fallback state lock.');
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Unable to lock Player Matches fallback state.');
            $state = $this->readUnlocked();
            $result = $callback($state);
            if ($write) $this->writeUnlocked($state);
            flock($lock, LOCK_UN);
            return $result;
        } finally {
            @fclose($lock);
        }
    }
}
