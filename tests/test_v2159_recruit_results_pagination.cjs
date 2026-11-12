/* Regression: only 50 rendered result rows; full-set search/sort/export; CSV retry after reload. */
"use strict";
const assert=require("assert"),fs=require("fs"),vm=require("vm"),path=require("path");
const helper=fs.readFileSync(path.join(__dirname,"../assets/js/pages/recruit-match-ui-helpers.js"),"utf8");
const controller=fs.readFileSync(path.join(__dirname,"../assets/js/pages/recruit-match.js"),"utf8");
const html=fs.readFileSync(path.join(__dirname,"../RecruitMatch.html"),"utf8");
function makeStorage(){
  const values=new Map();
  const db={
    objectStoreNames:{contains:()=>true},createObjectStore:()=>{},
    close:()=>{},
    transaction:(_store,_mode)=>{
      const tx={oncomplete:null,onabort:null,onerror:null};
      tx.objectStore=()=>({
        get:key=>schedule(values.get(key)),
        put:(value,key)=>schedule(values.set(key,value)),
        delete:key=>schedule(values.delete(key))
      });
      function schedule(result){
        const request={result,onerror:null,onsuccess:null};
        queueMicrotask(()=>{
          if(typeof request.onsuccess==="function")request.onsuccess();
          queueMicrotask(()=>{if(typeof tx.oncomplete==="function")tx.oncomplete()});
        });
        return request;
      }
      return tx;
    }
  };
  return {open:()=>{
    const request={result:db,onupgradeneeded:null,onsuccess:null,onerror:null,onblocked:null};
    queueMicrotask(()=>{request.onsuccess?.()});
    return request;
  },values};
}
function load(indexedDB){
  const window={indexedDB};vm.runInNewContext(helper,{window,console,Date,Promise,Number,String,Math,Error}, {filename:"recruit-match-ui-helpers.js"});
  return window.P2K_RECRUIT_UI_HELPERS;
}
(async()=>{
  const storage=makeStorage(),ui=load(storage),rows=Array.from({length:1638},(_,i)=>({
    username:"player"+String(i).padStart(4,"0"),rating:i,
    last_online_age_hours:i,live:{timeout_percent:i%100,current_match_load:i}
  }));
  assert.equal(ui.PAGE_SIZE,50);
  assert.deepEqual(Array.from(ui.page(rows,1).items).map(x=>x.username).slice(0,2),["player0000","player0001"]);
  for(let p=1;p<=33;p++)assert(ui.page(rows,p,500).items.length<=50);
  assert.equal(ui.page(rows,33).items.length,38);
  assert.equal(ui.page(rows,100).page,33);
  assert.equal(ui.page([],1).from,0);
  assert.equal(ui.page(rows,2).from,51);
  assert.equal(ui.page(rows,2).to,100);
  assert.equal(ui.filterSort(rows,"player015","rating",-1)[0].username,"player0159");
  assert.equal(ui.filterSort(rows,"player015","rating",1)[0].username,"player0150");
  assert.equal(ui.page(ui.filterSort(rows,"player015","rating",1),1).total,10);
  console.log("PASS 50-row bound, last/empty pages, full-list search and sort");

  const saved=await ui.pending.save({id:"scan-2019306",match:{id:2019306,name:"Example"},csv:"username,rating\\nalpha,1200\\n"});
  assert.equal(saved.id,"scan-2019306");
  // Independent page-load context, same IndexedDB store.
  const next=load(storage);
  assert.equal((await next.pending.load()).csv,saved.csv);
  await next.pending.clear("wrong-scan");
  assert.equal((await next.pending.load()).id,"scan-2019306");
  await next.pending.clear("scan-2019306");
  assert.equal(await next.pending.load(),null);
  console.log("PASS failed CSV restored after page reload; success clears only matching scan");

  assert(html.includes("recruit-match-ui-helpers.js"));
  assert(html.includes('id="p2kRetryRecruitmentArchive"'));
  assert(html.includes('id="p2kDownloadPendingRecruitment"'));
  assert(controller.includes("UI.page(filtered,state.resultPage,UI.PAGE_SIZE)"));
  assert(controller.includes("UI.filterSort(eligible,search.value,state.sort,state.direction)"));
  assert(controller.includes("downloadCsv(csvText,state.id)"));
  assert(controller.includes("await UI.pending.save(pending)"));
  assert(controller.includes("void restorePendingArchive()"));
  assert(controller.includes("await UI.pending.clear(record.id)"));
  assert(controller.includes("buttons.last.onclick"));
  console.log("PASS UI integration: archive-retry, download and global export are independent of page slice");
})().catch(e=>{console.error(e);process.exitCode=1});
