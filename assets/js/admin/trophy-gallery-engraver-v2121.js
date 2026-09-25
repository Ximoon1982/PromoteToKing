(() => {
"use strict";
if(window.P2K_TROPHY_ENGRAVER_V2121)return;
const SCRIPT=new URL(document.currentScript?.src||location.href),ROOT=new URL("../../../",SCRIPT),EDITOR_URL=new URL("assets/trophy-gallery/engraving/editor-v2121.html",ROOT),CACHE_KEY=SCRIPT.searchParams.get("v");if(CACHE_KEY)EDITOR_URL.searchParams.set("v",CACHE_KEY);const EDITOR=EDITOR_URL.href;
let frame=null,slot='vignette',receiver=null,modal=null,previousFocus=null;
function unlock(){document.body?.classList.remove("p2k-engraver-open")}
function close(){if(modal)modal.hidden=true;unlock();const focus=previousFocus;previousFocus=null;if(focus?.isConnected&&typeof focus.focus==="function")focus.focus({preventScroll:true})}
function ensure(){if(modal)return modal;modal=document.createElement('div');modal.id='p2kTrophyEngraverV2121';modal.className='p2k-engraver-modal';modal.hidden=true;modal.innerHTML='<div class="p2k-engraver-modal-card"><button type="button" data-v2121-engraver-close>Close</button><div data-v2121-frame-host></div></div>';document.body.appendChild(modal);modal.querySelector('[data-v2121-engraver-close]').onclick=close;modal.addEventListener('click',e=>{if(e.target===modal)close()});return modal}
function open(nextSlot,onFile){slot=nextSlot||'vignette';receiver=onFile;previousFocus=document.activeElement;const box=ensure(),host=box.querySelector('[data-v2121-frame-host]');if(!frame){frame=document.createElement('iframe');frame.className='p2k-engraver';frame.title='Trophy engraving editor';frame.src=EDITOR;frame.onload=()=>frame.contentWindow?.postMessage({type:'p2k-trophy-editor-open'},location.origin);host.appendChild(frame)}box.hidden=false;document.body?.classList.add("p2k-engraver-open");frame.contentWindow?.postMessage({type:'p2k-trophy-editor-open'},location.origin)}
window.addEventListener('message',e=>{if(e.origin!==location.origin||e.source!==frame?.contentWindow||e.data?.type!=='p2k-trophy-engraving'||!e.data.blob)return;const file=new File([e.data.blob],e.data.name||'engraving.png',{type:e.data.blob.type||'image/png'});file.__engraving=true;receiver?.(slot,file);close()});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal&&!modal.hidden){e.preventDefault();e.stopImmediatePropagation();close()}},true);
window.addEventListener('p2k-admin-shell-route',e=>{if(e.detail?.nativeKey!=='trophy-gallery')close()});
window.addEventListener('pagehide',close);
window.P2K_TROPHY_ENGRAVER_V2121=Object.freeze({open,close});
})();