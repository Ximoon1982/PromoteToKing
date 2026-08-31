<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\Auth;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\FairPlayPriorityRunner;
use P2K\TeamPoints\Repository;

// v2.10.9.7: Fair Play current-match correctness gets a small guaranteed slice
// before the long Green Club worker. Failures remain isolated so acquisition can
// still proceed and report its own status through the canonical cron endpoint.
try {
    $config = p2k_tp_config();
    Auth::requireCron(trim((string)($_SERVER['HTTP_X_P2K_CRON_TOKEN'] ?? ($_GET['token'] ?? ''))));
    $core = Database::core();
    $repo = new Repository($core);
    if ($repo->schemaInstalled()) {
        $club = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
        (new FairPlayPriorityRunner($core, $repo, new ChessApi($repo), $club))->run(microtime(true) + 5.0, 2);
    }
} catch (Throwable $e) {
    error_log('P2K Fair Play priority slice: ' . $e->getMessage());
}

define('P2K_TP_CRON_LANE','club');
define('P2K_TP_TASK_KEY','team-points-club');
define('P2K_TP_CRON_STATE_KEY','team-points-club-continuous');
require __DIR__ . '/cron.php';
