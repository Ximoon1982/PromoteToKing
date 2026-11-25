<?php
declare(strict_types=1);
namespace TeamFrance\Leaderboard;

/**
 * ClubWarsLive adaptation of Promote to King's current MCA index parser.
 * The Chess.com club live-tournaments index is discovery evidence only.
 */
final class ArenaIndexParser
{
    /** @return array{events:list<array<string,mixed>>,has_next:bool,page_fingerprint:string} */
    public static function parse(string $html, int $page): array
    {
        $page = max(1, $page);
        $decoded = html_entity_decode(str_replace('\\/', '/', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $events = [];
        $order = [];
        $hasNext = false;

        $add = static function (array $identity, array $date) use (&$events, &$order): void {
            $id = (int)$identity['arena_id'];
            $candidate = $identity + $date;
            if (!isset($events[$id])) {
                $events[$id] = $candidate;
                $order[] = $id;
            } elseif (($events[$id]['event_date'] ?? null) === null && ($candidate['event_date'] ?? null) !== null) {
                $events[$id] = $candidate;
            }
        };

        if (class_exists(\DOMDocument::class)) {
            $previous = libxml_use_internal_errors(true);
            try {
                $dom = new \DOMDocument();
                $dom->loadHTML($decoded, LIBXML_NOWARNING | LIBXML_NOERROR);
                $xpath = new \DOMXPath($dom);

                foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
                    if (!$anchor instanceof \DOMElement) continue;
                    $href = trim($anchor->getAttribute('href'));
                    if ($href !== '' && self::isNextPageHref($href, $page, $anchor->getAttribute('rel'))) {
                        $hasNext = true;
                    }
                }

                // P2K's v2.10.9.4 corrective: tournament rows are authoritative.
                $rows = $xpath->query('//tr[.//a[contains(@href,"/tournament/live/arena/")]]') ?: [];
                foreach ($rows as $row) {
                    if (!$row instanceof \DOMElement) continue;
                    $identity = null;
                    $arenaAnchor = null;
                    foreach ($xpath->query('.//a[@href]', $row) ?: [] as $anchor) {
                        if (!$anchor instanceof \DOMElement) continue;
                        $candidate = self::identityFromHref(trim($anchor->getAttribute('href')));
                        if ($candidate === null) continue;
                        $identity = $candidate;
                        $arenaAnchor = $anchor;
                        break;
                    }
                    if ($identity === null || !$arenaAnchor instanceof \DOMElement) continue;
                    $identity['name'] = trim(preg_replace('/\s+/u', ' ', (string)$arenaAnchor->textContent) ?? '');
                    if ($identity['name'] === '') $identity['name'] = trim($arenaAnchor->getAttribute('title'));
                    $rowHtml = $dom->saveHTML($row);
                    $date = self::extractDateFromHtml(is_string($rowHtml) ? $rowHtml : (string)$row->textContent);
                    $add($identity, $date);
                }

                // Only use broad links if Chess.com stopped rendering tournament rows.
                if ($events === []) {
                    foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
                        if (!$anchor instanceof \DOMElement) continue;
                        $identity = self::identityFromHref(trim($anchor->getAttribute('href')));
                        if ($identity === null) continue;
                        $identity['name'] = trim(preg_replace('/\s+/u', ' ', (string)$anchor->textContent) ?? '');
                        $add($identity, self::unknownDate());
                    }
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        if ($events === [] && preg_match_all('~<tr\\b[^>]*>.*?</tr>~is', $decoded, $rowMatches)) {
            foreach ($rowMatches[0] as $rowHtml) {
                if (!preg_match('~(?:https?://(?:www\\.)?chess\\.com)?(/tournament/live/arena/([A-Za-z0-9_%+.,:@\\~!$&\'()*;=\\-]+))~i', $rowHtml, $m)) continue;
                $identity = self::identityFromHref((string)$m[1]);
                if ($identity === null) continue;
                if (preg_match_all('~<a\b[^>]*href=["\x27]([^"\x27]+)["\x27][^>]*>(.*?)</a>~is', $rowHtml, $anchors, PREG_SET_ORDER)) {
                    foreach ($anchors as $a) {
                        $aId = self::identityFromHref($a[1]);
                        if ($aId !== null && $aId['arena_id'] === $identity['arena_id']) {
                            $identity['name'] = trim(preg_replace('/\s+/u',' ',strip_tags($a[2])) ?? '');
                            if ($identity['name'] !== '') break;
                        }
                    }
                }
                $add($identity, self::extractDateFromHtml($rowHtml));
            }
        }

        if ($events === [] && preg_match_all(
            '~(?:https?://(?:www\\.)?chess\\.com)?(/tournament/live/arena/([A-Za-z0-9_%+.,:@\\~!$&\'()*;=\\-]+))~i',
            $decoded,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches as $match) {
                $href = (string)($match[1][0] ?? '');
                $identity = self::identityFromHref($href);
                if ($identity === null) continue;
                $offset = (int)($match[0][1] ?? 0);
                $length = strlen((string)($match[0][0] ?? ''));
                $add($identity, self::unknownDate());
            }
        }

        if (!$hasNext && preg_match_all('~href=["\\\']([^"\\\']+)["\\\']~i', $decoded, $m)) {
            foreach ($m[1] as $href) {
                if (self::isNextPageHref(html_entity_decode((string)$href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $page, '')) {
                    $hasNext = true;
                    break;
                }
            }
        }

        $ordered = [];
        foreach ($order as $id) if (isset($events[$id])) $ordered[] = $events[$id];
        // The modern Chess.com index often omits a next-page anchor although a
        // full page of 25 tournaments implies more pages. Preserve that signal;
        // callers detect repeated page identities before moving forward.
        $ids = array_map(static fn(array $e): string => (string)($e['arena_id'] ?? ''), $ordered);
        return [
            'events' => $ordered,
            'has_next' => $hasNext || count($ordered) >= 25,
            'page_fingerprint' => hash('sha256', implode(',', $ids)),
        ];
    }

    /** @return array{arena_id:int,arena_slug:string,arena_url:string,csv_url:string}|null */
    public static function identityFromHref(string $href): ?array
    {
        $href = html_entity_decode(str_replace('\\/', '/', trim($href)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = (string)(parse_url($href, PHP_URL_PATH) ?: $href);
        if (!preg_match('~/tournament/live/arena/([^/?#]+)~i', $path, $m)) return null;
        $slug = rawurldecode(trim((string)$m[1], '/'));
        $slug = preg_replace('/\.csv$/i', '', $slug) ?? $slug;
        if ($slug === '' || !preg_match('/(?:^|-)([1-9]\d*)$/D', $slug, $idMatch)) return null;
        $id = (int)$idMatch[1];
        if ($id <= 0) return null;
        $arenaUrl = 'https://www.chess.com/tournament/live/arena/' . str_replace('%2D', '-', rawurlencode($slug));
        return [
            'arena_id' => $id,
            'arena_slug' => $slug,
            'arena_url' => $arenaUrl,
            'csv_url' => $arenaUrl . '.csv',
        ];
    }

    /** @return array{event_start_at:?string,event_date:?string,date_precision:string} */
    public static function extractDateFromText(string $text): array
    {
        $decoded = html_entity_decode(str_replace(['\\/','\\"'], ['/','"'], $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bounded = preg_replace('~<[^>]*>~u', ' ', $decoded) ?? $decoded;
        $bounded = str_replace(["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89"], ' ', $bounded);
        $plain = trim(preg_replace('/\s+/u', ' ', $bounded) ?? '');
        if ($plain === '') return self::unknownDate();

        if (preg_match(
            '~\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},\s+20\d{2}(?:,\s+\d{1,2}:\d{2}\s*(?:AM|PM))?~i',
            $plain,
            $m
        )) {
            $stamp = strtotime($m[0] . ' UTC');
            if ($stamp !== false && self::plausible($stamp)) {
                return [
                    'event_start_at' => gmdate('Y-m-d H:i:s', $stamp),
                    'event_date' => gmdate('Y-m-d', $stamp),
                    'date_precision' => str_contains($m[0], ':') ? 'index-visible-time' : 'index-visible-date',
                ];
            }
        }
        return self::unknownDate();
    }

    /** @return array{event_start_at:?string,event_date:?string,date_precision:string} */
    public static function extractDateFromHtml(string $html): array
    {
        $decoded = html_entity_decode(str_replace(['\\/','\\"'], ['/','"'], $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $visible = self::extractDateFromText($decoded);
        if ($visible['event_date'] !== null) return $visible;

        $candidates = [];
        if (preg_match_all('~["\'](?:startTime|start_time|startDate|start_date)["\']\s*:\s*["\']([^"\']+)["\']~i', $decoded, $m)) {
            $candidates = array_merge($candidates, $m[1]);
        }
        if (preg_match_all('~["\'](?:startTime|start_time)["\']\s*:\s*(\d{10,13})~i', $decoded, $m)) {
            $candidates = array_merge($candidates, $m[1]);
        }
        if (preg_match_all('~\bdata-start-(?:time|date)=["\']([^"\']+)["\']~i', $decoded, $m)) {
            $candidates = array_merge($candidates, $m[1]);
        }
        // Current Chess.com arena pages also expose data-tournament-start="YYYY-MM-DD-HH-MM".
        if (preg_match_all('~\bdata-tournament-start=["\'](\d{4}-\d{2}-\d{2})(?:-\d{2}-\d{2})?["\']~i', $decoded, $m)) {
            $candidates = array_merge($candidates, $m[1]);
        }
        foreach ($candidates as $candidate) {
            $stamp = self::candidateStamp((string)$candidate);
            if ($stamp !== null) {
                return [
                    'event_start_at' => gmdate('Y-m-d H:i:s', $stamp),
                    'event_date' => gmdate('Y-m-d', $stamp),
                    'date_precision' => 'arena-machine',
                ];
            }
        }

        if (preg_match_all('~<time\b[^>]*\bdatetime=["\']([^"\']+)["\']~i', $decoded, $m)) {
            foreach ($m[1] as $candidate) {
                $stamp = self::candidateStamp((string)$candidate);
                if ($stamp !== null) {
                    return [
                        'event_start_at' => gmdate('Y-m-d H:i:s', $stamp),
                        'event_date' => gmdate('Y-m-d', $stamp),
                        'date_precision' => 'arena-machine',
                    ];
                }
            }
        }
        return self::unknownDate();
    }

    private static function candidateStamp(string $candidate): ?int
    {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($candidate === '') return null;

        // Chess.com machine attributes use an exact calendar form such as
        // data-tournament-start="2026-07-04-17-30". Parse the calendar fields
        // deterministically in UTC instead of routing the extracted YYYY-MM-DD
        // through strtotime(), whose host/date-parser behavior is unnecessary here.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:-(\d{2})-(\d{2}))?$/D', $candidate, $m)) {
            $year = (int)$m[1];
            $month = (int)$m[2];
            $day = (int)$m[3];
            $hour = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0;
            $minute = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0;
            if (!checkdate($month, $day, $year) || $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                return null;
            }
            $stamp = gmmktime($hour, $minute, 0, $month, $day, $year);
            return self::plausible($stamp) ? $stamp : null;
        }

        if (ctype_digit($candidate)) {
            $stamp = (int)$candidate;
            if ($stamp > 20000000000) $stamp = (int)floor($stamp / 1000);
        } else {
            $parsed = strtotime($candidate);
            if ($parsed === false) return null;
            $stamp = (int)$parsed;
        }
        return self::plausible($stamp) ? $stamp : null;
    }

    /** @return array{event_start_at:?string,event_date:?string,date_precision:string} */
    private static function dateNearOffset(string $html, int $offset, int $length): array
    {
        foreach ([['<tr','</tr>'],['<li','</li>'],['<article','</article>']] as [$open,$close]) {
            $prefix = substr($html, 0, max(0, $offset));
            $start = strripos($prefix, $open);
            if ($start === false) continue;
            $closeAt = stripos($html, $close, $offset + max(0, $length));
            if ($closeAt === false) continue;
            $end = $closeAt + strlen($close);
            if ($end <= $start || ($end - $start) > 30000) continue;
            $date = self::extractDateFromText(substr($html, $start, $end - $start));
            if ($date['event_date'] !== null) return $date;
        }
        $start = max(0, $offset - 400);
        $window = substr($html, $start, max(0, $length) + 2200);
        $date = self::extractDateFromText($window);
        return $date['event_date'] !== null ? $date : self::unknownDate();
    }

    /** @return array{event_start_at:?string,event_date:?string,date_precision:string} */
    private static function dateNearNode(\DOMElement $anchor): array
    {
        $node = $anchor;
        for ($depth = 0; $depth < 6 && $node instanceof \DOMElement; $depth++) {
            foreach ($node->getElementsByTagName('time') as $time) {
                if (!$time instanceof \DOMElement) continue;
                $datetime = trim($time->getAttribute('datetime'));
                if ($datetime === '') continue;
                $stamp = strtotime($datetime);
                if ($stamp !== false && self::plausible($stamp)) {
                    return [
                        'event_start_at' => gmdate('Y-m-d H:i:s', $stamp),
                        'event_date' => gmdate('Y-m-d', $stamp),
                        'date_precision' => 'index-machine',
                    ];
                }
            }
            $nodeHtml = $node->ownerDocument instanceof \DOMDocument ? $node->ownerDocument->saveHTML($node) : false;
            $date = self::extractDateFromText(is_string($nodeHtml) ? $nodeHtml : (string)$node->textContent);
            if ($date['event_date'] !== null) return $date;
            $parent = $node->parentNode;
            $node = $parent instanceof \DOMElement ? $parent : null;
        }
        return self::unknownDate();
    }

    private static function isNextPageHref(string $href, int $page, string $rel): bool
    {
        if (stripos($rel, 'next') !== false) return true;
        $query = (string)(parse_url(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY) ?: '');
        if ($query === '') return false;
        parse_str($query, $params);
        return isset($params['page']) && (int)$params['page'] === $page + 1;
    }

    private static function plausible(int $stamp): bool
    {
        return $stamp >= 1420070400 && $stamp <= time() + 86400;
    }

    /** @return array{event_start_at:null,event_date:null,date_precision:string} */
    private static function unknownDate(): array
    {
        return ['event_start_at' => null, 'event_date' => null, 'date_precision' => 'unknown'];
    }
}
