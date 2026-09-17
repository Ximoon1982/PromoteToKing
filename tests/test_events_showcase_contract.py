from pathlib import Path
root=Path(__file__).resolve().parents[1]
admin=(root/'assets/js/admin/events-showcase-v2122.js').read_text()
embed=(root/'server/events-showcase/public/embed.php').read_text()
card_embed=(root/'server/events-showcase/public/embed-card.php').read_text()
trophy=(root/'assets/js/admin/trophy-card-presentation-v2122.js').read_text()
loader=(root/'assets/js/admin/tool-registry.js').read_text()

build=(root/'tools/release/v2122/build-package.sh').read_text()
installer=(root/'tools/release/v2122/install-promote-to-king-v2.12.2.sh').read_text()
qualify=(root/'tools/release/v2122/qualify-installer.sh').read_text()
readme=(root/'tools/release/v2122/README.md').read_text()
checks={
'competition card': 'Events showcase' in admin and 'data-v2122-events-showcase' in admin,
'card metrics': 'data-es-arena-metric' in admin and 'data-es-match-metric' in admin,
'pagination 20': 'SEARCH_PAGE_SIZE=20' in admin,
'arena modal save': 'data-es-arena-form' in admin and 'Save arena' in admin,
'enable immediate': 'setArenaEnabled' in admin,
'URL duplicate': 'This arena is already configured (same URL).' in admin,
'top-level inline detail': 'mountEditorInline' in admin and 'd=document.createElement("div")' in admin and 'native.dataset.nativeDetail="events-showcase"' in admin,
'arena child remains dialog': 'd=document.createElement("dialog")' not in admin and '<dialog class="es-arena-editor"' in admin,
'line iframe fixed 280': 'iframeHtml(src=EMBED,height=280' in admin and 'frame.style.height="280px"' in admin,
'card iframe fixed 480': 'CARD_EMBED' in admin and 'iframeHtml(CARD_EMBED,480' in admin and 'cardFrame.style.height="480px"' in admin,
'dual live previews': 'data-es-preview' in admin and 'data-es-card-preview' in admin,
'public titles': '⚔️ Join multi-club arenas ⚔️' in embed and '⚔️ Join daily matches ⚔️' in embed,
'public selector no all': 'data-filter="league"' in embed and 'data-filter="friendly"' in embed and 'data-filter="all"' not in embed,
'line max 4 daily': 'slice(0, 4)' in embed,
'48h league red': 'pm-league-48h' in embed and '48 * 3600000' in embed,
'no recruitment tooltip': 'recruitment-need' not in embed.lower(),
'card v18 r4 titles': '⚔️ Arenas ⚔️' in card_embed and '⚔️ Daily Matches ⚔️' in card_embed,
'card width 260': 'width:260px' in card_embed and 'grid-template-columns:repeat(2,minmax(0,1fr))' in card_embed,
'card max 2 arenas': '.slice(0,2)' in card_embed,
'card max 4 daily': '.slice(0,4)' in card_embed,
'card canonical state': 'api.php?action=state' in card_embed and 'events-showcase-core.js' in card_embed,
'card selector no all': 'data-filter="league"' in card_embed and 'data-filter="friendly"' in card_embed and 'data-filter="all"' not in card_embed,
'trophy exact card border': 'border:1px solid rgba(255,255,255,.09)!important' in trophy,
'trophy exact radius': 'border-radius:11px!important' in trophy,
'trophy exact background': 'background:rgba(0,0,0,.18)!important' in trophy,
'trophy exact square art': 'aspect-ratio:1/1!important' in trophy,
'trophy exact title': 'color:#f6b73c!important' in trophy and 'font-size:1.08rem!important' in trophy,
'trophy title border suppressed': '[data-r538-title]' in trophy and 'border-bottom:0!important' in trophy,
'loader immutable key': 'p2k-2.12.2-src-79c27cc76eb1f256' in loader,
'loader showcase': 'events-showcase-v2122.js' in loader,
'loader trophy fix': 'trophy-card-presentation-v2122.js' in loader,
'incremental cumulative base': 'BASE=c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303' in build and '2.12.0 or 2.12.1' in installer,
'incremental cumulative scope': 'comm -12 "$work/changed.all" "$work/production.all" >"$PACKAGE/FILES.list"' in build and '[[ $(wc -l <"$PACKAGE/FILES.list") -gt 11 ]]' in qualify,
'incremental supports both 2.12.x': 'qualify_source "$BASE_2120" from-2120 1' in qualify and 'qualify_source "$BASE_2121" from-2121 0' in qualify and '2.12.0 or `2.12.1`' in readme,
'incremental degraded repair': ': >"$t/assets/js/admin/tool-registry.js"' in qualify and 'does **not** require byte-identical source files' in readme,
'incremental no full convergence': 'SUPPORTED_TREES' not in build and 'enumerate_installed_tree' not in installer,
'embed immutable key': '?v=p2k-2.12.2-src-79c27cc76eb1f256' in embed,
'card embed immutable key': '?v=p2k-2.12.2-src-79c27cc76eb1f256' in card_embed,
}
failed=[k for k,v in checks.items() if not v]
for k,v in checks.items(): print(('PASS' if v else 'FAIL'),k)
if failed: raise SystemExit('failed: '+', '.join(failed))
