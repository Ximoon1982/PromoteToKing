#!/usr/bin/env python3
"""v2.9.22 browser gate: Fresh Points Reconstruction Task Control card and metrics."""
from pathlib import Path
import json, re
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
CHROMIUM = '/usr/bin/chromium'
SNAPSHOT = {
  'run': {'run_id':'recon-browser-gate','include_club':True,'include_player':True,'status':'ready','phase':'review','phase_label':'Ready for review','overall_progress':100.0,'club_progress':100.0,'player_progress':100.0,'opening_roster_count':4475,'closing_roster_count':4477,'created_at':'2026-08-14 06:00:00','updated_at':'2026-08-14 06:20:00','club_applied_at':None,'player_applied_at':None,'applied_at':None,'last_error':None},
  'metrics': {
    'matches': {'total':15432,'pending':0,'resolved':15428,'unresolved':2,'failed':2,'finished':14987,'excluded_zero_zero':17,'max_match_id':999999},
    'members': {'total':4477,'pending':0,'matches_done':0,'archive_fallback':43,'boards_done':0,'complete':4473,'unresolved':2,'failed':2},
    'boards': {'total':106231,'discovered':0,'pending':0,'resolved':106225,'unresolved':4,'failed':2},
    'games': {'total':201725,'points':119876.5},
    'client': {'persistence': {'queuedRows':321,'rowsPersisted':45000,'batchesSent':113,'failedBatches':0,'active':True,'highWaterRows':1600}, 'phase_rows': {
      'club-opening': {'label':'Opening match discovery','state':'complete','found':15432,'pending':0,'processed':15432,'failed':0,'progress':100},
      'club-details': {'label':'Match-detail reconstruction','state':'complete','found':15432,'pending':0,'processed':15432,'failed':2,'progress':100},
      'player-roster': {'label':'Opening roster','state':'complete','found':4475,'pending':0,'processed':4475,'failed':0,'progress':100},
      'player-boards': {'label':'Board/result resolution','state':'complete','found':106231,'pending':0,'processed':106231,'failed':2,'progress':100},
    }, 'network': {'scheduled':32100,'ok':31980,'failed':120,'retries':77,'lastUrl':'https://api.chess.com/pub/match/999999','oauth':True,'transportMode':'oauth-bearer-gateway','safeRate':24.8,'launchCps':24.3,'completionCps':23.9,'gatewayTarget':5,'gatewayQueued':31,'gatewayActivePosts':2}},
  },
  'review': {
    'integrity_ok': True, 'unresolved': 0,
    'club': {'current_score':119001.5,'reconstructed_score':120114.0,'delta':1112.5,'finished_matches':14987,'changed_matches':341,'excluded_zero_zero':17,'queue_supersedable':38618},
    'player': {'current_points':118645.0,'reconstructed_points':119876.5,'delta':1231.5,'members':4477,'boards':106231,'games':201725,'changed_members':608,'queue_supersedable':8554},
  }
}

def main():
    raw=(ROOT/'TaskControl.html').read_text(encoding='utf-8',errors='ignore')
    body=re.search(r'<body[^>]*>(.*)</body>',raw,re.I|re.S).group(1)
    body=re.sub(r'<script\b[^>]*>.*?</script>','',body,flags=re.I|re.S)
    css=(ROOT/'assets/css/task-control.css').read_text(encoding='utf-8',errors='ignore')
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
        page=browser.new_page(viewport={'width':1280,'height':1000})
        errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content(f'<!doctype html><html><head><style>{css}</style></head><body>{body}</body></html>')
        page.evaluate("""snapshot=>{
          window.P2K_ADMIN_ACCESS_READY=Promise.resolve(true); window.P2K_ADMIN_USERNAME='ximoon';
          window.P2K_AUTH={getSession:()=>({username:'ximoon',isAdmin:true})};
          window.P2K_CLIENT_CONTINUOUS_REFRESH={status:()=>({mode:'disabled',api:{label:'serial'}})};
          const status={snapshot,running:false,network:snapshot.metrics.client.network,phaseRows:snapshot.metrics.client.phase_rows,logs:[{at:Date.now(),level:'success',message:'Gate reconstruction ready.'}]};
          status.persistence=snapshot.metrics.client.persistence; window.P2K_FRESH_POINTS_RECONSTRUCTION={status:()=>status,sync:async()=>snapshot,start:async()=>status,pause:async()=>status,resume:async()=>status,cancel:async()=>status,apply:async()=>({queue_superseded:{club:1,player:2}})};
          window.P2K_TEAM_POINTS_CLIENT={
            connect:async()=>({ok:true,username:'ximoon',csrf:'gate',schema_version:15,analytics_schema_version:7}),
            endpointRequest:async(base,{action='' }={})=>{
              if(action==='status'||action==='reconstruction-status')return {ok:true,tasks:[],gateway:{health_status:'healthy',health_message:'gate',cache_entries:100,fresh_cache_entries:90,false_404_protection:true},reconstruction:snapshot};
              if(action==='logs')return {ok:true,logs:[],has_more:false,next_before_id:0};
              return {ok:true,message:'gate',reconstruction:snapshot};
            }
          };
        }""",SNAPSHOT)
        page.add_script_tag(path=str(ROOT/'assets/js/pages/task-control.js'))
        page.wait_for_selector('[data-task-card="fresh-points-reconstruction"]',timeout=10000)
        page.wait_for_timeout(100)
        page.wait_for_timeout(60)
        result=page.evaluate("""()=>({
          title:document.querySelector('[data-task-card="fresh-points-reconstruction"] h3')?.textContent?.trim()||'',
          clubChoice:!!document.getElementById('reconstructionClubChoice'), playerChoice:!!document.getElementById('reconstructionPlayerChoice'),
          start:!!document.querySelector('[data-reconstruction-command="start"]'), pause:!!document.querySelector('[data-reconstruction-command="pause"]'), resume:!!document.querySelector('[data-reconstruction-command="resume"]'),
          clubProgress:document.getElementById('reconstructionClubProgress')?.textContent||'', playerProgress:document.getElementById('reconstructionPlayerProgress')?.textContent||'',
          clubMetrics:document.getElementById('reconstructionClubMetrics')?.innerText||'', playerMetrics:document.getElementById('reconstructionPlayerMetrics')?.innerText||'',
          phases:[...document.querySelectorAll('#reconstructionPhaseRows tr')].map(x=>x.innerText), reviewHidden:document.getElementById('reconstructionReview')?.hidden,
          review:document.getElementById('reconstructionReviewGrid')?.innerText||'', integrity:document.getElementById('reconstructionIntegrityBadge')?.textContent||'',
          applyClubDisabled:document.getElementById('reconstructionApplyClub')?.disabled, applyPlayerDisabled:document.getElementById('reconstructionApplyPlayer')?.disabled, applyBothDisabled:document.getElementById('reconstructionApplyBoth')?.disabled,
          detailHidden:document.getElementById('reconstructionDetail')?.hidden, detailMetrics:document.getElementById('taskDetailMetrics')?.innerText||'',
        })""")
        assert result['title']=='Fresh Points Reconstruction',result
        assert result['clubChoice'] and result['playerChoice'] and result['start'] and result['pause'] and result['resume'],result
        assert result['clubProgress']=='100.0%' and result['playerProgress']=='100.0%',result
        assert '15,432' in result['clubMetrics'] and '14,987' in result['clubMetrics'],result
        compact=result['playerMetrics'].replace(',','').replace(' ',''); assert '4477' in compact and '106231' in compact and '201725' in compact,result
        assert len(result['phases'])>=4 and any('Board/result resolution' in x for x in result['phases']),result
        assert result['reviewHidden'] is False and result['detailHidden'] is False,result
        assert '120,114' in result['review'] and '119876.5' in result['review'].replace(',',''),result
        assert result['integrity']=='Integrity clean',result
        assert 'Bearer parallel' in result['detailMetrics'] and '24.3/s' in result['detailMetrics'] and '321' in result['detailMetrics'] and '45,000' in result['detailMetrics'],result
        assert result['applyClubDisabled'] is False and result['applyPlayerDisabled'] is False and result['applyBothDisabled'] is False,result
        assert not errors,errors
        print(json.dumps({'task':result,'pageErrors':0},indent=2)); browser.close()

if __name__=='__main__': main()
