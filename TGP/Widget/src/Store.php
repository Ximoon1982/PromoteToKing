<?php
declare(strict_types=1);

namespace TGP\Widget;

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
    public const SCHEMA_VERSION = 2;

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
                'arenas' => $this->normalizeArenas($payload['arenas'] ?? $current['arenas']),
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
            'arenas' => [],
        ];
    }

    private function normalizeState(array $raw): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'revision' => max(0, (int)($raw['revision'] ?? 0)),
            'updatedAt' => is_string($raw['updatedAt'] ?? null) ? $raw['updatedAt'] : null,
            'items' => $this->normalizeItems(is_array($raw['items'] ?? null) ? $raw['items'] : []),
            'arenas' => $this->normalizeArenas(is_array($raw['arenas'] ?? null) ? $raw['arenas'] : []),
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

    private function normalizeArenas(mixed $raw): array
    {
        if (!is_array($raw)) throw new InvalidArgumentException('arenas must be an array.');
        if (count($raw) > 100) throw new InvalidArgumentException('Too many arenas.');
        $result = []; $seen = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) throw new InvalidArgumentException('Invalid arena entry.');
            $name = trim((string)($entry['name'] ?? ''));
            $link = trim((string)($entry['link'] ?? ''));
            $date = trim((string)($entry['date'] ?? ''));
            $time = trim((string)($entry['time'] ?? ''));
            $duration = trim((string)($entry['duration'] ?? ''));
            $control = trim((string)($entry['timeControl'] ?? ''));
            $active = ($entry['active'] ?? false) === true;
            if (!$active && $name === '' && $link === '' && $date === '' && $time === '' && $duration === '' && $control === '') continue;
            if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Arena name is required (maximum 120 characters).');
            if (strlen($link) > 600 || !preg_match('~^https://(?:www\.)?chess\.com/(?:play/)?(?:tournament|arena|live)(?:/|\?|$)~i', $link)) throw new InvalidArgumentException('Use a Chess.com HTTPS arena link.');
            $parts = parse_url($link);
            if (!is_array($parts) || !in_array(strtolower((string)($parts['host'] ?? '')), ['chess.com', 'www.chess.com'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) throw new InvalidArgumentException('Invalid arena URL.');
            if ($date === '' || $time === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !preg_match('/^\d{2}:\d{2}$/D', $time)) throw new InvalidArgumentException('Arena start date and time are required.');
            if (strlen($control) > 40 || strlen($duration) > 40) throw new InvalidArgumentException('Arena time control or duration is too long.');
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, new \DateTimeZone('Europe/Luxembourg'));
            if (!$dt || $dt->format('Y-m-d H:i') !== $date . ' ' . $time) throw new InvalidArgumentException('Invalid arena start date/time.');
            $minutes = self::durationMinutes($duration);
            if ($minutes === null) throw new InvalidArgumentException('Arena duration must be minutes (90m), hours (2h), or H:MM.');
            $key = strtolower(rtrim($link, '/'));
            if (isset($seen[$key])) throw new InvalidArgumentException('Duplicate arena URL.');
            $seen[$key] = true;
            $end = $dt->getTimestamp() + $minutes * 60;
            if ($end <= time()) continue;
            $result[] = ['active'=>$active,'name'=>$name,'link'=>$link,'date'=>$date,'time'=>$time,
                'timeControl'=>$control,'duration'=>$duration,'durationMinutes'=>$minutes,'startAt'=>$dt->format(DATE_ATOM),'endAt'=>gmdate('c', $end)];
        }
        usort($result, static fn(array $a, array $b): int => strcmp($a['startAt'], $b['startAt']));
        return $result;
    }

    private static function durationMinutes(string $value): ?int
    {
        $v = strtolower(trim($value));
        if (preg_match('/^(\d+)\s*(?:m|min|mins|minutes?)?$/D', $v, $m)) return max(1, (int)$m[1]);
        if (preg_match('/^(\d+)\s*(?:h|hr|hours?)(?:\s*(\d{1,2})\s*m?)?$/D', $v, $m)) return max(1, (int)$m[1] * 60 + (int)($m[2] ?? 0));
        if (preg_match('/^(\d+):([0-5]\d)$/D', $v, $m)) return max(1, (int)$m[1] * 60 + (int)$m[2]);
        return null;
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
