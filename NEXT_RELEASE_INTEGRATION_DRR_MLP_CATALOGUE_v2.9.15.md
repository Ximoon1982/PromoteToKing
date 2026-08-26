# Post-v2.9.15 integration — DRR Fix, MLP Fix, catalogue progress context

Status: integrated into the internal post-v2.9.15 working tree only. No release number, updater, standalone archive, incremental archive, schema, CRON cadence, or package manifest finalization is part of this preparation.

## Dashboard Recommendation Resilience Fix (DRR Fix)

- The existing per-user dashboard Match Assistant session cache is now available to dashboard recommendation mode, not only to full-assistant restoration.
- A valid recent successful recommendation snapshot may be rendered while a live recommendation refresh continues.
- If the live refresh fails, the valid recent snapshot remains usable instead of the recommendation card being blanked.
- If a live refresh times out after cached recommendations were shown, those recommendations are preserved.
- Cached presentation never suppresses the live refresh and is explicitly tagged as cached/warning state across the iframe bridge.
- Re-rendering cached recommendations does not refresh the cache timestamp, preventing stale data from being perpetuated indefinitely.
- Parent-side recommendation rendering is null-safe for optional card elements and validates same-origin recommendation messages.

## Match Lifecycle Propagation Fix (MLP Fix)

- Browser, ACAMR and other browser-observation club-index payloads remain non-canonical.
- `recordObservedClubMatchReference()` continues to insert newly observed matches with canonical `status='unknown'` and stores the browser bucket only in `observed_status`.
- A new repository divergence check detects when `observed_status` is one of `registered`, `in_progress`, or `finished` and differs from canonical `status`.
- Such divergence queues the existing authoritative `sync_club_matches` work with priority discovery.
- Canonical queue identity remains `club-match-index`, so repeated browser/ACAMR/Fast Fetch observations coalesce to one outstanding authoritative club-index audit.
- Only the authoritative server club-index fetch promotes lifecycle into canonical status; browser observations never write canonical lifecycle directly.

## Achievement catalogue progress-bar context

- General catalogue (no player selected): no family or group progress bars are rendered.
- Player-specific catalogue: family/group progress bars remain, and per-achievement progress remains available from member intelligence.
- The rule is applied both to the Dashboard achievement catalogue modal and the embedded Achievements/Hall catalogue page.

## Validation

- Focused DRR/MLP/catalogue tests: PASS.
- Existing observation/OAuth/dashboard focused regressions: PASS.
- Full pytest suite after integration: 412 / 412 PASS.
- Dashboard main-bundle size guard remains enforced and PASS (<172000 bytes).
- `dashboard-v2.js` and `find-match.js` JavaScript syntax: PASS.
- TournamentAchievementBadgesDemo inline JavaScript syntax: PASS.
- Repository.php and ObservationIngestor.php PHP syntax: PASS.
