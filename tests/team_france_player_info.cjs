const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('TeamFrance/Leaderboard/assets/public.js','utf8');
class E{constructor(tag){this.tag=tag;this.children=[];this.value='';this.classList={toggle(){}};this.textContent=''}append(...nodes){this.children.push(...nodes)}replaceChildren(...nodes){this.children=nodes}setAttribute(k,v){this[k]=v}addEventListener(){}querySelector(s){return this[s]??(this[s]=new E(s))}}
const nodes=new Map(),document={getElementById(id){if(!nodes.has(id))nodes.set(id,new E(id));return nodes.get(id)},createElement:t=>new E(t)};
const start=source.indexOf('function profileLink'),end=source.indexOf('async function load');
const chosen=[];
const ctx={document,Intl,console,encodeURIComponent,Number,String,Array,fmt:new Intl.NumberFormat('fr-FR'),kinds:['daily','arena'],titles:{daily:'Daily',arena:'Arena'},state:{daily:{},arena:{}},$:id=>document.getElementById(id),el:(tag,text,cls)=>Object.assign(new E(tag),{textContent:text,className:cls}),button:(t,fn)=>Object.assign(new E('button'),{textContent:t,onclick:fn}),choose(name){chosen.push(name)},user:'Alice',load(){}};
vm.createContext(ctx);vm.runInContext(source.slice(start,end)+';this.ui={renderSection,renderProfile,avatar,profileLink,toggleExpanded,showRankings}',ctx);
const row={username:'Alice',points:12,rank:1,events:3,avatar:'https://images.chesscomfiles.com/a.png'};
const all=n=>[n,...n.children.filter(x=>x instanceof E).flatMap(all)];
for(const kind of ['daily','arena']){
 ctx.ui.renderSection(kind,{season:{id:1},seasons:[{id:1,name:'2026'}],rows:[row],page:1,total:1,pages:1});
 const nodeset=all(document.getElementById(kind).querySelector('.ranking'));
 const name=nodeset.find(x=>x.tag==='button'&&x.textContent==='Alice'&&x.className==='name');
 assert(name);assert(!nodeset.some(x=>x.tag==='a'&&x.href?.includes('chess.com/member/')));
 name.onclick();assert.equal(chosen.at(-1),'Alice');
 const avatar=nodeset.find(x=>x.tag==='button'&&x.className==='avatar-button');assert(avatar);avatar.onclick();assert.equal(chosen.at(-1),'Alice');
 assert(nodeset.some(x=>x.tag==='img'&&x.src===row.avatar));
 assert(nodeset.some(x=>x.textContent===(kind==='daily'?'3 matchs':'3 arènes')));
}
assert.equal(chosen.length,4);
assert.equal(all(ctx.ui.avatar({username:'Alice',avatar:'https://evil.example/a'})).filter(x=>x.tag==='img').length,0);
ctx.ui.renderProfile({player:row,profile:{daily:row,arena:{...row,events:1}},sections:{daily:{season:{name:'Daily'}},arena:{season:{name:'Arena'}}}});
const nodeset=all(document.getElementById('profile'));assert(nodeset.some(x=>x.tag==='a'&&x.href==='https://www.chess.com/member/Alice'&&x.target==='_blank'&&x.rel==='noopener noreferrer'));assert(nodeset.some(x=>x.textContent==='Matchs'));assert(nodeset.some(x=>x.textContent==='Arènes'));
console.log('PASS: both leaderboard names and avatars open internal profile; only detail name links to Chess.com; avatars and counts preserved.');

for(const [kind,other] of [['daily','arena'],['arena','daily']]){
 const s={season:{id:2},seasons:[{id:1,name:'2026'},{id:2,name:'2025'}],rows:[row],page:1,total:41,pages:9};
 ctx.ui.renderSection(kind,s);
 let pager=all(document.getElementById(kind).querySelector('.pager'));
 pager.find(x=>x.textContent==='Voir plus').onclick();
 ctx.ui.showRankings();
 assert.equal(ctx.state[kind].expanded,true);assert.equal(ctx.state[other].expanded,false);
 assert.equal(document.getElementById(kind).hidden,false);assert.equal(document.getElementById(other).hidden,true);
 assert.equal(ctx.state[kind].id,2);
 ctx.ui.renderSection(kind,{...s,page:2,pages:2});
 assert.equal(ctx.state[kind].page,2);
 ctx.ui.renderProfile({player:row,profile:{daily:row,arena:row},sections:{daily:{season:{name:'Daily'}},arena:{season:{name:'Arena'}}}});
 assert.equal(document.getElementById(kind).hidden,true);
 ctx.ui.showRankings();assert.equal(document.getElementById(other).hidden,true);assert.equal(ctx.state[kind].page,2);
 pager=all(document.getElementById(kind).querySelector('.pager'));pager.find(x=>x.textContent==='Voir moins').onclick();
 ctx.ui.showRankings();assert.equal(document.getElementById(kind).hidden,false);assert.equal(document.getElementById(other).hidden,false);
 assert.equal(ctx.state[kind].expanded,false);assert.equal(ctx.state[kind].page,1);
}
console.log('PASS: exclusive expansion, retained season and page, internal profile return, collapse.');
