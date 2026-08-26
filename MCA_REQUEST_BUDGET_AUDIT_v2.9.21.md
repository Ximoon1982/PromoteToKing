# MCA request-budget audit — v2.9.21

## Observed v2.9.20 regression

`team-points-client.js` defaults administrator requests to 30 seconds. `team-points-features.js` invoked both MCA `process_start` and `process_step` without an action-specific timeout. MORCF made the OAuth profile path operational, exposing valid operations that can exceed that generic deadline.

## Correction

- Full MCA rebuild client deadline: 110 s.
- OAuth profile step client deadline: 75 s.
- OAuth batch launch budget: 12 s of the currently learned CPS.
- Absolute MCA profile-batch cap: 32.
- Backend re-applies the rate-derived cap before `processProfileBatch()`.

Examples: at 0.5 CPS the cap is 6 profiles; at 1 CPS it is 12; at 5 CPS or above the absolute cap of 32 applies. This keeps low-rate/backoff operation bounded while retaining useful parallelism at healthy rates.
