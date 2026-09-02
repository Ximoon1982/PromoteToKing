# P2K Test Suite Audit & Regression Hardening Release

## Release contract

Promote to King v2.11.1 retains strict functional and visual parity with the promoted v2.11.0 R6 baseline, including its UI, workflows, data semantics, storage behavior and appearance, **except for the explicitly approved seven-day sliding authentication/session-persistence hardening in `server/team-points/src/OAuthSession.php`**. No other intentional product behavior change is permitted.

## Audit baseline

The inherited suite contains at least 160 Python test modules, 791 pytest-style test functions, 20 standalone browser gates, 8 PHP regression harnesses, the canonical JavaScript feature runner, and legacy standalone backend/storage/tracking runners.

Before this increment, no current workflow inventoried or ran all layers. The verification README documented only an older five-command subset, and release workflows selected tests independently. A test file or entire gate category could disappear without a central policy failure.

The audit also reproduced a collection-breaking defect: `pytest tests` imports legacy executable release checks whose top-level `SystemExit` aborts collection before the pytest-style suite can run. The canonical runner now selects real pytest modules explicitly and executes zero-test legacy modules separately, retaining both categories without cross-contamination.

The first isolated run executed all 795 pytest-style tests: 552 passed and 243 inherited assertions failed. Of 23 legacy standalone checks, 7 passed and 16 failed locally; the PHP-enabled CI environment exposed one additional legacy backend-ordering failure, producing a bounded cross-environment standalone debt ceiling of 17. Those exact node IDs/files are recorded as known debt. They do not hide new regressions: any failure outside the audited baseline fails CI, collection/runtime errors fail immediately, and resolved debt is reported. The policy forbids either debt count from growing.

The browser audit initially passed six gates before reproducing the timing-sensitive v2.9.14 interactive-survival timeout. Browser execution was changed from fail-fast to complete-inventory mode. The complete Linux CI audit passed 14 of 20 gates and identified six inherited environment-sensitive failures: the v2.9.14 interactive-survival gate, three OAuth/reconstruction gates, and two startup gates whose administration control is present but hidden. Those exact files are recorded as bounded browser debt; every gate still runs, any failure outside that list is rejected, and resolved debt is reported.

## Hardening changes

- One canonical read-only entry point provides `audit`, `static`, `regression` and `full` profiles.
- A checked-in policy sets conservative minimum counts and names indispensable gates.
- Python parsing and duplicate-name checks make collection hazards explicit.
- JavaScript and PHP sources are syntax-gated before regressions.
- The complete pytest-style suite and all legacy standalone Python checks share one command but run in isolated phases.
- All discovered browser gates share one full profile and a separate CI job.
- Test dependencies are pinned independently from production.
- Package validation remains separate because it verifies an exact artifact manifest.
- The legacy `tests/validate_package.py` remains inventoried but is not used as the v2.11.1 artifact authority because its contract is frozen to v2.10.6.23. The v2.11.1 builder instead emits and verifies its own payload manifest plus top-level source/installer checksums.
- A full-tree production-parity gate compares the branch with promoted R6 commit `93480c852fc4c554c9a404e5d68b0ac51efed04b`. The only permitted runtime difference is `server/team-points/src/OAuthSession.php`; unexpected HTML, CSS, JavaScript, PHP, image, configuration, manifest or other application changes fail CI.

## Inherited-debt triage

Every frozen pytest, standalone and browser failure is classified through `tests/debt-classification-policy-v2.11.1.json` as an obsolete historical assertion, environment-dependent failure, or potential real defect. Rules are ordered, exhaustive and machine-validated. Obsolete assertions may be repaired only by updating tests to the current release contract. Environment-dependent items remain explicit until a deterministic harness replaces them. Potential real defects are deferred for investigation and must not trigger an unreviewed production change in v2.11.1.

## Consolidation decision

No inherited tests were deleted. The audit found fragmented execution and documentation but did not establish semantic equivalence sufficient to justify deleting version-specific regressions. Future removals must identify the superseding assertion and update the policy in the same reviewed commit.

## Safety

The regression and parity machinery reads source and fixtures only. It does not migrate schemas, write production configuration, contact authenticated production services, or modify runtime data. Package qualification uses isolated temporary directories.
