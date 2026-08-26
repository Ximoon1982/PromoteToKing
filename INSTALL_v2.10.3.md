# Install Promote to King v2.10.3

The cumulative incremental updater is intended for a v2.10.1.2 installation, including installations where either or both post-v2.10.1.2 Green hotfixes were already deployed.

```bash
cd ~/PromoteToKing
unzip -o PromoteToKing_v2.10.1.2_to_v2.10.3_CUMULATIVE_INCREMENTAL.zip
chmod +x P2K_v2.10.1.2_to_v2.10.3_CUMULATIVE_INCREMENTAL/update-v2.10.1.2-to-v2.10.3.sh
bash P2K_v2.10.1.2_to_v2.10.3_CUMULATIVE_INCREMENTAL/update-v2.10.1.2-to-v2.10.3.sh "$PWD"
```

The installer backs up every overwritten release file, protects local credential/config files, uses an IONOS-compatible versioned PHP CLI for syntax validation when available, and rolls release files back if post-install validation fails.

No database migration, reset or reseed is performed. The existing Green databases and migration state are left in place. The new achievement logic becomes eligible for the normal achievement/analytics refresh through its v2.10.3 source watermark.
