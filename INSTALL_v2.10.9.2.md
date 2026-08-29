# Promote to King v2.10.9.2 installation

This is an incremental corrective installer for an existing **v2.10.9.1** production installation. Idempotent replay on v2.10.9.2 is supported.

```bash
chmod +x PromoteToKing_v2.10.9.2_INCREMENTAL_INSTALLER.run install-promote-to-king-2.10.9.2.sh
./install-promote-to-king-2.10.9.2.sh /path/to/promotetoking
```

Or, when both files are in the production site root:

```bash
./install-promote-to-king-2.10.9.2.sh .
```

The installer validates only its own embedded payload and the deployed `VERSION` boundary (`2.10.9.1`, or `2.10.9.2` for replay). It does not assume repository tests, source manifests, or predecessor files exist in production. Every touched production file is backed up before replacement, installed hashes are verified, and an installation failure after the copy stage triggers automatic file rollback.

No database schema or CRON definition is changed. Existing CSV files are never deleted: browser-suffixed legacy copies are retained for audit but excluded from derived MCA statistics. The existing MCA worker will detect whether a canonical rebuild is required; the Admin **Process complete MCA dataset** action can also be used to rebuild immediately.
