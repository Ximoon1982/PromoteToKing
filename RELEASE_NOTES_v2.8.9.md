# Promote to King Standalone v2.8.9

v2.8.9 is a convergence release built from the latest v2.8.8.3 tree. It retains the v2.8.8 feature set and successive fixes, fixes the production-confirmed Dashboard startup regression at its actual source, and re-enables ACAMR.

## Dashboard startup regression: root cause fixed

Production testing established that v2.8.6 loads correctly and v2.8.7 does not. The browser startup audit reproduced the failure.

The primary failure was introduced by the v2.8.7 Hall/Insights lazy-module split:

- `renderView()` runs during initial Dashboard initialization.
- on the default Dashboard route it calls `setIntegratedFrameActivity("")`;
- that function referenced `integratedFrameIds`;
- the ID list had been moved out of the main Dashboard controller during the lazy split;
- the result was a synchronous `ReferenceError: integratedFrameIds is not defined` before `loadTeamData()` and `applySession()` could run.

This explains both production symptoms at once: the HTML Dashboard shell was visible but data remained in Loading state, and the authenticated user was not recognized as an administrator because session/admin initialization was never reached.

v2.8.9 removes that cross-module startup dependency. The main controller discovers the integrated frames directly from `iframe.dashboard-integrated-frame[id]`, so adding/removing an embedded tool cannot leave a missing or stale hard-coded identifier list.

## Secondary rollback dependency fixed

The emergency v2.8.8.3 rollback restored the v2.8.6 `loadTeamData()` body, but the later controller refactor had already removed the `clubMembersAPI` constant that the v2.8.6 second wave uses. That would allow the database summary to load but could throw when the automatic Chess.com member/profile/match refresh starts.

v2.8.9 restores the complete v2.8.6 Dashboard dependency contract, including `clubMembersAPI`, not only the function body.

## Dashboard loading policy

The proven v2.8.6 Dashboard loading sequence remains the baseline:

- first visible database summary through the existing Team Points client;
- database values applied immediately;
- automatic post-paint Chess.com members/profile/matches and Live data second wave;
- no low-priority scheduler is allowed to gate authentication or the first Dashboard state;
- Hall/Insights may remain lazy because their dependencies are now isolated from startup.

## Administration

The v2.8.8.x admin resilience fixes are retained:

- configured/local administrators are recognized before remote Chess.com verification;
- OAuth role claims remain supported;
- embedded Administration tools tolerate parent/iframe initialization-order races;
- failed integrated-frame loads have a bounded retry.

The browser startup gate verifies that a simulated Ximoon session becomes admin and that the Administration view can be entered without an uncaught startup exception.

## ACAMR re-enabled

ACAMR is re-enabled because the reproduced startup failure occurs before ACAMR participates in Dashboard work and is attributable to the missing startup identifier.

Activation remains:

- real OAuth authentication: ACAMR active regardless of the simulated-OAuth flag;
- simulated authentication: active only when the OAuth/simulatedOAuth flag is enabled;
- no authenticated session: inactive.

Scope remains:

- Club Points and Member Points discovery/verification support;
- relevant current-member match/board discovery;
- player archives used to locate finished team games;
- Daily/Classical and Chess960 rating refresh hints;
- low-frequency roster freshness hints.

Explicitly excluded:

- tournaments;
- match registration / registered-player monitoring / recruitment tracking.

Browser observations remain non-authoritative. Canonical facts are still written only after server-side verification.

## Retained v2.8.8 features and fixes

The independent v2.8.8 feature set remains, including:

- human Chess.com URLs for achievement trigger links;
- unified Profile actions from Daily and Live Hall;
- mobile Hall rank containment;
- DOM-preserving nested Profile/Achievement modals;
- Achievements as the default Hall tab;
- Team depth visualization;
- Club Intelligence dashboards and telemetry;
- data freshness / coverage and anomaly/action queue;
- member activity, availability/overload and contribution intelligence;
- recruitment-confidence model;
- opponent intelligence profiles and cached opponent logos;
- historical snapshots/time travel;
- explainable Club Points forecasts;
- cross-feature unified player modal;
- personalized authenticated home;
- achievement challenges;
- Opponent Intelligence W/D/L correction;
- Team Insights all-history Club Points progression through today + six months using the shared Low/Medium/High forecast model;
- Profile rank-image clipping fix;
- Administration iframe and lazy-feature retry hardening.

## Database and CRON

- Core schema: **6**.
- Analytics schema: **5**.
- No database reset, re-seed or new SQL migration is required from v2.8.8.x.
- CRON cadence is unchanged: Club every 5 minutes, Tournament every 10 minutes, Player every 30 minutes, league monitoring hourly.
- ACAMR remains opportunistic and adds no CRON entry.
