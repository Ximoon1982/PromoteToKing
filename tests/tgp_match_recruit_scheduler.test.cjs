const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const root=path.resolve(__dirname,'../TGP/MatchRecruitment');
class Element{
 constructor(id){this.id=id;this.value='';this.textContent='';this.hidden=false;this.style={};this.classList={toggle(){}};this.dataset={};this.listeners={};this.disabled=false;this._html='';}
 set innerHTML(v){this._html=v} get innerHTML(){return this._html}
 addEventListener(type,handler){this.listeners[type]=handler}
 dispatch(type){return this.listeners[type]?.({preventDefault(){}})}
 replaceChildren(...args){this.children=args}
 querySelectorAll(){return []}
}
const els=new Map();const get=id=>{if(!els.has(id))els.set(id,new Element(id));return els.get(id)};
get('p2kOnlineDays').value='2';get('p2kTimeoutRate').value='5';get('p2kMatchReference').value='123456';
const document={getElementById:get,querySelector:q=>q.includes('tgp-recruitment-csrf')?{content:'testcsrf'}:null,createTextNode:text=>({text}),createElement:tag=>({tag,href:'',textContent:'',click(){}})};
const users=Array.from({length:125},(_,i)=>({username:'player'+i,joined:1700000000+i}));
const match={id:123456,name:'Friendly 125',settings:{rules:'chess',min_rating:1000,max_rating:2000},teams:{team1:{'@id':'https://api.chess.com/pub/club/the-golden-phoenix',name:'TGP',players:[]},team2:{'@id':'https://api.chess.com/pub/club/opponent',name:'Opp',players:[]}}};
let active=0,maxActive=0,posts=0,requests=0;
function bodyFor(url){const p=new URL(url).pathname;if(p==='/pub/club/the-golden-phoenix')return {icon:'https://images.chesscomfiles.com/avatar.png'};if(p==='/pub/match/123456')return match;if(p.endsWith('/stats'))return {chess_daily:{last:{rating:1500},best:{rating:1910},record:{timeout_percent:1}}};if(p.endsWith('/clubs'))return {clubs:['https://api.chess.com/pub/club/the-golden-phoenix']};if(p.startsWith('/pub/player/'))return {name:'Test Player',last_online:Math.floor(Date.now()/1000)};throw Error('Unknown '+p)}
async function fetcher(url,options){if(url==='api/?action=roster')return{ok:true,status:200,json:async()=>({ok:true,source:'live',members:process.env.TGP_ROSTER_SHAPE==='malformed'?[{username:{nested:'bad'}}]:process.env.TGP_ROSTER_SHAPE==='structured'?users:users.map(x=>x.username),joinedByUsername:Object.fromEntries(users.map(x=>[x.username,x.joined])),count:users.length})};assert.equal(url,'api/?action=batch');active++;maxActive=Math.max(maxActive,active);posts++;assert.equal(options.headers['X-P2K-OAuth-CSRF'],'testcsrf');const requestsInPost=JSON.parse(options.body).requests;assert.ok(requestsInPost.length<=12);requests+=requestsInPost.length;await new Promise(r=>setTimeout(r,2));active--;return{ok:true,status:200,json:async()=>({ok:true,mode:'oauth-bearer',results:requestsInPost.map((x,i)=>({id:String(i),status:200,json:bodyFor(x.url),elapsed_ms:2}))})}}
const context={window:{P2K_RECRUITMENT_V2:{parseMatchReference:x=>x,ratingCategory:()=> 'daily_standard',verify(row,live,criteria){const age=(Date.now()/1000-live.last_online)/3600;return{...row,live,last_online_age_hours:age,decision:age<=criteria.onlineDays*24&&live.timeout_percent<=criteria.maxTimeout?'eligible':'excluded'}},eta:()=>0,csv:(rows)=>rows.length+' rows'}},document,fetch:fetcher,URLSearchParams,location:{href:'https://www.promotetoking.org/TGP/MatchRecruitment/',search:''},URL,Blob,AbortController,DOMException,setTimeout,clearTimeout,performance,console,Date,Math,navigator:{clipboard:{writeText:()=>Promise.resolve()}}};
vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(root,'assets/js/recruitment.js'),'utf8'),context);
async function until(pred,timeout=6000){const end=Date.now()+timeout;while(Date.now()<end){if(pred())return;await new Promise(r=>setTimeout(r,20))}throw Error('Test timed out: '+get('p2kStatusText').textContent)}
(async()=>{
await get('p2kMatchForm').dispatch('submit');await until(()=>get('p2kStatusText').textContent==='Match resolved.');
const link=get('p2kMatchSummary').children[0];assert.equal(link.href,'https://www.chess.com/club/matches/the-golden-phoenix/123456');assert.equal(link.textContent,'Friendly 125');
await get('p2kRecruitmentForm').dispatch('submit');
if(process.env.TGP_ROSTER_SHAPE==='malformed'){
 await until(()=>get('p2kStatusText').textContent.includes('Invalid username'));
 assert.equal(requests,2,'Malformed roster should not generate candidate API requests (avatar + match only)');
 assert.match(get('p2kStatusText').textContent,/scan stopped/i);
 console.log('PASS malformed username rejected before any candidate API request');
 return;
}
await until(()=>get('p2kStatusText').textContent.includes('Recruitment analysis completed.'));
assert.ok(maxActive<=2,`PHP gateway overload: ${maxActive}`);assert.ok(requests>=376,`requests ${requests}`);assert.ok(posts<125,`too many HTTP posts ${posts}`);
assert.match(get('tgpPageInfo').textContent,/Page 1 of 3/);assert.match(get('tgpPageInfo').textContent,/125 players/);
assert.equal((get('tgpRows').innerHTML.match(/<tr>/g)||[]).length,50);assert.match(get('tgpRows').innerHTML,/Test Player/);assert.match(get('tgpRows').innerHTML,/1910/);assert.match(get('tgpRows').innerHTML,/2023-11-/);
get('tgpNextPage').onclick();assert.match(get('tgpPageInfo').textContent,/Page 2 of 3/);assert.equal((get('tgpRows').innerHTML.match(/<tr>/g)||[]).length,50);assert.match(get('tgpRows').innerHTML,/Test Player/);assert.match(get('tgpRows').innerHTML,/1910/);assert.match(get('tgpRows').innerHTML,/2023-11-/);
get('tgpNextPage').onclick();assert.equal((get('tgpRows').innerHTML.match(/<tr>/g)||[]).length,25);
get('tgpSearch').value='player1';get('tgpSearch').dispatch('input');assert.match(get('tgpPageInfo').textContent,/Page 1 of 1/);
assert.equal(get('p2kSelectedTeamLogo').src,'https://images.chesscomfiles.com/avatar.png');
console.log('PASS OAuth batching: '+requests+' external calls through '+posts+' HTTP posts, maximum '+maxActive+' concurrent PHP posts; pagination 50/50/25, filtering, avatar, link');
})().catch(e=>{console.error(e);process.exitCode=1});

// The standalone CSV core must preserve the new field order and omit retired fields.
const core=require(path.join(root,'assets/js/recruitment-core.js'));
const sample=core.csv([{username:'tester',name:'Taylor, Tester',rating:1500,max_rating:1910,joined_tgp_epoch:1700000000,last_online_age_hours:1,live:{last_online:1700000100,timeout_percent:1.4}}],{id:123456,name:'Example',min:1000,max:2000});
const header=sample.slice(1).split('\r\n')[0];
assert.equal(header,'"username","name","profile_url","rating","last_online","joined_tgp","max_rating","timeout_rate","last_online_age_hours","match_id","match_name","rating_min","rating_max"');
assert.ok(!header.includes('rating_category')&&!header.includes('rating_updated_at')&&!header.includes('current_match_load'));
assert.match(sample,/Taylor, Tester/);assert.match(sample,/2023-11-/);assert.match(sample,/1910/);
