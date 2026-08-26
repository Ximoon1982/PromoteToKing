# Migration notes — v2.9.7

No database schema migration is required.

- Core schema remains 10.
- Analytics schema remains 6.
- Existing v2.9.5 durable queue rows are retained.
- Do not reset/reseed the database to install this release.
- Old duplicate recurring Player/Stats/Profile queue rows are allowed to drain safely; already-current work is committed as `skipped` before outbound API access.
