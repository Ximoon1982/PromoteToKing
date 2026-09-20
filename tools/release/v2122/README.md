# Promote to King v2.12.2 cumulative 2.12.x incremental installer

This package is a **scope-limited cumulative overlay** for existing P2K **v2.12.0 or v2.12.1** installations, and may also be safely re-applied to an already-marked **v2.12.2** target to repair a partial or stale scoped overlay. It converges every immutable production file changed since the qualified v2.12.0 baseline (`c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303`) to the qualified v2.12.2 payload; it is not a full-tree reinstall.

The payload is generated from the intersection of the v2.12.0-to-v2.12.2 Git delta and the canonical immutable production-path selector. This includes the cumulative v2.12.1 work plus the v2.12.2 Events Showcase, Trophy compatibility, Match Recruitment integration/readiness fixes and runtime/version metadata. The same cumulative overlay also carries the v2.12.2 corrective dashboard/admin-health loading changes and the seven-day OAuth persistence hardening: `P2KOAUTH` uses protected isolated server-side session storage, preserves compatible legacy sessions during the move, and restores the caller PHP session runtime before DMA, Super Bingo, PPA or other bridges open their own sessions. The installer does **not** require byte-identical source files: scoped files may be repaired/replaced transactionally, including a missing, stale or empty scoped loader file.

Mutable state is outside the transaction and is preserved, including `data/priority-matches.json` and `data/events-showcase.json`. Local configuration, uploads, runtime storage, unrelated application files and system CRON are also untouched. A failed activation removes newly introduced scoped paths and restores every pre-existing scoped file from the transaction backup.

## Install or repair

```bash
cd /path/to/PromoteToKing_v2.12.2_INCREMENTAL_FROM_2.12.x
bash install-promote-to-king-v2.12.2.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing install
```

## Verify an installed v2.12.2 overlay

```bash
bash install-promote-to-king-v2.12.2.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing verify
```

The installer accepts semantic version `2.12.0`, `2.12.1` or `2.12.2`. On a `2.12.2` target, `install` transactionally reapplies the qualified scoped overlay so a partial or stale installation self-heals; `verify` remains read-only. Non-2.12.x source versions are rejected before transaction activation.

Qualification covers both supported upgrade source versions, safe reapplication to a complete v2.12.2 target, repair of a partial v2.12.2 target with a stale scoped file, mutable-state and unrelated-file preservation, unchanged CRON, idempotency, forced-failure rollback, and a degraded 2.12.0 target with an empty scoped `assets/js/admin/tool-registry.js`.
