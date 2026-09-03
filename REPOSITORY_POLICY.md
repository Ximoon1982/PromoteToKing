# Promote to King — Repository Policy

## Source of truth

`Ximoon1982/PromoteToKing` is the durable source-of-truth repository for Promote to King.

The recovered and cryptographically verified baseline is **v2.10.6.23**. The `v2.10.6.23` tag must remain immutable.

## Release discipline

- Never reconstruct a released baseline from snippets when an exact tagged tree exists.
- Every released version gets an immutable Git tag.
- Earlier tags are never rewritten.
- Release work starts from the immediately preceding canonical tag/tree.
- The complete deployable project tree is retained in Git, including shipped sanitized reference data and artwork.
- Release documentation, installers, updaters, validation reports and manifests remain version controlled.
- Production/runtime data is not part of the canonical source tree unless explicitly sanitized and shipped as a fixture/reference dataset.

## Branch model

- `main`: latest released canonical tree.
- `next/vX.Y.Z`: integration branch for the next release.
- `archive/pre-canonical-2026-08-26`: preserves the repository state that existed before the canonical v2.10.6.23 import.

## Sensitive material

Never commit production credentials, OAuth tokens, database passwords, private keys, local host configuration, session state, mutable queues, caches, logs, database dumps or private exports. Commit sanitized `.example` files instead.

## Artwork

Approved artwork masters and their release derivatives are version controlled. Artwork approved for a future release may be staged on the corresponding `next/` branch before catalogue integration.

# Qualified-build static asset identity

Every qualified build must use a unique, immutable static-asset cache key derived from its exact source revision and build identity. The displayed semantic version is not a build identity and must never be the sole cache key, including when multiple qualified builds display the same semantic version. Package qualification must reject missing, semantic-version-only, inconsistent or reused build keys.
