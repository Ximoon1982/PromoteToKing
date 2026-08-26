# Promote to King v2.10.6.23

## Member chronology, tournament visibility and member lookup

This release improves the Administration data surfaces without changing Team Points scoring, Green routing, CRON definitions or database schema.

Member chronology now consolidates a stable-player-ID rename into one authoritative `Name changed` event. Transient new-name discovery/join observations and the correlated old-name departure are suppressed from chronology output, while genuine historical leaves and rejoins remain visible. New rename confirmations also clean the correlated transient lifecycle rows.

Tournament Management now includes a complete `Known tournaments` table sourced from the existing tournament archive and displaying latest known status, period, type, player count and update time.

The Dashboard `New matches · 24 h` metric now starts at the same unknown value used elsewhere (`—`). An authoritative successful response containing no matches displays `0`; failed or not-yet-loaded state remains `—`.

Administration → Members gains a `Member lookup` card. A current or former username resolves through the Green identity graph and presents available membership lifecycle, aliases, account/closure evidence, ratings, Daily activity and Team Points, current match workload, achievement count, MCA summary, roster/profile/stat freshness and lifecycle chronology. The lookup endpoint remains admin-authorized.

No DB schema/reset/reseed, CRON-definition, Blue/Green routing, scoring, heatmap formula/data, artwork or public-navigation changes are included.
