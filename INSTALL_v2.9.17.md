# Install Promote to King v2.9.17

For an existing canonical v2.9.16 site, place `PromoteToKing_v2.9.16_to_v2.9.17_INCREMENTAL.zip` and `update-v2.9.16-to-v2.9.17.sh` in the site root, then run:

```bash
chmod +x update-v2.9.16-to-v2.9.17.sh
./update-v2.9.16-to-v2.9.17.sh
```

Do not manually unzip the incremental archive. The updater validates the payload, creates compact rollback archives, preserves protected configuration/OAuth/runtime data, installs the four v2.9.17 CRON dispatcher entries, validates the installation and rolls back if validation fails.

Fresh installations use the standalone archive and the normal protected configuration workflow.
