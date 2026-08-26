<!--
SOURCE RECOVERY NOTICE
This documentation file was reconstructed on 2026-08-18 from authoritative
v2.9.22.10 validation, audit and updater records after the original byte stream
became unavailable. It is not claimed to be byte-identical to the historical
RELEASE_NOTES_v2.9.22.10.md.
-->

# Promote to King v2.9.22.10

Focused Scheduled Task Control telemetry restoration hotfix on the frozen
v2.9.22.9 baseline.

## Scheduled Task Control

The preceding status-only/lazy-detail load-shedding work protected shared-host
headroom, but selected server-task telemetry could become stale or disappear.
v2.9.22.10 restores the operational detail contract while retaining those
headroom protections.

- Selected task detail survives the normal 60-second status refresh.
- Only the currently selected server-backed task detail is refreshed.
- A failed detail refresh retains the previous detail snapshot instead of
  blanking the panel.
- Club Points detail again exposes index freshness, durable debt and queue state.
- Member Points separates operational freshness from server-verified freshness.
- Worker idle/job-reason telemetry is preserved through CRON and ACSR paths.
- Match monitoring and Tournaments retain their existing detail behavior.

## Compatibility

- VERSION: 2.9.22.10
- Core schema: 16 unchanged
- Analytics schema: 7 unchanged
- Existing CRON contract remains 4 operational jobs plus 1 weekly backup
- No schema migration
- v2.9.22.9 FastCGI/server-headroom protections are retained
- No scoring, Fresh Points Reconstruction, OAuth, scheduler-policy or queue
  redesign is introduced by this hotfix
