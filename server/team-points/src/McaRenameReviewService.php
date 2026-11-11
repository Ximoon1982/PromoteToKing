<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

/**
 * MCA -> MIAC review bridge. No new identity store and no automatic merges.
 * All proposals are MIAC candidate edges; only MIAC confirmation can change
 * canonical identity attribution. Raw Results CSVs remain immutable evidence.
 */
final class McaRenameReviewService
{
    private MiacService $miac;

    public function __construct(
        private readonly PDO $core,
        private readonly PDO $analytics,
        private readonly string $clubSlug
    ) {
        $this->miac = new MiacService($core, $clubSlug);
    }

    public function report(): array
    {
        $generation = $this->miac->generation();
        $stateQ = $this->analytics->prepare(
            "SELECT identity_map_generation,status,phase FROM p2k_lr_processing_state WHERE club_slug=? LIMIT 1"
        );
        $stateQ->execute([$this->clubSlug]);
        $state = $stateQ->fetch(PDO::FETCH_ASSOC) ?: [];
        $derived = (int)($state['identity_map_generation'] ?? 0);
        $sourcesQ = $this->analytics->prepare('SELECT COUNT(*) FROM p2k_lr_source_rows WHERE club_slug=?');
        $sourcesQ->execute([$this->clubSlug]);
        $hasSources = (int)$sourcesQ->fetchColumn() > 0;
        $stale = $hasSources && $derived !== $generation;

        $query = $this->analytics->prepare(
            "SELECT username_key,username,total_points,arena_count,source_files_json,last_error,profile_checked_at
             FROM p2k_lr_players WHERE club_slug=? AND account_state='possible_renamed'
             ORDER BY total_points DESC, username_key ASC"
        );
        $query->execute([$this->clubSlug]);
        $mapQ = $this->core->prepare(
            "SELECT canonical_username_key,canonical_username,conflict,resolution_reason
             FROM p2k_miac_canonical_map WHERE club_slug=? AND username_key=? LIMIT 1"
        );
        $edgeQ = $this->core->prepare(
            "SELECT new_username_key,status,confidence,shared_boards,coexists,evidence_source,reviewed_by
             FROM p2k_miac_edges WHERE club_slug=? AND old_username_key=?
             ORDER BY FIELD(status,'candidate','conflict','confirmed','rejected'),
                      FIELD(confidence,'Very strong','Strong','Possible'), shared_boards DESC LIMIT 12"
        );

        $unresolved = [];
        $resolved = [];
        $reviewable = 0;
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (string)$row['username_key'];
            $mapQ->execute([$this->clubSlug, $key]);
            $identity = $mapQ->fetch(PDO::FETCH_ASSOC) ?: [];
            $edgeQ->execute([$this->clubSlug, $key]);
            $edges = $edgeQ->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $files = \p2k_tp_json_decode((string)($row['source_files_json'] ?? '[]'));
            $suggestions = [];
            foreach ($edges as $edge) {
                $suggestions[] = [
                    'new_username' => (string)$edge['new_username_key'],
                    'status' => (string)$edge['status'],
                    'confidence' => (string)$edge['confidence'],
                    'shared_boards' => (int)$edge['shared_boards'],
                    'coexists' => (bool)$edge['coexists'],
                    'source' => (string)($edge['evidence_source'] ?? ''),
                    'reviewed_by' => (string)($edge['reviewed_by'] ?? ''),
                ];
            }
            $canonicalKey = (string)($identity['canonical_username_key'] ?? $key);
            $isResolved = $canonicalKey !== $key && empty($identity['conflict']);
            $case = [
                'username' => (string)$row['username'],
                'username_key' => $key,
                'points' => (float)$row['total_points'],
                'arenas' => (int)$row['arena_count'],
                'source_files' => is_array($files) ? array_values(array_map('strval', $files)) : [],
                'verification_error' => (string)($row['last_error'] ?? ''),
                'profile_checked_at' => $row['profile_checked_at'] ?? null,
                'canonical_username' => $isResolved ? (string)($identity['canonical_username'] ?? $canonicalKey) : null,
                'identity_conflict' => !empty($identity['conflict']),
                'suggestions' => $suggestions,
                'state' => $isResolved ? 'resolved_pending_attribution' : 'unresolved',
            ];
            if ($isResolved) {
                $resolved[] = $case;
            } else {
                foreach ($suggestions as $suggestion) {
                    if ($suggestion['status'] === 'candidate') { $reviewable++; break; }
                }
                $unresolved[] = $case;
            }
        }
        return [
            'ok' => true,
            'unresolved' => $unresolved,
            'resolved_pending_attribution' => $resolved,
            'counts' => [
                'unresolved' => count($unresolved),
                'ready_for_miac_review' => $reviewable,
                'resolved_pending_attribution' => count($resolved),
                'raw_possible_renames' => count($unresolved) + count($resolved),
            ],
            'mira' => [
                'generation' => $generation,
                'derived_generation' => $derived,
                'stale' => $stale,
                'source_rows_available' => $hasSources,
                'processing_status' => (string)($state['status'] ?? 'unknown'),
            ],
        ];
    }

    /**
     * Creates an unverified MIAC candidate; does NOT join accounts or change
     * points. A human must confirm it after checking the evidence.
     */
    public function propose(string $oldUsername, string $newUsername): array
    {
        $old = \p2k_tp_username_key($oldUsername);
        $new = \p2k_tp_username_key($newUsername);
        foreach ([$old, $new] as $key) {
            if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,29}$/D', $key)) {
                throw new ApiException('Provide a valid Chess.com username.', 400, 'MCA_ALIAS_INVALID_NAME');
            }
        }
        if ($old === $new) throw new ApiException('A username cannot be its own alias.', 400, 'MCA_ALIAS_SAME_NAME');
        $q = $this->analytics->prepare(
            "SELECT username,total_points,arena_count,source_files_json
             FROM p2k_lr_players WHERE club_slug=? AND username_key=? AND account_state='possible_renamed' LIMIT 1"
        );
        $q->execute([$this->clubSlug, $old]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new ApiException('This MCA username is not in the unresolved verification queue.', 409, 'MCA_ALIAS_NOT_PENDING');

        $mapQ = $this->core->prepare(
            'SELECT canonical_username_key,conflict FROM p2k_miac_canonical_map WHERE club_slug=? AND username_key=? LIMIT 1'
        );
        $mapQ->execute([$this->clubSlug, $old]);
        $mapped = $mapQ->fetch(PDO::FETCH_ASSOC) ?: [];
        if (empty($mapped['conflict']) && !empty($mapped['canonical_username_key'])
            && (string)$mapped['canonical_username_key'] !== $old) {
            throw new ApiException('This MCA username is already attributed to a canonical identity.', 409, 'MCA_ALIAS_ALREADY_RESOLVED');
        }

        // Register both names in MIAC's existing name ledger. No identity
        // topology changes occur while the edge is only a candidate.
        $this->miac->resolve((string)$row['username']);
        $this->miac->resolve($new);
        $existingQ = $this->core->prepare(
            'SELECT status FROM p2k_miac_edges WHERE club_slug=? AND old_username_key=? AND new_username_key=? LIMIT 1'
        );
        $existingQ->execute([$this->clubSlug, $old, $new]);
        $existing = $existingQ->fetchColumn();
        if ($existing !== false) {
            if ($existing === 'rejected') throw new ApiException('This identity relationship was previously rejected and cannot be silently proposed again.', 409, 'MCA_ALIAS_REJECTED');
            return ['old_username' => $old, 'new_username' => $new, 'status' => (string)$existing, 'created' => false];
        }

        $files = \p2k_tp_json_decode((string)($row['source_files_json'] ?? '[]'));
        $evidence = json_encode([
            'origin' => 'mca_possible_rename',
            'warning' => 'Missing Chess.com username is NOT proof of a rename; manual MIAC review required.',
            'points' => (float)$row['total_points'],
            'arena_count' => (int)$row['arena_count'],
            'source_files' => is_array($files) ? array_slice(array_values($files), 0, 50) : [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $insert = $this->core->prepare(
            "INSERT IGNORE INTO p2k_miac_edges
             (club_slug,old_username_key,new_username_key,confidence,shared_boards,same_joined,roster_handover,
              coexists,status,evidence_source,evidence_json,created_at,updated_at)
             VALUES(?,?,?,'Possible',0,0,0,0,'candidate','mca_possible_rename',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
        );
        $insert->execute([$this->clubSlug, $old, $new, $evidence]);
        $existingQ->execute([$this->clubSlug, $old, $new]);
        $status = (string)($existingQ->fetchColumn() ?: '');
        if ($status === 'rejected') throw new ApiException('This identity relationship has been rejected.', 409, 'MCA_ALIAS_REJECTED');
        if ($status === '') throw new \RuntimeException('MIAC candidate could not be stored.');
        return ['old_username' => $old, 'new_username' => $new, 'status' => $status, 'created' => $insert->rowCount() > 0];
    }

    public function review(string $oldUsername, string $newUsername, string $decision): array
    {
        $old = \p2k_tp_username_key($oldUsername);
        $new = \p2k_tp_username_key($newUsername);
        $q = $this->analytics->prepare(
            "SELECT COUNT(*) FROM p2k_lr_players WHERE club_slug=? AND username_key=? AND account_state='possible_renamed'"
        );
        $q->execute([$this->clubSlug, $old]);
        if ((int)$q->fetchColumn() === 0) throw new ApiException('The MCA rename case is no longer pending.', 409, 'MCA_ALIAS_NOT_PENDING');
        // This is the same review path as Admin -> Members -> Aliases.
        return $this->miac->reviewEdge($old, $new, $decision, 'admin:mca_review');
    }

    public function exportUnresolvedCsv(): void
    {
        $report = $this->report();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="p2k-mca-unresolved-renames.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['username_in_csv','points','arenas','source_files','verification_error','MIAC_candidates','corrected_username']);
        foreach ($report['unresolved'] as $row) {
            $candidateNames = array_map(
                static fn(array $e): string => $e['new_username'].' ('.$e['status'].')',
                $row['suggestions']
            );
            fputcsv($out, [
                $row['username'], $row['points'], $row['arenas'],
                implode(' | ', $row['source_files']), $row['verification_error'],
                implode(' | ', $candidateNames), '',
            ]);
        }
        fclose($out);
    }
}
