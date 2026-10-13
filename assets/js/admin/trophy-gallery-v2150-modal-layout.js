(() => {
"use strict";
if (window.__P2K_TROPHY_V2150_MODAL_LAYOUT) return;
window.__P2K_TROPHY_V2150_MODAL_LAYOUT = true;

const LANDSCAPE_RATIO = 1.10;
const DESKTOP = "(min-width: 781px)";
const VIEWERS = ["#p2kR538Viewer", "#p2kTrophyViewer"];

const targets = [
  {
    image: "#p2kR538Modal .p2k-r538-modal-art img[data-r538-view-image]",
    modal: ".p2k-r538-modal",
  },
  {
    image: "#p2kTrophyModal .p2k-trophy-modal-art img",
    modal: "#p2kTrophyModal > article",
  },
];

function classify(img, modalSelector) {
  if (!(img instanceof HTMLImageElement)) return false;
  const modal = modalSelector.startsWith("#")
    ? document.querySelector(modalSelector)
    : img.closest(modalSelector);
  if (!modal || !img.naturalWidth || !img.naturalHeight) return false;
  const ratio = img.naturalWidth / img.naturalHeight;
  modal.dataset.v2150AspectRatio = ratio.toFixed(4);
  const landscape = window.matchMedia(DESKTOP).matches && ratio > LANDSCAPE_RATIO;
  modal.classList.toggle("p2k-v2150-landscape", landscape);
  modal.classList.toggle("p2k-v2150-side-by-side", window.matchMedia(DESKTOP).matches && !landscape);
  return true;
}

function bindImage(img, modalSelector) {
  if (!(img instanceof HTMLImageElement)) return;
  const key = modalSelector.includes("p2kTrophyModal") ? "base" : "r538";
  const attr = "v2150AspectBound" + key;
  if (img.dataset[attr] !== "1") {
    img.dataset[attr] = "1";
    img.addEventListener("load", () => classify(img, modalSelector));
  }
  if (img.complete && img.naturalWidth) classify(img, modalSelector);
}

function scan() {
  for (const target of targets) {
    document.querySelectorAll(target.image).forEach(img => bindImage(img, target.modal));
  }
}

function scanBurst() {
  scan();
  requestAnimationFrame(scan);
  setTimeout(scan, 0);
  setTimeout(scan, 50);
  setTimeout(scan, 200);
}

function openBaseViewerFromImage(img) {
  const modal = img.closest("#p2kTrophyModal");
  if (!modal) return;
  const button = modal.querySelector("[data-enlarge]");
  if (button) {
    button.click();
    return;
  }
  /* Public cleanup removes the visible enlarge button after opening.
     Recreate the existing viewer semantics without altering Trophy data. */
  let viewer = document.querySelector("#p2kTrophyViewer");
  if (!viewer) {
    viewer = document.createElement("div");
    viewer.id = "p2kTrophyViewer";
    viewer.className = "p2k-trophy-viewer";
    viewer.hidden = true;
    document.body.appendChild(viewer);
    viewer.addEventListener("click", event => {
      if (event.target === viewer || event.target.closest("[data-view-close]")) viewer.hidden = true;
    });
  }
  viewer.innerHTML = '<button type="button" data-view-close aria-label="Close">×</button><img alt="">';
  const viewImg = viewer.querySelector("img");
  viewImg.src = img.currentSrc || img.src;
  viewImg.alt = img.alt || "Trophy artwork";
  viewer.hidden = false;
}

document.addEventListener("click", event => {
  const baseImage = event.target.closest?.("#p2kTrophyModal .p2k-trophy-modal-art img");
  if (baseImage) {
    openBaseViewerFromImage(baseImage);
    return;
  }
  if (event.target.closest?.("[data-open], .p2k-trophy-card")) scanBurst();
}, true);

const observer = new MutationObserver(scanBurst);
observer.observe(document.documentElement, {
  childList: true,
  subtree: true,
  attributes: true,
  attributeFilter: ["src", "hidden"],
});

function reclassify() { scanBurst(); }
window.addEventListener("resize", reclassify);

document.addEventListener("keydown", event => {
  if (event.key !== "Escape") return;
  for (const selector of VIEWERS) {
    const viewer = document.querySelector(selector);
    if (viewer && !viewer.hidden) {
      viewer.hidden = true;
      event.preventDefault();
      event.stopPropagation();
      return;
    }
  }
});

scanBurst();
})();
