# Promote to King v2.10.3 — OAuth default, display identity, achievements and Green consolidation

v2.10.3 is a cumulative application release on top of v2.10.1.2. The public Team Points source remains Blue-hardwired with the verified Blue engine baseline v2.9.22.10 while Green reconstruction continues.

## Authentication and display identity
- Real Chess.com OAuth is now the normal/default mode on every API-capable production page; `oauth=2` is no longer required.
- Explicit `?oauth=1` remains the simulated/test-account override.
- Team Points Migration follows the same default while retaining its parallel OAuth bridge fallback for the browser accelerator.
- `?name=<username>` adds a display-only identity. It changes player-facing subject/rendering only when a real authenticated session exists. The real OAuth session continues to govern administration, CSRF, bearer/API transport and server authorization.
- A visible **Viewing as <name>** cue is rendered when display identity differs from the authenticated account.

## Achievement challenges
- Dashboard and unified Player Profile challenge surfaces no longer disappear when an optional intelligence query fails.
- Optional member-intelligence enrichment is fail-soft; core member/profile responses remain available.
- Dashboard always retains the Achievements/View surface; Profile replaces failed challenge loading with an explicit unavailable state.

## Achievement catalogue
The catalogue grows from 162 to 191 definitions with 29 additive achievements in six families:
- Consecutive match-start days: 3 / 5 / 7 / 10 / 14 days.
- Match Impact: Match Winner and Match Saver ladders at 1 / 5 / 10 / 15 / 20.
- Close calls: Photo Finish / Close-Call Veteran / Master / Legend at 5 / 20 / 50 / 100.
- Winning Side: 10 / 50 / 100 / 250 winning P2K team matches.
- Opponent Variety: 25 / 50 / 100 distinct opponent clubs.
- Old Foes: 5 / 10 / 25 rematch participations after the first meeting with an opponent.

Match Impact is derived only from stored authoritative finished-game points and finished non-void match scores. A winner contribution is one whose removal would make the P2K team no longer win; a saver contribution is one whose removal would turn a team draw into a loss.

Legacy breadth semantics remain frozen. The newer additive breadth model may recognize the new categories.

## Artwork
- 54 approved 640×640 masters from the seven supplied artwork archives are integrated byte-for-byte after manifest SHA-256 verification.
- Matching 128×128 WebP thumbs and 64×64 WebP miniatures are generated from the approved masters.
- The 54 artworks cover both newly added families and approved replacements for existing placeholder families.

## Green reconstruction hotfix consolidation
v2.10.3 folds the final v2.10.1.2 Green patch line into the numbered release:
- Green profile/stats seeding-stage stall hotfix.
- Green browser accelerator terminal HTTP 404/410 handling hotfix.

The cumulative updater accepts v2.10.1.2 installations with neither patch, only the profile/stats patch, or both patches already installed, and converges them to the same v2.10.3 files.

## Storage capacity monitoring
- Database/storage history is now rolled up by ISO week instead of calendar month.
- Growth and capacity projection use weekly measured change once at least two weekly observations exist; daily sampling remains available as bootstrap evidence.

## Compatibility / operations
- Public Team Points source: Blue hardwired.
- Blue engine baseline: v2.9.22.10.
- Green migration version: v2.10.3.
- Core schema: 16 unchanged.
- Analytics schema: 7 unchanged.
- No database reset or reseed.
- No Green cycle/stage/routing/force-mode changes by the installer.
- Existing OAuth credentials/configuration and local server configuration are protected.
