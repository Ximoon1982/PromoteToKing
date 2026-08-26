# Promote to King v2.10.6.5

## MCA discovery boundary & deterministic source URL corrective

v2.10.6.5 simplifies the MCA source synchronization model after the v2.10.6.3/.4 pagination experiments.

- The Promote to King Chess.com past-events index is used **only to discover genuinely new MCA arena IDs**.
- Page 1 is checked first. Older index pages are explored only when the newest arena already stored in the MCA source pack is not present/reached on page 1. Exploration stops immediately when that known boundary is seen or crossed.
- Arena IDs remain monotonic, so events newer than the latest stored arena can be identified without walking the entire historical index.
- The event page and Results CSV URLs are derived directly from the arena slug/ID:
  - event: `https://www.chess.com/tournament/live/arena/<slug>-<id>`
  - results: `https://www.chess.com/tournament/live/arena/<slug>-<id>.csv`
- Index CSV-link scraping is no longer part of acquisition.
- Existing stored arenas are **not force-refetched merely because they are recent**. A normal scan queues only new/missing arena IDs, stored files with a missing event date, and sources explicitly requested by possible-rename refresh logic.
- Date backfill continues directly from the event URL reconstructed from each stored CSV filename. It is independent of index pagination.
- Legacy `results_pages` queue state from v2.10.6.3/.4 self-heals to the deterministic CSV route.
- Existing future-date sanity repair remains in place.
- Serial source pacing remains at least one second between requests.

No database schema migration, reset, reseed, asset update, or source deletion is required.
