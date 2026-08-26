# Promote to King v2.9.4 release notes

v2.9.4 is a focused performance/correctness release over v2.9.3. It retains the isolated Chess.com OAuth POC1 page and does not change the database schema.

## Achievement catalogue preservation and additive breadth

- Audited the exact packaged catalogue from v2.8.9 forward and corrected the earlier v2.9.2/v2.9.3 interpretation that replaced historical breadth entries.
- v2.8.9 and v2.8.11 contain 115 achievements.
- v2.9.0/v2.9.1 contain 128: the 115 baseline plus 4 same-day-start, 5 concurrency and 4 shipped legacy breadth entries (`groups-5`, `groups-10`, `groups-15`, `groups-all`).
- **All 128 v2.9.1 catalogue keys are preserved in their original order.** No historical key is renamed, removed or repurposed.
- The requested 1 / 5 / 10 / 15 / 20 distinct-group breadth ladder is added under five new identities: `breadth-groups-1`, `breadth-groups-5`, `breadth-groups-10`, `breadth-groups-15`, `breadth-groups-20`.
- The current catalogue is therefore **128 preserved + 5 additive = 133 achievements**. A frozen 128-key regression fixture prevents future accidental deletion or renaming.
- The shipped v2.9.0/v2.9.1 source contains four entries explicitly categorized as `achievement-breadth`; rather than invent a missing historical identity, v2.9.4 preserves the entire 128-key catalogue and adds the five new requested achievements separately.

## Hall of Fame player search

- Fixed the desktop unified player-search result width. The outer `hallUnifiedResults` host no longer inherits the generic three-column result-grid rule that forced its single unified result into one narrow track.
- The unified result now spans the full Hall of Fame content width.
- Desktop displays the four result cards (Daily ranks, Live ranks, Tournaments, Achievements) on one row; narrower layouts step down responsively.
- Long player names, detail text and actions are constrained/wrapped inside their cards so result content cannot spill outside the container.

## Insights · Opponents heatmap performance

The all-match heatmaps had avoidable cold-load work. v2.9.4:
- starts the heatmap request in parallel with the Opponents summary instead of serially after it;
- paints a valid session snapshot immediately when revisiting the panel, then refreshes it;
- uses a stable versioned 15-minute server cache for this historical aggregate rather than invalidating it for unrelated Core-generation activity;
- removes irrelevant table page/search/filter/sort parameters from the balance request/cache key;
- removes the unused chronological full-history sort;
- sends a dictionary-encoded compact tuple stream instead of repeated object keys and unused match id/status/time fields;
- normalizes the compact stream once in the browser;
- stores only count/sum aggregates in heatmap bins rather than retaining row arrays in every occupied cell;
- caps the decorative Included Opponents pill strip at the first 100 names plus a remainder count; all opponents still participate in the heatmaps and KPIs.

A representative synthetic 20,000-match serialization measured ~6.18 MiB → ~0.52 MiB raw JSON and ~491 KiB → ~132 KiB gzip. This benchmark measures the transport-format change, not production network latency.

## Compatibility

- Same two aggregate heatmaps and same filters/interactions.
- Legacy object-form balance payloads remain accepted by the renderer.
- Core schema 9 / Analytics schema 6 unchanged.
- Existing v2.9.3 OAuth POC1 remains isolated behind `?oauth=2`.
