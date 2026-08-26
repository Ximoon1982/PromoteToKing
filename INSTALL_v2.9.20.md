# Promote to King v2.9.20 installation

## Incremental upgrade from v2.9.19
Place these two files in the Promote to King production root:

- `PromoteToKing_v2.9.19_to_v2.9.20_INCREMENTAL.zip`
- `update-v2.9.19-to-v2.9.20.sh`

Then run:

```bash
cd /path/to/promotetoking
chmod +x update-v2.9.19-to-v2.9.20.sh
./update-v2.9.19-to-v2.9.20.sh
```

The updater validates the incremental manifest before touching production, makes compact rollback archives, preserves protected configuration/runtime state, applies the byte-verified delta, validates the installed release, and installs/verifies the unchanged 4+1 managed CRON schedule.

## Fresh/full installation
Extract `PromoteToKing_Standalone_v2.9.20.zip` into the web root, keep production secrets out of the release archive, configure the existing protected local configuration files as applicable, and install the v2.9.20 CRON/OAuth helpers using the existing production procedure.
