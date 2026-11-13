/* Deterministic Hall of Fame runtime controls, server pagination and mode regressions. */
"use strict";
const assert=require("node:assert/strict");
const fs=require("node:fs"),vm=require("node:vm"),path=require("node:path");
const src=fs.readFileSync(path.join(__dirname,"../assets/js/widgets/p2k-spotlight-v2159.js"),"utf8");
const requests=[],buttons={};
const makeControl=(dataset={})=>({dataset,onclick:null,callbacks:{},addEventListener(type,cb){this.callbacks[type]=cb},fire(type,value){this.callbacks[type]?.({target:{value}})}});
const form=makeControl(),search=makeControl(),select=makeControl();
search.value="";select.value="all";
const nav=makeControl();nav.setAttribute=()=>{};nav.innerHTML="";nav.className="";
const main={innerHTML:"",scrollTop:0,parentNode:{insertBefore(element){assert.equal(element,nav)}},
 querySelector(selector){
   if(selector==="#p2kwPlayerForm"||selector==="#p2kwTournamentSearch")return form;
   if(selector==="#p2kwPlayerSearch"||selector==="#p2kwTournamentInput")return search;
   if(selector==="#p2kwHofPeriod")return select;
   if(!buttons[selector])buttons[selector]=makeControl();
   return buttons[selector];
 },
 querySelectorAll(selector){
   if(selector==="[data-hof-more]")return ["daily","arena"].map(kind=>makeControl({hofMore:kind}));
   if(selector==="[data-hof-page]")return [-1,1].map(page=>makeControl({hofPage:String(page)}));
   if(selector==="[data-username]")return [];
   if(selector==="[data-scoretab]")return ["daily","arena"].map(tab=>makeControl({scoretab:tab}));
   if(selector==="[data-tour-index]")return [];
   return [];
 }
};
const status={textContent:""},foot={textContent:""};
const document={body:{dataset:{widget:"hall-of-fame",preview:"0"}},currentScript:{src:"https://www.promotetoking.org/assets/js/widgets/p2k-spotlight-v2159.js"},
 documentElement:{scrollHeight:940},createElement(tag){assert.equal(tag,"nav");return nav},
 getElementById(id){return id==="p2kwContent"?main:id==="p2kwStatus"?status:id==="p2kwFoot"?foot:null},
 querySelector(){return {classList:{add(){}}}}
};
async function payload(address){
 const u=new URL(String(address)),action=u.searchParams.get("action");
 requests.push({action,url:u.href,kind:u.searchParams.get("kind"),period:u.searchParams.get("period"),page:Number(u.searchParams.get("page")||1)});
 if(action==="config")return {ok:true,settings:{enabled:true,theme:"auto",title:"Hall of Fame",medalPageSize:3}};
 if(action==="hall-leaderboard"){
   const p=Number(u.searchParams.get("page")||1),kind=u.searchParams.get("kind"),period=u.searchParams.get("period");
   const rows=Array.from({length:25},(_,i)=>({username:kind+"-"+period+"-"+(i+1+(p-1)*25),rank:i+1+(p-1)*25,points:10000-25*(p-1)-i}));
   return {ok:true,kind,period,rows,pagination:{page:p,page_size:25,total_rows:61,total_pages:3},range:{start:"",end:""}};
 }
 if(action==="tournament-highlights"){
   return {ok:true,completed:{name:"Winter Cup",status:"finished",webUrl:"https://www.chess.com/tournament/winter",podium:{gold:["Alpha"],silver:["Bravo"],bronze:["Charlie"]}},
      completed_pagination:{page:1,pages:12,total:12},search_kind:"tournament",
      matches:[],pagination:{page:1,pages:1,total:0}};
 }
 if(u.pathname.endsWith("/hall-search.php"))return {ok:true,found:true,username:"Alpha",daily:{username:"Alpha",points:123,team_position:4,rank:{name:"Knight"}},live:{available:true,points:12,team_position:7,rank:{name:"Pawn"}},tournaments:{gold:1,silver:0,bronze:0}};
 if(u.pathname.endsWith("/members-insights.php"))return {ok:true,rows:[{username:"Alpha",points:123,team_position:4}]};
 if(u.pathname.endsWith("/arenas-insights.php"))return {ok:true,leaders:[]};
 throw Error("Unexpected call "+u);
}
const ctx={document,window:{},parent:{postMessage(){}},URL,Intl,AbortController,console,Date,Math,Number,String,Error,Promise,setTimeout,clearTimeout,fetch:async addr=>({ok:true,status:200,json:()=>payload(addr)})};
vm.runInNewContext(src,ctx,{filename:"p2k-spotlight-v2159.js"});
const wait=()=>new Promise(ok=>setTimeout(ok,30));
const countUserRows=()=>[...main.innerHTML.matchAll(/data-username="/g)].length;
const switchMode=(mode)=>nav.callbacks.click({target:{closest:()=>({dataset:{hofMode:mode}})}});
(async()=>{
 await wait();
 assert.equal(status.textContent,"LIVE");
 assert(nav.innerHTML.includes("Leaderboards")&&nav.innerHTML.includes("Tournaments"));
 assert(main.innerHTML.includes("Daily Matches")&&main.innerHTML.includes("Multi Club Arenas"));
 assert(!main.innerHTML.includes("Top 5"));
 assert.equal(countUserRows(),10,"default shows five Daily and five Arena players");
 assert(requests.filter(r=>r.action==="hall-leaderboard").every(r=>r.page===1&&r.period==="all"));
 const more=main.querySelectorAll("[data-hof-more]")[1];more.onclick();await wait();
 assert(!main.innerHTML.includes("Daily Matches"),"Expanding Arena must hide Daily");
 assert.equal(countUserRows(),25,"full Arena page contains 25 players");
 assert(main.innerHTML.includes("Page 1 / 3"));
 const pageForward=main.querySelectorAll("[data-hof-page]")[1];pageForward.onclick();await wait();
 assert(main.innerHTML.includes("arena-all-26"));
 assert(!main.innerHTML.includes("arena-all-1\""));
 assert(main.innerHTML.includes("Page 2 / 3"));
 buttons["[data-hof-back]"].callbacks.click();await wait();
 assert.equal(countUserRows(),10,"returning to compact view shows top five again");
 assert(main.innerHTML.includes("arena-all-1\""),"top-five cache is not replaced by page two");
 select.value="month";select.callbacks.change({target:select});await wait();
 assert(requests.some(r=>r.action==="hall-leaderboard"&&r.kind==="daily"&&r.period==="month"));
 assert(main.innerHTML.includes("daily-month-1"),"calendar period changes Daily score rows");
 assert(main.innerHTML.includes("arena-all-1"),"calendar period does not modify Arena");
 const expandDaily=main.querySelectorAll("[data-hof-more]")[0];expandDaily.onclick();await wait();
 assert(!main.innerHTML.includes("Multi Club Arenas"),"Daily expansion hides Arena");
 assert.equal(countUserRows(),25);
 main.querySelectorAll("[data-hof-page]")[1].onclick();await wait();
 assert(main.innerHTML.includes("daily-month-26"));
 search.value="Alpha";form.onsubmit({preventDefault(){}});await wait();
 assert(main.innerHTML.includes("Back to leaderboard"));
 assert(main.innerHTML.includes("Daily Matches")&&main.innerHTML.includes("Multi Club Arenas"),"shared profile display retains full labels");
 buttons["#p2kwTopButton"].onclick();await wait();
 assert(main.innerHTML.includes("daily-month-26"),"profile back restores period and expanded page");
 switchMode("tournaments");await wait();
 assert(main.innerHTML.includes("Winter Cup"));
 assert(!main.innerHTML.includes("Daily Matches"));
 switchMode("leaderboards");await wait();
 assert(main.innerHTML.includes("daily-month-26"));
 console.log("PASS Hall of Fame leaderboards/tournaments, five-row compact, 25-row paging, scope isolation and profile restoration");
})().catch(error=>{console.error(error);process.exitCode=1});
