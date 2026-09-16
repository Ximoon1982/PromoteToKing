# Promote to King v2.12.2 scope-limited incremental installer

This package is a **true incremental overlay** for the exact qualified P2K **v2.12.1** release (`3568e8c36d900fab341c3ded43b97adee3fb6263`). It does not contain or replace the full P2K immutable tree.

The payload is limited to the 11 production files added or changed by v2.12.2 (Events Showcase integration, Trophy card presentation compatibility, runtime/version metadata). Before installation it verifies the four overwritten v2.12.1 files byte-for-byte and verifies that the seven new v2.12.2 paths are absent.

Mutable state is outside the transaction and is preserved, including `data/priority-matches.json` and `data/events-showcase.json`. Local configuration, uploads, runtime storage, unrelated application files and system CRON are also untouched. A failed activation rolls back only the overlay and restores the exact pre-install tree.

## Install

```bash
cd /path/to/PromoteToKing_v2.12.2_INCREMENTAL_FROM_2.12.1
bash install-promote-to-king-v2.12.2.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing install
```

## Verify an installed v2.12.2 overlay

```bash
bash install-promote-to-king-v2.12.2.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing verify
```

The installer intentionally rejects v2.11.x and v2.12.0. Upgrade those installations to the qualified v2.12.1 baseline first rather than using this package as a full-tree convergence installer.
