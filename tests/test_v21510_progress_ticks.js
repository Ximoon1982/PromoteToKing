const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
const context={window:{},Intl,Date,Number,Math,Array,String};
vm.runInNewContext(fs.readFileSync('assets/js/widgets/p2k-progress-chart-v2159.js','utf8'),context);
const chart=context.window.P2K_PROGRESS_CHART;
function check(values,lower,upper){
 const history=values.map((value,i)=>({date:new Date(Date.UTC(2026,9,1+i)).toISOString().slice(0,10),value}));
 const out=chart.render(history,{lowerReferencePoints:lower,upperReferencePoints:upper});
 assert.ok(out.svg,'chart SVG missing');
 const labels=[...out.svg.matchAll(/<text x="2" y="[^"]+"[^>]*>([^<]+)<\/text>/g)].map(x=>x[1]);
 assert.ok(labels.length>0,'no scale ticks');
 assert.deepEqual(labels,[...new Set(labels)],'duplicate tick labels');
 for(const label of labels){assert.match(label,/^[\d,]+$/,'fractional scale tick: '+label)}
 return out;
}
check([400000,400000.5,400001,400002.25,400003,400004],399999.6,400005.2);
check([4,4.1,4.2,4.3,4.35,4.4],4.1,4.9);
const full=check([100,110,120,130,140,150,160,170,180,190],95,230);
assert.ok(full.forecast,'forecast lost');
console.log('MCA v2.15.10 integer scale tick / precision regression: PASS');
