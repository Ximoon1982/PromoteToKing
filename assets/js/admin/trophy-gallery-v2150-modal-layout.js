(() => {
"use strict";
if (window.__P2K_TROPHY_V2150_MODAL_LAYOUT) return;
window.__P2K_TROPHY_V2150_MODAL_LAYOUT = true;

const MODAL = "#p2kR538Modal .p2k-r538-modal";
const IMAGE = "#p2kR538Modal .p2k-r538-modal-art img[data-r538-view-image]";
const VIEWER = "#p2kR538Viewer";
const LANDSCAPE_RATIO = 1.10;
const DESKTOP = "(min-width: 781px)";

function applyOrientation(img) {
  if (!(img instanceof HTMLImageElement)) return;
  const modal = img.closest(".p2k-r538-modal");
  if (!modal || !img.naturalWidth || !img.naturalHeight) return;
  const ratio = img.naturalWidth / img.naturalHeight;
  modal.dataset.v2150AspectRatio = ratio.toFixed(4);
  modal.classList.toggle("p2k-v2150-landscape", window.matchMedia(DESKTOP).matches && ratio > LANDSCAPE_RATIO);
  modal.classList.toggle("p2k-v2150-side-by-side", !window.matchMedia(DESKTOP).matches ? false : ratio <= LANDSCAPE_RATIO);
}

function bindImage(img) {
  if (!(img instanceof HTMLImageElement)) return;
  if (img.dataset.v2150AspectBound !== "1") {
    img.dataset.v2150AspectBound = "1";
    img.addEventListener("load", () => applyOrientation(img));
  }
  if (img.complete && img.naturalWidth) applyOrientation(img);
}

function scan(root = document) {
  root.querySelectorAll?.(IMAGE).forEach(bindImage);
  if (root instanceof Element && root.matches?.(IMAGE)) bindImage(root);
}

let queued = false;
const observer = new MutationObserver(() => {
  if (queued) return;
  queued = true;
  requestAnimationFrame(() => {
    queued = false;
    scan();
  });
});

function reclassify() {
  document.querySelectorAll(IMAGE).forEach(applyOrientation);
}

document.addEventListener("keydown", (event) => {
  if (event.key !== "Escape") return;
  const viewer = document.querySelector(VIEWER);
  if (viewer && !viewer.hidden) {
    viewer.hidden = true;
    event.preventDefault();
    event.stopPropagation();
  }
});

window.addEventListener("resize", reclassify);
scan();
observer.observe(document.documentElement, {childList: true, subtree: true});
})();
