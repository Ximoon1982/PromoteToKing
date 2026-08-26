# Next release integration — opportunistic Chess.com observations

Baseline: v2.8.3 plus Tournament Achievement Date Integration Revision 2.

## Purpose
When the browser already downloads useful public Chess.com JSON, it asynchronously sends the raw response to the same-origin server. This adds no extra Chess.com request from the browser and never delays rendering.

## Server behavior
- `server/team-points/public/observe.php` accepts same-origin batches only, max 12 observations / 2 MiB, with a protected runtime rate limiter.
- `club/{P2K}/matches`: extracts match IDs/status buckets only as hints and queues authoritative match details when the database says they are due; the browser does not write match metadata.
- `club/{P2K}/members`: queues the existing authoritative roster refresh; the browser does not alter membership flags.
- `player/{username}/matches`: accepts only entries explicitly pointing at the configured P2K club and queues authoritative match/board work without writing points or results.
- `player/{username}/stats`: for a current member, queues an authoritative server-side stats refresh which stores the Daily/Chess960 rating snapshot.
- `match/{id}`: treats the ID as a hint and queues the normal authoritative match fetch.
- player monthly archive URLs: queue the normal authoritative archive task.
- profile responses are accepted as observations but do not write arbitrary profile fields.

## Client behavior
The shared Chess.com API client detects useful responses, deduplicates each URL per page load, batches up to four observations, and POSTs them without awaiting the result. Payloads below ~60 KiB use `keepalive`; larger payloads use an ordinary non-blocking POST.

## Trust boundary
No client-computed points, match results, probabilities, ranks, or achievement facts are accepted. Browser observations only identify what should be checked; canonical match/member/rating/point facts are written by the existing authoritative server worker after its own Chess.com request.

## Database
No schema change. This is a next-release baseline integration change.
