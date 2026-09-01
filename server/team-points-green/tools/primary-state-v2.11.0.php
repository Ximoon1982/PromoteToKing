<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__,2).'/team-points/src/bootstrap.php';

use P2K\Green\GreenRepository;
use P2K\TeamPoints\Database;

$mode = strtolower(trim((string)($argv[1] ?? 'snapshot')));
$path = (string)($argv[2] ?? '');
$repo = GreenRepository::open();

$snapshot = static function () use ($repo): array {
    $state = $repo->state();
    $blue = Database::core();
    $tasks = [];
    try {
        $q = $blue->query("SELECT task_key,pause_requested FROM p2k_control_tasks WHERE task_key IN ('team-points-club','team-points-player') ORDER BY task_key");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $tasks[(string)$row['task_key']] = (int)($row['pause_requested'] ?? 0);
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Unable to snapshot Blue task-control flags: '.$e->getMessage(), 0, $e);
    }
    return [
        'release'=>'2.11.0',
        'green'=>[
            'public_read_target'=>(string)($state['public_read_target'] ?? 'blue'),
            'migration_phase'=>(string)($state['migration_phase'] ?? 'blue_primary'),
            'worker_target'=>(string)($state['worker_target'] ?? 'blue'),
            'client_ingest_target'=>(string)($state['client_ingest_target'] ?? 'blue'),
        ],
        'blue_pause_requested'=>$tasks,
    ];
};

if ($mode === 'snapshot') {
    $data = $snapshot();
    if ($path !== '') {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create snapshot directory.');
        }
        if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to write primary-state snapshot.');
        }
        @chmod($path, 0600);
    }
    echo json_encode(['ok'=>true,'mode'=>'snapshot','state'=>$data], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

if ($mode !== 'restore') {
    throw new RuntimeException('Usage: primary-state-v2.11.0.php snapshot [file] | restore <file>');
}
if ($path === '' || !is_file($path)) {
    throw new RuntimeException('A valid primary-state snapshot file is required for restore.');
}
$raw = file_get_contents($path);
if ($raw === false) throw new RuntimeException('Unable to read primary-state snapshot.');
$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($data) || !is_array($data['green'] ?? null)) throw new RuntimeException('Invalid primary-state snapshot.');

$green = $data['green'];
$repo->setControl([
    'public_read_target'=>(string)($green['public_read_target'] ?? 'blue'),
    'migration_phase'=>(string)($green['migration_phase'] ?? 'blue_primary'),
    'worker_target'=>(string)($green['worker_target'] ?? 'blue'),
    'client_ingest_target'=>(string)($green['client_ingest_target'] ?? 'blue'),
]);

$blue = Database::core();
$pause = is_array($data['blue_pause_requested'] ?? null) ? $data['blue_pause_requested'] : [];
$update = $blue->prepare('UPDATE p2k_control_tasks SET pause_requested=? WHERE task_key=?');
foreach (['team-points-club','team-points-player'] as $taskKey) {
    if (!array_key_exists($taskKey, $pause)) continue;
    $update->execute([(int)!empty($pause[$taskKey]), $taskKey]);
}

echo json_encode(['ok'=>true,'mode'=>'restore','state'=>$snapshot()], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), "\n";
