# Promote to King release-control recovery plane

## v2.14.2 scope: candidate installation

v2.14.2 adds a true candidate-installation path while **keeping public serving in direct-root mode**.
The fixed recovery page remains `/ReleaseControl.php`, server-rendered and independent from UI v1/UI v2 application shells.

### Candidate package contract

A candidate package is an extracted P2K incremental package containing:

- `CANDIDATE_RELEASE.json` — exact target version, source commit, cache/build identity, qualification workflow name, accepted public build identities, and the SHA-256 identity of the candidate payload manifest.
- `CANDIDATE_PAYLOAD.sha256` — hashes only release-owned overlay files. Recovery-plane and shared mutable files are deliberately excluded.
- `CANDIDATE_REMOVED_PATHS.txt` — optional explicit release-owned removals.
- `payload/` — the verified candidate overlay.

The normal cumulative installer remains usable for direct-root upgrades. Candidate installation is a separate operation and never runs the normal activation section.

### Candidate installation algorithm

`server/release-control/tools/install-candidate.php` is CLI-only.

It:

1. validates the candidate package and every candidate-overlay hash;
2. compares the package's accepted-public-build list with the exact live VERSION/build identity;
3. requires the matching sealed current public release slot and full-verifies it;
4. constructs the candidate tree from that immutable base slot;
5. hard-links unchanged release-owned files when the proven filesystem strategy allows it;
6. copies changed/new files from the candidate payload;
7. applies explicit removals;
8. seals and full-verifies the resulting slot;
9. atomically registers only `candidate_release` metadata in protected release-control state.

Direct-root public files are not changed.

Re-installing the exact same candidate is idempotent. Registering a different candidate while one is already selected requires the explicit `--replace-candidate` option; the existing slot is never overwritten.

### State behavior

Candidate registration initializes schema-1 state when needed, but keeps:

```json
{
  "schema_version": 1,
  "mode": "direct-root",
  "public_release": "2.14.2 (direct root)",
  "candidate_release": "2.14.3-<source-short>"
}
```

Public routing therefore remains unchanged. State writes use a lock, a private temporary file, and same-directory atomic rename.

### Recovery and shared-state boundary

Release slots continue to exclude:

- `data/**`
- `logs/**`
- `storage/**`
- host-local `*.local.*` and `.env*`
- `ReleaseControl.php`
- `server/release-control/**`

The recovery plane itself is never part of a switchable candidate slot and must remain outside any switchable application slot.

### Still intentionally disabled in v2.14.2

- per-Super-Admin candidate preview;
- public release pointer switching;
- promotion;
- rollback;
- public slot routing;
- candidate/background CRON execution.

Candidate installation only prepares and registers a verified dormant release.

## Filesystem model retained from v2.14.1

The production-host probe records cross-root hard-link support, hard-link snapshot isolation under atomic replacement, symlink availability and atomic rename support. Hard links are selected only when the host proved the required behavior; otherwise candidate assembly falls back to copies. Symlinks are never used as release snapshots.

Hard links share an inode. P2K therefore continues to treat installed release files as immutable and uses staged atomic replacement for direct-root upgrades rather than editing release-owned files in place.
