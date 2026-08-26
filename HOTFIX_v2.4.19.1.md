# Promote to King v2.4.19.1 hotfix

This hotfix repairs Matches, Opponents and Live ranks in UI v2.

## Upload

Upload the package contents over v2.4.19 while preserving paths. Do not overwrite `server/team-points/config/config.local.php`.

Hard-refresh `ui-v2.html` after upload.

## Verification

Open these URLs on the hosted site:

- `server/team-points/public/public.php?action=matches`
- `server/team-points/public/public.php?action=opponents`
- `server/team-points/public/public.php?action=live-ranks`

Each must return JSON. A valid empty dataset is acceptable; HTTP 404 is not.
