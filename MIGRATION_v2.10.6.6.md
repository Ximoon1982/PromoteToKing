# Migration notes — v2.10.6.6

There is no database migration in v2.10.6.6.

## Runtime transition

If an MCA synchronization cycle from v2.10.6.5 or an earlier experimental source-discovery release is still marked `running` at deployment, the first v2.10.6.6 worker step discards that retired discovery/download queue and rebuilds it exclusively from stored MCA CSV files whose actual event date is missing.

Queued arenas that do not correspond to a stored CSV are therefore not fetched.

Legacy `needs_csv`, CSV-stage, index-stage and pagination work is not continued.

## Data preservation

Existing MCA source files and computed data remain in place. Timestamp maintenance only updates arena identity/event-page metadata and missing actual event dates.
