/* P2K 2.15.9 Club Progress: square, read-only points history and goal-aligned linear forecast.
   Self-contained within the embed iframe; no host script, external chart library, or invented scores. */
(function(root){"use strict";
 const MS=86400000;
 const xml=value=>String(value??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
 const format=new Intl.NumberFormat("en-GB",{maximumFractionDigits:1});
 const pointsValue=value=>Number.isFinite(Number(value))?format.format(Number(value)):"—";
 function normalize(raw){
   const sorted=(Array.isArray(raw)?raw:[]).map(item=>{
     const stamp=String(item?.date||"").slice(0,10);
     const time=Date.parse(stamp+"T00:00:00Z"),value=Number(item?.value);
     return {day:time/MS,value,stamp};
   }).filter(p=>Number.isFinite(p.day)&&Number.isFinite(p.value)&&p.value>=0).sort((a,b)=>a.day-b.day);
   const unique=[];for(const p of sorted){if(unique.length&&unique.at(-1).day===p.day)unique[unique.length-1]=p;else unique.push(p)}
   return unique;
 }
 function slopeOf(points){
   if(points.length<7||points.at(-1).day-points[0].day<7)return null;
   const zero=points[0].day,meanX=points.reduce((s,p)=>s+p.day-zero,0)/points.length;
   const meanY=points.reduce((s,p)=>s+p.value,0)/points.length;
   let numer=0,denom=0;
   for(const p of points){const x=p.day-zero-meanX;numer+=x*(p.value-meanY);denom+=x*x}
   const slope=denom?numer/denom:0;
   return slope>0&&Number.isFinite(slope)?slope:null;
 }
 function dateOf(day){return new Date(Math.round(day)*MS).toISOString().slice(0,10)}
 function formatDate(day){return new Intl.DateTimeFormat("en-GB",{day:"numeric",month:"short",year:"numeric",timeZone:"UTC"}).format(new Date(day*MS))}
 function render(raw,settings={},logoUrl=""){
   const all=normalize(raw);
   if(all.length<2)return {svg:"",message:"Historical club points are not yet available.",last:null,forecast:null};
   const current=all.at(-1),history=all.filter(p=>p.day>=current.day-89);
   if(history.length<2)return {svg:"",message:"Not enough historical points for a progression chart.",last:current.value,forecast:null};
   const amount=name=>{
     const rawValue=String(settings[name]??"").trim();
     return rawValue!==""&&Number.isFinite(Number(rawValue))&&Number(rawValue)>0?Number(rawValue):null;
   };
   const lower=amount("lowerReferencePoints"),upper=amount("upperReferencePoints");
   const lowerLabel=String(settings.lowerReferenceLabel||"").trim(),upperLabel=String(settings.upperReferenceLabel||"").trim();
   const recent=all.filter(p=>p.day>=current.day-30);
   const slope=slopeOf(recent),toUpper=upper!==null&&upper>current.value&&slope!==null?(upper-current.value)/slope:null;
   const forecastDay=toUpper!==null&&toUpper<=3650?current.day+toUpper:null;
   // End the time axis just beyond the estimated crossing of the upper goal.
   // The projection stays tied to the observed 30-day slope, not invented points.
   const endValue=forecastDay===null?null:upper+Math.max(1,(upper-current.value)*.04);
   const futureEnd=endValue===null?current.day:current.day+(endValue-current.value)/slope;
   const visibleStart=history[0].day,xEnd=Math.max(current.day+.5,futureEnd);
   let yMin=lower,yMax=upper;
   const lows=history.map(p=>p.value),smallest=Math.min(...lows),largest=Math.max(...lows);
   const baseSpan=Math.max(1,largest-smallest);
   if(yMin===null)yMin=Math.max(0,smallest-baseSpan*.16);
   if(yMax===null)yMax=largest+baseSpan*.16;
   if(endValue!==null)yMax=Math.max(yMax,endValue+Math.max(1,(endValue-yMin)*.08));
   if(yMax<=yMin){yMin=Math.max(0,smallest-baseSpan*.15);yMax=largest+baseSpan*.15+1}
   // Keep at least one integer point visible on very narrow reference ranges.
   if(yMax-yMin<1){yMin=Math.max(0,Math.floor(yMin));yMax=Math.max(yMin+1,Math.ceil(yMax));}
   const x1=42,x2=246,top=14,bottom=223,span=yMax-yMin;
   const x=p=>x1+(p-visibleStart)/Math.max(1,xEnd-visibleStart)*(x2-x1);
   const y=p=>Math.max(top,Math.min(bottom,bottom-(p-yMin)/span*(bottom-top)));
   const ppath=history.map((p,i)=>(i?"L":"M")+x(p.day).toFixed(2)+" "+y(p.value).toFixed(2)).join(" ");
   // Axis tick values are whole Club Points; preserve full precision in graph/projection math.
   // The step is at least 1, preventing duplicate integer labels for narrow ranges.
   const tickStep=Math.max(1,Math.ceil(span/4));
   const firstTick=Math.ceil(yMin/tickStep)*tickStep;
   const ticks=Array.from({length:Math.min(6,Math.max(0,Math.floor((yMax-firstTick)/tickStep)+1))},(_,i)=>{
     const val=firstTick+i*tickStep,py=y(val);
     return '<path d="M'+x1+' '+py.toFixed(1)+'H'+x2+'" stroke="var(--border)" stroke-width=".65"/>'+
       '<text x="2" y="'+(py+3).toFixed(1)+'" fill="var(--muted)" font-size="8.5" paint-order="stroke" stroke="var(--card)" stroke-width="1.5">'+xml(Math.round(val).toLocaleString("en-GB"))+'</text>';
   }).join("");
   const refs=[[lower,lowerLabel],[upper,upperLabel]].filter(([val])=>val!==null).map(([val,label])=>{
     const py=y(val),short=label.length>14?label.slice(0,13)+"…":label;
     return '<g><title>'+xml((label||"Reference")+" · "+Math.round(val).toLocaleString("en-GB"))+'</title>'+
       '<path d="M'+x1+' '+py.toFixed(1)+'H'+x2+'" stroke="var(--gold)" stroke-width="1.25" opacity=".9"/>'+
       (short?'<text x="'+(x2-1)+'" y="'+(py<=top+6?py+12:py-4).toFixed(1)+'" text-anchor="end" fill="var(--gold)" font-size="10" font-weight="800" paint-order="stroke" stroke="var(--card)" stroke-width="2">'+xml(short)+'</text>':"")+
       '</g>';
   }).join("");
   let futureLine="";
   if(forecastDay!==null){
     const projected=current.value+slope*(xEnd-current.day);
     const segment='M'+x(current.day).toFixed(1)+' '+y(current.value).toFixed(1)+
       'L'+x(xEnd).toFixed(1)+' '+y(projected).toFixed(1);
     futureLine='<path d="'+segment+'" stroke="var(--card)" stroke-width="4.5" stroke-dasharray="5 4" fill="none"/>'+
       '<path d="'+segment+'" stroke="var(--blue)" stroke-width="2.6" stroke-dasharray="5 4" stroke-linecap="round" fill="none"/>';
   }
   // Keep the original club artwork full-size and full-color beneath the chart data.
   // The chart frame is square; the SVG viewport clips overflow automatically.
   const logo=logoUrl?'<image href="'+xml(logoUrl)+'" x="0" y="0" width="256" height="256" opacity="1" preserveAspectRatio="xMidYMid meet"/>':"";
   const svg='<svg class="p2kw-progress-chart" viewBox="0 0 256 256" role="img" aria-label="Historical club points with distinct blue projection and reference targets" xmlns="http://www.w3.org/2000/svg">'+
     '<rect x="0.75" y="0.75" width="254.5" height="254.5" fill="var(--card)"/>'+logo+
     ticks+refs+
     '<path d="'+ppath+'" fill="none" stroke="var(--card)" stroke-width="5.4" opacity=".85" stroke-linecap="round" stroke-linejoin="round"/>'+
     '<path d="'+ppath+'" fill="none" stroke="var(--gold)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>'+
     futureLine+
     '<circle cx="'+x(current.day).toFixed(1)+'" cy="'+y(current.value).toFixed(1)+'" r="2.9" fill="var(--gold)"/>'+
     '<text x="'+x1+'" y="244" fill="var(--muted)" font-size="9" paint-order="stroke" stroke="var(--card)" stroke-width="2">'+xml(formatDate(visibleStart))+'</text>'+
     '<text x="'+x2+'" y="244" text-anchor="end" fill="var(--muted)" font-size="9" paint-order="stroke" stroke="var(--card)" stroke-width="2">'+xml(formatDate(xEnd))+'</text>'+
     '<rect x="0.75" y="0.75" width="254.5" height="254.5" fill="none" stroke="var(--border)" stroke-width="1.5" pointer-events="none"/></svg>';
   let message="";
   if(upper!==null&&upper<=current.value)message=(upperLabel||"Upper reference")+" reached";
   else if(forecastDay!==null)message=(upperLabel||"Upper reference")+" · estimated "+formatDate(forecastDay)+" (recent 30-day trend)";
   // The absence of positive recent growth is not presented as a fake forecast.
   return {svg,message,last:current.value,forecast:forecastDay?dateOf(forecastDay):null,historyCount:history.length};
 }
 root.P2K_PROGRESS_CHART={render,normalize,slopeOf};
})(window);
