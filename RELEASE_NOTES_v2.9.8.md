# Promote to King v2.9.8

v2.9.8 is the first numbered release integrating the post-v2.9.7 observation/provenance and continuous client-refresh staging work together with production Chess.com OAuth and the requested Insights/Hall corrections. It is built from the exact verified v2.9.7 release and reconciles the staging overlays semantically rather than replacing newer source files wholesale.

## Real Chess.com OAuth (`?oauth=2`)

- `?oauth=2` now activates the real Chess.com Authorization Code + PKCE S256 flow from the normal site login control.
- The Chess.com access token remains exclusively in the protected server-side PHP session and is never returned to page JavaScript.
- The existing avatar/profile presentation is retained. The profile modal's existing Log off action is the real OAuth logout anchor.
- The obsolete "Concurrent Chess.com API access" control is removed.
- Logged-out and simulated `?oauth=1` traffic remains serial.
- Real OAuth PubAPI reads use a same-origin server gateway and PHP cURL-multi. Concurrency is discovered adaptively and reduced on HTTP 429, server/transport errors or latency pressure instead of using the POC benchmark's aggressive fixed target.
- OAuth configuration remains host-owned in `server/team-points/config/oauth.local.php`; release payloads never contain that private file.
- `install-oauth-v2.9.8.sh` creates the protected configuration for new installations and defaults the callback to `/auth/callback`. Existing protected configuration is preserved unchanged by the updater.

### OAuth validation scope

The production flow enforces state, PKCE S256, exact redirect URI, HTTPS token exchange, ID-token `alg=RS256`, Chess.com issuer, configured audience and expiration. The access token is obtained directly by the backend token exchange and retained server-side. Independent JWT signature verification is not claimed by this release because no signing-key/JWKS discovery mechanism is bundled; this limitation is explicit rather than silently treating a decoded JWT as fully verified.

## Opportunistic API forwarding / ACAMR

- Integrates the post-v2.9.7 claim-bound observation/provenance work and continuous client-refresh card after reconciling them with the OAuth source changes.
- Operational browser-observed freshness and server-verified freshness remain distinct.
- Claim-backed observations can suppress exact duplicate discovery calls but cannot become canonical authority for points, achievements, membership, match results or ratings used as verified facts.
- Server-side targeted match/board verification and hard audit ceilings remain mandatory.
- Existing v2.9.7 fair Player scheduling and false-completion recovery are preserved.

## Insights corrections

- Members / Monthly active members: the past/future delimiter now sits at the previous month, so the current incomplete month is displayed on the future/incomplete side.
- Team / Club Points score progression: adds Daily Club Points on an independent right axis. Daily points stop at today and never extend into the projection region.
- Team / Daily boards and Club Points is renamed **Daily boards and current active boards**. The right axis now shows current active boards rather than Club Points.
- Opponents heatmap now exposes paired-rating coverage and explains why historical coverage grows as authoritative `sync_match` CRON/worker revalidation revisits stored matches.

## Hall of Fame

- Fixes unified-search achievement totals incorrectly displaying zero because the search endpoint treated the already-materialized player profile as a nested `player` response.
- Search totals now use the same player achievement data/catalogue as the opened catalogue.

## Administration telemetry

- Adds a rolling 10-minute Chess.com OAuth Bearer throughput chart on the Administration page.
- Plots per-minute average requests/second and peak requests/second from server-side gateway completions.
- The telemetry endpoint is administrator-protected and stores aggregate transport metrics, not Bearer tokens.

## Data / compatibility

- Core schema: **11** (was 10).
- Analytics schema: **6** (unchanged).
- Core 11 is additive and preserves existing canonical data.
- Achievement catalogue remains **162** definitions.
- No destructive reset or reseed.
- CRON cadence is unchanged from v2.9.7.
