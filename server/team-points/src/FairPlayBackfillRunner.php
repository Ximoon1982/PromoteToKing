<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

/** v2.10.9.7 dedicated, resumable historical Fair Play backfill. */
final class FairPlayBackfillRunner
{
    public function __construct(
        private readonly PDO $core,
        private readonly Repository $repository,
        private readonly ChessApi $api,
        private readonly string $clubSlug
    ) {}

    public function run(int $maxSeconds = 20, int $maxMatches = 20, int $minimumIntervalMs = 1000): array
    {
        $maxSeconds = max(5, min(45, $maxSeconds));
        $maxMatches = max(1, min(50, $maxMatches));
        $minimumIntervalMs = max(1000, min(5000, $minimumIntervalMs));
        $deadline = microtime(true) + $maxSeconds;
        $service = new FairPlayReconciliationService($this->core, $this->repository, $this->api, $this->clubSlug);
        $service->ensureSchema();

        $lockName = 'p2k_tp_fair_play_backfill_' . substr(preg_replace('/[^a-z0-9_-]+/i', '-', $this->clubSlug) ?: 'club', 0, 40);
        $lock = $this->core->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            return ['ok'=>true,'busy'=>true,'processed_matches'=>0,'status'=>$service->status()];
        }

        $processed = 0;
        $corrected = 0;
        $pointsAdded = 0.0;
        $errors = [];
        try {
            $state = $service->status();
            if (($state['status'] ?? 'running') !== 'running') {
                return ['ok'=>true,'processed_matches'=>0,'status'=>$state];
            }
            $cursor = max(0, (int)($state['cursor_match_id'] ?? 0));

            while ($processed < $maxMatches && microtime(true) + 1.5 < $deadline) {
                $q = $this->core->prepare("SELECT match_id FROM p2k_tp_match_metadata WHERE club_slug=? AND status='finished' AND match_id>? ORDER BY match_id ASC LIMIT 1");
                $q->execute([$this->clubSlug, $cursor]);
                $id = (int)($q->fetchColumn() ?: 0);
                if ($id <= 0) {
                    $done = $this->core->prepare("UPDATE p2k_tp_fair_play_backfill_state SET status='complete',completed_at=COALESCE(completed_at,UTC_TIMESTAMP()),last_run_at=UTC_TIMESTAMP(),last_error=NULL WHERE club_slug=?");
                    $done->execute([$this->clubSlug]);
                    break;
                }

                $requestStarted = microtime(true);
                try {
                    $payload = $this->api->json('https://api.chess.com/pub/match/' . $id, true);
                    $result = $service->applyMatchPayload($id, $payload, true, true);
                    $this->advance($id, $result);
                    $cursor = $id;
                    $processed++;
                    $corrected += (int)($result['corrected_games'] ?? 0);
                    $pointsAdded += (float)($result['points_added'] ?? 0);
                } catch (\Throwable $e) {
                    $errors[] = 'match ' . $id . ': ' . $e->getMessage();
                    $this->recordError($e->getMessage());
                    break;
                }

                $elapsedMs = (int)round((microtime(true) - $requestStarted) * 1000);
                $sleepMs = $minimumIntervalMs - $elapsedMs;
                if ($sleepMs > 0 && microtime(true) + ($sleepMs / 1000) + 0.5 < $deadline) {
                    usleep($sleepMs * 1000);
                }
            }

            return [
                'ok'=>true,
                'busy'=>false,
                'processed_matches'=>$processed,
                'corrected_games'=>$corrected,
                'points_added'=>$pointsAdded,
                'errors'=>$errors,
                'status'=>$service->status(),
            ];
        } finally {
            try {
                $release = $this->core->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (\Throwable) {}
        }
    }

    private function advance(int $matchId, array $result): void
    {
        $has = !empty($result['has_removals']);
        $before = isset($result['mismatch_before']) && (float)$result['mismatch_before'] !== 0.0;
        $after = isset($result['mismatch_after']) && (float)$result['mismatch_after'] !== 0.0;
        $resolved = $before && !$after;
        $q = $this->core->prepare("UPDATE p2k_tp_fair_play_backfill_state SET cursor_match_id=GREATEST(cursor_match_id,?),checked_matches=checked_matches+1,matches_with_removals=matches_with_removals+?,affected_games=affected_games+?,corrected_games=corrected_games+?,points_added_x2=points_added_x2+?,mismatches_before=mismatches_before+?,mismatches_resolved=mismatches_resolved+?,mismatches_remaining=mismatches_remaining+?,last_run_at=UTC_TIMESTAMP(),last_error=NULL WHERE club_slug=?");
        $q->execute([
            $matchId,
            $has ? 1 : 0,
            (int)($result['affected_games'] ?? 0),
            (int)($result['corrected_games'] ?? 0),
            (int)round(2 * (float)($result['points_added'] ?? 0)),
            $before ? 1 : 0,
            $resolved ? 1 : 0,
            $after ? 1 : 0,
            $this->clubSlug,
        ]);
    }

    private function recordError(string $error): void
    {
        $q = $this->core->prepare('UPDATE p2k_tp_fair_play_backfill_state SET last_run_at=UTC_TIMESTAMP(),last_error=? WHERE club_slug=?');
        $q->execute([substr($error, 0, 4000), $this->clubSlug]);
    }
}
