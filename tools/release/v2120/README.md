# Promote to King v2.12.0 universal incremental installer

This self-contained, network-independent package upgrades an exact supported
canonical P2K 2.11.0–2.11.5 installation to v2.12.0.

The installer authenticates the complete package, verifies the exact source
immutable-tree fingerprint, checks disk space and write access, stages the new
tree, backs up every affected file, activates the payload, removes only known
obsolete managed immutable paths, and verifies exact target-tree convergence.
Any failure during mutation restores the original files.

Local configuration, credentials, OAuth state, `.env` files, databases, data,
storage, caches, uploads, logs, runtime state, backups, user-generated material,
and system CRON are not managed or replaced. This release has no database
migration and makes no CRON change.

## Install

```bash
unzip PromoteToKing_v2.12.0_INCREMENTAL.zip
cd PromoteToKing_v2.12.0_INCREMENTAL
chmod +x install-promote-to-king-v2.12.0.sh

./install-promote-to-king-v2.12.0.sh \
  /kunden/homepages/43/d141198007/htdocs/PromoteToKing
```

## Verify

```bash
./install-promote-to-king-v2.12.0.sh \
  /kunden/homepages/43/d141198007/htdocs/PromoteToKing \
  verify
```

`check` is an alias for the same complete installed-tree verification.
Re-running install on an already correct v2.12.0 installation is safe and
performs verification without changing it.

The installer requires Bash, Python 3, and standard Unix utilities. It does not
require Git, GitHub CLI, Composer, npm, Docker, root privileges, or internet
access.
