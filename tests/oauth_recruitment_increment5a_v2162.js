"use strict";
const assert=require("node:assert/strict"),fs=require("node:fs"),vm=require("node:vm"),path=require("node:path");
const root=path.resolve(__dirname,"..");
const C=require(path.join(root,"assets/js/pages/recruit-match-v2-core.js"));
const source=fs.readFileSync(path.join(root,"assets/js/pages/recruit-match.js"),"utf8");
const part=source.slice(source.indexOf("async function live("),source.indexOf("async function enrichLoads("));
assert(part.includes("function verifyLive"));
const now=Math.floor(Date.now()/1000),calls=[];
let profile={last_online:now},stats={chess_daily:{record:{timeout_percent:1}}},profileError=null;
const window={P2K_API_CLIENT:{userMessage:e=>e.message}};
const api=async(url,signal,mode)=>{assert.equal(mode,"no-store");calls.push(url);if(signal.aborted)throw new DOMException("Aborted","AbortError");if(url.endsWith("/stats"))return stats;if(profileError)throw profileError;return profile};
const ctx=vm.createContext({C,window,api,Date,Math,Number,encodeURIComponent,Promise,DOMException});
vm.runInContext(part+";globalThis.test={live,verifyLive};",ctx);
const row={username:"example",rating:1500},signal=new AbortController().signal;
async function run(days=1){calls.length=0;const criteria={onlineDays:days,maxTimeout:5};const live=await ctx.test.live(row,signal,criteria);return ctx.test.verifyLive(row,live,criteria)}
(async()=>{
 profile={last_online:now-8*86400};let result=await run();assert.equal(result.decision,"excluded");assert.equal(result.live.timeout_percent,null);assert.equal(calls.length,1);assert(!calls[0].endsWith("/stats"));
 profile={last_online:now};result=await run();assert.equal(result.decision,"eligible");assert.equal(calls.length,2);
 profile={};result=await run();assert.equal(result.decision,"unverified");assert.equal(calls.length,2);
 profile={last_online:now};stats={chess_daily:{record:{timeout_percent:8}}};result=await run();assert.equal(result.decision,"excluded");assert.equal(calls.length,2);
 profile={last_online:now-60*86400};result=await run(30);assert.equal(result.decision,"excluded");assert.equal(calls.length,2);
 profileError=Object.assign(Error("deferred"),{deferred:true});await assert.rejects(run(),/deferred/);assert.equal(calls.length,1);
 console.log("PASS P2K DB-first recruitment: activity short circuit, current-only verified inputs, timeout exclusion, broad-window and deferred behavior");
})().catch(e=>{console.error(e);process.exitCode=1});
