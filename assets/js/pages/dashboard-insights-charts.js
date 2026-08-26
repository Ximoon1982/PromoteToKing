(() => {
  "use strict";
  window.P2K_CREATE_INSIGHTS_CHARTS = function(runtime = {}) {
    const { byId, number, escapeHTML, openOpponentProfile } = runtime;
  const nativeChartZoom = new Map();
  const nativeNS = "http://www.w3.org/2000/svg";
  const nativeSVG = (name, attributes = {}) => {
    const node = document.createElementNS(nativeNS, name);
    Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, String(value)));
    return node;
  };
  function nativeChartEmpty(host, message) {
    if (!host) return;
    host.replaceChildren();
    const empty = document.createElement("div"); empty.className = "p2k-chart-empty"; empty.textContent = message; host.appendChild(empty);
  }
  function nativeChartTip(host, event, html) {
    let tip = host.querySelector(".p2k-chart-tooltip");
    if (!tip) { tip = document.createElement("div"); tip.className = "p2k-chart-tooltip"; host.appendChild(tip); }
    tip.innerHTML = html; tip.style.display = "block";
    const rect = host.getBoundingClientRect();
    const tipWidth=Math.min(260,Math.max(150,tip.offsetWidth||180)),tipHeight=Math.max(44,tip.offsetHeight||54);
    tip.style.left = `${Math.max(4,Math.min(Math.max(4,rect.width-tipWidth-4),event.clientX-rect.left+9))}px`;
    tip.style.top = `${Math.max(4,Math.min(Math.max(4,rect.height-tipHeight-4),event.clientY-rect.top-tipHeight-8))}px`;
  }
  function hideNativeChartTip(host) { const tip = host?.querySelector(".p2k-chart-tooltip"); if (tip) tip.style.display = "none"; }

  function bindChartTip(node,host,htmlFactory){
    const show=event=>nativeChartTip(host,event,typeof htmlFactory==="function"?htmlFactory():htmlFactory);
    node.addEventListener("pointermove",event=>{if(event.pointerType!=="touch")show(event)});
    node.addEventListener("pointerleave",event=>{if(event.pointerType!=="touch")hideNativeChartTip(host)});
    node.addEventListener("click",event=>{show(event);event.stopPropagation();});
  }
  function installPinchZoom(overlay,svg,hostId,rows,start,end,rerender){
    if(!overlay||!svg||!Array.isArray(rows)||rows.length<3)return;
    overlay.style.touchAction="pan-y";let pinch=null;
    const dist=t=>Math.hypot(t[0].clientX-t[1].clientX,t[0].clientY-t[1].clientY);
    overlay.addEventListener("touchstart",event=>{if(event.touches.length!==2)return;event.preventDefault();const rect=svg.getBoundingClientRect(),center=(event.touches[0].clientX+event.touches[1].clientX)/2;pinch={initial:Math.max(1,dist(event.touches)),current:Math.max(1,dist(event.touches)),centerRatio:Math.max(0,Math.min(1,(center-rect.left)/Math.max(1,rect.width)))};},{passive:false});
    overlay.addEventListener("touchmove",event=>{if(!pinch||event.touches.length!==2)return;event.preventDefault();pinch.current=Math.max(1,dist(event.touches));},{passive:false});
    const finish=()=>{if(!pinch)return;const ratio=pinch.current/pinch.initial,oldSpan=Math.max(2,end-start+1),newSpan=Math.max(2,Math.min(rows.length,Math.round(oldSpan/Math.max(.25,Math.min(4,ratio))))),centerAbs=start+Math.round((oldSpan-1)*pinch.centerRatio);let nextStart=Math.max(0,centerAbs-Math.floor((newSpan-1)/2)),nextEnd=Math.min(rows.length-1,nextStart+newSpan-1);nextStart=Math.max(0,nextEnd-newSpan+1);pinch=null;if(newSpan>=rows.length)nativeChartZoom.delete(hostId);else nativeChartZoom.set(hostId,[nextStart,nextEnd]);rerender();};
    overlay.addEventListener("touchend",event=>{if(pinch&&event.touches.length<2)finish();},{passive:true});
    overlay.addEventListener("touchcancel",()=>{pinch=null;},{passive:true});
  }

  function renderNativeBars(hostId, rows, { valueKey = "value", labelKey = "label", detail = null, color = "#4aa8d8", horizontal = false, onClick = null, showValues = false } = {}) {
    const host = byId(hostId); if (!host) return;
    host.replaceChildren();
    if (!Array.isArray(rows) || !rows.length) return nativeChartEmpty(host, "No database data is available.");
    const width = 720, height = horizontal ? Math.max(210, rows.length * 31 + 34) : 230;
    const margin = horizontal ? { left: 110, right: 34, top: 12, bottom: 22 } : { left: 42, right: 12, top: 12, bottom: 42 };
    const innerWidth = width - margin.left - margin.right, innerHeight = height - margin.top - margin.bottom;
    const maximum = Math.max(1, ...rows.map(row => Number(row[valueKey]) || 0));
    const svg = nativeSVG("svg", { viewBox: `0 0 ${width} ${height}`, role: "img", preserveAspectRatio: "none" }); host.appendChild(svg);
    for (let i = 0; i <= 4; i += 1) {
      if (horizontal) continue;
      const y = margin.top + innerHeight - innerHeight * i / 4;
      svg.appendChild(nativeSVG("line", { x1: margin.left, y1: y, x2: width - margin.right, y2: y, class: "p2k-chart-grid" }));
      const label = nativeSVG("text", { x: margin.left - 6, y: y + 3, "text-anchor": "end", class: "p2k-chart-axis" }); label.textContent = number(Math.round(maximum * i / 4)); svg.appendChild(label);
    }
    rows.forEach((row, index) => {
      const value = Number(row[valueKey]) || 0, labelValue = String(row[labelKey] ?? "");
      let bar;
      if (horizontal) {
        const slot = innerHeight / rows.length, y = margin.top + index * slot + 3, barWidth = innerWidth * value / maximum;
        bar = nativeSVG("rect", { x: margin.left, y, width: Math.max(1, barWidth), height: Math.max(8, slot - 7), rx: 4, fill: row.color || color });
        const axis = nativeSVG("text", { x: margin.left - 6, y: y + Math.max(8, slot - 7) / 2 + 3, "text-anchor": "end", class: "p2k-chart-axis" }); axis.textContent = labelValue; svg.appendChild(axis);
        const valueText = nativeSVG("text", { x: Math.min(width - margin.right, margin.left + barWidth + 5), y: y + Math.max(8, slot - 7) / 2 + 3, class: "p2k-chart-axis" }); valueText.textContent = number(value); svg.appendChild(valueText);
      } else {
        const slot = innerWidth / rows.length, barWidth = Math.max(4, slot * .68), x = margin.left + index * slot + (slot - barWidth) / 2, barHeight = innerHeight * value / maximum, y = margin.top + innerHeight - barHeight;
        bar = nativeSVG("rect", { x, y, width: barWidth, height: Math.max(1, barHeight), rx: 4, fill: row.color || color });
        if (showValues) { const valueText=nativeSVG("text",{x:x+barWidth/2,y:Math.max(11,y-5),"text-anchor":"middle",class:"p2k-chart-value"}); valueText.textContent=number(value); svg.appendChild(valueText); }
        if (rows.length <= 16 || index % Math.ceil(rows.length / 12) === 0) { const axis = nativeSVG("text", { x: x + barWidth / 2, y: height - 12, "text-anchor": "middle", class: "p2k-chart-axis" }); axis.textContent = labelValue.length > 10 ? labelValue.slice(0, 9) + "…" : labelValue; svg.appendChild(axis); }
      }
      bindChartTip(bar,host,()=>`<strong>${escapeHTML(labelValue)}</strong><br>${detail ? detail(row) : number(value)}`); if (typeof onClick === "function") { bar.style.cursor = "pointer"; bar.addEventListener("click", () => onClick(row)); } svg.appendChild(bar);
    });
  }
  function renderNativePie(hostId, rows, { valueKey = "value", labelKey = "label" } = {}) {
    const host = byId(hostId); if (!host) return; host.replaceChildren();
    const usable = (Array.isArray(rows) ? rows : []).filter(row => Number(row[valueKey]) > 0); if (!usable.length) return nativeChartEmpty(host, "No database data is available.");
    const width = 760, height = 320, cx = 170, cy = 160, radius = 122, palette = ["#4aa8d8", "#f6b73c", "#5fbf72", "#a98ae8", "#e7685a", "#48b8a8", "#d98d18"];
    const total = usable.reduce((sum, row) => sum + Number(row[valueKey] || 0), 0), svg = nativeSVG("svg", { viewBox: `0 0 ${width} ${height}`, role: "img" }); host.appendChild(svg); let angle = -Math.PI / 2;
    usable.forEach((row, index) => { const value = Number(row[valueKey]), next = angle + 2 * Math.PI * value / total, x1 = cx + radius * Math.cos(angle), y1 = cy + radius * Math.sin(angle), x2 = cx + radius * Math.cos(next), y2 = cy + radius * Math.sin(next), large = next - angle > Math.PI ? 1 : 0, color = row.color || palette[index % palette.length]; const path = nativeSVG("path", { d: `M${cx},${cy} L${x1},${y1} A${radius},${radius} 0 ${large} 1 ${x2},${y2} Z`, fill: color }); bindChartTip(path,host,()=>`<strong>${escapeHTML(String(row[labelKey]))}</strong><br>${number(value)} · ${(100 * value / total).toFixed(1)}%`); svg.appendChild(path); const y = 50 + index * 31, swatch = nativeSVG("rect", { x: 350, y: y - 11, width: 13, height: 13, rx: 2, fill: color }), label = nativeSVG("text", { x: 374, y, class: "p2k-chart-axis" }); label.textContent = `${row[labelKey]} · ${number(value)} · ${(100 * value / total).toFixed(1)}%`; svg.append(swatch, label); angle = next; });
  }
  function renderNativeStackedBars(hostId, rows, { labelKey = "label", series = [], showTotals = false } = {}) {
    const host = byId(hostId); if (!host) return; host.replaceChildren(); if (!Array.isArray(rows) || !rows.length) return nativeChartEmpty(host, "No database data is available.");
    let start = 0, end = rows.length - 1; const zoom = nativeChartZoom.get(hostId); if (zoom) { start = zoom[0]; end = zoom[1]; }
    const visible = rows.slice(start, end + 1), width = 760, height = 280, margin = { left: 48, right: 16, top: 13, bottom: 48 }, innerWidth = width - margin.left - margin.right, innerHeight = height - margin.top - margin.bottom, maximum = Math.max(1, ...visible.map(row => series.reduce((sum, item) => sum + Number(row[item.key] || 0), 0))), svg = nativeSVG("svg", { viewBox: `0 0 ${width} ${height}`, role: "img", preserveAspectRatio: "none" }); host.appendChild(svg);
    for (let i=0;i<=4;i+=1){const y=margin.top+innerHeight-innerHeight*i/4;svg.appendChild(nativeSVG("line",{x1:margin.left,y1:y,x2:width-margin.right,y2:y,class:"p2k-chart-grid"}));}
    const X=index=>margin.left+innerWidth*(index+.5)/Math.max(1,visible.length), slot=innerWidth/Math.max(1,visible.length);
    visible.forEach((row,index)=>{const barWidth=Math.max(3,slot*.66),x=X(index)-barWidth/2;let cumulative=0;const total=series.reduce((sum,item)=>sum+Number(row[item.key]||0),0);series.forEach(item=>{const value=Number(row[item.key])||0,h=innerHeight*value/maximum,y=margin.top+innerHeight-cumulative-h,rect=nativeSVG("rect",{x,y,width:barWidth,height:Math.max(0,h),fill:item.color});bindChartTip(rect,host,()=>`<strong>${escapeHTML(String(row[labelKey]))}</strong><br>Wins / draws / losses: ${number(row.wins)} / ${number(row.draws)} / ${number(row.losses)}<br>${escapeHTML(item.label)}: ${number(value)}`);svg.appendChild(rect);cumulative+=h;});if(showTotals){const label=nativeSVG("text",{x:X(index),y:Math.max(11,margin.top+innerHeight-cumulative-6),"text-anchor":"middle",class:"p2k-chart-value"});label.textContent=number(total);svg.appendChild(label);}if(visible.length<=16||index%Math.ceil(visible.length/10)===0){const axis=nativeSVG("text",{x:X(index),y:height-15,"text-anchor":"middle",class:"p2k-chart-axis"});axis.textContent=String(row[labelKey]);svg.appendChild(axis);}});
    const selection=nativeSVG("rect",{y:margin.top,height:innerHeight,class:"p2k-chart-selection",visibility:"hidden"}),overlay=nativeSVG("rect",{x:margin.left,y:margin.top,width:innerWidth,height:innerHeight,fill:"transparent",style:"cursor:crosshair"});svg.append(selection,overlay);let drag=null;const localIndex=px=>Math.max(0,Math.min(visible.length-1,Math.floor((px-margin.left)/innerWidth*visible.length)));
    overlay.addEventListener("pointerdown",event=>{if(event.pointerType==="touch")return;const rect=svg.getBoundingClientRect();drag=(event.clientX-rect.left)/rect.width*width;overlay.setPointerCapture(event.pointerId);});overlay.addEventListener("pointermove",event=>{if(drag===null)return;const rect=svg.getBoundingClientRect(),px=(event.clientX-rect.left)/rect.width*width;selection.setAttribute("visibility","visible");selection.setAttribute("x",Math.min(drag,px));selection.setAttribute("width",Math.abs(px-drag));});overlay.addEventListener("pointerup",event=>{const rect=svg.getBoundingClientRect(),finish=(event.clientX-rect.left)/rect.width*width,begin=drag;drag=null;selection.setAttribute("visibility","hidden");if(begin===null||Math.abs(finish-begin)<18)return;const a=localIndex(Math.min(begin,finish)),b=localIndex(Math.max(begin,finish));nativeChartZoom.set(hostId,[start+a,start+b]);renderNativeStackedBars(hostId,rows,{labelKey,series,showTotals});});overlay.addEventListener("dblclick",()=>{nativeChartZoom.delete(hostId);renderNativeStackedBars(hostId,rows,{labelKey,series,showTotals});});
    installPinchZoom(overlay,svg,hostId,rows,start,end,()=>renderNativeStackedBars(hostId,rows,{labelKey,series,showTotals}));
    host._p2kResetZoom=()=>{nativeChartZoom.delete(hostId);renderNativeStackedBars(hostId,rows,{labelKey,series,showTotals});};
  }
  function renderNativeLine(hostId, rows, { xKey = "month", series = [], tooltipExtra = null, futureBoundary = null, yMin = null, yMax = null, invertY = false, axisFormatter = null } = {}) {
    const host = byId(hostId); if (!host) return; host.replaceChildren(); if (!Array.isArray(rows) || rows.length < 1) return nativeChartEmpty(host, "No database data is available.");
    let start = 0, end = rows.length - 1; const zoom = nativeChartZoom.get(hostId); if (zoom) { start = zoom[0]; end = zoom[1]; }
    const visible = rows.slice(start, end + 1), width = 760, height = 230, margin = { left: 52, right: 16, top: 13, bottom: 38 }, innerWidth = width - margin.left - margin.right, innerHeight = height - margin.top - margin.bottom;
    const values = visible.flatMap(row => series.map(item => row[item.key])).map(Number).filter(Number.isFinite);
    let minimum = yMin == null ? 0 : Number(yMin), maximum = yMax == null ? Math.max(1, ...(values.length ? values : [1])) : Number(yMax);
    if (!Number.isFinite(minimum)) minimum = 0; if (!Number.isFinite(maximum)) maximum = Math.max(1, minimum + 1); if (maximum <= minimum) maximum = minimum + 1;
    const range = maximum - minimum, svg = nativeSVG("svg", { viewBox: `0 0 ${width} ${height}`, role: "img", preserveAspectRatio: "none" }); host.appendChild(svg);
    const X = index => margin.left + (visible.length === 1 ? innerWidth / 2 : innerWidth * index / (visible.length - 1));
    const Y = value => { const ratio = Math.max(0, Math.min(1, (Number(value) - minimum) / range)); return invertY ? margin.top + innerHeight * ratio : margin.top + innerHeight - innerHeight * ratio; };
    const formatAxis = value => typeof axisFormatter === "function" ? axisFormatter(value) : Number(value).toFixed(Math.abs(range) < 10 ? 1 : 0);
    for (let i = 0; i <= 4; i += 1) { const y = margin.top + innerHeight * i / 4, value = invertY ? minimum + range * i / 4 : maximum - range * i / 4; svg.appendChild(nativeSVG("line", { x1: margin.left, y1: y, x2: width - margin.right, y2: y, class: "p2k-chart-grid" })); const label = nativeSVG("text", { x: margin.left - 6, y: y + 3, "text-anchor": "end", class: "p2k-chart-axis" }); label.textContent = formatAxis(value); svg.appendChild(label); }
    if (futureBoundary) {
      const boundaryIndex=visible.findIndex(row=>String(row[xKey])===String(futureBoundary.key));
      if(boundaryIndex>=0){const next=Math.min(visible.length-1,boundaryIndex+1),fraction=Math.max(0,Math.min(1,Number(futureBoundary.fraction)||0)),bx=X(boundaryIndex)+(X(next)-X(boundaryIndex))*fraction;svg.appendChild(nativeSVG("rect",{x:bx,y:margin.top,width:Math.max(0,width-margin.right-bx),height:innerHeight,fill:"rgba(246,183,60,.045)"}));const line=nativeSVG("line",{x1:bx,y1:margin.top,x2:bx,y2:margin.top+innerHeight,stroke:"#f6b73c","stroke-dasharray":"5 4","stroke-width":"1.5"});svg.appendChild(line);const label=nativeSVG("text",{x:Math.min(width-margin.right-3,bx+5),y:margin.top+11,class:"p2k-chart-axis"});label.textContent=futureBoundary.label||"Today · future →";svg.appendChild(label);}
    }
    series.forEach(item => { let drawing=false,d="";visible.forEach((row,index)=>{const value=row[item.key];if(value==null||!Number.isFinite(Number(value))){drawing=false;return;}d+=`${drawing?"L":"M"}${X(index)},${Y(value)} `;drawing=true;});if(d.trim()){const path=nativeSVG("path",{d:d.trim(),stroke:item.color,class:"p2k-chart-line"});svg.appendChild(path);} });
    const cross = nativeSVG("line", { y1: margin.top, y2: margin.top + innerHeight, class: "p2k-chart-crosshair", visibility: "hidden" }), selection = nativeSVG("rect", { y: margin.top, height: innerHeight, class: "p2k-chart-selection", visibility: "hidden" }); svg.append(cross, selection); const overlay = nativeSVG("rect", { x: margin.left, y: margin.top, width: innerWidth, height: innerHeight, fill: "transparent", style: "cursor:crosshair" }); svg.appendChild(overlay); let drag = null;
    const localIndex = px => Math.max(0, Math.min(visible.length - 1, Math.round((px - margin.left) / innerWidth * Math.max(1, visible.length - 1))));
    const tipHTML = row => `<strong>${escapeHTML(String(row[xKey]))}</strong><br>${series.map(item => `<span style="color:${item.color}">●</span> ${escapeHTML(item.label)}: <b>${row[item.key] == null ? "—" : Number(row[item.key] || 0).toFixed(item.decimals ?? 1)}</b>`).join("<br>")}${typeof tooltipExtra === "function" ? `<br>${tooltipExtra(row)}` : ""}`;
    overlay.addEventListener("pointermove", event => { if(event.pointerType==="touch" && drag===null)return; const rect = svg.getBoundingClientRect(), px = (event.clientX - rect.left) / rect.width * width; if (drag !== null) { selection.setAttribute("visibility", "visible"); selection.setAttribute("x", Math.min(drag, px)); selection.setAttribute("width", Math.abs(px - drag)); return; } const index = localIndex(px), row = visible[index], x = X(index); cross.setAttribute("x1", x); cross.setAttribute("x2", x); cross.setAttribute("visibility", "visible"); nativeChartTip(host, event, tipHTML(row)); });
    overlay.addEventListener("pointerleave", () => { if (drag === null) { cross.setAttribute("visibility", "hidden"); hideNativeChartTip(host); } }); overlay.addEventListener("pointerdown", event => { if(event.pointerType==="touch")return; const rect = svg.getBoundingClientRect(); drag = (event.clientX - rect.left) / rect.width * width; overlay.setPointerCapture(event.pointerId); }); overlay.addEventListener("pointerup", event => { const rect = svg.getBoundingClientRect(), finish = (event.clientX - rect.left) / rect.width * width, begin = drag; drag = null; selection.setAttribute("visibility", "hidden"); if (begin === null || Math.abs(finish - begin) < 18) return; const a = localIndex(Math.min(begin, finish)), b = localIndex(Math.max(begin, finish)); nativeChartZoom.set(hostId, [start + a, start + b]); renderNativeLine(hostId, rows, { xKey, series, tooltipExtra, futureBoundary, yMin, yMax, invertY, axisFormatter }); }); overlay.addEventListener("dblclick", () => { nativeChartZoom.delete(hostId); renderNativeLine(hostId, rows, { xKey, series, tooltipExtra, futureBoundary, yMin, yMax, invertY, axisFormatter }); });
    const tickCount = Math.min(6, visible.length); for (let i = 0; i < tickCount; i += 1) { const index = Math.round((visible.length - 1) * i / Math.max(1, tickCount - 1)), label = nativeSVG("text", { x: X(index), y: height - 12, "text-anchor": i === 0 ? "start" : i === tickCount - 1 ? "end" : "middle", class: "p2k-chart-axis" }); label.textContent = String(visible[index][xKey]); svg.appendChild(label); }
    overlay.addEventListener("click",event=>{const rect=svg.getBoundingClientRect(),px=(event.clientX-rect.left)/rect.width*width,index=localIndex(px),row=visible[index],x=X(index);cross.setAttribute("x1",x);cross.setAttribute("x2",x);cross.setAttribute("visibility","visible");nativeChartTip(host,event,tipHTML(row));});
    installPinchZoom(overlay,svg,hostId,rows,start,end,()=>renderNativeLine(hostId,rows,{xKey,series,tooltipExtra,futureBoundary,yMin,yMax,invertY,axisFormatter}));
    host._p2kResetZoom = () => { nativeChartZoom.delete(hostId); renderNativeLine(hostId, rows, { xKey, series, tooltipExtra, futureBoundary, yMin, yMax, invertY, axisFormatter }); };
  }
  function renderNativeBarLine(hostId, rows, { xKey="month", barKey="points", lineKey="cumulative_points", barLabel="Monthly points", lineLabel="Cumulative points" } = {}) {
    const host=byId(hostId);if(!host)return;host.replaceChildren();if(!Array.isArray(rows)||!rows.length)return nativeChartEmpty(host,"No database data is available.");
    let start=0,end=rows.length-1;const zoom=nativeChartZoom.get(hostId);if(zoom){start=zoom[0];end=zoom[1];}const visible=rows.slice(start,end+1),width=760,height=300,margin={left:56,right:58,top:18,bottom:42},iw=width-margin.left-margin.right,ih=height-margin.top-margin.bottom,lineMax=Math.max(1,...visible.map(r=>Number(r[lineKey])||0)),barMax=Math.max(1,...visible.map(r=>Number(r[barKey])||0)),svg=nativeSVG("svg",{viewBox:`0 0 ${width} ${height}`,role:"img",preserveAspectRatio:"none"});host.appendChild(svg);
    const X=i=>margin.left+iw*(i+.5)/visible.length,YLine=v=>margin.top+ih-ih*Number(v||0)/lineMax,YBar=v=>margin.top+ih-ih*Number(v||0)/barMax,slot=iw/visible.length;for(let i=0;i<=4;i+=1){const y=margin.top+ih-ih*i/4;svg.appendChild(nativeSVG("line",{x1:margin.left,y1:y,x2:width-margin.right,y2:y,class:"p2k-chart-grid"}));const left=nativeSVG("text",{x:margin.left-6,y:y+3,"text-anchor":"end",class:"p2k-chart-axis"});left.textContent=number(Math.round(lineMax*i/4));const right=nativeSVG("text",{x:width-margin.right+6,y:y+3,"text-anchor":"start",class:"p2k-chart-axis"});right.textContent=number(Math.round(barMax*i/4));svg.append(left,right);}
    visible.forEach((r,i)=>{const y=YBar(r[barKey]),rect=nativeSVG("rect",{x:X(i)-slot*.28,y,width:Math.max(2,slot*.56),height:Math.max(1,margin.top+ih-y),rx:3,fill:"#4aa8d8",opacity:.72});svg.appendChild(rect);});const path=nativeSVG("path",{d:visible.map((r,i)=>`${i?"L":"M"}${X(i)},${YLine(r[lineKey])}`).join(" "),stroke:"#f6b73c",class:"p2k-chart-line"});svg.appendChild(path);
    const selection=nativeSVG("rect",{y:margin.top,height:ih,class:"p2k-chart-selection",visibility:"hidden"}),overlay=nativeSVG("rect",{x:margin.left,y:margin.top,width:iw,height:ih,fill:"transparent",style:"cursor:crosshair"});svg.append(selection,overlay);let drag=null;const li=px=>Math.max(0,Math.min(visible.length-1,Math.floor((px-margin.left)/iw*visible.length)));overlay.addEventListener("pointermove",event=>{if(event.pointerType==="touch"&&drag===null)return;const rect=svg.getBoundingClientRect(),px=(event.clientX-rect.left)/rect.width*width;if(drag!==null){selection.setAttribute("visibility","visible");selection.setAttribute("x",Math.min(drag,px));selection.setAttribute("width",Math.abs(px-drag));return;}const r=visible[li(px)];nativeChartTip(host,event,`<strong>${escapeHTML(String(r[xKey]))}</strong><br><span style="color:#4aa8d8">■</span> ${barLabel}: <b>${number(r[barKey])}</b><br><span style="color:#f6b73c">●</span> ${lineLabel}: <b>${number(r[lineKey])}</b>`);});overlay.addEventListener("pointerleave",()=>{if(drag===null)hideNativeChartTip(host)});overlay.addEventListener("pointerdown",event=>{if(event.pointerType==="touch")return;const rect=svg.getBoundingClientRect();drag=(event.clientX-rect.left)/rect.width*width;overlay.setPointerCapture(event.pointerId);});overlay.addEventListener("pointerup",event=>{const rect=svg.getBoundingClientRect(),finish=(event.clientX-rect.left)/rect.width*width,begin=drag;drag=null;selection.setAttribute("visibility","hidden");if(begin===null||Math.abs(finish-begin)<18)return;const a=li(Math.min(begin,finish)),b=li(Math.max(begin,finish));nativeChartZoom.set(hostId,[start+a,start+b]);renderNativeBarLine(hostId,rows,{xKey,barKey,lineKey,barLabel,lineLabel});});overlay.addEventListener("dblclick",()=>{nativeChartZoom.delete(hostId);renderNativeBarLine(hostId,rows,{xKey,barKey,lineKey,barLabel,lineLabel});});const ticks=Math.min(6,visible.length);for(let i=0;i<ticks;i+=1){const j=Math.round((visible.length-1)*i/Math.max(1,ticks-1)),t=nativeSVG("text",{x:X(j),y:height-13,"text-anchor":i===0?"start":i===ticks-1?"end":"middle",class:"p2k-chart-axis"});t.textContent=String(visible[j][xKey]);svg.appendChild(t);}const leftTitle=nativeSVG("text",{x:6,y:16,class:"p2k-chart-axis"});leftTitle.textContent="Cumulative";const rightTitle=nativeSVG("text",{x:width-6,y:16,"text-anchor":"end",class:"p2k-chart-axis"});rightTitle.textContent="Monthly";svg.append(leftTitle,rightTitle);overlay.addEventListener("click",event=>{const rect=svg.getBoundingClientRect(),px=(event.clientX-rect.left)/rect.width*width,r=visible[li(px)];nativeChartTip(host,event,`<strong>${escapeHTML(String(r[xKey]))}</strong><br><span style="color:#4aa8d8">■</span> ${barLabel}: <b>${number(r[barKey])}</b><br><span style="color:#f6b73c">●</span> ${lineLabel}: <b>${number(r[lineKey])}</b>`);});installPinchZoom(overlay,svg,hostId,rows,start,end,()=>renderNativeBarLine(hostId,rows,{xKey,barKey,lineKey,barLabel,lineLabel}));host._p2kResetZoom=()=>{nativeChartZoom.delete(hostId);renderNativeBarLine(hostId,rows,{xKey,barKey,lineKey,barLabel,lineLabel});};
  }

  function renderOpponentTopChart(rows) {
    const host=byId("opponentsTopChart");if(!host)return;host.replaceChildren();const chart=(Array.isArray(rows)?rows:[]).slice(0,15);if(!chart.length)return nativeChartEmpty(host,"No opponent data is available.");
    const width=960,rowHeight=42,height=chart.length*rowHeight+34,margin={left:330,right:82,top:10,bottom:16},innerWidth=width-margin.left-margin.right,maximum=Math.max(1,...chart.map(r=>Number(r.total)||0));const svg=nativeSVG("svg",{viewBox:`0 0 ${width} ${height}`,role:"img",preserveAspectRatio:"xMidYMid meet"});host.appendChild(svg);
    chart.forEach((row,index)=>{const value=Number(row.total)||0,y=margin.top+index*rowHeight+6,barWidth=innerWidth*value/maximum,name=String(row.name||row.slug||"Unknown opponent"),short=name.length>38?name.slice(0,37)+"…":name;const labelX=margin.left-48,label=nativeSVG("text",{x:labelX,y:y+18,"text-anchor":"end",class:"p2k-chart-opponent-name"});label.textContent=short;label.style.cursor=row.slug?"pointer":"default";if(row.slug)label.addEventListener("click",()=>openOpponentProfile(row.slug));svg.appendChild(label);if(row.icon){const image=nativeSVG("image",{href:String(row.icon),x:margin.left-40,y:y-3,width:30,height:30,preserveAspectRatio:"xMidYMid meet",class:"p2k-opponent-logo"});image.style.cursor=row.slug?"pointer":"default";if(row.slug)image.addEventListener("click",()=>openOpponentProfile(row.slug));svg.appendChild(image);}const bar=nativeSVG("rect",{x:margin.left,y,width:Math.max(2,barWidth),height:25,rx:5,fill:"#d98d18"});bar.style.cursor=row.slug?"pointer":"default";bindChartTip(bar,host,()=>`<strong>${escapeHTML(name)}</strong><br>${number(value)} matches · ${number(row.wins)}w / ${number(row.draws)}d / ${number(row.losses)}l · ${number(row.ongoing)} ongoing`);if(row.slug)bar.addEventListener("click",()=>openOpponentProfile(row.slug));svg.appendChild(bar);const count=nativeSVG("text",{x:Math.min(width-margin.right+4,margin.left+barWidth+8),y:y+18,class:"p2k-chart-value"});count.textContent=number(value);svg.appendChild(count);});host.style.minHeight=`${Math.max(420,height)}px`;
  }


    return Object.freeze({ nativeSVG, nativeChartEmpty, renderNativeBars, renderNativePie, renderNativeStackedBars, renderNativeLine, renderNativeBarLine, renderOpponentTopChart });
  };
})();
