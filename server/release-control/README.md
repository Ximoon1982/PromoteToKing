# Promote to King release-control recovery plane

## v2.14.4 scope: candidate side-effect isolation

v2.14.4 keeps the public application on the qualified v2.14.2 direct-root release while
strengthening the authenticated Super Admin candidate preview introduced in v2.14.3.

The stable recovery plane remains outside switchable release slots. Promotion, rollback
and public release-slot routing remain deliberately disabled until the later roadmap
increments.

### Production transition from v2.14.2

The recommended production path is **not** the direct-root v2.14.4 installer.

Run the packaged:

`prepare-candidate-preview-v2.14.4.sh`

against the qualified public v2.14.2 installation. It:

1. verifies the exact public v2.14.2 build identity;
2. verifies the complete v2.14.4 package payload;
3. backs up the current stable recovery/preview infrastructure;
4. atomically updates only `.htaccess`, `ReleaseControl.php`, `PreviewRouter.php`,
   and `server/release-control/**`;
5. verifies public `VERSION` and `ui-v2.html` did not change;
6. installs v2.14.4 as a sealed dormant candidate slot;
7. registers that candidate in protected release-control state;
8. prebuilds and verifies the runtime preview tree from the CLI.

The browser's **Preview candidate for me** action remains lightweight: it validates the
prebuilt tree and writes only the signed preview-routing cookie.

### Side-effect isolation

A routed candidate request receives an explicit preview context. The preview router
creates a protected per-candidate/per-user sandbox under:

`data/runtime-v280/release-control/preview-sandboxes/<candidate>/<username>/`

Candidate application code then receives:

- read-only Team Points and Green MariaDB sessions;
- preview-local runtime, cache, logs and archive paths;
- a preview-local administrator PHP session namespace (`P2KTPPREVIEWSESSID`);
- disabled continuous/self-triggered CRON configuration.

The normal public Team Points session and OAuth session are not refreshed by candidate
identity/status checks. The one candidate POST allowed by policy is the administrator
session bootstrap, and it writes only the preview-local session.

### Fail-closed endpoint policy

Candidate preview continues to allow ordinary GET/HEAD application reads. The router
explicitly rejects OAuth mutations and known maintenance/background/repair/ingestion
endpoints, including Team Points CRON and match-tracking CRON. Other POST/PUT/PATCH/
DELETE requests remain rejected.

The public OAuth login/callback flow and the recovery-plane read-only OAuth status endpoint stay
outside the candidate runtime. Candidate logout/batch OAuth actions are not allowed through the
preview runtime.

### Legacy API protection

Some historical GET endpoints contain migration/housekeeping behavior. In preview:

- legacy match-tracking migration is suppressed;
- automatic tracking expiry is suppressed;
- diagnostics no longer performs a production filesystem write test;
- helper-based JSON/log writes are redirected into the preview sandbox.

These protections supplement, rather than replace, the database and router barriers.

### Preview tree

The candidate slot remains sealed. The prebuilt runtime preview tree contains candidate
application files plus explicit links to shared durable paths and host-local protected
configuration. Shared durable data may be read, but candidate mutations are prevented by
the read-only database policy, request policy and sandboxed write paths.

### Stable recovery infrastructure

Slot policy version 2 keeps these paths outside switchable release slots:

- root `.htaccess`
- `ReleaseControl.php`
- `PreviewRouter.php`
- `server/release-control/**`

Historical policy-1 slots remain valid and are inspected using the policy version sealed
in their metadata.

### Still disabled in v2.14.4

- public release-slot routing;
- candidate promotion;
- rollback;
- candidate CRON/background execution;
- ordinary candidate browser mutations.

The public release remains v2.14.2 until the later promotion increment.

## State contract retained from v2.14.0

The protected release state remains schema 1, with public serving in direct-root mode:

```json
{
  "schema_version": 1,
  "mode": "direct-root",
  "public_release": "2.14.2 (direct root)",
  "previous_public_release": null,
  "candidate_release": "2.14.4-<source-short>"
}
```

The recovery control plane must remain outside any switchable application slot.
