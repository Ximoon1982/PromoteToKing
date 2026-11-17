"use strict";
const fs=require("node:fs");
const vm=require("node:vm");
const assert=require("node:assert/strict");
const {spawnSync}=require("node:child_process");

const core=fs.readFileSync("assets/js/shared/events-showcase-core.js","utf8");
const line=fs.readFileSync("assets/js/events-showcase-line-v2122.js","utf8");
const card=fs.readFileSync("server/events-showcase/public/embed-card.php","utf8");
const linePage=fs.readFileSync("server/events-showcase/public/embed.php","utf8");
const fmtLine=(source,prefix)=>{
  const lines=source.split("\n").filter(s=>s.trimStart().startsWith(prefix));
  assert.equal(lines.length,1,prefix+" occurs exactly once");
  return lines[0].trim();
};
const coreCompact=fmtLine(core,"function formatStartCompact(epoch)");
const coreFull=fmtLine(core,"function formatStart(epoch)");
const startUrgency=fmtLine(core,"function startUrgency(epoch)");
const cardDaily=fmtLine(card,"function formatCardDailyDate(epoch)");
const cardArena=fmtLine(card,"function formatArenaStart(start)");
const lineStart=line.indexOf("function formatArenaStart(start) {");
const lineEnd=line.indexOf("function renderArenas(",lineStart);
assert(lineStart>=0&&lineEnd>lineStart,"line-style arena formatter exists");
const lineArena=line.slice(lineStart,lineEnd).trim();
assert(!coreCompact.includes("timeZone:")&&!coreCompact.includes("timeZoneName:"),
  "Shared compact Daily date must not force a timezone or show CET suffix");
assert(!cardDaily.includes("timeZone:")&&!cardDaily.includes("timeZoneName:"),
  "Card-style Daily visible date must remain compact");
assert(!cardArena.includes("timeZone:")&&!cardArena.includes("timeZoneName:"),
  "Card-style arena visible date/time must remain compact");
assert(!lineArena.includes("timeZone:")&&!lineArena.includes("timeZoneName:"),
  "Line-style arena visible date/time must not include CET suffix");
assert(coreFull.includes('timeZoneName:"short"'),
  "Full tooltip must retain viewer-local timezone detail");
assert(line.includes('title="'+"$"+"{api.escapeHTML(api.formatStart(timing.start/1000))}"+'"'),
  "Line-style arena tooltip must retain full timestamp");
assert(line.includes('title="'+"$"+"{api.escapeHTML(urgency.title)}"+'"'),
  "Line-style Daily tooltip must retain full timestamp");
assert(card.includes('fullStart=CORE.formatStart(timing.start/1000)'),
  "Card-style arena tooltip must retain full timestamp");
assert(linePage.includes("events-showcase-core.js?v=p2k-2.15.11-compact-local-2"),
  "Line iframe must load the fixed shared compact core with fresh cache key");
assert(linePage.includes("events-showcase-line-v2122.js?v=p2k-2.15.11-compact-local-2"),
  "Line iframe must load the fixed visible arena formatter with fresh cache key");
assert(card.includes("events-showcase-core.js?v=p2k-2.15.11-compact-local-2"),
  "Card iframe must load same fixed shared core with fresh cache key");
const program=[coreFull,coreCompact,startUrgency,cardDaily,cardArena,lineArena].join("\n");

if(process.argv[2]==="--child"){
  const dateNow=new Date("2026-11-17T18:00:00Z");
  const NativeDate=Date;
  class FixedDate extends NativeDate{
    constructor(...args){super(...(args.length?args:[dateNow.getTime()]));}
    static now(){return dateNow.getTime();}
  }
  const ctx=vm.createContext({Date:FixedDate,Intl,Number,Math,String});
  vm.runInContext(program,ctx,{timeout:4000});
  const dates=[
    "2027-01-15T18:00:00Z",
    "2027-07-15T18:00:00Z",
    "2027-10-31T00:30:00Z",
    "2027-10-31T01:30:00Z"
  ];
  const rows=dates.map(iso=>{
    const ms=NativeDate.parse(iso),s=ms/1000;
    const shownLineDaily=vm.runInContext("formatStartCompact("+s+")",ctx);
    const shownCardDaily=vm.runInContext("formatCardDailyDate("+s+")",ctx);
    const shownLineArena=vm.runInContext("formatArenaStart("+ms+")",ctx);
    const shownCardArena=vm.runInContext("formatArenaStart("+ms+")",ctx);
    // The card variant uses the same named function; execute it independently below.
    const visibleCardArena=vm.runInNewContext(cardArena+";formatArenaStart("+ms+")",{Date:FixedDate,Intl,Number,Math,String});
    const full=vm.runInContext("formatStart("+s+")",ctx);
    const urgency=vm.runInContext("startUrgency("+s+")",ctx);
    assert.equal(shownLineDaily,shownCardDaily,"Line and card Daily labels must match");
    assert.equal(visibleCardArena,
      new Intl.DateTimeFormat("en-GB",{day:"numeric",month:"short"}).format(new NativeDate(ms))+
      " · "+new Intl.DateTimeFormat("en-GB",{hour:"2-digit",minute:"2-digit",hour12:false}).format(new NativeDate(ms)));
    assert(!/(?:CET|CEST|GMT[+\-]\d+|UTC|EST|EDT|JST)/.test(shownLineDaily));
    assert(!/(?:CET|CEST|GMT[+\-]\d+|UTC|EST|EDT|JST)/.test(shownLineArena));
    assert(!/(?:CET|CEST|GMT[+\-]\d+|UTC|EST|EDT|JST)/.test(visibleCardArena));
    assert.equal(urgency.label,shownLineDaily,"Distant Daily match visible label uses fixed compact formatter");
    assert.equal(urgency.title,full,"Distant Daily match tooltip retains full time");
    assert.match(full,/2027/);
    return{iso,shownLineDaily,shownLineArena,visibleCardArena,full};
  });
  const todayLocal=new FixedDate(dateNow.getFullYear(),dateNow.getMonth(),dateNow.getDate(),12).getTime();
  const tomorrowLocal=new FixedDate(dateNow.getFullYear(),dateNow.getMonth(),dateNow.getDate()+1,12).getTime();
  const today=vm.runInContext("formatArenaStart("+todayLocal+")",ctx);
  const tomorrow=vm.runInContext("formatArenaStart("+tomorrowLocal+")",ctx);
  assert(today.startsWith("Today "),"Today label retained");
  assert(tomorrow.startsWith("Tomorrow "),"Tomorrow label retained");
  assert(!today.includes("CET")&&!tomorrow.includes("CET"),"Relative arena dates have no timezone suffix");
  process.stdout.write(JSON.stringify({rows,today,tomorrow}));
}else{
  const expected={
    "Europe/Luxembourg":[
      ["15 Jan","15 Jan 19:00","15 Jan · 19:00"],
      ["15 Jul","15 Jul 20:00","15 Jul · 20:00"],
      ["31 Oct","31 Oct 02:30","31 Oct · 02:30"],
      ["31 Oct","31 Oct 02:30","31 Oct · 02:30"]
    ],
    "America/New_York":[
      ["15 Jan","15 Jan 13:00","15 Jan · 13:00"],
      ["15 Jul","15 Jul 14:00","15 Jul · 14:00"],
      ["30 Oct","30 Oct 20:30","30 Oct · 20:30"],
      ["30 Oct","30 Oct 21:30","30 Oct · 21:30"]
    ],
    "Asia/Tokyo":[
      ["16 Jan","16 Jan 03:00","16 Jan · 03:00"],
      ["16 Jul","16 Jul 03:00","16 Jul · 03:00"],
      ["31 Oct","31 Oct 09:30","31 Oct · 09:30"],
      ["31 Oct","31 Oct 10:30","31 Oct · 10:30"]
    ]
  };
  for(const [zone,labels] of Object.entries(expected)){
    const r=spawnSync(process.execPath,[__filename,"--child"],{
      cwd:process.cwd(),env:{...process.env,TZ:zone},encoding:"utf8",timeout:12000
    });
    assert.equal(r.status,0,zone+": "+r.stderr);
    const {rows,today,tomorrow}=JSON.parse(r.stdout);
    assert.equal(rows.length,labels.length);
    rows.forEach((row,i)=>{
      assert.equal(row.shownLineDaily,labels[i][0],zone+" Daily compact "+row.iso);
      assert.equal(row.shownLineArena,labels[i][1],zone+" line arena compact "+row.iso);
      assert.equal(row.visibleCardArena,labels[i][2],zone+" card arena compact "+row.iso);
      assert.match(row.full,/(?:GMT|CET|CEST|JST|EST|EDT|UTC)/,
        zone+" full timestamp retains timezone "+row.iso);
    });
    console.log("PASS "+zone+": line/card compact times, DST folds and full tooltips; "+today+"; "+tomorrow);
  }
  console.log("PASS both P2K Showcase layouts and Competition iframe retain local time without visible CET suffix");
}
