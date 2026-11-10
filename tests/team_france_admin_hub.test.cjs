const assert=require('node:assert/strict');
const fs=require('fs');const vm=require('vm');const path=require('path');
const root=path.resolve(__dirname,'../TeamFrance');
class Element {
 constructor(tag){this.tagName=tag;this.children=[];this.value='';this.checked=false;this.textContent='';this.listeners={};this.attributes={};}
 append(...els){this.children.push(...els)}
 replaceChildren(...els){this.children=[...els]}
 setAttribute(k,v){this.attributes[k]=v}
 addEventListener(k,fn){this.listeners[k]=fn}
 dispatchEvent(e){this.listeners[e.type]?.(e)}
 insertAdjacentElement(_,el){this.extra=el}
 querySelectorAll(query){const all=[];const walk=e=>{if(e.tagName==='input'&&e.type==='radio')all.push(e);e.children.forEach(walk)};walk(this);return all}
}
const input=new Element('input'),form=new Element('form');input.value='';const nodes={p2kMatchReference:input,p2kMatchForm:form};
const document={getElementById:id=>nodes[id]??null,createElement:tag=>new Element(tag)};
const fetchCalls=[];const matches=[{id:'2019622',name:'Team France contre un club',startTime:1798502400},{id:'2019623',name:'Second match'}];
async function fetch(url,opt){fetchCalls.push({url,opt});return {ok:true,async json(){return {ok:true,matches}}}}
vm.runInNewContext(fs.readFileSync(path.join(root,'assets/upcoming-selector.js'),'utf8'),{document,fetch,Event:class Event {constructor(type){this.type=type}},Date,setTimeout,console});
async function check(){await new Promise(r=>setTimeout(r,20));const panel=form.extra;assert(panel,'selector panel follows input form');const options=panel.children[2].children;assert.equal(options.length,2);const radio=options[0].children[0];radio.checked=true;radio.dispatchEvent({type:'change'});assert.equal(input.value,'2019622');input.value='2019623';input.dispatchEvent({type:'input'});assert.equal(options[1].children[0].checked,true);assert.equal(options[0].children[0].checked,false);assert.equal(fetchCalls[0].url,'/TeamFrance/api/overview.php');assert.equal(fetchCalls[0].opt.credentials,'same-origin');
const recruiting=fs.readFileSync(path.join(root,'MatchRecruitment/template.html'),'utf8');
assert(recruiting.includes('/TeamFrance/assets/upcoming-selector.js'));
assert(recruiting.includes('/TeamFrance/assets/upcoming-selector.css'));
const analyzer=fs.readFileSync(path.join(root,'MatchAnalyzer/template.html'),'utf8');
assert(analyzer.includes('/TeamFrance/assets/upcoming-selector.js'));
const home=fs.readFileSync(path.join(root,'home.html'),'utf8');
for(const p of ['/TeamFrance/Widget/admin/','/TeamFrance/MatchAnalyzer/','/TeamFrance/MatchRecruitment/'])assert(home.includes(p));
assert(home.includes('lang="fr"')&&home.includes('Outils d’administration'));
const php=fs.readFileSync(path.join(root,'api/overview.php'),'utf8');assert(php.includes('team_france_authorize()'));assert(php.includes("['registered']"));assert(php.includes("'cache_ttl_seconds'=>60"));assert(!php.includes('Authorization: Bearer'));
console.log('Team France administration / shared selector tests passed');}
check().catch(e=>{console.error(e);process.exitCode=1});
