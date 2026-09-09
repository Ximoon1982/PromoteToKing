# P2K 1WL Engraving POC v1.9.1 integration

The r5 Trophy Gallery engraving editor is mechanically externalized from the user-supplied file `P2K_1WL_Engraving_POC_v1.9.1(1).html`.

- Exact supplied source SHA-256: `8c1308e10f343f95dda26628c83014a0c3102fe48afeb1253dcb5de6cd26d7b1`
- Integration changes: embedded font and nine PNG data URIs are local files under `assets/trophy-gallery/engraving/`; the Download action posts the generated PNG to its same-origin parent for managed upload when embedded and retains normal download behavior when standalone.
- Rendering configuration, engraving geometry, text fitting, finishes, controls and sample text are otherwise retained from the supplied POC.

The exact immutable source is retained at
`tools/poc/engraving/reference/P2K_1WL_Engraving_POC_v1.9.1.html`. It is kept
even though it duplicates the embedded assets so future releases can perform a
deterministic same-browser rendering comparison against the validated original.
