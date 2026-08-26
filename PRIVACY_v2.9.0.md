# Promote to King v2.9.0 — Traffic analytics privacy design

Promote to King v2.9.0 uses optional **first-party, self-hosted, cookieless traffic analytics**. It does not set analytics cookies, localStorage/sessionStorage identifiers, advertising IDs, or cross-site identifiers, and it does not use Statcounter, Google Analytics, or another third-party audience tracker.

The server may transiently process the request IP address to derive a country (or broad region when a trusted local GeoIP source is configured). The raw IP is not written to analytics files. Full external referrer URLs and query strings are not retained: only normalized internal paths and external source hostnames are stored.

A short-lived rotating HMAC pseudonym may be derived server-side from the transient network address only to estimate a visit/session and daily unique visitors. Browser/User-Agent entropy is deliberately excluded from the pseudonym to avoid fingerprinting; shared/NAT addresses can therefore undercount uniques. That pseudonym is retained for no more than 24 hours and is not linked to Chess.com usernames, authenticated identities, achievements, recruitment, ACAMR, or member intelligence. Daily aggregate files are compacted to remove pseudonymous visitor/session keys. Because pseudonyms rotate daily, multi-day reports deliberately show latest-day uniques, average daily uniques, and unique visitor-days rather than claiming a period-wide de-duplicated unique visitor count.

Approximate duration is calculated from first/last activity and cookieless page lifecycle signals. Do Not Track (`DNT: 1`) and Global Privacy Control (`Sec-GPC: 1`) are honored as opt-out signals, and obvious bots/crawlers are excluded.

This design minimizes personal data but does not claim that transient collection is legally anonymous. The site operator remains responsible for the applicable privacy notice, legal basis, retention policy, and data-subject rights under applicable EU/Luxembourg law.
