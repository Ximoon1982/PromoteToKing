# Security and privacy

## Content Security Policy

Every HTML page includes a Content Security Policy restricting content to local assets plus the explicitly required Chess.com API/image and optional analytics origins. `object-src` is disabled, framing is same-origin, base URLs are restricted, and forms cannot submit to another origin.

Inline executable scripts, inline event handlers, `document.write`, and `unsafe-eval` are not used. Inline CSS remains allowed because existing pages use authored style attributes and the retained-tab shell adds an embedded presentation override.

## API origin enforcement

`P2K_API_CLIENT` accepts only HTTPS origins listed in `P2K_SITE_CONFIG.api.allowedOrigins`. JSONP uses the same allowlist and cannot be used for an arbitrary URL.

JSONP executes a remote script and is therefore retained only as a compatibility fallback after conditional and ordinary CORS fetch both fail. Its usage count is visible in Diagnostics and it can be disabled with `jsonpFallback: false` after deployment evidence shows it is unnecessary.


## Challenge Assistant server storage

The Python launcher locally and the packaged PHP backend online expose the saved list only through `/api/challenge-club-list`; direct requests under `/data/` are denied.

Save requests require:

- HTTP `PUT` with `application/json`;
- the `X-P2K-Request: challenge-club-list` header;
- a same-origin `Origin`/`Host` match when the browser sends an Origin header;
- a loopback client address by default;
- a matching revision number.

The default Python bind address is `127.0.0.1`. `--allow-remote-write` removes only the loopback restriction and should be used exclusively on a trusted network. The PHP backend permits same-origin writes so online saving works, but same-origin validation is not authentication. Protect public administration deployments with IONOS directory protection, HTTP Basic authentication, or another server-side access control.

Payload size, club count, slug length, and slug syntax are bounded and validated server-side. Writes are atomic and retain one backup revision.

## Match Assistant usage logs

Completed user-initiated Match Assistant analyses are sent to `/api/match-assistant-log` with same-origin JSON and a dedicated request header. The server, not the browser, supplies the UTC timestamp and daily filename. Each log record contains only the event type, normalized username, UTC timestamp, and matches-found count. Automatic synchronized refreshes, cancellations, and failed analyses are excluded.

Daily JSONL files under `/logs/` are never served as static files. Aggregated reads use `/api/match-assistant-logs`. Python-local reads are restricted to loopback clients by default; `--allow-remote-log-read` removes that restriction. The online PHP read endpoint is same-origin and should be protected by server-side authentication when the site is public.

Log writes are intentionally non-blocking in the browser: an unavailable logging endpoint never affects Match Assistant results. Server-side payload length, username length/control characters, and match-count bounds are validated before append.

## Analytics

Analytics is optional and is loaded through `assets/js/shared/analytics.js` only. It is skipped when:

- the central feature flag is disabled;
- a page is embedded in the retained-tab shell;
- the site is running on localhost;
- Global Privacy Control is enabled;
- Do Not Track is enabled.

The tools do not depend on analytics for any calculation or control.

## Simulated authentication

The optional login is a UI simulation based on a public Chess.com username. It never requests or stores a password, token, or private Chess.com account data. The selected username and public profile snapshot are stored locally in the browser.

## Deployment

Use HTTPS for production. Keep all packaged paths together and avoid adding third-party scripts outside the CSP and central configuration. Run the automated verification commands before deployment.

## Administration visibility flag

The `admin` URL flag reveals Diagnostics, Match Recruitment, and Challenge Assistant in the index shell. It is deliberately a presentation switch comparable to the simulated OAuth flag, not an authentication mechanism. Anyone who knows the URL can set it, and standalone page URLs remain addressable. Protect public administrative tools and server APIs with hosting-level authentication or another server-side access control.

## Cron tracker and match-history data

The tracker requires the private token from `data/server-config.json` as the `token` query parameter. A missing configuration returns HTTP 503; a mismatch returns HTTP 403. Use HTTPS because query strings may otherwise be exposed in transit, and rotate the token if the URL is disclosed. The token authorizes only snapshot collection; it does not grant access to raw files.

Snapshots are stored under `data/match-tracking/matches/`, and execution logs under `logs/scheduled-tasks/`; both are denied as static content by both server implementations. The public analytical endpoint returns only parsed snapshot content for a numeric match ID. Administration manual recording uses same-origin POST plus `X-P2K-Request: track-upcoming-league-matches`; cleanup uses same-origin DELETE plus `X-P2K-Request: tracked-match-data`, but same-origin checks and the `admin` visibility flag are not authentication. Protect public Administration access and destructive endpoints with hosting-level authentication.


## Current OAuth flag requirement

Stored simulated-login data alone never enables administration. Automatic club-administrator verification requires OAuth to be explicitly enabled for the current page. Direct administration pages enforce the same rule, while the `admin` URL flag remains an explicit client-side override. These checks do not replace hosting-level authentication.
