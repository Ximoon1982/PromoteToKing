# Promote to King v2.7.1

## Embedded clean Team Points initialization

- Added a standalone Python `.pyz` seed utility containing the six updated source files supplied on 6 August 2026. The operator does not select or provide CSV files at runtime.
- Every embedded raw file and compressed resource is verified by SHA-256 before parsing.
- The embedded snapshot contains 4,473 members, 17,091 authoritative discovered match IDs, 15,997 finished matches, 1,019 in-progress matches, 74 registration matches and 104,761 member/board rows.
- One findings-only match (`1983076`) has no supplied metadata. It is preserved as `unknown` and queued for an authoritative match-detail refresh after import.
- Local validation checks duplicate/conflicting members, matches and boards, references, timestamps, result codes, finished-match authority, void 0–0 matches and recomputed club points.
- The normalized snapshot contains 96,691 complete two-game boards, 4,725 one-game boards, 3,345 zero-game boards, 198,107 finished game events and 371,168 club competition points.
- Added signed, nonce-protected, gzip batch staging through `server/team-points/public/seed-import.php`.
- Production rows are replaced only after complete server validation and the exact confirmation phrase.
- The final replacement acquires the Team Points worker lock and runs in one database transaction; failed staging or validation leaves production unchanged.
- Apply clears obsolete Team Points jobs, queue items, leases and Team Points task-run/log history, then creates one paused incremental catch-up job.
- Team Points remains paused after Apply so totals can be inspected before catch-up.

## Seeded incremental synchronization

- Routine roster refresh uses one club-member request and no longer queues every member’s `/matches` endpoint.
- Routine match discovery uses the club match index and fetches only new, changed or due match details.
- Current and previous player monthly archives are the primary fast path for recent finished game events and exact `end_time` values.
- Individual board endpoints remain available as unresolved-data fallbacks.
- Complete two-game boards remain immutable and are skipped permanently.
- Full member-history scans and numeric raw match-ID scans are retained only as explicit, confirmed repair actions.
- Club totals are rebuilt from immutable non-void match summaries; authoritative finished 0–0 matches remain stored as void.

## Administration and CRON control

- Scheduled Tasks Control displays the Team Points operating algorithm, latest seed state and separate incremental/member-history/raw-ID actions.
- The administrator dashboard reports seed and algorithm health alongside scheduler status.
- Team Points and tournament scheduler ticks are five minutes; match monitoring remains hourly with per-match adaptive due times.
- Added complete instructions to pause tasks, remove old host entries, install the new three-task schedule, test it and perform an emergency stop.

## Database and compatibility

- Team Points schema advances from 11 to **12** with additive seed-staging, void-match and due-detail scheduling structures.
- Existing URLs, tokens, tournament data, Live-rank/MCA data and shared gateway storage remain compatible.
- The release is built from the complete v2.7.0 standalone baseline and retains its achievements, profiles, tournament stages and adaptive match monitoring.
