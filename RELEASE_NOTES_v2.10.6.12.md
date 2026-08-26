# Promote to King v2.10.6.12 — Administration shell reorganization

## Scope

v2.10.6.12 is an **admin-only UI release** on top of the authoritative v2.10.6.11 source. It reorganizes the authenticated Dashboard Admin view without changing the public Dashboard, Hall of Fame or Insights surfaces and without replacing the existing administration tools.

## 1. Six top-level administration views

The authenticated Admin toggle now opens a shell organized as:

- **Competitions** — Daily Matches, Multi Club Arenas, Tournaments.
- **Members** — Team Depth, Chronology, Aliases & name changes.
- **Team** — Club intelligence.
- **Opponents** — Opponent intelligence.
- **Admin & maintenance** — Diagnostics, Scheduled Task Control, Logs, Storage & Capacity, Performance, Freshness, Traffic & visitors.
- **Misc** — Lost & found catalogue.

The selected tab is preserved in the existing navigation state through `adminCategory`.

## 2. Live operational metric cards

The new cards reuse existing authoritative P2K endpoints and data products rather than introducing another backend layer. Cards surface concise operational metrics from Green Core/Analytics, MCA data, tournament data, Club Intelligence, protected runtime telemetry and first-party traffic analytics.

Each card shows:

- current **status**;
- **freshness** / observation age or live-read state;
- explicit **source/provenance**;
- direct links into the existing detailed tool or filtered administration context.

A manual **Refresh live cards** action is provided. Automatic card reloads are guarded by the existing authenticated-admin path and a short client-side refresh interval.

## 3. Existing tools remain authoritative

The shell is an entry/navigation layer only. Existing admin pages, iframes, APIs and operational controls are preserved.

In particular:

- Scheduled Task Control remains the existing implementation, including the **Green accelerator control**.
- Production Migration remains available and is deep-linked from Scheduled Task Control.
- MCA source-data, match creation, team depth, member chronology, aliases, diagnostics, reconciliation, storage, Club Intelligence, Opponent Intelligence and other detailed tools continue to use their existing implementations.

## 4. Lost & found catalogue

The **Misc → Lost & found tools** view renders the complete pre-existing administrator tool catalogue. The validated catalogue remains **36 tools**; none is removed by the new shell.

## 5. Regression lock

The public user-facing surfaces are intentionally outside this release scope:

- Dashboard public section: unchanged from v2.10.6.11.
- Hall of Fame section: unchanged from v2.10.6.11.
- Insights section: unchanged from v2.10.6.11.

The focused release regression test fingerprints those sections and fails if they change.

## Deployment impact

- No database schema migration.
- No database reset or reseed.
- No CRON definition change.
- No Green/Blue routing change.
- No Team Points scoring change.
- No image/artwork change.
- No existing source file removed.
