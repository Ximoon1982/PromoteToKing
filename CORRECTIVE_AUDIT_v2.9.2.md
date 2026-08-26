# Promote to King v2.9.2 — corrective audit against actual v2.9.1

Audit baseline: the extracted `PromoteToKing_v2.9.1_FULL_STANDALONE.zip`, not v2.9.1 release-note claims.

| v2.9.1 gap / requirement | v2.9.2 result |
|---|---|
| Hall of Fame before Insights | **Corrected** — primary public order is Hall then Insights. |
| Achievement breadth 1 / 5 / 10 / 15 / 20 | **Corrected** — exact five tiers; former all-groups tier removed. |
| Approved recovered artwork and 640 / 64 / 128 normalization | **Corrected with honest provenance** — 7 high-confidence recovered originals mapped and normalized; resolved raster assets use normalized derivatives; unresolved items use explicit per-achievement placeholders. The false v2.9.1 “50 recovered originals” claim is not repeated. |
| Opponent Balance Analyzer rating integrity | **Corrected** — both team averages use the same intersection of valid rated board positions; `rated_board_count` records provenance/coverage. |
| Opponent heatmap layout requested after audit | **Corrected** — one aggregate two-heatmap set covers all included matches; filters rerender those same charts and never create per-opponent heatmap sets. |
| Daily Boards / Club Points clickable-legend description | **Corrected**. |
| Maximized chart exact scroll restore | **Corrected** — pre-open X/Y captured and restored. |
| Monthly Active Members whole current month incomplete/future | **Corrected** — current-month boundary begins at day 1. |
| Admin URL state through refresh/back/forward | **Corrected** — group/subtab/tool/context restored. |
| Dashboard Admin shortcuts exact integrated context | **Corrected**. |
| Database Match Profile human Chess.com match URL | **Corrected**. |
| Admin health traffic lights | **Corrected** — consistent dot vocabulary. |
| Below-minimum Dashboard warnings only inside next 7 days | **Corrected**. |
| Match Creation bar selectively fetch missing contributing match details | **Corrected** — targeted/cache-aware; no archive-wide scan. |
| Lineup/Win-probability identical run retains first + last | **Corrected**. |
| Durable Work Report queue-state separation | **Corrected** — total, committed, remaining backlog, pending, claimed/running, retry-waiting, failed. |

## Retained behavior verified during corrective work

The v2.9.1 features that were already genuinely implemented remain intact: earned achievement cards omit progress bars; Achievement Challenge focuses the selected player/achievement; achievement search remains available; corrected rank artwork mappings remain; Hall desktop search stays full-width/four-column; Team Depth excludes unrated players from the rating axis while retaining coverage cards; Members activity filtering remains server-side before pagination; opponent logo/avatar ordering remains; Traffic DNT/GPC/self-test diagnostics remain; Data Reconciliation remains protected and authoritative-queue based; and the ACAMR telemetry correction remains integrated.

## Release acceptance gates

- Corrective source acceptance tests: pass.
- Full Python suite: 250/250 pass.
- Package validator: pass (resource/DOM/source assertions, Node checks, JS syntax, PHP lint, Python compile probe).
- v2.9.2 aggregate heatmap Playwright gate: pass, zero page errors.
- Dashboard/Admin/ACAMR startup browser gates: pass, zero page errors.
- Mobile Team Insights/pinch/opponent rendering browser gate: pass, zero page errors.
