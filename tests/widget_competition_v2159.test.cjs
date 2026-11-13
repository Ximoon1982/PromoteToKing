"use strict";
const assert=require("node:assert/strict"),fs=require("node:fs"),vm=require("node:vm"),path=require("node:path");
const root=path.join(__dirname,"..");
const read=p=>fs.readFileSync(path.join(root,p),"utf8");
const source=read("assets/js/widgets/p2k-competition-v2159.js");
const showcase=read("server/events-showcase/public/embed-card.php");
const embed=read("server/widgets/public/embed.php");
const admin=read("assets/js/admin/widgets-v2153.js");
const css=read("assets/css/widgets/p2k-competition-v2159.css");
class Node{
 constructor(tag){this.tagName=tag;this.children=[];this.listeners={};this.attrs={};
   this.style={};this.dataset={};this.hidden=false;this.tabIndex=-1;this.scrollTop=23;
   this.textContent="";this.innerHTML="";this.focused=false;}
 append(child){this.children.push(child);}
 addEventListener(name,fn){this.listeners[name]=fn;}
 setAttribute(name,value){this.attrs[name]=String(value);}
 getAttribute(name){return this.attrs[name]??null;}
 focus(){this.focused=true;}
}
function run(mode="0",theme="dark",widget="competition"){
 const main=new Node("main"),status=new Node("span"),nav=new Node("nav"),chrome=new Node("div");
 const head=new Node("header"),foot=new Node("footer"),panels=new Map(),tabs=new Map();
 for(const name of ["showcase","match-calls","rivals"]){
   panels.set(name,new Node("section"));tabs.set(name,new Node("button"));
 }
 main.parentNode={
   querySelector(selector){return selector===".p2kw-head"?head:null;},
   insertBefore(child,target){assert.strictEqual(child,chrome);assert.strictEqual(target,main);}
 };
 main.querySelector=selector=>{
   const match=selector.match(/^#p2kwCompetitionPanel-(showcase|match-calls|rivals)$/);
   return match?panels.get(match[1]):null;
 };
 nav.querySelector=selector=>{
   const match=selector.match(/^#p2kwCompetitionTab-(showcase|match-calls|rivals)$/);
   return match?tabs.get(match[1]):null;
 };
 const document={
   body:{dataset:{widget,preview:mode}},
   currentScript:{src:"https://www.promotetoking.org/assets/js/widgets/p2k-competition-v2159.js"},
   documentElement:{className:"p2kw-theme-"+theme},
   getElementById(id){return {p2kwContent:main,p2kwStatus:status,p2kwFoot:foot}[id]??null;},
   createElement(tag){return tag==="nav"?nav:tag==="div"?chrome:new Node(tag);}
 };
 vm.runInNewContext(source,{document,URL,Map,console,Array,String,Number},{filename:"p2k-competition-v2159.js"});
 return {main,status,nav,chrome,head,foot,panels,tabs};
}
const fixture=run(),{main,status,nav,chrome,head,foot,panels,tabs}=fixture;
assert.equal(status.textContent,"Loading…","Do not duplicate the selected tab in status");
assert.equal(chrome.className,"p2kw-competition-chrome");
assert.deepEqual(chrome.children,[head,nav],"Header and selector share one opaque box");
assert.equal(nav.className,"p2kw-hof-tabs p2kw-competition-tabs");
assert.equal(nav.getAttribute("role"),"tablist");
assert(!main.innerHTML.includes("p2kw-competition-tabs"),"Selector sits outside scrolling region");
assert.equal(foot.className,"p2kw-foot p2kw-competition-assistant");
assert.equal(foot.children.length,1,"One permanent footer button");
assert.equal(foot.hidden,false);
assert.equal(foot.children[0].textContent,"Open P2K match selection assistant");
assert.equal(foot.children[0].href,"https://www.promotetoking.org/");
assert.equal(foot.children[0].target,"_blank");
assert.equal(foot.children[0].rel,"noopener noreferrer");
assert.equal(panels.get("showcase").children.length,1);
assert.equal(panels.get("match-calls").children.length,0,"Radar is loaded lazily");
assert.equal(panels.get("rivals").children.length,0,"Rivalry is loaded lazily");
const showcaseFrame=panels.get("showcase").children[0];
const showcaseURL=new URL(showcaseFrame.src);
assert.equal(showcaseURL.pathname,"/server/events-showcase/public/embed-card.php");
assert.equal(showcaseURL.searchParams.get("theme"),"dark");
assert.equal(showcaseURL.searchParams.get("via"),"competition");
assert.equal(showcaseFrame.height,"565");
assert.equal(showcaseFrame.style.height,"100%");
assert.equal(showcaseFrame.title,"Current P2K Match Showcase");
showcaseFrame.contentDocument={documentElement:{getAttribute(){return "loading";}}};
showcaseFrame.listeners.load();
assert.equal(status.textContent,"Loading…");
showcaseFrame.contentDocument={documentElement:{getAttribute(){return "live";}}};
showcaseFrame.listeners.load();
assert.equal(status.textContent,"LIVE","Status reflects Showcase data ready");
tabs.get("match-calls").listeners.click();
assert.equal(status.textContent,"Loading…","Newly selected Radar is still loading");
assert.equal(foot.hidden,false,"Showcase and Radar share footer position");
assert.equal(panels.get("showcase").hidden,true);
const radarPane=panels.get("match-calls");
assert.equal(radarPane.children.length,1,"No button below child iframe");
const radarFrame=radarPane.children[0],radarURL=new URL(radarFrame.src);
assert.equal(radarURL.pathname,"/server/widgets/public/embed.php");
assert.equal(radarURL.searchParams.get("widget"),"recruitment-radar");
assert.equal(radarURL.searchParams.get("via"),"competition");
assert.equal(radarURL.searchParams.get("theme"),"dark");
assert.equal(radarFrame.height,"565");
assert.equal(radarFrame.style.height,"100%");
radarFrame.contentDocument={getElementById(){return {textContent:"PARTIAL"};}};
radarFrame.listeners.load();
assert.equal(status.textContent,"PARTIAL","Status uses selected child state");
tabs.get("rivals").listeners.click();
assert.equal(foot.hidden,true,"Rivals does not show match-selection footer");
assert.equal(status.textContent,"Loading…");
const rivalPane=panels.get("rivals"),rivalFrame=rivalPane.children[0];
assert.equal(new URL(rivalFrame.src).searchParams.get("widget"),"rivalry-spotlight");
assert.equal(rivalFrame.height,"565");
tabs.get("match-calls").listeners.click();
assert.strictEqual(radarPane.children[0],radarFrame,"Switching tabs preserves existing iframe");
assert.equal(radarPane.children.length,1);
assert.equal(foot.children.length,1,"Shared CTA never duplicates");
assert.equal(foot.hidden,false);
assert.equal(status.textContent,"PARTIAL","Returning to Radar restores its state");
assert.equal(main.scrollTop,0);
tabs.get("showcase").listeners.click();
assert.strictEqual(panels.get("showcase").children[0],showcaseFrame,"Showcase remains mounted");
assert.equal(status.textContent,"LIVE");
let prevented=false;
tabs.get("showcase").listeners.keydown({key:"ArrowRight",preventDefault(){prevented=true;}});
assert(prevented);
assert(tabs.get("match-calls").focused);
assert.equal(status.textContent,"PARTIAL");
const preview=run("1","auto");
preview.tabs.get("match-calls").listeners.click();
const previewURL=new URL(preview.panels.get("match-calls").children[0].src);
assert.equal(previewURL.searchParams.get("preview"),"1");
assert.equal(previewURL.searchParams.get("theme"),"auto");
assert.equal(run("0","light","recruitment-radar").panels.get("showcase").children.length,0);
assert(embed.includes("in_array($widget,['recruitment-radar','rivalry-spotlight'],true)"));
assert(embed.includes("$competitionChild=in_array"),"Only embedded Radar and Rivals suppress child titles");
assert(embed.includes("<?php if($competitionChild): ?><style>"),"Hidden child headings are scoped to Competition");
assert(embed.includes(".p2kw-head{display:none!important}"),"Embedded child titles and duplicate statuses are hidden");
assert(!embed.includes("<?php if($widget==='competition'): ?><style>"),"Competition's own title remains visible");
assert(embed.includes("$store->get('competition')['enabled']"));
assert(embed.includes("p2k-competition-v2159.js"));
assert(admin.includes("'competition':565"));
assert(css.includes("grid-template-columns:repeat(3,minmax(0,1fr))"));
assert(css.includes("background:transparent"),"Outer frame must remain transparent");
assert(css.includes("background:var(--panel)"),"Only header/selector gets card backdrop");
assert(css.includes("height:100%"),"Child content gets fixed viewport");
assert(css.includes(".p2kw-foot.p2kw-competition-assistant[hidden]"));
assert(showcase.includes("pc-competition-embed .pc-footer{display:none!important}"),"Embedded Showcase hides its duplicate footer");
assert(showcase.includes('data-p2kw-load-state="loading"'));
assert(showcase.includes("dataset.p2kwLoadState='live'"));
assert(showcase.includes('class="pc-theme-'),"Standalone cards remain unchanged");
assert(source.includes("new MutationObserver(refresh)"));
assert(!source.includes("api.chess.com")&&!source.includes("fetch("),"Parent must not fetch extra data");
console.log("PASS Competition compact transparent viewport, fixed shared CTA, live/partial loading state, keyboard navigation, standalone Showcase isolation and child state persistence");
