# Dashboard regression audit — v2.8.6 to v2.8.9

## Confirmed boundary

Production confirmation: v2.8.6 works; v2.8.7 does not.

## Confirmed primary cause

The v2.8.7 Hall/Insights module split moved an integrated-frame ID list out of the startup controller while `setIntegratedFrameActivity()` in the main controller continued to reference it. The default Dashboard route calls that function during the first `renderView()`.

Observed browser-gate failure on the shipped pre-fix tree:

`ReferenceError: integratedFrameIds is not defined`

Because `initialize()` calls `renderView()` before `loadTeamData()` and `applySession()`, the exception stops both data startup and authentication/admin application. This is why the two user-visible symptoms occurred together.

## Confirmed secondary dependency defect

The emergency v2.8.8.3 rollback restored the v2.8.6 `loadTeamData()` function but did not restore the `clubMembersAPI` constant removed by the later refactor. Once the primary startup exception is fixed, the post-paint second wave could therefore fail when it first evaluates that identifier.

## Why previous static tests missed it

Syntax checking proves only that JavaScript parses. Unit/static assertions also validated function presence and release contracts but did not execute the real startup ordering. An unresolved lexical/global identifier is valid syntax and fails only at runtime when the branch executes.

## Safer implementation adopted

1. Startup-owned frame enumeration is derived from the DOM in the main controller; no lazy module owns a startup dependency.
2. The v2.8.6 Dashboard function is paired with its full constant/dependency set.
3. Admin recognition is independent of remote API completion.
4. Lazy-feature failures are local and retryable.
5. A browser execution gate runs the shipped HTML/controller with mocked network data and requires:
   - zero uncaught page errors;
   - Team database values rendered;
   - configured simulated admin recognized;
   - Administration navigation available.
6. ACAMR is tested independently from Dashboard boot and does not participate in the startup dependency graph.

## ACAMR assessment

No evidence links ACAMR itself to the confirmed startup exception. The failure occurs synchronously in `renderView()` before `loadTeamData()` and `applySession()` complete. ACAMR is therefore re-enabled in v2.8.9 with its original trust/scope boundaries and remains independently disable-able if future telemetry shows a performance problem.
