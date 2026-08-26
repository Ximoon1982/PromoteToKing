# Promote to King v2.9.16

## Scope

v2.9.16 is the prepared post-v2.9.15 integration release. It is built directly from the exact frozen v2.9.15 standalone SHA256 `83813c121c82b6c318e230065baaf52fd6930854ebdbb2723acb4159f0dc070e`, using internal source/package history only.

## Reliability and convergence

- **Dashboard Recommendation Resilience Fix (DRR).** Recent successful Match Assistant recommendations remain available as a presentation fallback during transient live-refresh failure, while live acquisition still runs and cached fallback display never renews its own age.
- **Match Lifecycle Propagation Fix (MLP).** Browser lifecycle observations stay non-canonical, but registered/in-progress/finished divergence queues the authoritative coalesced club-index audit so canonical lifecycle converges promptly.
- **Fast Fetch Stability & Diagnostics Fix (FFSD).** Fast Fetch/Continuous Refresh records work-class failure reasons (HTTP, timeout, gateway, cache/lease, abort and observation rejection), clears sticky healthy-state errors, has a liveness reschedule, uses null-safe Task Control rendering and separates canonical checks due from browser work claimable now.
- **UI v2 Deep-Link Routing Fix (UDR).** Direct UI-v2 Administration/deep links retain `ui=v2`, survive the pre-authentication startup phase, restore compatible alias/subview/context state, and remain stable through browser history instead of being rewritten to Dashboard/UI v1 before admin resolution.
- **Player Matches Archive Fallback Fix (PMAF).** A known-valid current member whose Chess.com `/matches` endpoint is specifically unusable can fall back to bounded `/games/archives` discovery. Transient/auth/rate-limit failures do not activate fallback, unrelated personal-history matches do not fan out, and the primary endpoint is periodically re-probed.

## Opponent Intelligence

- **Opponent Intelligence Coverage & Results Fix (OICR).** W/D/L uses full canonical result history independently from the 200-row detail window; result coverage/missing results are explicit; `loss` maps correctly to `losses`; win-rate classifications use covered results.
- Missing strength points in Opponent Balance can be derived from canonical paired-board rating evidence where metadata averages are absent, increasing honest heatmap coverage without inventing data.
- Opponent links are normalized to human `https://www.chess.com/club/<slug>` URLs at write and read boundaries, fixing historical PubAPI-form links without a database migration.

## Achievement catalogue and artwork

- The approved 1WL, PCL, TCMAC, TMCL and KOTML league-specific artwork is integrated for all 35 league achievements: exact approved 640×640 PNG masters, 128×128 WebP thumbnails and 64×64 WebP miniatures.
- General achievement catalogues no longer display family/group progress bars. Progress remains available only in a player's catalogue.

## Compatibility

- Core schema: **13** (unchanged)
- Analytics schema: **6** (unchanged)
- Achievement catalogue definitions: **162** (unchanged)
- External CRON cadence: **4 tasks, unchanged**
- OAuth adaptive tuning namespace and P0 Interactive Survival policy: unchanged
- No database reset or reseed
