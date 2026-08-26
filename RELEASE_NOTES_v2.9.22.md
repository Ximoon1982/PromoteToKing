# Promote to King v2.9.22

Date: 2026-08-14 (Europe/Luxembourg)
Baseline: exact frozen PromoteToKing_Standalone_v2.9.21.zip
Core schema: 15
Analytics schema: 7
Achievement catalogue: 162 (unchanged)

## Fresh Points Reconstruction

Scheduled Task Control now includes a **Fresh Points Reconstruction** card for deliberate, client-driven recovery of Club Points and current-member Player Points. The reconstruction is isolated from canonical Core data until explicit administrator approval.

### Common workflow

- Select Club Points, Player Points, or both.
- Start, pause and resume a durable reconstruction run. Completed acquisition units are checkpointed server-side, so browser suspension/reload does not discard staged work.
- Fresh Chess.com acquisition uses the shared adaptive OAuth transport with network-only requests.
- Dedicated overall and per-track progress plus per-phase tables expose found, pending, processed, resolved, unresolved and failed work.
- Live metrics cover matches, members, boards, games, reconstructed points, requests, failures, checkpoints and queue impact.
- At completion a review compares reconstructed facts/totals with current Core. No canonical record changes before approval.
- Explicit approval promotes the selected track under the normal worker lane lock, rebuilds Analytics and marks only obsolete durable work as superseded rather than deleting unrelated queue work.

### Club Points reconstruction

- Seeds discovery from every known Core match ID without trusting existing status or score.
- Performs a fresh opening club match-index query; if unavailable, a bounded blind-tail probe starts after the true highest known match ID.
- Freshly refetches all discovered match details and recomputes P2K status, board count and final score.
- Finished 0-0 records are explicitly classified as void/excluded from Club Points rather than treated as authoritative scored results.
- Performs bounded closing match-index passes and processes matches discovered while reconstruction was running.
- Review reports reconstructed finished matches, score, changed matches, 0-0 void records and queue work that would be superseded.
- Approval overrides differing stored match status/score/Club Points facts with the reconstructed facts.

### Player Points reconstruction

- Fetches a fresh opening current-member roster.
- Queries the team-match history of every current member.
- Falls back to Chess.com game archives when player-match discovery is unavailable/incomplete, deriving team-match IDs from archive games.
- Resolves every candidate participation to the member's P2K board, fetches board/game results and reconstructs Player Points independently of stored board/game facts.
- Performs a fresh closing roster check, processes newly joined members, repeats closing player-match discovery and resolves newly found boards.
- Review reports members, boards, games, reconstructed current-member points, changed members and queue impact.
- Approval replaces the reconstructed current-member board/game history and Player Points authority while retaining unrelated historical/non-current-member data.

## ACSR Canonical Drain Mode correction

v2.9.22 corrects the bottleneck exposed by large post-dedup canonical backlogs:

- Club worker capacity is now 100 queue items per invocation rather than the historical 25-item ceiling.
- Club execution receives an authoritative 34-second floor with a 36-second hard ceiling, even when a protected older `config.local.php` still contains the old 25-second value.
- Browser ACDM authoritative pulses now adapt from 16 up to 64 items according to canonical debt/productivity.
- External Club CRON therefore drains substantially more useful work even when browsers are closed or Android suspends the page.
- Existing P0 interactive-survival rules remain: foreground gateway work has priority and automatic background work yields when interactive work is waiting.
- Shared OAuth learning/rate targets are unchanged; this release attacks the worker/scheduling bottleneck rather than raising Chess.com pressure.

## Database and compatibility

- Additive Core 14 -> 15 migration introduces reconstruction run/staging tables and per-track apply timestamps.
- Analytics remains revision 7.
- No destructive reset, Team Points re-seed or achievement migration is required.
- Existing protected `server/team-points/config/config.local.php`, OAuth configuration and `data/server-config.json` remain private and are not shipped/replaced by the incremental updater.
- CRON topology remains four operational jobs plus the weekly long-life backup.
