# Promote to King v2.10.6.25

Functional release based on the immutable canonical v2.10.6.24 tree.

## MCA Results synchronization

- Restores automated MCA discovery using the legacy Chess.com live-tournaments index for Promote to King.
- Discovery is incremental and resumable: page 1 first, then older pages only until the latest stored arena-ID boundary is reached.
- Arena URLs and Results CSV URLs are derived deterministically from the arena slug/ID.
- Only genuinely missing Results CSV files are downloaded; stored sources are never blindly replaced.
- All Chess.com source requests are serialized and spaced by at least one second.
- Failed Results downloads remain visible and are not repeatedly retried within the same worker slice.
- Successful downloads are processed through the existing MCA/MIRA pipeline even when another download in the batch failed.
- The twice-daily CRON performs discovery + missing Results acquisition only. Historical missing-date repair remains a separate manual Admin workflow.
- Manual CSV upload remains supported and existing data is preserved.

## Member chronology

- Adds server-backed filters for event type, UTC date range and current/former member username.
- Consolidates `discovered` + initial `joined` into one displayed **Joined** lifecycle event without rewriting stored history.
- Removes Source and Cycle columns from the chronology display.
- Extends the existing asynchronous departure-profile checker to retain the exact Chess.com account status and closure reason when supplied (for example `closed:fair_play_violations`).
- Member lookup resolves stable Chess.com player-ID aliases so former usernames remain usable as chronology filters.

## Challenge Assistant

- Adds **Save current list** to Club URL checker, Club activity checker and Challenge recommendation tabs.
- Each button saves exactly the list loaded in that tab to the existing secured shared server-list endpoint.
- Existing revision-conflict protection and administrator write authorization are retained.

## Release invariants

- No database reset or reseed.
- No scoring-rule change.
- No Blue/Green routing change.
- Normal production deployment uses a self-contained incremental updater from v2.10.6.24; the complete source archive remains a separate canonical/recovery artifact.
- Existing runtime data, local configuration and unrelated CRON entries are preserved by the release installer.
