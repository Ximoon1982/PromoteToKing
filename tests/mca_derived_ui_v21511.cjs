"use strict";
const assert=require("node:assert/strict");
const fs=require("node:fs");
const html=fs.readFileSync("TeamPointsAdmin.html","utf8");
const features=fs.readFileSync("assets/js/pages/team-points-features.js","utf8");
const miac=fs.readFileSync("assets/js/pages/mca-rename-review-v2157.js","utf8");
const service=fs.readFileSync("server/team-points/src/McaRenameReviewService.php","utf8");
const live=fs.readFileSync("server/team-points/src/LiveRanksService.php","utf8");
const cron=fs.readFileSync("server/team-points/src/McaResultsCronService.php","utf8");
for(const oldId of ["liveRanksIntegrityMetrics","liveRanksIntegrityStatus","liveRanksDuplicateWrap","liveRanksDuplicateRows"]){
  assert(!html.includes(oldId),oldId+" must be removed from the routine MCA admin page");
  assert(!features.includes(oldId),oldId+" must not be rendered");
}
assert(!html.includes("Excluded legacy copies"),"Legacy duplicate table should be hidden");
assert(!features.includes("legacy duplicate CSV record(s) are retained"),"Legacy duplicate audit message should be hidden");
assert(html.includes('id="liveRanksFileRows"'),"Canonical sources list must be retained");
assert(html.includes('id="liveRanksDerivedStatus"'),"Computed players must have an explicit freshness status");
assert(features.includes("Showing the last successfully published"),"Stale snapshot must be labelled");
assert(features.includes("Automatic rebuild is pending"),"Missing derived data must not appear as an authoritative zero");
assert(html.includes("assets/js/pages/team-points-features.js?v=2.15.11-mca-derived-safe-1"),"Feature JS cache token not updated");
assert(html.includes("assets/js/pages/mca-rename-review-v2157.js?v=2.15.11-mca-derived-safe-1"),"MIAC JS cache token not updated");
assert(service.includes("'rebuild_pending' => $pendingRebuild")&&service.includes("'published_players' => $publishedPlayers"),"MIAC report must expose freshness");
assert(miac.includes("mira.rebuild_pending"),"MIAC admin must distinguish pending rebuild");
assert(miac.includes("zero rename candidates is not a verified result"),"Empty MIAC queue must not imply verified absence");
assert(live.includes("'derived_rebuild' => ["),"Computed-player status must expose derived freshness");
assert(cron.includes("private function invalidateDerivedMca(): void")&&cron.includes("private function runPendingDerivedRebuild(float $deadline): void"),
       "Non-destructive and automatic derived processing must be present");
assert(cron.includes("private function storeAutomaticCsv"),"Source acquisition must remain unchanged");
assert(cron.includes("private function sourceCatalogue()"),"Duplicate detection and canonical selection are mandatory");
assert(live.includes("public function sourceIntegrity()"),"Duplicate audit and canonical-source detection must remain in backend");
assert(html.includes('id="liveRanksProcess"'),"Manual complete MCA processing must remain accessible");
console.log("PASS duplicate UI hidden; canonical source auditing retained; MCA player/MIAC freshness explicit and cache versioned");
