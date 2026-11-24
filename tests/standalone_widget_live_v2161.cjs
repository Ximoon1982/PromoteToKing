"use strict";
const fs=require("node:fs");
const assert=require("node:assert/strict");
const vm=require("node:vm");

async function run(club) {
  const prefix=club+"/Widget/";
  const source=fs.readFileSync(prefix+"assets/widget.js","utf8");
  const admin=fs.readFileSync(prefix+"assets/admin.js","utf8");
  const css=fs.readFileSync(prefix+"assets/widget.css","utf8");
  const page=fs.readFileSync(prefix+"index.php","utf8");
  const adminPage=fs.readFileSync(prefix+"admin/index.php","utf8");
  const config=fs.readFileSync(prefix+"config.php","utf8");
  new vm.Script(source,{filename:prefix+"assets/widget.js"});
  new vm.Script(admin,{filename:prefix+"assets/admin.js"});
  for(const expected of ['id="liveSection"','id="liveShowcase"'])
    assert(page.includes(expected),club+" missing "+expected);
  for(const expected of ['id="liveRows"','id="liveReference"','id="liveList"',
    'id="liveRegistrationList"','id="refreshLiveRegistration"'])
    assert(adminPage.includes(expected),club+" missing "+expected);
  assert(css.includes(".live-speed .tgp-speed-icon"),club+" mini speed CSS");
  assert(config.includes("'GCWL'"),club+" missing GCWL label");
  assert(admin.includes("persistedLiveItems"),club+" arena save drops Live list");
  assert(admin.includes("dataset.liveIndex"),club+" live row handlers incorrect");
  assert(admin.includes("dataset.liveRegistrationId"),club+" live chooser handlers incorrect");
  assert(admin.includes("?action=registration-list&timeClass=live"),club+" live chooser API filter missing");
  assert(admin.includes('timeClass: "live"'),club+" live resolver type missing");

  const fake={tgpGrid:{innerHTML:"",replaceChildren(){}},
    arenaShowcase:{innerHTML:"",querySelectorAll(){return[]}},
    arenaSection:{hidden:true},
    liveShowcase:{innerHTML:"",querySelectorAll(){return[]}},
    liveSection:{hidden:true}};
  const now=Math.floor(Date.now()/1000);
  const live=(name,control,delay,league,status="registration")=>({
    matchId:String(987000+delay),name,opponentName:name,
    opponentLogo:"https://example.invalid/"+name+".png",
    time_control:control,startTime:now+delay,category:league?"league":"friendly",
    leagueAcronyms:league?["GCWL"]:[],status,
    url:"https://www.chess.com/club/matches/example/987001",
    urgent:false,
  });
  const values=[live("Belgique","5+0",1800,true),
    live("Scotland","3+2",7200,false),live("Italy","10+0",8100,true),
    live("Quebec","1+0",9000,false)];
  const response={ok:true,layout:{arenaRows:1,liveRows:2,dailyRows:2},
    cards:[],arenas:[],liveCards:values};
  const ctx={
    window:{WIDGET_FRANCE:club==="TeamFrance",setInterval(){}},
    document:{getElementById(id){return fake[id]||null}},
    fetch:async()=>({ok:true,json:async()=>response}),
    Date,Math,Number,String,Array,Object,Intl,console,
  };
  vm.runInNewContext(source,ctx,{filename:prefix+"assets/widget.js"});
  for(let tick=0;tick<8;tick++)await Promise.resolve();
  const html=fake.liveShowcase.innerHTML;
  assert.equal(fake.liveSection.hidden,false,club+" Live section hidden");
  assert.equal((html.match(/class="tgp-card live-card/g)||[]).length,4,club+" 2-row count");
  for(const marker of ["GCWL","Dans 30 min","5+0","tgp-speed-icon bullet","tgp-speed-icon blitz","tgp-speed-icon rapid"]) {
    if(marker==="Dans 30 min"&&club!=="TeamFrance")continue;
    assert(html.includes(marker),club+" missing "+marker);
  }
  assert(html.includes(club==="TeamFrance"?"Dans 30 min":"In 30 min"),club+" countdown locale");
  assert(html.includes("tgp-logo"),club+" opponent crest missing");
  assert(html.includes("tgp-badge friendly"),club+" friendly label");
  response.layout.liveRows=1;
  response.liveCards=values.slice(0,4);
  // New execution with 1-row layout; no live time should trigger a worker call.
  const fresh={...fake,liveShowcase:{innerHTML:"",querySelectorAll(){return[]}},
    liveSection:{hidden:true},tgpGrid:{innerHTML:"",replaceChildren(){}}};
  const ctx2={...ctx,document:{getElementById(id){return fresh[id]||null}}};
  vm.runInNewContext(source,ctx2);
  for(let tick=0;tick<8;tick++)await Promise.resolve();
  assert.equal((fresh.liveShowcase.innerHTML.match(/class="tgp-card live-card/g)||[]).length,2,
    club+" one-row cap");
  // Index-confirmed started cards are removed from render, never displayed.
  response.liveCards=[live("Finished","5+0",1800,false,"finished")];
  const empty={...fake,liveShowcase:{innerHTML:"",querySelectorAll(){return[]}},
    liveSection:{hidden:false},tgpGrid:{innerHTML:"",replaceChildren(){}}};
  vm.runInNewContext(source,{...ctx,document:{getElementById(id){return empty[id]||null}}});
  for(let tick=0;tick<8;tick++)await Promise.resolve();
  assert.equal(empty.liveSection.hidden,true,club+" finished Live card was displayed");
  console.log("PASS "+club+": live cards, 1/2 row limits, league/friendly, clocks and icons");
}
(async()=>{await run("TeamFrance");await run("TGP");})().catch(e=>{console.error(e);process.exitCode=1;});
