(() => {
"use strict";
/* v2.12.1 compatibility shim.
 * The old r5fix3.8 JS layer scraped rendered cards for KPIs and fetched the full
 * admin catalogue. Public/admin state is now owned by the v2.12.1 modules.
 * Keep the marker so older optional layers do not attempt to recreate it.
 */
window.__P2K_TROPHY_R5FIX3_8=true;
})();
