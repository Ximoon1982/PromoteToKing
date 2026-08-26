# Promote to King v2.8.8.3 installation

1. Back up the deployed site and protected configuration files.
2. Upload/extract the v2.8.8.3 standalone package over the existing v2.8.8.x deployment, preserving protected production configuration.
3. No database reset or manual SQL migration is required. Core remains schema 6 and Analytics remains schema 5.
4. Keep the existing CRON schedule. Reinstall it only if needed with `reset-install-cron-v2.8.8.3.sh`.
5. Because this hotfix intentionally rolls back JavaScript introduced from v2.8.7 onward, perform a hard reload once after deployment. Active runtime assets use the v2.8.8.3 cache token.
6. Verify the Dashboard first: Team Points/member/match data should populate through the restored v2.8.6 loading sequence.
7. Verify simulated/real authentication and Administration. ACAMR should remain disabled.

No production secrets are included in the package.
