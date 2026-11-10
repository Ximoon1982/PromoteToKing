# TGP Match Recruitment — standalone v2.15.3

URL: https://www.promotetoking.org/TGP/MatchRecruitment/

Installer: `bash TGP/MatchRecruitment/install-standalone.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing`

## Security and scope
- P2K OAuth identity and live TGP admin verification, using exactly the established `TGP/MatchAnalyzer/auth.php` implementation.
- Only matches containing `the-golden-phoenix`; recruitment is always from TGP's perspective.
- No P2K Core/Analytics databases, recruitment-pool endpoint, recruitment archive, or saved CSV.
- The recruitment core and visual style are copied from P2K. Player data comes directly from Chess.com in the browser.
- The same player-clubs response verifies both continued TGP membership and nonmembership in the opponent club.
- Unverified players are excluded from eligible CSV.
- A four-worker concurrency limit bounds browser-side Chess.com calls.

## Member roster and fallback
Private snapshot: `data/members-latest.json`, containing UTC retrieval timestamp and normalized usernames.
Successful full roster retrieval atomically replaces the saved snapshot. An invalid/partial/failed call never overwrites it.
If a saved snapshot exists, the visible warning includes its timestamp and explains that the member list must be configured Public on Chess.com while the analysis is in progress.
Without a valid snapshot, analysis fails and displays the same visibility guidance.
Both fresh and fallback-roster candidates are individually checked via Chess.com `/pub/player/{username}/clubs` before they can qualify.
The browser generates a downloadable CSV only when requested. No CSV is stored.

The installer copies only this application's source, preserves runtime `data/`, and never alters `.htaccess`, TGP Widget, TGP Analyzer, P2K databases, or Release Control.

## OAuth bearer transport and diagnostic log
- Every active Chess.com roster, match, profile, stats and player-clubs request is sent via P2K's already-existing **server-side OAuth bearer transport**, through the TGP-local `api/?action=batch` gateway. OAuth tokens never reach JavaScript.
- The gateway requires an authenticated TGP administrator, same-origin request and a matching OAuth CSRF header. It only accepts whitelisted Chess.com match and player endpoints, at most 12 URLs per batch. P2K's existing rate coordinator limits backend traffic. The roster continues to fall back to the last complete local snapshot when unavailable.
- Transient player API errors (HTTP 429 or 5xx) are retried up to three total attempts with bounded exponential backoff. Permanent errors do not become eligible players.
- The **Request / event log** at the bottom of the page is collapsed by default. It displays UTC timestamps, request paths, HTTP statuses, durations, retries, gateway errors and scan summaries. Copy/Clear actions are provided. It is session-local to the page and never contains OAuth tokens or request headers.


## v2.15.3 recruitment scheduler & display update

- Continuous multi-stage pipeline: each unregistered candidate advances from rating/statistics to live club membership/profile checks without waiting for a complete rating phase.
- At most 24 logical candidate workers, initially 18, falling back toward 6 when HTTP 429 responses occur, growing conservatively after successful checks. At most two concurrent PHP gateway POSTs; up to 12 Chess.com requests per POST. The existing server OAuth rate coordinator remains authoritative; we do not override its learned rate.
- Successful public player profile and statistics responses may be reused for 90 seconds in the same page; club membership requests are never reused across scans and must be reverified for eligibility.
- Request/event log stores up to 500 recent entries and only updates the expanded contents in batches. OAuth tokens and authorization headers are never logged.
- Analyzer-style header: Chess.com TGP club avatar retrieved through the authenticated gateway, P2K favicon, existing TGP assistant page title retained until a replacement is specified.
- Match name becomes an external Chess.com match link when resolved.
- Eligible player table is globally searchable and sortable, then paginated at **50 rows per page**, with navigation. Download CSV still includes **all** eligible players, not only the current page, and is never persisted.
- No changes to P2K Core/Analytics databases, existing Match Analyzer, Widget, global routing or Release Control.
