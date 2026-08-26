# Promote to King v2.9.2 — migration notes

v2.9.2 requires no destructive migration.

The upgrade is additive:

1. Core schema revision 9 adds `rated_board_count INT UNSIGNED NULL` to `p2k_tp_match_metadata`.
2. Analytics schema revision 6 adds the same field to `p2k_an_match_facts`.
3. New authoritative match-detail observations compute both team average ratings from the intersection of valid rated board positions and persist the number of paired boards.
4. Analytics copies that provenance count with each match fact.
5. Opponent Balance heatmaps omit historical rating rows that do not yet have paired-board provenance rather than mixing incompatible averages.

Normal authoritative match revalidation progressively repopulates historical rating coverage. Existing match history, Club Points, Member Points, achievements, queues and configuration are preserved.
