# Promote to King v2.12.2

Corrective/incremental release integrating the Events Showcase, restoring the approved Trophy Gallery card presentation, and closing Match Recruitment administration/controller integration defects.

## Events Showcase
- Native Administration → Competitions card and editor.
- Top-level Showcase administration now expands in the normal Administration detail area rather than opening as a modal; individual arena editing remains modal.
- Compact metrics for configured/enabled arenas and curated Daily matches.
- Schema-4 protected state with optimistic revision checks, URL-key arena de-duplication, chronological arena sorting, automatic expiry cleanup and preserved legacy migration from `data/priority-matches.json`.
- Arena editing is save-only in a child modal; Enabled remains immediate.
- Daily match catalogue is paginated at 20; curated rows retain manual order, enable/disable and Priority.
- Existing/default line presentation remains supported and its generated iframe uses a fixed `height="280"`.
- Added the validated POC v18 r4 card presentation: 260px, two-column cards, `⚔️ Arenas ⚔️` / `⚔️ Daily Matches ⚔️`, maximum two arenas and four Daily matches, League/Friendly fallback, theme-adaptive transparent embedding and fixed `height="480"` host iframe.
- Administration exposes separate line/card URLs, copyable iframe HTML and live previews using the real public renderers.
- Both public presentations use the canonical Events Showcase state and shared recruitment/showcase browser model.

## Match Recruitment
- Match Recruitment now opens inside the normal Administration detail area while reusing the existing embedded Recruitment application.
- Fixed the false `Load match did not bind to the recruitment controller.` failure. The bootstrap now awaits an explicit controller-ready contract after asynchronous administrator-access initialization rather than assuming the script `load` event means form handlers are already bound.
- Genuine controller initialization failures still reject the readiness contract and remain visible to the user.
- Existing DB-first filtering, opponent-roster verification, shared API scheduler, Profile behavior, CSV export and optional match-load enrichment are retained.

## Trophy Gallery
- Current v2.12.1 functionality is retained.
- Trophy cards reproduce the approved `fe163d85…` / R5fix3.8 presentation (geometry, artwork containment, border/radius/background and centered gold title).
- Sole intentional visual difference: the trophy title has no inherited `border-bottom`.

## Release / migration
- No database schema reset.
- Mutable data, local configuration, uploads, runtime storage and CRON are preserved.
- Installer is a cumulative scope-limited **2.12.x → 2.12.2** overlay. It accepts existing semantic version 2.12.0 or 2.12.1 and converges every immutable production file changed since the qualified v2.12.0 baseline.
- The installer does not require byte-identical source files. Scoped stale/missing/degraded files are replaced transactionally; qualification explicitly covers a 2.12.0-shaped target with an empty `assets/js/admin/tool-registry.js`.
- Qualification covers both supported source versions, preservation of unrelated files/mutable state/CRON, idempotency and exact forced-failure rollback. Non-2.12.x source versions are rejected before transaction activation.
- Every qualified package receives an immutable cache key derived from exact source HEAD plus build identity, including both Showcase public renderers.
