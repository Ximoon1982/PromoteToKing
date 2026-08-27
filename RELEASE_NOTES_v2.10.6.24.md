# Promote to King v2.10.6.24

Canonical GitHub archival checkpoint for the production v2.10.6.24 line.

## Scope

- Adds first-class **Insights → Arenas** backed by the stored MCA Results archive and canonical MIRA identity attribution.
- Adds participation, field-share, best-placement, normalized percentile, MCA-points, W/D/L, score-percent, leaders, records, archive and arena-detail views.
- Integrates the seven approved Matches milestone artworks at 1, 10, 50, 100, 250, 500 and 1,000 matches, including the 1,000-match achievement threshold.
- No database schema reset, reseed, scoring-rule change or CRON change.

## Lineage

- Source baseline: **v2.10.6.23**.
- Expanded v2.10.6.24 implementation commit before metadata normalization: `0bf364d4813bdf120a98117628b52b0639ef4e0a`.
- Canonical v2.10.6.23 source archive SHA-256: `1462082218e177c9fab06aa13ea9ce0c82658a08ef9fda0eec97062f6cc1d78d`.
- Inherited complete `assets/images` archive SHA-256: `8a31701a139a03aaeb6546c7c03a9e38e51529a407cda4326f1342a8c5327490`.
- `resources/miac/seed.zip` remains losslessly recoverable from the verified `.p2k/large-files/` chunks.

Production deployment preceded this formal GitHub archival normalization. This checkpoint changes release-identifying metadata around the already-deployed v2.10.6.24 source; it does not introduce a new functional release.
