#!/usr/bin/env python3
"""v2.9.2 browser gate for aggregate Opponent Balance Analyzer."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import json

ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = '/usr/bin/chromium'
ROWS = [
    {'match_id':1,'opponent_slug':'alpha','opponent_name':'Alpha Club','boards':8,'rated_boards':8,'rated_coverage_percent':100,'match_type':'friendly','chess_type':'classical','p2k_avg_rating':1500,'opponent_avg_rating':1480,'rating_delta':20},
    {'match_id':2,'opponent_slug':'alpha','opponent_name':'Alpha Club','boards':20,'rated_boards':15,'rated_coverage_percent':75,'match_type':'league','chess_type':'classical','p2k_avg_rating':1580,'opponent_avg_rating':1620,'rating_delta':-40},
    {'match_id':3,'opponent_slug':'beta','opponent_name':'Beta Knights','boards':50,'rated_boards':25,'rated_coverage_percent':50,'match_type':'friendly','chess_type':'chess960','p2k_avg_rating':1700,'opponent_avg_rating':1690,'rating_delta':10},
    {'match_id':4,'opponent_slug':'gamma','opponent_name':'Gamma Chess','boards':100,'rated_boards':100,'rated_coverage_percent':100,'match_type':'league','chess_type':'classical','p2k_avg_rating':1850,'opponent_avg_rating':1780,'rating_delta':70},
]
DATA={'rows':ROWS,'coverage':{'finished_matches':10,'paired_rating_matches':4,'paired_match_percent':40}}

def main():
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True, executable_path=CHROMIUM, args=['--no-sandbox','--disable-dev-shm-usage'])
        page=browser.new_page(viewport={'width':1100,'height':900})
        errors=[]; page.on('pageerror', lambda e: errors.append(str(e)))
        css=(ROOT/'assets/css/club-intelligence.css').read_text(encoding='utf-8',errors='ignore')
        page.set_content(f'<html><head><style>{css}</style></head><body><main id="root"></main></body></html>')
        page.add_script_tag(path=str(ROOT/'assets/js/pages/opponent-balance-analyzer.js'))
        page.evaluate('(d)=>window.P2K_OPPONENT_BALANCE.render(document.getElementById("root"),d)', DATA)
        page.wait_for_timeout(100)
        initial=page.evaluate('''() => ({
          title:document.querySelector('#root h3')?.textContent||'',
          charts:document.querySelectorAll('.ci-balance-chart svg').length,
          chartHosts:document.querySelectorAll('#ciBalanceSize,#ciBalanceStrength').length,
          matches:[...document.querySelectorAll('.ci-balance-kpis strong')][0]?.textContent||'',
          opponents:[...document.querySelectorAll('.ci-balance-opponent-name')].map(x=>x.textContent.trim()),
          top:document.getElementById('ciBalanceTop')?.value,
          coverage:document.getElementById('ciBalanceCoverage')?.value,
          color:document.getElementById('ciBalanceColor')?.value,
          cellLabels:document.querySelectorAll('.ci-balance-cell-label').length
        })''')
        if errors or initial['title']!='Opponent Balance Analyzer · all matches' or initial['charts']!=2 or initial['chartHosts']!=2 or initial['matches']!='4' or initial['top']!='all' or initial['coverage']!='0' or initial['color']!='log' or len(initial['opponents'])!=3:
            raise AssertionError(json.dumps({'errors':errors,'initial':initial},indent=2))
        # Shared filters rerender the same two aggregate charts, rather than creating per-opponent charts.
        page.select_option('#ciBalanceMatch','league'); page.wait_for_timeout(80)
        filtered=page.evaluate('''() => ({
          charts:document.querySelectorAll('.ci-balance-chart svg').length,
          hosts:document.querySelectorAll('#ciBalanceSize,#ciBalanceStrength').length,
          matches:[...document.querySelectorAll('.ci-balance-kpis strong')][0]?.textContent||'',
          opponents:[...document.querySelectorAll('.ci-balance-opponent-name')].map(x=>x.textContent.trim())
        })''')
        if errors or filtered['charts']!=2 or filtered['hosts']!=2 or filtered['matches']!='2' or len(filtered['opponents'])!=2:
            raise AssertionError(json.dumps({'errors':errors,'filtered':filtered},indent=2))
        # Coverage is shared too: league + 100% leaves only Gamma.
        page.select_option('#ciBalanceCoverage','100'); page.wait_for_timeout(80)
        covered=page.evaluate('''() => ({matches:[...document.querySelectorAll('.ci-balance-kpis strong')][0]?.textContent||'', opponents:[...document.querySelectorAll('.ci-balance-opponent-name')].map(x=>x.textContent.trim()), charts:document.querySelectorAll('.ci-balance-chart svg').length})''')
        if errors or covered['matches']!='1' or covered['charts']!=2 or len(covered['opponents'])!=1 or 'Gamma Chess' not in covered['opponents'][0]:
            raise AssertionError(json.dumps({'errors':errors,'covered':covered},indent=2))
        browser.close()
        print(json.dumps({'initial':initial,'filtered':filtered,'covered':covered,'pageErrors':0},indent=2))

if __name__=='__main__': main()
