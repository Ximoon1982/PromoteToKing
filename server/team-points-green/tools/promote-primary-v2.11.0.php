<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__,2).'/team-points/src/bootstrap.php';

use P2K\Green\GreenConfig;
use P2K\Green\GreenRepository;
use P2K\Green\GreenComparison;

/**
 * One-time/idempotent v2.11.0 production promotion.
 *
 * Green is normalized to the only production route. Blue databases are preserved,
 * but their Team Points task flags are paused so they remain a static recovery copy.
 */
$repo = GreenRepository::open();
$stateBefore = $repo->state();

$coreVersion = (int)$repo->core->query('SELECT COALESCE(MAX(version),0) FROM p2k_core_schema_version')->fetchColumn();
$analyticsVersion = (int)$repo->analytics->query('SELECT COALESCE(MAX(version),0) FROM p2k_analytics_schema_version')->fetchColumn();
if ($coreVersion < 17 || $analyticsVersion < 9) {
    throw new RuntimeException('Green schema prerequisites are incomplete; refusing 2.11.0 promotion.');
}

$stateAfter = $repo->setControl([
    'public_read_target'=>'green',
    'migration_phase'=>'green_primary',
    'worker_target'=>'green',
    'client_ingest_target'=>'green',
]);

$bluePause = ['attempted'=>true,'ok'=>false,'message'=>''];
try {
    (new GreenComparison($repo))->setBluePaused(true);
    $bluePause['ok'] = true;
    $bluePause['message'] = 'Blue Team Points tasks paused; Blue databases were not modified or deleted.';
} catch (Throwable $e) {
    // State has already been normalized to Green-only. This warning is reported so the
    // installer can decide whether to stop and roll back files before exposing 2.11.0.
    $bluePause['message'] = $e->getMessage();
}

$result = [
    'ok'=>$bluePause['ok'],
    'release'=>'2.11.0',
    'production_backend'=>'green',
    'blue_mode'=>'recovery_only',
    'schema'=>['core'=>$coreVersion,'analytics'=>$analyticsVersion],
    'state_before'=>[
        'public_read_target'=>$stateBefore['public_read_target']??null,
        'migration_phase'=>$stateBefore['migration_phase']??null,
        'worker_target'=>$stateBefore['worker_target']??null,
        'client_ingest_target'=>$stateBefore['client_ingest_target']??null,
    ],
    'state_after'=>[
        'public_read_target'=>$stateAfter['public_read_target']??null,
        'migration_phase'=>$stateAfter['migration_phase']??null,
        'worker_target'=>$stateAfter['worker_target']??null,
        'client_ingest_target'=>$stateAfter['client_ingest_target']??null,
    ],
    'blue_pause'=>$bluePause,
];

echo json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR), "\n";
if (!$bluePause['ok']) exit(3);
