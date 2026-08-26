# Promote to King v2.9.4 — Achievement catalogue lineage audit

Date: 2026-08-10

## Corrected preservation result

The earlier v2.9.2/v2.9.3 corrective interpretation treated the requested 1 / 5 / 10 / 15 / 20 group-breadth ladder as a replacement for the v2.9.0 breadth entries. That is not the preservation contract now required.

| Release | Total | Catalogue interpretation |
|---|---:|---|
| v2.8.9 | 115 | baseline |
| v2.8.11 | 115 | unchanged |
| v2.9.0 / v2.9.1 | 128 | 115 baseline + 4 same-day + 5 concurrency + 4 shipped legacy breadth entries |
| v2.9.4 | **133** | **all 128 v2.9.1 keys preserved + 5 new additive distinct-group breadth entries** |

### The 128-key v2.9.1 baseline is preserved exactly

v2.9.4 freezes the v2.9.1 catalogue key sequence in `tests/fixtures/v291_achievement_keys.txt`. The first 128 current catalogue keys must match that fixture exactly. This protects every historical achievement identity, not merely a representative subset.

The shipped v2.9.0/v2.9.1 source contains four entries explicitly categorized as `achievement-breadth`:

- `groups-5` — Achievement Explorer
- `groups-10` — Achievement Voyager
- `groups-15` — Achievement Pathfinder
- `groups-all` — Achievement Universalist

Although the user recollection is that the original breadth family contained five achievements, no fifth stable historical key is present in the audited shipped 128-key catalogue. v2.9.4 therefore does **not invent** a historical key. Instead it preserves the entire 128-key baseline so no original achievement of any family can disappear.

### Five requested breadth achievements are additive

The requested distinct eligible-group milestones are introduced with separate identities:

- `breadth-groups-1`
- `breadth-groups-5`
- `breadth-groups-10`
- `breadth-groups-15`
- `breadth-groups-20`

These are excluded from their own eligible-group counting category, exactly like the legacy breadth family, so meta-achievements do not recursively inflate breadth progress.

## Resulting invariant

**128 preserved v2.9.1 achievements + 5 new breadth achievements = 133 total.**

Regression coverage verifies:

- the exact 128-key preserved baseline and order;
- the five new `breadth-groups-*` keys;
- retention of `groups-all` and the three other shipped legacy breadth keys;
- evaluator/materializer support for both legacy and new breadth families;
- complete public/player catalogue reads without breadth-family filtering.
