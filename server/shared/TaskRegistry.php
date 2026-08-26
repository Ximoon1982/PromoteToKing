<?php
declare(strict_types=1);

namespace P2K\Shared;

use PDO;

final class TaskRegistry
{
    private string $lastBeginReason = '';
    public const DEFINITIONS = [
        'team-points-club' => [
            'label' => 'Club Points',
            'expected_interval_seconds' => 300,
            'legacy_endpoint' => 'server/team-points/public/cron-club.php',
            'maintenance_url' => 'TaskControl.html#greenSchedulerControl',
        ],
        'team-points-player' => [
            'label' => 'Member Points',
            'expected_interval_seconds' => 600,
            'legacy_endpoint' => 'server/team-points/public/cron-player.php',
            'maintenance_url' => 'TaskControl.html#greenSchedulerControl',
        ],
        // Compatibility alias retained for old bookmarks/CRON URLs; hidden from the normal task list.
        'team-points' => [
            'label' => 'Team Points (legacy)',
            'expected_interval_seconds' => 300,
            'legacy_endpoint' => 'server/team-points/public/cron.php',
            'maintenance_url' => 'TaskControl.html#greenSchedulerControl',
        ],
        'match-tracking' => [
            'label' => 'Match monitoring',
            'expected_interval_seconds' => 3600,
            'legacy_endpoint' => 'api/track-upcoming-league-matches/',
            'maintenance_url' => 'TaskControl.html?task=match-tracking#task-detail',
        ],
        'tournaments' => [
            'label' => 'Tournaments',
            'expected_interval_seconds' => 600,
            'legacy_endpoint' => 'server/tournaments/public/cron.php',
            'maintenance_url' => 'TaskControl.html?task=tournaments#task-detail',
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
        self::ensureSchema($pdo);
        $this->ensureDefaults();
    }

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS p2k_control_tasks (
            task_key VARCHAR(80) NOT NULL PRIMARY KEY,
            label VARCHAR(160) NOT NULL,
            expected_interval_seconds INT UNSIGNED NOT NULL,
            legacy_endpoint VARCHAR(500) NULL,
            maintenance_url VARCHAR(500) NULL,
            status ENUM('idle','queued','running','paused','failed') NOT NULL DEFAULT 'idle',
            pause_requested TINYINT(1) NOT NULL DEFAULT 0,
            last_started_at DATETIME NULL,
            last_completed_at DATETIME NULL,
            last_success_at DATETIME NULL,
            next_due_at DATETIME NULL,
            last_message VARCHAR(500) NULL,
            details_json MEDIUMTEXT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_control_due (status,next_due_at),
            KEY idx_control_success (last_success_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS p2k_control_task_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            run_id VARCHAR(120) NOT NULL,
            task_key VARCHAR(80) NOT NULL,
            source ENUM('cron','manual','scheduler','legacy') NOT NULL DEFAULT 'scheduler',
            status ENUM('running','success','partial','failed','paused','skipped') NOT NULL DEFAULT 'running',
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            processed_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
            failed_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
            message VARCHAR(500) NULL,
            details_json MEDIUMTEXT NULL,
            UNIQUE KEY uq_control_run (run_id),
            KEY idx_control_runs_task (task_key,started_at),
            KEY idx_control_runs_status (status,started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS p2k_control_task_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            task_key VARCHAR(80) NOT NULL,
            run_id VARCHAR(120) NULL,
            level ENUM('debug','info','success','warning','error') NOT NULL DEFAULT 'info',
            component VARCHAR(80) NOT NULL DEFAULT 'controller',
            message VARCHAR(500) NOT NULL,
            context_json MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_control_logs_task (task_key,created_at),
            KEY idx_control_logs_level (level,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function ensureDefaults(): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO p2k_control_tasks
            (task_key,label,expected_interval_seconds,legacy_endpoint,maintenance_url,status,next_due_at,updated_at)
            VALUES (?,?,?,?,?,'idle',UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE label=VALUES(label),expected_interval_seconds=VALUES(expected_interval_seconds),legacy_endpoint=VALUES(legacy_endpoint),maintenance_url=VALUES(maintenance_url)");
        foreach (self::DEFINITIONS as $key => $definition) {
            $stmt->execute([$key, $definition['label'], $definition['expected_interval_seconds'], $definition['legacy_endpoint'], $definition['maintenance_url']]);
        }
    }

    public function list(): array
    {
        $rows = $this->pdo->query("SELECT * FROM p2k_control_tasks WHERE task_key<>'team-points' ORDER BY FIELD(task_key,'team-points-club','team-points-player','match-tracking','tournaments'),task_key")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn(array $row): array => $this->decorate($row), $rows);
    }

    public function task(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM p2k_control_tasks WHERE task_key=?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->decorate($row) : null;
    }

    public function isPaused(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT status,pause_requested FROM p2k_control_tasks WHERE task_key=?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return (string)($row['status'] ?? '') === 'paused' || (int)($row['pause_requested'] ?? 0) === 1;
    }

    public function command(string $key, string $action, string $message = ''): array
    {
        if (!isset(self::DEFINITIONS[$key])) throw new \InvalidArgumentException('Unknown scheduled task.');
        if ($action === 'pause') {
            $stmt = $this->pdo->prepare("UPDATE p2k_control_tasks SET pause_requested=1,status=IF(status='running','running','paused'),last_message=?,updated_at=UTC_TIMESTAMP() WHERE task_key=?");
            $stmt->execute([$message ?: 'Safe pause requested.', $key]);
            $this->log($key, 'warning', 'controller', $message ?: 'Safe pause requested; an active item may finish and commit first.');
        } elseif (in_array($action, ['start','resume'], true)) {
            $stmt = $this->pdo->prepare("UPDATE p2k_control_tasks SET pause_requested=0,status='queued',next_due_at=UTC_TIMESTAMP(),last_message=?,updated_at=UTC_TIMESTAMP() WHERE task_key=?");
            $stmt->execute([$message ?: ucfirst($action) . ' requested.', $key]);
            $this->log($key, 'success', 'controller', $message ?: ucfirst($action) . ' requested through the unified control page.');
        } elseif ($action === 'refresh') {
            $this->log($key, 'info', 'status', $message ?: 'Task status refreshed.');
        } else {
            throw new \InvalidArgumentException('Unsupported task action.');
        }
        return $this->task($key) ?? [];
    }

    public function beginRun(string $key, string $source, string $runId): bool
    {
        $this->lastBeginReason = '';
        if ($this->isPaused($key)) {
            $this->lastBeginReason = 'paused';
            $this->log($key, 'warning', 'controller', 'Execution skipped because the task is paused.', [], $runId);
            return false;
        }
        if (!$this->acquireTaskLock($key)) {
            $this->lastBeginReason = 'busy';
            $this->log($key, 'warning', 'controller', 'Execution skipped because another invocation of the same task is active.', [], $runId);
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE p2k_control_tasks SET status='running',pause_requested=0,last_started_at=UTC_TIMESTAMP(),last_message='Execution started.',updated_at=UTC_TIMESTAMP() WHERE task_key=?");
            $stmt->execute([$key]);
            $run = $this->pdo->prepare("INSERT INTO p2k_control_task_runs (run_id,task_key,source,status,started_at) VALUES (?,?,?,'running',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status='running',started_at=UTC_TIMESTAMP(),ended_at=NULL");
            $normalizedSource = in_array($source, ['cron','manual','scheduler','legacy'], true) ? $source : 'legacy';
            $run->execute([$runId, $key, $normalizedSource]);
            $this->pdo->commit();
            $this->log($key, 'info', 'controller', 'Task execution started.', ['source'=>$normalizedSource], $runId);
            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->releaseTaskLock($key);
            throw $exception;
        }
    }

    public function lastBeginReason(): string
    {
        return $this->lastBeginReason;
    }

    public function finishRun(string $key, string $runId, string $status, array $metrics = [], string $message = '', array $details = []): void
    {
        $status = in_array($status, ['success','partial','failed','paused','skipped'], true) ? $status : 'failed';
        $success = in_array($status, ['success','partial'], true);
        $expected = (int)(self::DEFINITIONS[$key]['expected_interval_seconds'] ?? 3600);
        $taskStatus = $status === 'failed' ? 'failed' : ($this->pauseRequested($key) || $status === 'paused' ? 'paused' : 'idle');
        $stmt = $this->pdo->prepare("UPDATE p2k_control_tasks SET status=?,last_completed_at=UTC_TIMESTAMP(),last_success_at=IF(?,UTC_TIMESTAMP(),last_success_at),next_due_at=IF(?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),next_due_at),last_message=?,details_json=?,updated_at=UTC_TIMESTAMP() WHERE task_key=?");
        $stmt->execute([$taskStatus, $success ? 1 : 0, $success ? 1 : 0, $expected, substr($message,0,500), json_encode($details, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $key]);
        $run = $this->pdo->prepare("UPDATE p2k_control_task_runs SET status=?,ended_at=UTC_TIMESTAMP(),processed_items=?,updated_items=?,failed_items=?,message=?,details_json=? WHERE run_id=?");
        $run->execute([$status, max(0,(int)($metrics['processed']??0)), max(0,(int)($metrics['updated']??0)), max(0,(int)($metrics['failed']??0)), substr($message,0,500), json_encode($details, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $runId]);
        $level = $status === 'success' ? 'success' : ($status === 'failed' ? 'error' : 'warning');
        $this->log($key, $level, 'controller', $message ?: 'Task execution finished.', $details + $metrics, $runId);
        $this->releaseTaskLock($key);
    }

    public function pauseRequested(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT pause_requested FROM p2k_control_tasks WHERE task_key=?');
        $stmt->execute([$key]);
        return (int)$stmt->fetchColumn() === 1;
    }

    public function log(string $key, string $level, string $component, string $message, array $context = [], ?string $runId = null): void
    {
        $allowed = ['debug','info','success','warning','error'];
        if (!in_array($level, $allowed, true)) $level = 'info';
        $stmt = $this->pdo->prepare('INSERT INTO p2k_control_task_logs (task_key,run_id,level,component,message,context_json,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())');
        $stmt->execute([$key, $runId, $level, substr($component,0,80), substr($message,0,500), $context ? json_encode($context, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : null]);
    }

    public function logs(int $limit = 250, string $taskKey = '', string $level = '', int $beforeId = 0): array
    {
        $where = []; $values = [];
        if ($taskKey !== '') { $where[] = 'task_key=?'; $values[] = $taskKey; }
        if ($level !== '') { $where[] = 'level=?'; $values[] = $level; }
        if ($beforeId > 0) { $where[] = 'id < ?'; $values[] = $beforeId; }
        $sql = 'SELECT * FROM p2k_control_task_logs' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . max(1,min(1000,$limit));
        $stmt = $this->pdo->prepare($sql); $stmt->execute($values);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function runs(int $limit = 100, string $taskKey = ''): array
    {
        $sql = 'SELECT * FROM p2k_control_task_runs' . ($taskKey !== '' ? ' WHERE task_key=?' : '') . ' ORDER BY id DESC LIMIT ' . max(1,min(500,$limit));
        $stmt = $this->pdo->prepare($sql); $stmt->execute($taskKey !== '' ? [$taskKey] : []);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function taskLockName(string $key): string
    {
        return 'p2k_control_task_' . substr(preg_replace('/[^a-z0-9_-]+/i', '-', $key) ?: 'task', 0, 40);
    }

    private function acquireTaskLock(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?,0)');
        $stmt->execute([$this->taskLockName($key)]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseTaskLock(string $key): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$this->taskLockName($key)]);
        } catch (\Throwable) {
        }
    }

    private function decorate(array $row): array
    {
        $expected = max(1, (int)$row['expected_interval_seconds']);
        $lastSuccess = !empty($row['last_success_at']) ? strtotime((string)$row['last_success_at'] . ' UTC') : false;
        $age = $lastSuccess === false ? null : max(0, time() - $lastSuccess);
        $status = (string)$row['status'];
        if ($status === 'failed') { $health = 'critical'; $healthMessage = 'Latest execution failed.'; }
        elseif ($status === 'paused') { $health = 'paused'; $healthMessage = 'Task is paused.'; }
        elseif ($lastSuccess === false) { $health = 'warning'; $healthMessage = 'No successful run recorded.'; }
        elseif ($age > (int)round($expected * 1.5)) { $health = 'critical'; $healthMessage = 'Last successful run is overdue.'; }
        elseif ($age > $expected) { $health = 'warning'; $healthMessage = 'Refresh is due.'; }
        else { $health = 'healthy'; $healthMessage = 'Running within the expected cadence.'; }
        $row['expected_interval_seconds'] = $expected;
        $row['age_seconds'] = $age;
        $row['health'] = $health;
        $row['health_message'] = $healthMessage;
        $row['details'] = !empty($row['details_json']) ? (json_decode((string)$row['details_json'], true) ?: []) : [];
        unset($row['details_json']);
        return $row;
    }
}
