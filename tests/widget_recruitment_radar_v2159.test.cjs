"use strict";
const assert=require("node:assert/strict"),fs=require("node:fs"),vm=require("node:vm"),path=require("node:path");
const source=fs.readFileSync(path.join(__dirname,"../assets/js/widgets/p2k-recruitment-radar-v2159.js"),"utf8");
const calls=[],controls=new Map();
function control(dataset={}){
 return {dataset,onclick:null,oninput:null,onsubmit:null,innerHTML:"",textContent:"",value:"",
    ariaPressed:"",setAttribute(name,value){if(name==="aria-pressed")this.ariaPressed=value},
    querySelector(){return usernameInput}};
}
const usernameInput=control(),form=control();
const list=control(),hint=control(),count=control(),overview=control();
const status=control(),foot=control();
const cache=selector=>{
 if(selector==="#p2kwRadarList")return list;
 if(selector==="#p2kwRadarHint")return hint;
 if(selector==="#p2kwRadarCount")return count;
 if(selector==="#p2kwRadarOverview")return overview;
 if(selector==="#p2kwRadarForm")return form;
 return null;
};
const main={
 innerHTML:"",
 querySelector:cache,
 querySelectorAll(selector){
   if(!controls.has(selector)){
     if(selector==="[data-radar-scope]")
       controls.set(selector,[control({radarScope:"priority"}),control({radarScope:"all"})]);
     else if(selector==="[data-radar-window]")
       controls.set(selector,["24h","48h","72h","7d"].map(r=>control({radarWindow:r})));
     else controls.set(selector,[]);
   }
   return controls.get(selector);
 }
};
const document={
 body:{dataset:{widget:"recruitment-radar",preview:"0"}},
 currentScript:{src:"https://www.promotetoking.org/assets/js/widgets/p2k-recruitment-radar-v2159.js"},
 getElementById(id){return {p2kwContent:main,p2kwStatus:status,p2kwFoot:foot}[id]||null}
};
const now=Math.floor(Date.now()/1000);
const matches=[
 {matchId:"1001",name:"League One",opponentName:"League One",url:"https://www.chess.com/club/matches/1001",startTime:now+8*3600,category:"league",timeControl:"86400"},
 {matchId:"1002",name:"Friendly Two",opponentName:"Friendly Two",url:"https://www.chess.com/club/matches/1002",startTime:now+14*3600,category:"friendly",timeControl:"259200"},
 {matchId:"1003",name:"League 960",opponentName:"League 960",url:"https://www.chess.com/club/matches/1003",startTime:now+36*3600,category:"league",timeControl:"259200"},
 {matchId:"1004",name:"U1500 League",opponentName:"U1500 League",url:"https://www.chess.com/club/matches/1004",startTime:now+25*3600,category:"league",timeControl:"86400"},
 {matchId:"1005",name:"Open Rating Match",opponentName:"Open Rating Match",url:"https://www.chess.com/club/matches/1005",startTime:now+56*3600,category:"league",timeControl:"86400"},
];
const data={
 "1001":{verified:true,matchId:1001,minRating:1400,maxRating:1800,minimumPlayers:3,maximumPlayers:20,
   oursCount:2,opponentCount:4,oursRatings:[1500,1700],opponentRatings:[1600,1800,1900,1950],rules:"chess",timeControl:"86400",locked:false,minRatingKnown:true,maxRatingKnown:true},
 "1002":{verified:true,matchId:1002,minRating:1600,maxRating:2000,minimumPlayers:3,maximumPlayers:20,
   oursCount:4,opponentCount:4,oursRatings:[1600,1700,1800,1950],opponentRatings:[1600,1700,1800,1850],rules:"chess",timeControl:"259200",locked:false,minRatingKnown:true,maxRatingKnown:true},
 "1003":{verified:true,matchId:1003,minRating:1800,maxRating:2100,minimumPlayers:2,maximumPlayers:20,
   oursCount:1,opponentCount:3,oursRatings:[1880],opponentRatings:[1900,1990,2050],rules:"chess960",timeControl:"259200",locked:false,minRatingKnown:true,maxRatingKnown:true},
 "1004":{verified:true,matchId:1004,minRating:null,maxRating:1500,minimumPlayers:2,maximumPlayers:30,
   oursCount:1,opponentCount:3,oursRatings:[1400],opponentRatings:[1200,1300,1400],rules:"chess",timeControl:"86400",locked:false,minRatingKnown:false,maxRatingKnown:true},
 "1005":{verified:true,matchId:1005,minRating:null,maxRating:null,minimumPlayers:2,maximumPlayers:30,
   oursCount:1,opponentCount:3,oursRatings:[1400],opponentRatings:[1200,1300,1400],rules:"chess",timeControl:"86400",locked:false,minRatingKnown:true,maxRatingKnown:false}
};
const window={P2K_RECRUITMENT_LINEUP_CORE:{
 compareRecruitmentLineupsCheap(a,b){return {expectedScoreShare:a.length>=b.length?.6:.38}}
}};
const fetch=async u=>{
 const url=new URL(String(u)),action=url.searchParams.get("action"),who=url.searchParams.get("player")||"";
 calls.push({action,url:url.href,player:who});
 let output;
 if(action==="config")output={ok:true,settings:{enabled:true,window:"48h",limit:4}};
 else if(url.pathname.endsWith("/oauth.php"))output={ok:true,authenticated:false,oauth_verified:false,profile:null};
 else if(action==="radar-catalog")output={ok:true,source:"Green synchronized DB",matches,
    details:Object.keys(data).map(id=>({...data[id],alreadyRegistered:who.toLowerCase()==="alpha"&&id==="1001"}))};
 else if(action==="radar-player")output={ok:true,username:who,available:true,classical:who==="Beta"?1300:1550,chess960:1950,statsCheckedAt:"2026-11-13 10:00:00"};
 else throw Error("Unexpected fetch "+url.href);
 return {ok:true,status:200,json:async()=>output};
};
vm.runInNewContext(source,{document,window,URL,Intl,AbortController,console,Date,Math,Number,String,Error,Promise,setTimeout,clearTimeout,fetch},{filename:"p2k-recruitment-radar-v2159.js"});
const wait=()=>new Promise(resolve=>setTimeout(resolve,55));
(async()=>{
 await wait();
 assert.equal(status.textContent,"LIVE");
 assert(calls.some(x=>x.url.includes("/oauth.php?action=session")),"OAuth identity checked first");
 assert(main.innerHTML.includes("p2kwRadarForm"),"Anonymous visitors retain generic username form");
 assert.equal(calls.filter(x=>x.action==="radar-catalog").length,1,"One DB-only catalog call, no per-match fetches");
 assert(!calls.some(x=>x.action==="radar-batch"),"No match-sheet fanout even with a cold cache");
 assert(calls.every(x=>new URL(x.url).hostname==="www.promotetoking.org"),"No browser Chess.com API requests");
 assert(list.innerHTML.includes("League One")&&list.innerHTML.includes("League 960"));
 assert(!list.innerHTML.includes("Friendly Two"),"Default Priority must suppress matches without recruitment need");
 assert(list.innerHTML.includes("1400–1800"),"Rating restrictions shown");
 assert(list.innerHTML.includes("1d/m"),"Compact daily time control displayed");
 assert(list.innerHTML.includes("p2kw-radar-pill is-league"),"League accent pill displayed");
 assert(list.innerHTML.includes("p2kw-radar-meta"),"All match fields grouped in one metadata row");
 assert(main.innerHTML.includes('class="p2kw-radar-summary"'),"Compact single-line summary is used");
 assert(!main.innerHTML.includes('class="p2kw-big" id="p2kwRadarCount"'),"Counts use normal text size");
 assert(main.innerHTML.includes('class="p2kw-radar-subhead"><strong>Upcoming matches</strong>'),"List heading is Upcoming matches");
 assert(!main.innerHTML.includes("Recruitment opportunities")&&!main.innerHTML.includes("Priority = verified need"),"Redundant labels removed");
 assert.equal(overview.textContent,"4 upcoming matches","Total upcoming shows for anonymous visitor");
 const meta=(list.innerHTML.match(/<div class="p2kw-radar-meta"[^>]*>(.*?)<\/div>/s)||[])[1]||"";
 assert(meta.startsWith('<span class="p2kw-radar-pill '),"League/Friendly pill leads the metadata line");
 assert(meta.indexOf('p2kw-radar-pill')<meta.indexOf('p2kw-radar-date'),"Category pill precedes date");
 const radarCss=fs.readFileSync(path.join(__dirname,"../assets/css/widgets/p2k-recruitment-radar-v2159.css"),"utf8");
 assert(radarCss.includes("font:400 9px/1.35"),"All summary values share normal font size");
 assert(radarCss.includes("white-space:nowrap"),"Summary remains a single line");
 controls.get("[data-radar-scope]")[1].onclick();
 assert(list.innerHTML.includes("Friendly Two"),"All mode retains non-priority matches");
 usernameInput.value="Alpha";form.onsubmit({preventDefault(){}});await wait();
 assert(calls.some(x=>x.action==="radar-player"&&x.player==="Alpha"));
 assert(calls.some(x=>x.action==="radar-catalog"&&x.player==="Alpha"));
 assert(!list.innerHTML.includes("League One"),"Already registered match excluded");
 assert(!list.innerHTML.includes("Friendly Two"),"Standard rating outside match window");
 assert(list.innerHTML.includes("League 960"),"Chess960 rating used for 960 match");
 controls.get("[data-radar-scope]")[0].onclick();
 assert(list.innerHTML.includes("League 960"),"Personalized priority matches verified need and eligibility");
 usernameInput.value="Beta";await form.onsubmit({preventDefault(){}});await wait();
 assert(list.innerHTML.includes("U1500 League"),"Unset minimum rating admits an eligible 1300-rated user");
 assert(list.innerHTML.includes("U1500"),"Missing lower bound renders as U1500 rather than ?–1500");
 assert(!list.innerHTML.includes("?–1500"),"Unspecified minimum no longer printed as unknown");
 usernameInput.value="Alpha";await form.onsubmit({preventDefault(){}});await wait();
 controls.get("[data-radar-window]")[0].onclick();await wait();
 assert(list.innerHTML.includes("League 960"),"Empty 24h expands to the 48h opportunity");
 assert(hint.textContent.includes("showing 48h"),"Automatic expansion disclosed to visitor");
 assert(!list.innerHTML.includes("League One"),"Registration exclusion preserved across fallback");
 controls.get("[data-radar-window]")[3].onclick();await wait();
 assert(list.innerHTML.includes("Open Rating Match"),"Unrestricted upper rating does not exclude personalized results");
 assert(overview.textContent.endsWith(" upcoming matches"),"Personalized results keep numeric upcoming summary");
 assert(list.innerHTML.includes("Open"),"Missing rating cap displays as Open");
 assert(!list.innerHTML.includes("Rating cap unavailable"),"No misleading unknown cap label");
 assert(!hint.textContent.includes("Synchronized Green")&&!hint.textContent.includes("Chess.com requests"),"No internal technical hints");
 assert.equal(foot.textContent,"");
 const scripts=fs.readFileSync(path.join(__dirname,"../server/widgets/public/embed.php"),"utf8");
 assert(scripts.includes("p2k-recruitment-radar-v2159.js"));
 assert(scripts.includes("recruitment-lineup-core.js"));
 console.log("PASS Radar Green-only catalog, priority/all, one-line pills, rating personalization, registered exclusion and automatic window widening");
})().catch(e=>{console.error(e);process.exitCode=1});
