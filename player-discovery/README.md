# P2K Player Discovery — v0.1.0

Standalone, OAuth-gated player discovery tool intended to be hosted at `/player-discovery/` under Promote to King.

## Purpose

The tool accepts a large seed population (up to 100,000 Chess.com usernames), scans a configurable rolling period of Daily archives for each original seed, discovers opponents, de-duplicates the union, then enriches each unique player from the Chess.com PubAPI.

The default window is **2 rolling months**. Because Chess.com archives are monthly, a rolling two-month period normally touches three archive buckets (the cutoff month, the intervening month, and the current month). Games themselves are filtered against the frozen job cutoff/end timestamps.

Discovery is deliberately **one hop only**: newly found opponents are enriched but are not recursively archive-scanned unless they were also present in the original seed list.

## Transport

* P2K Chess.com OAuth authenticates access to the tool and its persisted jobs.
* Chess.com PubAPI reads are performed directly from the browser to `https://api.chess.com`.
* No Chess.com Bearer token is exposed to this application.
* The browser scheduler adapts concurrency upward on sustained success and downward on HTTP 429, while observing Retry-After when available.
* Job checkpoints travel only to same-origin `api.php` and require the P2K OAuth CSRF token.

## Persistence and resume

Two namespaced MariaDB tables are created lazily in the existing P2K Core database:

* `p2k_pd_jobs`
* `p2k_pd_players`

Each original seed is a resumable discovery unit; each unique player is a resumable enrichment unit. Claims use leases. A stable browser worker ID allows the same browser to reclaim its own interrupted leases immediately after reopening. The Web Locks API prevents two tabs in the same browser from intentionally running the worker at once when supported.

Jobs belong to the authenticated Chess.com account. The stable OAuth `playerId` is used when available so a username rename does not orphan saved jobs.

## Enrichment fields

The stored/exported result includes seed/discovery source, encounter count, Chess.com player id, canonical username, account status, title, public name/location/country, joined timestamp, last-online timestamp, followers, FIDE rating, Daily rating/RD/W-D-L/timeout/time-per-move/best rating/last game, total club count, and active-club counts for 30/90 days.

## Hosting

Copy the whole `player-discovery/` directory to the Promote to King document root. It expects the existing P2K backend at `../server/team-points/` and the existing logo at `../assets/images/p2k-logo.jpg`.

The P2K Core database user must be able to create the two namespaced tables on first use. The schema marker is stored under the configured P2K runtime directory (`player-discovery/schema-v1.ok`). Bump `SCHEMA_VERSION` when changing table structure.
