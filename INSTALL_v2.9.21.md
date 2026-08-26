# Promote to King v2.9.21 installation

For an existing v2.9.20 installation, place the v2.9.20-to-v2.9.21 incremental ZIP and `update-v2.9.20-to-v2.9.21.sh` in the site root, make the updater executable, and run it from that site root.

The updater validates that the installed `VERSION` is exactly `2.9.20`, verifies every payload hash, preserves protected configuration, updates the managed CRON entries to the v2.9.21 helper names, and verifies the final installed hashes.

No MCA CSV re-upload is needed after this corrective release.
