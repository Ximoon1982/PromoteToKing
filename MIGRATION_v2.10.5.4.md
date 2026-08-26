# Promote to King v2.10.5.4 — Green migration note

v2.10.5.4 does not change the Blue → Green migration phase model or cutover gates introduced in v2.10.5.2 and hardened in v2.10.5.3.

The Scheduled Task Control Green card gains improved observability only:

- real last-completed-cycle duration;
- rolling average duration for the latest 10 completed cycles;
- estimated `quick_matches` progress based on live workload/request counters;
- clearer adapter/parity wording and responsive control layout.

Continue operating in `shadow_writing` / Both until Green validation and GAB/read parity are ready. GFFL should remain enabled. The Accelerator remains optional/opportunistic and is not required for correctness.

No schema migration, Green Core reseed, database reset, CRON change or Blue retirement is performed by this release.
