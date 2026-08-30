<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\{ApiException,Http,MemberInsightsTableService,Repository,ResponseCache};

try {
    Http::method('GET');
    $config = p2k_tp_config();
    $repository = new Repository(PublicReadDatabase::core(), PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) {
        throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.', 503, 'SCHEMA_NOT_INSTALLED');
    }

    $club = (string)$config['app']['club_slug'];
    $section = strtolower(trim((string)($_GET['section'] ?? 'all')));
    $options = [
        'page' => (int)($_GET['page'] ?? 1),
        'page_size' => (int)($_GET['page_size'] ?? 25),
        'search' => (string)($_GET['search'] ?? ''),
        'filter' => (string)($_GET['filter'] ?? 'current'),
        'sort' => (string)($_GET['sort'] ?? 'points'),
        'direction' => (string)($_GET['direction'] ?? 'desc'),
        'start' => (string)($_GET['start'] ?? ''),
        'end' => (string)($_GET['end'] ?? ''),
        'evolution' => (string)($_GET['evolution'] ?? '1m'),
        'usernames' => (string)($_GET['usernames'] ?? ''),
        'activity_status' => (string)($_GET['activity_status'] ?? ''),
        'section' => $section,
    ];

    $generation = $repository->publicReadGenerationToken($club, true, true);
    $cache = new ResponseCache(is_array($config['storage'] ?? null) ? $config['storage'] : []);
    $key = 'members-insights|' . $club . '|' . $generation . '|' . hash('sha256', json_encode($options, JSON_UNESCAPED_SLASHES));

    $builder = static function () use ($repository, $club, $options, $section, $cache, $generation): array {
        $meta = ['ok' => true, 'meta' => $repository->publicReadMeta($club)];

        // The table and CSV share one canonical service. Ranking is computed before
        // search/filter/pagination and uses Points, then net wins, then username_key.
        if ($section === 'table') {
            $service = new MemberInsightsTableService($repository, $cache, $generation);
            return $meta + $service->table($club, $options, true);
        }

        $hasRange = trim((string)($options['start'] ?? '')) !== '' || trim((string)($options['end'] ?? '')) !== '';
        if ($hasRange && in_array($section, ['summary','ranks'], true)) {
            // One range-wide Core/event projection is shared by progressive sections
            // and by MemberInsightsTableService through the identical cache key.
            $base = $options;
            $base['page'] = 1;
            $base['page_size'] = 100000;
            $base['search'] = '';
            $base['filter'] = 'all';
            $base['sort'] = 'points';
            $base['direction'] = 'desc';
            $base['usernames'] = '';
            $base['activity_status'] = '';
            $base['_unpaged'] = true;
            $sharedKey = 'members-range-base|' . $club . '|' . $generation . '|' . hash('sha256', json_encode([$base['start'], $base['end']], JSON_UNESCAPED_SLASHES));
            $shared = $cache->remember($sharedKey, 180, static fn() => $repository->publicMemberInsights($club, $base), 900)['payload'];
            if ($section === 'summary') {
                return $meta + [
                    'summary' => $shared['summary'] ?? [],
                    'analytics' => ['monthly_activity' => $shared['analytics']['monthly_activity'] ?? []],
                    'range' => $shared['range'] ?? [],
                    'shared_range_cache' => true,
                ];
            }
            return $meta + [
                'analytics' => ['rank_distribution' => $shared['analytics']['rank_distribution'] ?? []],
                'range' => $shared['range'] ?? [],
                'shared_range_cache' => true,
            ];
        }

        return $meta + ($section === 'all'
            ? $repository->publicMemberInsights($club, $options)
            : $repository->publicMemberInsightsSection($club, $options, $section));
    };

    $entry = $cache->remember($key, 120, $builder, 600);
    Http::jsonCacheable($entry['payload'], 200, 60, 300, $entry['etag']);
} catch (ApiException $e) {
    Http::json(['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K Members insights: ' . $e);
    Http::json(['ok' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'Member Insights data is temporarily unavailable.']], 500);
}
