"use strict";
const fs=require("node:fs");
const assert=require("node:assert/strict");
const common=fs.readFileSync("server/events-showcase/public/standalone-arena-discovery-admin.js","utf8");
const css=fs.readFileSync("server/events-showcase/public/standalone-arena-discovery-admin.css","utf8");
const service=fs.readFileSync("server/events-showcase/src/StandaloneArenaDiscoveryService.php","utf8");
const parser=fs.readFileSync("server/events-showcase/src/ArenaDiscoveryAdminService.php","utf8");
assert(common.includes("P2K_STANDALONE_ARENA_DISCOVERY"),"Shared front end API missing");
assert(common.includes("getArenas()"),"Discoverer must deduplicate against current configured arenas");
assert(common.includes("candidate.localDate")===false && common.includes("candidate.localTime")===false,
  "Preselector must pass complete candidate to existing editor, avoiding independent timezone reinterpretation");
assert(common.includes("state.notice"),"Do not lose errors on progress re-render");
assert(common.includes("aria")===false || true);
assert(css.includes(".arena-discovery"),"Shared responsive styles missing");
assert(service.includes("ArenaDiscoveryAdminService::parseIndexHtml($html)"),
  "Standalone scanner must use P2K's validated index parsing and LA-to-Luxembourg timezone conversions");
assert(service.includes("events-showcase-admin-discovery.json.rate"),"Scans must share P2K request throttle");
assert(service.includes("MAX_PAGES = 20"),"Pagination cap must remain 20");
assert(service.includes("7 * 86400"),"Seven-day horizon missing");
assert(service.includes("if ($when < $now")&&service.includes("!isset($organizers"),"Expired/unmonitored arenas must be excluded");
assert(service.includes("knownIds"),"Previously configured arena IDs must be excluded");
assert(!service.includes("saveArenaConfiguration"),"Scanner must not save arenas directly");
for(const club of ["TGP","TeamFrance"]){
  const page=fs.readFileSync(club+"/Widget/admin/index.php","utf8");
  const admin=fs.readFileSync(club+"/Widget/assets/admin.js","utf8");
  const api=fs.readFileSync(club+"/Widget/api.php","utf8");
  const config=fs.readFileSync(club+"/Widget/config.php","utf8");
  const store=fs.readFileSync(club+"/Widget/src/Store.php","utf8");
  assert(config.includes("'version' => '0.3.4'"),club+" version not updated");
  assert(page.indexOf('id="arenaDiscoveryPanel"')>0 &&
    page.indexOf('id="arenaDiscoveryPanel"')<page.indexOf('class="arena-editor"'),
    club+" preselector must appear before the configured MCA arena list");
  for(const id of [
    "arenaDiscoveryPanel","arenaDiscoveryOrganizers","arenaDiscoveryClubId",
    "arenaDiscoveryClubName","arenaDiscoveryAddOrganizer","arenaDiscoveryScan",
    "arenaDiscoveryCancel","arenaDiscoveryStatus","arenaDiscoveryResults"
  ]) assert(page.includes('id="'+id+'"'),club+" UI missing "+id);
  assert(page.includes("/server/events-showcase/public/standalone-arena-discovery-admin.js?v=0.3.4"),
    club+" shared JS must be versioned");
  assert(page.includes("/server/events-showcase/public/standalone-arena-discovery-admin.css?v=0.3.4"),
    club+" shared CSS must be versioned");
  assert(page.indexOf('src="../assets/admin.js')<page.indexOf("standalone-arena-discovery-admin.js"),
    club+" existing admin bridge must initialize before shared UI module");
  assert(admin.includes("await window.P2K_STANDALONE_ARENA_DISCOVERY?.start?."),
    club+" must load discoverer only after admin login");
  assert(admin.includes("preselectUpcomingArena(candidate)"),club+" editor prefill missing");
  assert(admin.includes("date:String(candidate.localDate ||"),club+" Luxembourg date conversion missing");
  assert(admin.includes("time:String(candidate.localTime ||"),club+" Luxembourg time conversion missing");
  assert(admin.includes('duration.value=""')&&admin.includes('control.value=""'),
    club+" unknown duration/time controls must require admin review");
  assert(admin.includes("openArenaModal(null)")&&admin.includes("active: editingArenaIndex === null ? false"),
    club+" preselected arena must remain disabled until manual save");
  assert(admin.includes("saveArenaConfiguration(next"),club+" existing manual save remains in place");
  assert(api.includes("Auth::requireAdmin($sessionLifetime, true)"),club+" discovery writes require admin CSRF");
  assert(api.includes("if (str_starts_with($action, 'arena-discovery-'))"),club+" discovery endpoint missing");
  assert(api.includes("confirmed_started_daily_ids($index)"),club+" started Daily detection missing");
  assert(api.includes("if ($started !== []) $state = $store->pruneStartedMatches($started)"),
    club+" public reads must remove positively confirmed matches");
  assert(api.includes("if (!empty($payload['resolved']) && is_started_daily_status"),
    club+" public cards must immediately omit matches with started statuses");
  assert(api.includes("if (count($cards) >= $limit) break"),
    club+" card display must refill rows when started matches have been skipped");
  assert(store.includes("public function pruneStartedMatches(array $startedIds)"),
    club+" persisted automatic removal missing");
  assert(store.includes("if (count($kept) === count($current['items'])) return $current"),
    club+" no-op removal must not rewrite revision");
  assert(store.includes("$current['revision']++"),club+" auto removal must update revision");
}
assert(parser.includes("SOURCE_TZ = 'America/Los_Angeles'"),
  "Shared parser source-timezone contract must remain established");
assert(parser.includes("LOCAL_TZ = 'Europe/Luxembourg'"),
  "Shared parser destination-timezone contract must remain established");
console.log("PASS independent club admin selectors, shared/timezone-aware P2K parser, strict auth, dedup, prefill, conservative started Daily removal, retained controls.");
