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
];
const data={
 "1001":{verified:true,matchId:1001,minRating:1400,maxRating:1800,minimumPlayers:3,maximumPlayers:20,
   oursCount:2,opponentCount:4,oursRatings:[1500,1700],opponentRatings:[1600,1800,1900,1950],rules:"chess",timeControl:"86400",locked:false},
 "1002":{verified:true,matchId:1002,minRating:1600,maxRating:2000,minimumPlayers:3,maximumPlayers:20,
   oursCount:4,opponentCount:4,oursRatings:[1600,1700,1800,1950],opponentRatings:[1600,1700,1800,1850],rules:"chess",timeControl:"259200",locked:false},
 "1003":{verified:true,matchId:1003,minRating:1800,maxRating:2100,minimumPlayers:2,maximumPlayers:20,
   oursCount:1,opponentCount:3,oursRatings:[1880],opponentRatings:[1900,1990,2050],rules:"chess960",timeControl:"259200",locked:false}
};
const window={P2K_RECRUITMENT_LINEUP_CORE:{
 recruitmentMetrics({match,p2kTeam,opponentTeam}){
   const ours=p2kTeam.players.length,other=opponentTeam.players.length;
   const target=Math.max(Number(match.settings.min_team_players)||0,other);
   const baseline=Math.max(0,target-ours);
   return {count:baseline,baseline,ready:baseline===0};
 }
}};
const fetch=async u=>{
 const url=new URL(String(u)),action=url.searchParams.get("action");
 calls.push({action,ids:url.searchParams.get("ids"),player:url.searchParams.get("player")});
 let output;
 if(action==="config")output={ok:true,settings:{enabled:true,window:"48h",limit:4}};
 else if(action==="catalog")output={ok:true,matches};
 else if(action==="radar-batch"){
    const who=url.searchParams.get("player")||"";
    output={ok:true,rows:(url.searchParams.get("ids")||"").split(",").map(id=>({
        ...data[id],alreadyRegistered:who.toLowerCase()==="alpha"&&id==="1001"
    }))};
 }else if(action==="radar-player")output={ok:true,username:"Alpha",classical:1550,chess960:1950};
 else throw Error("Unexpected fetch "+url.href);
 return {ok:true,status:200,json:async()=>output};
};
vm.runInNewContext(source,{document,window,URL,Intl,AbortController,console,Date,Math,Number,String,Error,Promise,setTimeout,clearTimeout,fetch},{filename:"p2k-recruitment-radar-v2159.js"});
const wait=()=>new Promise(resolve=>setTimeout(resolve,55));
(async()=>{
 await wait();
 assert.equal(status.textContent,"LIVE");
 assert.equal(calls.filter(x=>x.action==="radar-batch").length,1,"At most four match sheets requested in one batch");
 assert(calls.some(x=>x.action==="radar-batch"&&x.ids==="1001,1002,1003"));
 assert(list.innerHTML.includes("League One"));
 assert(list.innerHTML.includes("League 960"));
 assert(!list.innerHTML.includes("Friendly Two"),"Priority must suppress matches not needing recruitment");
 assert(list.innerHTML.includes("1400–1800"),"Match rating restrictions shown");
 assert(list.innerHTML.includes("1 day/move"),"Match time control displayed");
 assert(!list.innerHTML.includes("Source: current Green match catalogue"));
 controls.get("[data-radar-scope]")[1].onclick();
 assert(list.innerHTML.includes("Friendly Two"),"All mode retains non-priority matches");
 usernameInput.value="Alpha";form.onsubmit({preventDefault(){}});await wait();
 assert(calls.some(x=>x.action==="radar-player"&&x.player==="Alpha"));
 assert(calls.some(x=>x.action==="radar-batch"&&x.player==="Alpha"));
 assert(!list.innerHTML.includes("League One"),"Already-registered match removed for user");
 assert(!list.innerHTML.includes("Friendly Two"),"Rating 1550 is outside 1600-2000");
 assert(list.innerHTML.includes("League 960"),"Correct Chess960 1950 rating allows 1800-2100 match");
 controls.get("[data-radar-scope]")[0].onclick();
 assert(list.innerHTML.includes("League 960"),"Personalized priority is the intersection of eligibility and need");
 controls.get("[data-radar-window]")[0].onclick();await wait();
 assert(!list.innerHTML.includes("League 960"),"24h window removes a 36h match");
 assert(!list.innerHTML.includes("League One"),"Registered match remains excluded");
 assert.equal(foot.textContent,"");
 const scripts=fs.readFileSync(path.join(__dirname,"../server/widgets/public/embed.php"),"utf8");
 assert(scripts.includes("p2k-recruitment-radar-v2159.js"));
 assert(scripts.includes("recruitment-lineup-core.js"));
 console.log("PASS Radar priorities, All, rating/time-control metadata, personalized Daily/960 filtering, registered exclusion, and 24h window");
})().catch(e=>{console.error(e);process.exitCode=1});
