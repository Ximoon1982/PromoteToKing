# Promote to King v2.10.9 installation

Baseline: v2.10.8.

Use `PromoteToKing_v2.10.9_INCREMENTAL_INSTALLER.run` with the companion launcher `install-promote-to-king-2.10.9.sh`.

From any directory:

```sh
bash install-promote-to-king-2.10.9.sh /path/to/PromoteToKing
```

The installer accepts v2.10.8 or v2.10.9 for idempotent replay. It verifies its complete-file payload, lints changed PHP and shell files, backs up every touched application file and the current crontab, installs the v2.10.9 files atomically, applies Analytics schema 10 through the normal Repository upgrader, installs only the dedicated MCA every-minute CRON block, and verifies the installed hashes/version markers.

If installation fails after the production change begins, application files and the previous crontab are restored. Database schema additions are intentionally additive/idempotent and are not destructively rolled back; no existing tables or rows are deleted by the v2.10.9 schema migration.

The MCA scheduler installed by this release is:

```cron
* * * * *  # dedicated MCA arena worker; script slice <=55 s
```

The worker itself keeps discovery due-gated to 12 hours and enforces serial Chess.com requests at >=1 second spacing. All unrelated CRON entries are preserved.

No production secret, OAuth/session token, host-specific local configuration, existing MCA Results CSV, or unrelated runtime data is replaced.
