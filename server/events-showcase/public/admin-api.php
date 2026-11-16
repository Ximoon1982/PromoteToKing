<?php
declare(strict_types=1);

/** Administrative discovery and ranking; the public Showcase API is unchanged. */
require_once dirname(__DIR__, 3) . '/api/_common.php';
require_once dirname(__DIR__) . '/src/EventsShowcaseStore.php';
require_once dirname(__DIR__) . '/src/ArenaDiscoveryAdminService.php';

use P2K\EventsShowcase\ArenaDiscoveryAdminService;
use P2K\EventsShowcase\EventsShowcaseStore;
use P2K\EventsShowcase\RevisionConflict;
use P2K\TeamPoints\PublicReadDatabase;

$action = trim((string)($_GET['action'] ?? ''));
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    // Admin authentication is required even for reads; unlike the separate public API.
    \P2K\TeamPoints\Auth::requireAdmin();
    $discovery = new ArenaDiscoveryAdminService(root_dir());
    $store = new EventsShowcaseStore(root_dir());

    if ($action === 'config' && $method === 'GET') {
        json_response(200, array_merge(['ok' => true], $discovery->read()));
    }
    if ($action === 'config' && $method === 'POST') {
        require_admin_write('events-showcase');
        $body = body_json(32768);
        $revision = filter_var($body['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($revision === false || $revision < 0) throw new InvalidArgumentException('Invalid configuration revision.');
        if (!is_array($body['organizers'] ?? null)) throw new InvalidArgumentException('Organizers must be an array.');
        json_response(200, array_merge(['ok' => true], $discovery->saveOrganizers($body['organizers'], $revision)));
    }
    if ($action === 'arena-scan-begin' && $method === 'POST') {
        require_admin_write('events-showcase');
        json_response(200, array_merge(['ok' => true], $discovery->beginScan()));
    }
    if ($action === 'arena-scan-page' && $method === 'POST') {
        require_admin_write('events-showcase');
        $body = body_json(2048);
        $scanId = trim((string)($body['scanId'] ?? ''));
        $page = filter_var($body['page'] ?? null, FILTER_VALIDATE_INT);
        if ($page === false) throw new InvalidArgumentException('Invalid page number.');
        $known = [];
        foreach ($store->read()['arenas'] as $arena) {
            $path = (string)parse_url((string)($arena['link'] ?? ''), PHP_URL_PATH);
            if (preg_match('~/(?:play/arena|tournament/live/arena)/([0-9]+)(?:/|$)~', $path, $m)) $known[] = $m[1];
        }
        json_response(200, $discovery->scanPage($scanId, $page, $known));
    }
    if ($action === 'arena-scan-cancel' && $method === 'POST') {
        require_admin_write('events-showcase');
        $body = body_json(1024);
        json_response(200, array_merge(['ok' => true], $discovery->cancelScan(trim((string)($body['scanId'] ?? '')))));
    }
    if ($action === 'priorities' && $method === 'GET') {
        // Reuse the Radar's bounded Green read; no external Chess.com request.
        require_once dirname(__DIR__, 2) . '/widgets/src/RecruitmentRadarGreenReadService.php';
        $config = p2k_tp_config();
        $club = strtolower(trim((string)($config['app']['club_slug'] ?? DEFAULT_CLUB_SLUG)));
        $data = \P2K\Widgets\RecruitmentRadarGreenReadService::catalog(PublicReadDatabase::core(), $club);
        json_response(200, [
            'ok' => true, 'loadedAt' => gmdate(DATE_ATOM), 'source' => 'Green synchronized Recruitment Radar',
            'details' => $data['details'] ?? [],
        ]);
    }
    if ($action === 'cleanup-started' && $method === 'POST') {
        require_admin_write('events-showcase');
        $state = $store->read();
        $items = $state['items'] ?? [];
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $item): int => (int)($item['matchId'] ?? 0), $items
        ), static fn(int $id): bool => $id > 0)));
        $expired = [];
        if ($ids !== []) {
            $pdo = PublicReadDatabase::core();
            // All statuses: a started match may already have left the registered catalog.
            foreach (array_chunk($ids, 100) as $part) {
                $q = $pdo->prepare('SELECT match_id,start_epoch FROM p2k_g_matches WHERE match_id IN ('
                    . implode(',', array_fill(0, count($part), '?')) . ')');
                $q->execute($part);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $stamp = $row['start_epoch'] ?? null;
                    if (is_numeric($stamp) && (int)$stamp > 0 && (int)$stamp <= time()) {
                        $expired[(string)$row['match_id']] = true;
                    }
                }
            }
        }
        if ($expired !== []) {
            $updated = array_values(array_filter($items,
                static fn(array $entry): bool => !isset($expired[(string)($entry['matchId'] ?? '')])));
            $state = $store->save(['items' => $updated, 'arenas' => $state['arenas']], (int)$state['revision']);
        }
        json_response(200, ['ok' => true, 'removed' => array_keys($expired),
            'revision' => $state['revision'], 'items' => $state['items'], 'arenas' => $state['arenas']]);
    }
    api_error(405, 'METHOD_NOT_ALLOWED', 'Unsupported admin action.');
} catch (RevisionConflict $error) {
    api_error(409, 'REVISION_CONFLICT', $error->getMessage(), ['current' => $error->current]);
} catch (\P2K\TeamPoints\ApiException $error) {
    api_error($error->httpStatus, $error->errorCode, $error->getMessage(), $error->details);
} catch (InvalidArgumentException $error) {
    api_error(400, 'INVALID_REQUEST', $error->getMessage());
} catch (Throwable $error) {
    api_error(500, 'SERVER_ERROR', $error->getMessage());
}
