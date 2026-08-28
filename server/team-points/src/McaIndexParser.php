<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

/**
 * Parser for the legacy Chess.com club live-tournaments index.
 *
 * The index is discovery evidence: it provides arena identities and, when
 * present, the arena start timestamp used as a fallback by v2.10.6.25.
 */
final class McaIndexParser
{
    /** @return array{events:list<array<string,mixed>>,has_next:bool} */
    public static function parse(string $html, int $page): array
    {
        $page = max(1, $page);
        $decoded = html_entity_decode(str_replace('\\/', '/', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $events = [];
        $hasNext = false;

        if (class_exists(\DOMDocument::class)) {
            $previous = libxml_use_internal_errors(true);
            try {
                $dom = new \DOMDocument();
                $dom->loadHTML($decoded, LIBXML_NOWARNING | LIBXML_NOERROR);
                $xpath = new \DOMXPath($dom);
                foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
                    if (!$anchor instanceof \DOMElement) continue;
                    $href = trim($anchor->getAttribute('href'));
                    if ($href === '') continue;
                    if (self::isNextPageHref($href, $page, $anchor->getAttribute('rel'))) {
                        $hasNext = true;
                    }
                    $identity = self::identityFromHref($href);
                    if ($identity === null) continue;
                    $date = self::dateNearNode($anchor);
                    $events[$identity['arena_id']] = $identity + $date;
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        // Defensive regex fallback for environments/builds where DOM parsing did
        // not expose the links (or HTML is embedded inside script state).
        if ($events === [] && preg_match_all(
            '~(?:https?://(?:www\.)?chess\.com)?(/tournament/live/arena/([A-Za-z0-9_%+.,:@\~!$&\'()*;=\-]+))~i',
            $decoded,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $identity = self::identityFromHref((string)$match[1]);
                if ($identity === null) continue;
                $events[$identity['arena_id']] = $identity + [
                    'event_start_at' => null,
                    'event_date' => null,
                    'date_precision' => 'unknown',
                ];
            }
        }

        if (!$hasNext && preg_match_all('~href=["\']([^"\']+)["\']~i', $decoded, $m)) {
            foreach ($m[1] as $href) {
                if (self::isNextPageHref(html_entity_decode((string)$href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $page, '')) {
                    $hasNext = true;
                    break;
                }
            }
        }

        krsort($events, SORT_NUMERIC);
        return ['events' => array_values($events), 'has_next' => $hasNext];
    }

    /** @return array{arena_id:int,arena_slug:string,arena_url:string,csv_url:string}|null */
    public static function identityFromHref(string $href): ?array
    {
        $href = html_entity_decode(str_replace('\\/', '/', trim($href)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = (string)(parse_url($href, PHP_URL_PATH) ?: $href);
        if (!preg_match('~/tournament/live/arena/([^/?#]+)~i', $path, $m)) return null;
        $slug = rawurldecode(trim((string)$m[1], '/'));
        $slug = preg_replace('/\.csv$/i', '', $slug) ?? $slug;
        if ($slug === '' || !preg_match('/-(\d+)$/', $slug, $idMatch)) return null;
        $id = (int)$idMatch[1];
        if ($id <= 0) return null;
        $arenaUrl = 'https://www.chess.com/tournament/live/arena/' . rawurlencode($slug);
        // Keep slashes/dashes readable; rawurlencode is only used for unsafe bytes.
        $arenaUrl = str_replace('%2D', '-', $arenaUrl);
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
        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
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

    private static function dateNearNode(\DOMElement $anchor): array
    {
        $node = $anchor;
        for ($depth = 0; $depth < 6 && $node instanceof \DOMElement; $depth++) {
            // Prefer an explicit machine timestamp local to the same tournament row/card.
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
            $date = self::extractDateFromText((string)$node->textContent);
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
        return $stamp >= strtotime('2015-01-01 UTC') && $stamp <= time() + 86400;
    }

    /** @return array{event_start_at:null,event_date:null,date_precision:string} */
    private static function unknownDate(): array
    {
        return ['event_start_at' => null, 'event_date' => null, 'date_precision' => 'unknown'];
    }
}
