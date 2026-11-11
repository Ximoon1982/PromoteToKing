const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const now = Date.now();
function arena(hours, name, timeControl) {
  const start = now + hours*3600000;
  return {
    active: true, name, timeControl, duration: "90m",
    durationMinutes:90,
    link:"https://www.chess.com/tournament/live/arena/example",
    startAt:new Date(start).toISOString(),endAt:new Date(start+90*60000).toISOString(),
  };
}
async function fixture(club, arenas, cards=[], layout={arenaRows:1,dailyRows:2}) {
  const grid={innerHTML:"",replaceChildren(){this.innerHTML="";}};
  const arenaGrid={innerHTML:"",querySelectorAll(){return [];}};
  const section={hidden:true};
  const elements={tgpGrid:grid,arenaShowcase:arenaGrid,arenaSection:section};
  const timers=[];
  const sandbox={
    document:{getElementById:id=>elements[id]||null},
    window:{setInterval:(callback,period)=>timers.push({callback,period})},
    fetch:async()=>({ok:true,json:async()=>({ok:true,cards,arenas,layout})}),
    Date, Intl, Math, Number, String, console,
  };
  const file=path.join(__dirname,"..",club,"Widget","assets","widget.js");
  vm.runInNewContext(fs.readFileSync(file,"utf8"),sandbox,{filename:file});
  await new Promise(resolve=>setImmediate(resolve));
  return {grid,arenaGrid,section,timers};
}
(async()=>{
  for (const club of ["TGP","TeamFrance"]) {
    const html=fs.readFileSync(path.join(__dirname,"..",club,"Widget","index.php"),"utf8");
    assert.match(html,/id="arenaSection"/);
    assert.match(html,/id="dailySection"/);
    if (club === "TeamFrance") {
      assert.match(html,/Matchs en différé/);
      assert.match(html,/Arènes multiclubs/);
    } else {
      assert.match(html,/Join daily matches/);
      assert.match(html,/Join multi-club arenas/);
    }
    const match={matchId:"56789",category:"league",leagueAcronyms:["WCGC"],opponentName:"Opposition",name:"WCGC event",url:"https://www.chess.com/club/matches/56789",startTime:now/1000+86400,time_control:86400};
    const a=await fixture(club,[arena(0.5,"Bullet open","1+0"),arena(3,"Blitz open","5+3"),arena(5,"Rapid open","15+10")],[match]);
    assert.equal(a.section.hidden,false,club+" arena visible");
    assert.equal((a.arenaGrid.innerHTML.match(/class="tgp-card arena-card"/g)||[]).length,2,club+" max 2");
    assert.match(a.arenaGrid.innerHTML,/tgp-speed-icon bullet/,club+" bullet icon");
    assert.match(a.arenaGrid.innerHTML,/tgp-speed-icon blitz/,club+" blitz icon");
    if (club === "TeamFrance") {
      assert.match(a.arenaGrid.innerHTML,/Inscriptions/,club+" registration phase");
      assert.match(a.arenaGrid.innerHTML,/À venir/,club+" upcoming phase");
      assert.match(a.arenaGrid.innerHTML,/Dans /,club+" registration countdown");
      assert.match(a.grid.innerHTML,/j\/c/,club+" jours par coup");
    } else {
      assert.match(a.arenaGrid.innerHTML,/Registration/,club+" registration phase");
      assert.match(a.arenaGrid.innerHTML,/Upcoming/,club+" upcoming phase");
      assert.match(a.arenaGrid.innerHTML,/Starts in /,club+" registration countdown");
    }
    assert.match(a.arenaGrid.innerHTML,/90m/,club+" duration");
    assert.match(a.grid.innerHTML,/WCGC/,club+" Daily card preserved");
    assert.ok(a.timers.some(x=>x.period===1000),club+" timer");
    const b=await fixture(club,[arena(4,"Rapid open","15+10")]);
    assert.match(b.arenaGrid.innerHTML,/tgp-speed-icon rapid/,club+" rapid icon");
    const c=await fixture(club,[]);
    assert.equal(c.section.hidden,true,club+" hide empty arenas");
    assert.equal(c.arenaGrid.innerHTML,"",club+" no stale card");
    const priorityArena=arena(6,"Priority arena","3+2"); priorityArena.urgent=true;
    const p=await fixture(club,[priorityArena],[{...match,ourTeam:{players:[]},opponentTeam:{players:[]}}]);
    assert.match(p.arenaGrid.innerHTML,/tgp-priority/,club+" priority badge");
    assert.match(p.arenaGrid.innerHTML,club==="TeamFrance"?/Priorité/:/Priority/,club+" priority translated");
    assert.match(p.grid.innerHTML,club==="TeamFrance"?/Manque 0/:/Need ✓/,club+" zero-need wording");
    const four=await fixture(club,[
      arena(2,"One","1+0"),arena(3,"Two","3+2"),arena(4,"Three","10+0"),arena(5,"Four","5+0")
    ],Array.from({length:6},(_,i)=>({...match,matchId:String(56790+i)})),{arenaRows:2,dailyRows:3});
    assert.equal((four.arenaGrid.innerHTML.match(/class="tgp-card arena-card"/g)||[]).length,4,club+" MCA 2 rows = 4 cards");
    assert.equal((four.grid.innerHTML.match(/class="tgp-card/g)||[]).length,6,club+" Daily 3 rows = 6 cards");
    const css=fs.readFileSync(path.join(__dirname,"..",club,"Widget","assets","widget.css"),"utf8");
    assert.match(css,/text-align:center/,club+" section headings centered");
    const admin=fs.readFileSync(path.join(__dirname,"..",club,"Widget","assets","admin.js"),"utf8");
    assert.match(admin,/const height = 24 \+ 132/,club+" dynamic iframe sizing");
    assert.match(admin,/saveLayoutConfiguration/,club+" row control persistence");
    assert.match(admin,/data-arena-action="priority"/,club+" MCA priority control");
    assert.match(admin,/persistedItems/,club+" arena saves preserve unsaved Daily edits");
    assert.match(admin,/arenaModal/,club+" modal editor");
    assert.match(admin,/if \(arenaSaving \|\| dailySaving\)/,club+" concurrent saves blocked");
  }
  process.stdout.write("TGP and Team France arena card parity / section headings passed.\n");
})().catch(e=>{console.error(e);process.exitCode=1;});
