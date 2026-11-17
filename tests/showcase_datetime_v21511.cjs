"use strict";
const fs=require("node:fs");
const assert=require("node:assert/strict");
const code=fs.readFileSync("assets/js/admin/events-showcase-v2122.js","utf8");
const tz=Intl.DateTimeFormat().resolvedOptions().timeZone;
const between=(start,end)=>{
  const a=code.indexOf(start),b=code.indexOf(end,a+start.length);
  assert(a>=0&&b>a,"Expected Showcase function not found: "+start);
  return code.slice(a,b);
};
// Execute the actual render formatter, not just a static source assertion: the former
// dateStyle + timeZoneName combination threw "Invalid option : option" in production.
const arenaFormatter=between("function formatArenaStart(","function renderArenas(");
const formatArenaStart=new Function(arenaFormatter+"\nreturn formatArenaStart;")();
for(const iso of ["2027-01-15T18:00:00Z","2027-07-15T18:00:00Z","2027-10-31T00:30:00Z","2027-10-31T01:30:00Z"]){
  let shown;
  assert.doesNotThrow(()=>{shown=formatArenaStart({startAt:iso});});
  assert(shown&&shown!=="Start unavailable",tz+": display format "+iso);
}
assert.equal(formatArenaStart({startAt:"not-a-date"}),"Start unavailable");
// Simulate a populated administrator arena list; loading it must never throw.
const host={innerHTML:""},api={escapeHTML:value=>String(value)};
const config={arenas:[{active:true,name:"Test arena",link:"https://www.chess.com/play/arena/32145678",
  startAt:"2027-10-31T00:30:00Z",duration:"2h",timeControl:"3+2"}]};
const mockWindow={P2K_EVENTS_SHOWCASE_CORE:api};
const mockQuery=selector=>selector==="[data-es-arena-list]"?host:null;
const mockQueryAll=()=>[];
const listCode=between("function renderArenas(","function renderSelected(");
const renderArenas=new Function("window","config","normalizedArenas","q","$$",
  arenaFormatter+listCode+"\nreturn renderArenas;")(
  mockWindow,config,arenas=>arenas,mockQuery,mockQueryAll);
assert.doesNotThrow(()=>renderArenas(),"Showcase administration must render configured arenas");
assert(host.innerHTML.includes("Test arena")&&host.innerHTML.includes("2027"),
  "Rendered arena contains the name and date");
// Execute the actual DST chooser, exercising the two UTC offsets of a repeated hour.
const fields=new Map([
  ["[data-es-arena-date]",{value:""}],["[data-es-arena-time]",{value:""}],
  ["[data-es-arena-dst-group]",{hidden:true}],
  ["[data-es-arena-dst]",{value:"",innerHTML:""}],
  ["[data-es-arena-zone]",{textContent:""}],
]);
const q=selector=>{assert(fields.has(selector),"Unexpected editor selector "+selector);return fields.get(selector);};
const esc=value=>String(value);
const helpers=between("const ARENA_STORAGE_TIMEZONE=","const EMPTY_ARENA=");
const update=between("function updateArenaDstChoices(","function openArenaModal(");
const tools=new Function("q","esc",helpers+update+
  "\nreturn {updateArenaDstChoices,arenaLocalFields,arenaLocalCandidates,arenaUtcOffset,setOriginal:v=>{arenaEditorOriginalInstant=v;}};")(q,esc);
const iso="2027-07-15T18:00:00Z",local=tools.arenaLocalFields(iso);
fields.get("[data-es-arena-date]").value=local.date;
fields.get("[data-es-arena-time]").value=local.time;
tools.setOriginal(iso);
assert.doesNotThrow(()=>tools.updateArenaDstChoices(),"A normal local arena time must render");
assert(fields.get("[data-es-arena-zone]").textContent.includes(tz),
  "Local timezone is displayed");
if(tz==="Europe/Luxembourg"){
  fields.get("[data-es-arena-date]").value="2027-10-31";
  fields.get("[data-es-arena-time]").value="02:30";
  tools.setOriginal("2027-10-31T01:30:00Z");
  assert.doesNotThrow(()=>tools.updateArenaDstChoices(),"EU DST repeat must render");
  assert.equal(fields.get("[data-es-arena-dst-group]").hidden,false);
  assert.equal(fields.get("[data-es-arena-dst]").value,String(Date.parse("2027-10-31T01:30:00Z")));
  assert(fields.get("[data-es-arena-dst]").innerHTML.includes("UTC+01:00"));
  assert(fields.get("[data-es-arena-dst]").innerHTML.includes("UTC+02:00"));
  fields.get("[data-es-arena-date]").value="2027-03-28";
  fields.get("[data-es-arena-time]").value="02:30";
  assert.equal(tools.arenaLocalCandidates("2027-03-28","02:30").length,0);
}
if(tz==="America/New_York"){
  fields.get("[data-es-arena-date]").value="2027-11-07";
  fields.get("[data-es-arena-time]").value="01:30";
  tools.setOriginal("2027-11-07T06:30:00Z");
  assert.doesNotThrow(()=>tools.updateArenaDstChoices(),"US DST repeat must render");
  assert.equal(fields.get("[data-es-arena-dst-group]").hidden,false);
  assert(fields.get("[data-es-arena-dst]").innerHTML.includes("UTC-04:00"));
  assert(fields.get("[data-es-arena-dst]").innerHTML.includes("UTC-05:00"));
}
const registry=fs.readFileSync("assets/js/admin/tool-registry.js","utf8");
const html=fs.readFileSync("ui-v2.html","utf8");
assert(registry.includes("p2k-2.15.11-showcase-intl-1"),"JS cache key must change");
assert(html.includes("tool-registry.js?v=p2k-2.15.11-showcase-intl-1"),"admin page cache key must change");
assert(!code.includes('dateStyle:"medium",timeStyle:"short",timeZoneName:'),
  "Prevent reintroduction of unsupported Intl option combination");
console.log("PASS "+tz+": Showcase full list render, DST picker and local display format");
