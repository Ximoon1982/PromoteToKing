# Promote to King v2.9.17 migration

v2.9.17 is an in-place release from canonical v2.9.16.

- Core remains schema 13.
- Analytics remains schema 6.
- No database reset, reseed or SQL migration is required.
- The four-task CRON cadence is unchanged; the numbered dispatcher is advanced to v2.9.17.
- Protected `config.local.php`, `oauth.local.php`, `data/server-config.json`, runtime caches/state and production logs are not release payload state.
- ACDC persistent fairness and PMAF verification ledgers are bounded runtime state created automatically under the protected runtime root.
