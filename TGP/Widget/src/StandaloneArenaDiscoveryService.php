<?php
declare(strict_types=1);

namespace P2K\EventsShowcase;

use InvalidArgumentException;
use RuntimeException;

/**
 * Independent TGP / Team France organizer watchlists with a shared P2K arena
 * index parser and a process-shared index request throttle. Admin-only calls.
 * Never changes either widget's public showcase until an editor saves an arena.
 */
final class StandaloneArenaDiscoveryService
{
    public const MAX_PAGES = 20;
    private const SOURCE = 'https://www.chess.com/tournament/upcoming/arena';
    private readonly string $dataPath;
    private readonly string $ratePath;

    public function __construct(string $widgetRoot, string $applicationRoot)
    {
        $this->dataPath = rtrim($widgetRoot, '/\\') . '/data/upcoming-arena-discovery.json';
        // P2K arena scanner already uses this lock. Coordinate both standalone
        // widgets with the P2K admin scanner instead of bursting parallel scans.
        $this->ratePath = rtrim($applicationRoot, '/\\') . '/data/events-showcase-admin-discovery.json.rate';
    }

    private function locked(callable $callback): mixed
    {
        $dir = dirname($this->dataPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir))
            throw new RuntimeException('Unable to prepare arena discovery storage.');
        $lock = fopen($this->dataPath . '.lock', 'c+');
        if ($lock === false) throw new RuntimeException('Unable to lock arena discovery.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock arena discovery.');
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readUnlocked(): array
    {
        $raw = is_file($this->dataPath) ? @file_get_contents($this->dataPath) : false;
        $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $data = is_array($json) ? $json : [];
        return [
            'revision' => max(0, (int)($data['revision'] ?? 0)),
            'organizers' => is_array($data['organizers'] ?? null) ? $data['organizers'] : [],
            'scan' => is_array($data['scan'] ?? null) ? $data['scan'] : null,
        ];
    }

    private function writeUnlocked(array $state): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $temp = tempnam(dirname($this->dataPath), '.arena-discovery-');
        if ($temp === false) throw new RuntimeException('Unable to stage arena discovery state.');
        try {
            if (file_put_contents($temp, $json . "\n", LOCK_EX) === false ||
                !@rename($temp, $this->dataPath)) {
                throw new RuntimeException('Unable to save arena discovery state.');
            }
        } finally {
            if (is_file($temp)) @unlink($temp);
        }
    }

    public function read(): array
    {
        return $this->locked(fn(): array => $this->readUnlocked());
    }

    /**
     * Accept Chess.com club URL / public slug / positive numeric club ID.
     * Network URLs are constructed from validated references, never supplied
     * directly by the caller (SSRF and redirect protections).
     */
    public static function parseClubReference(string $reference): array
    {
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 600)
            throw new InvalidArgumentException('Enter a Chess.com club URL, slug or numeric ID.');
        if (preg_match('/^[1-9][0-9]{0,14}$/D', $reference))
            return ['kind'=>'id','value'=>$reference];
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/iD', $reference))
            return ['kind'=>'slug','value'=>strtolower($reference)];
        if (preg_match('~^(?:www\.|api\.)?chess\.com/~i', $reference))
            $reference = 'https://' . $reference;
        $parts = parse_url($reference);
        if (!is_array($parts) ||
            !in_array(strtolower((string)($parts['scheme'] ?? '')), ['https','http'],true) ||
            !in_array(strtolower((string)($parts['host'] ?? '')),
                ['chess.com','www.chess.com','api.chess.com'],true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port']))
            throw new InvalidArgumentException('Use a Chess.com club URL, slug or numeric ID.');
        $path = trim((string)($parts['path'] ?? ''), '/');
        $pattern = strtolower((string)$parts['host']) === 'api.chess.com'
            ? '~^pub/club/([a-z0-9-]{1,100})$~iD'
            : '~^club/([a-z0-9-]{1,100})$~iD';
        if (!preg_match($pattern, $path, $m))
            throw new InvalidArgumentException('The URL must point to a Chess.com club profile.');
        $value = strtolower($m[1]);
        if (preg_match('/^[1-9][0-9]{0,14}$/D', $value))
            return ['kind'=>'id','value'=>$value];
        return ['kind'=>'slug','value'=>$value];
    }

    /** Verify the PubAPI's actual numeric identity and published club name. */
    public static function normalizeClubProfile(array $profile, string $fallbackSlug, ?string $expectedId = null): array
    {
        $id = trim((string)($profile['club_id'] ?? ''));
        $name = trim((string)($profile['name'] ?? ''));
        if (!preg_match('/^[1-9][0-9]{0,14}$/D', $id) ||
            $name === '' || (function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 80)
            throw new RuntimeException('Chess.com returned incomplete club identity information.', 502);
        if ($expectedId !== null && $id !== $expectedId)
            throw new RuntimeException('Chess.com returned a club with a different numeric ID.', 422);
        $uri = trim((string)($profile['@id'] ?? $profile['url'] ?? ''));
        $parts = parse_url($uri);
        $slug = '';
        if (is_array($parts) &&
            in_array(strtolower((string)($parts['host'] ?? '')),['api.chess.com','www.chess.com','chess.com'],true) &&
            preg_match('~/(?:pub/)?club/([a-z0-9-]{1,100})/?$~iD',(string)($parts['path'] ?? ''),$m))
            $slug = strtolower($m[1]);
        if ($slug === '' && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/iD',$fallbackSlug))
            $slug = strtolower($fallbackSlug);
        if ($slug === '' || ctype_digit($slug))
            throw new RuntimeException('Chess.com did not provide a verifiable club URL slug.', 502);
        return ['clubId'=>$id,'name'=>$name,'slug'=>$slug,
            'url'=>'https://www.chess.com/club/'.$slug,'enabled'=>true];
    }

    /** Extract only canonical URL or official redirect, never arbitrary links. */
    public static function canonicalClubSlug(string $headers, string $html): string
    {
        $candidates = [];
        if (preg_match('/^location:\s*(\S+)/im',$headers,$m))
            $candidates[] = trim($m[1]);
        if (preg_match_all('~<(?:link|meta)\b[^>]+>~i',$html,$tags)) {
            foreach ($tags[0] as $tag) {
                if (!preg_match('~(?:canonical|og:url)~i',$tag)) continue;
                if (preg_match('~(?:href|content)\s*=\s*["\x27]([^"\x27]+)~i',$tag,$m))
                    $candidates[] = html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
            }
        }
        foreach ($candidates as $uri) {
            if (preg_match('~^/club/([a-z0-9-]{1,100})/?(?:[?#].*)?$~iD',$uri,$m) &&
                !ctype_digit($m[1])) return strtolower($m[1]);
            $parts=parse_url($uri);
            if (is_array($parts) &&
                in_array(strtolower((string)($parts['host'] ?? '')),['chess.com','www.chess.com'],true) &&
                preg_match('~^/club/([a-z0-9-]{1,100})/?$~iD',(string)($parts['path'] ?? ''),$m) &&
                !ctype_digit($m[1])) return strtolower($m[1]);
        }
        return '';
    }

    public function resolveOrganizer(string $reference): array
    {
        $input=self::parseClubReference($reference);
        $value=$input['value'];
        if ($input['kind']==='slug')
            return self::normalizeClubProfile($this->getClubProfile($value),$value);

        // Chess.com's published API normally uses URL slugs. Some numeric
        // references resolve directly; only accept an EXACT matching club_id.
        $numericId=$value;
        try {
            $profile=$this->getClubProfile($numericId);
            return self::normalizeClubProfile($profile,'',$numericId);
        } catch (RuntimeException $e) {
            if ($e->getCode()===429) throw $e;
        }
        // Club page canonical URL/redirect can reveal the slug. Never follow
        // redirects automatically or trust unrelated clubs linked on the page.
        foreach ([
            'https://www.chess.com/club/'.$numericId,
            'https://www.chess.com/club/live-tournaments/'.$numericId
        ] as $url) {
            $page=$this->getChessContent($url,'text/html');
            if ($page['status']===429)
                throw new RuntimeException('Chess.com is rate limiting club lookups; retry later.',429);
            if ($page['status']!==200 && !in_array($page['status'],[301,302,307,308],true)) continue;
            $slug=self::canonicalClubSlug($page['headers'],$page['body']);
            if ($slug==='') continue;
            $profile=$this->getClubProfile($slug);
            return self::normalizeClubProfile($profile,$slug,$numericId);
        }
        throw new RuntimeException(
            'Club #'.$numericId.' could not be resolved to a verified Chess.com name. ' .
            'Try the club URL or slug instead.',422);
    }

    private function getClubProfile(string $slug): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/iD',$slug))
            throw new InvalidArgumentException('Invalid Chess.com club reference.');
        $response=$this->getChessContent('https://api.chess.com/pub/club/'.rawurlencode($slug),'application/json');
        if ($response['status']===429)
            throw new RuntimeException('Chess.com is rate limiting club lookups; retry later.',429);
        if ($response['status']!==200)
            throw new RuntimeException('Chess.com club profile unavailable (HTTP '.$response['status'].').',502);
        $json=json_decode($response['body'],true);
        if (!is_array($json))
            throw new RuntimeException('Chess.com club profile returned invalid JSON.',502);
        return $json;
    }

    /** Use the P2K-discovery throttle for ALL club and arena requests. */
    private function getChessContent(string $url,string $accept): array
    {
        $rateDir=dirname($this->ratePath);
        if (!is_dir($rateDir) && !@mkdir($rateDir,0775,true) && !is_dir($rateDir))
            throw new RuntimeException('Unable to prepare Chess.com shared request throttle.');
        $lock=fopen($this->ratePath,'c+');
        if ($lock===false) throw new RuntimeException('Unable to open Chess.com request throttle.');
        try {
            if (!flock($lock,LOCK_EX)) throw new RuntimeException('Unable to lock Chess.com request throttle.');
            $last=(float)trim((string)stream_get_contents($lock));
            $wait=1.0-(microtime(true)-$last);
            if ($wait>0) usleep((int)ceil($wait*1000000));
            rewind($lock);
            ftruncate($lock,0);
            fwrite($lock,sprintf('%.6f',microtime(true)));
            fflush($lock);
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for Chess.com lookups.');
        $ch=curl_init($url);
        $headers='';
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_TIMEOUT=>18, CURLOPT_CONNECTTIMEOUT=>6,
            CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; P2K-Widget-MCA-Organizer/0.3.4)',
            CURLOPT_HTTPHEADER=>['Accept: '.$accept],
            CURLOPT_ENCODING=>'', CURLOPT_MAXREDIRS=>0,
            CURLOPT_HEADERFUNCTION=>static function($ch,string $header) use (&$headers): int {
                $headers.=$header;
                return strlen($header);
            }
        ]);
        $body=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || strlen($body)>2000000)
            throw new RuntimeException('Chess.com club lookup failed or exceeded size limit.',502);
        return ['status'=>$status,'headers'=>$headers,'body'=>$body];
    }

    public function saveOrganizers(array $organizers, int $revision): array
    {
        if (count($organizers) > 100) throw new InvalidArgumentException('Maximum 100 organizing clubs.');
        $new = [];
        $seen = [];
        foreach ($organizers as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Organizer must be an object.');
            $id = trim((string)($row['clubId'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            if (!preg_match('/^[1-9][0-9]{0,14}$/D', $id) ||
                $name === '' || (function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 80)
                throw new InvalidArgumentException('Each organizer needs a numeric Chess.com club ID and a name (1–80 characters).');
            if (isset($seen[$id])) throw new InvalidArgumentException('Duplicate organizing club ID.');
            $seen[$id] = true;
            $new[] = ['clubId'=>$id, 'name'=>$name, 'enabled'=>($row['enabled'] ?? true) === true];
        }
        return $this->locked(function () use ($new, $revision): array {
            $state = $this->readUnlocked();
            if ($state['revision'] !== $revision)
                throw new RuntimeException('Organizer configuration changed in another session; reload before saving.', 409);
            if ($state['organizers'] !== $new) {
                $state['organizers'] = $new;
                $state['scan'] = null;
                $state['revision']++;
                $this->writeUnlocked($state);
            }
            return $state;
        });
    }

    public function begin(): array
    {
        return $this->locked(function (): array {
            $state = $this->readUnlocked();
            if (array_filter($state['organizers'], static fn($x): bool => is_array($x) && ($x['enabled'] ?? false) === true) === [])
                throw new InvalidArgumentException('Add and enable an organizing club before scanning.');
            $now = time();
            $state['scan'] = [
                'id'=>bin2hex(random_bytes(12)), 'status'=>'running',
                'startedAt'=>gmdate(DATE_ATOM, $now), 'cutoffEpoch'=>$now + 7 * 86400,
                'pages'=>0, 'seen'=>0, 'results'=>[], 'message'=>'',
                'lastSourceTime'=>null,
            ];
            $this->writeUnlocked($state);
            return $state;
        });
    }

    public function cancel(string $scanId): array
    {
        return $this->locked(function () use ($scanId): array {
            $state = $this->readUnlocked();
            if (hash_equals((string)($state['scan']['id'] ?? ''), $scanId) &&
                ($state['scan']['status'] ?? '') === 'running') {
                $state['scan']['status'] = 'cancelled';
                $this->writeUnlocked($state);
            }
            return $state;
        });
    }

    private function fetchPage(int $page): array
    {
        $rateDir = dirname($this->ratePath);
        if (!is_dir($rateDir) && !mkdir($rateDir, 0775, true) && !is_dir($rateDir))
            throw new RuntimeException('Unable to prepare shared arena source rate limiter.');
        $lock = fopen($this->ratePath, 'c+');
        if ($lock === false) throw new RuntimeException('Unable to open shared arena source rate limiter.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock shared arena source rate limiter.');
            $last = (float)trim((string)stream_get_contents($lock));
            $wait = 1.0 - (microtime(true) - $last);
            if ($wait > 0) usleep((int)ceil($wait * 1000000));
            rewind($lock);
            ftruncate($lock, 0);
            fwrite($lock, sprintf('%.6f', microtime(true)));
            fflush($lock);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for arena discovery.');
        $url = self::SOURCE . ($page > 1 ? '?page=' . $page : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_TIMEOUT=>18, CURLOPT_CONNECTTIMEOUT=>6,
            CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; P2K-Standalone-ArenaDiscovery/0.3.4; admin-triggered)',
            CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9'],
            CURLOPT_ENCODING=>'', CURLOPT_MAXREDIRS=>0,
        ]);
        $html = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($html) || $status !== 200 || strlen($html) > 3000000)
            throw new RuntimeException('Chess.com upcoming arena index unavailable (HTTP '.$status.').', 502);
        require_once __DIR__ . '/ArenaDiscoveryAdminService.php';
        $parsed = ArenaDiscoveryAdminService::parseIndexHtml($html);
        if ((int)$parsed['currentPage'] !== $page)
            throw new RuntimeException('Chess.com arena pagination did not advance to page '.$page.'.', 502);
        return $parsed;
    }

    /** The index fetch occurs outside the widget-state lock. Each page advances only once. */
    public function scanPage(string $scanId, int $page, array $knownIds): array
    {
        if (!preg_match('/^[a-f0-9]{24}$/D', $scanId) || $page < 1 || $page > self::MAX_PAGES)
            throw new InvalidArgumentException('Invalid scan or page.');
        $state = $this->read();
        if (($state['scan']['id'] ?? '') !== $scanId ||
            ($state['scan']['status'] ?? '') !== 'running' ||
            (int)($state['scan']['pages'] ?? 0) + 1 !== $page)
            throw new RuntimeException('The scan is no longer active or its page is out of sequence.', 409);
        $parsed = $this->fetchPage($page);
        return $this->locked(function () use ($scanId, $page, $parsed, $knownIds): array {
            $state = $this->readUnlocked();
            if (($state['scan']['id'] ?? '') !== $scanId ||
                ($state['scan']['status'] ?? '') !== 'running' ||
                (int)($state['scan']['pages'] ?? 0) + 1 !== $page)
                throw new RuntimeException('Arena scan changed while fetching; reload its status.', 409);
            $scan =& $state['scan'];
            $organizers = [];
            foreach ($state['organizers'] as $c)
                if (is_array($c) && ($c['enabled'] ?? false) === true)
                    $organizers[(string)$c['clubId']] = (string)$c['name'];
            $known = array_fill_keys(array_map('strval', $knownIds), true);
            $seen = [];
            foreach ($scan['results'] as $row) $seen[(string)$row['arenaId']] = true;
            $now = strtotime((string)$scan['startedAt']);
            $until = (int)$scan['cutoffEpoch'];
            $beyond = false;
            foreach ($parsed['rows'] as $row) {
                $when = (int)$row['startEpoch'];
                if ($when > $until) { $beyond = true; continue; }
                $id = (string)$row['arenaId'];
                if ($when < $now || !isset($organizers[(string)$row['clubId']]) ||
                    isset($known[$id]) || isset($seen[$id])) continue;
                $row['organizer'] = $organizers[(string)$row['clubId']];
                $scan['results'][] = $row;
                $seen[$id] = true;
            }
            $scan['pages'] = $page;
            $scan['seen'] += count($parsed['rows']);
            $scan['lastSourceTime'] = end($parsed['rows'])['rawSource'] ?? null;
            $done = $beyond || $page >= (int)$parsed['totalPages'] || $page >= self::MAX_PAGES;
            if ($done) {
                $scan['status'] = 'complete';
                $scan['finishedAt'] = gmdate(DATE_ATOM);
                $scan['partial'] = !$beyond && $page === self::MAX_PAGES &&
                    $page < (int)$parsed['totalPages'];
                $scan['message'] = $beyond ? 'Seven-day horizon reached.' :
                    ($page >= (int)$parsed['totalPages'] ? 'Last source page reached.' : 'Safety page limit reached; scan is partial.');
            }
            unset($scan);
            $this->writeUnlocked($state);
            return ['scan'=>$state['scan'], 'totalPages'=>$parsed['totalPages']];
        });
    }
}
