# Promote to King v2.10.9.1 installation

This is an incremental corrective installer for an existing **v2.10.9** production installation. Idempotent replay on v2.10.9.1 is supported.

Use the final production package distributed with this release:

```bash
chmod +x PromoteToKing_v2.10.9.1_INCREMENTAL_INSTALLER_FINAL.run install-promote-to-king-2.10.9.1-final.sh
./install-promote-to-king-2.10.9.1-final.sh /path/to/promotetoking
```

Or, when both files are in the production site root:

```bash
./install-promote-to-king-2.10.9.1-final.sh .
```

The installer validates the embedded payload and the deployed `VERSION` boundary only. It deliberately does **not** require repository-only tests, source manifests, or exact predecessor hashes. Every production file it touches is backed up before replacement; installed files are hash-verified afterwards and restored automatically on failure.

It does not modify CRON or database schema. Existing runtime data is preserved. The existing v2.10.9 every-minute MCA scheduler continues to call the updated worker.
