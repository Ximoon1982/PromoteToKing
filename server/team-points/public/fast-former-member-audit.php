<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\Repository;

@set_time_limit(55);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function fast_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function fast_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException('The request body must be a JSON object.');
    }
    return $value;
}

function fast_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

function fast_authorize(array $config): void
{
    $expected = trim((string)($config['app']['admin_token'] ?? ''));
    if ($expected === '' || str_starts_with($expected, 'CHANGE_')) {
        throw new RuntimeException('The Team Points administrator token is not configured.');
    }
    $provided = fast_header('X-P2K-Admin-Token');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        fast_json(['ok' => false, 'error' => 'Administrator authorization failed.'], 403);
    }
}

function fast_club_team(array $match, string $clubSlug): ?array
{
    $teams = is_array($match['teams'] ?? null) ? $match['teams'] : [];
    $apiClub = 'https://api.chess.com/pub/club/' . strtolower($clubSlug);
    $webClub = 'https://www.chess.com/club/' . strtolower($clubSlug);
    foreach ($teams as $team) {
        if (!is_array($team)) {
            continue;
        }
        foreach (['@id', 'url'] as $field) {
            $value = rtrim(strtolower(trim((string)($team[$field] ?? ''))), '/');
            if ($value === $apiClub || $value === $webClub) {
                return $team;
            }
            $path = trim((string)(parse_url($value, PHP_URL_PATH) ?: ''), '/');
            $parts = $path === '' ? [] : explode('/', $path);
            if ($parts !== [] && strtolower((string)end($parts)) === strtolower($clubSlug)) {
                return $team;
            }
        }
    }
    return null;
}

function fast_authoritative(array $match, string $clubSlug): array
{
    $status = strtolower(trim((string)($match['status'] ?? 'unknown')));
    $boards = (int)($match['boards'] ?? 0);
    $team = fast_club_team($match, $clubSlug);
    if ($team === null) {
        return ['ok' => false, 'status' => $status, 'reason' => 'P2K team not found in the match payload.'];
    }
    if ($boards <= 0 || !is_numeric($team['score'] ?? null)) {
        return ['ok' => false, 'status' => $status, 'reason' => 'The match payload has no usable board count or P2K score.'];
    }
    $players = is_array($team['players'] ?? null) ? $team['players'] : [];
    if ($players !== [] && count($players) !== $boards) {
        return [
            'ok' => false,
            'status' => $status,
            'reason' => 'The authoritative P2K player count differs from the match board count.',
            'boards' => $boards,
            'players' => count($players),
        ];
    }
    $score = (float)$team['score'];
    if ($score > $boards) {
        $result = 'win';
        $competitionPoints = 5 * $boards;
    } elseif (abs($score - $boards) < 0.001) {
        $result = 'draw';
        $competitionPoints = 2 * $boards;
    } else {
        $result = 'loss';
        $competitionPoints = 0;
    }
    return [
        'ok' => true,
        'status' => $status,
        'boards' => $boards,
        'game_count' => 2 * $boards,
        'team_score' => $score,
        'result' => $result,
        'competition_points' => $competitionPoints,
        'team_players' => count($players),
        'match_name' => trim((string)($match['name'] ?? '')),
        'match_url' => trim((string)($match['url'] ?? '')),
    ];
}

function fast_candidate(PDO $pdo, string $clubSlug, int $matchId): ?array
{
    $query = $pdo->prepare(
        "SELECT s.match_id,s.finalized_at,s.board_count,s.game_count,s.team_score,s.result,s.competition_points,
                COUNT(DISTINCT p.username_key) AS former_member_count,
                GROUP_CONCAT(DISTINCT p.username ORDER BY p.username SEPARATOR ', ') AS former_members
         FROM p2k_tp_match_summaries s
         JOIN p2k_tp_participations p
           ON p.club_slug=s.club_slug AND p.match_id=s.match_id
         JOIN p2k_tp_members m
           ON m.club_slug=p.club_slug AND m.username_key=p.username_key AND m.current_member=0
         WHERE s.club_slug=? AND s.match_id=?
         GROUP BY s.club_slug,s.match_id,s.finalized_at,s.board_count,s.game_count,s.team_score,s.result,s.competition_points
         LIMIT 1"
    );
    $query->execute([$clubSlug, $matchId]);
    $row = $query->fetch();
    return is_array($row) ? $row : null;
}

function fast_summary_details(PDO $pdo, string $clubSlug, int $matchId): ?array
{
    $query = $pdo->prepare(
        "SELECT s.*,
            (SELECT COUNT(*) FROM p2k_tp_participations p WHERE p.club_slug=s.club_slug AND p.match_id=s.match_id) AS current_boards,
            (SELECT COUNT(*) FROM p2k_tp_point_events e WHERE e.club_slug=s.club_slug AND e.match_id=s.match_id) AS current_games,
            (SELECT COALESCE(SUM(e.points),0) FROM p2k_tp_point_events e WHERE e.club_slug=s.club_slug AND e.match_id=s.match_id) AS current_score,
            (SELECT COUNT(*) FROM p2k_tp_board_states b WHERE b.club_slug=s.club_slug AND b.match_id=s.match_id AND b.source_bucket<>'finished') AS non_finished_source_boards,
            (SELECT COUNT(*) FROM p2k_tp_board_states b WHERE b.club_slug=s.club_slug AND b.match_id=s.match_id AND (b.state<>'complete_immutable' OR b.finished_game_count<2)) AS incomplete_boards,
            (SELECT COUNT(*) FROM p2k_tp_participations p WHERE p.club_slug=s.club_slug AND p.match_id=s.match_id AND p.first_seen_at>s.finalized_at) AS boards_discovered_late
         FROM p2k_tp_match_summaries s
         WHERE s.club_slug=? AND s.match_id=? LIMIT 1"
    );
    $query->execute([$clubSlug, $matchId]);
    $row = $query->fetch();
    return is_array($row) ? $row : null;
}

function fast_board_states(PDO $pdo, string $clubSlug, int $matchId): array
{
    $query = $pdo->prepare(
        "SELECT username_key,board_url,source_bucket,state,finished_game_count,next_check_at,completed_at
         FROM p2k_tp_board_states WHERE club_slug=? AND match_id=? ORDER BY username_key,board_url"
    );
    $query->execute([$clubSlug, $matchId]);
    return $query->fetchAll() ?: [];
}

function fast_rebuild_totals(PDO $pdo, string $clubSlug): void
{
    // v2.8.0: Analytics totals are rebuilt lazily from canonical Core facts.
}

function fast_log_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS p2k_tp_consistency_repairs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            repair_run_id CHAR(36) NOT NULL,
            club_slug VARCHAR(120) NOT NULL,
            match_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(24) NOT NULL,
            reason TEXT NULL,
            before_json MEDIUMTEXT NULL,
            after_json MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_tp_consistency_run (repair_run_id,id),
            KEY idx_tp_consistency_match (club_slug,match_id,created_at)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

$action = strtolower(trim((string)($_GET['action'] ?? '')));
if ($action !== '') {
    try {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            fast_json(['ok' => false, 'error' => 'Use POST for audit and repair actions.'], 405);
        }
        $config = p2k_tp_config();
        fast_authorize($config);
        $clubSlug = strtolower(trim((string)($config['app']['club_slug'] ?? 'promote-to-king')));
        $pdo = Database::connection();
        $repository = new Repository($pdo);
        if (!$repository->schemaInstalled()) {
            fast_json(['ok' => false, 'error' => 'The Team Points schema is not installed.'], 503);
        }
        $body = fast_body();

        if ($action === 'audit') {
            $cached = $pdo->prepare('SELECT * FROM p2k_tp_club_totals WHERE club_slug=? LIMIT 1');
            $cached->execute([$clubSlug]);
            $cachedRow = $cached->fetch() ?: [];

            $calculated = $pdo->prepare(
                "SELECT COUNT(*) AS finished_matches,COALESCE(SUM(board_count),0) AS finished_boards,
                        COALESCE(SUM(game_count),0) AS finished_games,COALESCE(SUM(competition_points),0) AS club_points,
                        COALESCE(SUM(result='win'),0) AS won_matches,COALESCE(SUM(result='draw'),0) AS drawn_matches,
                        COALESCE(SUM(result='loss'),0) AS lost_matches
                 FROM p2k_tp_match_summaries WHERE club_slug=?"
            );
            $calculated->execute([$clubSlug]);
            $calculatedRow = $calculated->fetch() ?: [];

            $candidateStats = $pdo->prepare(
                "SELECT COUNT(*) AS candidate_matches,
                        COALESCE(SUM(candidate.board_count),0) AS candidate_boards,
                        COALESCE(SUM(candidate.competition_points),0) AS candidate_points,
                        COALESCE(SUM(candidate.former_member_count),0) AS former_member_participations
                 FROM (
                    SELECT s.match_id,s.board_count,s.competition_points,COUNT(DISTINCT p.username_key) AS former_member_count
                    FROM p2k_tp_match_summaries s
                    JOIN p2k_tp_participations p ON p.club_slug=s.club_slug AND p.match_id=s.match_id
                    JOIN p2k_tp_members m ON m.club_slug=p.club_slug AND m.username_key=p.username_key AND m.current_member=0
                    WHERE s.club_slug=?
                    GROUP BY s.match_id,s.board_count,s.competition_points
                 ) candidate"
            );
            $candidateStats->execute([$clubSlug]);
            $stats = $candidateStats->fetch() ?: [];

            $preview = $pdo->prepare(
                "SELECT s.match_id,s.finalized_at,s.board_count,s.team_score,s.competition_points,
                        COUNT(DISTINCT p.username_key) AS former_member_count,
                        GROUP_CONCAT(DISTINCT p.username ORDER BY p.username SEPARATOR ', ') AS former_members
                 FROM p2k_tp_match_summaries s
                 JOIN p2k_tp_participations p ON p.club_slug=s.club_slug AND p.match_id=s.match_id
                 JOIN p2k_tp_members m ON m.club_slug=p.club_slug AND m.username_key=p.username_key AND m.current_member=0
                 WHERE s.club_slug=?
                 GROUP BY s.match_id,s.finalized_at,s.board_count,s.team_score,s.competition_points
                 ORDER BY s.finalized_at DESC,s.match_id DESC LIMIT 1000"
            );
            $preview->execute([$clubSlug]);

            fast_json([
                'ok' => true,
                'club_slug' => $clubSlug,
                'cached_totals' => $cachedRow,
                'calculated_totals' => $calculatedRow,
                'candidate_stats' => $stats,
                'candidates' => $preview->fetchAll(),
            ]);
        }

        if ($action === 'ids') {
            $offset = max(0, (int)($body['offset'] ?? 0));
            $limit = max(1, min(500, (int)($body['limit'] ?? 250)));
            $query = $pdo->prepare(
                "SELECT s.match_id
                 FROM p2k_tp_match_summaries s
                 WHERE s.club_slug=?
                   AND EXISTS (
                       SELECT 1
                       FROM p2k_tp_participations p
                       JOIN p2k_tp_members m
                         ON m.club_slug=p.club_slug AND m.username_key=p.username_key AND m.current_member=0
                       WHERE p.club_slug=s.club_slug AND p.match_id=s.match_id
                   )
                 ORDER BY s.finalized_at DESC,s.match_id DESC
                 LIMIT {$limit} OFFSET {$offset}"
            );
            $query->execute([$clubSlug]);
            $ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN) ?: []);
            fast_json(['ok' => true, 'ids' => $ids, 'offset' => $offset, 'limit' => $limit]);
        }

        if ($action === 'validate') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($body['match_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
            if ($ids === [] || count($ids) > 2) {
                fast_json(['ok' => false, 'error' => 'Provide one or two candidate match IDs.'], 400);
            }
            $api = new ChessApi($repository);
            $rows = [];
            foreach ($ids as $matchId) {
                $candidate = fast_candidate($pdo, $clubSlug, $matchId);
                $summary = fast_summary_details($pdo, $clubSlug, $matchId);
                if ($candidate === null || $summary === null) {
                    $rows[] = [
                        'match_id' => $matchId,
                        'summary' => $summary,
                        'candidate' => $candidate,
                        'authoritative' => ['ok' => false],
                        'proposed_action' => 'review',
                        'reason' => 'The match is no longer a fast-audit candidate or its summary no longer exists.',
                    ];
                    continue;
                }
                try {
                    // Force a fresh status request; a cached response must never validate a finished summary.
                    $match = $api->json('https://api.chess.com/pub/match/' . $matchId, true);
                    $authority = fast_authoritative($match, $clubSlug);
                    $proposed = 'review';
                    $reason = (string)($authority['reason'] ?? 'Unable to validate the authoritative match payload.');
                    if (($authority['ok'] ?? false) === true) {
                        if (($authority['status'] ?? '') !== 'finished') {
                            $proposed = 'remove';
                            $reason = 'Chess.com reports this match as ' . ($authority['status'] ?? 'not finished') . '.';
                        } else {
                            $same = (int)$summary['board_count'] === (int)$authority['boards']
                                && (int)$summary['game_count'] === (int)$authority['game_count']
                                && abs((float)$summary['team_score'] - (float)$authority['team_score']) < 0.001
                                && (string)$summary['result'] === (string)$authority['result']
                                && (int)$summary['competition_points'] === (int)$authority['competition_points'];
                            $proposed = $same ? 'keep' : 'update';
                            $reason = $same
                                ? 'The stored summary matches the authoritative finished match.'
                                : 'The match is finished, but the stored summary differs from the authoritative values.';
                        }
                    }
                    $rows[] = [
                        'match_id' => $matchId,
                        'summary' => $summary,
                        'candidate' => $candidate,
                        'authoritative' => $authority,
                        'proposed_action' => $proposed,
                        'reason' => $reason,
                    ];
                } catch (Throwable $exception) {
                    $rows[] = [
                        'match_id' => $matchId,
                        'summary' => $summary,
                        'candidate' => $candidate,
                        'authoritative' => ['ok' => false],
                        'proposed_action' => 'review',
                        'reason' => $exception->getMessage(),
                    ];
                }
            }
            fast_json(['ok' => true, 'rows' => $rows]);
        }

        if ($action === 'apply') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($body['match_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
            if ($ids === [] || count($ids) > 2) {
                fast_json(['ok' => false, 'error' => 'Apply one or two validated candidate matches per batch.'], 400);
            }
            fast_log_table($pdo);
            $api = new ChessApi($repository);
            $validated = [];
            foreach ($ids as $matchId) {
                $candidate = fast_candidate($pdo, $clubSlug, $matchId);
                $before = fast_summary_details($pdo, $clubSlug, $matchId);
                if ($candidate === null || $before === null) {
                    throw new RuntimeException("Match {$matchId} is no longer a fast-audit candidate.");
                }
                $match = $api->json('https://api.chess.com/pub/match/' . $matchId, true);
                $authority = fast_authoritative($match, $clubSlug);
                if (($authority['ok'] ?? false) !== true) {
                    throw new RuntimeException("Match {$matchId}: " . ($authority['reason'] ?? 'authoritative validation failed'));
                }
                $same = (string)$authority['status'] === 'finished'
                    && (int)$before['board_count'] === (int)$authority['boards']
                    && (int)$before['game_count'] === (int)$authority['game_count']
                    && abs((float)$before['team_score'] - (float)$authority['team_score']) < 0.001
                    && (string)$before['result'] === (string)$authority['result']
                    && (int)$before['competition_points'] === (int)$authority['competition_points'];
                if ($same) {
                    continue;
                }
                $validated[] = [
                    'match_id' => $matchId,
                    'candidate' => $candidate,
                    'before' => $before,
                    'before_states' => fast_board_states($pdo, $clubSlug, $matchId),
                    'authority' => $authority,
                ];
            }

            $runId = p2k_tp_uuid();
            $applied = [];
            $pdo->beginTransaction();
            try {
                foreach ($validated as $validatedRow) {
                    $matchId = (int)$validatedRow['match_id'];
                    $before = $validatedRow['before'];
                    $beforeStates = $validatedRow['before_states'];
                    $authority = $validatedRow['authority'];
                    $status = (string)$authority['status'];
                    $after = null;
                    if ($status !== 'finished') {
                        $delete = $pdo->prepare("UPDATE p2k_tp_match_metadata SET status=?,result='unknown',competition_points=0,finalized_at=NULL,last_verified_at=UTC_TIMESTAMP() WHERE club_slug=? AND match_id=?");
                        $delete->execute([$status,$clubSlug,$matchId]);
                        $reset = $pdo->prepare(
                            "UPDATE p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id
                             SET b.source_bucket='in_progress',
                                 b.state=CASE WHEN b.finished_game_count>=2 THEN 'complete_immutable' ELSE 'recent_in_progress' END,
                                 b.next_check_at=CASE WHEN b.finished_game_count>=2 THEN NULL ELSE UTC_TIMESTAMP() END,
                                 b.completed_at=CASE WHEN b.finished_game_count>=2 THEN b.completed_at ELSE NULL END
                             WHERE u.club_slug=? AND b.match_id=?"
                        );
                        $reset->execute([$clubSlug, $matchId]);
                        $repairAction = 'remove';
                        $reason = "Chess.com status is {$status}; the summary cannot count as finished.";
                    } else {
                        $upsert = $pdo->prepare(
                            "UPDATE p2k_tp_match_metadata SET status='finished',board_count=?,p2k_score=?,result=?,competition_points=?,finalized_at=COALESCE(finalized_at,UTC_TIMESTAMP()),last_verified_at=UTC_TIMESTAMP() WHERE club_slug=? AND match_id=?"
                        );
                        $upsert->execute([(int)$authority['boards'],(float)$authority['team_score'],(string)$authority['result'],(int)$authority['competition_points'],$clubSlug,$matchId]);
                        $markFinished = $pdo->prepare(
                            "UPDATE p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id SET b.source_bucket='finished' WHERE u.club_slug=? AND b.match_id=?"
                        );
                        $markFinished->execute([$clubSlug, $matchId]);
                        $after = fast_summary_details($pdo, $clubSlug, $matchId);
                        $repairAction = 'update';
                        $reason = 'The summary was replaced with authoritative finished-match board count, score and competition points.';
                    }
                    $log = $pdo->prepare(
                        "INSERT INTO p2k_tp_consistency_repairs(
                            repair_run_id,club_slug,match_id,action,reason,before_json,after_json,created_at
                         ) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())"
                    );
                    $log->execute([
                        $runId,$clubSlug,$matchId,$repairAction,$reason,
                        json_encode([
                            'summary' => $before,
                            'board_states' => $beforeStates,
                            'fast_audit_candidate' => $validatedRow['candidate'],
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                    $applied[] = ['match_id' => $matchId, 'action' => $repairAction, 'reason' => $reason];
                }
                fast_rebuild_totals($pdo, $clubSlug);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            fast_json(['ok' => true, 'repair_run_id' => $runId, 'applied' => $applied]);
        }

        if ($action === 'runs') {
            fast_log_table($pdo);
            $query = $pdo->prepare(
                "SELECT repair_run_id,MIN(created_at) AS created_at,COUNT(*) AS changes,
                        SUM(action='remove') AS removed,SUM(action='update') AS updated
                 FROM p2k_tp_consistency_repairs WHERE club_slug=? GROUP BY repair_run_id
                 ORDER BY MIN(created_at) DESC LIMIT 20"
            );
            $query->execute([$clubSlug]);
            fast_json(['ok' => true, 'runs' => $query->fetchAll()]);
        }

        if ($action === 'rollback') {
            $runId = trim((string)($body['repair_run_id'] ?? ''));
            if ($runId === '') {
                fast_json(['ok' => false, 'error' => 'repair_run_id is required.'], 400);
            }
            fast_log_table($pdo);
            $query = $pdo->prepare(
                'SELECT * FROM p2k_tp_consistency_repairs WHERE club_slug=? AND repair_run_id=? ORDER BY id DESC'
            );
            $query->execute([$clubSlug, $runId]);
            $changes = $query->fetchAll();
            if ($changes === []) {
                fast_json(['ok' => false, 'error' => 'Repair run not found.'], 404);
            }
            $pdo->beginTransaction();
            try {
                foreach ($changes as $change) {
                    $matchId = (int)$change['match_id'];
                    $beforeSnapshot = json_decode((string)($change['before_json'] ?? ''), true);
                    $before = is_array($beforeSnapshot['summary'] ?? null)
                        ? $beforeSnapshot['summary']
                        : (is_array($beforeSnapshot) ? $beforeSnapshot : null);
                    $beforeStates = is_array($beforeSnapshot['board_states'] ?? null) ? $beforeSnapshot['board_states'] : [];
                    if (!is_array($before)) {
                        $delete = $pdo->prepare("UPDATE p2k_tp_match_metadata SET status=?,result='unknown',competition_points=0,finalized_at=NULL,last_verified_at=UTC_TIMESTAMP() WHERE club_slug=? AND match_id=?");
                        $delete->execute([$status,$clubSlug,$matchId]);
                    } else {
                        $upsert = $pdo->prepare(
                            "UPDATE p2k_tp_match_metadata SET status='finished',board_count=?,p2k_score=?,result=?,competition_points=?,finalized_at=?,last_verified_at=UTC_TIMESTAMP() WHERE club_slug=? AND match_id=?"
                        );
                        $upsert->execute([(int)$before['board_count'],(float)$before['team_score'],(string)$before['result'],(int)$before['competition_points'],(string)$before['finalized_at'],$clubSlug,$matchId]);
                    }
                    if ($beforeStates !== []) {
                        $restore = $pdo->prepare(
                            "UPDATE p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id SET b.source_bucket=?,b.state=?,b.finished_game_count=?,b.next_check_at=?,b.completed_at=?
                             WHERE u.club_slug=? AND b.match_id=? AND u.username_key=? AND COALESCE(b.board_url_override,CONCAT('https://api.chess.com/pub/match/',b.match_id,'/',b.board_no))=?"
                        );
                        foreach ($beforeStates as $state) {
                            $restore->execute([
                                (string)$state['source_bucket'],(string)$state['state'],(int)$state['finished_game_count'],
                                $state['next_check_at'] ?? null,$state['completed_at'] ?? null,
                                $clubSlug,$matchId,(string)$state['username_key'],(string)$state['board_url'],
                            ]);
                        }
                    }
                }
                fast_rebuild_totals($pdo, $clubSlug);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            fast_json(['ok' => true, 'repair_run_id' => $runId, 'changes' => count($changes)]);
        }

        fast_json(['ok' => false, 'error' => 'Unknown action.'], 404);
    } catch (Throwable $exception) {
        fast_json(['ok' => false, 'error' => $exception->getMessage()], 500);
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>P2K Team Points fast former-member audit</title>
<style>
:root{color-scheme:dark;--bg:#11100f;--panel:#1c1916;--line:#493d31;--text:#f4ede3;--muted:#bbae9e;--accent:#e59a2c;--good:#6ed29a;--warn:#ffd166;--bad:#ff8585;--blue:#75bdff}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#2a2117 0,#11100f 44%);color:var(--text);font:14px/1.45 Arial,sans-serif}.wrap{max-width:1500px;margin:auto;padding:22px}.hero,.panel{background:rgba(28,25,22,.96);border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 32px #0007;padding:20px;margin-bottom:16px}.hero h1{margin:0 0 7px;color:#ffc66d}.hero p{margin:5px 0;color:var(--muted)}.row{display:flex;flex-wrap:wrap;gap:10px;align-items:end}.field{min-width:280px;flex:1}.field label{display:block;font-weight:700;margin-bottom:5px}input,button{font:inherit}input[type=password]{width:100%;padding:10px;border-radius:7px;border:1px solid #5a4938;background:#100f0e;color:var(--text)}button{padding:10px 13px;border:1px solid #6b5439;border-radius:7px;background:#2a241e;color:var(--text);font-weight:700;cursor:pointer}button.primary{background:#9b5a12;border-color:#d99029}button.danger{background:#792f2f;border-color:#c75959}button:disabled{opacity:.45;cursor:not-allowed}.notice{border-left:4px solid var(--warn);padding:10px 12px;background:#241d13;border-radius:6px;margin-top:12px}.status{margin-top:12px;padding:10px;border-radius:7px;background:#151311;border:1px solid #41372e}.status.good{border-color:#2c8054;color:var(--good)}.status.warn{border-color:#8c6d20;color:var(--warn)}.status.bad{border-color:#9b3e3e;color:var(--bad)}.progress{height:12px;background:#100f0e;border-radius:999px;overflow:hidden;border:1px solid #3d342c;margin-top:10px}.progress>div{height:100%;width:0;background:linear-gradient(90deg,#d38a1d,#f4c45f);transition:width .2s}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:10px;margin-top:12px}.card{background:#151311;border:1px solid #3d342c;border-radius:9px;padding:12px}.card b{display:block;font-size:22px;color:#ffd487}.card span{color:var(--muted)}.table-wrap{overflow:auto;margin-top:12px}table{border-collapse:collapse;width:100%;min-width:1150px}th,td{padding:9px;border-bottom:1px solid #3d342c;text-align:left;vertical-align:top}th{position:sticky;top:0;background:#241f1a;color:#ffd487}.pill{display:inline-block;border-radius:999px;padding:3px 8px;font-weight:700;font-size:12px}.keep{background:#173d2b;color:var(--good)}.update{background:#493711;color:var(--warn)}.remove{background:#4a1d1d;color:var(--bad)}.review{background:#17324a;color:var(--blue)}code{color:#ffd487}.small{font-size:12px;color:var(--muted)}.check{width:18px;height:18px}@media(max-width:700px){.wrap{padding:10px}.panel,.hero{padding:14px}.field{min-width:100%}}
</style>
</head>
<body>
<div class="wrap">
<section class="hero">
  <h1>Team Points fast audit — former members</h1>
  <p>Checks only finalized match summaries involving at least one player currently marked as no longer belonging to P2K.</p>
  <p class="small">This is a targeted audit. It is much faster than validating every summary, but it cannot detect a missing former-member participation row.</p>
</section>
<section class="panel">
  <div class="row">
    <div class="field"><label for="token">Team Points administrator token</label><input id="token" type="password" autocomplete="off" placeholder="app.admin_token from config.local.php"></div>
    <button id="audit" class="primary">1. Find candidates</button>
    <button id="validate" disabled>2. Validate candidates</button>
    <button id="export" disabled>Export report</button>
  </div>
  <div class="notice"><b>Safe workflow:</b> find candidates → validate fresh Chess.com statuses → review proposals → apply selected fixes. No database row is changed before step 3.</div>
  <div id="status" class="status">Enter the administrator token and find candidates.</div>
  <div class="progress"><div id="bar"></div></div>
  <div id="cards" class="cards"></div>
</section>
<section class="panel">
  <div class="row"><h2 style="margin:0;flex:1">Validated candidate summaries</h2><button id="selectFixes" disabled>Select proposed fixes</button><button id="apply" class="danger" disabled>3. Apply selected fixes</button></div>
  <div class="table-wrap"><table><thead><tr><th></th><th>Match</th><th>Former members</th><th>Proposal</th><th>Chess.com status</th><th>Stored summary</th><th>Authoritative</th><th>Reason</th></tr></thead><tbody id="results"><tr><td colspan="8">No validation results yet.</td></tr></tbody></table></div>
</section>
<section class="panel">
  <div class="row"><h2 style="margin:0;flex:1">Repair history and rollback</h2><button id="loadRuns">Load repair history</button></div>
  <div id="runs" class="small" style="margin-top:10px">No repair history loaded.</div>
</section>
</div>
<script>
const state={audit:null,rows:new Map(),report:[]};
const $=id=>document.getElementById(id);
function token(){return $('token').value.trim()}
function status(msg,kind=''){const el=$('status');el.textContent=msg;el.className='status '+kind}
function progress(done,total){$('bar').style.width=(total?Math.min(100,done/total*100):0)+'%'}
async function call(action,body={}){const t=token();if(!t)throw new Error('Enter the administrator token.');const r=await fetch('?action='+encodeURIComponent(action),{method:'POST',headers:{'Content-Type':'application/json','X-P2K-Admin-Token':t},body:JSON.stringify(body),cache:'no-store'});let j;try{j=await r.json()}catch{throw new Error('Server returned HTTP '+r.status+' without valid JSON.')}if(!r.ok||!j.ok)throw new Error(j.error||('HTTP '+r.status));return j}
function n(v){return Number(v||0).toLocaleString()}
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function renderCards(a){const c=a.cached_totals||{},x=a.calculated_totals||{},s=a.candidate_stats||{};$('cards').innerHTML=[['Candidate matches',s.candidate_matches],['Points currently carried by candidates',s.candidate_points],['Candidate boards',s.candidate_boards],['Former-member participations',s.former_member_participations],['Cached finished matches',c.finished_matches],['Calculated finished matches',x.finished_matches],['Cached club points',c.club_points],['Calculated club points',x.club_points]].map(([k,v])=>`<div class="card"><b>${n(v)}</b><span>${k}</span></div>`).join('')}
function renderRows(){const rows=[...state.rows.values()];state.report=rows;if(!rows.length){$('results').innerHTML='<tr><td colspan="8">No validation results yet.</td></tr>';return}$('results').innerHTML=rows.map(r=>{const s=r.summary||{},a=r.authoritative||{},c=r.candidate||{},p=r.proposed_action||'review';const selectable=p==='remove'||p==='update';return `<tr><td><input class="check fix" type="checkbox" data-id="${r.match_id}" ${selectable?'':'disabled'}></td><td><a href="https://www.chess.com/club/matches/${r.match_id}" target="_blank" rel="noopener" style="color:#75bdff">${r.match_id}</a><br><span class="small">${esc(a.match_name||'')}</span></td><td><b>${n(c.former_member_count)}</b><br><span class="small">${esc(c.former_members||'')}</span></td><td><span class="pill ${p}">${esc(p)}</span></td><td>${esc(a.status||'unknown')}</td><td>${n(s.board_count)} boards<br>${n(s.team_score)} score<br>${n(s.competition_points)} points</td><td>${a.ok?`${n(a.boards)} boards<br>${n(a.team_score)} score<br>${n(a.competition_points)} points`:'Unavailable'}</td><td>${esc(r.reason)}</td></tr>`}).join('');$('selectFixes').disabled=false;$('apply').disabled=false;$('export').disabled=false}
async function audit(){try{status('Finding finalized summaries involving known former members…');progress(0,1);const a=await call('audit');state.audit=a;renderCards(a);$('validate').disabled=Number(a.candidate_stats?.candidate_matches||0)===0;$('export').disabled=false;progress(1,1);const count=Number(a.candidate_stats?.candidate_matches||0);status(count?`Found ${count} fast-audit candidate match(es). No Chess.com requests have been made yet.`:'No finalized summaries involving known former members were found.',count?'warn':'good')}catch(e){status(e.message,'bad')}}
async function ids(){const out=[];let offset=0;for(;;){const j=await call('ids',{offset,limit:250});out.push(...j.ids);if(j.ids.length<j.limit)break;offset+=j.ids.length}return [...new Set(out)]}
async function validate(){try{state.rows.clear();renderRows();status('Loading fast-audit candidate IDs…');const all=await ids();if(!all.length){status('No fast-audit candidates remain.','good');return}let done=0;for(let i=0;i<all.length;i+=2){const batch=all.slice(i,i+2);const j=await call('validate',{match_ids:batch});for(const r of j.rows)state.rows.set(r.match_id,r);done+=batch.length;progress(done,all.length);status(`Validated ${done} of ${all.length} candidate matches. Keep this tab open.`);renderRows()}const values=[...state.rows.values()];const remove=values.filter(r=>r.proposed_action==='remove').length;const update=values.filter(r=>r.proposed_action==='update').length;const review=values.filter(r=>r.proposed_action==='review').length;status(`Validation complete: ${remove} removal(s), ${update} update(s), ${review} manual-review result(s).`,remove||update?'warn':review?'warn':'good')}catch(e){status(e.message,'bad')}}
function selected(){return [...document.querySelectorAll('.fix:checked')].map(x=>Number(x.dataset.id))}
async function apply(){const ids=selected();if(!ids.length){status('Select at least one proposed fix.','warn');return}if(!confirm(`Apply authoritative repairs to ${ids.length} selected match summary row(s)? A rollback record will be created.`))return;try{let done=0,runs=[];for(let i=0;i<ids.length;i+=2){const j=await call('apply',{match_ids:ids.slice(i,i+2)});if(j.repair_run_id)runs.push(j.repair_run_id);done+=j.applied.length;progress(Math.min(i+2,ids.length),ids.length);status(`Processed ${Math.min(i+2,ids.length)} of ${ids.length} selected matches…`)}status(`Repair complete: ${done} summary row(s) changed. Run Find candidates again to verify. Repair run(s): ${runs.join(', ')||'none needed'}`,'good');await loadRuns()}catch(e){status(e.message,'bad')}}
function exportReport(){const blob=new Blob([JSON.stringify({generated_at:new Date().toISOString(),audit:state.audit,validation:state.report},null,2)],{type:'application/json'});const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='p2k-team-points-fast-former-member-audit-'+new Date().toISOString().replace(/[:.]/g,'-')+'.json';a.click();URL.revokeObjectURL(a.href)}
async function loadRuns(){try{const j=await call('runs');if(!j.runs.length){$('runs').textContent='No repair run has been recorded.';return}$('runs').innerHTML=j.runs.map(r=>`<div style="padding:8px;border-bottom:1px solid #3e352c"><b>${esc(r.created_at)}</b> — ${n(r.changes)} change(s), ${n(r.removed)} removed, ${n(r.updated)} updated <button data-run="${esc(r.repair_run_id)}" class="rollback" style="margin-left:10px;padding:5px 8px">Rollback</button><br><code>${esc(r.repair_run_id)}</code></div>`).join('');document.querySelectorAll('.rollback').forEach(b=>b.onclick=()=>rollback(b.dataset.run))}catch(e){status(e.message,'bad')}}
async function rollback(run){if(!confirm('Rollback repair run '+run+' and rebuild totals?'))return;try{const j=await call('rollback',{repair_run_id:run});status(`Rolled back ${j.changes} change(s) from ${run}. Run Find candidates again.`,'good');await loadRuns()}catch(e){status(e.message,'bad')}}
$('audit').onclick=audit;$('validate').onclick=validate;$('selectFixes').onclick=()=>document.querySelectorAll('.fix:not(:disabled)').forEach(x=>x.checked=true);$('apply').onclick=apply;$('export').onclick=exportReport;$('loadRuns').onclick=loadRuns;
</script>
</body>
</html>
