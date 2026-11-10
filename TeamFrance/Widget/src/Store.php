<?php
declare(strict_types=1);

namespace TeamFrance\Widget;

use InvalidArgumentException;
use RuntimeException;

final class RevisionConflict extends RuntimeException
{
    public function __construct(public readonly array $current)
    {
        parent::__construct('The showcase configuration changed after this page loaded.');
    }
}

final class Store
{
    public const SCHEMA_VERSION = 1;

    private string $statePath;
    private string $lockPath;

    public function __construct(private readonly string $root, private readonly int $maxItems = 100)
    {
        $this->statePath = $root . '/data/showcase.json';
        $this->lockPath = $root . '/data/showcase.lock';
    }

    public function read(): array
    {
        return $this->withLock(function (): array {
            $raw = $this->readJson($this->statePath) ?? $this->emptyState();
            $state = $this->normalizeState($raw);
            if ($state !== $raw) $this->writeRaw($state);
            return $state;
        });
    }

    public function save(array $payload, int $expectedRevision): array
    {
        return $this->withLock(function () use ($payload, $expectedRevision): array {
            $current = $this->normalizeState($this->readJson($this->statePath) ?? $this->emptyState());
            if ((int)$current['revision'] !== $expectedRevision) throw new RevisionConflict($current);
            $next = [
                'schemaVersion' => self::SCHEMA_VERSION,
                'revision' => $expectedRevision + 1,
                'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'items' => $this->normalizeItems($payload['items'] ?? []),
            ];
            $this->writeRaw($next);
            return $next;
        });
    }

    private function emptyState(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'revision' => 0,
            'updatedAt' => null,
            'items' => [],
        ];
    }

    private function normalizeState(array $raw): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'revision' => max(0, (int)($raw['revision'] ?? 0)),
            'updatedAt' => is_string($raw['updatedAt'] ?? null) ? $raw['updatedAt'] : null,
            'items' => $this->normalizeItems(is_array($raw['items'] ?? null) ? $raw['items'] : []),
        ];
    }

    private function normalizeItems(mixed $rawItems): array
    {
        if (!is_array($rawItems)) throw new InvalidArgumentException('items must be an array');
        $items = [];
        $seen = [];
        foreach ($rawItems as $raw) {
            if (!is_array($raw)) throw new InvalidArgumentException('Each match must be an object.');
            $matchId = trim((string)($raw['matchId'] ?? ''));
            if ($matchId === '' || !preg_match('/^[0-9]{1,18}$/D', $matchId)) throw new InvalidArgumentException('Invalid Chess.com match id.');
            if (isset($seen[$matchId])) continue;
            $seen[$matchId] = true;
            $items[] = [
                'matchId' => $matchId,
                'enabled' => !array_key_exists('enabled', $raw) || $raw['enabled'] === true,
                'urgent' => ($raw['urgent'] ?? false) === true,
            ];
            if (count($items) > $this->maxItems) throw new InvalidArgumentException('Too many configured matches.');
        }
        return array_values($items);
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeRaw(array $state): void
    {
        $dir = dirname($this->statePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Unable to create showcase data directory.');
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new RuntimeException('Unable to encode showcase state.');
        $tmp = tempnam($dir, '.showcase.');
        if ($tmp === false) throw new RuntimeException('Unable to create temporary showcase state.');
        try {
            if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) throw new RuntimeException('Unable to write showcase state.');
            @chmod($tmp, 0664);
            if (is_file($this->statePath)) @copy($this->statePath, $this->statePath . '.bak');
            if (!@rename($tmp, $this->statePath)) throw new RuntimeException('Unable to replace showcase state.');
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private function withLock(callable $callback): mixed
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Unable to create showcase data directory.');
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) throw new RuntimeException('Unable to open showcase lock.');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Unable to lock showcase state.');
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
