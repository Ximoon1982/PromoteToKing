# Promote to King v2.9.14 — Active Convergence & Self-Refresh Pack audit

## Scope

v2.9.14 promotes the prepared Active Convergence & Self-Refresh Pack (ACSR) and adds the release-blocking P0 **interactive survival** correction. ACSR accelerates stale visible views, ACAMR work and explicitly enabled Continuous Refresh, while the server-owned canonical queue and external CRON remain authoritative for canonical facts.

## P0 interactive survival

- User-initiated Chess.com requests are foreground. Automatic ACSR/ACAMR/Continuous Refresh/cache refresh acquisition is background.
- OAuth gateway has 6 POST lanes; background can occupy at most 5. One POST lane is permanently reserved for foreground work.
- When foreground work is queued or an interactive gateway POST is active, no new background gateway wave is admitted.
- The server-shared OAuth coordinator announces foreground demand and background launch reservations yield for the foreground hold window.
- Diagnostics expose Interactive protection, foreground/background gateway queues, reserved foreground lane, current/max foreground queue wait, and background suppression count.
- Saturation browser gate deliberately fills all five background-admissible POST lanes and proves a foreground request enters the sixth reserved lane within the 250 ms queue-delay target.

## OAuth startup and learning

Fresh v2.9.14 real-OAuth controller state starts at **30 calls/s** and browser gateway connection cap **3**. The server rate coordinator and browser cap learner immediately continue their normal adaptation from those seed values. Existing v2.9.13 tuning is intentionally invalidated by a new browser tuning key and server coordinator state version/hash namespace.

## Authority boundaries

ACSR does not turn public pages into admin worker endpoints. Continuous Refresh may issue bounded authoritative Club/Member worker pulses only through the existing authorized control path. Canonical queue coalescing remains the duplicate-work authority. External CRON remains the fallback and authoritative cadence.
