<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Canonical Members Insights table projection.
 *
 * Ranking is deliberately calculated before display filters/pagination so a search
 * never turns the searched member into #1. Daily Points are the primary key,
 * net wins (wins-losses) the first tie breaker, and username_key the stable final
 * tie breaker. The same projection feeds the browser table and CSV export.
 */
final class MemberInsightsTableService
{
    private const EVOLUTION = [
        '1w' => ['label' => '1 week', 'modify' => '-7 days'],
        '1m' => ['label' => '1 month', 'modify' => '-1 month'],
        '3m' => ['label' => '3 months', 'modify' => '-3 months'],
        '1y' => ['label' => '1 year', 'modify' => '-1 year'],
    ];

    public function __construct(
        private Repository $repository,
        private ResponseCache $cache,
        private string $generationToken
    ) {}

    public function table(string $clubSlug, array $options, bool $paginate = true): array
    {
        $clubSlug = strtolower(trim($clubSlug));
        $evolutionKey = strtolower(trim((string)($options['evolution'] ?? '1m')));
        if (!isset(self::EVOLUTION[$evolutionKey])) $evolutionKey = '1m';

        $start = trim((string)($options['start'] ?? ''));
        $end = trim((string)($options['end'] ?? ''));
        $base = $this->baseRows($clubSlug, $start, $end);
        $rows = is_array($base['rows'] ?? null) ? array_values($base['rows']) : [];

        $rows = self::decorateAndRankRows($rows);
        [$comparisonEnd, $comparisonStart] = $this->comparisonRange($start, $end, $evolutionKey);
        $previousPositions = [];
        $comparisonAvailable = $comparisonEnd !== null && ($comparisonStart === null || $comparisonStart <= $comparisonEnd);
        if ($comparisonAvailable) {
            $previous = $this->baseRows($clubSlug, $comparisonStart ?? '', $comparisonEnd);
            $previousRows = is_array($previous['rows'] ?? null) ? array_values($previous['rows']) : [];
            $joinedAtByKey = $this->joinedAtMap($clubSlug, $previousRows);
            $previousPositions = self::positionMap($previousRows, $comparisonEnd, $joinedAtByKey);
        }

        foreach ($rows as &$row) {
            $key = (string)($row['username_key'] ?? p2k_tp_username_key((string)($row['username'] ?? '')));
            $currentPosition = isset($row['team_position']) && $row['team_position'] !== null ? (int)$row['team_position'] : null;
            $previousPosition = $currentPosition !== null && array_key_exists($key, $previousPositions)
                ? (int)$previousPositions[$key]
                : null;
            $row['previous_position'] = $previousPosition;
            $row['position_change'] = ($currentPosition !== null && $previousPosition !== null)
                ? $previousPosition - $currentPosition
                : null;
            $row['position_comparison_available'] = $comparisonAvailable;
            $row['position_new'] = $comparisonAvailable && $currentPosition !== null && $previousPosition === null;
        }
        unset($row);

        $rows = $this->applyDisplayFilters($rows, $options);
        $rows = $this->sortRows($rows, (string)($options['sort'] ?? 'points'), (string)($options['direction'] ?? 'desc'));

        $totalRows = count($rows);
        $pageSize = max(10, min(100, (int)($options['page_size'] ?? 25)));
        $page = max(1, (int)($options['page'] ?? 1));
        $totalPages = max(1, (int)ceil($totalRows / $pageSize));
        $page = min($page, $totalPages);
        if ($paginate) $rows = array_slice($rows, ($page - 1) * $pageSize, $pageSize);

        return [
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total_rows' => $totalRows,
                'total_pages' => $totalPages,
            ],
            'range' => [
                'start' => $start !== '' ? $start : null,
                'end' => $end !== '' ? $end : null,
            ],
            'evolution' => [
                'key' => $evolutionKey,
                'label' => self::EVOLUTION[$evolutionKey]['label'],
                'comparison_start' => $comparisonStart,
                'comparison_end' => $comparisonEnd,
            ],
        ];
    }

    private function baseRows(string $clubSlug, string $start, string $end): array
    {
        $baseOptions = [
            'page' => 1,
            'page_size' => 100000,
            'search' => '',
            'filter' => 'all',
            'sort' => 'points',
            'direction' => 'desc',
            'start' => $start,
            'end' => $end,
            'usernames' => '',
            'activity_status' => '',
            '_unpaged' => true,
        ];
        $rangeKey = hash('sha256', json_encode([$start, $end], JSON_UNESCAPED_SLASHES));
        $cacheKey = 'members-range-base|' . $clubSlug . '|' . $this->generationToken . '|' . $rangeKey;
        $entry = $this->cache->remember(
            $cacheKey,
            180,
            fn() => $this->repository->publicMemberInsights($clubSlug, $baseOptions),
            900
        );
        return is_array($entry['payload'] ?? null) ? $entry['payload'] : [];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,?string> */
    private function joinedAtMap(string $clubSlug, array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $key = (string)($row['username_key'] ?? p2k_tp_username_key((string)($row['username'] ?? '')));
            if ($key !== '') $keys[$key] = true;
        }
        $keys = array_keys($keys);
        if ($keys === []) return [];

        $out = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->repository->core()->prepare(
                "SELECT username_key,joined_at FROM p2k_tp_members WHERE club_slug=? AND username_key IN ({$marks})"
            );
            $q->execute(array_merge([$clubSlug], $chunk));
            foreach ($q->fetchAll() ?: [] as $row) {
                $out[(string)$row['username_key']] = $row['joined_at'] === null ? null : (string)$row['joined_at'];
            }
        }
        return $out;
    }

    private function comparisonRange(string $start, string $end, string $evolutionKey): array
    {
        $utc = new DateTimeZone('UTC');
        $anchor = $end !== ''
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $end, $utc)
            : new DateTimeImmutable('today', $utc);
        if (!$anchor) return [null, $start !== '' ? $start : null];
        $comparisonEnd = $anchor->modify(self::EVOLUTION[$evolutionKey]['modify'])->format('Y-m-d');
        return [$comparisonEnd, $start !== '' ? $start : null];
    }

    /** Normalize result-derived fields and assign globally stable current-member positions. */
    public static function decorateAndRankRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $games = max(0, (int)($row['games'] ?? 0));
            $wins = max(0, (int)($row['wins'] ?? 0));
            $draws = max(0, (int)($row['draws'] ?? 0));
            $losses = max(0, (int)($row['losses'] ?? 0));
            $covered = $wins + $draws + $losses;
            $points = (float)($row['points'] ?? 0);
            $row['result_covered'] = $covered;
            $row['result_missing'] = max(0, $games - $covered);
            $row['result_coverage_percent'] = $games > 0 ? round(100 * $covered / $games, 1) : 0.0;
            $row['win_rate'] = $covered > 0 ? round(100 * $wins / $covered, 1) : null;
            $row['net_wins'] = $wins - $losses;
            $row['points_per_game'] = $games > 0 ? round($points / $games, 3) : 0.0;
            $row['team_position'] = null;
        }
        unset($row);

        $rankedIndexes = [];
        foreach ($rows as $index => $row) if (!empty($row['current_member'])) $rankedIndexes[] = $index;
        usort($rankedIndexes, static function (int $left, int $right) use ($rows): int {
            $a = $rows[$left]; $b = $rows[$right];
            $cmp = ((float)($b['points'] ?? 0)) <=> ((float)($a['points'] ?? 0));
            if ($cmp !== 0) return $cmp;
            $cmp = ((int)($b['net_wins'] ?? 0)) <=> ((int)($a['net_wins'] ?? 0));
            if ($cmp !== 0) return $cmp;
            return strcmp(
                (string)($a['username_key'] ?? p2k_tp_username_key((string)($a['username'] ?? ''))),
                (string)($b['username_key'] ?? p2k_tp_username_key((string)($b['username'] ?? '')))
            );
        });
        foreach ($rankedIndexes as $offset => $index) $rows[$index]['team_position'] = $offset + 1;
        return $rows;
    }

    /**
     * Build the comparison ranking from historical evidence, not database-import time.
     * A point event on/before the cutoff is strongest evidence; authoritative joined_at
     * is next; first_seen_at is only a fallback when neither historical signal exists.
     *
     * @param array<string,?string> $joinedAtByKey
     */
    public static function positionMap(array $rows, ?string $cutoffDate = null, array $joinedAtByKey = []): array
    {
        $rows = self::decorateAndRankRows($rows);
        $cutoffTs = $cutoffDate !== null ? strtotime($cutoffDate . ' 23:59:59 UTC') : false;
        $eligible = [];
        foreach ($rows as $row) {
            if (empty($row['current_member'])) continue;
            if ($cutoffTs !== false) {
                $key = (string)($row['username_key'] ?? p2k_tp_username_key((string)($row['username'] ?? '')));
                $firstActivity = strtotime((string)($row['first_activity'] ?? '') . ' UTC');
                $joinedAt = strtotime((string)($joinedAtByKey[$key] ?? '') . ' UTC');
                $firstSeen = strtotime((string)($row['first_seen_at'] ?? '') . ' UTC');

                $hadHistoricalActivity = $firstActivity !== false && $firstActivity <= $cutoffTs;
                $wasAuthoritativelyJoined = $joinedAt !== false && $joinedAt <= $cutoffTs;
                $fallbackSeen = $firstActivity === false && $joinedAt === false
                    && $firstSeen !== false && $firstSeen <= $cutoffTs;
                if (!$hadHistoricalActivity && !$wasAuthoritativelyJoined && !$fallbackSeen) continue;
            }
            $eligible[] = $row;
        }
        usort($eligible, static function (array $a, array $b): int {
            $cmp = ((float)($b['points'] ?? 0)) <=> ((float)($a['points'] ?? 0));
            if ($cmp !== 0) return $cmp;
            $cmp = ((int)($b['net_wins'] ?? 0)) <=> ((int)($a['net_wins'] ?? 0));
            if ($cmp !== 0) return $cmp;
            return strcmp(
                (string)($a['username_key'] ?? p2k_tp_username_key((string)($a['username'] ?? ''))),
                (string)($b['username_key'] ?? p2k_tp_username_key((string)($b['username'] ?? '')))
            );
        });
        $map = [];
        foreach ($eligible as $index => $row) {
            $key = (string)($row['username_key'] ?? p2k_tp_username_key((string)($row['username'] ?? '')));
            if ($key !== '') $map[$key] = $index + 1;
        }
        return $map;
    }

    private function applyDisplayFilters(array $rows, array $options): array
    {
        $search = strtolower(trim((string)($options['search'] ?? '')));
        $filter = strtolower(trim((string)($options['filter'] ?? 'current')));
        if (!in_array($filter, ['all','current','former','active','playing','new','milestones'], true)) $filter = 'current';
        $wanted = array_values(array_unique(array_filter(array_map(
            static fn(string $value): string => p2k_tp_username_key($value),
            explode(',', (string)($options['usernames'] ?? ''))
        ))));
        if (count($wanted) > 200) $wanted = array_slice($wanted, 0, 200);
        $activityStatuses = array_values(array_intersect(
            ['active','cooling','inactive','dormant','unknown'],
            array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower((string)($options['activity_status'] ?? '')))))))
        ));

        return array_values(array_filter($rows, static function (array $row) use ($search, $filter, $wanted, $activityStatuses): bool {
            $key = (string)($row['username_key'] ?? p2k_tp_username_key((string)($row['username'] ?? '')));
            if ($search !== '' && !str_contains(strtolower((string)($row['username'] ?? '')), $search)) return false;
            if ($wanted !== [] && !in_array($key, $wanted, true)) return false;
            $current = !empty($row['current_member']);
            $games = (int)($row['games'] ?? 0);
            $currentMatches = (int)($row['current_matches'] ?? 0);
            if ($filter === 'current' && !$current) return false;
            if ($filter === 'former' && $current) return false;
            if ($filter === 'active' && $games === 0) return false;
            if ($filter === 'playing' && $currentMatches === 0) return false;
            if ($filter === 'new' && (strtotime((string)($row['first_seen_at'] ?? '') . ' UTC') ?: 0) < time() - 30 * 86400) return false;
            if ($filter === 'milestones' && (int)($row['achievement_count'] ?? 0) <= 0 && (float)($row['points'] ?? 0) <= 0 && (float)($row['live_points'] ?? 0) <= 0) return false;
            if ($activityStatuses !== [] && !in_array((string)($row['activity_status'] ?? 'unknown'), $activityStatuses, true)) return false;
            return true;
        }));
    }

    private function sortRows(array $rows, string $sort, string $direction): array
    {
        $sort = strtolower(trim($sort));
        $valid = [
            'team_position','position_change','username','points','matches','games','wins','draws','losses','net_wins',
            'win_rate','result_coverage_percent','points_per_game','first_activity','last_activity','current_matches',
            'live_points','achievement_count','daily_rating','chess960_rating','last_standard_game_at','last_chess960_game_at'
        ];
        if (!in_array($sort, $valid, true)) $sort = 'points';
        $directionFactor = strtolower(trim($direction)) === 'asc' ? 1 : -1;
        usort($rows, static function (array $a, array $b) use ($sort, $directionFactor): int {
            $av = $a[$sort] ?? null; $bv = $b[$sort] ?? null;
            if ($av === null && $bv !== null) return 1;
            if ($bv === null && $av !== null) return -1;
            if ($av === null && $bv === null) return strcasecmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
            $cmp = is_numeric($av) && is_numeric($bv) ? ($av <=> $bv) : strcasecmp((string)$av, (string)$bv);
            if ($cmp === 0) return strcasecmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
            return $directionFactor * $cmp;
        });
        return $rows;
    }
}
