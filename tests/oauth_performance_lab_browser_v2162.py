#!/usr/bin/env python3
"""Browser test with mocked authorization/gateway; never calls Chess.com."""
from pathlib import Path
from http.server import ThreadingHTTPServer,SimpleHTTPRequestHandler
from threading import Thread
from functools import partial
from playwright.sync_api import sync_playwright,expect
import shutil,json
ROOT=Path(__file__).resolve().parents[1]
server=ThreadingHTTPServer(('127.0.0.1',0),partial(SimpleHTTPRequestHandler,directory=str(ROOT)))
Thread(target=server.serve_forever,daemon=True).start()
origin='http://127.0.0.1:'+str(server.server_port)
try:
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
        for mode in ('denied','preview','valid'):
            page=browser.new_page(accept_downloads=True);errors=[];batches=[]
            page.on('pageerror',lambda e:errors.append(str(e)))
            def route(r):
                u=r.request.url
                if not u.startswith(origin):return r.abort()
                if 'detailed-match-analysis.php' in u:
                    return r.fulfill(status=403 if mode=='denied' else 200,headers={'X-P2K-Candidate-Preview':'fixture'} if mode=='preview' else {},json={'allowed':mode!='denied','username':'admin'})
                if 'oauth.php?action=session' in u:return r.fulfill(json={'authenticated':True,'real_oauth':True,'oauth_verified':True,'csrf':'fixture-secret','profile':{'username':'admin'},'transport':{'batch_available':True,'http2_capable':True,'max_concurrency':256}})
                if 'oauth.php?action=batch' in u:
                    b=r.request.post_data_json;batches.append(b)
                    assert r.request.headers['x-p2k-oauth-csrf']=='fixture-secret'
                    assert b['rate_cps']==120 and b['traffic_class']=='foreground' and len(b['requests'])<=32
                    if len(batches)>1:return # Keep later fixture POSTs in flight until manual cancellation.
                    return r.fulfill(json={'ok':True,'mode':'oauth-bearer','controller':{'rate_target_cps':40,'safe_rate_cps':39,'unsafe_rate_cps':45},'median_latency_ms':100,'p95_latency_ms':150,'peak_in_flight':4,'results':[{'id':x['id'],'status':200,'elapsed_ms':100,'json':{'status':'basic'}} for x in b['requests']]})
                return r.continue_()
            page.route('**/*',route);page.goto(origin+'/OAuthPerformanceLab.html')
            page.locator('#names').fill('\n'.join('fixture-'+str(i) for i in range(12000)));page.locator('#confirm').check()
            if mode!='valid':
                expect(page.locator('#auth')).to_contain_text('HTTP 403' if mode=='denied' else 'read-only');expect(page.locator('#start')).to_be_disabled();assert not batches
            else:
                expect(page.locator('#start')).to_be_enabled();page.locator('#start').click();expect(page.locator('#stop')).to_be_enabled();expect(page.locator('#success')).not_to_have_text('0',timeout=5000)
                print('Before manual stop:',page.locator('#status').inner_text(),len(batches),'POSTs',flush=True)
                page.locator('#stop').click(timeout=5000);expect(page.locator('#json')).to_be_enabled()
                with page.expect_download() as download:page.locator('#json').click()
                data=json.loads(download.value.path().read_text());assert data['stop_reason']=='Stopped by administrator' and data['sample_count']==12000 and 'fixture-secret' not in json.dumps(data) and 'fixture-0' not in json.dumps(data)
                with page.expect_download() as csv:page.locator('#csv').click()
                assert 'successful_cps' in csv.value.path().read_text();assert batches
                assert not errors,errors
            page.close()
        browser.close()
    print('PASS browser: administrator/OAuth gate, preview refusal, real request shape, stop and sanitized JSON/CSV export')
finally:server.shutdown()
