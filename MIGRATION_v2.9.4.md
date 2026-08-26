# Promote to King v2.9.4 migration

v2.9.4 is an in-place upgrade from v2.9.3.

- Core schema remains 9.
- Analytics schema remains 6.
- No database reset, re-seed, DDL migration, or historical reprocessing is required.
- The heatmap optimization is query/transport/cache/browser only.
- Achievement changes are catalogue/projection logic only: all 128 v2.9.1 keys remain valid and five new additive breadth keys are introduced.
- The Hall of Fame player-search correction is CSS/layout only.
- The isolated OAuth POC1 is retained.
