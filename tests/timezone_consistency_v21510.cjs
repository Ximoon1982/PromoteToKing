"use strict";
const fs=require("node:fs");
const assert=require("node:assert/strict");
const get=p=>fs.readFileSync(p,"utf8");
const tz=Intl.DateTimeFormat().resolvedOptions().timeZone;
const ops=get("assets/js/widgets/p2k-operations-v21510.js");
const start=ops.indexOf("  const absoluteDate=");
const end=ops.indexOf("  const text=(id,s)=>",start);
assert(start>=0&&end>start,"public widget local-date helpers present");
const {absoluteDate,localSchedule,when,dateKey}=new Function(ops.slice(start,end)+
    "\nreturn {absoluteDate,localSchedule,when,dateKey};")();
assert.equal(absoluteDate("2026-11-16 12:00:00").toISOString(),"2026-11-16T12:00:00.000Z");
assert.equal(absoluteDate("2026-11-16T12:00:00").toISOString(),"2026-11-16T12:00:00.000Z");
assert.equal(absoluteDate("2026-11-16T12:00:00Z").toISOString(),"2026-11-16T12:00:00.000Z");
assert.equal(absoluteDate(1794830400).toISOString(),"2026-11-16T12:00:00.000Z");
const shown=when("2026-11-16 12:00:00");
const hourByZone={"America/Los_Angeles":"04:00","Europe/Luxembourg":"13:00","Asia/Tokyo":"21:00"};
if(hourByZone[tz])assert(shown.includes(hourByZone[tz]),tz+": "+shown);
const now=new Date("2026-11-16T22:00:00.000Z");
const ts=Math.floor(new Date("2026-11-17T00:30:00.000Z").getTime()/1000);
const buckets=[{date:"2026-11-17",items:[{matchId:2019768,startTime:ts,boards:99}]}];
const days=localSchedule(buckets,now);
assert.equal(days.length,7);
const localDay=dateKey(new Date(ts*1000));
const matching=days.find(d=>d.date===localDay);
if(matching){assert.equal(matching.matches,1);assert.equal(matching.boards,99);assert.equal(matching.items[0].matchId,2019768);}
const expectedByZone={"America/Los_Angeles":"2026-11-16","Europe/Luxembourg":"2026-11-17","Asia/Tokyo":"2026-11-17"};
if(expectedByZone[tz])assert.equal(localDay,expectedByZone[tz]);
assert(!ops.includes("timeZone:'Europe/Luxembourg'"),"old forced Luxembourg visitor formatting removed");
assert(get("server/widgets/public/operations-api.php").includes("week-utc-buffer-v2|"));
assert(get("server/widgets/public/operations-api.php").includes("day_grouping'=>'client_local_from_utc_buffer"));
assert(!get("server/widgets/public/operations-api.php").includes("'timezone'=>'Europe/Luxembourg'"));
const radar=get("assets/js/widgets/p2k-recruitment-radar-v2159.js");
assert(radar.includes("timeZoneName:'short'"));
assert(!radar.includes("timeZone:'UTC'"),"Radar uses visitor-local match date");
const showcase=get("assets/js/events-showcase-line-v2122.js");
assert(showcase.includes("getFullYear()")&&!showcase.includes("getUTCFullYear()"),"arena Today/Tomorrow uses local day");
const history=get("assets/js/shared/match-history-ui.js");
assert(history.includes('timeZoneName:"short"')&&!history.includes('timeZone: "UTC"'),"tracked history displays locally");
const find=get("assets/js/pages/find-match.js");
assert(!find.includes('timeZone: "UTC"'),"match start local in Match Assistant");
const analyzed=get("assets/js/pages/analyze-match.js");
assert(!analyzed.includes('timeZone: "UTC"'),"full Analyzer match start local");
const upcoming=get("assets/js/shared/upcoming-analysis-core.js");
assert(upcoming.includes('resolvedOptions().timeZone'),"recruitment messages explicitly label local timezone");
const admin=get("assets/js/admin/events-showcase-v2122.js");
assert(admin.includes("Start time (Luxembourg)")&&admin.includes("Date (Luxembourg)"),
    "scheduled arena editor explains fixed input timezone");
assert(get("assets/js/pages/admin-features.js").includes('timeZone: "UTC"'),"admin logs stay UTC");
assert(get("server/widgets/src/HallOfFameLeaderboardService.php").includes("UTC"),
    "monthly and quarterly periods retain UTC");
assert(get("assets/js/dashboard/dashboard-v2.js").includes('timeZone: "UTC"'),
    "dashboard's statistical/date-only convention remains UTC");
for(const path of ["assets/js/pages/match-creation-analyzer.js","assets/js/pages/match-creation-charts.js"]){
 const code=get(path);
 assert(code.includes("function localToday()")&&!code.includes("function utcToday()"),"match creation calendar is local: "+path);
 assert(code.includes("date.setDate(")&&!code.includes("date.setUTCDate("), "DST-safe calendar arithmetic: "+path);
}
for(const path of ["assets/js/dashboard/match-assistant.js","assets/js/dashboard/match-list-dialog.js"])
 assert(get(path).includes('timeZoneName:"short"'),"local match time in dashboard: "+path);
const core=get("assets/js/shared/events-showcase-core.js");
const a=core.indexOf("function formatStart("),b=core.indexOf("function timeControlSeconds",a);
assert(a>=0&&b>a);
const {formatStart,formatStartCompact}=new Function(core.slice(a,b)+
    "\nreturn {formatStart,formatStartCompact};")();
const arenaTime=formatStart(ts);
assert(arenaTime&&!arenaTime.includes("Start unavailable"));
assert(formatStartCompact(ts)!=="—");
console.log("PASS timezone "+tz+": UTC SQL parsing, local histogram grouping, widgets/public/admin presentation and DST-aware dates");
