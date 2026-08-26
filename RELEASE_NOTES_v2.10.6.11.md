# Promote to King v2.10.6.11 — Green live freshness corrective

## Purpose

v2.10.6.11 corrects the remaining mixed-source and stale-cache behavior after Green cutover. The transition panel was showing the correct Green state; the Dashboard and some embedded tools could still display older or non-Green data.

## 1. Dashboard uses live Green database data

- Club Points, members, registered matches, ongoing matches and finished matches are read from Green Core when Green is the public source.
- Registered/ongoing Dashboard match lists no longer overwrite Green counts with the Chess.com club-matches endpoint.
- Finished-match modal rows use the live Green dashboard-matches endpoint instead of the compatibility Insights cache.
- Current player registered/ongoing match activity uses Green Core when the Green-native player payload is available.
- Dashboard Team Points public requests are `no-store`; the six-hour progressive snapshot is only a failure fallback and is no longer shown before a successful fresh DB read.
- The Dashboard visibly reports `GREEN · live database` when the native Green path is active.

## 2. Highest DB freshness for public Team Points views

- Browser-memory caching is bypassed for `server/team-points/public/*` database endpoints.
- Team Points client public requests use `cache: no-store`.
- Green compatibility/native analytics maintenance is eligible every 30 seconds instead of every 300 seconds.
- The migration/transition Green headline totals and Green Top players are computed from live Green Core; the periodic analytics snapshot is retained separately for diagnostics.
- No Blue data is used as an implicit public fallback while Green routing is selected.

## 3. Registration chart deployment correction

- The canonical-record graph logic from v2.10.6.10 is retained.
- `MatchCreationAnalyzer.htm` now loads its analyzer/chart assets with the v2.10.6.11 cache generation.
- The Administration iframe route now carries `release=2.10.6.11`.
- This closes the v2.10.6.10 packaging gap where the corrected JS could coexist with a browser-cached previous analyzer bundle.

## 4. Dashboard Match Assistant readiness correction

- `p2k-dashboard-assistant-ready` now means only that the embedded assistant shell is ready.
- It no longer sets `assistantFullReady=true`.
- Only `p2k-dashboard-full-assistant-ready` can transition the full assistant to visible/ready state.
- When the shell becomes ready while the Dashboard panel is open, the Dashboard explicitly requests full-assistant mode and keeps the loader until the genuine full-ready event arrives.
- `FindMatch.htm` and its first-party assets use the v2.10.6.11 cache generation, and Dashboard embedded URLs carry the current release parameter.

## Deployment impact

- No database schema migration.
- No database reset or reseed.
- No CRON definition change.
- Analytics refresh cadence changes internally from a 300-second minimum to 30 seconds.
- No scoring-rule change.
- No artwork/image change.
- Existing Green/Blue routing state is preserved.
