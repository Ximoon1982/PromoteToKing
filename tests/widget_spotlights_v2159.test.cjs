/* P2K v2.15.9: runtime smoke tests for both 260 px embeddable widgets. */
"use strict";
const fs=require("fs"),path=require("path"),vm=require("vm"),assert=require("assert");
const src=fs.readFileSync(path.join(__dirname,"../assets/js/widgets/p2k-spotlight-v2159.js"),"utf8");
const css=fs.readFileSync(path.join(__dirname,"../assets/css/widgets/p2k-spotlight-v2159.css"),"utf8");
const completedRecord=(page)=>({name:"Winter Kings "+page,slug:"w"+page,status:"finished",period:"2026 Q4",
 webUrl:"https://www.chess.com/tournament/winter-kings-"+page,
 podium:{gold:["Alpha","Bravo"],silver:["Charlie"],bronze:["Delta"]}});
function payload(u){
 const url=new URL(String(u)),action=url.searchParams.get("action");
 if(action==="config")return {ok:true,settings:{enabled:true,medalPageSize:3,showArena:true,showMedals:true}};
 if(action==="spotlight-leaders")return {ok:true,daily:Array.from({length:5},(_,i)=>({username:"daily"+i,points:200-i})),arena:Array.from({length:5},(_,i)=>({username:"live"+i,points:300-i}))};
 if(action==="tournament-highlights"){
  const p=Number(url.searchParams.get("completed_page")||1),search=url.searchParams.get("search")||"";
  const byPlayer=search==="Alpha",byTitle=search==="Winter";
  const searchPage=Number(url.searchParams.get("page")||1);
  const medals=Array.from({length:searchPage===1?10:2},(_,i)=>({
   ...completedRecord(i+1+(searchPage-1)*10),matched_medalists:[{username:"Alpha",medal:"gold"}]
  }));
  return {ok:true,completed:completedRecord(p),completed_pagination:{page:p,pages:18,total:18,per_page:1},
    search_kind:byPlayer?"player":"tournament",
    matches:byPlayer?medals:byTitle?[{name:"Winter Masters",slug:"wm",status:"in_progress",period:"2026 Q4",podium:{}}]:[],
    pagination:{page:searchPage,pages:byPlayer?2:1,total:byPlayer?12:byTitle?1:0,per_page:10}};
 }
 if(url.pathname.endsWith("hall-search.php"))return {ok:true,found:true,username:"Alpha",daily:{username:"Alpha",points:210,team_position:3,rank:{name:"Daily Knight",key:"knight",image:"knight.png"}},live:{available:true,points:81,team_position:5,best_rank:2,arenas:17,best_streak:6,rank:{name:"Live Pawn",key:"live_pawn",icon:"01_Pawn.png"}},tournaments:{gold:2,silver:1,bronze:0}};
 if(url.pathname.endsWith("members-insights.php")){let start=url.searchParams.get("start");return {ok:true,rows:[{username:"Alpha",team_position:start?6:3,points:start?17:210,previous_position:7,position_change:4}]};}
 if(url.pathname.endsWith("arenas-insights.php"))return {ok:true,leaders:[{username:"Alpha",points:17}]};
 throw Error("Unexpected request "+url);
}
function start(widget){
 const buttons=new Map(),form={onsubmit:null},search={value:widget==="player-spotlight"?"Alpha":""};
 const main={innerHTML:"",scoretabs:[],querySelector(selector){
  if(selector==="#p2kwPlayerForm"||selector==="#p2kwTournamentSearch")return form;
  if(selector==="#p2kwPlayerSearch"||selector==="#p2kwTournamentInput")return search;
  if(!buttons.has(selector))buttons.set(selector,{onclick:null,addEventListener(_name,callback){this.onclick=callback}});
  return buttons.get(selector);
 },querySelectorAll(selector){if(selector==="[data-scoretab]"){
  this.scoretabs=["daily","arena"].map(v=>({dataset:{scoretab:v},onclick:null}));return this.scoretabs;
 }return []}};
 const status={textContent:""},foot={textContent:""};
 const document={body:{dataset:{widget,preview:"0"}},currentScript:{src:"https://www.promotetoking.org/assets/js/widgets/p2k-spotlight-v2159.js"},
  documentElement:{scrollHeight:550},getElementById(id){return id==="p2kwContent"?main:id==="p2kwStatus"?status:id==="p2kwFoot"?foot:null},
  querySelector:()=>({classList:{add:()=>{}}})};
 const window={};
 const context={document,window,parent:{postMessage:()=>{}},URL,Intl,AbortController,console,Date,Math,Number,String,Error,Promise,setTimeout,clearTimeout,
  fetch:async u=>({ok:true,status:200,json:async()=>payload(u)})};
 vm.runInNewContext(src,context,{filename:"p2k-spotlight-v2159.js"});
 return{main,status,foot,form,search,buttons};
}
const wait=()=>new Promise(r=>setTimeout(r,20));
(async()=>{
 assert(css.includes(".p2kw-search"));
 assert(src.includes("const ROOT=new URL('../../../',document.currentScript.src)"));
 const player=start("player-spotlight");await wait();
 assert.equal(player.status.textContent,"LIVE");
 assert.equal((player.main.innerHTML.match(/data-username=/g)||[]).length,10);
 assert(player.main.innerHTML.includes("Daily · Top 5"));
 assert(player.main.innerHTML.includes("MCA Arenas · Top 5"));
 player.form.onsubmit({preventDefault(){}});await wait();
 assert(player.main.innerHTML.includes("210"));
 assert(player.main.innerHTML.includes("Tournament medals"));
 assert(player.main.innerHTML.includes("Daily Knight"));
 assert(player.main.innerHTML.includes("Live Pawn"));
 assert(player.main.innerHTML.includes("This month rank"));
 assert(player.main.innerHTML.includes("p2kw-big-position"));
 assert.equal(player.foot.textContent,"");
 player.main.scoretabs[1].onclick();await wait();
 assert(player.main.innerHTML.includes("Best arena finish"));
 assert(player.main.innerHTML.includes("Arena participations"));
 assert(!player.main.innerHTML.includes("Longest arena participation streak"));
 assert(!player.main.innerHTML.includes("Current arena participation streak"));
 assert(!player.main.innerHTML.includes("Tournament medals"));
 assert(player.main.innerHTML.includes("#5"));
 console.log("PASS player widget scores, icons, ranks, arena history without removed streaks");

 const tour=start("tournament-winners");await wait();
 assert.equal(tour.status.textContent,"LIVE");
 assert(tour.main.innerHTML.includes("Winter Kings 1"));
 assert(tour.main.innerHTML.includes("Completed 1 / 18"));
 assert(tour.main.innerHTML.includes("Medal page 1 / 2"),"Tied medals must paginate at three by default");
 assert(tour.main.innerHTML.includes("https://www.chess.com/tournament/winter-kings-1"));
 // Navigate beyond the former five-record cap.
 for(let i=0;i<6;i++){tour.buttons.get("#p2kwNextT").onclick();await wait();}
 assert(tour.main.innerHTML.includes("Winter Kings 7"));
 assert(tour.main.innerHTML.includes("Completed 7 / 18"));
 assert(tour.main.innerHTML.includes("Medal page 1 / 2"));
 tour.buttons.get("#p2kwPrevT").onclick();await wait();
 assert(tour.main.innerHTML.includes("Completed 6 / 18"));
 tour.search.value="Winter";tour.form.onsubmit({preventDefault(){}});await wait();
 assert(tour.main.innerHTML.includes("Winter Masters"));
 assert(tour.main.innerHTML.includes("in progress"));
 assert(!tour.main.innerHTML.includes("2026 Q4"));
 assert(tour.main.innerHTML.includes("Winter Kings 6"));
 tour.search.value="Alpha";tour.form.onsubmit({preventDefault(){}});await wait();
 assert(tour.main.innerHTML.includes("Medals for Alpha"));
 assert(tour.main.innerHTML.includes("🥇 Alpha"));
 assert(!tour.main.innerHTML.includes("Completed 6 / 18"),"Player results must replace, not append to, completed tournament");
 assert(!tour.main.innerHTML.includes("Medal page 1 / 2"));
 tour.buttons.get("#p2kwTourSearchNext").onclick();await wait();
 assert(tour.main.innerHTML.includes("Page 2 / 2 · 12 tournaments"));
 assert.equal(tour.foot.textContent,"");
 tour.buttons.get("#p2kwClearSearch").onclick();await wait();
 assert(tour.main.innerHTML.includes("Winter Kings 1"));
 assert(tour.main.innerHTML.includes("Completed 1 / 18"));
 console.log("PASS complete finished-tournament paging, real status search, replacement player results and medal pagination");
})().catch(e=>{console.error(e);process.exitCode=1});
