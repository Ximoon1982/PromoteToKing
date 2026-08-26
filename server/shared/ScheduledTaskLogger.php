<?php
declare(strict_types=1);

namespace P2K\Shared;

final class ScheduledTaskLogger
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function append(array $entry): array
    {
        $taskType = self::identifier((string)($entry['taskType'] ?? 'database-update'), 'database-update');
        $taskId = self::identifier((string)($entry['taskId'] ?? $taskType), $taskType);
        $runId = self::identifier((string)($entry['runId'] ?? ''), self::runId($taskId));
        $status = strtolower((string)($entry['status'] ?? 'success'));
        if (!in_array($status, ['success', 'partial', 'failed'], true)) $status = 'failed';
        $source = strtolower((string)($entry['source'] ?? 'cron'));
        if (!in_array($source, ['cron', 'manual'], true)) $source = 'cron';
        $startedAt = (string)($entry['startedAt'] ?? gmdate('c'));
        $endedAt = (string)($entry['endedAt'] ?? gmdate('c'));
        $processed = max(0, (int)($entry['processedReferences'] ?? $entry['processedItems'] ?? 0));
        $updated = max(0, (int)($entry['storedMatches'] ?? $entry['updatedItems'] ?? 0));
        $failed = max(0, (int)($entry['failedMatches'] ?? $entry['failedItems'] ?? 0));
        $record = [
            'schemaVersion' => 4,
            'event' => 'match-snapshot-task',
            'timestamp' => $endedAt,
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
            'taskType' => $taskType,
            'taskId' => $taskId,
            'runId' => $runId,
            'source' => $source,
            'status' => $status,
            'registeredReferences' => 0,
            'autoLeagueReferences' => 0,
            'followedReferences' => 0,
            'trackedReferences' => $processed,
            'leagueReferences' => $processed,
            'processedReferences' => $processed,
            'storedMatches' => $updated,
            'updatedItems' => $updated,
            'skippedMatches' => max(0, (int)($entry['skippedMatches'] ?? $entry['skippedItems'] ?? 0)),
            'failedMatches' => $failed,
            'excludedItems' => max(0, (int)($entry['excludedItems'] ?? 0)),
            'durationMs' => max(0, (int)($entry['durationMs'] ?? 0)),
            'storedMatchIds' => is_array($entry['storedMatchIds'] ?? null) ? array_slice($entry['storedMatchIds'], 0, 200) : [],
            'failedMatchIds' => is_array($entry['failedMatchIds'] ?? null) ? array_slice($entry['failedMatchIds'], 0, 200) : [],
            'message' => substr((string)($entry['message'] ?? ''), 0, 500),
            'details' => is_array($entry['details'] ?? null) ? $entry['details'] : [],
        ];
        $dir = self::root() . '/logs/scheduled-tasks';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create scheduled-task log directory.');
        }
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false || file_put_contents($dir . '/' . gmdate('Y-m-d') . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Unable to append the scheduled-task log.');
        }
        return $record;
    }

    public static function runId(string $taskId): string
    {
        try { $suffix = bin2hex(random_bytes(4)); }
        catch (\Throwable) { $suffix = substr(sha1(uniqid('', true)), 0, 8); }
        return self::identifier($taskId, 'task') . '-' . gmdate('Ymd\THis\Z') . '-' . $suffix;
    }

    private static function identifier(string $value, string $fallback): string
    {
        $clean = strtolower(trim($value));
        $clean = preg_replace('/[^a-z0-9._:-]+/', '-', $clean) ?: '';
        return substr(trim($clean, '-') ?: $fallback, 0, 120);
    }
}
