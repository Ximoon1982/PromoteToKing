<?php
declare(strict_types=1);

namespace P2K\Tournaments;

final class TournamentService
{
    private array $config;
    private ?ChessApi $api = null;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? \p2k_tournament_config();
    }

    private function api(): ChessApi
    {
        return $this->api ??= new ChessApi($this->config);
    }

    public function archive(): array
    {
        $path = \p2k_tournament_archive_path();
        $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
        $archive = is_array($data) ? $this->normalizeArchive($data) : $this->emptyArchive();
        if (!empty($archive['tournaments']) || !empty($archive['reinitializedAt'])) return $archive;

        // Upgrade safety: older standalone ZIPs accidentally shipped an empty live archive.
        // If that placeholder overwrote production, recover read-time data from the newest
        // non-empty backup or from the still-present materialized BrowseIndex cache.
        $candidates = [];
        foreach ([$path . '.bak', ...array_reverse(glob(dirname($path) . '/backups/archive-*.json') ?: [])] as $candidate) {
            if (!is_file($candidate)) continue;
            $raw = json_decode((string)@file_get_contents($candidate), true);
            if (is_array($raw) && !empty($raw['tournaments'])) $candidates[] = ['source'=>$candidate,'archive'=>$this->normalizeArchive($raw)];
        }
        if ($candidates) {
            $recovered = $candidates[0]['archive'];
            $recovered['recoverySource'] = 'backup:' . basename($candidates[0]['source']);
            return $recovered;
        }
        $browseCache = \p2k_tournament_cache_dir() . '/browse-index-v1.json';
        if (is_file($browseCache)) {
            $cache = json_decode((string)@file_get_contents($browseCache), true);
            $rows = is_array($cache['index']['tournaments'] ?? null) ? $cache['index']['tournaments'] : [];
            if ($rows) {
                $recovered = $this->emptyArchive();
                $recovered['generatedAt'] = $cache['index']['generatedAt'] ?? null;
                $recovered['tournaments'] = $rows;
                $recovered['recoverySource'] = 'browse-index-cache';
                return $this->normalizeArchive($recovered);
            }
        }
        return $archive;
    }

    /**
     * Server-side bounded refresh used by CRON and the lightweight status button.
     * Discovery uses the same exact naming families as the browser demo.
     */
    public function refresh(string $mode, ?int $maxSeconds = null): array
    {
        if (!in_array($mode, ['status', 'cron'], true)) {
            throw new \InvalidArgumentException('mode must be status or cron.');
        }
        return $this->withLock(function () use ($mode, $maxSeconds): array {
            $deadline = $maxSeconds === null ? null : microtime(true) + max(10, min(45, $maxSeconds));
            $deadlineCheckpoint = false;
            $archive = $this->archive();
            $known = $this->indexed($archive['tournaments']);
            $scanState = is_array($archive['scanState'] ?? null) ? $archive['scanState'] : [];
            $errors = [];
            $checked = 0;
            $updated = 0;
            $discovered = 0;

            // IONOS-safe staged worker: each invocation performs only one API-heavy
            // stage and persists its cursor. This keeps HTTP cron calls below the
            // 60-second platform ceiling and prevents one slow podium analysis from
            // starving discovery and status maintenance.
            $stages = ['discovery', 'status', 'podium'];
            $stageIndex = max(0, (int)($scanState['workerStageIndex'] ?? 0)) % count($stages);
            $workerStage = $mode === 'cron' ? $stages[$stageIndex] : 'status';
            $scanState['workerStage'] = $workerStage;
            $scanState['workerStageStartedAt'] = \p2k_tournament_now();

            $batch = is_array($scanState['serverBatch'] ?? null) ? $scanState['serverBatch'] : [];
            $candidates = is_array($batch['candidates'] ?? null) ? array_values(array_filter(array_map('strval', $batch['candidates']))) : [];
            $index = max(0, (int)($batch['index'] ?? 0));
            $candidateRetries = is_array($batch['retryCandidates'] ?? null) ? array_values(array_filter(array_map('strval', $batch['retryCandidates']))) : [];
            if ($candidates === [] || $index >= count($candidates)) {
                $candidates = $this->candidateSlugs($scanState);
                $index = 0;
                $batch = [
                    'createdAt' => \p2k_tournament_now(),
                    'candidates' => $candidates,
                    'index' => 0,
                    'retryCandidates' => [],
                    'attempt' => 1,
                ];
                $candidateRetries = [];
            }

            $candidateLimit = ($mode === 'cron' && $workerStage === 'discovery') ? max(1, min(20, (int)($this->config['cron_candidate_batch_size'] ?? 3))) : 0;
            $candidateEnd = min(count($candidates), $index + $candidateLimit);
            for (; $index < $candidateEnd; $index++) {
                if (!$this->hasRequestBudget($deadline)) {
                    $deadlineCheckpoint = true;
                    break;
                }
                $slug = $candidates[$index];
                try {
                    $profile = $this->api()->get('https://api.chess.com/pub/tournament/' . rawurlencode($slug), (int)($this->config['status_cache_ttl_seconds'] ?? 3600), true);
                    $checked++;
                    if (!$profile) continue;
                    $existing = $known[$slug] ?? ['slug' => $slug];
                    $fresh = $this->readTournamentFromProfile($slug, $profile, $existing, false);
                    if (!isset($known[$slug])) $discovered++;
                    if ($fresh != $existing) $updated++;
                    $known[$slug] = $fresh;
                } catch (\Throwable $error) {
                    $errors[] = ['slug' => $slug, 'message' => $error->getMessage()];
                    $candidateRetries[] = $slug;
                }
            }
            $batchRemaining = 0;
            if ($mode === 'cron') {
                $batch['index'] = $index;
                $batch['retryCandidates'] = array_values(array_unique($candidateRetries));
                if ($index >= count($candidates)) {
                    if ($batch['retryCandidates'] !== []) {
                        $batch = [
                            'createdAt' => \p2k_tournament_now(),
                            'candidates' => $batch['retryCandidates'],
                            'index' => 0,
                            'retryCandidates' => [],
                            'attempt' => max(1, (int)($batch['attempt'] ?? 1)) + 1,
                        ];
                        $scanState['serverBatch'] = $batch;
                        $batchRemaining = count($batch['candidates']);
                    } else {
                        $scanState = array_replace($scanState, $this->settledScanState());
                        unset($scanState['serverBatch']);
                    }
                } else {
                    $scanState['serverBatch'] = $batch;
                    $batchRemaining = max(0, count($candidates) - $index) + count($batch['retryCandidates']);
                }
            }

            // Rotate through stored unfinished tournaments without making a CRON run unbounded.
            $pending = array_values(array_filter(array_keys($known), static function (string $slug) use ($known): bool {
                return strtolower((string)($known[$slug]['status'] ?? 'unknown')) !== 'finished';
            }));
            sort($pending, SORT_NATURAL | SORT_FLAG_CASE);
            // Status maintenance is the primary 10-minute Tournament contract and
            // therefore runs on every cron invocation. Discovery/podium remain staged
            // extras so they cannot starve current-status freshness.
            $statusLimit = max(1, min(20, (int)($this->config['cron_status_batch_size'] ?? 4)));
            $cursor = max(0, (int)($scanState['statusCursor'] ?? 0));
            $statusProcessed = 0;
            if ($pending !== []) {
                for ($offset = 0; $offset < min($statusLimit, count($pending)); $offset++) {
                    if (!$this->hasRequestBudget($deadline)) {
                        $deadlineCheckpoint = true;
                        break;
                    }
                    $position = ($cursor + $offset) % count($pending);
                    $slug = $pending[$position];
                    try {
                        $existing = $known[$slug];
                        $fresh = $this->readTournament($slug, $existing, false);
                        $checked++;
                        if ($fresh != $existing) $updated++;
                        $known[$slug] = $fresh;
                    } catch (\Throwable $error) {
                        $errors[] = ['slug' => $slug, 'message' => $error->getMessage()];
                    }
                    $statusProcessed++;
                }
                $scanState['statusCursor'] = ($cursor + $statusProcessed) % count($pending);
            } else {
                $scanState['statusCursor'] = 0;
            }
            $scanState['lastStatusCheckAt'] = \p2k_tournament_now();

            // Resolve at most a small number of incomplete finished podiums per run.
            $podiumPending = array_values(array_filter(array_keys($known), function (string $slug) use ($known): bool {
                $row = $known[$slug];
                if (strtolower((string)($row['status'] ?? '')) !== 'finished') return false;
                $podium = is_array($row['podium'] ?? null) ? $row['podium'] : [];
                return empty($row['finishAt']) || !$this->names($podium['gold'] ?? []) || !$this->names($podium['silver'] ?? []) || !$this->names($podium['bronze'] ?? []);
            }));
            sort($podiumPending, SORT_NATURAL | SORT_FLAG_CASE);
            $podiumLimit = ($mode === 'cron' && $workerStage === 'podium') ? max(1, min(2, (int)($this->config['cron_podium_batch_size'] ?? 1))) : 0;
            $podiumCursor = max(0, (int)($scanState['podiumCursor'] ?? 0));
            if ($podiumPending !== [] && $podiumLimit > 0) {
                $podiumProcessed = 0;
                for ($offset = 0; $offset < min($podiumLimit, count($podiumPending)); $offset++) {
                    if (!$this->hasRequestBudget($deadline)) {
                        $deadlineCheckpoint = true;
                        break;
                    }
                    $position = ($podiumCursor + $offset) % count($podiumPending);
                    $slug = $podiumPending[$position];
                    try {
                        $existing = $known[$slug];
                        $fresh = $this->readTournament($slug, $existing, true);
                        $checked++;
                        if ($fresh != $existing) $updated++;
                        $known[$slug] = $fresh;
                    } catch (\Throwable $error) {
                        $errors[] = ['slug' => $slug, 'message' => $error->getMessage()];
                    }
                    $podiumProcessed++;
                }
                $scanState['podiumCursor'] = ($podiumCursor + $podiumProcessed) % count($podiumPending);
            } else {
                $scanState['podiumCursor'] = 0;
            }

            if ($mode === 'cron') {
                $scanState['workerStageCompletedAt'] = \p2k_tournament_now();
                $scanState['workerStageIndex'] = ($stageIndex + 1) % count($stages);
                $scanState['nextWorkerStage'] = $stages[$scanState['workerStageIndex']];
                $cycleDate = gmdate('Y-m-d');
                $cycle = is_array($scanState['dailyCycle'] ?? null) && ($scanState['dailyCycle']['date'] ?? '') === $cycleDate
                    ? $scanState['dailyCycle']
                    : ['date' => $cycleDate, 'startedAt' => \p2k_tournament_now(), 'stages' => []];
                $cycle['stages'][$workerStage] = ['lastRunAt' => \p2k_tournament_now(), 'checked' => $checked, 'updated' => $updated, 'errors' => count($errors)];
                $cycle['complete'] = count(array_intersect($stages, array_keys($cycle['stages']))) === count($stages);
                if ($cycle['complete']) $cycle['completedAt'] = \p2k_tournament_now();
                $scanState['dailyCycle'] = $cycle;
            }

            $tournaments = $this->sorted(array_values($known));
            $archive['schemaVersion'] = 3;
            $archive['generatedAt'] = \p2k_tournament_now();
            $archive['scanState'] = $scanState + $this->defaultScanState();
            $archive['tournaments'] = $tournaments;
            $archive['lastStatusRefresh'] = [
                'at' => \p2k_tournament_now(),
                'checked' => $checked,
                'updated' => $updated,
                'discovered' => $discovered,
                'errors' => count($errors),
                'batchRemaining' => $mode === 'cron' ? $batchRemaining : 0,
                'deadlineCheckpoint' => $deadlineCheckpoint,
                'workerStage' => $workerStage,
                'statusChecked' => $statusProcessed,
                'statusCheckedAt' => $scanState['lastStatusCheckAt'],
                'nextWorkerStage' => $scanState['nextWorkerStage'] ?? null,
            ];
            $this->save($archive);

            return [
                'archive' => $archive,
                'checked' => $checked,
                'updated' => $updated,
                'excluded' => count($archive['excludedMedalists'] ?? []),
                'discovered' => $discovered,
                'errors' => $errors,
                'requests' => $this->api?->requestCount ?? 0,
                'cacheHits' => $this->api?->cacheHits ?? 0,
                'batchRemaining' => $mode === 'cron' ? $batchRemaining : 0,
                'deadlineCheckpoint' => $deadlineCheckpoint,
                'workerStage' => $workerStage,
                'nextWorkerStage' => $scanState['nextWorkerStage'] ?? null,
                'dailyCycle' => $scanState['dailyCycle'] ?? null,
            ];
        });
    }

    private function hasRequestBudget(?float $deadline): bool
    {
        if ($deadline === null) return true;
        $timeout = max(3, min(30, (int)($this->config['request_timeout_seconds'] ?? 15)));
        return ($deadline - microtime(true)) >= min(18, $timeout + 2);
    }


    /** Queue a fresh incremental maintenance cycle without doing Chess.com work in the browser request. */
    public function enqueueUpdate(array $payload = []): array
    {
        return $this->withLock(function () use ($payload): array {
            $archive = $this->archive();
            $scanState = is_array($archive['scanState'] ?? null) ? $archive['scanState'] : [];
            $scanState['workerStageIndex'] = 0;
            $scanState['workerStage'] = 'discovery';
            $scanState['nextWorkerStage'] = 'discovery';
            $scanState['manualUpdateQueuedAt'] = \p2k_tournament_now();
            $scanState['dailyCycle'] = [
                'date' => gmdate('Y-m-d'),
                'startedAt' => \p2k_tournament_now(),
                'stages' => [],
                'complete' => false,
                'source' => 'manual',
            ];
            if (!empty($payload['missingMedalists'])) $scanState['podiumCursor'] = 0;
            $archive['scanState'] = $scanState + $this->defaultScanState();
            $archive['generatedAt'] = \p2k_tournament_now();
            $this->save($archive);
            return [
                'archive' => $archive,
                'checked' => 0,
                'updated' => 0,
                'discovered' => 0,
                'excluded' => 0,
                'errors' => [],
                'queued' => true,
                'workerStage' => 'discovery',
            ];
        });
    }

    /**
     * Back up and clear all tournament, podium, cursor, exclusion and HTTP-cache
     * state before a deliberate browser-side rebuild from January 2024.
     */
    public function reinitializeArchive(array $payload = []): array
    {
        if (strtoupper(trim((string)($payload['confirm'] ?? ''))) !== 'REINITIALIZE') {
            throw new \InvalidArgumentException('Explicit REINITIALIZE confirmation is required.');
        }
        return $this->withLock(function (): array {
            $path = \p2k_tournament_archive_path();
            $backupPath = null;
            if (is_file($path)) {
                $backupDir = dirname($path) . '/backups';
                if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
                    throw new \RuntimeException('Unable to create the tournament backup directory.');
                }
                $backupPath = $backupDir . '/archive-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
                if (!copy($path, $backupPath)) throw new \RuntimeException('Unable to back up the current tournament archive.');
            }

            $cacheDir = \p2k_tournament_cache_dir();
            $clearedCacheFiles = 0;
            if (is_dir($cacheDir)) {
                foreach (new \DirectoryIterator($cacheDir) as $item) {
                    if ($item->isDot() || !$item->isFile() || $item->getFilename() === '.gitkeep') continue;
                    if (@unlink($item->getPathname())) $clearedCacheFiles++;
                }
            }

            $archive = $this->emptyArchive();
            $archive['generatedAt'] = \p2k_tournament_now();
            $archive['reinitializedAt'] = \p2k_tournament_now();
            $archive['previousArchiveBackup'] = $backupPath ? 'data/tournaments/backups/' . basename($backupPath) : null;
            $this->save($archive);
            return [
                'archive' => $archive,
                'checked' => 0,
                'updated' => 0,
                'imported' => 0,
                'excluded' => 0,
                'errors' => [],
                'backup' => $archive['previousArchiveBackup'],
                'clearedCacheFiles' => $clearedCacheFiles,
            ];
        });
    }

    /** Persist a completed browser-side scan without making Chess.com calls in PHP. */
    public function importClientScan(array $payload): array
    {
        return $this->withLock(function () use ($payload): array {
            $archive = $this->archive();
            $known = $this->indexed($archive['tournaments']);
            $incoming = is_array($payload['tournaments'] ?? null) ? $payload['tournaments'] : [];
            $imported = 0;
            $updated = 0;
            foreach ($incoming as $row) {
                if (!is_array($row)) continue;
                $tournament = $this->sanitizeTournament($row);
                $slug = $tournament['slug'];
                if ($slug === '') continue;
                $existing = $known[$slug] ?? null;
                if ($existing === null) $imported++;
                elseif ($existing != $tournament) $updated++;
                $known[$slug] = $tournament;
            }

            $excluded = [];
            foreach (($payload['excludedMedalists'] ?? []) as $row) {
                if (!is_array($row)) continue;
                $username = trim((string)($row['username'] ?? ''));
                $tournament = trim((string)($row['tournament'] ?? ''));
                if ($username === '' || $tournament === '') continue;
                $key = strtolower($username) . '|' . $tournament;
                $excluded[$key] = [
                    'username' => $username,
                    'tournament' => $tournament,
                    'medal' => trim((string)($row['medal'] ?? '')),
                    'reason' => trim((string)($row['reason'] ?? 'closed_account')),
                    'checkedAt' => trim((string)($row['checkedAt'] ?? \p2k_tournament_now())),
                ];
            }

            $scanState = is_array($payload['scanState'] ?? null) ? $payload['scanState'] : [];
            $scanState = array_replace($this->defaultScanState(), $scanState);
            unset($scanState['serverBatch']);
            $tournaments = $this->sorted(array_values($known));
            $archive = [
                'schemaVersion' => 2,
                'generatedAt' => \p2k_tournament_now(),
                'scanState' => $scanState,
                'tournaments' => $tournaments,
                'excludedMedalists' => array_values($excluded),
                'lastManualCheck' => [
                    'at' => \p2k_tournament_now(),
                    'checked' => max(0, (int)($payload['checked'] ?? count($incoming))),
                    'excluded' => count($excluded),
                    'errors' => max(0, (int)($payload['errors'] ?? 0)),
                ],
                'lastStatusRefresh' => $archive['lastStatusRefresh'] ?? null,
            ];
            $this->save($archive);
            $clientErrorCount = max(0, (int)($payload['errors'] ?? 0));
            return [
                'archive' => $archive,
                'checked' => max(0, (int)($payload['checked'] ?? count($incoming))),
                'updated' => $updated,
                'imported' => $imported,
                'excluded' => count($excluded),
                'errors' => [],
                'errorCount' => $clientErrorCount,
                'requests' => max(0, (int)($payload['requests'] ?? 0)),
                'cacheHits' => 0,
            ];
        });
    }


    /** Add or refresh one explicitly supplied tournament without running discovery. */
    public function manualAdd(array $payload): array
    {
        $input = trim((string)($payload['tournament'] ?? $payload['url'] ?? $payload['slug'] ?? ''));
        if ($input === '') throw new \InvalidArgumentException('A tournament URL or slug is required.');
        $slug = $this->slug($input);
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]{1,180}$/', $slug)) {
            throw new \InvalidArgumentException('The tournament URL or slug is invalid.');
        }
        $resolvePodium = !array_key_exists('resolvePodium', $payload) || (bool)$payload['resolvePodium'];
        return $this->withLock(function () use ($slug, $resolvePodium): array {
            $archive = $this->archive();
            $known = $this->indexed($archive['tournaments']);
            $existing = $known[$slug] ?? ['slug' => $slug];
            $fresh = $this->readTournament($slug, $existing, $resolvePodium);
            $wasNew = !isset($known[$slug]);
            $changed = $wasNew || $fresh != $existing;
            $known[$slug] = $fresh;
            $archive['schemaVersion'] = 2;
            $archive['generatedAt'] = \p2k_tournament_now();
            $archive['tournaments'] = $this->sorted(array_values($known));
            $archive['lastManualEntry'] = [
                'at' => \p2k_tournament_now(),
                'slug' => $slug,
                'added' => $wasNew,
                'updated' => $changed && !$wasNew,
            ];
            $this->save($archive);
            return [
                'archive' => $archive,
                'checked' => 1,
                'updated' => $changed && !$wasNew ? 1 : 0,
                'imported' => $wasNew ? 1 : 0,
                'excluded' => count($archive['excludedMedalists'] ?? []),
                'errors' => [],
                'tournament' => $fresh,
                'requests' => $this->api?->requestCount ?? 0,
                'cacheHits' => $this->api?->cacheHits ?? 0,
            ];
        });
    }

    private function readTournament(string $slug, array $existing, bool $resolvePodium): array
    {
        $root = $this->api()->get(
            'https://api.chess.com/pub/tournament/' . rawurlencode($slug),
            (int)($this->config['status_cache_ttl_seconds'] ?? 3600)
        );
        if (!$root) throw new \RuntimeException('Tournament not found.');
        return $this->readTournamentFromProfile($slug, $root, $existing, $resolvePodium);
    }

    private function readTournamentFromProfile(string $slug, array $root, array $existing, bool $resolvePodium): array
    {
        $period = $this->periodInfo($slug, $root);
        $status = $this->normalizeStatus((string)($root['status'] ?? $existing['status'] ?? 'unknown'));
        $podium = is_array($existing['podium'] ?? null) ? $existing['podium'] : ['gold' => [], 'silver' => [], 'bronze' => []];
        $fairPlay = is_array($existing['fairPlayRemovals'] ?? null) ? $existing['fairPlayRemovals'] : [];
        if ($status === 'finished' && $resolvePodium && (!$this->names($podium['gold'] ?? []) || !$this->names($podium['silver'] ?? []) || !$this->names($podium['bronze'] ?? []))) {
            [$podium, $roundFairPlay] = $this->resolvePodium($slug, $root);
            $fairPlay = array_values(array_unique(array_merge($fairPlay, $roundFairPlay)));
        }
        return $this->sanitizeTournament([
            'slug' => $slug,
            'name' => (string)($root['name'] ?? $existing['name'] ?? $this->title($slug)),
            'webUrl' => 'https://www.chess.com/tournament/' . $slug,
            'type' => $this->type($slug),
            'period' => $period['period'],
            'periodSort' => $period['periodSort'],
            'year' => $period['year'],
            'status' => $status,
            'finishAt' => $this->tournamentFinishAt($root, $existing),
            'playerCount' => is_array($root['players'] ?? null) ? count($root['players']) : (int)($root['players'] ?? $root['total_players'] ?? $existing['playerCount'] ?? 0),
            'participants' => is_array($root['players'] ?? null) ? $this->names($root['players']) : $this->names($existing['participants'] ?? []),
            'podium' => $podium,
            'fairPlayRemovals' => $fairPlay,
            'updatedAt' => \p2k_tournament_now(),
        ]);
    }

    private function resolvePodium(string $slug, array $root): array
    {
        $placements = [1 => [], 2 => [], 3 => []];
        $fairPlay = [];
        $seen = [];
        $processed = 0;
        foreach (($root['players'] ?? []) as $player) {
            $username = $this->playerName($player);
            $status = is_array($player) ? strtolower(trim((string)($player['status'] ?? ''))) : '';
            if ($username !== '' && in_array($status, ['winner', 'won'], true)) $placements[1][] = $username;
        }
        $rounds = array_reverse(array_values(array_filter($root['rounds'] ?? [], 'is_string')));
        foreach ($rounds as $roundUrl) {
            $round = $this->api()->get($roundUrl, 0, true) ?? [];
            $roundPlayers = [];
            foreach (($round['players'] ?? []) as $player) {
                $username = $this->playerName($player);
                if ($username !== '') $roundPlayers[strtolower($username)] = $username;
            }
            $groupRows = [];
            foreach (($round['groups'] ?? []) as $groupUrl) {
                $group = $this->api()->get((string)$groupUrl, 0, true) ?? [];
                $fairPlay = array_merge($fairPlay, $this->names($group['fair_play_removals'] ?? []));
                $rows = is_array($group['players'] ?? null) ? $group['players'] : [];
                if ($rows !== []) $groupRows[] = $rows;
                foreach ($rows as $player) {
                    $username = $this->playerName($player);
                    if ($username !== '') $roundPlayers[strtolower($username)] = $username;
                }
            }

            $roundHasNonMedalist = false;
            // A completed daily knock-out ends with one final group. Its points and
            // tie-break values are sufficient to assign the three medal colours,
            // including shared medals for exact ties, without querying every player.
            if (count($groupRows) === 1) {
                $fallback = $this->fallbackPlacements($groupRows[0]);
                foreach ([1, 2, 3] as $place) {
                    if ($this->names($placements[$place]) === []) $placements[$place] = $fallback[$place];
                }
                $roundHasNonMedalist = (bool)$fallback['hasFourth'];
                foreach ([1, 2, 3] as $place) $placements[$place] = $this->names($placements[$place]);
                if ($roundHasNonMedalist && $placements[1] !== [] && $placements[2] !== [] && $placements[3] !== []) break;
            }

            // Player-tournament placement remains the authoritative fallback for
            // unusual brackets where the last round contains more than one group.
            foreach ($roundPlayers as $key => $username) {
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $processed++;
                $placement = $this->playerPlacement($username, $slug);
                if ($placement >= 1 && $placement <= 3) $placements[$placement][] = $username;
                if ($placement > 3) $roundHasNonMedalist = true;
                if ($processed >= (int)($this->config['max_ranking_players'] ?? 160)) break 2;
            }
            foreach ([1, 2, 3] as $place) $placements[$place] = $this->names($placements[$place]);
            if ($roundHasNonMedalist && $placements[1] !== [] && $placements[2] !== [] && $placements[3] !== []) break;
        }
        return [[
            'gold' => $this->names($placements[1]),
            'silver' => $this->names($placements[2]),
            'bronze' => $this->names($placements[3]),
        ], $this->names($fairPlay)];
    }

    private function fallbackPlacements(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $username = $this->playerName($row);
            if ($username === '' || !is_numeric($row['points'] ?? null)) continue;
            $clean[] = [
                'username' => $username,
                'points' => (float)$row['points'],
                'tie' => (float)($row['tie_break'] ?? $row['tiebreak'] ?? 0),
            ];
        }
        usort($clean, static fn(array $a, array $b): int => ($b['points'] <=> $a['points']) ?: ($b['tie'] <=> $a['tie']) ?: strcasecmp($a['username'], $b['username']));
        $result = [1 => [], 2 => [], 3 => [], 'hasFourth' => false];
        $previous = null;
        $medalPlace = 0;
        foreach ($clean as $row) {
            $signature = $row['points'] . '|' . $row['tie'];
            if ($signature !== $previous) {
                $medalPlace++;
                $previous = $signature;
            }
            if ($medalPlace <= 3) $result[$medalPlace][] = $row['username'];
            else $result['hasFourth'] = true;
        }
        return $result;
    }

    private function playerPlacement(string $username, string $slug): int
    {
        $payload = $this->api()->get('https://api.chess.com/pub/player/' . rawurlencode(strtolower($username)) . '/tournaments', 86400, true);
        if (!$payload) return 0;
        foreach (['finished', 'in_progress', 'registered'] as $bucket) {
            foreach (($payload[$bucket] ?? []) as $entry) {
                if (!is_array($entry)) continue;
                $matches = false;
                foreach ([$entry['@id'] ?? '', $entry['url'] ?? '', $entry['id'] ?? ''] as $url) {
                    if ($url !== '' && strtolower($this->tournamentSlug((string)$url)) === strtolower($slug)) { $matches = true; break; }
                }
                if (!$matches) continue;
                $placement = max(0, (int)($entry['placement'] ?? 0));
                $status = strtolower(trim((string)($entry['status'] ?? '')));
                return $placement > 0 ? $placement : (in_array($status, ['winner', 'won'], true) ? 1 : 0);
            }
        }
        return 0;
    }

    private function playerName(mixed $player): string
    {
        if (is_string($player)) return trim($player);
        if (!is_array($player)) return '';
        return trim((string)($player['username'] ?? $player['name'] ?? ''));
    }

    private function tournamentSlug(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? $url);
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
        foreach ($parts as $index => $part) {
            if (strtolower($part) === 'tournament' && isset($parts[$index + 1])) return strtolower(rawurldecode($parts[$index + 1]));
        }
        return strtolower(rawurldecode((string)basename(rtrim($path, '/'))));
    }

    private function candidateSlugs(array $scanState): array
    {
        $list = [];
        $startYear = max(2000, (int)($this->config['discovery_start_year'] ?? 2024));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $monthlyFrontier = $now->modify('first day of this month')->modify('-1 month');
        $monthlyStart = $this->monthAfter((string)($scanState['monthlyThrough'] ?? ''), $startYear);
        for ($month = $monthlyStart; $month <= $monthlyFrontier; $month = $month->modify('+1 month')) {
            $monthName = $month->format('F');
            $year = (int)$month->format('Y');
            foreach ([10, 32] as $size) {
                $list[] = $this->slugify("Promote to King top {$size} players of {$monthName} {$year} knock-out");
            }
        }

        $currentQuarter = (int)ceil(((int)$now->format('n')) / 3);
        [$quarterYear, $quarter] = $this->quarterAfter((string)($scanState['quarterlyThrough'] ?? ''), $startYear);
        while ($quarterYear < (int)$now->format('Y') || ($quarterYear === (int)$now->format('Y') && $quarter <= $currentQuarter)) {
            foreach (['Pawns', 'Knights', 'Bishops', 'Rooks', 'Queens', 'Kings'] as $rank) {
                $list[] = $this->slugify("Promote to King quarterly {$rank} knock-out Q{$quarter} {$quarterYear}");
            }
            $quarter++;
            if ($quarter > 4) { $quarter = 1; $quarterYear++; }
        }

        $christmasStart = max($startYear, ((int)($scanState['christmasThrough'] ?? 0)) + 1);
        for ($year = $christmasStart; $year <= (int)$now->format('Y'); $year++) {
            $list[] = $this->slugify("Promote to King {$year} private Christmas tournament");
        }
        foreach ($this->config['known_tournaments'] ?? [] as $slug) $list[] = trim((string)$slug);
        return array_values(array_unique(array_filter($list)));
    }

    private function settledScanState(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $monthly = $now->modify('first day of this month')->modify('-2 months')->format('Y-m');
        $currentQuarter = (int)ceil(((int)$now->format('n')) / 3);
        $year = (int)$now->format('Y');
        $settledQuarter = $currentQuarter - 1;
        if ($settledQuarter < 1) { $settledQuarter = 4; $year--; }
        return [
            'monthlyThrough' => $monthly,
            'quarterlyThrough' => sprintf('%04d-Q%d', $year, $settledQuarter),
            'christmasThrough' => (int)$now->format('Y') - 1,
            'lastSync' => \p2k_tournament_now(),
            'discoveryModel' => 'title-derived-v1',
        ];
    }

    private function monthAfter(string $cursor, int $startYear): \DateTimeImmutable
    {
        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $cursor)) {
            return (new \DateTimeImmutable($cursor . '-01', new \DateTimeZone('UTC')))->modify('+1 month');
        }
        return new \DateTimeImmutable(sprintf('%04d-01-01', $startYear), new \DateTimeZone('UTC'));
    }

    private function quarterAfter(string $cursor, int $startYear): array
    {
        if (preg_match('/^(20\d{2})-Q([1-4])$/', $cursor, $match)) {
            $year = (int)$match[1];
            $quarter = (int)$match[2] + 1;
            if ($quarter > 4) { $quarter = 1; $year++; }
            return [$year, $quarter];
        }
        return [$startYear, 1];
    }

    private function defaultScanState(): array
    {
        return ['monthlyThrough' => null, 'quarterlyThrough' => null, 'christmasThrough' => null, 'lastSync' => null, 'statusCursor' => 0, 'podiumCursor' => 0, 'discoveryModel' => null];
    }

    private function normalizeArchive(array $archive): array
    {
        $empty = $this->emptyArchive();
        $normalized = array_replace($empty, $archive);
        $normalized['scanState'] = array_replace($this->defaultScanState(), is_array($archive['scanState'] ?? null) ? $archive['scanState'] : []);
        $normalized['tournaments'] = $this->sorted(array_values(array_filter(array_map(function ($row): ?array {
            return is_array($row) ? $this->sanitizeTournament($row) : null;
        }, is_array($archive['tournaments'] ?? null) ? $archive['tournaments'] : []))));
        $normalized['excludedMedalists'] = is_array($archive['excludedMedalists'] ?? null) ? array_values($archive['excludedMedalists']) : [];
        return $normalized;
    }

    private function sanitizeTournament(array $row): array
    {
        $slug = $this->slug((string)($row['slug'] ?? $row['webUrl'] ?? ''));
        $period = $this->periodInfo($slug, $row);
        $podium = is_array($row['podium'] ?? null) ? $row['podium'] : [];
        return [
            'slug' => $slug,
            'name' => trim((string)($row['name'] ?? $this->title($slug))),
            'webUrl' => 'https://www.chess.com/tournament/' . $slug,
            'type' => trim((string)($row['type'] ?? $this->type($slug))),
            'period' => trim((string)($row['period'] ?? $period['period'])),
            'periodSort' => trim((string)($row['periodSort'] ?? $period['periodSort'])),
            'year' => (int)($row['year'] ?? $period['year']),
            'status' => $this->normalizeStatus((string)($row['status'] ?? 'unknown')),
            'finishAt' => trim((string)($row['finishAt'] ?? $row['finish_at'] ?? '')),
            'playerCount' => max(0, (int)($row['playerCount'] ?? 0)),
            'participants' => $this->names($row['participants'] ?? []),
            'podium' => [
                'gold' => $this->names($podium['gold'] ?? []),
                'silver' => $this->names($podium['silver'] ?? []),
                'bronze' => $this->names($podium['bronze'] ?? []),
            ],
            'fairPlayRemovals' => $this->names($row['fairPlayRemovals'] ?? []),
            'updatedAt' => trim((string)($row['updatedAt'] ?? \p2k_tournament_now())),
        ];
    }

    private function tournamentFinishAt(array $root, array $existing = []): string
    {
        $raw = $root['finish_time'] ?? $root['finishAt'] ?? $root['finish_at'] ?? null;
        if (is_numeric($raw) && (int)$raw > 0) return gmdate('c', (int)$raw);
        if (is_string($raw) && trim($raw) !== '') return trim($raw);
        return trim((string)($existing['finishAt'] ?? $existing['finish_at'] ?? ''));
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(str_replace([' ', '-'], '_', trim($status)));
        if (str_contains($status, 'finish') || str_contains($status, 'complete')) return 'finished';
        if (str_contains($status, 'progress') || str_contains($status, 'active')) return 'in_progress';
        if (str_contains($status, 'register')) return 'registration';
        return $status ?: 'unknown';
    }

    private function type(string $slug): string
    {
        $slug = strtolower($slug);
        if (str_contains($slug, 'christmas')) return 'christmas';
        if (preg_match('/top[- ]?32|32[- ]?players/', $slug)) return 'top32';
        if (preg_match('/top[- ]?10|10[- ]?players/', $slug)) return 'top10';
        if (preg_match('/quarter|q[1-4]/', $slug)) return 'quarterly';
        return 'other';
    }

    private function periodInfo(string $slug, array $root): array
    {
        $year = 0;
        $month = 0;
        $period = '';
        $months = ['january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12];
        $text = strtolower($slug . ' ' . ($root['name'] ?? ''));
        if (preg_match('/\b(20\d{2})\b/', $text, $match)) $year = (int)$match[1];
        foreach ($months as $name => $number) if (str_contains($text, $name)) { $month = $number; break; }
        if (!$year) {
            $stamp = (int)($root['start_time'] ?? $root['finish_time'] ?? 0);
            if ($stamp > 0) { $year = (int)gmdate('Y', $stamp); $month = (int)gmdate('n', $stamp); }
        }
        if (!$year) $year = (int)gmdate('Y');
        if (preg_match('/quarter(?:ly)?[- ]?([1-4])|\bq([1-4])\b/', $text, $quarterMatch)) {
            $quarter = (int)($quarterMatch[1] ?: $quarterMatch[2]);
            $month = ($quarter - 1) * 3 + 1;
            $period = 'Q' . $quarter . ' ' . $year;
        } elseif ($month) {
            $period = date('F', gmmktime(0, 0, 0, $month, 1, $year)) . ' ' . $year;
        } else {
            $period = (string)$year;
        }
        return ['year' => $year, 'period' => $period, 'periodSort' => sprintf('%04d-%02d', $year, max(1, $month))];
    }

    private function withLock(callable $callback): array
    {
        $lockPath = \p2k_tournament_lock_path();
        $dir = dirname($lockPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException('Unable to create tournament lock directory.');
        $lock = fopen($lockPath, 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('A tournament refresh is already running.');
        try { return $callback(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function save(array $archive): void
    {
        $path = \p2k_tournament_archive_path();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException('Unable to create tournament storage.');
        // Keep a rolling last-known-good copy so an accidental deployment overwrite
        // can be recovered without reconstructing the complete tournament archive.
        if (is_file($path)) {
            $current = json_decode((string)@file_get_contents($path), true);
            if (is_array($current) && !empty($current['tournaments'])) @copy($path, $path . '.bak');
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to save tournament archive.');
        }
    }

    private function emptyArchive(): array
    {
        return [
            'schemaVersion' => 2,
            'generatedAt' => null,
            'scanState' => $this->defaultScanState(),
            'tournaments' => [],
            'excludedMedalists' => [],
            'lastManualCheck' => null,
            'lastStatusRefresh' => null,
        ];
    }

    private function indexed(array $tournaments): array
    {
        $known = [];
        foreach ($tournaments as $row) if (is_array($row) && !empty($row['slug'])) $known[(string)$row['slug']] = $this->sanitizeTournament($row);
        return $known;
    }

    private function sorted(array $tournaments): array
    {
        usort($tournaments, static fn(array $a, array $b): int => strcmp((string)($b['periodSort'] ?? ''), (string)($a['periodSort'] ?? '')) ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
        return $tournaments;
    }

    private function slug(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? $url);
        return rawurldecode((string)basename(rtrim($path, '/')));
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function title(string $slug): string { return ucwords(str_replace(['-', '_'], ' ', $slug)); }

    private function names(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $name = trim(is_array($value) ? (string)($value['username'] ?? '') : (string)$value);
            if ($name !== '' && !isset($out[strtolower($name)])) $out[strtolower($name)] = $name;
        }
        return array_values($out);
    }
}
