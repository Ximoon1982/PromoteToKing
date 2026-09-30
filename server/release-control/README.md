# Promote to King release-control recovery plane

## v2.14.3 scope: Super Admin-only candidate preview

v2.14.3 adds authenticated personal preview of one registered candidate while the
public site remains in direct-root mode. The stable control plane remains outside
switchable release slots.

### Production transition from v2.14.2

The recommended production path is **not** the direct-root v2.14.3 installer.

Run the packaged:

`prepare-candidate-preview-v2.14.3.sh`

against the qualified public v2.14.2 installation. It:

1. verifies the exact public v2.14.2 build identity;
2. verifies the complete v2.14.3 package payload;
3. backs up the current stable recovery/preview infrastructure;
4. atomically updates only `.htaccess`, `ReleaseControl.php`,
   `PreviewRouter.php`, and `server/release-control/**`;
5. verifies public `VERSION` and `ui-v2.html` did not change;
6. installs v2.14.3 as a sealed dormant candidate slot;
7. registers that candidate in protected release-control state;
8. prebuilds and verifies the runtime preview tree from the CLI.

The public application therefore remains v2.14.2. The browser's **Preview candidate for me**
action performs no slot hashing or tree construction; it only validates the prebuilt tree
and writes the signed, authenticated preview cookie. This keeps preview activation fast
and avoids shared-hosting request timeouts.

### Preview session

`/ReleaseControl.php` exposes **Preview candidate for me** only to an authenticated
Release Control Super Admin.

The preview session is:

- bound to the authenticated username;
- stored in a Secure, HttpOnly, SameSite=Lax cookie;
- signed with a server-side HMAC secret kept under protected runtime storage;
- bound to the currently registered candidate release;
- time-limited;
- revalidated by `PreviewRouter.php` on every routed request.

The cookie is only a routing hint. Its presence alone never grants preview access.

### Preview tree

The candidate slot remains sealed. Enabling preview creates a separate runtime preview
tree under protected release-control storage.

Candidate application files are hard-linked from the verified candidate slot when
possible. Shared mutable production paths are attached only to the preview tree:

- `data/**`
- `logs/**`
- `storage/**`
- host-local `.env*` files
- host-local `*.local.*` configuration files

The candidate slot itself is not modified.

### Stable recovery infrastructure

Beginning with slot policy version 2, these paths are outside switchable release slots:

- root `.htaccess`
- `ReleaseControl.php`
- `PreviewRouter.php`
- `server/release-control/**`

Historical policy-1 slots remain valid and are inspected using the policy version
recorded in their own sealed metadata.

### Routing boundary

Only requests with a valid Super Admin preview session are internally routed through
`PreviewRouter.php`. Recovery paths, OAuth callback traffic, and directly requested
shared storage paths stay outside candidate routing.

CRON/background jobs do not carry the browser preview cookie and therefore continue
to execute the public release.

### Write boundary in v2.14.3

Candidate preview accepts only GET and HEAD requests. POST, PUT, PATCH, DELETE and
other methods are rejected with `CANDIDATE_PREVIEW_WRITE_BLOCKED`.

This is deliberate. v2.14.4 is reserved for explicit side-effect isolation and the
policy for candidate writes/background behavior.

### Still disabled in v2.14.3

- public release-slot routing;
- candidate promotion;
- rollback;
- candidate CRON/background execution;
- candidate browser writes.

The public release remains v2.14.2 until a later promotion increment.


## State contract retained from v2.14.0

The protected release state remains schema 1 and keeps public serving in direct-root mode:

```json
{
  "schema_version": 1,
  "mode": "direct-root",
  "public_release": "2.14.2 (direct root)",
  "previous_public_release": null,
  "candidate_release": "2.14.3-<source-short>"
}
```

The recovery control plane must remain outside any switchable application slot.
