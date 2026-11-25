const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('TeamFrance/Leaderboard/assets/admin.js','utf8');
const start=source.indexOf('// Both event choosers share'),end=source.indexOf("$('csv-form').onsubmit",start);
assert(start>=0&&end>start);
class E{
 constructor(tag,text='',cls=''){this.tag=tag;this.textContent=text;this.className=cls;this.children=[];this.value='';this.hidden=false;this.disabled=false;}
 append(...v){this.children.push(...v)}
 replaceChildren(...v){this.children=v}
 setAttribute(){}
}
const nodes=new Map(),$=id=>{if(!nodes.has(id))nodes.set(id,new E('div'));return nodes.get(id)};
$('assign-form').elements={season_id:new E('select'),references:new E('textarea')};$('assign-form').elements.season_id.value='1';
const data={seasons:[{id:1,start_date:'2026-01-01',end_date:'2026-12-31'}]};
const el=(t,v,c)=>new E(t,v,c),btn=(t,fn)=>Object.assign(new E('button',t),{onclick:fn});
const env={$,data,tab:'daily',el,btn,document:{createTextNode:x=>x},msg:()=>{},call:async()=>({}),console};
vm.createContext(env);vm.runInContext(source.slice(start,end)+'\nthis.test={createChooser,addChoices,closeChooser,selectedSeason,seasonRange};',env);
const t=env.test,season=data.seasons[0];
const rows=Array.from({length:62},(_,i)=>({id:String(i+1),name:(i<35?'League ':'Friendly ')+(i+1),url:'https://www.chess.com/club/matches/'+(i+1),assigned:i===2?{season:'Already assigned'}:null}));
let c=t.createChooser('daily',season);t.addChoices(c,rows);
function all(node){return [node,...node.children.filter(x=>x instanceof E).flatMap(all)]}
function button(text){const b=all($('index-results')).find(n=>n.tag==='button'&&n.textContent===text);assert(b,text);return b}
function checks(){return all($('index-results')).filter(n=>n.tag==='input'&&n.type==='checkbox')}
assert.equal(checks().length,25);
c.find.value='League';c.find.oninput();
button('Select all filtered').onclick();assert.equal(c.selected.size,34);assert(!c.selected.has('3'));
button('Next').onclick();assert.equal(checks().length,10);assert(checks().every(n=>n.checked));
c.find.value='Friendly';c.find.oninput();button('Select all filtered').onclick();assert.equal(c.selected.size,61);
button('Deselect all filtered').onclick();assert.equal(c.selected.size,34);
button('Use selected matches').onclick();assert.equal($('assign-form').elements.references.value.split('\n').length,34);
assert.equal(c.selected.size,0);
t.closeChooser();env.tab='arena';c=t.createChooser('arena',season);
t.addChoices(c,Array.from({length:50},(_,i)=>({id:String(i+100),name:'Arena '+i,date:i<30?'2026-02-01':'2026-03-01',url:'https://www.chess.com/tournament/live/arena/test-'+(i+100)})));
c.find.value='2026-02-01';c.find.oninput();button('Select all filtered').onclick();assert.equal(c.selected.size,30);
button('Next').onclick();assert.equal(checks().length,5);assert(checks().every(n=>n.checked));
button('Use selected arenas').onclick();assert($('assign-form').elements.references.value.includes('/arena/test-129'));
console.log('PASS: shared Daily/arena chooser, page size, all filtered results, assigned exclusion, selection persistence and URLs.');
