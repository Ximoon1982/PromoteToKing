# Install Promote to King v2.10.6.7

v2.10.6.7 is an incremental source update over v2.10.6.6. No database migration, reset, reseed, CRON edit, or image deployment is required.

## Incremental install

From the PromoteToKing application directory, place `P2K_v2.10.6.6_to_v2.10.6.7_INCREMENTAL.zip` there and run:

```bash
rm -rf /tmp/p2k-v21067 && mkdir -p /tmp/p2k-v21067 && unzip -q P2K_v2.10.6.6_to_v2.10.6.7_INCREMENTAL.zip -d /tmp/p2k-v21067 && bash /tmp/p2k-v21067/update-v2.10.6.6-to-v2.10.6.7.sh
```

The updater explicitly selects a modern PHP CLI, preferring `/usr/bin/php8.5-cli`, and validates the resulting source.

## After deployment

1. Open Administration → Scheduled Task Control → Green.
2. Confirm timestamps are shown in local browser time (CEST in Luxembourg on 20 August 2026).
3. Inspect GAB reconciliation: it should show `projection attempts`, convergence pass, and last full-pass remaining count rather than an impossible numerator/denominator.
4. Press **Validate Green**. The Cutover panel lists the exact remaining blockers.
5. Do not bypass the gate. Once **Read cutover = READY**, select **Green reads · both maintained** and apply the migration phase. Keep Blue maintained for rollback observation before later moving to Green primary.
