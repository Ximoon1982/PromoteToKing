# Promote to King v2.12.2

Corrective/incremental release integrating the Events Showcase and restoring the approved Trophy Gallery card presentation.

## Events Showcase
- Native Administration → Competitions card and editor.
- Compact metrics for configured/enabled arenas and curated Daily matches.
- Schema-4 protected state with optimistic revision checks, URL-key arena de-duplication, chronological arena sorting, automatic expiry cleanup and preserved legacy migration from `data/priority-matches.json`.
- Arena editing is save-only in a modal; Enabled remains immediate.
- Daily match catalogue is paginated at 20; curated rows retain manual order, enable/disable and Priority.
- Public unauthenticated transparent iframe shows at most two earliest enabled arenas and four curated Daily matches, with Upcoming/Registration/On-going arena phases and the validated League/Friendly presentation.
- Generated host snippet is a plain fixed-height iframe; no host-side JavaScript is required.

## Trophy Gallery
- Current v2.12.1 functionality is retained.
- Trophy cards reproduce the approved `fe163d85…` / R5fix3.8 presentation (geometry, artwork containment, border/radius/background and centered gold title).
- Sole intentional visual difference: the trophy title has no inherited `border-bottom`.

## Release / migration
- No database schema reset.
- Mutable data, local configuration, uploads, runtime storage and CRON are preserved.
- Installer is a scope-limited 11-file incremental overlay for the exact qualified v2.12.1 baseline; it intentionally rejects v2.11.x and v2.12.0 rather than performing full-tree convergence.
- The overlay verifies the four overwritten baseline files byte-for-byte, verifies seven new paths are absent, preserves unrelated application files, mutable state and CRON, and provides exact rollback/idempotency qualification.
- Every qualified package receives an immutable cache key derived from exact source HEAD plus build identity.
