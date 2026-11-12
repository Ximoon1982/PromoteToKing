/* Regression: bounded CSRF recovery and concurrency for recruitment history writes. */
"use strict";
const fs = require("fs");
const vm = require("vm");
const assert = require("assert");
const path = require("path");
const source = fs.readFileSync(path.join(__dirname,"../assets/js/shared/team-points-client.js"),"utf8");

function loadClient(options = {}) {
  let csrf = "initial-csrf";
  let sessions = 0, writes = 0, profiles = 0;
  const response = (status, payload) => ({
    ok: status >= 200 && status < 300, status,
    json: async () => payload,
  });
  const window = {
    P2K_SITE_CONFIG: {serverStorage: {
      teamPointsSessionEndpoint: "/server/team-points/public/session.php",
      teamPointsEndpoint: "/server/team-points/public/api.php",
    }},
    location: {href:"https://www.promotetoking.org/RecruitMatch.html"},
    P2K_AUTH: {
      getAdminBootstrap: () => "signed-oauth-assertion",
      getAdminBootstrapAgeMs: () => 0,
    },
    setTimeout,clearTimeout,
    addEventListener:()=>{},dispatchEvent:()=>{},
  };
  const fetch = async (url, init) => {
    const u=new URL(url);
    if(u.pathname.endsWith("/session.php")) {
      sessions++;
      await Promise.resolve();
      return response(200,{ok:true,username:"ximoon",csrf});
    }
    if(u.pathname.endsWith("/recruitment-history.php") || u.pathname.endsWith("/api.php")) {
      writes++;
      await Promise.resolve();
      if(options.deny) return response(403,{ok:false,error:{code:"ADMIN_AUTH_FAILED",message:"Access denied"}});
      if(options.permanentCsrf) return response(403,{ok:false,error:{code:"CSRF_VALIDATION_FAILED",message:"Invalid CSRF"}});
      if(options.expire && writes === 1) return response(401,{ok:false,error:{code:"ADMIN_SESSION_REQUIRED",message:"Session expired"}});
      if(init.headers["X-P2K-CSRF"]!==csrf) return response(403,{ok:false,error:{code:"CSRF_VALIDATION_FAILED",message:"Invalid CSRF"}});
      return response(200,{ok:true,entry:{id:"history-row-"+writes}});
    }
    profiles++;
    return response(404,{ok:false});
  };
  const sandbox={window,fetch,URL,AbortController,CustomEvent:class {}};
  vm.runInNewContext(source,sandbox,{filename:"team-points-client.js"});
  return {
    client: window.P2K_TEAM_POINTS_CLIENT,
    changeCsrf(value) {csrf=value;},
    counts() {return {sessions,writes,profiles}},
  };
}
const endpoint="server/team-points/public/recruitment-history.php?action=archive";
const body={match_id:1234,match_name:"Example match",csv:"username,rating\nalpha,1400\n"};
const send = client=>client.endpointRequest(endpoint,{method:"POST",body,requestKind:"recruitment-history"});

async function tests() {
  // Regression for previous 2.15.2 issue: a different tab rotates the PHP
  // session while a long scan is underway; the last archive request must
  // recover without the user losing a completed result.
  {
    const x=loadClient();
    await x.client.connect();
    x.changeCsrf("refreshed-by-other-tab");
    const result=await send(x.client);
    assert.equal(result.ok,true);
    assert.deepEqual(x.counts(),{sessions:2,writes:2,profiles:0});
    console.log("PASS stale-CSRF recruitment archive reconnects once and succeeds");
  }
  {
    const x=loadClient();
    await x.client.connect();
    x.changeCsrf("refreshed-by-other-tab");
    const [a,b]=await Promise.all([send(x.client),send(x.client)]);
    assert(a.ok && b.ok);
    assert.deepEqual(x.counts(),{sessions:2,writes:4,profiles:0});
    console.log("PASS concurrent stale requests share exactly one session refresh");
  }
  {
    const x=loadClient({permanentCsrf:true});
    await x.client.connect();
    await assert.rejects(()=>send(x.client),error=>error.code==="CSRF_VALIDATION_FAILED");
    assert.deepEqual(x.counts(),{sessions:2,writes:2,profiles:0});
    console.log("PASS persistent CSRF errors stop after one safe replay");
  }
  {
    const x=loadClient({deny:true});
    await x.client.connect();
    await assert.rejects(()=>send(x.client),error=>error.code==="ADMIN_AUTH_FAILED");
    assert.deepEqual(x.counts(),{sessions:1,writes:1,profiles:0});
    console.log("PASS real authorization denial is never retried");
  }
  {
    const x=loadClient({expire:true});
    await x.client.connect();
    assert.equal((await send(x.client)).ok,true);
    assert.deepEqual(x.counts(),{sessions:2,writes:2,profiles:0});
    console.log("PASS expired Team Points admin session can reconnect and archive");
  }
}
tests().catch(e=>{console.error(e);process.exit(1)});
