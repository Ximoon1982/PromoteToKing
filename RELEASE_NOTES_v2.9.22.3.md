# Promote to King v2.9.22.3

Minor artwork integration release based on the frozen v2.9.22.2 application tree.

## Seniority achievement artwork

Integrates the seven user-approved 640×640 Seniority achievement artworks from `P2K_APPROVED_Seniority_Artwork_FutureIntegration.zip`:

- `seniority-1m` — New Recruit
- `seniority-3m` — Established Member
- `seniority-6m` — Six-Month Member
- `seniority-1y` — One-Year Member
- `seniority-2y` — Two-Year Member
- `seniority-3y` — Three-Year Member
- `seniority-5y` — Five-Year Member

Each approved master is installed at `assets/images/achievements/<key>.png`. Standard 128px and 64px WebP derivatives are regenerated from the approved masters. The Achievement Catalogue now references those raster assets instead of the former Seniority SVG placeholders.

Achievement keys, names, criteria, award logic, catalogue size (162), Core schema (15), Analytics schema (7), CRON contract, Fresh Points Reconstruction, ACDM and OAuth behavior are unchanged.

## Validation scope

Because this is an artwork-only minor update, validation is intentionally focused on artwork lineage, catalogue mapping, package integrity, affected syntax and deployment replay rather than unrelated full regression suites.
