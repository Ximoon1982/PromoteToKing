# Promote to King v2.9.9

v2.9.9 is a focused authenticated-throughput release built from the exact verified v2.9.8 package. It does not change scoring, achievements, database schemas or CRON cadence. Its purpose is to remove caller-side bottlenecks that prevented the real Chess.com OAuth Bearer transport from reaching the adaptive throughput demonstrated by the OAuth POCs.

## Real OAuth throughput policy

- `?oauth=2` remains the only real Chess.com OAuth mode. The access token stays exclusively in the protected server-side PHP OAuth session and is never exposed to page JavaScript.
- Anonymous/public traffic and simulated `?oauth=1` remain serial/conservative.
- Real OAuth starts at a concurrency target of 8 and probes upward while batches remain healthy. The shared controller uses a deep reservoir of up to four waves per target and retains learned tuning in the browser session for 30 minutes.
- Healthy growth proceeds progressively (for example 8 → 12 → 16 → 24 → 36) rather than restarting from 4 on every small batch.
- HTTP 429 halves pressure immediately and honors `Retry-After`/backoff. Transport/5xx errors reduce pressure, and sustained throughput plateaus or sharply increased latency stop upward probing.
- The server OAuth gateway accepts up to 512 queued PubAPI requests per batch and uses PHP cURL-multi with a runtime-safe open-file/connection cap. Feature pages no longer impose their own low concurrency ceilings when real OAuth is active.

## Cross-site OAuth propagation and startup

- Fixed the site router so a parent page running `oauth=2` propagates **`oauth=2`**, not `oauth=1`, to embedded and standalone tools.
- Fixed the router's flag parsing so value `2` is recognized as real OAuth rather than treated as false.
- All pages that use the shared Chess.com API client now load the real-OAuth adapter.
- Chess.com requests on an `oauth=2` page wait for the initial server-side OAuth session probe before selecting the transport. Auto-running tools can no longer begin their first scan in anonymous serial mode merely because the OAuth adapter was deferred.

## CRON Control / continuous speed fetch

- The continuous client refresh / speed-fetch card now requests up to 256 due tasks under real OAuth instead of 48.
- The server planner accepts up to 512 tasks and scans a deeper due-work reservoir.
- All scheduled tasks are handed to the shared adaptive transport together, allowing cURL-multi to stay fed through multiple probe waves.
- The card does not maintain a competing feature-level concurrency setting; the shared OAuth gateway is the single pressure controller.

## ACAMR

- Real OAuth ACAMR uses a substantially deeper feed: default 48 claims per pulse, 5-second pulse target and 600-member scan batch, with bounded caps.
- Anonymous/simulated behavior retains the conservative historical pulse and claim limits.
- Claim-bound observation/provenance rules from v2.9.8 remain unchanged; browser observations still cannot become canonical authority for points, match results, achievements or verified ratings.

## Match and recruitment tools

The following callers now feed the shared adaptive scheduler rather than retaining feature-era serial loops or hard worker counts:

- Find Match, including retry scans.
- Upcoming Matches analysis, including failed-match retries.
- Match Creation Analyzer, including targeted chart-detail reloads.
- Challenge Assistant bulk checks, recommendation evaluation, board-detail acquisition and profile/match-index pairs.
- Recruitment Demand Planner; the former hard four-worker pool is removed.
- Router/admin club-profile verification uses the shared client when available.

## Tournament ingestion

- Tournament Management now uses the shared OAuth-aware API client for historical discovery, podium/group reads, placement lookups, retry work and medalist profile validation.
- The legacy 550 ms paced requester remains only as a fallback when the shared client is genuinely unavailable; it is not used in the normal real-OAuth site path.
- Cancellation and repair checkpoints remain intact.

## Server-maintenance paths

Some admin operations POST to P2K server endpoints rather than directly scheduling browser PubAPI reads. Those paths now also use the authenticated Bearer transport when a real OAuth session exists:

- Live Ranks former-player profile verification dynamically probes multiple profiles per server step instead of the former hard 8-profile serial step.
- Opponent-club maintenance batches club profile/fallback checks through the server-held Bearer token.
- Temporary 429/5xx/transport failures remain pending/retryable and cannot incorrectly classify a player as renamed/closed or an opponent club as disabled.
- CLI/CRON workers deliberately remain anonymous/serial because v2.9.9 does not persist a user's OAuth token for unattended background use.

## Validation

The dedicated browser throughput gate queues 256 mocked Chess.com calls and verifies the real-OAuth shared client actually produces successive batches of 32 at target 8, 48 at 12, 64 at 16 and 96 at 24, then probes to 36. The same gate verifies immediate reduction to 18 when a synthetic 429-pressure batch is reported.

## Data / compatibility

- Core schema: **11** (unchanged).
- Analytics schema: **6** (unchanged).
- Achievement catalogue: **162** (unchanged).
- No migration, reset or reseed.
- CRON cadence unchanged from v2.9.8.
- Existing protected `server/team-points/config/oauth.local.php` is preserved unchanged.
