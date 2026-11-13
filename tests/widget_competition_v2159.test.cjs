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
    this.style={};this.dataset={};this.hidden=false;this.tabIndex=-1;this.scrollTop=18;
    this.value="";this.textContent="";this.innerHTML="";this.focused=false;}
  append(n){this.children.push(n);}
  addEventListener(name,fn){this.listeners[name]=fn;}
  setAttribute(name,value){this.attrs[name]=String(value);}
  getAttribute(name){return this.attrs[name]??null;}
  focus(){this.focused=true;}
}
function run(mode="0",theme="dark",widget="competition"){
  const main=new Node("main"),status=new Node("span"),nav=new Node("nav");
  main.parentNode={insertBefore(node,target){assert.strictEqual(node,nav);assert.strictEqual(target,main);}};
  nav.querySelector=selector=>{const id=selector.match(/^#p2kwCompetitionTab-(showcase|match-calls|rivals)$/);return id?tabs.get(id[1]):null;};
  const panels=new Map(),tabs=new Map();
  for(const name of ["showcase","match-calls","rivals"]){
    panels.set(name,new Node("section"));
    tabs.set(name,new Node("button"));
  }
  main.querySelector=selector=>{
    const x=selector.match(/^#p2kwCompetition(Tab|Panel)-(showcase|match-calls|rivals)$/);
    if(!x)return null;
    return x[1]==="Panel"?panels.get(x[2]):null;
  };
  const document={
    body:{dataset:{widget,preview:mode}},
    currentScript:{src:"https://www.promotetoking.org/assets/js/widgets/p2k-competition-v2159.js"},
    documentElement:{className:"p2kw-theme-"+theme},
    getElementById(id){return id==="p2kwContent"?main:id==="p2kwStatus"?status:null;},
    createElement(tag){return tag==="nav"?nav:new Node(tag);}
  };
  const context={document,URL,Map,console,Array,String,Number};
  vm.runInNewContext(source,context,{filename:"p2k-competition-v2159.js"});
  return {main,status,panels,tabs,nav};
}
const fixture=run(),{main,status,panels,tabs,nav}=fixture;
assert.equal(status.textContent,"Showcase","Default selector is Showcase");
assert.match(nav.innerHTML,/role="tab"/);
assert.equal(nav.getAttribute("role"),"tablist");
assert.equal(nav.getAttribute("aria-label"),"Competition widget views");
assert.equal(nav.className,"p2kw-hof-tabs p2kw-competition-tabs");
assert(!main.innerHTML.includes("p2kw-competition-tabs"),"Top selector outside scrolling content");
assert.equal(panels.get("showcase").children.length,1,"Only Showcase loads at startup");
assert.equal(panels.get("match-calls").children.length,0,"No Radar fetch on Showcase startup");
assert.equal(panels.get("rivals").children.length,0,"No Rivals fetch on Showcase startup");
const showcaseFrame=panels.get("showcase").children[0];
assert.match(showcaseFrame.src,/server\/events-showcase\/public\/embed-card\.php\?theme=dark$/);
assert.equal(showcaseFrame.height,"690");
assert.equal(showcaseFrame.title,"Current P2K Match Showcase");
assert.equal(tabs.get("showcase").getAttribute("aria-selected"),"true");
assert.equal(tabs.get("match-calls").tabIndex,-1);

tabs.get("match-calls").listeners.click();
assert.equal(status.textContent,"Match calls");
assert.equal(panels.get("showcase").hidden,true);
assert.equal(panels.get("match-calls").hidden,false);
const radarPane=panels.get("match-calls");
assert.equal(radarPane.children.length,2,"Radar iframe followed by assistant CTA");
const radarFrame=radarPane.children[0],footer=radarPane.children[1],link=footer.children[0];
const radar=new URL(radarFrame.src);
assert.equal(radar.pathname,"/server/widgets/public/embed.php");
assert.equal(radar.searchParams.get("widget"),"recruitment-radar");
assert.equal(radar.searchParams.get("via"),"competition");
assert.equal(radar.searchParams.get("theme"),"dark");
assert.equal(radarFrame.height,"650");
assert.equal(link.textContent,"Open P2K match selection assistant");
assert.equal(link.href,"https://www.promotetoking.org/");
assert.equal(link.target,"_blank");
assert.equal(link.rel,"noopener noreferrer");
assert(showcase.includes(link.textContent),"CTA text exactly matches existing Showcase");
assert(showcase.includes('href="https://www.promotetoking.org/"'),"CTA target exactly matches existing Showcase");

tabs.get("rivals").listeners.click();
assert.equal(status.textContent,"Rivals");
const rivalPane=panels.get("rivals"),rivalFrame=rivalPane.children[0],rival=new URL(rivalFrame.src);
assert.equal(rival.searchParams.get("widget"),"rivalry-spotlight");
assert.equal(rival.searchParams.get("via"),"competition");
assert.equal(rivalFrame.height,"540");
assert.equal(panels.get("match-calls").hidden,true);
assert.equal(rivalPane.hidden,false);
tabs.get("match-calls").listeners.click();
assert.strictEqual(radarPane.children[0],radarFrame,"Switching back must not recreate Radar or re-trigger username search");
assert.strictEqual(radarPane.children[1],footer,"Radar CTA is not duplicated");
assert.equal(panels.get("showcase").children.length,1,"Showcase remains mounted");
assert.equal(panels.get("rivals").children.length,1,"Rivals remains mounted");
assert.equal(main.scrollTop,0,"Outer Competition content returns to top on switch");

// Accessible keyboard support; theme and private admin preview are passed through.
let prevented=false;
tabs.get("match-calls").listeners.keydown({key:"ArrowRight",preventDefault(){prevented=true;}});
assert(prevented);
assert.equal(status.textContent,"Rivals");
assert(tabs.get("rivals").focused);
assert.equal(tabs.get("rivals").getAttribute("aria-selected"),"true");
tabs.get("rivals").listeners.keydown({key:"Home",preventDefault(){}});
assert.equal(status.textContent,"Showcase");
const preview=run("1","auto");
preview.tabs.get("match-calls").listeners.click();
assert.equal(new URL(preview.panels.get("match-calls").children[0].src).searchParams.get("preview"),"1");
assert.equal(new URL(preview.panels.get("match-calls").children[0].src).searchParams.get("theme"),"auto");
const wrong=run("0","light","recruitment-radar");
assert.equal(wrong.panels.get("showcase").children.length,0,"Script does not run inside other widgets");

// Publication independent, and only allowed compositional children bypass standalone disabled setting.
assert(embed.includes("in_array($widget,['recruitment-radar','rivalry-spotlight'],true)"));
assert(embed.includes("$store->get('competition')['enabled']"));
assert(embed.includes("$published=(bool)$settings['enabled']||$viaCompetition"));
assert(embed.includes("($preview||$viaCompetition)?'1':'0'"));
assert(embed.includes("p2k-competition-v2159.js"));
assert(embed.includes("p2k-competition-v2159.css"));
assert(admin.includes("'competition':785"));
assert(admin.includes("'competition':'Competition'"));
assert(css.includes(".p2kw-competition-view[hidden]{display:none!important}"));
assert(css.includes("width:260px"));
assert(css.includes("grid-template-columns:repeat(3,minmax(0,1fr))"));
assert(css.includes("background:var(--fill)"));
assert(source.includes("main.parentNode.insertBefore(nav,main)"));
assert(!source.includes("api.chess.com"),"Composite itself must make no Chess.com API calls");
assert(!source.includes("fetch("),"Composite makes no duplicate public API requests");
console.log("PASS Competition card Showcase, fixed Hall of Fame-style navigation, preserved lazy children, assistant CTA, keyboard tabs and 260px/785px embed");
