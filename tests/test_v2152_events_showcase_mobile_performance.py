from pathlib import Path

root = Path(__file__).resolve().parents[1]
js = (root / "assets/js/admin/events-showcase-v2122.js").read_text(encoding="utf-8")

assert 'data-es-load-preview' in js
assert 'data-es-load-card-preview' in js
assert 'loading="lazy" hidden' in js
assert 'function loadPreview(kind,refresh=false)' in js
assert 'function suspendPreviews()' in js
assert 'function scheduleLoadedPreviewRefresh()' in js
assert 'refreshPreview()' not in js

# Rendering selected rows must be side-effect free: no preview/network refresh.
start = js.index("function renderSelected()")
end = js.index("function renderSearch()", start)
render_selected = js[start:end]
assert "loadPreview(" not in render_selected
assert "postMessage(" not in render_selected
assert ".src=" not in render_selected

# Opening/initializing the editor must not auto-load previews or block on catalog.
start = js.index("async function initialize()")
end = js.index("function bindEditor(d)", start)
startup = js[start:end]
assert "await refreshCatalog(" not in startup
assert "loadPreview(" not in startup
assert "refreshPreview" not in startup
assert "catalogPromise=refreshCatalog(false)" in startup

# Preview refreshes happen only for already-loaded frames after persisted changes.
assert 'if(frame?.dataset.loaded==="1")frame.contentWindow?.postMessage' in js
assert 'scheduleLoadedPreviewRefresh();void refreshCard();' in js

print("PASS Events Showcase mobile performance contract")
