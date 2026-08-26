# Promote to King v2.9.4 installation

## Incremental production update

Place `PromoteToKing_v2.9.3_to_v2.9.4_INCREMENTAL.zip` and `update-v2.9.3-to-v2.9.4.sh` in the current site root, then run:

`bash update-v2.9.3-to-v2.9.4.sh`

The updater verifies the incremental manifest/hashes before replacement, backs up configuration/data/logs/replaced files and crontab under `_backup`, applies the payload, validates the release, and installs the four v2.9.4 managed CRON entries while preserving unrelated CRON lines.

## Standalone

Extract `PromoteToKing_Standalone_v2.9.4.zip` into a clean site root and supply the protected production configuration as usual.
