"use strict";
const fs=require("node:fs");
const assert=require("node:assert/strict");
const vm=require("node:vm");
const {spawnSync}=require("node:child_process");

const card=fs.readFileSync("server/events-showcase/public/embed-card.php","utf8");
const core=fs.readFileSync("assets/js/shared/events-showcase-core.js","utf8");
const line=(s,prefix)=>{
  const found=s.split("\n").filter(x=>x.trimStart().startsWith(prefix));
  assert.equal(found.length,1,"One declaration expected: "+prefix);
  return found[0].trim();
};
const dailySource=line(card,"function formatCardDailyDate(epoch)");
const arenaSource=line(card,"function formatArenaStart(start)");
const code=[
  line(core,"function formatStart(epoch)"),dailySource,arenaSource,
  line(card,"function matchCard(match)"),
  line(card,"function arenaCard(arena,timing,now)")
].join("\n");
assert(!dailySource.includes("timeZone:")&&!dailySource.includes("timeZoneName"),
  "Visible Daily date is browser-local without a timezone suffix");
assert(!arenaSource.includes("timeZone:")&&!arenaSource.includes("timeZoneName"),
  "Visible arena date/time is browser-local without a timezone suffix");
assert(card.includes("start=formatCardDailyDate(match.startTime)"));
assert(card.includes("fullStart=CORE.formatStart(timing.start/1000)"));
assert(card.includes('data-arena-live title="'+"$"+"{esc(fullStart)}"+'">'));
assert(card.includes('title="'+"$"+"{esc(urgency.title)}"+'">'));
assert(card.includes('timing.phase==="ongoing"?formatCountdown(timing.end-now)'));
assert(card.includes('timing.phase==="registration"?formatStartsIn(timing.start-now)'));

if(process.argv[2]==="--child"){
  const ctx=vm.createContext({
    Date,Intl,Number,Math,String,
    esc:x=>String(x??""),
    initials:()=>"EX",needClass:()=>"pc-gold",
    parseArenaControl:()=>({kind:"blitz",label:"Blitz"}),
    speedIcon:()=>"<svg></svg>",
    formatCountdown:()=>"1h 00m",
    formatStartsIn:()=>"Starts in 10 mn"
  });
  vm.runInContext(code,ctx,{timeout:3000});
  const full=epoch=>vm.runInContext("formatStart("+Number(epoch)+")",ctx);
  ctx.CORE={
    formatStart:full,
    startUrgency:epoch=>({title:full(epoch),level:""}),
    timePerMoveCompactLabel:()=>"3d/m",
    timePerMoveLabel:()=>"3 days/move"
  };
  const dates=[
    "2026-11-17T18:00:00Z","2026-11-17T23:30:00Z",
    "2026-03-29T00:30:00Z","2026-03-29T01:30:00Z"
  ];
  const rows=dates.map(iso=>{
    const ms=Date.parse(iso),epoch=ms/1000;
    const shortDaily=vm.runInContext("formatCardDailyDate("+epoch+")",ctx);
    const shortArena=vm.runInContext("formatArenaStart("+ms+")",ctx);
    const expanded=full(epoch);
    const match=vm.runInContext("matchCard("+JSON.stringify({
      startTime:epoch,recruitment:{count:5},category:"league",
      opponentName:"Example Opponent",opponentSlug:"example-opponent",
      name:"Test",url:"https://www.chess.com/club/matches/example/12345",
      timePerMove:259200,ratingLabel:"U1800",urgent:false
    })+")",ctx);
    const arena=vm.runInContext("arenaCard("+JSON.stringify({
      name:"Example Arena",link:"https://www.chess.com/play/arena/31100001",
      timeControl:"3+2",duration:"2h"
    })+","+JSON.stringify({start:ms,end:ms+7200000,phase:"upcoming"})+","+(ms-36000000)+")",ctx);
    const dm=match.match(/<span class="pc-match-start [^"]+" title="[^"]+">([^<]+)<\/span>/);
    const ar=arena.match(/<div class="pc-arena-start-line upcoming" data-arena-live title="[^"]+">([^<]+)<\/div>/);
    assert(dm,"Rendered Daily date missing");
    assert(ar,"Rendered arena start missing");
    assert.equal(dm[1],shortDaily);
    assert.equal(ar[1],shortArena);
    assert.match(expanded,/2026/,"Expanded date includes year");
    assert(match.includes(expanded),"Full Daily tooltip shows browser local date/time/zone");
    assert(arena.includes(expanded),"Full arena tooltip shows browser local date/time/zone");
    assert(!/(?:GMT[+-]\d|CET|CEST|EST|EDT|JST)/.test(shortDaily));
    assert(!/(?:GMT[+-]\d|CET|CEST|EST|EDT|JST)/.test(shortArena));
    return{iso,shortDaily,shortArena,expanded};
  });
  process.stdout.write(JSON.stringify(rows));
}else{
  const checks={
    "Europe/Luxembourg":[
      ["17 Nov","17 Nov · 19:00"],["18 Nov","18 Nov · 00:30"],
      ["29 Mar","29 Mar · 01:30"],["29 Mar","29 Mar · 03:30"]
    ],
    "America/New_York":[
      ["17 Nov","17 Nov · 13:00"],["17 Nov","17 Nov · 18:30"],
      ["28 Mar","28 Mar · 20:30"],["28 Mar","28 Mar · 21:30"]
    ],
    "Asia/Tokyo":[
      ["18 Nov","18 Nov · 03:00"],["18 Nov","18 Nov · 08:30"],
      ["29 Mar","29 Mar · 09:30"],["29 Mar","29 Mar · 10:30"]
    ]
  };
  for(const [zone,expected] of Object.entries(checks)){
    const r=spawnSync(process.execPath,[__filename,"--child"],{
      cwd:process.cwd(),encoding:"utf8",env:{...process.env,TZ:zone},timeout:15000
    });
    assert.equal(r.status,0,zone+": "+r.stderr);
    const data=JSON.parse(r.stdout);
    assert.equal(data.length,expected.length);
    data.forEach((x,i)=>{
      assert.equal(x.shortDaily,expected[i][0],zone+" Daily "+x.iso);
      assert.equal(x.shortArena,expected[i][1],zone+" arena "+x.iso);
      assert.match(x.expanded,/(GMT|EST|EDT|UTC|JST|CET|CEST)/,"Full tooltip should include timezone");
    });
    console.log("PASS "+zone+": "+data.map(x=>x.shortArena).join("; "));
  }
  console.log("PASS card-only compact browser-local dates with expanded timezone tooltips, DST and midnight rollovers");
}
