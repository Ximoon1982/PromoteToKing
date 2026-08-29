# Promote to King v2.10.9.1 recovery

This directory completes the durable recovery record for v2.10.9.1.

- Base release: `42f179bee4f10c88b8b4a133a904666ab62305f7` (v2.10.9).
- `remaining-source.patch.xz` is the exact v2.10.9 -> v2.10.9.1 delta for the seven large text files not directly rewritten through the connector.
- `installer-header.sh` and `payload-manifest.json`, together with `rebuild-release.py`, reproduce the delivered FINAL self-extracting installer byte-for-byte.
- FINAL installer SHA-256: `38416bbc8518b8443c75371bcfb5d6dec4361ee0fda1b933a7a8c9d6ec5820d2`, size 178267 bytes.
- Canonical predecessor source manifest SHA-256: `908542ab3de1f572bce44596b30d1e5bf72383e6b4c348f4d01cd3e1b5fac142`, size 496350 bytes.
- Remaining patch SHA-256: `bba19abdedfe9660393a6459685aa967ae8fb4a3b652601d4eda3e7f42b988e7`.

Rebuild from a checkout of `main`:

```bash
python3 .p2k/v21091/rebuild-release.py . PromoteToKing_v2.10.9.1_INCREMENTAL_INSTALLER_FINAL.run
```

The reproducer verifies the resulting installer SHA before writing success.
