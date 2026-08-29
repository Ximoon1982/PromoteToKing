# Promote to King v2.10.9.1

## MCA arena date-recovery corrective

v2.10.9.1 is a narrow corrective release on top of canonical v2.10.9. It fixes historical MCA arenas whose dates were visible on Chess.com but could still remain unresolved by the acquisition worker.

### Fixes

- Arena pages now use a shared robust date extractor that accepts the visible Chess.com date as well as embedded `startTime`, `start_time`, `startDate`, `start_date`, Unix timestamps, `data-start-*` attributes and `<time datetime>` evidence.
- Historical index date recovery no longer assumes that encountering a lower arena ID proves that the requested arena cannot appear on a later index page. The worker follows pagination until the target is found, pagination ends, or the 250-page safety limit is reached.
- Duplicate index links preserve already-found date evidence instead of allowing a later weaker duplicate to overwrite it.
- Existing legacy `date_index` failures with the old date-unavailable messages are automatically requeued once by the next MCA worker run, starting again from index page 1. New unresolved failures use new error wording and therefore do not loop forever.

### Preserved behavior

- No database schema change. Analytics schema remains 10.
- No runtime data reset.
- Results, club, player and Pairings/game acquisition are unchanged.
- Chess.com requests remain serial and spaced by at least one second.
- The existing every-minute v2.10.9 MCA scheduler remains valid and unchanged.
