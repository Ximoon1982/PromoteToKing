#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv)!=2:
    raise SystemExit("usage: consolidate-trophy-runtime.py <trophy-gallery-poc.js>")
p=Path(sys.argv[1]); s=p.read_text(encoding="utf-8")

positions=[x for x in (s.find("__P2K_TROPHY_R5FIX1"),s.find("__P2K_TROPHY_R5FIX2")) if x>=0]
if positions:
    pos=min(positions)
    start=max(s.rfind(";(() => {",0,pos),s.rfind("(() => {",0,pos))
    if start<0: raise SystemExit("Old repair marker found but repair block start was not found")
    s=s[:start].rstrip()+"\n"

old='const year=r=>String(r.award_date||"").slice(0,4)||"Undated";'
new='const LEGACY_YEARS={"owl-2024-swiss4all":"2024","owl-2024-grand-prix-candidates":"2024","owl-2024-vote-g1":"2024","owl-2024-classic-u1700":"2024","pcl-super-bingo-2025":"2025","tcmac-centurion-s4":"2025","club-wars-galactic-conflict":"2026"};const year=r=>String(r.award_date||"").slice(0,4)||LEGACY_YEARS[r?.id]||"Undated";'
if old in s: s=s.replace(old,new,1)
elif "const LEGACY_YEARS=" not in s: raise SystemExit("Could not locate r5 year helper")

a=s.find("function openModal(r){"); b=s.find("async function mountPublic(host){",a)
if a<0 or b<0: raise SystemExit("Could not locate r5 openModal")
modal_fn=r'''function openModal(r){if(!r)return;let modal=$("#p2kTrophyModal");if(!modal){modal=document.createElement("div");modal.id="p2kTrophyModal";modal.className="p2k-trophy-modal";modal.hidden=true;document.body.append(modal);modal.onclick=e=>{if(e.target===modal||e.target.closest("[data-close]"))modal.hidden=true}}const image=media(r,"modal"),links=[["Award page",r.award_page],["Competition page",r.competition_page],["Result table",r.result_table_url]].filter(([,u])=>safeUrl(u));modal.innerHTML=`<article role="dialog" aria-modal="true"><header class="p2k-trophy-modal-head"><div><small>${esc(r.league)} · ${esc(year(r))}</small><h2>${esc(r.title)}</h2></div><button type="button" data-close aria-label="Close">×</button></header><div class="p2k-trophy-modal-body"><div class="p2k-trophy-modal-art">${image?`<img src="${esc(image)}" alt="${esc(r.title)}"><button type="button" data-enlarge>Enlarge image</button>`:""}</div><div class="p2k-trophy-modal-details"><dl class="p2k-trophy-meta"><div><dt>League</dt><dd>${esc(r.league||"—")}</dd></div><div><dt>Competition</dt><dd>${esc(r.competition||"—")}</dd></div><div><dt>Award</dt><dd>${esc(r.award||"—")}</dd></div><div><dt>Award date</dt><dd>${esc(r.award_date||year(r)||"—")}</dd></div></dl><section class="p2k-trophy-detail-section"><h3>Description</h3><div class="p2k-markdown">${markdown(r.description_md)}</div></section>${(r.matches||[]).length?`<section class="p2k-trophy-detail-section"><h3>Matches involved</h3><ul>${r.matches.map(m=>`<li>${esc(m.name||`Match ${m.match_id}`)}</li>`).join("")}</ul></section>`:""}${links.length?`<section class="p2k-trophy-detail-section"><h3>References</h3><p class="p2k-links">${links.map(([label,url])=>`<a href="${esc(safeUrl(url))}" target="_blank" rel="noopener noreferrer">${label}</a>`).join("")}</p></section>`:""}</div></div></article>`;$("#p2kTrophyModal [data-enlarge]")?.addEventListener("click",()=>viewer(image,r.title));modal.hidden=false;}
'''
s=s[:a]+modal_fn+s[b:]

s=s.replace('<iframe class="p2k-engraver" title="Trophy engraving editor"', '<iframe class="p2k-engraver" data-engraver title="Trophy engraving editor"',1)
p.write_text(s,encoding="utf-8")
print("Consolidated Trophy runtime and restored rich modal")
