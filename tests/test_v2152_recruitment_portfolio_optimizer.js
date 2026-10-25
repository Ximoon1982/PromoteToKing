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

console.log('PASS practical recruitment portfolio optimizer');
