Promote to King — Trophy Gallery r5fix3.8

Scope: mobile/card containment correction on top of r5fix3.7.

Root cause addressed:
- the consolidated r5fix2.2 runtime still dynamically appends r5fix2.2.css; that stylesheet contains broad important rules such as .p2k-trophy-root button and can win against later card work depending on cascade order.
- r5fix3.8 marks the exact card nodes it owns and appends a small authoritative runtime style after the canonical/legacy Trophy styles.

Changes:
- card/button/art/title nodes have dedicated data-r538-* ownership markers;
- card content uses width:auto + stretch rather than width:100% plus inherited button padding;
- image stage remains square, flush, centered, object-fit:contain; title only remains;
- Proposal C internal modal design is unchanged;
- Trophy modal outer shell now mirrors the proven P2K Profile modal viewport strategy: 100dvh overlay, horizontal centering, safe-area padding, overflow containment, width:min(1080px,100%), max-width:100%, margin:auto, and 4px mobile gutter.

Installer remains deployment-safe: no shell PHP, immutable manifest, stage/scope proof, byte backup, same-filesystem atomic rename, rollback, exact verify.

JS SHA-256: d52193a717125f33359b12ac62dbdb77c35e71bb5c8632eae17e1ad3fff1ef9c
CSS SHA-256: fdcea54d62ba4c16b35d8c8bc1b109f6491200a3ede65f32373aac07712a9e0e
