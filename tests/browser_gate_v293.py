#!/usr/bin/env python3
"""v2.9.4 browser gate for the native Insights · Opponents aggregate heatmap pair."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import json

ROOT=Path(__file__).resolve().parents[1]
CHROMIUM='/usr/bin/chromium'
ROWS=[
 {'match_id':1,'status':'finished','opponent_slug':'alpha','opponent_name':'Alpha Club','boards':8,'rated_boards':8,'rated_coverage_percent':100,'match_type':'friendly','chess_type':'classical','p2k_avg_rating':1500,'opponent_avg_rating':1480,'avg_rating_delta':20},
 {'match_id':2,'status':'ongoing','opponent_slug':'alpha','opponent_name':'Alpha Club','boards':20,'rated_boards':15,'rated_coverage_percent':75,'match_type':'league','chess_type':'classical','p2k_avg_rating':1580,'opponent_avg_rating':1620,'avg_rating_delta':-40},
 {'match_id':3,'status':'registration','opponent_slug':'beta','opponent_name':'Beta Knights','boards':50,'rated_boards':25,'rated_coverage_percent':50,'match_type':'friendly','chess_type':'chess960','p2k_avg_rating':1700,'opponent_avg_rating':1690,'avg_rating_delta':10},
 {'match_id':4,'status':'finished','opponent_slug':'gamma','opponent_name':'Gamma Chess','boards':100,'rated_boards':100,'rated_coverage_percent':100,'match_type':'league','chess_type':'classical','p2k_avg_rating':1850,'opponent_avg_rating':1780,'avg_rating_delta':70},
]
DATA={'rows':ROWS,'coverage':{'all_matches':7,'paired_rating_matches':4,'paired_match_percent':57.1}}

def main():
  with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
    page=browser.new_page(viewport={'width':1100,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    css=(ROOT/'assets/css/dashboard-v2.css').read_text(encoding='utf-8',errors='ignore')
    page.set_content(f'<html><head><style>{css}</style></head><body><section class="p2k-opponent-balance-host" id="opponentsBalanceAnalyzer"></section></body></html>')
    page.add_script_tag(path=str(ROOT/'assets/js/pages/opponent-balance-analyzer.js'))
    page.evaluate('(d)=>window.P2K_OPPONENT_BALANCE.render(document.getElementById("opponentsBalanceAnalyzer"),d)',DATA);page.wait_for_timeout(80)
    state=page.evaluate('''() => ({charts:document.querySelectorAll('#opponentsBalanceAnalyzer .ci-balance-chart svg').length,hosts:document.querySelectorAll('#ciBalanceSize,#ciBalanceStrength').length,title:document.querySelector('#opponentsBalanceAnalyzer h3')?.textContent||'',copy:document.querySelector('#opponentsBalanceAnalyzer p')?.textContent||'',matches:[...document.querySelectorAll('.ci-balance-kpis strong')][0]?.textContent||''})''')
    if errors or state['charts']!=2 or state['hosts']!=2 or state['title']!='Opponent Balance Analyzer · all matches' or state['matches']!='4' or '7 non-void matches' not in state['copy']:
      raise AssertionError(json.dumps({'errors':errors,'state':state},indent=2))
    page.select_option('#ciBalanceMatch','league');page.wait_for_timeout(50)
    filtered=page.evaluate("() => ({charts:document.querySelectorAll('.ci-balance-chart svg').length,matches:[...document.querySelectorAll('.ci-balance-kpis strong')][0]?.textContent||''})")
    if filtered['charts']!=2 or filtered['matches']!='2':raise AssertionError(json.dumps(filtered))
    browser.close();print(json.dumps({'state':state,'filtered':filtered,'pageErrors':0},indent=2))
if __name__=='__main__':main()
