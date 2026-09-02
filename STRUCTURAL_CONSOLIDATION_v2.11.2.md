# P2K v2.11.2 — Structural Consolidation & Maintainability Release

## Contract

This release changes internal ownership only. It preserves the complete v2.11.1 UI, display, DOM, JavaScript runtime bytes and order, workflows, functions, API routes and payloads, data semantics, database schemas, persistence, scheduler behavior, OAuth/session behavior and operational behavior.

## Consolidation

- `ClubIntelligenceService` remains the public compatibility facade and delegates read-only PDO mechanics to `SqlReadGateway`.
- `AnalyticsBuilder` retains every public signature and delegates refresh marker paths, marker reads and lock-timeout recognition to `AnalyticsRefreshRuntime`.
- `AchievementCatalog` retains catalogue order, fields and public methods while delegating artwork fallback resolution to `AchievementArtwork`.
- The large JavaScript bundles remain byte-identical in v2.11.2. Their responsibility map is now explicit and frozen; runtime splitting is deferred until it can preserve byte/order semantics under browser qualification.

## Gates

CI compares public PHP signatures with the promoted v2.11.1 commit, hashes UI/HTML/CSS/JS/manifests byte-for-byte, rejects changes outside the six approved PHP structural files, runs the complete inherited source/browser suite, and qualifies the exact source and installer artifacts. Compatibility facades are retained for every existing caller.
