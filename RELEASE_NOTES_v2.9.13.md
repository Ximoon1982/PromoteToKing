# Promote to King v2.9.13 — canonical queue deduplication and compaction

v2.9.13 is a focused queue/scheduler release built from the verified v2.9.12 package. Core schema advances from 12 to 13; Analytics remains 6; the achievement catalogue remains 162; the external four-task CRON cadence is unchanged.

## Canonical outstanding work

- Queue deduplication now uses a canonical work identity rather than the caller-specific `item_key`.
- Equivalent outstanding requests from CRON, browser observations, repair tools, freshness buckets and analyzers coalesce into one active work item.
- Canonical identities cover club match index, membership/roster, matches, boards, player matches, player stats, player profiles, opponent profiles, monthly archives, member reconciliation and raw discovery chains.
- Terminal rows remain immutable audit history. Deduplication applies to outstanding `pending`, `running` and `retry` work only.

## Merge, promotion and continuation semantics

- A duplicate request against pending/retry work merges its payload, strongest priority and provenance into the surviving item.
- `sync_roster` and `sync_members` share one membership identity; a stronger full-membership request promotes a pending lightweight roster item.
- A request that arrives while the canonical item is already running records a requested next generation instead of adding another queue row.
- Any number of duplicates arriving during the same running generation produce at most one continuation generation.
- Freshness deadlines retain deadline priority after coalescing and force authoritative network acquisition when merged into membership work.

## Legacy backlog compaction

- Core 13 migration assigns canonical identities to outstanding legacy rows and compacts redundant pending/retry rows into one survivor.
- Redundant outstanding rows become `skipped/coalesced`; completed/failed historical records are not erased.
- Running legacy duplicates already in flight are allowed to finish safely; future requirements converge onto one canonical continuation.
- Six-hour housekeeping contains an idempotent safety net that resumes compaction only when legacy uncanonicalized active rows remain.

## Queue visibility

Task Control now reports active canonical work, coalesced request count and any residual legacy active rows. Progress treats `done + skipped/coalesced` as committed queue work so a compacted historical backlog no longer appears perpetually unfinished.

## Synthetic queue benchmark

A mixed 150,002-request model collapsed to 25,001 canonical outstanding work items: 125,001 duplicate requests coalesced, an 83.33% reduction. The two hourly freshness resources remained first in scheduler order. A separate 100,000-request flood against one running item advanced its generation exactly once.

## Compatibility

- Core schema 13 / Analytics schema 6.
- v2.9.12 OAuth transport, 50 req/s convergence controller, cache/feeder corrections and security properties are unchanged.
- Hourly authoritative member-list and club-match-index freshness guarantees remain enforced.
- Protected local/OAuth/shared configuration remains protected by the incremental updater.
