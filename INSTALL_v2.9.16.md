# Install Promote to King v2.9.16

For an existing canonical v2.9.15 site, place `PromoteToKing_v2.9.15_to_v2.9.16_INCREMENTAL.zip` and `update-v2.9.15-to-v2.9.16.sh` in the site root, then run:

```bash
chmod +x update-v2.9.15-to-v2.9.16.sh
./update-v2.9.15-to-v2.9.16.sh
```

Do not manually unzip the incremental archive. The updater validates the payload, creates compact rollback archives, preserves protected configuration/OAuth/runtime data, installs the four v2.9.16 CRON dispatcher entries, validates the installation and rolls back if validation fails.

Fresh installations use the standalone archive and the normal protected configuration workflow.
