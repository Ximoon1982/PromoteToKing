# Migration notes — v2.9.8

v2.9.8 performs one additive Core migration and no destructive data operation.

- Core schema: **10 → 11**.
- Analytics schema: **6** (unchanged).
- Migration: `server/team-points/sql/core-migration-post-v2.9.7-observation-provenance.sql`.
- Adds observation/provenance and claim-bound freshness fields used by opportunistic API forwarding / ACAMR.
- Existing verified timestamps, canonical ratings, durable queue rows, point events, achievements and match data are retained.
- Do **not** reset or reseed either database.
- Existing protected `server/team-points/config/oauth.local.php` is retained unchanged.
- CRON cadence remains the v2.9.7 cadence; only the numbered dispatcher/installer filenames advance to v2.9.8.
