# Promote to King v2.9.3 release notes

v2.9.3 is a focused corrective release over v2.9.2. It fixes two user-visible errors found after the v2.9.2 package was built. There is no schema change: Core schema remains 9 and Analytics schema remains 6, using the v2.9.2 additive migration files when an older installation still needs them.

## Insights · Opponents heatmaps

- The aggregate Opponent Balance heatmaps now live in the public **Insights · Opponents** panel, directly after **Most played opponents**.
- The duplicate/wrongly placed **Club Intelligence · Balance Analyzer** tab is removed from the visible UI.
- There is exactly one shared heatmap pair for the selected all-match population; filters re-bin those same two heatmaps rather than creating per-opponent heatmaps.
- The source population is all stored non-void matches with boards, regardless of registration/ongoing/finished status.
- Rating comparisons only plot rows where both teams have valid ratings on the same paired board positions. Coverage reports both the complete non-void match population and the paired-rating subset.

## Achievements · breadth is additive

- The 1 / 5 / 10 / 15 / 20 achievement-group breadth milestones remain **additional meta-achievements**. They do not replace any existing achievement family.
- The catalogue invariant is now regression-tested as **124 prior achievements + 5 breadth achievements = 129 total**.
- Player profiles now union persisted unlocks with profile-derived earned achievements. A newly persisted breadth unlock can therefore never suppress older match, game, win, Team Point or rank achievements that are still directly provable from the player's profile.
- The complete catalogue remains available through both the public achievement endpoint and the player catalogue.
- Existing August 10 behaviour remains: earned achievements have no progress bar; unearned non-zero metrics may show progress; player catalogues hide global ownership counts; challenge rows deep-link to the specific achievement; player profiles sort earned achievements most-recent-first; tournament award dates remain authoritative/pending rather than synthetic.

## Operations

- No database reset or destructive migration.
- Core schema: 9.
- Analytics schema: 6.
- v2.9.3 CRON dispatcher/installer preserve the four-task v2.9.2 schedule and only advance the release marker.
