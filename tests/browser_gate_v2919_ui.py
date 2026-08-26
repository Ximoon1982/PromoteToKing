#!/usr/bin/env python3
"""v2.9.19 browser acceptance: mobile framed art, admin alignment, history suppression and achievement UX."""
from pathlib import Path
import json,re
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
CHROMIUM='/usr/bin/chromium'

def inline_scripts(name):
    html=(ROOT/name).read_text(encoding='utf-8',errors='ignore')
    return [m.group('body') for m in re.finditer(r'<script(?P<attrs>[^>]*)>(?P<body>.*?)</script>',html,re.I|re.S) if 'src=' not in m.group('attrs').lower() and m.group('body').strip()]

def html_without_scripts(name):
    html=(ROOT/name).read_text(encoding='utf-8',errors='ignore')
    return re.sub(r'<script\b[^>]*>.*?</script>','',html,flags=re.I|re.S)

def main():
    css=(ROOT/'assets/css/dashboard-v2.css').read_text(encoding='utf-8',errors='ignore')
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,executable_path=CHROMIUM,args=['--no-sandbox','--disable-dev-shm-usage'])
        # Mobile framed-art containment + live-only history suppression.
        page=browser.new_page(viewport={'width':390,'height':844},has_touch=True,is_mobile=True)
        errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content(f'''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>{css}</style></head><body>
          <article class="p2k-live-rank-card" id="rook"><img id="rookImg" src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='640' height='640'><rect width='640' height='640' fill='gold'/></svg>"><div><strong>Live Rook</strong><span>2,500 points</span></div></article>
          <div id="history" class="p2k-match-history" data-p2k-match-history="123" hidden></div>
        </body></html>''')
        page.add_script_tag(content='''window.P2K_SITE_CONFIG={serverStorage:{matchHistoryEndpoint:'api/match-history/'}};
window.P2K_MATCH_HISTORY_SUMMARIZER=(m)=>({p2kWinProbability:.55,p2kName:'Promote to King',opponentName:'Opponent',p2kCount:1,opponentCount:1,minPlayers:1});
window.fetch=async()=>new Response(JSON.stringify({snapshots:[]}),{status:200,headers:{'Content-Type':'application/json'}});
window.P2K_API_CLIENT={json:async()=>({teams:[{name:'Promote to King',players:[{username:'a',rating:1200}]},{name:'Opponent',players:[{username:'b',rating:1200}]}]})};''')
        page.add_script_tag(path=str(ROOT/'assets/js/shared/match-history-ui.js'))
        page.evaluate("()=>window.P2K_MATCH_HISTORY_UI.hydrate(document)")
        page.wait_for_timeout(120)
        mobile=page.evaluate('''() => {const img=document.getElementById('rookImg'), card=document.getElementById('rook'), ir=img.getBoundingClientRect(),cr=card.getBoundingClientRect(),cs=getComputedStyle(img),h=document.getElementById('history');return{img:{left:ir.left,right:ir.right,top:ir.top,bottom:ir.bottom,width:ir.width},card:{left:cr.left,right:cr.right,top:cr.top,bottom:cr.bottom},objectFit:cs.objectFit,paddingLeft:parseFloat(cs.paddingLeft),historyHidden:h.hidden,historyLoaded:h.dataset.p2kHistoryLoaded||'',historyChildren:h.childElementCount};}''')
        assert mobile['objectFit']=='contain',mobile
        assert mobile['paddingLeft']>=5,mobile
        assert 70<=mobile['img']['width']<=74,mobile
        assert mobile['img']['left']>=mobile['card']['left']-1 and mobile['img']['right']<=mobile['card']['right']+1,mobile
        assert mobile['historyHidden'] and not mobile['historyLoaded'] and mobile['historyChildren']==0,mobile
        assert not errors,errors

        # Desktop priority/system-health row alignment.
        desk=browser.new_page(viewport={'width':1200,'height':800}); de=[];desk.on('pageerror',lambda e:de.append(str(e)))
        desk.set_content(f'''<!doctype html><html><head><style>{css}</style></head><body><div class="dashboard-admin-priority-columns">
          <section><div class="dashboard-admin-priority-queue"><div class="dashboard-admin-priority-item"><i class="dashboard-admin-priority-dot"></i><div><strong>A</strong><small>Long action explanation</small></div><button class="dashboard-admin-priority-action">Open</button></div><div class="dashboard-admin-priority-item"><i class="dashboard-admin-priority-dot"></i><div><strong>B</strong><small>Second</small></div><button class="dashboard-admin-priority-action">Open</button></div></div></section>
          <section><div class="dashboard-admin-priority-health"><div class="dashboard-admin-priority-health-row"><div><strong>Health A</strong><small>Status</small></div><b>OK</b></div><div class="dashboard-admin-priority-health-row"><div><strong>Health B</strong><small>Status</small></div><b>OK</b></div></div></section>
        </div></body></html>''')
        align=desk.evaluate('''() => {const a=[...document.querySelectorAll('.dashboard-admin-priority-item')].map(x=>x.getBoundingClientRect()),h=[...document.querySelectorAll('.dashboard-admin-priority-health-row')].map(x=>x.getBoundingClientRect());return{a:a.map(r=>({top:r.top,height:r.height})),h:h.map(r=>({top:r.top,height:r.height}))};}''')
        assert len(align['a'])==2 and len(align['h'])==2,align
        for i in range(2):
            assert abs(align['a'][i]['top']-align['h'][i]['top'])<=1,align
            assert abs(align['a'][i]['height']-72)<=1 and abs(align['h'][i]['height']-72)<=1,align
        assert not de,de

        # Real standalone Achievements runtime with mocked data.
        hall=browser.new_page(viewport={'width':1100,'height':820}); he=[];hall.on('pageerror',lambda e:he.append(str(e)))
        hall.set_content(html_without_scripts('TournamentAchievementBadgesDemo.html'),wait_until='domcontentloaded')
        hall.add_script_tag(content=r'''const NativeURL=window.URL;function SafeURL(input,base){const b=(!base||String(base)==='about:blank')?'https://p2k.test/TournamentAchievementBadgesDemo.html':base;return new NativeURL(input,b)}SafeURL.prototype=NativeURL.prototype;window.URL=SafeURL;
window.ResizeObserver=class{observe(){}};window.P2K_ACSR={register(){}};window.P2K_API_CLIENT={json:async()=>({})};
const CAT=[
 {key:'alpha',label:'Alpha',description:'A',criteria:'Do A',earned_current_member_count:10,family:'General',group:'Basics',icon:'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"/>'},
 {key:'beta',label:'Beta',description:'B',criteria:'Do B',earned_current_member_count:50,family:'General',group:'Basics',icon:'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"/>'},
 {key:'gamma',label:'Gamma',description:'C',criteria:'Do C',earned_current_member_count:25,family:'General',group:'Basics',icon:'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"/>'}
];
window.fetch=async(url)=>{const u=String(url);let p={ok:true};
 if(u.includes('achievement-players.php'))p={ok:true,rows:[{username:'Ximoon',points:321,achievement_count:1,daily_rank:{key:'rook',name:'Rook'},live_points:2500}],pagination:{total_pages:1}};
 else if(u.includes('achievements.php'))p={ok:true,catalog:CAT};
 else if(u.includes('player-profile.php'))p={ok:true,player:{username:'Ximoon',points:321,games:20,rank:{name:'Rook'},live:{rank_name:'Live Rook',points:2500},achievements:[{key:'alpha',label:'Alpha',earned:true}]}};
 else if(u.includes('member-intelligence.php'))p={ok:true,player:{member:{achievement_progress:[{key:'beta',progress_metric:'Wins',current:2,target:5,progress_percent:40}]}}};
 else if(u.includes('browse.php?view=player'))p={ok:true,player:{tournaments:[]}};
 else if(u.includes('browse.php?view=medals'))p={ok:true,medals:{}};
 else if(u.includes('player-cards.php'))p={ok:true,profiles:{}};
 else if(u.includes('medalist_names'))p={ok:true,usernames:[]};
 return new Response(JSON.stringify(p),{status:200,headers:{'Content-Type':'application/json'}})};''')
        scripts=inline_scripts('TournamentAchievementBadgesDemo.html'); assert scripts
        hall.add_script_tag(content=scripts[-1]);hall.wait_for_timeout(180)
        hall.locator('#popular').click();hall.wait_for_timeout(100)
        popular=hall.locator('#profile').inner_text()
        assert popular.find('Beta')<popular.find('Gamma')<popular.find('Alpha'),popular
        pl=popular.lower();assert '50 current players' in pl and '25 current players' in pl and '10 current players' in pl,popular
        hall.locator('#close').click();hall.wait_for_timeout(20)
        hall.locator('[data-open-catalog="Ximoon"]').click();hall.wait_for_timeout(140)
        hall.locator('[data-achievement-key="beta"]').evaluate('e=>e.click()');hall.wait_for_timeout(40)
        detail=hall.locator('#profile').inner_text()
        assert 'Not earned yet' in detail and 'Wins · 2 / 5' in detail and 'Previous' in detail and 'Next' in detail,detail
        title_before=hall.locator('#modalTitle').inner_text();assert title_before=='Beta',title_before
        hall.keyboard.press('ArrowRight');hall.wait_for_timeout(30);title_after=hall.locator('#modalTitle').inner_text();assert title_after=='Gamma',(title_before,title_after)
        hall.keyboard.press('ArrowLeft');hall.wait_for_timeout(30);assert hall.locator('#modalTitle').inner_text()=='Beta'
        assert not he,he
        browser.close()
        print(json.dumps({'mobileLiveRook':mobile,'adminAlignment':align,'achievementPopularity':True,'achievementProgressAndNavigation':True,'pageErrors':0},indent=2))
if __name__=='__main__': main()
