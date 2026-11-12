"use strict";
const assert=require("node:assert/strict"),fs=require("node:fs"),path=require("node:path");
const read=p=>fs.readFileSync(path.join(__dirname,"..",p),"utf8");
const admin=read("assets/js/admin/widgets-v2153.js"),embed=read("assets/js/widgets/p2k-embed-v2153.js");
const css=read("assets/css/widgets/p2k-embed-v2153.css"),spot=read("assets/js/widgets/p2k-spotlight-v2159.js");
const styles=read("assets/css/widgets/p2k-spotlight-v2159.css"),api=read("server/widgets/public/api.php");
const cfg=read("server/widgets/src/WidgetConfigStore.php"),html=read("server/widgets/public/embed.php");
const controls=admin.slice(admin.indexOf("  const heights="),admin.indexOf("  function fields()"));
assert(controls.includes('function code()'));
assert(!controls.includes("<script"),"Embed snippets must not require script tags");
assert(!controls.includes("postMessage"),"Host-side resize protocol must be absent");
assert(controls.includes("height=\"\u0024{height}\""),"Iframe HTML must contain fixed pixel height");
assert(controls.includes("height:\u0024{height}px"),"Iframe style must fix CSS height too");
for(const id of ["club-pulse","recruitment-radar","trophy-spotlight","rivalry-spotlight","club-progress","around-the-world","player-spotlight","tournament-winners"])assert(controls.includes("'"+id+"':"),"Missing fixed height "+id);
assert(admin.includes("theme||'auto'"));
assert(cfg.includes("'theme'=>'auto'"));
assert(!admin.includes("window.addEventListener('message',onHeight)"));
console.log("PASS script-free fixed-height embeds for all 8 widgets; auto theme defaults");
for(const forbidden of [
"Data: Green Team Points and published Insights",
"Green data · ",
"published trophies · P2K gallery",
"Map highlights countries with known stored codes only",
"Source: canonical P2K opponent metadata"
])assert(!embed.includes(forbidden),"Old footer still visible: "+forbidden);
assert(embed.includes("code==='XX'?'International'"));
assert(!embed.includes("countryName(code));"));
assert(embed.includes("media(t.vignette_media_id)"));
assert(embed.includes("media(t.modal_media_id)"));
assert(embed.includes("image.onerror="));
assert(css.includes("aspect-ratio:1 / 1"));
assert(css.includes("object-fit:contain"));
assert(embed.includes("const mapPaths=["));
assert((embed.split("const mapPaths=[")[1].split("];")[0].match(/'M/g)||[]).length>=18,"Map must have detailed coastlines");
assert(!embed.includes("Map coordinates are schematic"));
console.log("PASS Trophy Gallery artwork management, square contain display, XX International, detailed map, footers");
assert(!spot.includes("Current members · Published Green / MCA totals"));
assert(!spot.includes("Current members · Green + published MCA Results"));
assert(!spot.includes("Monthly/quarterly standings use the current member population"));
assert(!spot.includes("Green Daily · MCA Results · tournament archive"));
assert(!spot.includes("Finalized tournament archive · medals respect exclusions"));
assert(!spot.includes("esc(t.period||'')"));
assert(spot.includes("p2kw-big-position"));
assert(styles.includes("font-size:24px"));
assert(styles.includes("width:47px;height:47px"));
assert(spot.includes("activePlayerTab==='daily'&&config.showMedals!==false"));
assert(spot.includes("Longest arena participation streak"));
assert(spot.includes("Current arena participation streak"));
assert(spot.includes("Best arena finish"));
assert(spot.includes("include_position_comparison:1"));
assert(spot.includes("search_kind==='player'"));
assert(spot.includes("matched_medalists"));
assert(api.includes("matched_medalists"));
assert(api.includes("strcasecmp($username,$search)===0"));
assert(api.includes("strtoupper")||api.includes("stripos"));
assert(html.includes("p2k-embed-v2153.js?v=2"));
assert(html.includes("p2k-spotlight-v2159.js?v=2"));
console.log("PASS improved player/Live details, tournament medalist searches, fixed classification and cache versions");
