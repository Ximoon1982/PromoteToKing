# Promote to King v2.8.1 release notes

v2.8.1 is an in-place feature/UI release based on the corrected v2.8.0 Recovery Fix 6 + AuthBaseline. It preserves the Core/Analytics/filesystem architecture and upgrades both databases automatically to schema version 2.

## Tracked match analysis

- Lineup evolution and win-probability history now end with the current live lineup rather than the last stored snapshot.
- History uses a real UTC time axis.
- Drag-to-zoom, reset/double-click zoom and hover details were added.
- Timeslot details show the modeled win-probability change versus the previous slot.
- Left/Right keyboard arrows navigate previous/next timeslots.
- Desktop lineup-change typography and modal width were increased.

## Administration integration

- Administration / Live arena now exposes the actual Team Points Live-ranks computation panel.
- Storage & capacity moved to its own Administration subtab.
- Open Match Analyzer is integrated as natural-height page content.
- Integrated child pages report their height to the dashboard instead of remaining in fixed-height scrolling frames.
- Dashboard System health now includes overall health and storage-capacity status.
- Administrator-card operational links use integrated Administration routes.

## Open Match Analyzer

- Added **Open standalone ↗** link.
- The standalone URL carries the currently analyzed match and selected club/point of view.
- Removed the default-shown Promote to King logo; a selected-team logo is shown only when relevant.

## Administrator source of truth

- OAuth role claims remain authoritative when supplied by real OAuth.
- Otherwise the public Promote to King Chess.com club endpoint is the primary administrator list.
- `config/site-branding.js` `adminUsernames` is now outage fallback only.
- No MariaDB access is required to grant administrator page access.

## Tournaments

- Restored medal-color accentuation of medalist ranking rows.
- Added gold/silver/bronze colored markers to medal columns.

## Insights

### Team

- Removed duplicated metric presentation; comparison deltas are folded into the primary KPIs.

### Members

- Increased chart dimensions for desktop readability.

### Matches

- Match chart cards are full width.
- Pie charts are significantly larger.
- Fixed lower-edge chart spill/clipping behavior.
- Removed the Closest-match highlight.
- Added Longest ongoing match.
- Loading modal is replaced in place when match details resolve or fail.

### Opponents

- Removed the treemap.
- Most-played visualization now shows the top 25 clubs plus an aggregated Others row.
- Opponent result history is a stacked monthly W/D/L chart with hover and drag zoom.
- Added win-rate visualizations by time control and by 200-point opponent-rating bracket.
- Loading modal is replaced in place when opponent intelligence resolves or fails.

## Unified player profile

- Unranked Daily/Live categories no longer render a broken rank image.
- Team Points progression combines monthly bars and cumulative line with hover and drag zoom.

## Achievements

- Added persistent Analytics achievement unlock records.
- Stored fields include earned timestamp, timestamp precision, first recorded time and last verification time.
- Achievement catalogue/profile data now includes number of members who have earned each achievement.
- Added persistent MCA first-place counts to support 1/5/10 MCA-victory achievements.
- Tournament medal achievements are persisted from the tournament archive.
- All-achievements groups are collapsed by default and show achieved/total counts in their headings.

## Database migration

Core schema 2 adds match average-rating fields. Analytics schema 2 adds matching materialized fields, MCA first-place counts and `p2k_an_achievement_unlocks`.

The migration is automatic and in place. No initializer or seed import is required.
