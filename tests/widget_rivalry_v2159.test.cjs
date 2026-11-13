"use strict";
const assert=require("node:assert/strict"),fs=require("node:fs"),vm=require("node:vm"),path=require("node:path");
const source=fs.readFileSync(path.join(__dirname,"../assets/js/widgets/p2k-rivalry-v2159.js"),"utf8");
const calls=[],buttons={};
const control=()=>({innerHTML:"",textContent:"",value:"",onsubmit:null,onclick:null,listeners:{},
 addEventListener(type,cb){this.listeners[type]=cb},fire(type){this.listeners[type]?.({target:this})}});
const searchInput=control(),form=control(),suggest=control(),status=control(),foot=control();
const scopeControls=["total","friendly","league"].map(name=>Object.assign(control(),{dataset:{rivalScope:name}}));
const holder={innerHTML:"",
 querySelector(selector){
  if(!this.innerHTML.includes(selector.replace(/^\[|]$/g,"").split("=")[0]))return null;
  return buttons[selector]??=control();
 },
 querySelectorAll(selector){
  if(selector==="[data-rival-slug]"){
   const names=[...this.innerHTML.matchAll(/data-rival-slug="([^"]+)"/g)].map(x=>x[1]);
   return names.map((name,i)=>Object.assign(control(),{dataset:{rivalSlug:name}}));
  }
  if(selector==="[data-rival-scope]"){
   return scopeControls;
  }
  return [];
 }
};
const suggestControl=Object.assign(suggest,{
 querySelectorAll(selector){
  if(selector!=="[data-rival-suggestion]")return [];
  return [...this.innerHTML.matchAll(/data-rival-suggestion="([^"]+)"/g)].map(x=>Object.assign(control(),{dataset:{rivalSuggestion:x[1]}}));
 }
});
const main={innerHTML:"",scrollTop:0,
 querySelector(selector){
  if(selector==="#p2kwRivalSearch")return searchInput;
  if(selector==="#p2kwRivalForm")return form;
  if(selector==="#p2kwRivalSuggest")return suggestControl;
  if(selector==="#p2kwRivalContents")return holder;
  return null;
 }
};
const document={body:{dataset:{widget:"rivalry-spotlight",preview:"0"}},
 currentScript:{src:"https://www.promotetoking.org/assets/js/widgets/p2k-rivalry-v2159.js"},
 getElementById(id){return {p2kwContent:main,p2kwStatus:status,p2kwFoot:foot}[id]||null}};
const entry=i=>({slug:"opponent-"+i,name:"Opponent "+i,total:200-i,
 avatar:"https://images.chesscomfiles.com/test"+i+".png",
 url:"https://www.chess.com/club/opponent-"+i,ongoing:1,registered:0});
const finished={total:{matches:20,wins:8,draws:5,losses:6,covered:19},
 friendly:{matches:10,wins:5,draws:2,losses:3,covered:10},
 league:{matches:10,wins:3,draws:3,losses:3,covered:9}};
const fetch=async input=>{
 const u=new URL(String(input)),action=u.searchParams.get("action");
 assert.equal(u.hostname,"www.promotetoking.org","No visitor-side Chess.com request");
 calls.push({action,page:Number(u.searchParams.get("page")),query:u.searchParams.get("query"),slug:u.searchParams.get("slug")});
 let output;
 if(action==="config")output={ok:true,settings:{enabled:true,title:"Rivalry Spotlight"}};
 else if(action==="rivalry-page"){
   const page=Number(u.searchParams.get("page"))||1;
   output={ok:true,rows:page===1?Array.from({length:25},(_,i)=>entry(i+1)):[entry(26),entry(27),entry(28)],
     summary:{different_opponents:28,currently_playing:5},
     pagination:{page,total_pages:2,total_rows:28,page_size:25}};
 }else if(action==="rivalry-detail"){
   const slug=u.searchParams.get("slug");
   output={ok:true,opponent:{...entry(slug==="opponent-12"?12:1)},
     results:finished,coverage:{missing:1,category_gap:0}};
 }else if(action==="rivalry-search"){
   output={ok:true,results:[entry(12),entry(13),entry(14)]};
 }else throw Error("Unexpected action "+action);
 return {ok:true,status:200,json:async()=>output};
};
const ctx={document,window:{},URL,Intl,AbortController,console,Date,Math,Number,String,Error,Promise,setTimeout,clearTimeout,fetch};
vm.runInNewContext(source,ctx,{filename:"p2k-rivalry-v2159.js"});
const wait=()=>new Promise(resolve=>setTimeout(resolve,45));
(async()=>{
 await wait();
 assert(holder.innerHTML.includes("Total opponents"));
 assert(holder.innerHTML.includes("Currently playing"));
 assert(holder.innerHTML.includes("28")&&holder.innerHTML.includes("5"));
 assert.equal((holder.innerHTML.match(/data-rival-slug=/g)||[]).length,5,"Compact list has five opponents");
 assert(holder.innerHTML.includes("show more"));
 assert(!foot.textContent.includes("Green Analytics"));
 assert(holder.innerHTML.includes("p2kw-rival-avatar"),"Directory avatars rendered");
 assert.equal(calls.filter(x=>x.action==="rivalry-page").length,1);
 holder.querySelector("[data-rival-expand]").listeners.click();
 await wait();
 assert.equal((holder.innerHTML.match(/data-rival-slug=/g)||[]).length,25,"Expanded list shows 25");
 assert(holder.innerHTML.includes("Page 1 / 2"));
 holder.querySelector("[data-rival-next]").listeners.click();await wait();
 assert(holder.innerHTML.includes("Opponent 28"),"All pages accessible beyond initial five");
 assert.equal((holder.innerHTML.match(/data-rival-slug=/g)||[]).length,3);
 holder.querySelector("[data-rival-collapse]").listeners.click();await wait();
 assert.equal((holder.innerHTML.match(/data-rival-slug=/g)||[]).length,5);
 searchInput.value="https://www.chess.com/club/opponent-12";
 await form.onsubmit({preventDefault(){}});
 await wait();
 assert(calls.some(c=>c.action==="rivalry-search"&&c.query.includes("chess.com/club")));
 assert(calls.some(c=>c.action==="rivalry-detail"&&c.slug==="opponent-12"),"Exact Chess.com URL resolves to details");
 assert(holder.innerHTML.includes("Recorded encounters"));
 assert(holder.innerHTML.includes('<a class="p2kw-rival-name-link" href="https://www.chess.com/club/opponent-12"'),"Selected opponent name is a direct link to the Chess.com club");
 assert(holder.innerHTML.includes('>Opponent 12</a>'),"The linked text is the club name");
 assert(!holder.innerHTML.includes("Chess.com club")&&!holder.innerHTML.includes("p2kw-rival-clublink"),"Old bottom club link removed");
 assert(holder.innerHTML.includes("p2kw-rival-resultbar"));
 assert(holder.innerHTML.includes("data-rival-scope=\"total\""));
 assert(holder.innerHTML.includes("8</strong>"),"Total results preserve eight wins");
 const scope=holder.querySelectorAll("[data-rival-scope]")[2];
 scope.onclick();
 assert(holder.innerHTML.includes("League results")&&holder.innerHTML.includes("3</strong>"),"League breakdown changes results");
 assert(holder.innerHTML.includes("9 / 10 finished matches"));
 holder.querySelector("[data-rival-back]").listeners.click();
 assert(holder.innerHTML.includes("Most frequent opponents"),"Back restores the directory");
 searchInput.value="oppnent";
 searchInput.fire("input");
 await new Promise(resolve=>setTimeout(resolve,330));
 assert(suggest.innerHTML.includes("Closest clubs"),"Several suggestions shown for approximate names");
 assert.equal((suggest.innerHTML.match(/data-rival-suggestion=/g)||[]).length,3);
 const admin=fs.readFileSync(path.join(__dirname,"../assets/js/admin/widgets-v2153.js"),"utf8");
 assert(admin.includes("'rivalry-spotlight':540"),"Exactly fixed 650px height");
 assert(admin.includes("'rivalry-spotlight':{}"),"No imposed opponent limit");
 console.log("PASS Rivalry unlimited directory paging, stats, fuzzy/URL search, avatar, WDL scopes and fixed iframe");
})().catch(e=>{console.error(e);process.exitCode=1});
