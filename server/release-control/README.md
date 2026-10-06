# Promote to King release-control recovery plane

## v2.14.5 scope: atomic promotion and rollback

v2.14.5 extends the side-effect-isolated candidate preview from v2.14.4 with a stable
public routing plane and controlled public release transitions.

The physical Promote to King root remains the recovery baseline. Installing the v2.14.5
candidate-preview infrastructure does **not** overwrite the public application tree:
public serving in direct-root mode continues through `PublicRouter.php` until an
authenticated Super Admin explicitly promotes a verified candidate.

### Stable routing plane

The following paths remain outside switchable release slots:

- root `.htaccess`
- `ReleaseControl.php`
- `PreviewRouter.php`
- `PublicRouter.php`
- `server/release-control/**`

`PublicRouter.php` is the only public release selector. In direct-root mode it serves
the existing physical root. In slot mode it serves a prepared runtime tree for the
release id stored in protected release-control state.

The recovery control plane must remain outside any switchable application slot.

### Runtime trees

Immutable release slots intentionally exclude mutable data and host-local configuration.
Before promotion or rollback, v2.14.5 prepares and verifies a runtime tree under:

`data/runtime-v280/release-control/runtime-trees/<release-id>/`

The tree contains immutable release files, preferably hard-linked from the sealed slot,
plus links to the stable installation's shared `data/`, `logs/`, `storage/` and
host-local configuration files. Runtime trees are published with atomic rename.

### Atomic promotion

Promotion performs all expensive work before changing public traffic and commits the switch with one atomic state-file replacement:

1. verify the registered candidate slot with full hashes;
2. verify the current public release slot with full hashes;
3. prepare/verify runtime trees for both releases;
4. acquire the protected release-state lock;
5. re-check that candidate/public identities have not changed;
6. replace `state.json` atomically with:
   - `mode = slots`
   - `public_release = <candidate>`
   - `previous_public_release = <old-public>`
   - no registered candidate.

The final state-file rename is the public switch. No application files are copied over
the live root during promotion.

### Atomic rollback

Rollback verifies both the current and previous public slots/runtime trees, then performs
one atomic state change back to the previous release. The release being rolled back from
is registered again as the candidate so it can be previewed and re-promoted during the
v2.14.6 lifecycle proof.

### HTTP and CRON alignment

Existing Promote to King HTTP/curl CRON endpoints are invoked by the operational dispatchers over HTTPS.
Those URLs resolve through the same `PublicRouter.php` as normal public traffic.
Therefore promotion/rollback changes HTTP and the active HTTP/curl CRON implementation
together. Candidate preview continues to block candidate CRON/background execution.

### Candidate installation after promotion

Candidate installation is now public-slot-aware. Once slot routing is active, a later
candidate is validated and materialized from the **routed public release slot**, not from
the untouched physical recovery root. This is required for v2.14.6 and subsequent
releases.

### OAuth and preview

Candidate preview remains per-browser, Super Admin-only and side-effect-isolated.
The public OAuth login/callback plane follows the selected public release. The
recovery-plane read-only OAuth status bridge remains stable outside release slots.

Promotion and rollback clear the current browser's preview cookie. Other stale preview
cookies fail closed because their candidate id no longer matches protected state.

### Release Control actions

`/ReleaseControl.php` exposes:

- Preview candidate for me
- Promote candidate
- Rollback

Promotion and rollback require the authenticated Release Control Super Admin session,
the existing CSRF token, and an explicit confirmation checkbox.

Release Control shows the physical root baseline, routed public VERSION/release, candidate,
previous public rollback target, transition sequence, slot integrity and runtime readiness.

## State contract

The protected state remains schema 1. Before the first promotion:

```json
{
  "schema_version": 1,
  "mode": "direct-root",
  "public_release": "2.14.2 (direct root)",
  "previous_public_release": null,
  "candidate_release": "2.14.5-<source-short>"
}
```

After promotion:

```json
{
  "schema_version": 1,
  "mode": "slots",
  "public_release": "2.14.5-<source-short>",
  "previous_public_release": "2.14.2-9257577544d8",
  "candidate_release": null
}
```

After rollback, the old public slot is selected again and the rolled-back v2.14.5 slot
becomes the registered candidate for verification/re-promotion.

## Still deferred to v2.14.6

v2.14.5 provides and qualifies the mechanics. v2.14.6 performs the complete production
lifecycle proof:

`install candidate -> preview -> promote -> verify -> rollback -> verify -> re-promote`

Version cleanup/retention controls, browser ZIP upload/install and the filesystem cleanup
tool remain later roadmap items.
