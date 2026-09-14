(() => {
"use strict";
if(window.P2K_TROPHY_ENGRAVER_V2121)return;
const ROOT=new URL("../../../",document.currentScript?.src||location.href),EDITOR=new URL("assets/trophy-gallery/engraving/editor-v2121.html",ROOT).href;
let frame=null,slot='vignette',receiver=null,modal=null;
function ensure(){if(modal)return modal;modal=document.createElement('div');modal.id='p2kTrophyEngraverV2121';modal.className='p2k-engraver-modal';modal.hidden=true;modal.innerHTML='<div class="p2k-engraver-modal-card"><button type="button" data-v2121-engraver-close>Close</button><div data-v2121-frame-host></div></div>';document.body.appendChild(modal);modal.querySelector('[data-v2121-engraver-close]').onclick=()=>modal.hidden=true;return modal}
function open(nextSlot,onFile){slot=nextSlot||'vignette';receiver=onFile;const box=ensure(),host=box.querySelector('[data-v2121-frame-host]');if(!frame){frame=document.createElement('iframe');frame.className='p2k-engraver';frame.title='Trophy engraving editor';frame.src=EDITOR;frame.onload=()=>frame.contentWindow?.postMessage({type:'p2k-trophy-editor-open'},location.origin);host.appendChild(frame)}box.hidden=false;frame.contentWindow?.postMessage({type:'p2k-trophy-editor-open'},location.origin)}
window.addEventListener('message',e=>{if(e.origin!==location.origin||e.source!==frame?.contentWindow||e.data?.type!=='p2k-trophy-engraving'||!e.data.blob)return;const file=new File([e.data.blob],e.data.name||'engraving.png',{type:e.data.blob.type||'image/png'});file.__engraving=true;receiver?.(slot,file);ensure().hidden=true});
window.P2K_TROPHY_ENGRAVER_V2121=Object.freeze({open});
})();
