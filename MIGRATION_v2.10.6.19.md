# v2.10.6.19 Green migration note

This is an in-place corrective over v2.10.6.18. Existing Green Core, Green Analytics, GAB, GFFL, GQAC, cycle state, public Green routing, and historical game data remain in place.

The obsolete v2.10.6.18 historical match-detail heatmap work ledger is not authoritative data and is retired when the corrected backfill is prepared. Historical Green games/boards are preserved.

GABCRF can resume in place. Its next convergence pass will also revisit matches that have stored game rating evidence but no compatibility heatmap rating metadata.

No reset, reseed, schema migration, or Deep scan is required.
