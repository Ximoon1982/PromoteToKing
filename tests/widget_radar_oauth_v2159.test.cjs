"use strict";
/* Authenticated and anonymous Recruitment Radar behavior on the same script-free embed. */
const assert=require("node:assert/strict"),fs=require("node:fs"),vm=require("node:vm"),path=require("node:path");
const source=fs.readFileSync(path.join(__dirname,"../assets/js/widgets/p2k-recruitment-radar-v2159.js"),"utf8");
function run(oauthCase){
  const calls=[],elements={};
  function control(dataset={}){
    return {dataset,value:"",innerHTML:"",textContent:"",onclick:null,oninput:null,onsubmit:null,
      setAttribute() {},querySelector(){return elements.usernameInput}};
  }
  const form=control(),input=control(),list=control(),hint=control(),count=control(),overview=control();
  elements.usernameInput=input;
  const scope=[control({radarScope:"priority"}),control({radarScope:"all"})];
  const windows=["24h","48h","72h","7d"].map(r=>control({radarWindow:r}));
  const main={innerHTML:"",querySelector(sel){
    if(sel==="#p2kwRadarForm")return this.innerHTML.includes('id="p2kwRadarForm"')?form:null;
    return {"#p2kwRadarList":list,"#p2kwRadarHint":hint,"#p2kwRadarCount":count,
      "#p2kwRadarOverview":overview}[sel]||null;
  },querySelectorAll(sel){
    return sel==="[data-radar-scope]"?scope:sel==="[data-radar-window]"?windows:[];
  }};
  const status=control(),foot=control(),document={
    body:{dataset:{widget:"recruitment-radar",preview:"0"}},
    currentScript:{src:"https://www.promotetoking.org/assets/js/widgets/p2k-recruitment-radar-v2159.js"},
    getElementById(id){return {p2kwContent:main,p2kwStatus:status,p2kwFoot:foot}[id]||null}
  };
  const match={matchId:"1200",name:"U1500 Match",opponentName:"Opponent",
    url:"https://www.chess.com/club/matches/1200",startTime:Math.floor(Date.now()/1000)+3600,
    category:"league",timeControl:"86400"};
  const detail={verified:true,matchId:1200,minRating:null,maxRating:1500,minimumPlayers:2,
    maximumPlayers:20,oursCount:1,opponentCount:3,oursRatings:[1400],opponentRatings:[1200,1300,1400],
    rules:"chess",timeControl:"86400",locked:false,minRatingKnown:false,maxRatingKnown:true};
  const window={P2K_RECRUITMENT_LINEUP_CORE:{}};
  const fetch=async (address,options)=>{
    const url=new URL(String(address)),action=url.searchParams.get("action"),who=url.searchParams.get("player");
    calls.push({path:url.pathname,action,player:who,options});
    if(url.pathname.endsWith("/oauth.php")){
      assert.equal(options.credentials,"same-origin");
      if(oauthCase==="expired")return {ok:false,status:401,json:async()=>({ok:false,error:{message:"Expired"}})};
      if(oauthCase==="anonymous")return {ok:true,status:200,json:async()=>({ok:true,authenticated:false,oauth_verified:false,profile:null})};
      if(oauthCase==="unverified")return {ok:true,status:200,json:async()=>({ok:true,authenticated:true,oauth_verified:false,profile:{username:"Ximoon"}})};
      if(oauthCase==="invalid")return {ok:true,status:200,json:async()=>({ok:true,authenticated:true,oauth_verified:true,profile:{username:"bad username!"}})};
      return {ok:true,status:200,json:async()=>({ok:true,authenticated:true,oauth_verified:true,profile:{username:"Ximoon"}})};
    }
    if(action==="config")return {ok:true,status:200,json:async()=>({ok:true,settings:{enabled:true,window:"48h",limit:4}})};
    if(action==="radar-catalog")return {ok:true,status:200,json:async()=>({ok:true,matches:[match],details:[detail]})};
    if(action==="radar-player")return {ok:true,status:200,json:async()=>({ok:true,username:who,available:true,classical:1300,chess960:1300,statsCheckedAt:"2026-11-13 16:00:00"})};
    throw new Error("Unexpected network request: "+url);
  };
  vm.runInNewContext(source,{document,window,URL,Intl,AbortController,console,Date,Math,Number,String,Error,Promise,setTimeout,clearTimeout,fetch});
  return {calls,main,form,input,list,status};
}
const wait=()=>new Promise(resolve=>setTimeout(resolve,55));
(async()=>{
  const signed=run("signed");await wait();
  assert.equal(signed.status.textContent,"LIVE");
  assert(signed.main.innerHTML.includes("For Ximoon"),"Verified identity shown");
  assert(!signed.main.innerHTML.includes('id="p2kwRadarForm"'),"No generic search form for signed-in users");
  assert(signed.list.innerHTML.includes("U1500"),"Automatically personalized eligible match displayed");
  assert(signed.main.innerHTML.includes('class="p2kw-radar-summary"'),"Authenticated Radar retains a compact summary");
  assert(!signed.main.innerHTML.includes("Rating-matched opportunities"),"Authenticated summary uses numeric upcoming count");
  assert.equal(signed.calls.filter(x=>x.action==="radar-player").length,1,"Exactly one auto-search/audit event");
  assert.equal(signed.calls.filter(x=>x.action==="radar-catalog").length,1,"No redundant generic catalogue fetch");
  assert.equal(signed.calls.find(x=>x.action==="radar-catalog").player,"Ximoon");
  assert(signed.calls.every(x=>x.path.startsWith("/server/")),"No Chess.com public HTTP in Radar");
  for(const caseName of ["anonymous","expired","unverified","invalid"]){
    const f=run(caseName);await wait();
    assert.equal(f.status.textContent,"LIVE",caseName+" fallback should work");
    assert(f.main.innerHTML.includes('id="p2kwRadarForm"'),caseName+" retains generic search");
    assert.equal(f.calls.filter(x=>x.action==="radar-player").length,0,caseName+" must not impersonate or log user");
    assert.equal(f.calls.filter(x=>x.action==="radar-catalog").length,1,caseName+" loads one generic catalogue");
    assert.equal(f.calls.find(x=>x.action==="radar-catalog").player,null);
  }
  console.log("PASS Radar signed-in auto-personalization, once-only logged lookup, anonymous/expired/unverified/invalid safe fallback");
})().catch(e=>{console.error(e);process.exitCode=1});
