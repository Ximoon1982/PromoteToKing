# P2K v2.11.2 — Structural Consolidation & Maintainability Release

## Contract

This release changes internal ownership only. It preserves the complete v2.11.1 UI, display, rendered DOM, workflows, functions, API routes and payloads, data semantics, database schemas, persistence, scheduler behavior, OAuth/session behavior and operational behavior. JavaScript source files and entrypoint references change solely to activate behavior-equivalent internal modules.

## Consolidation

- `ClubIntelligenceService` remains the public compatibility facade and delegates read-only PDO mechanics to `SqlReadGateway`.
- `AnalyticsBuilder` retains every public signature and delegates refresh marker paths, marker reads and lock-timeout recognition to `AnalyticsRefreshRuntime`.
- `AchievementCatalog` retains catalogue order, fields and public methods while delegating artwork fallback resolution to `AchievementArtwork`.
- `admin-features.js` is a compatibility bootstrap over bounded Admin runtime, logs, diagnostics, history, match-management and recording controllers.
- `dashboard-v2.js` remains the page compatibility facade while Admin shell/session, embedded detail hosting, tools, personal home, insights, team summary, match assistant/list and startup wiring live in explicit factories.
- Classic-script loading and factory creation preserve the established synchronous global contracts; the dependency graph and entrypoint order are machine checked.

## Gates

CI compares public PHP signatures with the promoted v2.11.1 commit, rejects visual asset/style and runtime changes outside the explicit structural allowlist, checks module dependency direction/cycles/load order, runs DOM/rendered/startup-race and complete inherited source/browser suites, and qualifies the exact source and universal incremental installer artifacts. Compatibility facades are retained for every existing caller.
