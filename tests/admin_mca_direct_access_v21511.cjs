"use strict";
const assert=require("node:assert/strict");
const fs=require("node:fs");
const shell=fs.readFileSync("assets/js/admin/admin-shell.js","utf8");
const ui=fs.readFileSync("ui-v2.html","utf8");
assert(ui.includes("assets/js/admin/admin-shell.js?v=p2k-2.15.11-direct-mca-admin-1"),
  "Admin shell cache must be refreshed for the promoted release.");
const start=shell.indexOf("function renderAdminShellDetail(){");
const end=shell.indexOf("function adminShellActivate(){",start);
assert(start>=0&&end>start,"Canonical admin detail render function exists");
const functionSource=shell.slice(start,end);
const ids=["adminShellDetail","adminShellDetailFrame","adminShellDetailFrameWrap","adminShellNativeDetailHost",
  "adminShellDetailStandalone","adminShellDetailTitle","adminShellDetailBreadcrumb",
  "adminShellOverallStatus","adminShellUpdated","adminShellDetailTabs"];
const nodes=new Map(ids.map(id=>[id,{hidden:false,href:"",src:"",dataset:{},style:{},textContent:"",
  innerHTML:"",title:"",replaceChildren(){},removeAttribute(){}}]));
const byId=id=>nodes.get(id)||null;
const setText=(id,value)=>{assert(nodes.has(id),"Unexpected text id "+id);nodes.get(id).textContent=value};
const escapeHTML=value=>String(value).replace(/&/g,"&amp;").replace(/"/g,"&quot;");
const state={category:"competitions",adminDetail:"mca",adminDetailTab:"source",adminToolTab:"",
  adminShellLoadedAt:0};
const def={title:"Multi Club Arenas",tabs:[
  {key:"source",label:"MCA source data",src:"TeamPointsAdmin.html?embedded=1&tab=live-ranks&release=2.10.6.14"}]};
let activeFrame="";
const local={href:"https://www.promotetoking.org/ui-v2.html?view=admin"};
const render=new Function(
  "state","byId","adminDetailDefinition","setText","escapeHTML","adminShellHref",
  "setIntegratedFrameActivity","applyOAuthContext","ensureIntegratedFrame","window","location",
  functionSource+"\nreturn renderAdminShellDetail;")(
  state,byId,()=>def,setText,escapeHTML,()=>"ui-v2.html",
  ()=>{},url=>url,id=>{activeFrame=id;},{},local);
assert.doesNotThrow(render,"Admin MCA detail must render without throwing");
const direct=nodes.get("adminShellDetailStandalone");
assert.equal(direct.hidden,false,"MCA fallback action must be visible");
const url=new URL(direct.href);
assert.equal(url.pathname,"/TeamPointsAdmin.html");
assert.equal(url.searchParams.get("tab"),"live-ranks","Direct access must select MCA Live ranks");
assert.equal(url.searchParams.get("embedded"),null,"Direct access must not be embedded");
assert.equal(url.searchParams.get("active"),null,"Direct access must not be forced active");
assert.match(direct.textContent,/Open MCA administration directly/);
const iframeUrl=new URL(nodes.get("adminShellDetailFrame").src);
assert.equal(iframeUrl.searchParams.get("tab"),"live-ranks");
assert.equal(iframeUrl.searchParams.get("embedded"),"1");
assert.equal(iframeUrl.searchParams.get("active"),"1");
assert.equal(activeFrame,"adminShellDetailFrame","Embedded UI must remain enabled");
assert.equal(nodes.get("adminShellOverallStatus").textContent,"Viewing Multi Club Arenas",
  "Direct detail routes should not leave misleading permanent loading text");
assert.match(nodes.get("adminShellUpdated").textContent,/returning to the cards/);
assert.equal(nodes.get("adminShellDetailFrameWrap").hidden,false,
  "Fallback must not replace the normal embedded administration");
assert.equal(nodes.get("adminShellDetailTabs").hidden,true,
  "Single tab source detail need not add a redundant tab list");
console.log("PASS MCA direct admin access, normal iframe preservation, deep-link status and cache identity");
