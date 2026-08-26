(()=>{
  'use strict';
  let tip=null,hideTimer=0;
  function ensureTip(){
    if(tip)return tip;
    tip=document.createElement('div');tip.className='p2k-svg-touch-title';tip.hidden=true;
    Object.assign(tip.style,{position:'fixed',zIndex:'100000',maxWidth:'min(320px,calc(100vw - 24px))',padding:'7px 9px',border:'1px solid rgba(246,183,60,.42)',borderRadius:'7px',background:'#0e0d0c',color:'#f5eee5',font:'12px/1.4 Arial,sans-serif',boxShadow:'0 8px 24px rgba(0,0,0,.45)',pointerEvents:'none',whiteSpace:'normal'});
    document.body.appendChild(tip);return tip;
  }
  function titleFor(target){
    const el=target?.closest?.('svg [data-p2k-touch-title], svg circle, svg rect, svg path, svg line, svg polyline, svg polygon');
    if(!el)return '';
    if(el.matches?.('[role=button],a,button')||el.closest?.('a,button'))return '';
    const explicit=el.getAttribute?.('data-p2k-touch-title');if(explicit)return explicit;
    return String(el.querySelector?.(':scope > title')?.textContent||'').trim();
  }
  function show(text,x,y){
    if(!text)return;const node=ensureTip();node.textContent=text;node.hidden=false;
    const margin=12;node.style.left='0px';node.style.top='0px';
    const r=node.getBoundingClientRect(),left=Math.max(margin,Math.min(innerWidth-r.width-margin,x+10)),top=Math.max(margin,Math.min(innerHeight-r.height-margin,y-r.height-12));
    node.style.left=`${left}px`;node.style.top=`${top}px`;clearTimeout(hideTimer);hideTimer=setTimeout(()=>{node.hidden=true;},4500);
  }
  document.addEventListener('pointerup',event=>{
    if(event.pointerType!=='touch'&&event.pointerType!=='pen')return;
    const text=titleFor(event.target);if(text)show(text,event.clientX,event.clientY);
  },{passive:false,capture:true});
  document.addEventListener('click',event=>{
    if(event.detail===0)return; // keyboard activation should keep native semantics
    if(event.pointerType==='touch')return;
    // Mouse click is only a fallback for charts whose values are otherwise hover-only.
    const text=titleFor(event.target);if(text&&matchMedia('(hover: none)').matches)show(text,event.clientX,event.clientY);
  },true);
  document.addEventListener('pointerdown',event=>{if(tip&&!tip.hidden&&!titleFor(event.target))tip.hidden=true;},true);
})();
