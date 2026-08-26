# Install Promote to King v2.10.5.3

v2.10.5.3 is an in-place corrective for an installed v2.10.5.2 site. It accepts both pristine v2.10.5.2 and v2.10.5.2 with GABFIX1 already installed.

## Recommended installation

Place the v2.10.5.3 incremental ZIP and `install-v2.10.5.3.sh` in the PromoteToKing root, then run:

```bash
chmod +x install-v2.10.5.3.sh
bash install-v2.10.5.3.sh "$PWD"
```

The release updater performs an exact source overlay with rollback protection. Local Blue/Green configuration and credentials are not part of the payload.

## After installation

1. Open **Administration → Scheduled Task Control → Green Team Points**.
2. Refresh Green status.
3. Leave **GFFL enabled**.
4. Start the Green Accelerator when OAuth acceleration is available, especially while GAB remains incomplete.
5. Let the current Green cycle continue. Do **not** reset or reseed Cycle #16.
6. Watch GQAC: exhausted transient current-cycle obligations may move to `deferred`/`requeued for next`, allowing the finite Quick cycle to complete while preserving their underlying refresh debt.

No database reset, Green Core reseed or CRON change is required.
