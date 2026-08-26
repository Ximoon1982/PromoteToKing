# Migration v2.10.6.23

No schema migration is required. Deploy the source update only.

The member chronology corrective is backward-compatible with existing stored events: reads collapse correlated rename/discovery/join/left records, while future confirmed renames also remove the temporary lifecycle interpretations. Tournament data continues to use the existing archive.
