# Migration notes — v2.10.6.8

There is no database migration in v2.10.6.8. Existing Blue data, Green Core/Analytics data, GAB state, GFFL debt, GQAC state, cycle history and compatibility projections are preserved exactly.

The change is a routing-policy correction for the mature continuously cycling Green runtime. Bootstrap conditions such as `unknown_matches = 0` or `pending_boards = 0` are no longer meaningful production cutover requirements because Quick cycles continually repopulate those work queues.

## Public-read routing

When **Switch reads to Green** is selected:

- `public_read_target = green`
- `migration_phase = green_reads_both_writing`
- `worker_target = both`
- `client_ingest_target = both`
- Blue Team Points pause flags are cleared when possible.

GABCRF and compatibility convergence continue normally after the switch.

The public read router now requires only Green technical availability and compatible schemas. It no longer checks `gab_status=ready` before opening the Green read databases. Once Green is explicitly selected, a real Green connection/schema failure remains fail-closed and is surfaced rather than silently falling back to Blue.

## Green primary

**Make Green primary** changes the routing to Green reads + Green maintenance and requests pause of the Blue Team Points workers. It is deliberately separate from the first Green-read switch so production can first run with Blue maintained as a rollback source.

## Rollback

**Rollback reads to Blue** restores:

- `public_read_target = blue`
- `migration_phase = shadow_writing`
- `worker_target = both`
- `client_ingest_target = both`

No data is deleted on either side.
