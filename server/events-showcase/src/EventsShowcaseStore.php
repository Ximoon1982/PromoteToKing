<?php
declare(strict_types=1);

namespace P2K\EventsShowcase;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class RevisionConflict extends RuntimeException {
    public function __construct(public readonly array $current) { parent::__construct('Events Showcase changed after this page loaded.'); }
}

final class EventsShowcaseStore {
    public const SCHEMA_VERSION = 4;
    public const MAX_ITEMS = 100;
    public const TIMEZONE = 'Europe/Luxembourg';

    private string $statePath;
    private string $legacyPath;
    private string $lockPath;

    public function __construct(private readonly string $root) {
        $this->statePath = $root . '/data/events-showcase.json';
        $this->legacyPath = $root . '/data/priority-matches.json';
        $this->lockPath = $root . '/data/events-showcase.lock';
    }

    public function read(): array {
        return $this->withLock(function (): array {
            [$state, $dirty] = $this->loadNormalizedLocked();
            if ($dirty) $state = $this->writeLocked($state, false);
            return $state;
        });
    }

    public function save(array $payload, int $expectedRevision): array {
        return $this->withLock(function () use ($payload, $expectedRevision): array {
            [$current, $dirty] = $this->loadNormalizedLocked();
            if ($dirty) $current = $this->writeLocked($current, false);
            if ($expectedRevision !== (int)($current['revision'] ?? 0)) throw new RevisionConflict($current);
            $next = [
                'schemaVersion' => self::SCHEMA_VERSION,
                'revision' => $expectedRevision,
                'updatedAt' => $current['updatedAt'] ?? null,
                'migration' => is_array($current['migration'] ?? null) ? $current['migration'] : [],
                'items' => $this->normalizeItems($payload['items'] ?? []),
                'arenas' => $this->normalizeArenas($payload['arenas'] ?? [], true),
            ];
            return $this->writeLocked($next, true);
        });
    }

    public function metrics(): array {
        $state = $this->read();
        $arenas = $state['arenas'] ?? [];
        $items = $state['items'] ?? [];
        return [
            'arenas' => ['total' => count($arenas), 'enabled' => count(array_filter($arenas, fn($a) => ($a['active'] ?? false) === true))],
            'dailyMatches' => ['total' => count($items), 'enabled' => count(array_filter($items, fn($i) => ($i['enabled'] ?? true) !== false))],
        ];
    }

    private function withLock(callable $fn): mixed {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Unable to create Events Showcase storage directory.');
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) throw new RuntimeException('Unable to open Events Showcase state lock.');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Unable to lock Events Showcase state.');
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function emptyState(): array {
        return ['schemaVersion'=>self::SCHEMA_VERSION,'revision'=>0,'updatedAt'=>null,'migration'=>[],'items'=>[],'arenas'=>[]];
    }

    private function loadNormalizedLocked(): array {
        $dirty = false;
        $raw = $this->readJson($this->statePath);
        if ($raw === null) {
            $legacy = $this->readJson($this->legacyPath);
            if ($legacy !== null) {
                $raw = $legacy;
                $raw['migration'] = [
                    'source' => 'data/priority-matches.json',
                    'legacyPreserved' => true,
                    'legacySha256' => is_file($this->legacyPath) ? hash_file('sha256', $this->legacyPath) : null,
                    'migratedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ];
                $dirty = true;
            } else $raw = $this->emptyState();
        }
        $state = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'revision' => max(0, (int)($raw['revision'] ?? 0)),
            'updatedAt' => $raw['updatedAt'] ?? null,
            'migration' => is_array($raw['migration'] ?? null) ? $raw['migration'] : [],
            'items' => $this->normalizeItems(is_array($raw['items'] ?? null) ? $raw['items'] : []),
            'arenas' => [],
        ];
        $before = is_array($raw['arenas'] ?? null) ? $raw['arenas'] : [];
        $state['arenas'] = $this->normalizeArenas($before, false);
        if ((int)($raw['schemaVersion'] ?? 0) !== self::SCHEMA_VERSION || $before !== $state['arenas'] || ($raw['items'] ?? []) !== $state['items']) $dirty = true;
        return [$state, $dirty];
    }

    private function normalizeItems(mixed $rawItems): array {
        if (!is_array($rawItems)) throw new InvalidArgumentException('items must be an array');
        $items = []; $seen = [];
        foreach ($rawItems as $raw) {
            if (!is_array($raw)) throw new InvalidArgumentException('each item must be an object');
            $matchId = trim((string)($raw['matchId'] ?? ''));
            if ($matchId === '' || strlen($matchId) > 32 || !preg_match('/^[0-9]+$/', $matchId)) throw new InvalidArgumentException('invalid matchId');
            if (isset($seen[$matchId])) continue;
            $seen[$matchId] = true;
            $items[] = ['matchId'=>$matchId,'enabled'=>!array_key_exists('enabled',$raw)||$raw['enabled']===true,'urgent'=>($raw['urgent']??false)===true];
            if (count($items) > self::MAX_ITEMS) throw new InvalidArgumentException('too many priority matches');
        }
        return $items;
    }

    private function normalizeArenas(mixed $rawArenas, bool $strict): array {
        if (!is_array($rawArenas)) throw new InvalidArgumentException('arenas must be an array');
        $arenas=[]; $seen=[]; $now=time();
        foreach ($rawArenas as $raw) {
            if (!is_array($raw)) { if ($strict) throw new InvalidArgumentException('each arena must be an object'); else continue; }
            $arena = $this->normalizeArena($raw);
            if ($this->arenaEmpty($arena) || $this->arenaExpired($arena, $now)) continue;
            if (strlen($arena['name']) > 120) throw new InvalidArgumentException('arena name is too long');
            if (strlen($arena['link']) > 600) throw new InvalidArgumentException('arena link is too long');
            if (strlen($arena['timeControl']) > 40) throw new InvalidArgumentException('arena time control is too long');
            if (strlen($arena['duration']) > 40) throw new InvalidArgumentException('arena duration is too long');
            if ($arena['date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $arena['date'])) throw new InvalidArgumentException('arena date must use YYYY-MM-DD');
            if ($arena['time'] !== '' && !preg_match('/^\d{2}:\d{2}$/', $arena['time'])) throw new InvalidArgumentException('arena time must use HH:MM');
            if ($arena['duration'] !== '' && $arena['durationMinutes'] === null) throw new InvalidArgumentException('arena duration must be minutes, e.g. 90m, 2h, 1h30m, or H:MM');
            if ($arena['date'] !== '' && $arena['time'] !== '' && $arena['startAt'] === '') throw new InvalidArgumentException('arena date/time is invalid');
            $key = $this->arenaUrlKey($arena['link']);
            if ($key !== '') {
                $parts=parse_url($arena['link']); $scheme=strtolower((string)($parts['scheme']??'')); $host=strtolower((string)($parts['host']??''));
                if ($scheme !== 'https' || !in_array($host,['chess.com','www.chess.com'],true)) throw new InvalidArgumentException('arena link must be an HTTPS chess.com URL');
                if (isset($seen[$key])) { if ($strict) throw new InvalidArgumentException('an arena with this URL is already configured'); else continue; }
                $seen[$key]=true;
            }
            $arenas[]=$arena;
        }
        usort($arenas, static fn(array $a,array $b): int => (($a['startAt']??'')===''?1:(($b['startAt']??'')===''?-1:strcmp((string)$a['startAt'],(string)$b['startAt']))));
        return array_values($arenas);
    }

    private function normalizeArena(array $raw): array {
        $date=trim((string)($raw['date']??'')); $time=trim((string)($raw['time']??'')); $duration=trim((string)($raw['duration']??''));
        $start=$this->arenaStart($date,$time); $durationMinutes=$this->durationMinutes($duration);
        return ['active'=>($raw['active']??false)===true,'name'=>trim((string)($raw['name']??'')),'link'=>trim((string)($raw['link']??'')),'date'=>$date,'time'=>$time,'timeControl'=>trim((string)($raw['timeControl']??'')),'duration'=>$duration,'durationMinutes'=>$durationMinutes,'startAt'=>$start?->format(DATE_ATOM)??''];
    }

    private function durationMinutes(string $value): ?int {
        $value=strtolower(trim($value)); if($value==='')return null;
        if(preg_match('/^(\d+)$/',$value,$m))return max(1,(int)$m[1]);
        if(preg_match('/^(\d+)\s*(?:m|min|mins|minute|minutes)$/',$value,$m))return max(1,(int)$m[1]);
        if(preg_match('/^(\d+)\s*(?:h|hr|hrs|hour|hours)$/',$value,$m))return max(1,(int)$m[1]*60);
        if(preg_match('/^(\d+)\s*h(?:\s*(\d{1,2})\s*m?)?$/',$value,$m))return max(1,(int)$m[1]*60+(int)($m[2]??0));
        if(preg_match('/^(\d+):([0-5]\d)$/',$value,$m))return max(1,(int)$m[1]*60+(int)$m[2]);
        return null;
    }

    private function arenaStart(string $date,string $time): ?DateTimeImmutable {
        if($date===''||$time==='')return null; $tz=new DateTimeZone(self::TIMEZONE); $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time,$tz); $errors=DateTimeImmutable::getLastErrors();
        if(!$dt||(is_array($errors)&&(($errors['warning_count']??0)>0||($errors['error_count']??0)>0))||$dt->format('Y-m-d H:i')!==$date.' '.$time)return null; return $dt;
    }
    private function arenaExpired(array $arena,int $now): bool { $start=$this->arenaStart((string)$arena['date'],(string)$arena['time']);$duration=$this->durationMinutes((string)$arena['duration']);return $start&&$duration?$now>=($start->getTimestamp()+$duration*60):false; }
    private function arenaEmpty(array $a): bool { return !($a['active']??false)&&trim((string)$a['name'])===''&&trim((string)$a['link'])===''&&trim((string)$a['date'])===''&&trim((string)$a['time'])===''&&trim((string)$a['timeControl'])===''&&trim((string)$a['duration'])===''; }
    private function arenaUrlKey(string $url): string { $url=trim($url);if($url==='')return'';$p=parse_url($url);if(!is_array($p))return$url;$scheme=strtolower((string)($p['scheme']??''));$host=strtolower((string)($p['host']??''));if($host==='www.chess.com')$host='chess.com';$port=isset($p['port'])?':'.(int)$p['port']:'';$path=(string)($p['path']??'');if($path!=='/')$path=rtrim($path,'/');$query=isset($p['query'])&&$p['query']!==''?'?'.$p['query']:'';return$scheme.'://'.$host.$port.$path.$query; }

    private function readJson(string $path): ?array { if(!is_file($path))return null;$raw=@file_get_contents($path);if($raw===false)return null;$v=json_decode($raw,true);return is_array($v)?$v:null; }
    private function writeLocked(array $state,bool $increment): array {
        $state['schemaVersion']=self::SCHEMA_VERSION;
        $state['revision']=max(0,(int)($state['revision']??0))+($increment?1:0);
        $state['updatedAt']=gmdate('Y-m-d\TH:i:s\Z');
        $dir=dirname($this->statePath);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Unable to create Events Showcase storage directory.');
        $tmp=tempnam($dir,'.events-showcase.');if($tmp===false)throw new RuntimeException('Unable to create temporary Events Showcase state.');
        $json=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false||file_put_contents($tmp,$json."\n",LOCK_EX)===false){@unlink($tmp);throw new RuntimeException('Unable to write Events Showcase state.');}
        if(is_file($this->statePath))@copy($this->statePath,$this->statePath.'.bak');
        if(!@rename($tmp,$this->statePath)){@unlink($tmp);throw new RuntimeException('Unable to replace Events Showcase state.');}
        return $state;
    }
}
