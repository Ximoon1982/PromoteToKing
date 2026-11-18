<?php
declare(strict_types=1);
namespace P2K\TeamPoints;

use PDO;

/**
 * Green-native opponent metadata refresh, safely bounded by the existing maintenance CRON.
 * This never starts the retired Blue worker or rewrites opponent identities/statistics.
 */
final class OpponentProfileMaintenance
{
    public function __construct(
        private readonly PDO $core,
        private readonly Repository $repo,
        private readonly string $clubSlug
    ) {}

    public function runStep(int $limit = 2, ?float $deadlineAt = null): array
    {
        $limit = max(1, min(3, $limit));
        $eligible = "o.club_slug=? AND o.disabled=0
            AND (o.icon_checked_at IS NULL OR o.icon_checked_at < UTC_TIMESTAMP()-INTERVAL 90 DAY
                OR (COALESCE(o.country_code,'')='' AND
                    (o.profile_updated_at IS NULL OR o.profile_updated_at < UTC_TIMESTAMP()-INTERVAL 90 DAY)))
            AND (o.last_checked_at IS NULL OR
                 o.last_checked_at < UTC_TIMESTAMP()-INTERVAL
                     (CASE WHEN o.last_error='not_found' THEN 7*24 ELSE 6 END) HOUR)";
        $query = $this->core->prepare(
            "SELECT o.opponent_slug FROM p2k_tp_opponents o WHERE {$eligible}
             ORDER BY (COALESCE(o.country_code,'')='') DESC,
                      o.icon_checked_at IS NULL DESC,
                      COALESCE(o.last_checked_at,'1970-01-01') ASC,
                      o.opponent_slug LIMIT {$limit}"
        );
        $query->execute([$this->clubSlug]);
        $candidates = $query->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $checked = 0; $updated = 0; $notFound = 0; $errors = 0;
        $api = new ChessApi($this->repo);
        foreach ($candidates as $candidate) {
            if ($deadlineAt !== null && microtime(true) >= $deadlineAt - 1.0) break;
            $slug = strtolower(trim((string)$candidate));
            if (!preg_match('/^[a-z0-9_-]{1,100}$/D', $slug)) continue;
            $api->setDeadlineAt($deadlineAt ?? 0.0);
            try {
                $profile = $api->jsonIfExists('https://api.chess.com/pub/club/'.rawurlencode($slug), true);
                if ($profile === null) {
                    $this->markChecked($slug, 'not_found'); $notFound++; $checked++;
                    continue;
                }
                $this->repo->storeOpponentProfileSnapshot($this->clubSlug, $slug, $profile, true);
                $this->markChecked($slug, null);
                $updated++; $checked++;
            } catch (\Throwable $e) {
                // Do not disable clubs, erase existing icons, or chase a transient API failure.
                $message = substr(preg_replace('/[\r\n\t]+/', ' ', $e->getMessage()) ?? 'profile_fetch_failed', 0, 180);
                $this->markChecked($slug, $message); $errors++; $checked++;
            }
        }
        $count = $this->core->prepare("SELECT COUNT(*) FROM p2k_tp_opponents o WHERE {$eligible}");
        $count->execute([$this->clubSlug]);
        return [
            'ran'=>true, 'checked'=>$checked, 'updated'=>$updated,
            'not_found'=>$notFound, 'errors'=>$errors, 'remaining'=>(int)$count->fetchColumn(),
        ];
    }

    private function markChecked(string $slug, ?string $error): void
    {
        $q = $this->core->prepare("UPDATE p2k_tp_opponents
            SET last_checked_at=UTC_TIMESTAMP(),last_error=?
            WHERE club_slug=? AND opponent_slug=? AND disabled=0");
        $q->execute([$error,$this->clubSlug,$slug]);
    }
}
