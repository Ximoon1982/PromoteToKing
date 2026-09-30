# Promote to King release-control recovery plane

## v2.14.1 scope: release-slot foundation

v2.14.1 adds immutable release-slot storage while **keeping public serving in direct-root mode**.
The fixed recovery page remains `/ReleaseControl.php`; it is server-rendered and has no dependency on UI v1, UI v2, dashboard JavaScript, or normal CSS bundles.

### Installer behavior

The installer first performs the normal no-write cumulative preflight. Before changing production it probes the production filesystem for cross-root hard links, hard-link snapshot isolation after atomic source replacement, symlink support, and atomic rename support. It then seals a snapshot of the currently installed immutable application tree. After the v2.14.1 overlay has been activated and fully verified, it seals a second snapshot for v2.14.1.

The default protected slot location is:

`data/runtime-v280/release-control/releases/<version>-<source-short>/`

or the configured Team Points runtime directory when `storage.runtime_dir` is set.

Each slot contains `app/` plus a protected `meta/` directory with a SHA-256 manifest, exact build identity and a seal marker.

### Disk and filesystem model

When the host proves that cross-root hard links and atomic replacement are safe, unchanged files are hard-linked instead of copied. A later atomic replacement of a live file gives production a new inode while an older slot retains the previous inode. This preserves rollback material without duplicating unchanged file contents at creation time.

If hard links are unavailable or fail the safety probe, v2.14.1 falls back to copies. Symlink support is recorded for diagnostics but symlinks are not used as immutable snapshots because they would follow future direct-root replacements.

### Shared mutable state

Slots deliberately exclude `data/**`, `logs/**`, `storage/**`, host-local `*.local.*` / `.env*` configuration, and the recovery plane itself (`ReleaseControl.php` plus `server/release-control/**`).

### Immutability and validation

Slots are assembled in a temporary directory and published only through a same-directory atomic rename. Existing slots are never overwritten. Re-running the installer accepts an existing slot only after full hash verification and identity matching. The Release Control page performs lightweight manifest/structure validation; full verification is available through the CLI-only `server/release-control/tools/verify-slot.php` tool.

### Still intentionally disabled in v2.14.1

Candidate installation, per-Super-Admin preview, public release pointer switching, promotion, rollback, and slot-based CRON execution remain disabled. Slot existence alone never changes what users receive.
