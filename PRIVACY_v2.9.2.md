# Promote to King v2.9.2 — privacy notes

v2.9.2 introduces no new third-party analytics, tracking service, cookie, advertising identifier or cross-site data flow.

The existing first-party Traffic diagnostics continue to respect Do Not Track and Global Privacy Control. ACAMR keeps raw browser/session identifiers same-origin and stores protected hashes in telemetry rather than raw IDs.

The new Opponent Balance rating-provenance field contains only an aggregate count of paired rated boards for a public Chess.com team match; it does not add personal profile data.
