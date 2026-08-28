# Promote to King v2.10.7

Baseline: v2.10.6.25

## Locked release scope

This release contains only:

1. Eight new Team Points time-window achievements with their approved artwork set.
2. The approved `first-point` / **First Step** artwork replacement.
3. The confirmed achievement-progress computation corrective.

No MCA acquisition, Games CSV import, Administration, Arenas, or unrelated cleanup is included.

## New achievements

| Key | Name | Requirement |
|---|---|---|
| `team-points-day-2` | Daily Contributor | 2 Team Points in one UTC day |
| `team-points-day-5` | Consistent Day | 5 Team Points in one UTC day |
| `team-points-week-10` | Weekly Contributor | 10 Team Points in one ISO week |
| `team-points-week-20` | Consistent Week | 20 Team Points in one ISO week |
| `team-points-month-25` | Monthly Contributor | 25 Team Points in one UTC calendar month |
| `team-points-month-50` | Consistent Month | 50 Team Points in one UTC calendar month |
| `team-points-year-100` | Yearly Contributor | 100 Team Points in one UTC calendar year |
| `team-points-year-250` | Consistent Year | 250 Team Points in one UTC calendar year |

The unlock engine evaluates the complete stored historical game timeline, so qualifying historical periods are recognized during the normal achievement rebuild. Exact threshold-crossing game timestamps are retained.

## Achievement-progress corrective

The corrective implements the confirmed audit findings:

- progress reconstruction uses the same MIAC canonical identity scope as achievement awards;
- league participation progress is based on authoritative board/match participation and does not require game rows;
- league progress uses exactly the five achievement leagues: 1WL, PCL, TCMAC, TMCL, KOTML;
- opponent aliases are canonicalized for Rivalries, opponent variety, rematches and turnaround history;
- an earned lower tier becomes a durable floor for every mapped monotonic quantitative ladder, so progress cannot contradict an already-earned achievement;
- the new day/week/month/year Team Points progress uses historical peak period totals.

## Artwork

All nine user-approved source images are retained byte-for-byte under `artwork-masters/` in the source patch archive: First Step plus the eight Team Points time-window artworks. Deployment uses 640x640 PNG derivatives plus 128x128 WebP thumbnails, matching the existing achievement asset conventions. `first-point` no longer uses a placeholder artwork.

## Installer behavior

The installer is an anchored in-place upgrade from the v2.10.6.25 source layout. It:

- precomputes every source modification before touching production;
- runs source invariants before writes;
- runs PHP syntax checks before writes when CLI PHP is available;
- backs up every modified source file and any replaced assets under `.p2k-backups/v2.10.7-<UTC timestamp>/`;
- installs only the three achievement-related PHP source changes, the nine artwork assets and thumbnails, and a release marker;
- rolls source/assets back automatically if installation fails after writes begin.

No database rows are deleted and no runtime data/configuration/secrets are replaced.
