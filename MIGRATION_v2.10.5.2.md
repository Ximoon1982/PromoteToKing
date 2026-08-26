# v2.10.5.2 Green production cutover

## Intended state sequence

`BLUE PRIMARY → SHADOW WRITING → GREEN VALIDATED → GREEN READS + BOTH WRITING → GREEN PRIMARY`

Blue retirement is deliberately later than Green display cutover. Keep Blue available through a proving/rollback window before archive/removal.

## GAB readiness

GAB is complete only when all bootstrap lanes are complete and Green compatibility smoke/read-contract checks pass. It migrates historical/imported data that canonical Green Core cannot recreate directly, then rebuilds current facts/read models from Green.

## GFFL readiness

GFFL keeps current matches within a configurable freshness SLO. The default is 20 minutes server-only; the Green Accelerator can improve this. Duplicate match freshness obligations are factorized into one authoritative match fetch.

## Green primary

At Green primary:

- public Team Points reads select Green;
- Green workers are authoritative;
- browser ingest selects Green Accelerator;
- legacy Blue ACAMR planning/observation writes are disabled;
- auxiliary Live Ranks/MCA, MIAC and opponent maintenance target the selected Green compatibility store;
- Blue worker task flags are paused best-effort and Blue remains available as rollback/frozen data.

Do not delete Blue databases during the initial Green-primary proving period.
