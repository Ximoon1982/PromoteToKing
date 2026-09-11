(() => {
"use strict";
if (window.__P2K_TROPHY_R5FIX3_10) return;
window.__P2K_TROPHY_R5FIX3_10 = true;

const HOST_SELECTOR = '#adminShellNativeDetailHost[data-native-detail="trophy-gallery"]';
const PREVIEW_SELECTOR = `${HOST_SELECTOR} .p2k-media-preview`;
const STYLE_ID = 'p2kTrophyR5Fix310Style';
const STYLE = `
${PREVIEW_SELECTOR}{
  all:unset!important;
  box-sizing:border-box!important;
  position:relative!important;
  display:block!important;
  width:100%!important;
  max-width:100%!important;
  height:280px!important;
  min-height:280px!important;
  max-height:280px!important;
  overflow:hidden!important;
  contain:layout paint!important;
  isolation:isolate!important;
  border:1px solid rgba(255,255,255,.08)!important;
  border-radius:10px!important;
  background:#0d0c0b!important;
  cursor:zoom-in!important
}
${PREVIEW_SELECTOR} img{
  box-sizing:border-box!important;
  position:absolute!important;
  inset:0!important;
  display:block!important;
  width:100%!important;
  height:100%!important;
  max-width:100%!important;
  max-height:100%!important;
  min-width:0!important;
  min-height:0!important;
  margin:0!important;
  padding:0!important;
  border:0!important;
  object-fit:contain!important;
  object-position:50% 50%!important;
  background:transparent!important;
  transform:none!important
}
`;

function ensureStyle() {
  const head = document.head || document.documentElement;
  let style = document.getElementById(STYLE_ID);
  if (!style) {
    style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = STYLE;
    head.appendChild(style);
    return;
  }
  if (style.parentNode !== head) head.appendChild(style);
}

function importantStyle(node, values) {
  if (!node) return;
  for (const [property, value] of Object.entries(values)) {
    node.style.setProperty(property, value, 'important');
  }
}

function containPreview(preview) {
  if (!(preview instanceof HTMLElement)) return;
  importantStyle(preview, {
    'box-sizing': 'border-box',
    'position': 'relative',
    'display': 'block',
    'width': '100%',
    'max-width': '100%',
    'height': '280px',
    'min-height': '280px',
    'max-height': '280px',
    'overflow': 'hidden',
    'contain': 'layout paint',
    'isolation': 'isolate'
  });
  preview.querySelectorAll('img').forEach((img) => importantStyle(img, {
    'box-sizing': 'border-box',
    'position': 'absolute',
    'inset': '0',
    'display': 'block',
    'width': '100%',
    'height': '100%',
    'max-width': '100%',
    'max-height': '100%',
    'min-width': '0',
    'min-height': '0',
    'margin': '0',
    'padding': '0',
    'border': '0',
    'object-fit': 'contain',
    'object-position': '50% 50%',
    'transform': 'none'
  }));
  preview.dataset.r5310Contained = '1';
}

function scan(root = document) {
  ensureStyle();
  root.querySelectorAll?.(PREVIEW_SELECTOR).forEach(containPreview);
  if (root instanceof Element && root.matches?.(PREVIEW_SELECTOR)) containPreview(root);
}

let queued = false;
const observer = new MutationObserver(() => {
  ensureStyle();
  if (queued) return;
  queued = true;
  requestAnimationFrame(() => {
    queued = false;
    scan();
  });
});

ensureStyle();
scan();
observer.observe(document.documentElement, { childList: true, subtree: true });
})();
