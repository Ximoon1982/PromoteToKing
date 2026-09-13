(() => {
"use strict";
if (window.__P2K_TROPHY_V2120_MOBILE_FIX) return;
window.__P2K_TROPHY_V2120_MOBILE_FIX = true;

const STYLE_ID = "p2kTrophyV2120MobileFixStyle";
const STYLE = `
.p2k-trophy-root .p2k-trophy-group [data-r538-title]{border-bottom:0!important}
.p2k-trophy-root .p2k-engraver-modal{z-index:10080!important}
@media(max-width:560px){
 .p2k-trophy-root .p2k-engraver-modal{padding:4px!important}
 .p2k-trophy-root .p2k-engraver-modal-card{width:100%!important;max-width:100%!important;height:calc(100dvh - 8px)!important;max-height:calc(100dvh - 8px)!important;border-radius:10px!important}
 .p2k-trophy-root .p2k-engraver{height:calc(100dvh - 58px)!important;min-height:0!important}
}
`;

function ensureStyle() {
 const head=document.head||document.documentElement;
 let style=document.getElementById(STYLE_ID);
 if(!style){style=document.createElement("style");style.id=STYLE_ID;style.textContent=STYLE;head.appendChild(style)}
 else if(style.parentNode!==head)head.appendChild(style);
}
function setStatus(form,message){const status=form?.querySelector("[data-status]");if(status)status.textContent=message}
function recordId(form){return String(form?.elements?.id?.value||"").trim()}
function closeEngraver(modal){
 if(!modal)return;
 modal.hidden=true;
 modal.setAttribute("aria-hidden","true");
 const frame=modal.querySelector("iframe.p2k-engraver");
 if(frame)frame.hidden=true;
 if(!document.querySelector(".p2k-engraver-modal:not([hidden])"))document.body.classList.remove("p2k-engraver-open");
}
function ensureModal(form,frame){
 let modal=frame.closest(".p2k-engraver-modal")||form.querySelector(":scope > .p2k-engraver-modal");
 if(!modal){
  modal=document.createElement("div");
  modal.className="p2k-engraver-modal";
  modal.hidden=true;
  modal.setAttribute("aria-hidden","true");
  modal.innerHTML='<section class="p2k-engraver-modal-card" role="dialog" aria-modal="true"><header class="p2k-engraver-modal-head"><strong>Trophy engraving editor</strong><button type="button" data-engraver-close aria-label="Close">×</button></header><div data-engraver-body></div></section>';
  modal.querySelector("[data-engraver-body]").appendChild(frame);
  form.appendChild(modal);
 }
 if(modal.dataset.v2120CloseBound!=="1"){
  modal.dataset.v2120CloseBound="1";
  modal.addEventListener("click",event=>{if(event.target===modal||event.target.closest?.("[data-engraver-close]"))closeEngraver(modal)},true);
 }
 return modal;
}
function wakeEnhancer(form){
 const marker=document.createElement("span");
 marker.hidden=true;
 marker.dataset.v2120EngraverRefresh="1";
 form.appendChild(marker);
 marker.remove();
}
function sendOpen(frame){
 try{frame.contentWindow?.postMessage({type:"p2k-trophy-editor-open",team:"Promote to King"},location.origin)}catch{}
}
function activateFrame(frame,form){
 if(frame.dataset.v2120LoadBound!=="1"){
  frame.dataset.v2120LoadBound="1";
  frame.addEventListener("load",()=>{
   try{if(frame.contentDocument?.URL==="about:blank")return}catch{}
   wakeEnhancer(form);
   setTimeout(()=>sendOpen(frame),0);
  });
 }
 if(!frame.getAttribute("src")){
  const source=frame.dataset.src;
  if(!source){setStatus(form,"Trophy engraving editor unavailable.");return false}
  frame.setAttribute("src",source);
 }else{
  try{
   if(frame.contentDocument?.readyState==="complete"&&frame.contentDocument?.URL!=="about:blank"){
    wakeEnhancer(form);
    setTimeout(()=>sendOpen(frame),0);
   }
  }catch{}
 }
 return true;
}
function eventButton(event){
 const path=typeof event.composedPath==="function"?event.composedPath():[];
 for(const node of path){if(node instanceof Element&&node.matches?.("[data-engrave]"))return node}
 const target=event.target instanceof Element?event.target:event.target?.parentElement;
 return target?.closest?.("[data-engrave]")||null;
}
function openEngraver(event,button){
 const form=button.closest("form");
 if(!form)return;
 event.preventDefault();
 event.stopPropagation();
 event.stopImmediatePropagation();
 if(!recordId(form)){setStatus(form,"Save the Trophy before creating artwork.");return}
 const frame=form.querySelector("iframe.p2k-engraver");
 if(!frame){setStatus(form,"Trophy engraving editor unavailable.");return}
 const modal=ensureModal(form,frame);
 frame.dataset.slot=button.dataset.engrave||"vignette";
 frame.hidden=false;
 modal.hidden=false;
 modal.setAttribute("aria-hidden","false");
 document.body.classList.add("p2k-engraver-open");
 activateFrame(frame,form);
}

ensureStyle();
window.addEventListener("pageshow",ensureStyle);
window.addEventListener("click",event=>{const button=eventButton(event);if(button)openEngraver(event,button)},true);
window.addEventListener("message",event=>{
 if(event.origin!==location.origin||event.data?.type!=="p2k-trophy-editor-ready")return;
 const frame=[...document.querySelectorAll("iframe.p2k-engraver")].find(item=>item.contentWindow===event.source);
 if(!frame)return;
 const form=frame.closest("form");
 if(!form)return;
 wakeEnhancer(form);
 setTimeout(()=>sendOpen(frame),0);
});
document.addEventListener("keydown",event=>{if(event.key!=="Escape")return;const modal=document.querySelector(".p2k-engraver-modal:not([hidden])");if(modal){event.preventDefault();event.stopImmediatePropagation();closeEngraver(modal)}},true);
})();
