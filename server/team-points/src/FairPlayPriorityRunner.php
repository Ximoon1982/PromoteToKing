<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

/**
 * v2.10.9.7 guaranteed Fair Play priority slice.
 *
 * This runner is deliberately finished-only: it handles one recently finished match
 * before the bounded Club/Player maintenance lanes. Active matches are not polled.
 * Historical traversal belongs to FairPlayBackfillRunner and never consumes this
 * priority budget.
 */
final class FairPlayPriorityRunner
{
    public function __construct(
        private readonly PDO $core,
        private readonly Repository $repository,
        private readonly ChessApi $api,
        private readonly string $clubSlug
    ) {}

    public function run(?float $deadline = null, int $maxLiveMatches = 0): array
    {
        $deadline ??= microtime(true) + 5.0;
        $this->api->setDeadlineAt($deadline);
        $maxLiveMatches = max(0, min(3, $maxLiveMatches));
        $service = new FairPlayReconciliationService($this->core, $this->repository, $this->api, $this->clubSlug);
        $service->ensureSchema();

        $finished = null;
        $live = [];
        $errors = [];

        if ($this->hasTime($deadline, 1.35)) {
            try {
                $q = $this->core->prepare("SELECT m.match_id
                    FROM p2k_tp_match_metadata m
                    LEFT JOIN p2k_tp_fair_play_match_state f
                      ON f.club_slug=m.club_slug AND f.match_id=m.match_id
                    WHERE m.club_slug=? AND m.status='finished' AND (
                        (f.finalized_at IS NULL AND (m.end_time>=UTC_TIMESTAMP()-INTERVAL 30 DAY OR (m.end_time IS NULL AND m.last_verified_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY)))
                        OR (f.finalized_at IS NOT NULL AND m.last_verified_at>f.checked_at AND m.last_verified_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY)
                    )
                    ORDER BY COALESCE(m.end_time,m.last_verified_at) DESC,m.match_id DESC
                    LIMIT 1");
                $q->execute([$this->clubSlug]);
                $id = (int)($q->fetchColumn() ?: 0);
                if ($id > 0 && $this->hasTime($deadline, 1.15)) {
                    $payload = $this->api->json('https://api.chess.com/pub/match/' . $id, true);
                    $finished = $service->applyMatchPayload($id, $payload, true, false);
                }
            } catch (\Throwable $e) {
                $errors[] = 'finished: ' . $e->getMessage();
            }
        }

        // Active matches are intentionally excluded; historical backfill is opt-in.

        return [
            'ran' => true,
            'finished_check' => $finished,
            'live_checks' => $live,
            'errors' => $errors,
            'remaining_seconds' => max(0.0, round($deadline - microtime(true), 3)),
        ];
    }

    private function hasTime(float $deadline, float $reserve): bool
    {
        return microtime(true) + max(0.0, $reserve) < $deadline;
    }
}
