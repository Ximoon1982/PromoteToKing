# Promote to King v2.10.5

v2.10.5 consolidates the accepted v2.10.4.x Green runtime line and integrates the next production-migration administration layer without changing the default public data authority.

## Green Quick-cycle correctness

- GQAC self-heals orphaned or no-longer-eligible board-cycle items instead of allowing a finite quick cohort to remain permanently pending.
- Match invalidation and authoritative board-count shrink retire admitted board work before derived rows are removed.
- A terminal authoritative two-game board is not dirtied again solely by a later parent-match hint change; its hint is updated without scheduling an unnecessary board endpoint fetch.
- Existing unsigned `claim_count` installations are upgraded to the accepted signed GQAC representation. Fresh schemas use the signed representation directly.
- GQAC observability includes retired-ineligible work alongside completion, claims and requeue counts.

## Quick-fetch correction

Quick-board work that became impossible because its board or parent match was removed, excluded, cancelled, non-Daily, or outside the authoritative board count is retired locally with a synthetic terminal accounting status. No Chess.com request is issued for that stale item. Normal transient HTTP failures retain the existing retry/backoff behavior.

## Dashboard Match Assistant

The Dashboard now persists the Match Assistant open intent before the embedded assistant frame is ready. `Matches starting within 7 days` and `Priority calls` therefore open the assistant reliably even when the iframe is still hydrating, and the selected filter is applied when the frame reports ready.

## Administration → Production Migration

The former standalone Green migration controls are integrated as an Administration Tools subtab with routing state, readiness gates, Green observables, Blue/Green comparison, worker/client controls, validation and the browser accelerator.

Migration phases are explicit: Blue primary, shadow writing, Green validated, Green reads + both writing, and Green primary. **Public reads remain Blue by default.** The two Green-public phases are intentionally safety-gated in this release until the complete compatibility read adapter is enabled; v2.10.5 does not permit a partial public cutover. Blue remains the rollback source.

## Administration → Scheduled Task Control

Scheduled Task Control now exposes the Green scheduler contract and runtime telemetry:

- five staggered Green CRON entries, aggregate one invocation per minute;
- 50 s soft / 55 s hard Green worker limits;
- latest Green invocation, request count and GQAC progress;
- retired/requeued GQAC accounting;
- browser/session accelerator Start/Stop controls and a direct route to Production Migration.

The browser accelerator is not represented as a scheduled CRON task and therefore has no fabricated next-run time.

## Administration → Members chronology

A new append-only Green member chronology records:

- discovered identities and joins;
- leaves;
- trusted name changes;
- rejoins.

Trusted identity mappings are reconciled before an absent username is declared a leave. A detected leave is recorded immediately and its Chess.com profile is checked asynchronously. HTTP 404/410 resolves the account as closed; HTTP 200 resolves it as active unless the profile explicitly reports a closed/disabled state. Transient failures remain pending and never block roster convergence.

## Scheduler and deployment

A new `reset-install-green-cron-v2.10.5.sh` installs the accepted five-entry 50/55/58 Green scheduler idempotently while preserving Blue and unrelated CRON entries.

The cumulative updater accepts v2.10.4, v2.10.4.1, v2.10.4.2, v2.10.4.3, v2.10.4.4, v2.10.4.5, v2.10.4.6, and idempotent v2.10.5. It is a complete sanitized non-image source overlay, so accepted .4/.5/.6 runtime corrections are not dependent on the starting hotfix level.

## Data safety

- No forced Green reseed.
- No destructive database reset.
- Existing Green databases receive only idempotent additive/compatibility schema upgrades.
- Blue Team Points data and Blue public behavior remain intact.
- Protected local credentials and live runtime data are not packaged.
