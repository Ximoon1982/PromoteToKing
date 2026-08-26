# Promote to King v2.9.5 — release notes

v2.9.5 is an additive achievement-expansion release built on the validated v2.9.4 baseline. It preserves all 133 v2.9.4 achievement identities and adds 29 new achievements, for a catalogue of 162. It also retains the Chess.com OAuth proof of concept and every v2.9.4 Hall/heatmap correction.

## New achievements — 29

### Collection depth — 5
- Achievement Collector — 25 achievements.
- Achievement Curator — 50 achievements.
- Achievement Hunter — 75 achievements.
- Century Collector — 100 achievements.
- Grand Collector — 125 achievements.

Collector achievements do not count toward their own Collector ladder.

### Rivalries — 4
- Familiar Foe — 5 team matches against the same opponent club.
- Recurring Rival — 10.
- Rivalry Veteran — 25.
- Old Adversary — 50.

### Global opposition — 3
- Globetrotter — opponent clubs from 10 distinct stored countries/regions.
- World Challenger — 25.
- Global Ambassador — 50.

Only stored Chess.com opponent-country evidence counts. Missing historical country metadata is never guessed.

### Chess960 specialization — 3
- Chess960 Initiate — 10 P2K Chess960 team matches.
- Chess960 Specialist — 50.
- Chess960 Veteran — 100.

### Activity continuity — 3
- Steady Presence — participate in at least one P2K team match in 3 consecutive UTC calendar months.
- Evergreen — 6 consecutive months.
- Year-Round Player — 12 consecutive months.

### Large matches — 3
- Big Match Player — participate in a 100+ board match.
- Giant Battle — 200+ boards.
- Titanic Battle — 500+ boards.

### Rating upsets — 3
- Punching Up — win against an opponent whose stored paired board rating is at least 100 points higher.
- Against the Odds — +200.
- Giant Slayer — +400.

The evaluator uses paired ratings stored for the same board. Match-lineup ratings take priority; a board-game rating may safely backfill older boards when no lineup rating was captured. No team-average or current-rating approximation is used.

### One-match performance — 3
- Unbeaten Pair — at least 1.5/2 across both completed games of one team-match board.
- Perfect Pair — 2/2.
- Draw Specialist — two draws.

Incomplete boards never qualify.

### Tournament versatility — 1
- Podium Variety — earn at least one gold, one silver and one bronze tournament medal.

### Rivalry turnaround — 1
- Old Rival, New Victory — participate in a P2K win against a club after previously participating in a P2K loss against that same club.

## Breadth compatibility

The historical v2.9.0 breadth achievements remain unchanged. Their eligible universe is frozen to the original 21 achievement categories, so later catalogue expansion cannot silently make `groups-all` harder or allow new categories to redefine an old unlock.

The separate additive `breadth-groups-1/5/10/15/20` ladder may recognize newly introduced behavioral categories. Collector achievements are excluded from breadth calculations.

## Progress display

Player achievement catalogues retain the v2.9.4 rules: earned achievements have no progress bar, while meaningful non-zero progress is shown for unearned achievements. v2.9.5 adds progress metrics for Collector, rivalry, opponent-country, Chess960, consecutive-month, large-match, rating-upset and two-game performance families where authoritative data exists.

## Data and schema

- Core schema: **10** (was 9).
- Analytics schema: **6** (unchanged).
- Additive migration only; no database reset or reseed.
- Core stores opponent club country metadata and paired P2K/opponent board ratings with source/capture provenance.
- Club-lane maintenance hydrates missing/stale opponent profile metadata in a bounded low-priority task. Missing-country profiles are not hammered repeatedly; a checked profile is allowed to age before another attempt.

Historical country/rating-dependent achievements converge as authoritative matches/opponent profiles are naturally revalidated. Unknown data does not create unlocks.

## OAuth proof of concept retained

The isolated Chess.com OAuth POC is included unchanged in scope:
- `OAuthTest.php` only renders for exact `?oauth=2`;
- Log in and Log out actions;
- success/failure state;
- authenticated Chess.com username display;
- authorization-code + PKCE flow with server-side token exchange;
- tokens are not rendered to page JavaScript/HTML;
- it remains separate from simulated `?oauth=1` and does not grant P2K administrator access.

Production `config/oauth-test.php` values are preserved by the v2.9.4 → v2.9.5 updater.

## Retained v2.9.4 corrections

- 133-key v2.9.4 catalogue preserved byte-for-identity at the key level before the 29 additions.
- Hall of Fame unified player search remains full-width with four contained cards per desktop row.
- Insights · Opponents retains the single aggregate all-match heatmap pair and the v2.9.4 parallel/cache/compact-payload optimizations.

