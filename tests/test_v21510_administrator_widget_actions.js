"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const file = fs.readFileSync("assets/js/widgets/p2k-administrator-v21510.js","utf8");
const parent = fs.readFileSync("assets/js/admin/admin-session-controller.js","utf8");
const php = fs.readFileSync("server/widgets/public/administrator.php","utf8");
function section(from,to){
 const a=file.indexOf(from),b=file.indexOf(to,a+from.length);
 assert(a>=0&&b>a,"Source section missing: "+from+" / "+to);
 return file.slice(a,b);
}
const greenQueue=new Function(section("function greenQueue(","function fallbackHealth(")+"return greenQueue;")();
const fallbackHealth=new Function(section("function fallbackHealth(","async function loadActions(")+"return fallbackHealth;")();
const eventLabel=new Function(
 section("function readableReason(","function closeMember(")+"return eventLabel;")();
const now=Math.floor(Date.now()/1000);
const catalog={generatedAt:new Date().toISOString(),matches:[
 {matchId:"11",name:"TCMAC U1800",url:"https://www.chess.com/club/matches/11",startTime:now+3600,isLeague:true,category:"league"},
 {matchId:"12",name:"Friendly",url:"https://www.chess.com/club/matches/12",startTime:now+7200,isLeague:false,category:"friendly"},
 {matchId:"13",name:"Incomplete",url:"https://www.chess.com/club/matches/13",startTime:now+10800,isLeague:true,category:"league"}],
 details:[
 {matchId:11,minimumPlayers:5,maximumPlayers:10,minRating:0,maxRating:1800,oursCount:3,opponentCount:7,oursRatings:[1200,1300,1400],rosterComplete:true,verified:true,locked:false},
 {matchId:12,minimumPlayers:0,maximumPlayers:null,oursCount:7,opponentCount:5,oursRatings:[1200,1300,1300,1200,1100,1000,1200],rosterComplete:true,verified:true,locked:false},
 {matchId:13,minimumPlayers:5,oursCount:0,opponentCount:0,oursRatings:[],rosterComplete:false,verified:true,locked:false}]
};
const feed=greenQueue(catalog);
assert.equal(feed.metrics.underfilled,1);
assert.equal(feed.metrics.starts48,3);
assert.equal(feed.metrics.leagueRecruitment,1);
assert.equal(feed.metrics.recruitsAdvised,4);
assert.equal(feed.queue.some(r=>r.title==="Incomplete"&&r.belowMinimumWarning),false,"Incomplete roster must not be a deficit");
assert.equal(feed.queue[0].title,"TCMAC U1800");
const health=fallbackHealth({data:{green:{targets:{club_index_seconds:300,roster_seconds:600},
 matches:{index_age_seconds:100},roster:{age_seconds:1250}}}}, {healthy:true,state:{cycle_no:12,mode:"quick",stage:"ready"}});
assert.equal(health[0].tone,"good");
assert.equal(health[1].tone,"warn");
assert.equal(health[2].tone,"good");
const delayed=fallbackHealth({data:{green:{matches:{index_age_seconds:3800},roster:{age_seconds:100}}}},null);
assert.equal(delayed[0].tone,"bad");
assert.match(eventLabel({event_type:"name_changed",previous_username:"OldName",new_username:"NewName"}),/OldName → NewName/);
assert.match(eventLabel({event_type:"left",profile_status:"closed",metadata:{profile_closure_reason:"fair_play_violation"}}),/Fair play violation/);
assert.match(eventLabel({event_type:"left",profile_status:"active"}),/account active/);
assert.match(eventLabel({event_type:"name_changed",previous_username:"A",new_username:"B",inferred:true}),/inferred/);
assert(!file.includes("scope=actions"),"Legacy stale action source reintroduced");
assert(file.includes("Dashboard Match Assistant")&&file.includes("Green synchronized fallback"));
assert(parent.includes('p2k-administrator-widget-queue-request')&&parent.includes('event.origin !== location.origin'));
assert(php.includes("Auth::requireAdmin()")&&php.includes("p2k_g_match_players"));
assert(file.includes("Chess.com account joined")&&file.includes("First joined P2K"));
assert(file.includes("awMemberResult")&&file.includes("closeMember"));
console.log("PASS Administrator action queue categorization, incomplete rosters, Green freshness, lifecycle closure and same-origin bridge");
