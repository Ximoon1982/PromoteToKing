#!/usr/bin/env python3
"""Real PHP/SQLite API and native Recruitment UI qualification with deterministic upstream data."""
import json,os,shutil,socket,subprocess,time,tempfile,urllib.request,urllib.error,hashlib
from pathlib import Path
from urllib.parse import urlencode
from playwright.sync_api import sync_playwright,expect
ROOT=Path(__file__).resolve().parents[1]
ENDPOINT='/server/team-points/public/recruitment-admin.php'
def check(condition,description):
    if not condition:raise AssertionError(description)
with tempfile.TemporaryDirectory() as tmp:
    root=Path(tmp);runtime=root/'runtime';legacy=runtime/'recruitment-admin';legacy.mkdir(parents=True)
    pool={'candidates':['old-one','old-two','old-three','old-four']}
    run={'id':'legacy','status':'running','createdAt':'2026-11-01T09:00:00+00:00','candidates':['old-one','old-two','old-three'],'criteria':{'minRating':1500},'results':[{'username':'old-one','selected':True,'checkedAt':'2026-11-02T00:00:00Z','data':{'daily_rating':1700},'reason':'All criteria passed.'},{'username':'old-two','selected':False,'checkedAt':'2026-11-02T00:00:00Z','data':{'error':'HTTP 500'},'reason':'Unavailable'}]}
    for name,data in [('pool',pool),('run',run)]: (legacy/(name+'.json')).write_text(json.dumps(data))
    original={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in legacy.glob('*.json')}
    files=['server/team-points/public/recruitment-admin.php','server/team-points/src/RecruitmentAnalysisStore.php','server/team-points/src/Http.php','server/team-points/src/ApiException.php','assets/js/pages/v2-11-0.js']
    for p in files:
        dest=root/p;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy(ROOT/p,dest)
    (root/'server/team-points/src/bootstrap.php').write_text(r'''<?php
namespace P2K\TeamPoints {
require_once __DIR__.'/ApiException.php';
require_once __DIR__.'/Http.php';
require_once __DIR__.'/RecruitmentAnalysisStore.php';
final class RuntimeTelemetry {public static function noteResponse(...$args):void{}}
final class Auth {public static function requireAdmin():void{
    if(($_SERVER['HTTP_X_FIXTURE_ADMIN']??'')!=='yes')throw new ApiException('Denied',403,'ADMIN_AUTH_FAILED');
    $_SESSION=['p2k_tp_admin_username'=>'fixture-admin'];
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_SERVER['HTTP_X_P2K_CSRF']??'')!=='fixture')throw new ApiException('CSRF denied',403,'CSRF_VALIDATION_FAILED');
}}
final class PreviewIsolation {
    public static function active():bool{return ($_SERVER['HTTP_X_FIXTURE_PREVIEW']??'')==='yes';}
    public static function username():string{return 'fixture-preview';}
}
final class PublicReadDatabase {
    public static function core():\PDO {
        $db=new \PDO('sqlite::memory:');$db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE p2k_g_players(username_key TEXT,current_member INTEGER,chess_player_id INTEGER);
          CREATE TABLE p2k_g_identity_map(username_key TEXT,canonical_username_key TEXT);
          CREATE TABLE p2k_g_player_aliases(username_key TEXT,chess_player_id INTEGER);
          INSERT INTO p2k_g_players VALUES('current-user',1,42),('former-user',0,43);
          INSERT INTO p2k_g_identity_map VALUES('current-alias','current-user');
          INSERT INTO p2k_g_player_aliases VALUES('former-alias',43);");
        return $db;
    }
}
}
namespace {
function p2k_tp_config():array{return ['storage'=>['runtime_dir'=>getenv('FIXTURE_RUNTIME').(\P2K\TeamPoints\PreviewIsolation::active()?'/preview':'')]];}
}
''')
    shell=r'''<!doctype html><html><head><meta charset="utf-8"><style>body{font:14px Arial;color:#eee;background:#222;padding:12px}button{padding:8px;cursor:pointer}button:disabled{opacity:.5}input,select,textarea{font:14px Arial}[hidden]{display:none!important}</style></head><body>
<div id="adminShellNativeDetailHost"></div><script>
window.P2K_TEAM_POINTS_CLIENT={endpointRequest:async(path,o)=>{
 const u=new URL(path,location.href);u.searchParams.set('action',o.action);
 const r=await fetch(u,{method:o.method,headers:{'Content-Type':'application/json','X-Fixture-Admin':'yes','X-P2K-CSRF':'fixture'},body:JSON.stringify(o.body)});
 const j=await r.json();if(!r.ok||!j.ok){const e=Error(j.error.message);e.code=j.error.code;throw e;}return j;
}};
window.P2K_API_CLIENT={diagnostics:()=>({oauthBearerMode:true}),json:async(u,o)=>{
 if(o.signal?.aborted)throw new DOMException('Aborted','AbortError');
 const name=u.split('/')[5];
 if(u.endsWith('/stats'))return {chess_daily:{last:{rating:1750,rd:50},record:{win:50,loss:10,draw:5,timeout_percent:1,time_per_move:100}}};
 if(u.endsWith('/games'))return {games:[{},{}]};
 if(name==='api-error'){const e=Error('Fixture temporary failure');e.status=500;throw e;}
 return {status:'basic',joined:Math.floor(Date.now()/1000)-86400*300,last_online:Math.floor(Date.now()/1000)};
},processPriority:async(values,fn,o)=>{
 for(const name of values){if(o.signal.aborted)break;try{await fn(name)}catch(e){if(!o.signal.aborted)throw e;break;}await new Promise(r=>setTimeout(r,90));}
}};
</script><script src="/assets/js/pages/v2-11-0.js"></script></body></html>'''
    (root/'ui-v2.html').write_text(shell)
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    origin='http://127.0.0.1:'+str(port)
    log=(root/'php.log').open('wb')
    php=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(root)],env={**os.environ,'FIXTURE_RUNTIME':str(runtime)},stdout=log,stderr=log)
    def call(action,body=None,params=None,expected=200,admin=True,preview=False,csrf=True,raw=False):
        url=origin+ENDPOINT+'?'+urlencode({'action':action,**(params or {})})
        headers={'X-Fixture-Admin':'yes' if admin else 'no','X-P2K-CSRF':'fixture' if csrf else '', 'X-Fixture-Preview':'yes' if preview else 'no','Content-Type':'application/json'}
        req=urllib.request.Request(url,data=None if body is None else json.dumps(body).encode(),headers=headers)
        try:r=urllib.request.urlopen(req,timeout=30)
        except urllib.error.HTTPError as e:r=e
        with r:status=r.status;data=r.read()
        check(status==expected,f'{action}: expected {expected}, got {status}: {data[:500]}')
        return data if raw else json.loads(data)
    try:
        for _ in range(100):
            try:call('state',admin=False,expected=403);break
            except OSError:time.sleep(.05)
        items=call('state')['analyses']['items'];check(len(items)==2,'migrate run and differing pool independently')
        imported=next(a for a in items if a['name']=='Imported recruitment analysis')
        check(imported['status']=='paused' and imported['summary']=={'total':3,'checked':2,'pending':1,'selected':1,'errors':1,'rejected':0},imported)
        check(len(call('state')['analyses']['items'])==2,'migration idempotent')
        check(original=={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in legacy.glob('*.json')},'legacy untouched')
        check(call('state',preview=True)['analyses']['total']==0,'preview isolated')
        call('create',{'name':'blocked','candidates':['a']},expected=403,preview=True)
        call('create',{'name':'blocked','candidates':['a']},expected=403,csrf=False)
        a=call('create',{'name':'Alpha campaign','criteria':{'minRating':1500},'candidates':'Alpha\nalpha\nhttps://www.chess.com/member/Beta\ncurrent-alias\napi-error'})['run']
        b=call('create',{'name':'Beta campaign','criteria':{'minRating':1900},'candidates':['Alpha','Gamma']})['run']
        check(a['id']!=b['id'] and a['summary']['total']==4 and b['summary']['total']==2,'independent normalized candidates')
        a=call('update-draft',{'id':a['id'],'name':'Alpha campaign','criteria':{'minRating':1600},'candidates':a['candidates']})['run']
        check(a['criteria']['minRating']==1600,'draft criteria edited')
        lease=call('resume',{'id':a['id']});token=lease['lease'];aid=a['id'];bid=b['id']
        check(len(lease['pending'])==4,'initial pending')
        call('resume',{'id':bid},expected=409);call('resume',{'id':aid},expected=409)
        call('delete',{'id':aid,'confirm':True},expected=409)
        call('update-draft',{'id':aid,'name':'spoof','candidates':['x']},expected=409)
        good={'daily_rating':1750,'timeout_percent':1,'daily_rd':50,'current_daily_games':2,'completed_daily_games':65,'last_online_days':1}
        def result(name,data):return {'username':name,'data':data}
        def checkpoint(rows,token=token,aid=aid,expected=200):
            return call('checkpoint',{'id':aid,'lease':token,'results':rows},expected=expected)
        checkpoint([result('Alpha',good)],token='wrong',expected=409)
        checkpoint([result('Alpha',good)],aid=bid,expected=409)
        r=checkpoint([result('Alpha',good),result('current-alias',good),result('api-error',{'error':'temporary HTTP 500'}),result('intruder',good)])['run']
        check(r['summary']=={'total':4,'checked':3,'pending':1,'selected':1,'errors':1,'rejected':1},r)
        check(checkpoint([result('Alpha',{'error':'do not overwrite'})])['run']['summary']==r['summary'],'duplicate checkpoint does not overwrite')
        check(call('detail',params={'id':bid})['run']['summary']['checked']==0,'other analysis remains empty')
        call('pause',{'id':aid,'lease':token})
        resumed=call('resume',{'id':aid});check(resumed['pending']==['Beta'],'resume only pending')
        checkpoint([result('Beta',good)],expected=409)
        r=checkpoint([result('Beta',good)],token=resumed['lease'])['run'];check(r['status']=='completed' and r['summary']['selected']==2,r)
        r=call('retry-errors',{'id':aid})['run'];check(r['summary']['pending']==1 and r['summary']['errors']==0,'retry errors separately')
        resumed=call('resume',{'id':aid});check(resumed['pending']==['api-error'],'only failed row retried')
        checkpoint([result('api-error',good)],token=resumed['lease'])
        page=call('results',params={'id':aid,'filter':'selected','size':1,'page':2})['results'];check(page['total']==3 and page['page']==2 and len(page['items'])==1,'pagination')
        page=call('results',params={'id':aid,'filter':'rejected','search':'current'})['results'];check(page['total']==1 and page['items'][0]['data']['p2k_state']=='current','canonical membership server revalidation')
        csv=call('csv',params={'id':aid,'scope':'all'},raw=True).decode();check('api-error' in csv and 'current-alias' in csv and 'Gamma' not in csv,'analysis-specific CSV')
        copy=call('duplicate',{'id':aid,'name':'Alpha copy'})['run'];check(copy['summary']['checked']==0 and copy['summary']['total']==4 and copy['criteria']==a['criteria'],'duplicate candidates and criteria only')
        call('delete',{'id':copy['id'],'confirm':False},expected=400)
        call('delete',{'id':copy['id'],'confirm':True})
        call('detail',params={'id':copy['id']},expected=404)
        check(call('detail',params={'id':aid})['run']['summary']['selected']==3,'delete independent')
        # Expiry recovery: simulate elapsed lease without sleeping 180 seconds.
        c=call('resume',{'id':bid});import sqlite3
        with sqlite3.connect(legacy/'analyses.sqlite3') as db:db.execute('UPDATE analyses SET lease_until=0 WHERE id=?',(bid,))
        check(call('detail',params={'id':bid})['run']['status']=='paused','expired lease presented as paused')
        new=call('resume',{'id':bid});check(new['lease']!=c['lease'],'new ownership')
        call('heartbeat',{'id':bid,'lease':c['lease']},expected=409);call('pause',{'id':bid,'lease':new['lease']})
        print('PASS real endpoint: migration, immutable analyses, lease conflict and expiry, stale checkpoint rejection, membership, retries, pagination, exports, duplication, deletion, authorization and preview')
        with sync_playwright() as p:
            exe=shutil.which('google-chrome') or shutil.which('chromium') or shutil.which('chromium-browser')
            browser=p.chromium.launch(executable_path=exe,headless=True,args=['--no-sandbox'])
            page=browser.new_page(viewport={'width':1280,'height':900},extra_http_headers={'X-Fixture-Admin':'yes','X-P2K-CSRF':'fixture'})
            errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            page.goto(origin+'/ui-v2.html?view=admin&adminCategory=members&adminDetail=recruitment')
            expect(page.locator('#recruitmentAnalysisRows')).to_contain_text('Alpha campaign')
            page.locator('#recruitmentNew').click()
            expect(page.locator('#recruitmentCandidates')).to_have_value('')
            page.locator('#recruitmentName').fill('UI campaign')
            page.locator('#recruitmentCandidates').fill('ui-first\nui-second\napi-error')
            page.locator('#recruitmentSaveDraft').click()
            expect(page.locator('#recruitmentStatus')).to_contain_text('Draft saved')
            page.locator('#recruitmentStart').click()
            expect(page.locator('#recruitmentStatus')).to_contain_text('Completed',timeout=20000)
            expect(page.locator('#recruitmentMetricChecked')).to_have_text('3')
            expect(page.locator('#recruitmentMetricSelected')).to_have_text('2')
            expect(page.locator('#recruitmentMetricErrors')).to_have_text('1')
            page.locator('#recruitmentResultFilter').select_option('error')
            expect(page.locator('#recruitmentRows')).to_contain_text('api-error')
            expect(page.locator('#recruitmentRows')).not_to_contain_text('ui-first')
            page.locator('#recruitmentRetry').click()
            expect(page.locator('#recruitmentStatus')).to_contain_text('API errors returned to pending')
            expect(page.locator('#recruitmentMetricChecked')).to_have_text('2')
            page.locator('#recruitmentClose').click()
            page.locator('#recruitmentNew').click()
            page.locator('#recruitmentName').fill('Pause campaign')
            page.locator('#recruitmentCandidates').fill('\n'.join('pause-'+str(i) for i in range(40)))
            page.locator('#recruitmentStart').click()
            expect(page.locator('#recruitmentStatus')).to_contain_text('Scanning')
            time.sleep(.6)
            page.locator('#recruitmentPause').click()
            expect(page.locator('#recruitmentStatus')).to_contain_text('Paused',timeout=20000)
            count=int(page.locator('#recruitmentMetricChecked').inner_text())
            check(0<count<40,'pause flushed partial batch: '+str(count))
            page.locator('#recruitmentStart').click()
            expect(page.locator('#recruitmentStatus')).to_contain_text('Completed',timeout=20000)
            expect(page.locator('#recruitmentMetricChecked')).to_have_text('40')
            check(not errors,errors)
            browser.close()
        print('PASS actual native UI: empty new campaign, draft, start, error filter/retry, pause flush, resume, completed scan')
    finally:
        php.terminate();php.wait(timeout=5);log.close()
        if os.environ.get('RECRUITMENT_TEST_LOG'):print((root/'php.log').read_text()[-10000:])
