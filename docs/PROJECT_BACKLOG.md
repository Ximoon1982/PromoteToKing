# Promote to King — Project-level backlog

**Last reviewed:** 2026-11-12  
**Scope:** P2K core and explicitly identified adjacent club-widget integrations.  
**Canonical location:** `docs/PROJECT_BACKLOG.md` in `Ximoon1982/PromoteToKing`.  
**Initial register authored from historic ChatGPT project conversations and repository/issue review.**  
**Release boundary:** The active development increment is **v2.15.9** on `release/v2.15.9`. Backlog inclusion alone **does not authorize implementation**, version changes, branch changes, deployment, or promotion.

## Maintenance rules

1. Every project idea, deferred enhancement, technical debt item, or verified follow-up receives a permanent `P2K-BL-###` ID. Never reuse IDs; close entries rather than deleting them.
2. Status vocabulary: **APPROVED-DEFERRED** (user expressly accepted into backlog, *not* authorized for coding), **IDEA** (discussed, not approved), **REASSESS** (historical proposal requiring present-code audit), **VERIFY** (implementation reported, operational evidence outstanding), **ACTIVE** (work explicitly authorized in the current increment), **DONE** (completed, retained as historical cross-reference), **CANCELLED**.
3. Each entry must record source conversation **date (Europe/Luxembourg)**, a **searchable quotation or topic**, relevant versions and concrete GitHub references where known. The ChatGPT conversation descriptors below are retrieval keys, *not fabricated chat links*.
4. Before starting an item, compare with current code and already-promoted versions; only fix actual gaps. Preserve existing data, identity/alias evidence, runtime semantics, security protections and Release Control contracts.
5. Update `Last reviewed`, item status, evidence, and last action when an item is implemented, deferred further, or closed. Maintain the separation between project backlog and the currently authorized release scope.
6. No automatic merging into `main`. Backlog updates should travel via the authorized active branch and established release workflow.

## Active and candidate register

| ID | Item | Status | Relevant version(s) | Source date |
| --- | --- | --- | --- | --- |
| P2K-BL-001 | Compact monthly match calendar widget | **APPROVED-DEFERRED** | v2.15.3+ widget platform; future version TBD | 2026-11-11 |
| P2K-BL-002 | Advanced Arenas Insights using pairings and games | **REASSESS** | v2.10.8 MCA groundwork; v2.15.x Arenas Insights | 2026-08-28 |
| P2K-BL-003 | Green worker vs browser/OAuth throughput telemetry | **REASSESS** | v2.10.8 Green Accelerator and later Green migration | 2026-08-28 |
| P2K-BL-004 | Work-class lifecycle / starvation diagnostics | **REASSESS** | v2.10.8+ Green task processing | 2026-08-28 |
| P2K-BL-005 | Additional public widget concepts (six ideas) | **IDEA** | v2.15.3 widget system | 2026-08-28 / 2026-11-11 |
| P2K-BL-006 | Optional Multi Club Arenas in TGP and Team France widgets | **APPROVED-DEFERRED** | Adjacent widgets, 2026-11-10 proposals | 2026-11-10 |
| P2K-BL-007 | Recruitment CSV archive/session recovery live qualification | **ACTIVE** | v2.15.9 | 2026-11-10 to 2026-11-12 |
| P2K-BL-008 | MCA username-change CSV correlation operational validation | **VERIFY** | v2.15.7–v2.15.8 | 2026-11-10 to 2026-11-12 |
| P2K-BL-009 | v2.15.9 cumulative Release Control package qualification | **ACTIVE** | v2.15.9 (includes v2.15.7–.8) | 2026-11-12 |
| P2K-BL-010 | MCA games CSV / results cross-check expansion | **REASSESS** | v2.10.6–v2.10.8; v2.15.x | 2026-08-19 / 2026-08-28 |
| P2K-BL-011 | Six selected widgets and Team → Widgets administration | **DONE** | v2.15.3 | 2026-11-11 |
| P2K-BL-012 | MCA/MIAC admin rename review and row-correlation feature | **DONE** (verify via 008) | v2.15.7–v2.15.8 | 2026-11-10 |
| P2K-BL-013 | MCA source inventory: successes versus warnings, no overlap | **DONE** (historical) | v2.15.4–v2.15.5 | 2026-11-11 |

## Individual entries and retrieval references

### P2K-BL-001 — Compact monthly match calendar widget
**Status:** APPROVED-DEFERRED. **Do not implement unless explicitly requested.**  
**Source conversation:** 2026-11-11, P2K widget concepts and Team → Widgets work. Search history for **"monthly match calendar"**, **"260 px"**, or **"future-improvements backlog"**. User accepted POC into backlog at ~12:36 Europe/Luxembourg.  
**Issue:** [GitHub #5 — Future improvement: compact monthly match calendar widget](https://github.com/Ximoon1982/PromoteToKing/issues/5).  
**Artifact reference:** `P2K_Match_Calendar_Widget_POC.html` (standalone illustrative POC supplied in original conversation; **not** a live-data implementation).  
**Version dependency:** Integrate on top of already-delivered v2.15.3 Team → Widgets, not as part of authorized v2.15.9 fixes.  
**Requirements/acceptance:** Width 260px; existing dark charcoal/gold visual language; current month default; previous/next and return-to-current; Monday-first days; zero-count days shown; All / League / Friendly segmented toggle, gold League and green Friendly; click day reveals all/league/friendly totals; use UTC scheduled daily match start dates and existing match classification; compact/responsive without horizontal overflow. Optional later date-click match list with Chess.com links. Prior to shipping, validate live source coverage, UTC handling, caching, and iframe embed sizing.  
**Last action:** Logged and linked to existing GitHub issue 2026-11-12.

### P2K-BL-002 — Advanced Arenas Insights based on pairings / games
**Status:** REASSESS; not an approved new release change.  
**Source conversations:** 2026-08-28 P2K v2.10.8 audit and Arenas backlog (history search: **"advanced arena insights"**, **"game pairings"**, **"MCA Games CSV"**); subsequent November 2026 MCA Results/Insights work.  
**Version context:** v2.10.8 MCA parser and pairings; v2.15.x Arenas Insights / leaders already delivered.  
**Potential work:** Determine what stored pairing/game data are actually consumed by current public Insights; only add genuinely missing advanced analytics after verifying source quality and completeness. Avoid treating existing basic Arenas Insights as missing.  
**Acceptance gate:** Inventory existing queries/fields, identify measurable gap, design a bounded aggregation, test consistency with MCA results and player identity resolution.  
**Last action:** Historical proposal recovered 2026-11-12.

### P2K-BL-003 — Split Green worker versus browser/OAuth throughput
**Status:** REASSESS; implementation state unverified.  
**Source conversation:** 2026-08-28 v2.10.8 audit/backlog. Search for **"Green throughput"**, **"browser/OAuth"**, **"throughput"**.  
**Version context:** v2.10.8 Green Accelerator and subsequent Green-primary migration and maintenance operations.  
**Potential work:** Display independently measured Green server worker throughput and browser/OAuth throughput plus correctly calculated combined total (without double counting).  
**Acceptance gate:** Verify current metrics and define counters, time windows and worker ownership first. Avoid introducing expensive telemetry to shared hosting.  
**Last action:** Reassessment proposed 2026-11-12.

### P2K-BL-004 — Detailed per-work-class processing and starvation diagnostics
**Status:** REASSESS; implementation state unverified.  
**Source conversation:** 2026-08-28 v2.10.8 processing/report backlog. Search for **"queued"**, **"handed out"**, **"stalled"**, **"starved"** and **"work-class"**.  
**Version context:** v2.10.8+ unified task/Green processing.  
**Potential work:** Per work class and processing actor report queued, handed out, completed, failed, stalled/starved counts; historic conversation additionally discussed client/session/user-level breakdown.  
**Acceptance gate:** Audit existing Scheduled Tasks / Admin metrics and logs, choose bounded non-invasive metrics and identify a genuine diagnostic gap before coding.  
**Last action:** Reassessment proposed 2026-11-12.

### P2K-BL-005 — Additional widget concepts, unapproved idea pool
**Status:** IDEA only. **No approval or implementation authorization implied.**  
**Source conversations:** 2026-08-28 widget brainstorming/backlog and 2026-11-11 widget-concepts session. Search for **"Latest Results"**, **"Arena Champions"**, **"My Chess Footprint"**.  
**Version context:** v2.15.3 widget-management foundation.  
**Ideas:** (1) Latest Results; (2) Member Leaderboard; (3) League & Competition Tracker; (4) Arena Champions; (5) Next Club Milestone; (6) My Chess Footprint.  
**Next action:** Obtain separate prioritization and requirements before treating any idea as APPROVED-DEFERRED or ACTIVE. Do not conflate these with six completed v2.15.3 widgets.

### P2K-BL-006 — Optional Multi Club Arenas in TGP and Team France widgets
**Status:** APPROVED-DEFERRED / proposed upgrade; no completed implementation confirmed. Adjacent club-widget work, not P2K 2.15.9 scope.  
**Source conversation:** 2026-11-10 **Golden Phoenix / Team France widget** discussion. Search for **"Both widgets"**, **"WCGC"**, **"Multi Club Arenas"**, **"arenaSectionEnabled"**. User specified the desired capability; assistant explicitly described proposed code only.  
**Version context:** Team France hub/tools `f4c56177...` and English/tricolour restoration `5409a75f...` reported committed but not deployed at that point; check their actual repository/current state before acting.  
**Requirements:** Recognize WCGC; optional MCA section controlled independently in each widget; disabled state must reproduce existing appearance unchanged; enabled state separates Arena and Daily Match sections; arena item settings local to each widget; preserve settings on repeated installations. Relevant older widget request (2026-09-16): up to two optional MCA slots, each with Active, Name, Link, Time control and Duration. Confirm whether these are already present before proposing new behavior.  
**Acceptance gate:** Backward-compatibility visual test for disabled state, correct enabled grouping and settings isolation, repeated installer preservation, live source and links.  
**Last action:** Retained as adjacent backlog 2026-11-12.

### P2K-BL-007 — Recruitment CSV archive / admin session recovery
**Status:** ACTIVE in explicitly authorized **v2.15.9** only.  
**Source conversations:** 2026-11-10 **Recover Work Scope**, user report **"Scan complete, but the recruitment CSV could not be archived: The secured Team Points session could not validate this request."**; 2026-11-12 **Recover current workspace of P2K, backlog and scope of 2.15.9**. Related earlier archived CSV/session fix in v2.15.2.  
**Version / source:** `release/v2.15.9`; before this backlog commit HEAD `e449ac12fabc9266df59f9f87b49bd116ece9e60`.  
**Implemented changes reported:** same-admin reauthorization keeps valid CSRF/session ID; expired session or identity switch rotates securely; bounded one-time JS retry only for definite CSRF/session failure; completed scan CSV retained for manual archive retry without repeating scan.  
**Evidence files:** `server/team-points/src/Auth.php`, `assets/js/shared/team-points-client.js`, `assets/js/pages/recruit-match.js`, `tests/test_v2159_admin_session_reconnect.php`, `tests/test_v2159_team_points_client_recovery.cjs`.  
**Remaining acceptance:** Run tests in a recovered exact checkout; verify long scan plus competing tabs, expired session, real unauthorized denial, same-origin protection, archive/download and retry on production-like preview; no duplicate archive entries or loss of generated CSV.  
**Last action:** Branch changes verified 2026-11-12, full live qualification not yet verified.

### P2K-BL-008 — MCA rename correlation operational verification
**Status:** VERIFY (feature already implemented in v2.15.8; not a request to redesign).  
**Source conversations:** 2026-11-10 **Recover Work Scope**, request **"if a name is missing, the manager shall re-DL the CSV to see what name is at the same place, correlation is enough to link the aliases"**; v2.15.7 MIAC workflow and v2.15.8 CSV re-download/correlation qualification.  
**Version context:** `main` v2.15.8 `d539f9e5893e5d7b2be5ef6e4bfa68630dc4f7db`; retained cumulatively in v2.15.9 packaging.  
**Implemented approach:** Bounded re-download of Results CSV for missing usernames, same-row substitution correlation, MIAC alias evidence and attribution reconciliation with ambiguity safeguards.  
**Remaining acceptance:** Test with a verified real Chess.com rename, distinguish identity changes from transport failures or changes in sorting, demonstrate MIAC alias link, preserve original source evidence, prevent unrelated players being merged.  
**Last action:** Runtime follow-up recorded 2026-11-12.

### P2K-BL-009 — Cumulative v2.15.9 Release Control package qualification
**Status:** ACTIVE; no deployment/promotion authorized.  
**Source conversation:** 2026-11-12 **Recover current workspace of P2K, backlog and scope of 2.15.9**.  
**Source branch/starting point:** `release/v2.15.9` at `e449ac12...`, 12 commits ahead of `main` at `d539f9e5...` before backlog record.  
**Workflow:** `.github/workflows/p2k-v2159-recruitment-auth-release-control.yml`. Candidate artifact planned as `P2K_Recruitment_Archive_Session_Fix_v2159_ReleaseControl_Upload.zip`.  
**Contract:** Cumulative MCA v2.15.7/2.15.8 payload for compatible known public builds; candidate version 2.15.9, integrity manifests, Release Control package validation, preserve all existing app data and runtime, no database migration, no auto-install. Note repository root `VERSION` was 2.15.3; package builder intentionally writes 2.15.9.  
**Remaining acceptance:** Verify workflow execution/artifact and accepted build-head mapping against actual public release, install via existing preview process, end-to-end smoke/rollback checks.  
**Last action:** Candidate build workflow committed; outcome still to verify.

### P2K-BL-010 — MCA Games CSV and result-statistic reconciliation
**Status:** REASSESS; historical deferred data work, possible overlap with P2K-BL-002.  
**Source conversations:** 2026-08-19 MCA source-data and index reconciliation (search **"games CSV explicitly deferred"**, **"played/won"**); 2026-08-28 v2.10.8 audit (search **"MCA Games CSV"**, **"Results played/won cross-check absent"**).  
**Version context:** MCA v2.10.6–v2.10.8 baseline; substantial v2.15.x result extraction improvements since.  
**Potential work:** Determine whether Games CSV acquisition / metrics and independent Chess.com Results played/won cross-check still offer missing value, without compromising the authoritative Results CSV ingestion.  
**Acceptance gate:** Audit current coverage and results integrity before any new fetch/reparse work; preserve source CSVs and identities, keep requests bounded.  
**Last action:** Historical item classified REASSESS 2026-11-12.

### P2K-BL-011 — Six selected widgets / Team → Widgets admin
**Status:** DONE; do not reimplement.  
**Source conversation:** 2026-11-11 widget POC → Team → Widgets implementation. Search **"Club Pulse"**, **"Recruitment Radar"**, **"Team → Widgets"**, **"previewed and promoted"**.  
**Version / commits:** v2.15.3 implementation `82bb965...`, follow-up candidate `b60ddb0...` (conversation references, abbreviations to verify if needed).  
**Delivered:** Club Pulse/Points, Recruitment Radar, Trophy Spotlight, Rivalry Spotlight, Club Progress, P2K Around the World; Team → Widgets subtabs, previews and iframe code; Match Showcase moved from Competition. User confirmed preview and promotion on 2026-11-11.  
**Last action:** Recorded DONE 2026-11-12.

### P2K-BL-012 — MCA / MIAC rename review + CSV row correlation
**Status:** DONE for code delivery; operational follow-up lives in P2K-BL-008.  
**Source conversations:** 2026-11-10 **Recover Work Scope**, user requirements for aliases and re-downloading matching source CSV rows; earlier 2026-11-11 MCA review and processing discussions.  
**Versions:** v2.15.7 (MIAC review), v2.15.8 (automatic correlation); main commit `d539f9e5893e5d7b2be5ef6e4bfa68630dc4f7db`.  
**Last action:** Closed as implementation completed, validation remains in BL-008.

### P2K-BL-013 — MCA stored/results/errors inventory reconciliation
**Status:** DONE historically; do not reopen without a fresh failing example.  
**Source conversation:** 2026-11-11 MCA importer/inventory discussion. Search **"successfully stored/parsed arenas"**, **"errors/warnings"**, **"31071472"**, **"688"**, **"98.4%"**.  
**Version context:** v2.15.4–v2.15.5, with later v2.15.6–.8 identity/data changes.  
**Original acceptance:** Paginated separate mutually exclusive success and error/warning sets, recovered CSV arenas removed from erroneous list, index reconciliation and non-destructive Results prioritization. Prior status cited 677/688 Results coverage and 11 Results gaps; these are historical counts, **not current live metrics**.  
**Last action:** Retained as closed context 2026-11-12.

## References / source index

- [P2K GitHub project](https://github.com/Ximoon1982/PromoteToKing), current work branch `release/v2.15.9`.
- [GitHub issue #5 — monthly calendar accepted backlog](https://github.com/Ximoon1982/PromoteToKing/issues/5).
- 2026-11-11 P2K widget concepts, standalone calendar POC, Team → Widgets, MCA inventory and success/warning separation.
- 2026-11-10 **Recover Work Scope**, MCA rename/aliases, recruitment archive failure; Golden Phoenix / Team France widget discussion.
- 2026-08-28 v2.10.8 P2K audit / project backlog: arena pairings/games, throughput, queue/work-class diagnostics and widget idea pool.
- 2026-08-19 MCA source-data reconciliation, Results versus Games CSV collection and results statistics.
- 2026-09-16 widget administration: optional MCA rows/fields.
- 2026-11-12 **Recover current workspace of P2K, backlog and scope of 2.15.9** and **Recover project level backlog**.

**Retrieval limitation:** ChatGPT history has not supplied stable conversation URLs/IDs for the older conversations. Dates, project/version labels, distinct phrases and referenced commits/issues serve as unambiguous searchable anchors; add real conversation URLs only when directly available. Never invent them.
