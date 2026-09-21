# v2.12.2 Green-native production-read audit

Scope: current production/public/dashboard/Administration read paths only. This audit deliberately excludes historical migrations, installer internals, write-side compatibility projection, repair/reconciliation code, and unrelated worker queues.

## Implemented now

| Live read | Previous dependency | Native Green source | Classification | Action |
|---|---|---|---|---|
| Recent matches / “New matches · 24 h” | `p2k_tp_match_metadata.first_discovered_at` | `p2k_g_matches.created_at` plus club-index provenance | migrate | Read native Green directly. Index-only Daily P2K matches are visible before detail hydration/projection. |
| Club key resolution used by public reads | `p2k_tp_state` first | `p2k_g_state` | migrate | Probe native Green state first; retain compatibility probes only for non-Green/legacy utility contexts. |

The recent-match response contract remains stable. Fields unavailable as native Green facts are returned safely without a compatibility join: `max_rating` remains present as `null`; `is_league` is derived from the established league-name markers; opponent slug is derived from the native opponent URL.

## Already Green-native on the production path

- Dashboard match lists: `Repository::publicDashboardMatches()` reads `p2k_g_matches`.
- Dashboard/team totals: `publicClubDashboard()` returns `greenNativeClubDashboard()` when Green is production.
- Player summary: `publicPlayerSummary()` prefers `greenNativePlayerSummary()`; compatibility reads are resilience fallbacks, not the normal Green path.
- Hall/Live analytical materializations use `p2k_an_*` / `p2k_lr_*`. These are current Analytics products, not the old Core compatibility projection and are not part of this migration.

## Follow-up migration debt — do not rewrite in this patch

| Surface | Current compatibility dependency | Why not migrated here |
|---|---|---|
| Events Showcase catalog | Match facts now come from `p2k_g_matches`; optional logo enrichment remains `p2k_tp_opponents`. | Registered/Daily membership, timing and names are Green-native. The legacy opponent table is non-gating visual enrichment only; a failed/missing logo lookup cannot hide a match. |
| Recruitment rating pool | `p2k_tp_members` | Verified Daily/960 ratings exist in `p2k_g_players`, but recruitment deliberately prefers newer claim-backed observed ratings when fresh. Those observed-rating provenance fields are not native Green facts yet. Moving only the verified part would change eligibility/rating precedence. |
| Match insights / match sections | `p2k_tp_match_metadata`, summaries, opponent aliases/profiles, void tables | Core match facts exist natively, but the public contract combines alias repair, opponent enrichment, historical aggregates and compatibility summary semantics. Requires a dedicated equivalence migration. |
| Member insights / player profile | members, participations, match metadata, point events plus Analytics | Green has the underlying player/match/event facts, but these endpoints also depend on MIAC aliases, chronology, achievements/live materializations and recent-match aggregation. Too broad for a freshness hotfix. |
| Match detail / opponent profile / league seasons / team insights | multiple `p2k_tp_*` facts and aggregate tables | Native equivalents are partial and the existing contracts aggregate several domains. Migrate per endpoint with parity tests rather than table substitution. |
| Opponent/admin profile cache and Events logos | `p2k_tp_opponents`, aliases | Green currently has no first-class opponent metadata/icon table. |
| Recruitment/admin and player-card profile overlays | `p2k_tp_members` profile/observation fields | Green player facts do not yet contain every passive-observation/profile provenance field used by these tools. |
| Public read generation/freshness metadata | Green source now reads `p2k_g_state`. | Cache generation uses stable cycle/discovery/analytics checkpoints rather than noisy `updated_at`; legacy `readState()` is retained only for non-Green contexts. Existing endpoint TTLs are unchanged. |

## Legitimate retained compatibility/internal code

`GreenCompatibility`, compatibility reconciliation/GAB, historical import/repair routines, legacy rollback/reference paths, and installer/schema migration code remain intentionally out of scope. Their existence does not make them production factual read sources.

Operational `p2k_tp_jobs`, queue/log/state tables are also not to be mechanically renamed or removed: some are still active orchestration stores rather than factual compatibility projections. They require a separate lifecycle audit.

## Regression contract

A Daily P2K match known only through Green club-index discovery must appear in the recent-match window before match-detail hydration or `GreenCompatibility::projectMatch()`. Old, non-Daily, foreign-club and unverified rows must not appear.
