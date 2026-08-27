<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use DOMDocument;
use DOMElement;
use PDO;

/**
 * v2.10.6.25 MCA acquisition controller.
 *
 * The network workflow is intentionally split into three independently callable
 * jobs: discover -> hydrate -> rebuild. A new discovery cycle may only start
 * after the previous cycle has reached complete. Discovery walks the Chess.com
 * multi-club arena index from newest to oldest until the immutable high-water
 * arena id captured at cycle start is reached.
 *
 * CRON never backfills timestamps for historical stored MCA files. Dates seen
 * on the index are attached only to arenas discovered in the current cycle and
 * are used as a fallback when the arena page does not yield a timestamp.
 */
final class McaDiscoveryService
{
    private const INDEX_BASE = 'https://www.chess.com/club/live-tournaments/';
    private const EVENT_BASE = 'https://www.chess.com/tournament/live/arena/';
    private const REQUEST_SPACING_SECONDS = 1.0;
    private const DISCOVERY_INTERVAL_HOURS = 12;

    private readonly string $clubSlug;
    private readonly string $storageDir;
    private readonly int $maxUploadFiles;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Repository $repository,
        private readonly ChessApi $api
    ) {
        $config = \p2k_tp_config();
        $this->clubSlug = strtolower(trim((string)($config['app']['club_slug'] ?? 'promote-to-king')));
        $configured = trim((string)($config['app']['live_ranks_upload_dir'] ?? ''));
        $this->storageDir = $configured !== ''
            ? rtrim($configured, '/\\')
            : dirname(__DIR__, 3) . '/data/live-ranks/uploads';
        $storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
        $this->maxUploadFiles = max(10, (int)($storage['live_ranks_max_upload_files'] ?? 5000));
    }

    public function status(): array
    {
        $this->ensureState();
        $q = $this->pdo->prepare('SELECT * FROM p2k_lr_sync_state WHERE club_slug=? LIMIT 1');
        $q->execute([$this->clubSlug]);
        $state = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts = ['pending'=>0,'running'=>0,'completed'=>0,'error'=>0];
        $cq = $this->pdo->prepare('SELECT status,COUNT(*) total FROM p2k_lr_sync_queue WHERE club_slug=? GROUP BY status');
        $cq->execute([$this->clubSlug]);
        foreach ($cq->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $counts[(string)$row['status']] = (int)$row['total'];
        }
        $eq = $this->pdo->prepare("SELECT arena_id,arena_slug,arena_url,csv_url,stage,attempts,last_error,updated_at FROM p2k_lr_sync_queue WHERE club_slug=? AND status='error' ORDER BY updated_at DESC,arena_id DESC LIMIT 100");
        $eq->execute([$this->clubSlug]);
        $errors = array_map(static fn(array $row): array => [
            'arena_id'=>(int)$row['arena_id'],
            'arena_slug'=>(string)$row['arena_slug'],
            'arena_url'=>(string)$row['arena_url'],
            'csv_url'=>(string)$row['csv_url'],
            'stage'=>(string)$row['stage'],
            'attempts'=>(int)$row['attempts'],
            'error'=>(string)($row['last_error'] ?? ''),
            'updated_at'=>(string)($row['updated_at'] ?? ''),
        ], $eq->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $total = max((int)($state['total_events'] ?? 0), array_sum($counts));
        $done = (int)($counts['completed'] ?? 0);
        return $state + [
            'queue'=>$counts,
            'errors'=>$errors,
            'progress_percent'=>$total > 0 ? round(100 * min($total, $done) / $total, 1) : ((string)($state['status'] ?? '') === 'completed' ? 100.0 : 0.0),
            'request_spacing_ms'=>1000,
            'serial'=>true,
            'mode'=>'discover_hydrate_rebuild',
            'historical_date_backfill'=>false,
            'index_date_fallback'=>true,
        ];
    }

    /** Twice-daily trigger. Starts/resumes discovery only; never hydrates or rebuilds. */
    public function runDiscoveryCron(int $maxSeconds = 90): array
    {
        $this->ensureState();
        $state = $this->status();
        $status = (string)($state['status'] ?? 'idle');
        $phase = (string)($state['phase'] ?? 'idle');
        $due = empty($state['next_scan_at']) || (strtotime((string)$state['next_scan_at'] . ' UTC') ?: 0) <= time();

        if ($status === 'running' && $phase !== 'discover') return $state;
        if ($status === 'failed') return $state;
        if ($status !== 'running') {
            if (!$due) return $state;
            $state = $this->startCycle();
        }

        $deadline = microtime(true) + max(5, min(300, $maxSeconds));
        while (($state['status'] ?? '') === 'running' && ($state['phase'] ?? '') === 'discover' && microtime(true) < $deadline - 2.0) {
            $state = $this->discoveryStep();
            if (!empty($state['last_error'])) break;
        }
        return $state;
    }

    /** Frequent worker. Hydrates only arenas queued by the current discovery cycle. */
    public function runHydrationCron(int $maxSeconds = 90): array
    {
        $this->ensureState();
        $state = $this->status();
        if (($state['status'] ?? '') !== 'running' || ($state['phase'] ?? '') !== 'hydrate') return $state;

        // Retry each failed current-cycle source once per independent invocation.
        $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='pending',last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND status='error'")->execute([$this->clubSlug]);
        $deadline = microtime(true) + max(5, min(300, $maxSeconds));
        do {
            $state = $this->hydrateStep();
        } while (($state['status'] ?? '') === 'running' && ($state['phase'] ?? '') === 'hydrate' && ($state['queue']['pending'] ?? 0) > 0 && microtime(true) < $deadline - 2.0);
        return $state;
    }

    /** Separate materialization worker. It runs only after discovery and hydration are complete. */
    public function runRebuildCron(int $maxSeconds = 240): array
    {
        $this->ensureState();
        $state = $this->status();
        if (($state['status'] ?? '') !== 'running' || ($state['phase'] ?? '') !== 'rebuild') return $state;

        return $this->cycleLock(function () use ($maxSeconds): array {
            $state = $this->status();
            if (($state['status'] ?? '') !== 'running' || ($state['phase'] ?? '') !== 'rebuild') return $state;
            $this->pdo->prepare("UPDATE p2k_lr_sync_state SET current_stage='rebuild',updated_at=UTC_TIMESTAMP(),last_error=NULL WHERE club_slug=?")->execute([$this->clubSlug]);
            try {
                $deadline = microtime(true) + max(20, min(300, $maxSeconds));
                $service = new LiveRanksService($this->pdo, $this->repository, $this->api);
                $service->startProcessing($deadline - 1.0);
                return $this->completeCycle();
            } catch (\Throwable $e) {
                $this->pdo->prepare("UPDATE p2k_lr_sync_state SET last_error=?,current_stage='rebuild_retry',updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([
                    substr($e->getMessage(), 0, 4000), $this->clubSlug,
                ]);
                return $this->status();
            }
        });
    }

    private function startCycle(): array
    {
        return $this->cycleLock(function (): array {
            $state = $this->status();
            if (($state['status'] ?? '') === 'running') return $state;
            if (($state['status'] ?? '') === 'failed') return $state;

            $highWater = $this->maxKnownArenaId();
            $this->pdo->prepare('DELETE FROM p2k_lr_sync_queue WHERE club_slug=?')->execute([$this->clubSlug]);
            $this->pdo->prepare(
                "UPDATE p2k_lr_sync_state SET status='running',phase='discover',total_events=0,checked_events=0,csv_found=0,csv_added=0,dates_added=0,error_count=0,request_count=0,current_arena_id=NULL,current_arena_slug=NULL,current_stage='index:1',high_water_arena_id=?,last_request_at=NULL,last_scan_at=UTC_TIMESTAMP(),next_scan_at=NULL,rebuild_required=0,started_at=UTC_TIMESTAMP(),finished_at=NULL,updated_at=UTC_TIMESTAMP(),last_error=NULL WHERE club_slug=?"
            )->execute([$highWater, $this->clubSlug]);
            return $this->status();
        });
    }

    private function discoveryStep(): array
    {
        return $this->cycleLock(function (): array {
            $state = $this->status();
            if (($state['status'] ?? '') !== 'running' || ($state['phase'] ?? '') !== 'discover') return $state;
            $page = $this->pageFromStage((string)($state['current_stage'] ?? 'index:1'));
            $highWater = max(0, (int)($state['high_water_arena_id'] ?? 0));
            $url = $this->indexUrl($page);
            try {
                $response = $this->httpGet($url, 'text/html,*/*;q=0.5');
                $arenas = $this->parseIndexPage((string)$response['body']);
                if ($arenas === []) {
                    return $this->finishDiscovery();
                }

                $boundaryReached = false;
                foreach ($arenas as $arena) {
                    $arenaId = (int)$arena['arena_id'];
                    if ($highWater > 0 && $arenaId <= $highWater) {
                        $boundaryReached = true;
                        break;
                    }
                    if ($this->storedArenaExists($arenaId, (string)$arena['arena_slug'])) continue;
                    $this->queueDiscoveredArena($arena);
                }

                if ($boundaryReached) return $this->finishDiscovery();
                $this->pdo->prepare("UPDATE p2k_lr_sync_state SET current_stage=?,current_arena_id=NULL,current_arena_slug=NULL,updated_at=UTC_TIMESTAMP(),last_error=NULL WHERE club_slug=?")->execute([
                    'index:' . ($page + 1), $this->clubSlug,
                ]);
                return $this->status();
            } catch (\Throwable $e) {
                $this->pdo->prepare("UPDATE p2k_lr_sync_state SET error_count=error_count+1,last_error=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([
                    substr($e->getMessage(), 0, 4000), $this->clubSlug,
                ]);
                return $this->status();
            }
        });
    }

    private function finishDiscovery(): array
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM p2k_lr_sync_queue WHERE club_slug=?');
        $q->execute([$this->clubSlug]);
        $total = (int)$q->fetchColumn();
        if ($total === 0) return $this->completeCycle();
        $this->pdo->prepare("UPDATE p2k_lr_sync_state SET phase='hydrate',total_events=?,current_stage='hydrate',current_arena_id=NULL,current_arena_slug=NULL,updated_at=UTC_TIMESTAMP(),last_error=NULL WHERE club_slug=?")->execute([$total, $this->clubSlug]);
        return $this->status();
    }

    private function hydrateStep(): array
    {
        return $this->cycleLock(function (): array {
            $state = $this->status();
            if (($state['status'] ?? '') !== 'running' || ($state['phase'] ?? '') !== 'hydrate') return $state;
            $q = $this->pdo->prepare("SELECT * FROM p2k_lr_sync_queue WHERE club_slug=? AND status='pending' ORDER BY arena_id ASC LIMIT 1");
            $q->execute([$this->clubSlug]);
            $item = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($item)) {
                $errors = $this->pdo->prepare("SELECT COUNT(*) FROM p2k_lr_sync_queue WHERE club_slug=? AND status='error'");
                $errors->execute([$this->clubSlug]);
                if ((int)$errors->fetchColumn() > 0) return $this->status();
                $added = (int)($state['csv_added'] ?? 0);
                if ($added > 0) {
                    $this->pdo->prepare("UPDATE p2k_lr_sync_state SET phase='rebuild',current_stage='rebuild',current_arena_id=NULL,current_arena_slug=NULL,rebuild_required=1,updated_at=UTC_TIMESTAMP(),last_error=NULL WHERE club_slug=?")->execute([$this->clubSlug]);
                    return $this->status();
                }
                return $this->completeCycle();
            }

            $arenaId = (int)$item['arena_id'];
            $arenaSlug = (string)$item['arena_slug'];
            $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='running',stage='csv',attempts=attempts+1,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND arena_id=?")->execute([$this->clubSlug, $arenaId]);
            $this->pdo->prepare("UPDATE p2k_lr_sync_state SET current_arena_id=?,current_arena_slug=?,current_stage='hydrate',updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([$arenaId, $arenaSlug, $this->clubSlug]);

            try {
                if ($this->storedArenaExists($arenaId, $arenaSlug)) {
                    $this->finishHydrationItem($arenaId, false, false);
                    return $this->status();
                }

                // Index date belongs to this newly discovered queue item. Prefer a
                // timestamp recovered from its event page, otherwise retain it.
                $eventDate = trim((string)($item['event_date'] ?? '')) ?: null;
                $eventStartAt = trim((string)($item['event_start_at'] ?? '')) ?: null;
                try {
                    $eventPage = $this->httpGet((string)$item['arena_url'], 'text/html,*/*;q=0.5');
                    $fromPage = $this->extractEventDate((string)$eventPage['body']);
                    if ($fromPage['event_date'] !== null) {
                        $eventDate = $fromPage['event_date'];
                        $eventStartAt = $fromPage['event_start_at'];
                    }
                } catch (\Throwable) {
                    // CSV acquisition may still succeed and the index date remains
                    // the sanctioned fallback for this new arena.
                }

                $csv = $this->httpGet((string)$item['csv_url'], 'text/csv,text/plain;q=0.9,*/*;q=0.5');
                $this->validateResultsCsv((string)$csv['body']);
                $this->pdo->prepare('UPDATE p2k_lr_sync_state SET csv_found=csv_found+1 WHERE club_slug=?')->execute([$this->clubSlug]);
                $this->storeAutoSource($arenaId, $arenaSlug, (string)$item['arena_url'], (string)$item['csv_url'], (string)$csv['body'], $eventDate);
                $this->pdo->prepare('UPDATE p2k_lr_sync_queue SET event_start_at=?,event_date=? WHERE club_slug=? AND arena_id=?')->execute([$eventStartAt, $eventDate, $this->clubSlug, $arenaId]);
                $this->finishHydrationItem($arenaId, true, $eventDate !== null);
            } catch (\Throwable $e) {
                $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='error',stage='csv',last_error=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND arena_id=?")->execute([
                    substr($e->getMessage(), 0, 4000), $this->clubSlug, $arenaId,
                ]);
                $this->pdo->prepare("UPDATE p2k_lr_sync_state SET error_count=error_count+1,last_error=?,current_arena_id=NULL,current_arena_slug=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([
                    substr($e->getMessage(), 0, 4000), $this->clubSlug,
                ]);
            }
            return $this->status();
        });
    }

    private function finishHydrationItem(int $arenaId, bool $added, bool $dated): void
    {
        $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='completed',stage='done',needs_csv=0,needs_date=0,fetched_at=UTC_TIMESTAMP(),last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND arena_id=?")->execute([$this->clubSlug, $arenaId]);
        $this->pdo->prepare("UPDATE p2k_lr_sync_state SET checked_events=checked_events+1,csv_added=csv_added+?,dates_added=dates_added+?,current_arena_id=NULL,current_arena_slug=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([$added ? 1 : 0, $dated ? 1 : 0, $this->clubSlug]);
    }

    private function completeCycle(): array
    {
        $highWater = $this->maxKnownArenaId();
        $this->pdo->prepare(
            "UPDATE p2k_lr_sync_state SET status='completed',phase='complete',current_arena_id=NULL,current_arena_slug=NULL,current_stage='complete',high_water_arena_id=?,next_scan_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL " . self::DISCOVERY_INTERVAL_HOURS . " HOUR),rebuild_required=0,finished_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),last_error=NULL WHERE club_slug=?"
        )->execute([$highWater, $this->clubSlug]);
        return $this->status();
    }

    private function queueDiscoveredArena(array $arena): void
    {
        $id = (int)$arena['arena_id'];
        $slug = (string)$arena['arena_slug'];
        $eventUrl = self::EVENT_BASE . $slug;
        $csvUrl = $eventUrl . '.csv';
        $q = $this->pdo->prepare(
            "INSERT INTO p2k_lr_sync_queue(club_slug,arena_id,arena_slug,arena_url,csv_url,stage,status,needs_csv,needs_date,event_start_at,event_date,discovered_at,updated_at) VALUES(?,?,?,?,?,'page','pending',1,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE arena_slug=VALUES(arena_slug),arena_url=VALUES(arena_url),csv_url=VALUES(csv_url),event_start_at=COALESCE(VALUES(event_start_at),event_start_at),event_date=COALESCE(VALUES(event_date),event_date),updated_at=UTC_TIMESTAMP()"
        );
        $q->execute([
            $this->clubSlug, $id, $slug, $eventUrl, $csvUrl,
            empty($arena['event_date']) ? 1 : 0,
            $arena['event_start_at'] ?? null,
            $arena['event_date'] ?? null,
        ]);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM p2k_lr_sync_queue WHERE club_slug=?');
        $count->execute([$this->clubSlug]);
        $this->pdo->prepare('UPDATE p2k_lr_sync_state SET total_events=? WHERE club_slug=?')->execute([(int)$count->fetchColumn(), $this->clubSlug]);
    }

    private function parseIndexPage(string $html): array
    {
        $decoded = html_entity_decode(str_replace('\\/', '/', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $items = [];
        if (class_exists(DOMDocument::class)) {
            $previous = libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            @$dom->loadHTML($decoded, LIBXML_NOWARNING | LIBXML_NOERROR);
            foreach ($dom->getElementsByTagName('a') as $anchor) {
                if (!$anchor instanceof DOMElement) continue;
                $href = html_entity_decode(trim($anchor->getAttribute('href')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $identity = $this->arenaIdentityFromHref($href);
                if ($identity === null) continue;
                $date = ['event_date'=>null,'event_start_at'=>null];
                $node = $anchor;
                for ($depth = 0; $depth < 6 && $node !== null; $depth++, $node = $node->parentNode) {
                    $date = $this->extractDateFromText((string)$node->textContent);
                    if ($date['event_date'] !== null) break;
                }
                $items[(int)$identity['arena_id']] = $identity + $date;
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($items === [] && preg_match_all('~href=["\']([^"\']*/tournament/live/arena/([^"\'?#]+)-(\d+)[^"\']*)["\']~i', $decoded, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $i => $hit) {
                $id = (int)$matches[3][$i][0];
                $slug = $matches[2][$i][0] . '-' . $matches[3][$i][0];
                $offset = (int)$hit[1];
                $near = substr($decoded, max(0, $offset - 1200), 2400);
                $items[$id] = ['arena_id'=>$id,'arena_slug'=>$slug] + $this->extractDateFromText(strip_tags($near));
            }
        }
        krsort($items, SORT_NUMERIC);
        return array_values($items);
    }

    private function arenaIdentityFromHref(string $href): ?array
    {
        if (!preg_match('~(?:^|https?://(?:www\.)?chess\.com)?/tournament/live/arena/([^/?#]+)-(\d+)(?:[/?#]|$)~i', $href, $m)) return null;
        $id = (int)$m[2];
        if ($id <= 0) return null;
        return ['arena_id'=>$id,'arena_slug'=>$m[1] . '-' . $m[2]];
    }

    private function extractDateFromText(string $text): array
    {
        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if (!preg_match('~\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},\s+20\d{2}(?:,?\s+\d{1,2}:\d{2}\s*(?:AM|PM))?~i', $plain, $m)) {
            return ['event_date'=>null,'event_start_at'=>null];
        }
        $stamp = strtotime($m[0] . ' UTC');
        if ($stamp === false || !$this->plausibleEventStamp($stamp)) return ['event_date'=>null,'event_start_at'=>null];
        return ['event_date'=>gmdate('Y-m-d', $stamp),'event_start_at'=>gmdate('Y-m-d H:i:s', $stamp)];
    }

    private function extractEventDate(string $html): array
    {
        $text = html_entity_decode(str_replace('\\/', '/', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $visible = $this->extractDateFromText(strip_tags($text));
        if ($visible['event_date'] !== null) return $visible;
        $candidates = [];
        if (preg_match_all('~"(?:startTime|start_time|startDate|start_date)"\s*:\s*"([^"]+)"~i', $text, $m)) $candidates = array_merge($candidates, $m[1]);
        if (preg_match_all('~"(?:startTime|start_time)"\s*:\s*(\d{10,13})~i', $text, $m)) $candidates = array_merge($candidates, $m[1]);
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') continue;
            if (ctype_digit($candidate)) {
                $stamp = (int)$candidate;
                if ($stamp > 20000000000) $stamp = (int)floor($stamp / 1000);
            } else {
                $parsed = strtotime($candidate);
                if ($parsed === false) continue;
                $stamp = (int)$parsed;
            }
            if ($this->plausibleEventStamp($stamp)) return ['event_date'=>gmdate('Y-m-d',$stamp),'event_start_at'=>gmdate('Y-m-d H:i:s',$stamp)];
        }
        return ['event_date'=>null,'event_start_at'=>null];
    }

    private function plausibleEventStamp(int $stamp): bool
    {
        return $stamp >= (strtotime('2015-01-01 UTC') ?: 0) && $stamp <= time() + 86400;
    }

    private function validateResultsCsv(string $body): void
    {
        if ($body === '' || strlen($body) > 20 * 1024 * 1024) throw new \RuntimeException('MCA Results CSV is empty or exceeds the 20 MB safety limit.');
        $first = strtok(preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body, "\r\n");
        if ($first === false || trim($first) === '') throw new \RuntimeException('MCA Results CSV has no header.');
        $counts = [','=>substr_count($first,','),';'=>substr_count($first,';'),"\t"=>substr_count($first,"\t")];
        arsort($counts); $delimiter = (string)array_key_first($counts);
        $header = array_map(static fn($v): string => strtolower(trim((string)$v)), str_getcsv($first, $delimiter));
        foreach (['username','club','score'] as $required) {
            if (!in_array($required, $header, true)) throw new \RuntimeException('MCA Results CSV is missing required column ' . $required . '.');
        }
    }

    private function storeAutoSource(int $arenaId, string $arenaSlug, string $eventUrl, string $csvUrl, string $body, ?string $eventDate): void
    {
        $this->ensureStorage();
        if ($this->storedArenaExists($arenaId, $arenaSlug)) return;
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM p2k_lr_files WHERE club_slug=?');
        $count->execute([$this->clubSlug]);
        if ((int)$count->fetchColumn() >= $this->maxUploadFiles) throw new \RuntimeException('The configured MCA source-file limit has been reached.');
        $storedName = bin2hex(random_bytes(12)) . '.csv';
        $path = $this->storageDir . '/' . $storedName;
        if (file_put_contents($path, $body, LOCK_EX) === false) throw new \RuntimeException('Unable to store automatically fetched MCA Results CSV.');
        @chmod($path, 0660);
        $hash = hash('sha256', $body);
        $size = strlen($body);
        $effectiveDate = $eventDate ?: gmdate('Y-m-d');
        $precision = $eventDate !== null ? 'known' : 'upload-fallback';
        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO p2k_lr_files(club_slug,original_name,stored_name,sha256,size_bytes,uploaded_at,arena_id,arena_slug,event_url,csv_url,source_origin,source_fetched_at,actual_event_date,effective_event_date,event_date_precision,event_date_updated_at,status,row_count,p2k_row_count) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),?,?,?,?,'auto',UTC_TIMESTAMP(),?,?,?,UTC_TIMESTAMP(),'uploaded',0,0)"
            );
            $insert->execute([$this->clubSlug,$arenaSlug.'.csv',$storedName,$hash,$size,$arenaId,$arenaSlug,$eventUrl,$csvUrl,$eventDate,$effectiveDate,$precision]);
            $this->invalidateProcessingAfterSourceChange();
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
    }

    private function invalidateProcessingAfterSourceChange(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM p2k_lr_players WHERE club_slug=?')->execute([$this->clubSlug]);
            $this->pdo->prepare('DELETE FROM p2k_lr_arena_stats WHERE club_slug=?')->execute([$this->clubSlug]);
            $this->pdo->prepare(
                "INSERT INTO p2k_lr_processing_state(club_slug,status,phase,total_files,processed_files,total_players,checked_players,possible_renamed,closed_accounts,started_at,updated_at,finished_at,last_error) VALUES(?,'idle','files_changed',0,0,0,0,0,0,NULL,UTC_TIMESTAMP(),NULL,NULL) ON DUPLICATE KEY UPDATE status='idle',phase='files_changed',total_files=0,processed_files=0,total_players=0,checked_players=0,possible_renamed=0,closed_accounts=0,started_at=NULL,updated_at=UTC_TIMESTAMP(),finished_at=NULL,last_error=NULL"
            )->execute([$this->clubSlug]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function storedArenaExists(int $arenaId, string $arenaSlug): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM p2k_lr_files WHERE club_slug=? AND (arena_id=? OR original_name=?) LIMIT 1');
        $q->execute([$this->clubSlug, $arenaId, $arenaSlug . '.csv']);
        return (bool)$q->fetchColumn();
    }

    private function maxKnownArenaId(): int
    {
        $q = $this->pdo->prepare('SELECT arena_id,original_name FROM p2k_lr_files WHERE club_slug=?');
        $q->execute([$this->clubSlug]);
        $max = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = is_numeric($row['arena_id'] ?? null) ? (int)$row['arena_id'] : 0;
            if ($id <= 0 && preg_match('/-(\d+)\.csv$/i', (string)($row['original_name'] ?? ''), $m)) $id = (int)$m[1];
            $max = max($max, $id);
        }
        return $max;
    }

    private function pageFromStage(string $stage): int
    {
        return preg_match('/^index:(\d+)$/', $stage, $m) ? max(1, (int)$m[1]) : 1;
    }

    private function indexUrl(int $page): string
    {
        return self::INDEX_BASE . rawurlencode($this->clubSlug) . '?type=multi&page=' . max(1, $page);
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0770, true) && !is_dir($this->storageDir)) throw new \RuntimeException('Unable to create the MCA source directory.');
        $deny = dirname($this->storageDir) . '/.htaccess';
        if (!is_file($deny)) @file_put_contents($deny, "Require all denied\nDeny from all\n");
    }

    private function ensureState(): void
    {
        $this->pdo->prepare("INSERT IGNORE INTO p2k_lr_sync_state(club_slug,status,phase,updated_at) VALUES(?,'idle','idle',UTC_TIMESTAMP())")->execute([$this->clubSlug]);
    }

    private function cycleLock(callable $callback): mixed
    {
        $key = 'p2k:mca-sync:' . substr($this->clubSlug, 0, 40);
        $q = $this->pdo->prepare('SELECT GET_LOCK(?,0)');
        $q->execute([$key]);
        if ((int)$q->fetchColumn() !== 1) throw new ApiException('Another MCA acquisition step is already active.', 409, 'MCA_SYNC_BUSY');
        try { return $callback(); }
        finally {
            try { $r = $this->pdo->prepare('SELECT RELEASE_LOCK(?)'); $r->execute([$key]); } catch (\Throwable) {}
        }
    }

    private function waitForRequestSlot(): void
    {
        $this->ensureState();
        $q = $this->pdo->prepare('SELECT last_request_at FROM p2k_lr_sync_state WHERE club_slug=? LIMIT 1');
        $q->execute([$this->clubSlug]);
        $last = (string)($q->fetchColumn() ?: '');
        if ($last !== '') {
            $stamp = (float)(strtotime(substr($last, 0, 19) . ' UTC') ?: 0);
            $micro = 0.0;
            if (preg_match('/\.(\d+)/', $last, $m)) $micro = (float)('0.' . substr($m[1], 0, 6));
            $elapsed = microtime(true) - ($stamp + $micro);
            if ($elapsed < self::REQUEST_SPACING_SECONDS) usleep((int)ceil((self::REQUEST_SPACING_SECONDS - $elapsed) * 1000000));
        }
        $this->pdo->prepare('UPDATE p2k_lr_sync_state SET last_request_at=UTC_TIMESTAMP(6),request_count=request_count+1,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
    }

    private function httpGet(string $url, string $accept): array
    {
        $this->waitForRequestSlot();
        $headers = ['Accept: ' . $accept, 'Accept-Language: en-US,en;q=0.8'];
        $body = ''; $status = 0; $contentType = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) throw new \RuntimeException('Unable to initialize MCA HTTP client.');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,
                CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>30,
                CURLOPT_USERAGENT=>'PromoteToKing/2.10.6.25 MCA Discovery (+https://www.promotetoking.org)',
                CURLOPT_HTTPHEADER=>$headers,CURLOPT_HEADER=>false,
            ]);
            $raw = curl_exec($ch);
            if ($raw === false) { $error = curl_error($ch); curl_close($ch); throw new \RuntimeException('MCA fetch failed: ' . $error); }
            $body = (string)$raw;
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
        } else {
            $context = stream_context_create(['http'=>['method'=>'GET','timeout'=>30,'ignore_errors'=>true,'header'=>"User-Agent: PromoteToKing/2.10.6.25 MCA Discovery\r\nAccept: {$accept}\r\nAccept-Language: en-US,en;q=0.8\r\n"]]);
            $raw = @file_get_contents($url, false, $context);
            $body = $raw === false ? '' : (string)$raw;
            foreach ($http_response_header ?? [] as $header) {
                if (preg_match('~^HTTP/\S+\s+(\d+)~i', $header, $m)) $status = (int)$m[1];
                elseif (stripos($header, 'Content-Type:') === 0) $contentType = trim(substr($header, 13));
            }
        }
        if ($status < 200 || $status >= 300) throw new \RuntimeException('MCA fetch returned HTTP ' . $status . ' for ' . $url);
        return ['status'=>$status,'body'=>$body,'content_type'=>$contentType,'url'=>$url];
    }
}
