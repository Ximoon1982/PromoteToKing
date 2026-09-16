from pathlib import Path
root=Path(__file__).resolve().parents[1]
admin=(root/'assets/js/admin/events-showcase-v2122.js').read_text()
embed=(root/'server/events-showcase/public/embed.php').read_text()
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
'max two arena height': '.slice(0,2).length' in admin and '226+publicArenaCount()*27' in admin,
'plain iframe': '<iframe src="${EMBED}"' in admin and 'height="${computedHeight()}"' in admin,
'public titles': '⚔️ Join multi-club arenas ⚔️' in embed and '⚔️ Join daily matches ⚔️' in embed,
'public selector no all': 'data-filter="league"' in embed and 'data-filter="friendly"' in embed and 'data-filter="all"' not in embed,
'max 4 daily': 'slice(0, 4)' in embed,
'48h league red': 'pm-league-48h' in embed and '48 * 3600000' in embed,
'no recruitment tooltip': 'recruitment-need' not in embed.lower(),
'trophy exact card border': 'border:1px solid rgba(255,255,255,.09)!important' in trophy,
'trophy exact radius': 'border-radius:11px!important' in trophy,
'trophy exact background': 'background:rgba(0,0,0,.18)!important' in trophy,
'trophy exact square art': 'aspect-ratio:1/1!important' in trophy,
'trophy exact title': 'color:#f6b73c!important' in trophy and 'font-size:1.08rem!important' in trophy,
'trophy title border suppressed': '[data-r538-title]' in trophy and 'border-bottom:0!important' in trophy,
'loader immutable key': 'p2k-2.12.2-src-79c27cc76eb1f256' in loader,
'loader showcase': 'events-showcase-v2122.js' in loader,
'loader trophy fix': 'trophy-card-presentation-v2122.js' in loader,
'incremental exact base': 'BASE=3568e8c36d900fab341c3ded43b97adee3fb6263' in build and 'requires qualified v2.12.1' in installer,
'incremental 11 file scope': 'payload_scope=11 production files' in build and '[[ $(wc -l <"$PACKAGE/FILES.list") -eq 11 ]]' in qualify,
'incremental rejects older': 'scope-limited installer unexpectedly accepted v2.12.0' in qualify and 'intentionally rejects v2.11.x and v2.12.0' in readme,
'incremental no full convergence': 'SUPPORTED_TREES' not in build and 'enumerate_installed_tree' not in installer,
'embed immutable key': '?v=p2k-2.12.2-src-79c27cc76eb1f256' in embed,
}
failed=[k for k,v in checks.items() if not v]
for k,v in checks.items(): print(('PASS' if v else 'FAIL'),k)
if failed: raise SystemExit('failed: '+', '.join(failed))
