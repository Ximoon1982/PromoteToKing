# Promote to King v2.10.5.3

## Green finite-cycle fairness corrective

v2.10.5.3 corrects a scheduler regression observed after the v2.10.5.2 GAB/GFFL cutover integration: a finite Green Quick cycle could remain in `quick_boards` for many invocations while continuous sidecar work continued to consume requests.

### Root causes corrected

1. **Server sidecar starvation** — GAB/GFFL/current-match maintenance could run before the finite Seed/Quick/Deep stage and consume the useful part of the worker invocation.
2. **Non-terminal transient GQAC debt** — repeatedly transient/backed-off board obligations could remain pending indefinitely and prevent a fixed Quick-board cohort from reaching terminal state.
3. **Accelerator hidden over-claiming** — after GAB/GFFL filled part of an accelerator batch, ordinary planners could still claim against the original full batch limit. Some 120-second leases could therefore be created for rows that were never actually returned to the browser.
4. **Misleading cycle observability** — GAB and GFFL continuous services appeared as `running 0/0` finite phases and invocation history did not show how requests were split among core/GFFL/current maintenance work.

### Scheduling behavior

- The active finite Green cycle receives the first approximately **70% of the 50-second soft worker target**.
- GAB server-side DB work is bounded after the finite slice. External GAB transport remains accelerator-fed.
- GFFL and current-match maintenance are bounded sidecars.
- A final finite-cycle tail pass uses remaining worker time when available.
- The global **50 s soft / 55 s hard** worker contract is unchanged.
- **GFFL remains enabled** and keeps its normal freshness role.
- In the browser Accelerator, **P0 interactive remains first; GAB remains the highest-priority background lane while bootstrap is incomplete**. Finite-cycle capacity is reserved so continuous sidecars cannot consume the entire batch.

### GQAC transient self-healing

A Quick-board obligation that has already received at least three transient, non-terminal attempts in the current cycle is completed **for that cycle only** and marked `requeued_for_next=1`. The underlying board remains `needs_refresh`, so the data is not declared fresh and is retried in a later cycle.

The same bounded rule is applied while reading/ensuring an already-running cohort, allowing a cycle such as the observed Cycle #16 to self-heal without reset or reseed.

### Accelerator claim correctness

Finite rows are now claimed only against the actual number of remaining output slots. The Accelerator no longer creates invisible leases for tasks it cannot return in the current plan.

### Observability

- Quick Boards displays real GQAC completed/total progress.
- GAB and GFFL are removed from the generic finite-cycle phase bars; each keeps its dedicated telemetry section.
- GQAC telemetry exposes eligible, claimed, retry-backoff and deferred-transient counts.
- Recent Green worker runs expose request split between finite core work, GFFL and current-match maintenance.

### GABFIX1 integrated

The MariaDB portability correction for GAB primary-key discovery is included. `SHOW KEYS` results are ordered in PHP rather than using unsupported `ORDER BY Seq_in_index` syntax.

### Safety / migration scope

- No Green Core reseed.
- No destructive database reset.
- No Green schema migration.
- No CRON cadence change.
- No change to the 50 s soft / 55 s hard worker budget.
- No image changes.
- Public reads remain **BLUE by default** until the existing validation + GAB compatibility gates are passed and the administrator explicitly advances the migration phase.
- Blue data is not retired or deleted.
