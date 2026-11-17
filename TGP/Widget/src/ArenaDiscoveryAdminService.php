<?php
declare(strict_types=1);

namespace P2K\EventsShowcase;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;

/** Admin-only on-demand arena suggestions. Does not participate in public Showcase reads. */
final class ArenaDiscoveryAdminService {
    private const SOURCE = 'https://www.chess.com/tournament/upcoming/arena';
    private const SOURCE_TZ = 'America/Los_Angeles'; // Validated against Chess.com CET display in November 2026.
    private const LOCAL_TZ = 'Europe/Luxembourg';
    public const MAX_PAGES = 20;

    public function __construct(private readonly string $root) {}

    private function dataPath(): string {
        $path = $this->root . '/data/events-showcase-admin-discovery.json';
        return \P2K\TeamPoints\PreviewIsolation::active()
            ? \P2K\TeamPoints\PreviewIsolation::mapWritablePath($path, $this->root) : $path;
    }

    private function withLock(callable $callback): mixed {
        $path = $this->dataPath() . '.lock';
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Unable to prepare arena discovery state.');
        }
        $handle = fopen($path, 'c+');
        if (!$handle) throw new RuntimeException('Unable to lock arena discovery.');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Arena discovery lock failed.');
            return $callback();
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function readUnlocked(): array {
        $data = \read_json_file($this->dataPath(), []);
        return [
            'revision' => max(0, (int) ($data['revision'] ?? 0)),
            'organizers' => is_array($data['organizers'] ?? null) ? $data['organizers'] : [],
            'scan' => is_array($data['scan'] ?? null) ? $data['scan'] : null,
        ];
    }

    private function writeUnlocked(array $state): void {
        // atomic_json provides PreviewIsolation-aware, atomic persistence.
        \atomic_json($this->root . '/data/events-showcase-admin-discovery.json', $state, true);
    }

    public function read(): array { return $this->withLock(fn(): array => $this->readUnlocked()); }

    public function saveOrganizers(array $organizers, int $expectedRevision): array {
        $normalized = []; $seen = [];
        if (count($organizers) > 100) throw new InvalidArgumentException('No more than 100 monitored clubs.');
        foreach ($organizers as $raw) {
            if (!is_array($raw)) throw new InvalidArgumentException('Invalid organizer entry.');
            $id = trim((string) ($raw['clubId'] ?? ''));
            $name = trim((string) ($raw['name'] ?? ''));
            if (!preg_match('/^[1-9][0-9]{0,14}$/D', $id)) throw new InvalidArgumentException('Club ID must be a positive numeric ID.');
            if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 80) throw new InvalidArgumentException('Organizer name must be 1–80 characters.');
            if (isset($seen[$id])) throw new InvalidArgumentException('Duplicate organizer club ID.');
            $seen[$id] = true;
            $normalized[] = ['clubId' => $id, 'name' => $name, 'enabled' => ($raw['enabled'] ?? true) !== false];
        }
        return $this->withLock(function () use ($normalized, $expectedRevision): array {
            $state = $this->readUnlocked();
            if ($state['revision'] !== $expectedRevision) throw new RevisionConflict($state);
            if ($state['organizers'] !== $normalized) {
                $state['organizers'] = $normalized;
                $state['scan'] = null; // Stale results must not survive a watchlist change.
                $state['revision']++;
                $this->writeUnlocked($state);
            }
            return $state;
        });
    }

    public function beginScan(): array {
        return $this->withLock(function (): array {
            $state = $this->readUnlocked();
            $enabled = array_values(array_filter($state['organizers'], static fn(array $c): bool => ($c['enabled'] ?? false) === true));
            if ($enabled === []) throw new InvalidArgumentException('Add and enable at least one organizing club before scanning.');
            $now = time();
            $state['scan'] = [
                'id' => bin2hex(random_bytes(12)), 'status' => 'running', 'startedAt' => gmdate(DATE_ATOM, $now),
                'cutoffEpoch' => $now + 7 * 86400, 'pages' => 0, 'seen' => 0,
                'results' => [], 'lastSourceTime' => null, 'message' => '',
            ];
            $this->writeUnlocked($state);
            return $state;
        });
    }

    public function cancelScan(string $id): array {
        return $this->withLock(function () use ($id): array {
            $state = $this->readUnlocked();
            if (($state['scan']['id'] ?? '') === $id && ($state['scan']['status'] ?? '') === 'running') {
                $state['scan']['status'] = 'cancelled';
                $this->writeUnlocked($state);
            }
            return $state;
        });
    }

    /** Parse the stable HTML table; never interpret an absent timezone as the PHP server timezone. */
    public static function parseIndexHtml(string $html): array {
        if (!class_exists(DOMDocument::class)) throw new RuntimeException('DOM extension is required to parse the arena index.');
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!@$doc->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new RuntimeException('Cannot parse the arena index HTML.');
            }
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xp = new DOMXPath($doc);
        $table = $xp->query("//table[contains(concat(' ',normalize-space(@class),' '),' table-component ')]")?->item(0);
        if (!$table) throw new RuntimeException('Arena index table not found; Chess.com format may have changed.');
        $root = $xp->query("//*[@id='tournament-root-index']")?->item(0);
        $pager = $xp->query("//*[@id='pagination-top']")?->item(0);
        $total = (int)($root?->attributes?->getNamedItem('data-total-pages')?->nodeValue ?? 0);
        $current = (int)($pager?->attributes?->getNamedItem('data-current-page')?->nodeValue ?? 0);
        $rows = [];
        foreach ($xp->query('.//tbody/tr', $table) ?: [] as $tr) {
            $cells = $xp->query('./td', $tr);
            if (!$cells || $cells->length < 6) continue;
            $id = trim($cells->item(0)->textContent);
            if (!preg_match('/^[0-9]{4,15}$/D', $id)) continue;
            $name = trim(preg_replace('/\s+/u', ' ', $cells->item(1)->textContent) ?? '');
            $clubId = trim($cells->item(2)->textContent);
            if (!preg_match('/^[1-9][0-9]{0,14}$/D', $clubId)) $clubId = '';
            $spans = $xp->query(".//span[contains(concat(' ',normalize-space(@class),' '),' tournaments-upcoming-start ')]", $cells->item(5));
            if (!$spans || $spans->length < 2) throw new RuntimeException('Arena date/time structure changed.');
            $dateText = trim(preg_replace('/\s+/u', ' ', str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $spans->item(0)->textContent)) ?? '');
            $timeText = trim(preg_replace('/\s+/u', ' ', str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $spans->item(1)->textContent)) ?? '');
            $date = DateTimeImmutable::createFromFormat('!M j, Y g:i A', $dateText . ' ' . $timeText, new DateTimeZone(self::SOURCE_TZ));
            $errors = DateTimeImmutable::getLastErrors();
            if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) {
                throw new RuntimeException('Unrecognized arena date/time: ' . $dateText . ' ' . $timeText);
            }
            $local = $date->setTimezone(new DateTimeZone(self::LOCAL_TZ));
            $rows[] = [
                'arenaId' => $id, 'name' => $name, 'clubId' => $clubId,
                'url' => 'https://www.chess.com/play/arena/' . $id,
                'startEpoch' => $date->getTimestamp(), 'startUtc' => $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
                'localDate' => $local->format('Y-m-d'), 'localTime' => $local->format('H:i'),
                'rawSource' => $dateText . ' ' . $timeText,
            ];
        }
        if ($rows === [] || $current < 1 || $total < $current) throw new RuntimeException('Arena index missing rows or pagination metadata.');
        return ['rows' => $rows, 'currentPage' => $current, 'totalPages' => $total, 'sourceTimezone' => self::SOURCE_TZ];
    }

    private function fetchPage(int $page): array {
        // One page request at a time and >=1 second between fetch starts, including across admin sessions.
        $lockPath = $this->dataPath() . '.rate';
        $fh = fopen($lockPath, 'c+');
        if (!$fh) throw new RuntimeException('Unable to lock index rate limiter.');
        try {
            if (!flock($fh, LOCK_EX)) throw new RuntimeException('Cannot lock arena index rate limit.');
            $last = (float) trim(stream_get_contents($fh));
            $wait = 1.0 - (microtime(true) - $last);
            if ($wait > 0) usleep((int)ceil($wait * 1000000));
            rewind($fh); ftruncate($fh, 0); fwrite($fh, sprintf('%.6f', microtime(true))); fflush($fh);
        } finally { flock($fh, LOCK_UN); fclose($fh); }
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL required for arena discovery.');
        $url = self::SOURCE . ($page > 1 ? '?page=' . $page : '');
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 18, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; P2K-ArenaDiscovery/2.15.10; admin-triggered)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9'],
            CURLOPT_ENCODING => '', CURLOPT_MAXREDIRS => 0,
        ]);
        $html = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if (!is_string($html) || $status !== 200 || strlen($html) > 3000000) {
            throw new RuntimeException('Chess.com arena index unavailable (HTTP ' . $status . ').');
        }
        $parsed = self::parseIndexHtml($html);
        if ($parsed['currentPage'] !== $page) throw new RuntimeException('Chess.com pagination did not advance to page ' . $page . '.');
        return $parsed;
    }

    public function scanPage(string $scanId, int $page, array $knownArenaIds): array {
        if (!preg_match('/^[a-f0-9]{24}$/D', $scanId) || $page < 1 || $page > self::MAX_PAGES) {
            throw new InvalidArgumentException('Invalid scan or page.');
        }
        $state = $this->read();
        if (($state['scan']['id'] ?? '') !== $scanId || ($state['scan']['status'] ?? '') !== 'running'
            || (int)($state['scan']['pages'] ?? 0) + 1 !== $page) {
            throw new InvalidArgumentException('This scan is no longer active or the page is out of sequence.');
        }
        $parsed = $this->fetchPage($page);
        return $this->withLock(function () use ($state, $scanId, $page, $parsed, $knownArenaIds): array {
            $current = $this->readUnlocked();
            if (($current['scan']['id'] ?? '') !== $scanId || ($current['scan']['status'] ?? '') !== 'running'
                || (int)($current['scan']['pages'] ?? 0) + 1 !== $page) {
                throw new InvalidArgumentException('Arena scan changed while fetching.');
            }
            $active = [];
            foreach ($current['organizers'] as $c) if (($c['enabled'] ?? false) === true) $active[(string)$c['clubId']] = (string)$c['name'];
            $known = array_fill_keys($knownArenaIds, true);
            $seen = [];
            foreach ($current['scan']['results'] as $row) $seen[(string)$row['arenaId']] = true;
            $now = strtotime((string)$current['scan']['startedAt']);
            $until = (int)$current['scan']['cutoffEpoch'];
            $beyond = false; $added = 0;
            foreach ($parsed['rows'] as $row) {
                $when = (int)$row['startEpoch'];
                if ($when > $until) { $beyond = true; continue; }
                if ($when < $now || !isset($active[$row['clubId']]) || isset($known[$row['arenaId']]) || isset($seen[$row['arenaId']])) continue;
                $row['organizer'] = $active[$row['clubId']];
                $current['scan']['results'][] = $row;
                $seen[$row['arenaId']] = true;
                $added++;
            }
            $current['scan']['pages'] = $page;
            $current['scan']['seen'] += count($parsed['rows']);
            $current['scan']['lastSourceTime'] = end($parsed['rows'])['rawSource'] ?? null;
            $done = $beyond || $page >= $parsed['totalPages'] || $page >= self::MAX_PAGES;
            if ($done) {
                $current['scan']['status'] = 'complete';
                $current['scan']['finishedAt'] = gmdate(DATE_ATOM);
                $current['scan']['message'] = $beyond ? 'Seven-day horizon reached.' : ($page >= $parsed['totalPages'] ? 'Last available source page reached.' : 'Safety page limit reached; coverage may be partial.');
                $current['scan']['partial'] = !$beyond && $page >= self::MAX_PAGES && $page < $parsed['totalPages'];
            }
            $this->writeUnlocked($current);
            return ['ok' => true, 'scan' => $current['scan'], 'added' => $added, 'totalPages' => $parsed['totalPages']];
        });
    }
}
