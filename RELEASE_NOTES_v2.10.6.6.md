# Promote to King v2.10.6.6 — MCA timestamp-only source maintenance

## Purpose

v2.10.6.6 deliberately removes automatic MCA arena discovery and automatic Results CSV acquisition from Promote to King.

Chess.com MCA Results CSV files are again an explicitly administered source pack: new files are added through the existing manual upload control. Automation is limited to recovering the event date for CSV files that are already stored.

## MCA source behavior

For each stored CSV whose `actual_event_date` is missing, P2K derives the arena identity from the filename:

- `slug-ID.csv`
- event page: `https://www.chess.com/tournament/live/arena/slug-ID`

The timestamp worker fetches only that public arena page, extracts the event start date, and records it as the trusted actual event date.

Files that already have a valid actual date are not requested again.

Impossible future dates are cleared by the existing sanity guard and become eligible for timestamp backfill again.

## Removed from the runtime MCA maintenance path

- Promote to King MCA past-events index requests
- index parsing / new-arena discovery
- index pagination traversal
- `/download-results` acquisition
- automatic Results CSV insertion or replacement
- arena Player Results table reconstruction
- Player Results pagination handling
- possible-rename-triggered CSV refreshes
- automatic dataset rebuild after the timestamp worker

Manual CSV upload, manual dataset processing, manual date editing and MCA Blue → Green synchronization remain unchanged.

## CRON

The existing twice-daily MCA worker is retained, but it is now timestamp-only. A normal cycle with no stored CSV missing a date makes zero Chess.com requests.

Requests for missing dates remain serial and paced at at least one second apart.

## Migration / data safety

- No database schema change.
- No database reset.
- No reseed.
- No stored MCA CSV is deleted or replaced by this change.
- No artwork/image change.
- Existing source data is retained.
