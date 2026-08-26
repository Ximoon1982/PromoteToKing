<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\{ApiException,ChessApi,Http,LiveRanksService,Repository,ResponseCache};
try {
    Http::method('GET');
    $config = p2k_tp_config();
    $repository = new Repository(PublicReadDatabase::core(), PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.', 503, 'SCHEMA_NOT_INSTALLED');
    $club = (string)$config['app']['club_slug'];
    $section = strtolower(trim((string)($_GET['section'] ?? 'all')));
    $options = [
        'page' => (int)($_GET['page'] ?? 1),
        'page_size' => (int)($_GET['page_size'] ?? 25),
        'search' => (string)($_GET['search'] ?? ''),
        'sort' => (string)($_GET['sort'] ?? 'event_date'),
        'direction' => (string)($_GET['direction'] ?? 'desc'),
        'file_id' => (int)($_GET['file_id'] ?? 0),
    ];
    $generation = $repository->publicReadGenerationToken($club, false, true);
    $cache = new ResponseCache(is_array($config['storage'] ?? null) ? $config['storage'] : []);
    $key = 'arenas-insights-v1|' . $club . '|' . $generation . '|' . $section . '|' . hash('sha256', json_encode($options));
    $entry = $cache->remember($key, 300, static function() use ($repository, $section, $options): array {
        $service = new LiveRanksService(PublicReadDatabase::analytics(), $repository, new ChessApi($repository));
        return ['ok' => true, 'meta' => $repository->publicReadMeta((string)p2k_tp_config()['app']['club_slug'])] + $service->publicArenasInsights($section, $options);
    }, 1800);
    Http::jsonCacheable($entry['payload'], 200, 120, 600, $entry['etag']);
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]], $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K Arena insights: ' . $e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Arena Insights data is temporarily unavailable.']], 500);
}
