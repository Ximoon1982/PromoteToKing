<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

/**
 * v2.10.6.25 MCA acquisition scheduler.
 *
 * Discovery and hydration are intentionally separate jobs. Discovery owns the
 * durable newest->oldest index walk and cannot start a new cycle before the
 * current one reaches the last-known arena boundary. Hydration consumes only
 * newly discovered queue items (needs_csv=1), so CRON never backfills dates on
 * older stored arenas.
 */
final class McaResultsCronService
{
    private readonly string $clubSlug;
    private readonly string $storageDir;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Repository $repository
    ) {
        $config = \p2k_tp_config();
        $this->clubSlug = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
        $configured = trim((string)($config['app']['live_ranks_upload_dir'] ?? ''));
        $this->storageDir = $configured !== ''
            ? rtrim($configured, '/\\')
            : dirname(__DIR__, 3) . '/data/live-ranks/uploads';
    }

    public function status(): array
    {
        $this->ensureState();
        $q = $this->pdo->prepare('SELECT * FROM p2k_lr_sync_state WHERE club_slug=? LIMIT 1');
        $q->execute([$this->clubSlug]);
        $state = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        $active = ['pending' => 0, 'running' => 0, 'error' => 0];
        $cq = $this->pdo->prepare("SELECT status,COUNT(*) total FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=1 GROUP BY status");
        $cq->execute([$this->clubSlug]);
        foreach ($cq->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string)$row['status'];
            if (array_key_exists($status, $active)) $active[$status] = (int)$row['total'];
        }
        $total = max(0, (int)($state['total_events'] ?? 0));
        $unfinished = $active['pending'] + $active['running'] + $active['error'];
        $completed = max(0, $total - $unfinished);
        $queue = $active + ['completed' => $completed];
        $attempted = min($total, $completed + $active['error']);

        $eq = $this->pdo->prepare("SELECT arena_id,arena_slug,arena_url,csv_url,stage,attempts,last_error,updated_at FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=1 AND status='error' ORDER BY updated_at DESC,arena_id DESC LIMIT 100");
        $eq->execute([$this->clubSlug]);
        $errors = array_map(static fn(array $row): array => [
            'arena_id' => (int)$row['arena_id'],
            'arena_slug' => (string)$row['arena_slug'],
            'arena_url' => (string)$row['arena_url'],
            'csv_url' => (string)$row['csv_url'],
            'stage' => (string)$row['stage'],
            'attempts' => (int)$row['attempts'],
            'error' => (string)($row['last_error'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ], $eq->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $hq = $this->pdo->prepare('SELECT COUNT(*) FROM p2k_lr_files WHERE club_slug=? AND actual_event_date IS NULL');
        $hq->execute([$this->clubSlug]);
        $historicalMissingDates = (int)$hq->fetchColumn();

        $workflow = 'current';
        if (($state['status'] ?? '') === 'failed') $workflow = 'failed';
        elseif (($state['status'] ?? '') === 'running' && ($state['phase'] ?? '') === 'discovery' && !empty($state['last_error'])) $workflow = 'discovery_attention';
        elseif (($state['status'] ?? '') === 'running' && ($state['phase'] ?? '') === 'discovery') $workflow = 'discovery';
        elseif ($active['running'] > 0 || $active['pending'] > 0) $workflow = 'hydration';
        elseif ($active['error'] > 0) $workflow = 'attention';

        return $state + [
            'mode' => 'v210625_split_discovery_hydration',
            'workflow_status' => $workflow,
            'queue' => $queue,
            'hydration_queue' => $queue,
            'errors' => $errors,
            'progress_percent' => $total > 0 ? round(100 * $attempted / $total, 1) : ($workflow === 'current' ? 100.0 : 0.0),
            'historical_missing_dates' => $historicalMissingDates,
            'serial' => true,
            'request_spacing_ms' => 1000,
            'historical_date_backfill_in_cron' => false,
        ];
    }

    /** Run/resume exactly one durable discovery cycle within the time budget. */
    public function runDiscovery(int $maxSeconds = 90, bool $force = false): array
    {
        return $this->withGlobalLock(function () use ($maxSeconds, $force): array {
            $this->ensureState();
            $state = $this->stateRow();
            if (($state['status'] ?? '') === 'running' && ($state['phase'] ?? '') === 'dates') {
                // Retire the pre-.25 timestamp-only CRON cycle without consuming its
                // old missing-date queue. Those rows remain available to manual repair.
                $this->pdo->prepare("UPDATE p2k_lr_sync_state SET status='completed',phase='legacy_dates_retired',finished_at=UTC_TIMESTAMP(),next_scan_at=UTC_TIMESTAMP(),current_stage=NULL,current_arena_id=NULL,current_arena_slug=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([$this->clubSlug]);
                $state = $this->stateRow();
            }

            if (($state['status'] ?? '') === 'failed') {
                if (!$force) return $this->status();
                // An explicit Admin start is the recovery path for a legacy or
                // terminal failed state. Keep every downloaded source and queue
                // item, but reopen discovery from the durable page-1 boundary.
                $this->pdo->prepare("UPDATE p2k_lr_sync_state SET status='completed',phase='failed_recovered',next_scan_at=UTC_TIMESTAMP(),current_stage=NULL,current_arena_id=NULL,current_arena_slug=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([$this->clubSlug]);
                $state = $this->stateRow();
            }
            if ($force && ($state['status'] ?? '') !== 'running') {
                $this->pdo->prepare('UPDATE p2k_lr_sync_state SET next_scan_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
                $state = $this->stateRow();
            }
            if (($state['status'] ?? '') !== 'running') {
                $due = empty($state['next_scan_at']) || (strtotime((string)$state['next_scan_at'] . ' UTC') ?: 0) <= time();
                if (!$due) return $this->status();
                $this->beginDiscoveryCycle();
                $state = $this->stateRow();
            }
            if (($state['phase'] ?? '') !== 'discovery') return $this->status();

            $deadline = microtime(true) + max(5, min(300, $maxSeconds));
            while (microtime(true) < $deadline - 2.0) {
                $state = $this->stateRow();
                if (($state['status'] ?? '') !== 'running' || ($state['phase'] ?? '') !== 'discovery') break;
                if (!$this->discoveryStep($state)) break;
            }
            return $this->status();
        });
    }

    /** Consume only newly discovered arenas. Existing old timestamp debt is excluded. */
    public function runHydration(int $maxSeconds = 90): array
    {
        return $this->withGlobalLock(function () use ($maxSeconds): array {
            $this->ensureState();
            $this->ensureStorage();
            $deadline = microtime(true) + max(5, min(300, $maxSeconds));
            $added = 0;
            while (microtime(true) < $deadline - 3.0) {
                $item = $this->nextHydrationItem();
                if ($item === null) break;
                if ($this->hydrateItem($item)) $added++;
            }

            // Reuse the existing MIRA/statistics pipeline once after a batch, not
            // once per arena. If the time slice is short, rebuild_required remains
            // set and a later hydration invocation finishes the rebuild.
            if ($this->activeHydrationDebt() === 0 && $this->rebuildRequired() && microtime(true) < $deadline - 5.0) {
                try {
                    $service = new LiveRanksService($this->pdo, $this->repository, new ChessApi($this->repository));
                    $service->startProcessing($deadline - 1.0);
                    $this->pdo->prepare('UPDATE p2k_lr_sync_state SET rebuild_required=0,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
                } catch (\Throwable $e) {
                    $this->pdo->prepare('UPDATE p2k_lr_sync_state SET last_error=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([substr($e->getMessage(), 0, 4000), $this->clubSlug]);
                }
            }
            return ['added' => $added, 'sync' => $this->status()];
        });
    }

    /**
     * Manual/admin-only historical date repair. CRON never calls this method.
     * Event page derived from the stored CSV identity is primary; the legacy club
     * index is the fallback when the event page does not expose a usable date.
     */
    public function backfillHistoricalDates(int $maxSeconds = 120): array
    {
        return $this->withGlobalLock(function () use ($maxSeconds): array {
            $deadline = microtime(true) + max(5, min(600, $maxSeconds));
            $updated = 0; $errors = [];
            $q = $this->pdo->prepare("SELECT id,original_name,arena_id,arena_slug,event_url FROM p2k_lr_files WHERE club_slug=? AND actual_event_date IS NULL ORDER BY COALESCE(arena_id,0) DESC,id DESC");
            $q->execute([$this->clubSlug]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $file) {
                if (microtime(true) >= $deadline - 2.0) break;
                $identity = $this->fileIdentity($file);
                if ($identity === null) continue;
                try {
                    $date = $this->dateFromArenaPage($identity['arena_url']);
                    if ($date['event_date'] === null) {
                        $index = $this->findArenaOnIndex($identity['arena_id'], $deadline);
                        if ($index !== null) $date = $index;
                    }
                    if ($date['event_date'] === null) throw new \RuntimeException('No MCA date found on arena page or club index.');
                    $u = $this->pdo->prepare('UPDATE p2k_lr_files SET actual_event_date=?,event_date_updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND id=? AND actual_event_date IS NULL');
                    $u->execute([$date['event_date'], $this->clubSlug, (int)$file['id']]);
                    if ($u->rowCount() > 0) $updated++;
                } catch (\Throwable $e) {
                    $errors[] = ['arena_id' => $identity['arena_id'], 'error' => $e->getMessage()];
                }
            }
            if ($updated > 0) {
                $service = new LiveRanksService($this->pdo, $this->repository, new ChessApi($this->repository));
                $service->recomputeEventDates();
            }
            return ['updated' => $updated, 'errors' => $errors, 'manual_only' => true];
        });
    }

    private function beginDiscoveryCycle(): void
    {
        $q = $this->pdo->prepare('SELECT COALESCE(MAX(arena_id),0) FROM p2k_lr_files WHERE club_slug=?');
        $q->execute([$this->clubSlug]);
        $boundary = (int)$q->fetchColumn();
        // Reset only prior auto-discovery items. Retained needs_csv=0 rows are old
        // timestamp debt and must remain manual-only.
        $this->pdo->prepare('DELETE FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=1')->execute([$this->clubSlug]);
        $this->pdo->prepare("UPDATE p2k_lr_sync_state SET status='running',phase='discovery',total_events=0,checked_events=0,csv_found=0,csv_added=0,dates_added=0,error_count=0,request_count=0,current_arena_id=NULL,current_arena_slug=NULL,current_stage='index:1',high_water_arena_id=?,started_at=UTC_TIMESTAMP(),finished_at=NULL,last_error=NULL,last_scan_at=UTC_TIMESTAMP(),next_scan_at=NULL,rebuild_required=0,updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([$boundary, $this->clubSlug]);
    }

    /** Return true when another step can be attempted in the current invocation. */
    private function discoveryStep(array $state): bool
    {
        $stage = (string)($state['current_stage'] ?? 'index:1');
        $page = preg_match('/^index:(\d+)$/', $stage, $m) ? max(1, (int)$m[1]) : 1;
        $boundary = (int)($state['high_water_arena_id'] ?? 0);
        try {
            $payload = $this->httpGet($this->indexUrl($page));
            $parsed = McaIndexParser::parse((string)$payload['body'], $page);
            $events = $parsed['events'];
            if ($events === []) throw new \RuntimeException('Legacy MCA index page contained no arena links.');

            $boundaryReached = false;
            $inserted = 0;
            foreach ($events as $event) {
                $arenaId = (int)$event['arena_id'];
                if ($boundary > 0 && $arenaId <= $boundary) {
                    $boundaryReached = true;
                    continue;
                }
                if ($this->fileExists($arenaId, (string)$event['arena_slug'])) continue;
                $ins = $this->pdo->prepare("INSERT INTO p2k_lr_sync_queue(club_slug,arena_id,arena_slug,arena_url,csv_url,stage,status,needs_csv,needs_date,event_start_at,event_date,discovered_at,updated_at) VALUES(?,?,?,?,?,'page','pending',1,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE arena_slug=VALUES(arena_slug),arena_url=VALUES(arena_url),csv_url=VALUES(csv_url),event_start_at=COALESCE(VALUES(event_start_at),event_start_at),event_date=COALESCE(VALUES(event_date),event_date),needs_csv=1,needs_date=IF(COALESCE(VALUES(event_date),event_date) IS NULL,1,0),status=IF(status='completed','completed','pending'),last_error=NULL,updated_at=UTC_TIMESTAMP()");
                $needsDate = empty($event['event_date']) ? 1 : 0;
                $ins->execute([$this->clubSlug, $arenaId, (string)$event['arena_slug'], (string)$event['arena_url'], (string)$event['csv_url'], $needsDate, $event['event_start_at'], $event['event_date']]);
                $inserted++;
            }
            $this->pdo->prepare('UPDATE p2k_lr_sync_state SET total_events=total_events+?,checked_events=checked_events+?,current_stage=?,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$inserted, count($events), 'index:' . ($page + 1), $this->clubSlug]);

            if ($boundaryReached || ($boundary === 0 && empty($parsed['has_next']))) {
                $this->completeDiscoveryCycle();
                return false;
            }
            if (empty($parsed['has_next'])) {
                throw new \RuntimeException('MCA index pagination ended before the last-known arena boundary ' . $boundary . ' was reached.');
            }
            return true;
        } catch (\Throwable $e) {
            // A source/parser failure never advances the cursor and never starts a
            // replacement cycle. The next invocation resumes this exact page.
            $this->pdo->prepare("UPDATE p2k_lr_sync_state SET status='running',phase='discovery',error_count=error_count+1,last_error=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([substr($e->getMessage(), 0, 4000), $this->clubSlug]);
            return false;
        }
    }

    private function completeDiscoveryCycle(): void
    {
        $max = $this->pdo->prepare('SELECT GREATEST(COALESCE((SELECT MAX(arena_id) FROM p2k_lr_files WHERE club_slug=?),0),COALESCE((SELECT MAX(arena_id) FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=1),0))');
        $max->execute([$this->clubSlug, $this->clubSlug]);
        $newHighWater = (int)$max->fetchColumn();
        $this->pdo->prepare("UPDATE p2k_lr_sync_state SET status='completed',phase='discovery_complete',high_water_arena_id=?,current_stage=NULL,current_arena_id=NULL,current_arena_slug=NULL,finished_at=UTC_TIMESTAMP(),next_scan_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 12 HOUR),last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([$newHighWater, $this->clubSlug]);
    }

    private function nextHydrationItem(): ?array
    {
        $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='pending' WHERE club_slug=? AND needs_csv=1 AND status='running'")->execute([$this->clubSlug]);
        $q = $this->pdo->prepare("SELECT * FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=1 AND status='pending' ORDER BY arena_id DESC LIMIT 1");
        $q->execute([$this->clubSlug]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** Explicitly requeue failed downloads. Errors are never retried repeatedly in the same CRON slice. */
    public function retryHydrationErrors(): array
    {
        return $this->withGlobalLock(function (): array {
            $this->ensureState();
            $q = $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='pending',stage='page',last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND needs_csv=1 AND status='error'");
            $q->execute([$this->clubSlug]);
            if ($q->rowCount() > 0) {
                $this->pdo->prepare('UPDATE p2k_lr_sync_state SET error_count=0,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
            }
            return ['requeued' => $q->rowCount(), 'sync' => $this->status()];
        });
    }

    private function hydrateItem(array $item): bool
    {
        $id = (int)$item['arena_id'];
        $slug = (string)$item['arena_slug'];
        $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='running',stage='page',attempts=attempts+1,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND arena_id=?")->execute([$this->clubSlug, $id]);
        try {
            $existing = $this->existingFile($id, $slug);
            if ($existing !== null) {
                // Never replace an already stored source. Only apply the discovery
                // timestamp when this is a newly discovered queue item and the file
                // itself still lacks a date.
                if (empty($existing['actual_event_date']) && !empty($item['event_date'])) {
                    $this->pdo->prepare('UPDATE p2k_lr_files SET actual_event_date=?,event_date_updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND id=? AND actual_event_date IS NULL')->execute([$item['event_date'], $this->clubSlug, (int)$existing['id']]);
                }
                $this->markHydrated($id, $item['event_start_at'] ?? null, $item['event_date'] ?? null);
                return false;
            }

            // Arena page is primary date evidence. The date captured on the index
            // is retained as the fallback required by v2.10.6.25.
            $date = $this->dateFromArenaPage((string)$item['arena_url']);
            if ($date['event_date'] === null && !empty($item['event_date'])) {
                $date = ['event_start_at' => $item['event_start_at'] ?: null, 'event_date' => $item['event_date'], 'date_precision' => 'index-fallback'];
            }

            $csv = $this->httpGet((string)$item['csv_url']);
            $body = (string)$csv['body'];
            $this->validateResultsCsv($body);
            $this->storeAutomaticCsv($item, $body, $date);
            $this->markHydrated($id, $date['event_start_at'], $date['event_date']);
            $this->pdo->prepare('UPDATE p2k_lr_sync_state SET csv_found=csv_found+1,csv_added=csv_added+1,dates_added=dates_added+?,rebuild_required=1,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$date['event_date'] !== null ? 1 : 0, $this->clubSlug]);
            return true;
        } catch (\Throwable $e) {
            $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='error',last_error=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND arena_id=?")->execute([substr($e->getMessage(), 0, 4000), $this->clubSlug, $id]);
            $this->pdo->prepare('UPDATE p2k_lr_sync_state SET error_count=error_count+1,last_error=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([substr($e->getMessage(), 0, 4000), $this->clubSlug]);
            return false;
        }
    }

    private function storeAutomaticCsv(array $item, string $body, array $date): void
    {
        $stored = bin2hex(random_bytes(12)) . '.csv';
        $path = $this->storageDir . '/' . $stored;
        if (file_put_contents($path, $body, LOCK_EX) === false) throw new \RuntimeException('Unable to store discovered MCA Results CSV.');
        @chmod($path, 0660);
        $hash = hash('sha256', $body); $size = strlen($body);
        try {
            $ins = $this->pdo->prepare("INSERT INTO p2k_lr_files(club_slug,original_name,stored_name,sha256,size_bytes,uploaded_at,arena_id,arena_slug,event_url,csv_url,source_origin,source_fetched_at,actual_event_date,effective_event_date,event_date_precision,event_date_updated_at,status,row_count,p2k_row_count) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),?,?,?,?, 'auto',UTC_TIMESTAMP(),?,?,?,UTC_TIMESTAMP(),'uploaded',0,0)");
            $precision = $date['event_date'] !== null ? 'known' : 'upload-fallback';
            $ins->execute([$this->clubSlug, (string)$item['arena_slug'] . '.csv', $stored, $hash, $size, (int)$item['arena_id'], (string)$item['arena_slug'], (string)$item['arena_url'], (string)$item['csv_url'], $date['event_date'], $date['event_date'], $precision]);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
        $this->invalidateDerivedMca();
    }

    private function invalidateDerivedMca(): void
    {
        $this->pdo->prepare('DELETE FROM p2k_lr_players WHERE club_slug=?')->execute([$this->clubSlug]);
        $this->pdo->prepare('DELETE FROM p2k_lr_arena_stats WHERE club_slug=?')->execute([$this->clubSlug]);
        $this->pdo->prepare("INSERT INTO p2k_lr_processing_state(club_slug,status,phase,total_files,processed_files,total_players,checked_players,possible_renamed,closed_accounts,started_at,updated_at,finished_at,last_error) VALUES(?,'idle','files_changed',0,0,0,0,0,0,NULL,UTC_TIMESTAMP(),NULL,NULL) ON DUPLICATE KEY UPDATE status='idle',phase='files_changed',total_files=0,processed_files=0,total_players=0,checked_players=0,possible_renamed=0,closed_accounts=0,started_at=NULL,updated_at=UTC_TIMESTAMP(),finished_at=NULL,last_error=NULL")->execute([$this->clubSlug]);
    }

    private function markHydrated(int $arenaId, mixed $start, mixed $date): void
    {
        $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='completed',stage='done',needs_csv=0,needs_date=0,event_start_at=COALESCE(?,event_start_at),event_date=COALESCE(?,event_date),fetched_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND arena_id=?")->execute([$start, $date, $this->clubSlug, $arenaId]);
    }

    private function validateResultsCsv(string $body): void
    {
        if ($body === '' || strlen($body) < 20) throw new \RuntimeException('Discovered MCA Results CSV is empty.');
        $first = preg_replace('/^\xEF\xBB\xBF/', '', strtok($body, "\r\n") ?: '') ?? '';
        $delimiter = ',';
        $scores = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($scores); if ((int)reset($scores) > 0) $delimiter = (string)key($scores);
        $headers = array_map(static fn($v) => strtolower(trim((string)$v)), str_getcsv($first, $delimiter));
        foreach (['username', 'club', 'score'] as $required) if (!in_array($required, $headers, true)) throw new \RuntimeException('Discovered MCA Results CSV is missing column ' . $required . '.');
    }

    /** @return array{event_start_at:?string,event_date:?string,date_precision:string} */
    private function dateFromArenaPage(string $url): array
    {
        try {
            $page = $this->httpGet($url);
            $date = McaIndexParser::extractDateFromText((string)$page['body']);
            if ($date['event_date'] !== null) return $date;
            // Machine fields used by the arena page when visible text is rendered client-side.
            $text = html_entity_decode((string)$page['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('~"(?:startTime|start_time|startDate|start_date)"\s*:\s*"([^"]+)"~i', $text, $m)) {
                $stamp = strtotime((string)$m[1]);
                if ($stamp !== false) return ['event_start_at' => gmdate('Y-m-d H:i:s', $stamp), 'event_date' => gmdate('Y-m-d', $stamp), 'date_precision' => 'arena-machine'];
            }
        } catch (\Throwable) {
            // Index evidence is an intentional fallback, not an error suppressing CSV acquisition.
        }
        return ['event_start_at' => null, 'event_date' => null, 'date_precision' => 'unknown'];
    }

    private function findArenaOnIndex(int $arenaId, float $deadline): ?array
    {
        for ($page = 1; $page <= 250 && microtime(true) < $deadline - 2.0; $page++) {
            $parsed = McaIndexParser::parse((string)$this->httpGet($this->indexUrl($page))['body'], $page);
            if ($parsed['events'] === []) return null;
            $oldest = PHP_INT_MAX;
            foreach ($parsed['events'] as $event) {
                $id = (int)$event['arena_id']; $oldest = min($oldest, $id);
                if ($id === $arenaId) return ['event_start_at' => $event['event_start_at'], 'event_date' => $event['event_date'], 'date_precision' => $event['date_precision']];
            }
            if ($oldest < $arenaId || empty($parsed['has_next'])) return null;
        }
        return null;
    }

    private function indexUrl(int $page): string
    {
        $base = 'https://www.chess.com/club/live-tournaments/' . rawurlencode($this->clubSlug) . '?type=multi';
        return $page <= 1 ? $base : $base . '&page=' . $page;
    }

    /** @return array{status:int,body:string,url:string} */
    private function httpGet(string $url): array
    {
        $this->waitForRequestSlot();
        $body = ''; $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url); if ($ch === false) throw new \RuntimeException('Unable to initialize MCA HTTP client.');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>30,CURLOPT_USERAGENT=>'PromoteToKing/2.10.6.25 MCA Results Sync (+https://www.promotetoking.org)',CURLOPT_HTTPHEADER=>['Accept: text/html,text/csv;q=0.9,*/*;q=0.5','Accept-Language: en-US,en;q=0.8']]);
            $raw = curl_exec($ch); if ($raw === false) { $error = curl_error($ch); curl_close($ch); throw new \RuntimeException('MCA fetch failed: ' . $error); }
            $body = (string)$raw; $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        } else {
            $context = stream_context_create(['http'=>['method'=>'GET','timeout'=>30,'ignore_errors'=>true,'header'=>"User-Agent: PromoteToKing/2.10.6.25 MCA Results Sync\r\nAccept: text/html,text/csv;q=0.9,*/*;q=0.5\r\nAccept-Language: en-US,en;q=0.8\r\n"]]);
            $raw = @file_get_contents($url, false, $context); $body = $raw === false ? '' : (string)$raw;
            foreach ($http_response_header ?? [] as $header) if (preg_match('~^HTTP/\S+\s+(\d+)~i', $header, $m)) $status = (int)$m[1];
        }
        if ($status < 200 || $status >= 300) throw new \RuntimeException('MCA fetch returned HTTP ' . $status . ' for ' . $url);
        return ['status' => $status, 'body' => $body, 'url' => $url];
    }

    private function waitForRequestSlot(): void
    {
        $this->ensureState();
        $q = $this->pdo->prepare('SELECT last_request_at FROM p2k_lr_sync_state WHERE club_slug=? LIMIT 1'); $q->execute([$this->clubSlug]);
        $last = (string)($q->fetchColumn() ?: '');
        if ($last !== '') {
            $stamp = (float)(strtotime(substr($last, 0, 19) . ' UTC') ?: 0); $micro = 0.0;
            if (preg_match('/\.(\d+)/', $last, $m)) $micro = (float)('0.' . substr($m[1], 0, 6));
            $elapsed = microtime(true) - ($stamp + $micro);
            if ($elapsed < 1.0) usleep((int)ceil((1.0 - $elapsed) * 1000000));
        }
        $this->pdo->prepare('UPDATE p2k_lr_sync_state SET last_request_at=UTC_TIMESTAMP(6),request_count=request_count+1,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
    }

    private function withGlobalLock(callable $callback): mixed
    {
        $key = 'p2k:mca-sync:' . substr($this->clubSlug, 0, 40);
        $q = $this->pdo->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$key]);
        if ((int)$q->fetchColumn() !== 1) throw new ApiException('Another MCA source synchronization job is already active.', 409, 'MCA_SYNC_BUSY');
        try { return $callback(); } finally { try { $r=$this->pdo->prepare('SELECT RELEASE_LOCK(?)'); $r->execute([$key]); } catch (\Throwable) {} }
    }

    private function ensureState(): void
    {
        $this->pdo->prepare("INSERT IGNORE INTO p2k_lr_sync_state(club_slug,status,phase,updated_at) VALUES(?,'idle','idle',UTC_TIMESTAMP())")->execute([$this->clubSlug]);
    }

    private function stateRow(): array
    {
        $q = $this->pdo->prepare('SELECT * FROM p2k_lr_sync_state WHERE club_slug=? LIMIT 1'); $q->execute([$this->clubSlug]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0770, true) && !is_dir($this->storageDir)) throw new \RuntimeException('Unable to create MCA upload directory.');
    }

    private function fileExists(int $arenaId, string $slug): bool { return $this->existingFile($arenaId, $slug) !== null; }
    private function existingFile(int $arenaId, string $slug): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM p2k_lr_files WHERE club_slug=? AND (arena_id=? OR original_name=?) LIMIT 1');
        $q->execute([$this->clubSlug, $arenaId, $slug . '.csv']); $row = $q->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
    private function activeHydrationDebt(): int { $q=$this->pdo->prepare("SELECT COUNT(*) FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=1 AND status IN ('pending','running')");$q->execute([$this->clubSlug]);return (int)$q->fetchColumn(); }
    private function rebuildRequired(): bool { $q=$this->pdo->prepare('SELECT rebuild_required FROM p2k_lr_sync_state WHERE club_slug=?');$q->execute([$this->clubSlug]);return (int)$q->fetchColumn() === 1; }

    private function fileIdentity(array $file): ?array
    {
        $slug = trim((string)($file['arena_slug'] ?? ''));
        if ($slug === '') $slug = preg_replace('/\.csv$/i', '', basename((string)$file['original_name'])) ?? '';
        $id = is_numeric($file['arena_id'] ?? null) ? (int)$file['arena_id'] : 0;
        if ($id <= 0 && preg_match('/-(\d+)$/', $slug, $m)) $id = (int)$m[1];
        if ($id <= 0 || $slug === '') return null;
        $url = trim((string)($file['event_url'] ?? '')) ?: 'https://www.chess.com/tournament/live/arena/' . $slug;
        return ['arena_id'=>$id,'arena_slug'=>$slug,'arena_url'=>$url];
    }
}
