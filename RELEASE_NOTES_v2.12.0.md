# Promote to King v2.12.0 — Match Recruitment Assistant

## Final release identity and universal upgrade

`VERSION` and `P2K_SITE_CONFIG.version` now identify 2.12.0. Unchanged 2.11.5
assets retain their identities; changed recruitment and site configuration bytes
use independently generated keys from the real release-runtime provenance commit.

The readable, network-independent `tools/release/v2120/` installer upgrades
supported canonical 2.11.x trees transactionally while preserving configuration,
OAuth/secrets, databases, data, storage, caches, uploads, logs and system CRON.
No database migration or CRON change is included.

The Match Recruitment Assistant now answers the operational question “Who is eligible to recruit?” using a DB-first, API-second pipeline.

- Resolves a Chess.com team-match URL, slug containing its numeric ID, or numeric ID.
- Selects stored Daily standard or Daily Chess960 ratings from the match rules.
- Applies rating, registration and opponent-membership exclusions before live player checks.
- Uses the shared OAuth/API scheduler for reduced-candidate last-online, timeout and current-load verification.
- Exposes configurable online-window and timeout thresholds, a live filtering funnel, observed-throughput ETA, explicit partial-failure results, sortable/searchable output and UTF-8 CSV export.
- Retains the canonical Admin guard, embedded detail integration, shared transport limits and current P2K data stores.

No database migration or new CRON task is introduced.

## Corrective qualification

- ETA derives from observed wall-clock completion throughput and a recent rolling window; it never assumes scheduler concurrency.
- Local Core rating/registration reduction runs before the opponent request. When candidates remain, opponent membership is fetched once through the shared API client with `no-store`; failure stops classification instead of accepting stale or unknown membership.
- Timeout eligibility uses Chess.com Daily `record.timeout_percent`, the same canonical metric as the existing P2K recruitment implementation. Missing values remain unverified.
- Profile, stats and opponent membership are hard checks using `no-store`, so stale-if-error data cannot produce eligibility. Current match load runs afterward as optional `/games` enrichment on the shared scheduler; its latency and failure do not delay or reject hard eligibility.
- The recruitment pool performs a lightweight Core rating query. Green Core/Analytics structural validation remains intact, but this request path does not execute population-wide Analytics recruitment/activity computation.
- Cache provenance procedure: commit the exact runtime assets first, then derive and stamp the loader key from that resolvable runtime commit plus a stable build ID in a qualification-only follow-up commit.
- The lightweight recruitment request does not invoke population-wide Analytics member-activity computation; the established Green Core/Analytics structural validation still applies.

## Post-release manifest correction

- `site-manifest.json` is now aligned with the v2.12.0 release identity and Match Recruitment Assistant scope instead of retaining the stale v2.11.0 manifest.
- The v2.12 production-path selector now manages and verifies `site-manifest.json`, preventing future package/site-config/manifest version drift from passing installer verification.
- The exact captured cumulative v2.11.5 production fingerprint was re-derived with the historical manifest included; no fuzzy baseline acceptance was introduced.
- Existing pre-correction v2.12.0 production installs can apply the standalone transactional `install-site-manifest-correction-v2.12.0.sh`; it accepts only the exact stale manifest hash, preserves the file mode, is idempotent, and rejects unknown manifest content.
