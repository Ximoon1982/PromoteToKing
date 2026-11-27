"use strict";
const assert=require("node:assert/strict"),fs=require("node:fs"),path=require("node:path"),vm=require("node:vm");
const storage=new Map([["p2k-oauth-gateway-tuning-v4",JSON.stringify({updatedAt:Date.now(),target:12,rateTarget:120,safeRateTarget:100,unsafeRateTarget:80,bestTargetRate:100})]]);
const window={location:{href:"https://p2k.test/FindMatch.htm",search:""},setTimeout,clearTimeout,dispatchEvent(){},P2K_AUTH:{realOAuth:true,getCsrf:()=>"fixture-csrf",getSession:()=>({oauthVerified:true})}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,"../assets/js/shared/api-oauth-context.js"),"utf8"),{window,URL,URLSearchParams,Response,Headers,AbortController,CustomEvent:class{},sessionStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v)}});
let posts=0;
const ratePolicy={revision:1,initial_rate_cps:24,maximum_rate_cps:30,minimum_rate_cps:1,unsafe_boundary_factor:.9,recovery_hold_seconds:60};
const controller=(at,target,unsafe,ceiling)=>({rate_policy:ratePolicy,observed_at_ms:at,rate_target_cps:target,safe_rate_cps:target,unsafe_rate_cps:unsafe,effective_ceiling_cps:ceiling,observed_launch_cps:20,observed_clean_cps:19});
const ctx=window.P2K_API_MODULES.oauthContext.create({
  nativeFetch:async(url,options)=>{
    posts++;
    assert.equal(new URL(url).searchParams.get("action"),"batch");
    const body=JSON.parse(options.body);assert.equal(body.rate_cps,30);assert.equal(body.traffic_class,"foreground");
    return new Response(JSON.stringify({ok:true,controller:controller(300,22.5,25,22.5),rate_cps:22.5,launch_cps:19,results:body.requests.map(r=>({id:r.id,status:200,body:'{"ok":true}',headers:{"content-type":"application/json"}}))}),{status:200});
  },
  maxConcurrency:12,integer:(v,f,min,max)=>Math.max(min,Math.min(max,Number.isFinite(Number(v))?Math.trunc(Number(v)):f)),
  apiError:(msg,fields)=>Object.assign(Error(msg),fields),abortError:()=>Error("abort"),normalizeError:e=>e,
  emitConcurrencyChange(){},configuredConcurrency:()=>1,adaptiveConcurrency:()=>1
});
(async()=>{
  assert.equal(ctx.diagnostics().oauthGatewayRateTarget,24);
  ctx.setEnabled(true);
  assert(ctx.sessionActive());
  let d=ctx.diagnostics();assert.equal(d.oauthGatewayRateTarget,30);assert.equal(d.oauthGatewaySafeRateTarget,30);assert.equal(d.oauthGatewayUnsafeRateTarget,80);
  ctx.observeBatch({controller:controller(200,24,80,30)});
  d=ctx.diagnostics();assert.equal(d.oauthGatewayRateTarget,24);assert.equal(d.oauthGatewayEffectiveCeiling,30);assert.equal(d.oauthGatewayUnsafeRateTarget,80);
  ctx.observeBatch({controller:controller(100,30,0,30)});
  assert.equal(ctx.diagnostics().oauthGatewayRateTarget,24,"Older POST replaced current server state");
  const response=await ctx.executeGateway("https://api.chess.com/pub/player/fixture",{headers:new Headers(),timeoutMs:1000});
  assert.equal(response.status,200);assert.equal(posts,1);
  d=ctx.diagnostics();assert.equal(d.oauthGatewayRateTarget,22.5);assert.equal(d.oauthGatewayEffectiveCeiling,22.5);assert.equal(d.oauthGatewayUnsafeRateTarget,25);assert.equal(d.oauthGatewayObservedLaunchCps,20);assert.equal(d.oauthGatewayObservedCleanCps,19);assert.equal(d.oauthGatewayRatePolicy.maximum_rate_cps,30);assert.equal(d.oauthGatewayControllerObservedAtMs,300);
  ctx.observeBatch({controller:controller(400,22.5,0,30)});
  assert.equal(ctx.diagnostics().oauthGatewayEffectiveCeiling,30);assert.equal(ctx.diagnostics().oauthGatewayUnsafeRateTarget,0);
  console.log("PASS production client initial/maximum policy, cached state clamping, raw boundary, stale telemetry, actual gateway caller ceiling and distinct observations");
})().catch(e=>{console.error(e);process.exitCode=1;});
