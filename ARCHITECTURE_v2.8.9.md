# Promote to King v2.8.9 architecture

## Startup ownership rule

Anything executed by `initialize()` / `renderView()` before lazy features load must be owned by the main Dashboard controller or derived from the live DOM. Startup code must never depend on a symbol defined only by a Hall/Insights/Admin lazy module.

Integrated iframe activity therefore uses DOM discovery (`iframe.dashboard-integrated-frame[id]`) rather than a manually synchronized cross-module ID array.

## Dashboard contract

v2.8.9 intentionally retains the proven v2.8.6 Dashboard data-loading contract. The first Team Points request and its required constants live in the main controller. Automatic Chess.com/Live enrichment remains post-paint and cannot be required for authentication.

## Authentication contract

Session application and configured/local admin recognition are independent from Dashboard data success. Configured administrators are authorized immediately; remote Chess.com club-profile verification is only a secondary route for users not in the local/configured set.

Embedded admin pages accept parent authorization and poll briefly to survive message-order races.

## Lazy-feature contract

Hall and integrated Insights remain lazy, but their factories receive an explicit context object. A failure to load a lazy module may fail that feature locally; it must not prevent Dashboard startup or authentication. Lazy-load promises reset after failure so a retry can succeed.

## ACAMR contract

ACAMR is opportunistic and isolated from Dashboard startup. It begins only after an authenticated session is available and the ACAMR client is loaded. Server workers and CRON remain authoritative and fully sufficient without connected browsers.

ACAMR may assist Club/Member Points, relevant member archives/boards, ratings and roster freshness. It may not monitor tournaments or match registration. Browser payloads remain hints/observations and do not write canonical point facts.

## Storage / operations

Core schema 6 and Analytics schema 5 are retained. No additional CRON task is introduced.
