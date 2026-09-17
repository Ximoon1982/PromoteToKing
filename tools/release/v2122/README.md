# Promote to King v2.12.2 cumulative 2.12.x incremental installer

This package is a **scope-limited cumulative overlay** for existing P2K **v2.12.0 or v2.12.1** installations. It converges every immutable production file changed since the qualified v2.12.0 baseline (`c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303`) to the qualified v2.12.2 payload; it is not a full-tree reinstall.

The payload is generated from the intersection of the v2.12.0-to-v2.12.2 Git delta and the canonical immutable production-path selector. This includes the cumulative v2.12.1 work plus the v2.12.2 Events Showcase, Trophy compatibility, Match Recruitment integration/readiness fixes and runtime/version metadata. The installer does **not** require byte-identical source files: scoped files may be repaired/replaced transactionally, including a missing, stale or empty scoped loader file.

Mutable state is outside the transaction and is preserved, including `data/priority-matches.json` and `data/events-showcase.json`. Local configuration, uploads, runtime storage, unrelated application files and system CRON are also untouched. A failed activation removes newly introduced scoped paths and restores every pre-existing scoped file from the transaction backup.

## Install

```bash
cd /path/to/PromoteToKing_v2.12.2_INCREMENTAL_FROM_2.12.x
bash install-promote-to-king-v2.12.2.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing install
```

## Verify an installed v2.12.2 overlay

```bash
bash install-promote-to-king-v2.12.2.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing verify
```

The installer accepts semantic version `2.12.0` or `2.12.1`, and treats an already-installed `2.12.2` target as verify-only/idempotent. Non-2.12.x source versions are rejected before transaction activation.

Qualification covers both supported source versions, mutable-state and unrelated-file preservation, unchanged CRON, idempotency, forced-failure rollback, and a degraded 2.12.0 target with an empty scoped `assets/js/admin/tool-registry.js`.
