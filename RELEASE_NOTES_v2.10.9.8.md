# Promote to King v2.10.9.8

Recruitment administration and Members Insights table-alignment release built from the canonical v2.10.9.7 baseline.

## Administration → Members → Recruitment

- Adds a Recruitment panel to Team Points Administration → Members, following the approved v2 proof of concept.
- Administrators can maintain one normalized, ordered server-side candidate pool from Chess.com usernames or player/profile URLs.
- Recruitment criteria are snapshotted when a run starts. Resuming an unfinished run keeps the original candidate set and criteria instead of silently reinterpreting completed candidates.
- Mandatory rules reject closed/unavailable accounts and players already stored as current Promote to King members. Former-member exclusion remains optional.
- Configurable criteria include Daily rating range, timeout percentage, rating deviation, current Daily-game range, completed Daily games, last-online age, optional average seconds per move, optional account age and browser parallelism.
- The browser performs bounded parallel Chess.com profile/stats/current-Daily-game reads; the server owns the durable pool, run state and per-candidate checkpoints.
- Closing or reloading the page does not lose completed work. Start / resume processes only candidates still pending.
- Selected candidates can be exported as CSV with the evaluated recruitment metrics and decision reason.
- Recruitment state is stored under the existing Team Points runtime directory. There is no database schema change and no runtime reset.

## Members Insights score table correction

- The player score rows already include the Result coverage percentage before Win rate.
- v2.10.9.8 restores the missing matching Result coverage header before Win rate, eliminating the visible `100%` column shift and realigning Win rate and every following column with their values.
- No scoring formula or stored member result is changed by this corrective UI fix.

## Preservation

- Core and Analytics schemas remain unchanged.
- Existing Team Points runtime data, credentials, MCA data, Fair Play state, CRON definitions and prior release artifacts are preserved.
- v2.10.9.7 remains the immutable predecessor and source baseline for this release.
