# Queue deduplication audit — v2.9.13

## Finding

Before v2.9.13, queue uniqueness was based on `(job_id,item_type,item_key)`. Many producers intentionally embedded source, batch, repair or freshness identifiers in `item_key`, so logically identical work could accumulate as independent active tickets. This explains how a dataset with roughly 15,000 matches can coexist with an order-of-magnitude larger queue history/outstanding set.

## Corrective model

Outstanding work now has a canonical scope and canonical key. Only active states (`pending`, `running`, `retry`) participate in canonical uniqueness. Terminal rows remain audit history.

A new request either creates one canonical active item, merges/promotes an existing pending/retry item, or records one requested continuation generation on a running item. Priority/freshness requirements are merged conservatively; provenance is retained in coalesced sources.

## Scheduler safety

Freshness deadlines receive the strongest queue priority. Canonical membership merging cannot weaken the hourly authoritative member-list guarantee: deadline-bearing membership work forces a network fetch. Club match-index deadline work remains authoritative. Deduplication therefore reduces redundant work without allowing ordinary backlog to mask the two hourly freshness floors.

## Benchmark

Synthetic mixed backlog:

- Input requests: 150,002.
- Canonical outstanding work: 25,001.
- Coalesced duplicates: 125,001.
- Reduction: 83.33%.
- First scheduler items after compaction: club match index and club members deadlines.
- 100,000 duplicate requests while one item was running: one requested continuation generation.

The benchmark is deterministic and implemented in `tests/simulate_queue_dedup_v2913.py` with release assertions in `tests/test_v2913_queue_dedup.py`.
