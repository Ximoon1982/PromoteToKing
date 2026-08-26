# Promote to King v2.10.6.10 — Registration/tracking/assistant corrective

## Scope

v2.10.6.10 is a tightly scoped corrective release on top of v2.10.6.9. It preserves the Green-authoritative public-read architecture and fixes three production issues reported after cutover.

### 1. Registration matches — next 15 days

- The 15-day registration graph no longer reparses localized date text from the rendered table.
- Both the graph and **Registered matches by start date** now derive from the same canonical registered-match records and UTC `YYYY-MM-DD` date keys.
- This removes the `Sep` / `Sept` locale parsing defect that could omit September rows from the graph while leaving the table correct.
- Match counts, board totals and unknown-board indicators use the same record population.

### 2. Tracked matches stop continuous tracking at start + 24h

- Continuous tracking now expires 24 hours after the known match start for **all** tracking sources, including automatic, manual and legacy entries.
- Expiry is applied before the Tracked Matches Explorer is returned and before scheduled tracking work is selected.
- Expired entries are marked unfollowed, retain all existing snapshots/history, clear their next-capture time and record `autoStopReason=started-over-24h` for compatibility/auditability.
- The monitoring scheduler independently treats start+24h entries as not due, providing a second protection against accidental CRON work.
- An expired historical match can still be recorded once; doing so does not silently reactivate continuous tracking.
- The standalone/local server follows the same rule as the hosted PHP API.

### 3. Dashboard Match Assistant loader lifecycle

- Match Assistant preparation/loading visibility is now controlled by one state synchronizer.
- When the requested assistant iframe is full-ready and visible, the preparation layer and recommendation loading skeleton are forced off.
- Closing/resetting the assistant clears the loading state.
- Delayed readiness events remain tied to the current iframe and cannot leave a stale loader visible behind a correctly opened filtered assistant.
- Existing **Matches starting within 7 days**, **Priority calls**, recommendations and normal Match Assistant entry paths are preserved.

## Deployment impact

- No database schema migration.
- No database reset or reseed.
- No CRON definition change.
- No Green routing/cutover change.
- No Team Points scoring change.
- No image/artwork change.
- Blue remains available as rollback according to the existing v2.10.6.9 routing state.
