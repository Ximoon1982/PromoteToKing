# Architecture

## Design goals

The repository remains deployable as ordinary static files while sharing network, cache, analysis, and configuration logic. Each tool also remains directly usable as a standalone page.

The user interface is intentionally isolated from infrastructure changes: existing page markup, CSS classes, labels, filters, result cards, and charts are preserved. Infrastructure changes preserve the existing page layouts. User-visible additions since 2.0.0 are the Administration Diagnostics tab and the shared viewport-aware information-popover behavior; analytical calculations and ordinary controls are unchanged.

## Runtime layers

```text
index.html retained-tab shell
  └── one persistent same-origin iframe per tool
       ├── standalone HTML shell
       ├── local page CSS
       ├── site-config.js
       ├── shared API cache and explicit API client
       ├── optional shared analysis coordinator / analytics
       ├── shared information-popover component where required
       ├── shared concise control-hint layer
       └── page controller or shared analysis core
```

The tab shell never parses or replays another page's scripts, never calls `document.write`, and never destroys a loaded tool when changing tabs.

## Page implementations

| Entry point | Controller and shared modules |
|---|---|
| `FindMatch.htm` | `find-match.js`, explicit client/cache, analysis coordinator, optional analytics and simulated login |
| `AnalyzeMatches.htm` | lightweight mode wrapper plus `upcoming-analysis-core.js`, explicit client/cache, analysis coordinator |
| `MatchCreationAnalyzer.htm` | `match-creation-analyzer.js`, `match-creation-charts.js`, explicit client/cache, analysis coordinator |
| `AnalyzeMatch.html` | `analyze-match.js`, explicit client/cache |
| `AnalyzeMatchModal.html` | lightweight modal mode wrapper plus `upcoming-analysis-core.js`, explicit client/cache |
| `RecruitMatch.html` | `recruit-match.js`, explicit client/cache |
| `ChallengeListAssistant.html` | URL validation, activity classification, ordered challenge recommendation, explicit client/cache |
| `index.html` | `site-tabs.js`, `admin-features.js`, explicit client/cache, analysis coordinator, simulated login |

The Upcoming page and detailed modal use the same analytical core. Their wrappers only describe page-specific presentation differences.

## Central configuration

`assets/js/site-config.js` defines routes, club slug, league identifiers, approved API origins, networking defaults, cache calibration values, and feature flags. Runtime controllers read this object rather than maintaining independent route or league lists.

`site-manifest.json` is packaging metadata. It lists every shipped file and records runtime capabilities, but it is not the runtime source of truth.


## Local and online application backends

`serve_local.py` uses `ThreadingHTTPServer` and `SimpleHTTPRequestHandler` from the Python standard library for local use. `api/index.php` and the endpoint directories implement the same contract on Apache/PHP hosting. The browser therefore uses the same same-origin endpoints in both modes:

```text
GET /api/challenge-club-list
PUT /api/challenge-club-list
POST /api/match-assistant-log
GET /api/match-assistant-logs
GET/POST /api/track-upcoming-league-matches
GET /api/match-history
GET/DELETE /api/tracked-match-data
```

The Challenge Assistant, Match Assistant usage logger, Administration log explorer, history chart, and match-data management panel use ordinary same-origin `fetch`; these endpoints are intentionally outside `P2K_API_CLIENT`, which remains restricted to approved Chess.com API origins. Endpoint directories provide extensionless URLs without requiring PHP filenames in the frontend.

The stored record contains a schema version, monotonically increasing revision, UTC update timestamp, and ordered de-duplicated club slugs. Writes use a temporary file, `fsync`, atomic replacement, and a backup of the preceding record. A process-local re-entrant lock serializes concurrent saves. The browser supplies the revision it last loaded; stale writes receive HTTP 409 and do not overwrite newer data.

Club-slug validation is deliberately limited to transport safety rather than undocumented Chess.com naming assumptions: lowercase letters, digits, and hyphens are accepted in any position, provided at least one letter or digit is present.

The frontend treats storage as optional. A failed initial read does not block any Challenge Assistant function. Automatic loading fills only untouched empty inputs; manual loading replaces only the active internal tab.

Completed user-initiated Match Assistant analyses append one compact JSON object per line to `logs/match-assistant/YYYY-MM-DD.jsonl`. The server supplies the UTC timestamp and selects the daily filename, while the browser supplies only the canonical username and current filtered match count. A process-local lock serializes appends. Automatic synchronized refreshes and cancelled or failed analyses do not send a log event.

The read endpoint aggregates a selected UTC date range and optional case-insensitive username substring. It returns total analyses, total matches found, distinct usernames, daily aggregates, per-user aggregates, and recent matching entries. Direct requests under `/logs/` and `/data/` are denied. Python-local log reads are loopback-only unless the launcher is explicitly started with `--allow-remote-log-read`; the online PHP deployment should be protected by hosting-level authentication when exposed publicly.

## Explicit API client

`assets/js/shared/api-client.js` captures and calls native `fetch`; it never replaces `window.fetch`.

### Scheduling

- One bounded queue is shared by calls within a page.
- Endpoint and task priorities determine initial order.
- Equal priorities retain insertion order.
- Waiting tasks gain priority over time to prevent starvation.
- Configured and adaptive concurrency are reported separately.

### Rate limiting and resilience

- `Retry-After` pauses the host-wide queue.
- Retry delays include jitter to avoid synchronized retries.
- Repeated transient failures open a short circuit pause.
- Concurrency reduces after rate limits and recovers gradually after successful requests.
- Conditional fetch failures retry without conditional headers before JSONP.

### Structured errors

Errors include category, code, HTTP status, URL, retryability, retry delay, and attempt number. Categories cover cancellation, timeout, network, not found, forbidden, rate limit, server, client, parse, and cache failures.

### Batch results

`processPriority()` returns separate succeeded, failed, and pending entries. The result can retry failures or resume pending entries without rerunning settled work. Page controllers decide how those entries map to their domain UI.

## Cache and request coordination

`assets/js/shared/api-cache.js` owns storage and duplicate-request coordination only.

### Storage

- IndexedDB stores response body, headers, validators, timestamp, approximate size, endpoint kind, and detected match state.
- A bounded in-memory LRU cache keeps the client useful when IndexedDB is unavailable or full.
- Malformed cached JSON is awaited and removed before a network retry.
- Quota errors trigger one emergency cleanup and one write retry.

### Retention

Scheduled pruning is disabled. See `CACHE_CALIBRATION.md` for the proposed measurement-based thresholds.

The cache differentiates:

- network-preferred club match indexes;
- active match JSON;
- finished match JSON;
- unknown-state match JSON;
- player data;
- other endpoints.

Freshness controls when the network is contacted; retention controls when stored records may be deleted. They are intentionally separate.

### Cross-tab duplicate coordination

`BroadcastChannel` is an optional optimization:

- local claim windows store only claims relevant to a locally pending URL;
- start and heartbeat events maintain renewable leases;
- recent completion memory prevents completion-before-waiter races;
- expired claims, leases, and completion records are cleaned periodically;
- channels and timers close on page teardown.

A shared local request owns an internal abort controller and tracks subscribers. One caller cancelling does not cancel the request for remaining subscribers.

## Analysis synchronization

`assets/js/shared/analysis-coordinator.js` publishes completion events between retained iframe documents through `BroadcastChannel` with a local-storage event fallback.

- Find, Upcoming, and Creation register refresh handlers.
- The source tool is excluded from its own event.
- Busy tools postpone by declining the current event rather than interrupting work.
- Synchronized refreshes carry a flag and do not publish another completion event.
- Find requires an already known username; Upcoming and Creation can initialize from their shared club match data.

This shares cached responses and avoids discarding each tool's page state. It does not merge unrelated view models or change the tools' calculations.

## Retained-tab shell

`assets/js/pages/site-tabs.js` creates one same-origin iframe per route and keeps it mounted. The current frame is visible and the others are hidden. A newly loading frame remains hidden until the parent has installed the embedded presentation override, preventing the standalone border/background from flashing before the retained-tab form is ready.

Benefits:

- loaded result DOM and controller state survive tab changes;
- global variables and styles remain isolated by document;
- standalone pages remain the only tool implementations;
- script replay, HTML parsing, and document-write compatibility are eliminated.

The parent applies an embedded-only presentation override inside each same-origin document so the existing index shell continues to provide the common heading without changing standalone pages. The `admin` URL flag controls whether Diagnostics, Match Recruitment, and Challenge Assistant are included in the visible route set; hidden administrative frames are not preloaded.


## Shared information popovers

`assets/js/shared/info-popover.js` and `assets/css/info-popover.css` provide one delegated component for dynamically rendered analytical information. Compact explanations use one generated tooltip per document; Match Assistant recommendation details retain their own interactive dialog element.

The component measures the trigger and the currently visible viewport, selects above or below placement, clamps horizontal placement, and repositions on local or same-origin parent scrolling and resizing. Retained iframe documents derive the parent-visible region from the iframe rectangle so popovers remain next to their trigger even when the parent page has scrolled through a tall embedded tool.

Outside pointer presses, Escape, parent-document interaction, and removal of the active rendered trigger close the open popover. Fine-pointer hover remains available, while touch devices rely on explicit tap state to avoid sticky emulated hover. All information buttons use the same blue visual treatment; embedded action buttons retain their existing page-specific style.

## Concise control hints

`assets/js/shared/control-hints.js` applies short native `title` descriptions to useful controls that do not already define one. It is selector-based, preserves page-defined titles, and observes dynamically rendered controls. Information triggers (`.p2k-info-button`) and every element inside `.p2k-info-popover` are excluded because their purpose is already to expose explanatory material. The module changes no classes, dimensions, labels, or styles.

## Diagnostics

`assets/js/pages/admin-features.js` initializes only when the index URL contains the `admin` flag. It reads public diagnostic snapshots from the parent and retained same-origin frames. It does not inspect private user content beyond the runtime counters exposed by those modules.

It can clear each frame's shared cache instance and copy a support snapshot. It also queries the local usage-log API, defaulting to the latest seven UTC days, and renders date/username filters, seven-day expansion, daily aggregates, per-user aggregates, matching entries, and distinct-username counts. It is enabled through the central feature flag.

The same snapshot includes the central analytical-model registry from `P2K_SITE_CONFIG.modelVersions`. Model identifiers are calculation revisions, independent from the package release, and are documented in `docs/MODEL_VERSIONS.md`.

## Analytics

`assets/js/shared/analytics.js` is the only analytics loader. It is configurable and automatically disabled when embedded, served locally, or when browser privacy signals request it. Ordinary tool functionality never depends on analytics.

## Security boundary

Every page has a Content Security Policy. The API client enforces the configured HTTPS origin allowlist independently of CSP. JSONP is confined to the explicit client and approved API origins. No inline executable script, inline event handler, global fetch interception, `document.write`, `unsafe-eval`, or remote source-code loader remains.

## Release-consistent asset loading

All HTML entry points request local JavaScript and CSS with the repository release version as a query token. The retained iframe router applies the same token to internal tool-page URLs. Standalone pages opened from tab names use clean URLs without release query parameters. `match-priority.js` owns league-first/date-second ordering independently of the API transport, while `api-client.js` keeps compatibility aliases. This prevents a cached older API client from breaking a newer analyzer controller.

## League-match history storage

The cron tracker is a same-origin server operation implemented in both `serve_local.py` and `api/index.php`. It reads the Promote to King club match index, follows registered match references, filters configured league acronyms, and writes one immutable snapshot per match and invocation to `data/match-tracking/matches/<match-id>/<UTC timestamp>.json`. Snapshot writes are atomic and preserve partial success when one match request fails.

`/api/match-history/?match=<id>` returns a chronological, bounded list of valid snapshots. The browser never reads raw files. `assets/js/shared/match-history-ui.js` passes each stored match JSON through the current page's analytical summarizer, so historical win probability uses the same model as live analysis. The host remains hidden unless usable snapshots are returned.

`/api/track-upcoming-league-matches/` accepts token-protected GET requests from the cron and same-origin, custom-header POST requests from Administration; both call the same snapshot routine. `/api/tracked-match-data/` exposes aggregate inventory metadata to Administration and accepts a confirmed same-origin DELETE for one numeric match ID. Each authorized cron or manual execution is wrapped by a shared execution logger that appends one record to `logs/scheduled-tasks/YYYY-MM-DD.jsonl`, including UTC start/end timestamps, duration, trigger type, status, and counts. `/api/scheduled-task-logs/` provides filtered read access to the Administration panel. Raw history and log directories remain inaccessible through Apache and the Python static handler.

## Administration panels

The admin-only modal contains retained internal panels for Logs, Scheduled tasks, Diagnostics, and Match data management. Panel switching does not recreate the index or tool frames. The match-data panel can invoke the tracking routine manually through a same-origin POST without exposing the cron token. It also reports match ID, name, league, file count, first/last timestamp, scheduled start, and server-calculated started/upcoming state.


## Focus-aware retained tools

The index creates a tool iframe only when its tab is selected. `tab-activity.js` receives same-origin activity messages, and `api-client.js` combines that activity signal with each caller signal. Inactive tools abort queued/active API acquisition. `analysis-coordinator.js` discards synchronized refreshes for inactive tools rather than waking hidden pages.

## Administration page gate

Administration-only standalone pages load `admin-page-guard.js` before their controllers. The guard accepts the explicit admin flag, a same-origin verified parent administration state, or a currently OAuth-enabled session verified against the configured Chess.com club administrator list.
