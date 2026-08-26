#!/usr/bin/env python3
"""v2.9.4 browser gate for Hall of Fame unified player-search width/containment."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import json

ROOT=Path(__file__).resolve().parents[1]
CHROMIUM='/usr/bin/chromium'
CSS_FILES=['assets/css/site.css','assets/css/dashboard-v2.css','assets/css/data-table.css','assets/css/responsive-unification.css']

def fixture_html():
    css='\n'.join((ROOT/p).read_text(encoding='utf-8',errors='ignore') for p in CSS_FILES)
    cards=''.join(
        f'<div class="p2k-hall-result"><span>Card {i}</span>'
        f'<strong>VeryLongUnbrokenContentThatMustNeverSpillOutsideTheCard{i}</strong>'
        '<small>Detailed content wraps and remains contained.</small>'
        '<div class="p2k-hall-result-actions"><button class="dashboard-button">Open this destination</button></div></div>'
        for i in range(1,5)
    )
    return f'''<!doctype html><html><head><style>{css}</style></head><body>
    <main class="site-main" style="max-width:1080px;margin:auto">
      <section class="dashboard-panel dashboard-hall-page" id="hallOfFamePage">
        <form class="dashboard-hall-search dashboard-hall-unified-search">
          <label><span>Find a player across the Hall of Fame</span></label>
          <div><input value="VeryLongPlayerNameForContainmentTest"><button class="dashboard-button dashboard-button-primary">Search</button></div>
        </form>
        <div class="p2k-hall-unified-results" id="hallUnifiedResults">
          <article class="p2k-hall-result p2k-hall-result-unified">
            <div class="p2k-hall-unified-head"><div><span>Player</span><strong>VeryLongPlayerNameForContainmentTest</strong><small>Daily ranks, Live ranks, tournament record and achievements</small></div></div>
            <div class="p2k-hall-unified-grid">{cards}</div>
          </article>
        </div>
      </section>
    </main></body></html>'''

def state(page):
    return page.evaluate('''() => {
      const hall=document.querySelector('#hallOfFamePage'), host=document.querySelector('#hallUnifiedResults'), article=host.firstElementChild;
      const grid=document.querySelector('.p2k-hall-unified-grid'), cards=[...grid.children], r=e=>e.getBoundingClientRect();
      return {vw:innerWidth,pageWidth:r(hall).width,hostWidth:r(host).width,articleWidth:r(article).width,
        columns:getComputedStyle(grid).gridTemplateColumns,cardTops:cards.map(c=>Math.round(r(c).top)),
        documentOverflow:document.documentElement.scrollWidth>document.documentElement.clientWidth,
        hostOverflow:r(host).right>r(hall).right+.5};
    }''')

def main():
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
        page=browser.new_page(viewport={'width':1280,'height':900})
        page.set_content(fixture_html())
        results=[]
        for width in (1280,960,760,600):
            page.set_viewport_size({'width':width,'height':900}); page.wait_for_timeout(20)
            s=state(page); results.append(s)
            assert abs(s['hostWidth']-s['pageWidth'])<1, s
            assert abs(s['articleWidth']-s['pageWidth'])<1, s
            assert not s['documentOverflow'] and not s['hostOverflow'], s
            if width>=900: assert len(set(s['cardTops']))==1, s
            elif width>620: assert len(set(s['cardTops']))==2, s
            else: assert len(set(s['cardTops']))==4, s
        browser.close()
        print(json.dumps(results,indent=2))

if __name__=='__main__': main()
