const fs=require('fs'),vm=require('vm');
global.window=global;
vm.runInThisContext(fs.readFileSync(__dirname+'/../assets/js/shared/recruitment-lineup-core.js','utf8'));
const uut=global.P2K_RECRUITMENT_LINEUP_CORE;

const match={settings:{min_team_players:5,max_team_players:5,min_rating:0,max_rating:3000}};
const p2k={locked:false,players:[1900,1800,1200,1100].map(rating=>({rating}))};
const opponent={players:[1800,1700,1600,1500,1400].map(rating=>({rating}))};

const result=uut.recruitmentMetrics({match,p2kTeam:p2k,opponentTeam:opponent,isLeague:true});
if(!result||result.ready)throw new Error('Expected recruitment to be needed');
if(result.feasible!==true)throw new Error('Expected a feasible practical portfolio');
if(result.count<2)throw new Error('A single exceptional recruit must not satisfy this lineup');
if(!Array.isArray(result.portfolioRatings)||result.portfolioRatings.length!==result.count)throw new Error('Expected explicit portfolio ratings');
if(Math.max(...result.portfolioRatings)>2000)throw new Error('Optimizer exceeded 2000+ top bucket');
if(result.minimumRating!==1600||result.maximumRating!==1800){
  throw new Error('Expected practical 1600–1800 recommendation, got '+JSON.stringify(result));
}

const buckets=uut.recruitmentBuckets({minimum:0,maximum:Infinity});
if(buckets[0].lower!==null||buckets[0].upper!==800)throw new Error('Bottom bucket must be up to 800');
const top=buckets[buckets.length-1];
if(top.lower!==2000||top.upper!==null||top.rep!==2000)throw new Error('Top bucket must be 2000+');


const hardMatch={settings:{min_team_players:4,max_team_players:4,min_rating:0,max_rating:3000}};
const hardP2k={locked:false,players:[1300,1200,1100].map(rating=>({rating}))};
const hardOpponent={players:[2600,2500,2400,2300].map(rating=>({rating}))};
const hard=uut.recruitmentMetrics({match:hardMatch,p2kTeam:hardP2k,opponentTeam:hardOpponent,isLeague:true});
if(!hard||hard.ready)throw new Error('Expected hard league match to require recruitment');
if(hard.feasible!==true)throw new Error('Adaptive planner must always return an actionable target');
if(!Number.isFinite(hard.count)||hard.count<1)throw new Error('Adaptive planner returned no recruitment count');
if(!Array.isArray(hard.portfolioRatings)||hard.portfolioRatings.length!==hard.count)throw new Error('Adaptive planner returned no rating portfolio');
if(Math.max(...hard.portfolioRatings)>2000)throw new Error('Adaptive fallback exceeded 2000+ top bucket');
if(!Number.isFinite(hard.targetTier)||hard.targetTier<1||hard.targetTier>4)throw new Error('Adaptive planner returned no target tier');
if(hard.targetTier===4)throw new Error('Hard case should require target relaxation, got decisive tier');


const stressMatch={settings:{min_team_players:24,max_team_players:24,min_rating:0,max_rating:3000}};
const stressP2k={locked:false,players:Array.from({length:23},(_,i)=>({rating:900+(i%6)*100}))};
const stressOpponent={players:Array.from({length:24},(_,i)=>({rating:2100-(i%5)*50}))};
const stressStarted=process.hrtime.bigint();
const stress=uut.recruitmentMetrics({match:stressMatch,p2kTeam:stressP2k,opponentTeam:stressOpponent,isLeague:true});
const stressMs=Number(process.hrtime.bigint()-stressStarted)/1e6;
if(!stress||stress.feasible!==true)throw new Error('Stress case must still return an actionable target');
if(stress.finalistLimit!==32)throw new Error('Exact recruitment finalist work is not bounded to 32');
if(stressMs>1500)throw new Error('Recruitment optimizer stress case took '+stressMs.toFixed(1)+'ms; expected <=1500ms');
console.log('Recruitment optimizer 24-board stress: '+stressMs.toFixed(1)+'ms');

console.log('PASS practical recruitment portfolio optimizer');
