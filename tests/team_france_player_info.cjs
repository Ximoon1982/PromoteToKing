const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('TeamFrance/Leaderboard/assets/public.js','utf8');
class E{constructor(tag){this.tag=tag;this.children=[];this.value='';this.classList={toggle(){}};this.textContent=''}append(...nodes){this.children.push(...nodes)}replaceChildren(...nodes){this.children=nodes}setAttribute(k,v){this[k]=v}addEventListener(){}querySelector(s){return this[s]??(this[s]=new E(s))}}
const nodes=new Map(),document={getElementById(id){if(!nodes.has(id))nodes.set(id,new E(id));return nodes.get(id)},createElement:t=>new E(t)};
const start=source.indexOf('function profileLink'),end=source.indexOf('async function load');
const ctx={document,Intl,console,encodeURIComponent,Number,String,Array,fmt:new Intl.NumberFormat('fr-FR'),kinds:['daily','arena'],titles:{daily:'Daily',arena:'Arena'},state:{daily:{},arena:{}},$:id=>document.getElementById(id),el:(tag,text,cls)=>Object.assign(new E(tag),{textContent:text,className:cls}),button:(t,fn)=>Object.assign(new E('button'),{textContent:t,onclick:fn}),choose(){},user:'Alice',load(){}};
vm.createContext(ctx);vm.runInContext(source.slice(start,end)+';this.ui={renderSection,renderProfile,avatar,profileLink}',ctx);
const row={username:'Alice',points:12,rank:1,events:3,avatar:'https://images.chesscomfiles.com/a.png'};
ctx.ui.renderSection('daily',{season:{id:1},seasons:[{id:1,name:'2026'}],rows:[row],page:1,total:1,pages:1});
const all=n=>[n,...n.children.filter(x=>x instanceof E).flatMap(all)];
let nodeset=all(document.getElementById('daily').querySelector('.ranking'));
assert(nodeset.some(x=>x.tag==='a'&&x.href==='https://www.chess.com/member/Alice'&&x.target==='_blank'));
assert(nodeset.some(x=>x.tag==='img'&&x.src===row.avatar));
assert(nodeset.some(x=>x.textContent==='3 matchs'));
assert.equal(all(ctx.ui.avatar({username:'Alice',avatar:'https://evil.example/a'})).filter(x=>x.tag==='img').length,0);
ctx.ui.renderProfile({player:row,profile:{daily:row,arena:{...row,events:1}},sections:{daily:{season:{name:'Daily'}},arena:{season:{name:'Arena'}}}});
nodeset=all(document.getElementById('profile'));assert(nodeset.some(x=>x.tag==='a'&&x.href.endsWith('/Alice')));assert(nodeset.some(x=>x.textContent==='Matchs'));assert(nodeset.some(x=>x.textContent==='Arènes'));
console.log('PASS: public links, avatar fallback, category participation and player detail.');
