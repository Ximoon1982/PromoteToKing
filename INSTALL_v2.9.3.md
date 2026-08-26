# Promote to King v2.9.3 installation

## Existing v2.9.2 production site

1. Upload `PromoteToKing_v2.9.2_to_v2.9.3_INCREMENTAL.zip` and `update-v2.9.2-to-v2.9.3.sh` to the website root.
2. From an SSH shell in that root, run `bash update-v2.9.2-to-v2.9.3.sh`.
3. The script validates the package before modification, creates a timestamped `_backup`, applies verified files, validates the installation, and reinstalls the four managed P2K CRON entries.

No arguments are required. Existing configuration and data are not supplied by the release and are backed up before replacement work.

## Fresh/standalone package

Extract `PromoteToKing_Standalone_v2.9.3.zip` into a clean site root and supply the normal protected production configuration. The release does not ship production databases, local secrets, runtime logs, or `_backup` content.
