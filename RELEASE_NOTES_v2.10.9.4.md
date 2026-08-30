# Promote to King v2.10.9.4

Small corrective release built from the mounted canonical v2.10.9.3 tree.

## MCA live-tournaments pagination

- Uses only the current Chess.com club endpoint: `https://www.chess.com/club/live-tournaments/<club>?type=multi&page=N`.
- Removes the `/clubs/pastevents/...` fallback, which is not usable from the production host.
- Restores the row-scoped parsing contract used by the successful proof of concept: actual server-rendered tournament `<tr>` rows are authoritative.
- Arena links elsewhere in the document are ignored whenever tournament rows were successfully parsed. This prevents repeated page-1 links in navigation/application state from masquerading as page-2 results.
- Keeps broad arena-link parsing only as a compatibility fallback when Chess.com renders no tournament rows at all.
- Adds explicit `Cache-Control: no-cache`, `Pragma: no-cache`, transparent content decoding, effective-URL capture and response SHA diagnostics to the MCA HTTP client.
- Non-advancing pagination still fails closed; it never marks the one-time full reconciliation complete on a repeated page.

## Preserved v2.10.9.3 behavior

- Arena IDs identify creation order only and are not used as event chronology.
- The one-time full reconciliation remains occurrence ordered.
- Routine discovery remains newest-to-first-previous-index-known.
- Canonical-source date repair, no ID-derived date interpolation, and source deduplication behavior are unchanged.

## Preservation

- No database schema change.
- No runtime-data reset.
- No CRON definition change.
- Existing CSV bytes are preserved.
