# Promote to King v2.10.9.2 recovery

Canonical development source remains the mounted/local release tree. GitHub is recovery/history.

The large text delta from canonical v2.10.9.1 is stored in the two `remaining-source.patch.xz.b85.part*` files. `rebuild-release.py` first applies the retained v2.10.9.1 recovery patch when necessary, then the v2.10.9.2 patch, verifies all 16 payload hashes, and rebuilds the delivered installer byte-for-byte.

Expected installer SHA-256: `79fa9e7df8e9c6711c8bdfe28ebe1c037682159aac2dffe854e0c02f97273991`.
