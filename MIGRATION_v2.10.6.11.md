# Migration notes — v2.10.6.11

No schema migration or data migration is required.

The release preserves the currently selected public-read target and worker/browser routing. When Green is selected, public Dashboard Team Points data is taken from live Green Core rather than from Blue-era compatibility snapshots or Chess.com club-match lists.

The existing Green analytics tables remain in place. They are refreshed more frequently and are retained for derived Insights/reporting, while authoritative headline totals use live Green Core.
