# Promote to King v2.10.9.3

Corrective MCA discovery / chronology release built from the canonical v2.10.9.2 mounted source tree.

## MCA discovery chronology

- Arena IDs remain the durable identity, but are no longer treated as event chronology. Chess.com assigns the ID when the arena is created; the arena may occur later.
- The MCA index parser now preserves Chess.com's document order instead of sorting events by arena ID.
- The previous numeric arena-ID high-water mark is retired.
- The first discovery cycle after upgrade performs one exhaustive occurrence-ordered reconciliation from the newest index page to the end.
- After that baseline succeeds, routine discovery starts at the newest event and stops at the first arena confirmed by a previous successful index scan, regardless of its numeric ID.
- Merely having a stored CSV does not make an arena an index boundary. Index confirmation is persisted through the existing acquisition `source_kind` field.
- No database schema migration is required.

## Pagination safety

- The modern club live-tournaments URL remains primary.
- When Chess.com accepts `page=N` but serves the same events again, discovery rejects the non-advancing page instead of falsely completing.
- The legacy `/clubs/pastevents/<club>?type=multi&page=N` route is attempted as a read-only pagination fallback.
- A full-size page continues scanning even when Chess.com's modern UI omits a traditional `rel=next` link; a short page is treated as the terminal page.
- If neither route advances, discovery remains resumable with an explicit error and does not mark the one-time reconciliation complete.

## Acquisition date-state repair

- Before legacy date errors are requeued, acquisition rows with a canonical Results source date are repaired directly from that local canonical date.
- A stale `date_index` error with a known canonical date moves to `done/pending` and is completed normally without another exhaustive index search.
- Legacy date errors are requeued only when their acquisition date is genuinely unresolved.

## Event-date correctness

- Removed arena-ID-weighted date interpolation from `LiveRanksService::recomputeEventDates()`.
- A source uses its known event date when available; otherwise it explicitly uses upload-date fallback.
- Creation-order IDs are never again used to fabricate occurrence dates.

## Duplicate-source audit semantics

- Raw SHA-256 differences remain visible.
- `Conflicting duplicate groups` now means that the exact Promote-to-King Results rows consumed by MCA aggregation differ, rather than merely that the raw files differ.
- Browser/download copies remain retained and excluded non-destructively from calculations.

## Preservation

- No schema change.
- No runtime-data reset.
- No CRON definition change.
- Existing CSV bytes are not deleted or rewritten.
- Existing canonical deduplication remains in force.
