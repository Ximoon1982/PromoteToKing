# Promote to King release-control recovery plane

v2.14.0 introduces the **foundation only** for future release slots and atomic deployment.

## Recovery invariants

- `/ReleaseControl.php` is a fixed standalone URL.
- It is server-rendered and has no dependency on UI v1, UI v2, dashboard JavaScript, or normal CSS bundles.
- It uses a separate Super Admin allowlist. The allowlist can be supplied by `P2K_RELEASE_CONTROL_ADMINS` or protected `server/release-control/config/config.local.php`; the release default is `ximoon`.
- It reads existing server-side P2K administrator/OAuth sessions in read-only mode.
- It never exposes OAuth or administrator tokens.
- v2.14.0 performs **no release-state mutation** and changes no public routing.
- Candidate install, personal preview, promotion, and rollback remain disabled until later v2.14.x increments.

## Future state contract

The protected state location is:

`data/runtime-v280/release-control/state.json`

(or the configured Team Points runtime directory).

Schema 1 reserves these fields:

```json
{
  "schema_version": 1,
  "mode": "direct-root",
  "public_release": null,
  "previous_public_release": null,
  "candidate_release": null,
  "updated_at": null,
  "updated_by": null
}
```

v2.14.0 does not create this file. If it is absent, Release Control reports the current direct-root installation as the public release.

## Recovery-plane boundary

When release slots arrive in later increments, `ReleaseControl.php`, `server/release-control/`, the release pointer/state, and the minimal authentication path must remain outside any switchable application slot. Public promotion must therefore never replace the recovery plane itself.
