# Promote to King v2.10.5.1

Runtime-version/cache-consistency corrective on top of v2.10.5.

## Corrected

- `assets/js/site-config.js` now reports the installed release as **2.10.5.1** instead of the stale 2.10.4.x build line.
- The browser site-config build timestamp is refreshed to the corrective build.
- All active top-level HTML/HTM pages use one first-party cache generation: **2.10.5.1**.
- Dynamic first-party scripts injected by `site-config.js` use the same cache generation.
- Green runtime release observability (`GreenConfig`, migration API, migration accelerator panel) reports **2.10.5.1** consistently.
- Package validation now rejects mixed/stale 2.10.x cache markers in active pages.

## Unchanged

- No database schema change.
- No database reset or reseed.
- No CRON or worker-budget change.
- No GQAC/Quick algorithm change relative to v2.10.5.
- No Member chronology change relative to v2.10.5.
- No Production Migration behavior change relative to v2.10.5.
- Public Team Points reads remain Blue by default and Green-read cutover remains safety-gated.
