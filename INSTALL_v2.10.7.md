# Promote to King v2.10.7 installation

Baseline: v2.10.6.25 (corrected R3 state).

Use `PromoteToKing_v2.10.7_INCREMENTAL_INSTALLER_CLEAN.run`. It contains complete finished replacement files and performs no PHP source transformation on the server.

From the PromoteToKing application root:

```sh
chmod +x PromoteToKing_v2.10.7_INCREMENTAL_INSTALLER_CLEAN.run
./PromoteToKing_v2.10.7_INCREMENTAL_INSTALLER_CLEAN.run
```

The installer verifies the payload, lints staged PHP with a compatible PHP 8.1+ CLI, backs up every touched existing file, copies only the v2.10.7 payload, verifies installed hashes/version, and automatically rolls back on failure. It does not modify databases, runtime data, local configuration, secrets, or CRON.
