"use strict";
const assert=require("node:assert/strict");
const fs=require("node:fs");
const vm=require("node:vm");
const php=fs.readFileSync("server/widgets/public/administrator.php","utf8");
const embed=fs.readFileSync("server/widgets/public/embed.php","utf8");
const api=fs.readFileSync("server/widgets/public/operations-api.php","utf8");
const js=fs.readFileSync("assets/js/widgets/p2k-operations-v21510.js","utf8");
const css=fs.readFileSync("assets/css/widgets/p2k-operations-v21510.css","utf8");
new Function(js);
const tabs=[...php.matchAll(/data-aw-tab="([^"]+)"/g)].map(x=>x[1]);
assert.deepEqual(tabs,["actions","matches","members","analysis"]);
assert(!php.includes('data-aw-tab="showcase"'));
assert(!php.includes('id="awScan"'));
assert(!php.includes('id="awArenaForm"'));
assert(php.indexOf('id="awMemberName"')<php.indexOf('Member chronology'));
assert(php.includes("frame-ancestors 'self' https://www.chess.com https://chess.com"));
assert(!php.includes("X-Frame-Options: SAMEORIGIN"));
assert(php.includes("Auth::requireAdmin()") && php.includes("if($preview)"),"previews must remain protected");
assert(php.includes("$store->get('administrator')"),"public access must respect publication");
assert(!embed.includes("exit('Administrator authentication required')"));
assert(!js.includes("getWriteClient") && !js.includes("arena-scan") && !js.includes("admin-api.php"),"no privileged controls in public widget");
assert(js.includes("match-search")&&js.includes("match-data")&&js.includes("match-history"));
assert(js.includes("renderRatings")&&js.includes("renderHistory")&&js.includes("C.compareRecruitmentLineups")&&js.includes("C.recruitmentMetrics"));
assert(js.includes("includeRecruitment?")&&js.includes("modelFor(match,x.p2kRatings"),"history must avoid repeated recruitment optimizer");
assert(css.includes("width:100%")&&css.includes("max-width:100%"));
for(const permission of ["GreenConfig::authorizeAdmin","Auth::requireAdmin","require_admin_write"]){
  assert(!api.includes(permission),"protected authorization must not be weakened for public read");
}
assert(api.includes("ops_match_query('match_id=?'")&&api.includes("verified_club_slug=?"));
assert(api.includes('AND (".$where.")'),"SQL name lookup must be club-scoped even when opponent name matches");
assert(api.includes("array_intersect_key")&&api.includes("ops_event"),"public member read must be projected");
assert(!api.includes("profile_closure_reason")&&!api.includes("'closure_reason'"));
assert(api.includes("in_array($action,")&&api.includes("'match-history'"));
for(const path of ["server/team-points-green/public/api.php","server/events-showcase/public/admin-api.php"]){
  const guarded=fs.readFileSync(path,"utf8");
  assert(/authorizeAdmin\(\)|requireAdmin\(\)/.test(guarded),"protected admin route still guarded: "+path);
}
// Shared model must retain exact Elo expected-score and 4-half-point per board algorithm.
const shared=fs.readFileSync("assets/js/shared/recruitment-lineup-core.js","utf8");
const ctx={window:{},Math,Number,Object,Array};vm.createContext(ctx);vm.runInContext(shared,ctx);
const model=ctx.window.P2K_RECRUITMENT_LINEUP_CORE;
assert.equal(model.eloExpectedScore(1500,1500),0.5);
const equal=model.compareRecruitmentLineups([1500,1500],[1500,1500],2);
assert(Math.abs(equal.p2kWinProbability-equal.opponentWinProbability)<1e-12);
assert.equal(equal.boardCount,2);
const match={settings:{min_rating:0,max_rating:1800,min_team_players:4,max_team_players:8}};
const teams=[{players:[{rating:1500},{rating:1300},{rating:1100}]},
             {players:[{rating:1400},{rating:1200},{rating:1100},{rating:1200}]}];
const rec=model.recruitmentMetrics({match,p2kTeam:teams[0],opponentTeam:teams[1],isLeague:true});
assert(rec && rec.count>=1 && rec.baseline>=1,"existing recruitment helper must cover deficit");
console.log("PASS anonymous four-tab UI, scoped Green reads, protected-admin isolation, compact model parity and charts");
