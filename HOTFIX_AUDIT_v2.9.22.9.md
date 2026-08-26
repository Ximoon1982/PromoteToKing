# v2.9.22.9 focused hotfix audit

## Why Task Control became slower than the effective v2.9.22 behavior
The secured Team Points session endpoint and database connection class are unchanged. The effective difference is transport occupancy: after the OAuth saturation fixes, Fresh Reconstruction can keep the OAuth gateway continuously fed. On shared CGI/FastCGI hosting, each same-origin gateway POST occupies a PHP worker while its cURL-multi batch waits on Chess.com. Keeping five background POSTs resident can therefore starve unrelated session/status/diagnostic requests even though the browser scheduler notionally reserves a foreground gateway lane.

v2.9.22.9 caps background gateway residency at two PHP requests and treats Team Points administration traffic as server foreground pressure. The OAuth rate coordinator remains authoritative for Chess.com CPS; parallelism inside each gateway POST is retained.

## Why Club reconciliation showed false positives
The prior comparator could expose rows whose Club Points authority was effectively unchanged and also treated moving in-progress scores as actionable. The v2.9.22.9 comparator only treats missing matches and finished authoritative scoring facts as actionable. Stored-points-only errors are identified separately from final-result errors.

## Negative point reconciliation
Point delta is fresh minus Core and may be positive or negative. v2.9.22.9 returns full-set positive/negative/net statistics, uses signed Point Δ sorting, and provides server-side impact filters so point-removal corrections cannot be hidden by a limited result window.
