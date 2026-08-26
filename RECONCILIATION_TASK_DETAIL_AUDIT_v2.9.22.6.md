# v2.9.22.6 focused audit

Scope intentionally limited to Team Points task-detail loading and Fresh Points incremental reconciliation.

Acceptance contract:

1. Club/Player selected-card detail does not call full Repository summary/synchronization coverage/full queue history.
2. Club differences are only missing matches or different authoritative match findings and are independently applicable.
3. Player differences are independently applicable per current member once that member is complete.
4. Acquisition errors do not block unrelated entity corrections.
5. Finalization supersedes only verified/satisfied queue work and retains acquisition-error work.
6. Player archive fallback begins at max(2024-01, Chess.com account creation month), verifies candidate matches as P2K matches containing the player, and ignores unrelated team matches.
7. Ongoing games remain valid pending history, not errors.
8. Core 16 migration is additive and Analytics remains 7.
