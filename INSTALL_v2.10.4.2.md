# Promote to King v2.10.4.2 installation

This is an application-only OAuth administrator-persistence hotfix for v2.10.4 / v2.10.4.1.

Use the cumulative incremental updater supplied with the release. The updater accepts either source release, verifies payload hashes, backs up every overwritten file, preserves protected local configuration, and performs no database or CRON operation.

After installation, reload the site normally and verify administrator access on:

- Administration dashboard
- Live Ranks administration
- Storage & Capacity
- Team Points Migration
- Scheduled Task Control

A normal page reload must retain the real administrator authority without requiring another Chess.com login. `?name=<non-admin>` must continue to hide administrator UI even while the real authenticated account remains an administrator for server credentials.
