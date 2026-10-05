<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\{ApiException,ChessApi,Http,LiveRanksService,Repository};

try {
    Http::method('GET');
    $config = p2k_tp_config();
    $repository = new Repository(PublicReadDatabase::core(), PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) {
        throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.', 503, 'SCHEMA_NOT_INSTALLED');
    }

    $options = [
        'search' => (string)($_GET['search'] ?? ''),
        'filter' => (string)($_GET['filter'] ?? 'current'),
        'activity_status' => (string)($_GET['activity_status'] ?? ''),
        'start' => (string)($_GET['start'] ?? ''),
        'end' => (string)($_GET['end'] ?? ''),
        'sort' => (string)($_GET['sort'] ?? 'points'),
        'direction' => (string)($_GET['direction'] ?? 'desc'),
    ];

    $service = new LiveRanksService(PublicReadDatabase::analytics(), $repository, new ChessApi($repository));
    $payload = $service->publicArenasInsights('leaders', $options);
    $rows = is_array($payload['leaders'] ?? null) ? $payload['leaders'] : [];

    $start = trim((string)$options['start']) ?: 'all';
    $end = trim((string)$options['end']) ?: gmdate('Y-m-d');
    $filename = sprintf('P2K_Arena_Leaders_%s_to_%s.csv', $start, $end);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'wb');
    if ($out === false) throw new RuntimeException('Unable to open CSV output stream.');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Username','Member status','Activity status','Arenas','Arena wins','Podiums','Top 10',
        'MCA points','Game wins','Draws','Losses','Best finish','Live rank'
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string)($row['username'] ?? ''),
            !empty($row['current_member']) ? 'Current member' : 'Former member',
            (string)($row['activity_status'] ?? 'unknown'),
            (int)($row['arenas'] ?? 0),
            (int)($row['wins'] ?? 0),
            (int)($row['podiums'] ?? 0),
            (int)($row['top10s'] ?? 0),
            (float)($row['points'] ?? 0),
            $row['game_wins'] === null ? '' : (int)$row['game_wins'],
            $row['draws'] === null ? '' : (int)$row['draws'],
            $row['losses'] === null ? '' : (int)$row['losses'],
            $row['best_finish'] === null ? '' : (int)$row['best_finish'],
            (string)($row['live_rank_name'] ?? 'Unranked'),
        ]);
    }
    fclose($out);
    exit;
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]], $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K Arena leaders CSV export: ' . $e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Arena leaders CSV export is temporarily unavailable.']], 500);
}
