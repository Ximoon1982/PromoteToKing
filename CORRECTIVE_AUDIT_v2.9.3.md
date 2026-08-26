# v2.9.3 corrective audit

## Heatmap defect

**Observed:** the v2.9.2 heatmaps could not be found under Insights · Opponents.

**Cause:** the renderer and backend were implemented, but the visible host was wired to Club Intelligence · Balance rather than the requested Insights · Opponents panel.

**Correction:** Insights · Opponents now owns the only visible heatmap host. It loads `section=balance` from the public opponents endpoint and renders one aggregate pair. The Club Intelligence Balance tab/script wiring is removed.

**Population correction:** the public balance query uses every stored non-void match with boards. It does not require `status='finished'`. Heatmap points still require paired-board rating provenance; omitted rating rows are reported through coverage rather than silently treated as comparable.

## Achievement breadth defect

**Required contract:** breadth milestones are additive to all existing achievement families.

**Catalogue audit:** 129 active definitions = 124 non-breadth definitions + 5 breadth definitions (`groups-1`, `groups-5`, `groups-10`, `groups-15`, `groups-20`). Existing families remain present: matches, Team Points, games, wins, Daily ranks, Live ranks, leagues and league-specific achievements, seniority, MCA participation/placement/victories/streaks/single-arena wins, same-day starts, concurrent games and tournaments.

**Read-path defect found:** player profiles previously chose persisted unlock rows whenever at least one existed, and only used profile-derived achievements when the persisted set was completely empty. A partial/new persisted family could therefore suppress still-valid profile-derived achievements.

**Correction:** player profiles now union persisted unlocks and profile-derived achievements by achievement key. Persisted rows keep their authoritative dates/source metadata; fallback/profile-derived rows fill only missing keys.

## Regression gates

`tests/test_v293_user_corrections.py` explicitly verifies:
- heatmap host is Insights · Opponents, not Club Intelligence;
- public heatmap query covers all non-void matches and records paired-rating coverage;
- breadth definitions are additive (124 + 5 = 129);
- persisted and profile-derived player achievements are unioned;
- public and player catalogues use the complete catalogue;
- August 10 player achievement progress, ownership, recent-order and challenge-deep-link rules remain present.
