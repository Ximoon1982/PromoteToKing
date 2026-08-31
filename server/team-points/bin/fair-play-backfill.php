<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Fair Play backfill requires PHP CLI; current SAPI is " . PHP_SAPI . PHP_EOL);
    exit(2);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\FairPlayBackfillRunner;
use P2K\TeamPoints\Repository;

try {
    $seconds = max(5, min(45, (int)($argv[1] ?? 20)));
    $maxMatches = max(1, min(50, (int)($argv[2] ?? 20)));
    @set_time_limit($seconds + 10);
    $config = p2k_tp_config();
    $club = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
    $core = Database::core();
    $repo = new Repository($core);
    if (!$repo->schemaInstalled()) $repo->upgradeExistingSchema();
    if (!$repo->schemaInstalled()) throw new RuntimeException('Team Points schema is not ready.');
    $runner = new FairPlayBackfillRunner($core, $repo, new ChessApi($repo), $club);
    $result = $runner->run($seconds, $maxMatches, 1000);
    $result['version'] = '2.10.9.7';
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Fair Play backfill failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
