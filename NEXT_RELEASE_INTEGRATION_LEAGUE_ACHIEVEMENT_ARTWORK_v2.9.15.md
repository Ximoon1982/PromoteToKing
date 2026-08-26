# Post-v2.9.15 integration: approved league achievement artwork

Status: **integrated in the post-v2.9.15 source worktree only; no release build finalized.**

The five user-approved artwork packs for 1WL, PCL, TCMAC, TMCL and KOTML are integrated for all 35 league-specific achievement keys.

For every key:
- master: `assets/images/achievements/<key>.png` — exact approved 640×640 PNG bytes from the supplied pack;
- thumbnail: `assets/images/achievements/thumbs/128/<key>.webp` — 128×128;
- miniature: `assets/images/achievements/mini/64/<key>.webp` — 64×64;
- legacy miniature alias: `assets/images/achievements/mini/<key>.webp` — 64×64.

`server/team-points/src/AchievementCatalog.php` points all 35 league-specific achievements to their raster master + 128px thumbnail. No league placeholder is used for these 35 keys. No duplicate `_640x640` aliases are retained in the source tree.

The historical `ARTWORK_PROVENANCE_v2.9.2.*` files are intentionally left unchanged. Integration hashes and source-pack hashes are recorded in `POST_v2.9.15_LEAGUE_ARTWORK_INTEGRATION.json`.

No version number, schema, updater, release notes, standalone package or incremental package has been finalized as part of this staging integration.
