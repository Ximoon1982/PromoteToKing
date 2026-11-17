"use strict";
const assert=require("node:assert/strict");
const fs=require("node:fs");
const s=fs.readFileSync("assets/js/pages/team-points-features.js","utf8");
const a=s.indexOf("  async function retryMcaSourceErrors() {");
const b=s.indexOf("  async function runMcaDateSync(",a);
assert(a>=0&&b>a,"MCA admin retry source not found.");
const src=s.slice(a,b);
assert(!src.includes("runMcaSourceSync("),"Retry must not launch competing manual sync.");
assert(!src.includes("sync_discovery"),"Retry must not force discovery.");
assert(!src.includes("sync_hydrate"),"Retry must not force hydration.");
assert(s.includes("MCA shared worker lock") && s.includes("worker_lock_held"),"MCA admin should expose read-only worker lock diagnostics.");
const status=[];
const state={mcaSyncRunning:false,mcaSync:{}};
const endpoint=async (path,args)=>{
  status.push(["call",args.action]);
  assert.equal(args.action,"sync_retry_download_errors");
  assert.equal(args.method,"POST");
  assert.equal(state.mcaSyncRunning,true);
  return {retry:{requeued:2},sync:{queue:{pending:2,error:0}}};
};
const renderMcaSync=()=>status.push(["render",state.mcaSyncRunning]);
const renderLive=()=>status.push(["data"]);
const message=(id,text,kind)=>status.push(["message",text,kind]);
const fmt=n=>String(n);
const retry=new Function("state","endpoint","renderMcaSync","renderLive","message","fmt","liveEndpoint",
  src+"\nreturn retryMcaSourceErrors;")(
  state,endpoint,renderMcaSync,renderLive,message,fmt,"live-ranks-admin.php");
retry().then(async ()=>{
  assert.equal(state.mcaSyncRunning,false);
  assert.equal(status.filter(x=>x[0]==="call").length,1);
  assert(status.some(x=>x[0]==="message"&&x[1].includes("2 failed arena acquisition(s) queued")&&x[1].includes("saved pages")));
  assert(status.some(x=>x[0]==="message"&&x[1].includes("no competing manual scan")));
  assert(status.at(-1)[0]==="message"&&status.at(-1)[2]==="success",
    "Success acknowledgement must survive final UI repaint");
  console.log("PASS MCA retry queues only, no competing sync, controls restored, acknowledgement retained");
}).catch(e=>{console.error(e);process.exitCode=1;});
