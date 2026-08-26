# v2.9.22.2 OAuth saturation audit

The production symptom was an OAuth launch target near 26.5 calls/s while Fresh Points Reconstruction completed only about 2.2 fetches/s, with gateway target 2, one active POST and no queued work.

Root causes:

1. `OAuthSession::runtimeOpenFileCap(count($requests))` returned the smaller of host capacity and current batch size. A two-request batch therefore advertised `transport_cap=2`.
2. The browser treated that transient value as persistent transport capacity.
3. `processPriority()` could snapshot that collapsed capacity as its logical worker count for a large reconstruction phase.
4. Reconstruction persistence still produced many small same-origin writes, adding avoidable downstream traffic.

v2.9.22.2 separates physical transport capacity from batch size, keeps a 256-request logical reconstruction feeder, uses the shared rate coordinator for actual launch pacing, reserves P0 foreground capacity, and coalesces staging persistence.

Focused browser saturation validation deliberately begins with a legacy two-request gateway response. Capacity remains 256. A subsequent 256-match background batch reaches a gateway target of 32 with five simultaneous background gateway POSTs and zero page errors.
