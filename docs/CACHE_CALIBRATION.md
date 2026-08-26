# Cache calibration proposal

## Current release decision

Scheduled IndexedDB pruning is disabled in version 2.0.0. This prevents age-, count-, or size-based deletion from removing useful historical match JSON before real storage behavior is measured.

Only a browser-reported quota failure can trigger emergency cleanup. The application still functions through its memory cache if that cleanup cannot free enough persistent storage.

## Why the previous global limits were unsuitable

A universal 12-hour maximum age, 750-entry limit, and 24 MiB ceiling treats a small volatile club index and a larger historical match record as though they had the same value and mutation rate. Match analyses repeatedly reuse match detail JSON, and completed matches are substantially more stable than registration or active matches.

## Proposed classification

| Data class | Freshness | Cached serving window | Proposed deletion age after calibration |
|---|---:|---:|---:|
| Club match index | 2 minutes, network preferred | stale-if-error up to 1 day | 6 hours after supersession |
| Active/registration match JSON | 5 minutes | normally 1 day; stale-if-error up to 7 days | 7 days after last access |
| Finished match JSON | 1 day | up to 365 days | 365 days or no age deletion while below quota |
| Unknown-state match JSON | 5 minutes | up to 30 days | 30 days |
| Player/profile/stats/clubs | 30 minutes | up to 48 hours; stale-if-error up to 7 days | 48 hours |
| Other PubAPI data | 10 minutes | up to 7 days | 7 days |

Serving windows and deletion ages are separate. A record can remain stored for future conditional revalidation even after the client no longer serves it directly.

## Measurement period

Collect at least 30 days of diagnostics across representative desktop and mobile browsers. Record only aggregate technical values:

- entry counts by endpoint kind and match state;
- p50, p90, p95, and maximum entry size;
- total estimated storage usage and browser-reported quota;
- hit, stale-hit, refresh, and stale-if-error rates;
- quota failures and emergency cleanup frequency;
- proportion of retained match records actually reused after 7, 30, 90, and 180 days;
- average and peak concurrent open tabs.

No username, match title, or response body needs to be exported for calibration.

## Proposed thresholds after measurement

Use the lower of:

- 512 MiB; or
- 60% of the browser-reported storage quota.

Use 10,000 entries only as a secondary safety cap, not as the primary target. Protect at least the 5,000 most recently used match records. Evict in this order:

1. expired non-match records;
2. old club indexes and player data;
3. unknown-state match records;
4. old active match records;
5. finished match records, oldest last-access first, only when necessary.

The final limits should be chosen from observed p95 usage with at least 50% headroom. Scheduled pruning should remain disabled until those measurements are reviewed.

## Manual verification

The Administration Diagnostics tab exposes pruning state, approximate cache size, counts, and cache/request counters. Use **Clear cache** only for troubleshooting; it is not part of routine maintenance.
