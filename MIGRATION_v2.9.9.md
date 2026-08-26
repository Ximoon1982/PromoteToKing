# Migration notes — v2.9.9

v2.9.9 is a code/transport release and requires **no database migration**.

- Core schema: **11** (unchanged from v2.9.8).
- Analytics schema: **6** (unchanged).
- Achievement catalogue: **162** (unchanged).
- No reset, reseed or data rewrite.
- The Core-11 claim-bound observation/provenance migration introduced in v2.9.8 remains the active schema contract.
- Existing protected `server/team-points/config/oauth.local.php`, Team Points configuration, shared server configuration, data and logs are retained unchanged.
- CRON cadence is unchanged; only the numbered dispatcher/installer filenames advance to v2.9.9.
