# Promote to King v2.12.1 universal incremental installer

Builds a self-contained, network-independent transactional installer for exact supported P2K 2.11.x and 2.12.0 immutable trees. Mutable/local state, databases, uploads, storage and system CRON are preserved. Unknown or future versions are rejected. The package derives one immutable static-asset cache key from the exact source revision plus build identity and stamps the packaged runtime before qualification.

Build: `bash tools/release/v2121/build-package.sh build-v2121`

Qualify: `bash tools/release/v2121/qualify-installer.sh build-v2121`
