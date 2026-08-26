from pathlib import Path
import subprocess, textwrap, json, re

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_identity():
    assert text('VERSION').strip() in {'2.10.4.1','2.10.4.2','2.10.4.3'}
    assert text('MIGRATION_VERSION').strip() in {'2.10.4.1','2.10.4.2','2.10.4.3'}
    assert text('BLUE_BASELINE_VERSION').strip()=='2.9.22.10'

def test_server_remains_authoritative_for_real_admin_identity():
    session=text('server/team-points/public/session.php')
    assert 'OAuthSession::authenticatedUsername(true)' in session
    assert "$body['username']" not in session and "body()['username']" not in session
    assert 'Auth::clubProfileHasAdmin($profile, $username)' in session

def test_client_does_not_require_browser_local_username():
    client=text('assets/js/shared/team-points-client.js')
    assert 'Log in with a verified club administrator account to use Team Points administration.' not in client
    assert 'bootstrap_assertion: bootstrapAssertion' in client
    assert 'server-signed assertion for the real OAuth identity' in client
    assert 'P2K_ADMIN_USERNAME' not in client
    # Executable semantic check: local P2K_AUTH has no session, secure bootstrap still succeeds.
    script=textwrap.dedent(f'''
      global.window=global;
      global.location={{href:'https://p2k.test/TeamPointsAdmin.html'}};
      global.P2K_SITE_CONFIG={{serverStorage:{{teamPointsEndpoint:'server/team-points/public/api.php',teamPointsSessionEndpoint:'server/team-points/public/session.php',teamPointsPublicEndpoint:'server/team-points/public/public.php'}}}};
      global.P2K_AUTH={{getSession(){{return null;}},getAdminBootstrap(){{return 'signed.assertion';}},getAdminBootstrapAgeMs(){{return 0;}}}};
      global.dispatchEvent=()=>true;
      global.addEventListener=()=>{{}};
      global.CustomEvent=class CustomEvent{{constructor(type,init){{this.type=type;this.detail=init?.detail;}}}};
      let calls=[];
      global.fetch=async(url,opt={{}})=>{{calls.push({{url:String(url),method:opt.method||'GET',body:opt.body||''}});return new Response(JSON.stringify({{ok:true,csrf:'csrf-1',username:'ximoon'}}),{{status:200,headers:{{'Content-Type':'application/json'}}}});}};
      eval({json.dumps(client)});
      (async()=>{{const p=await P2K_TEAM_POINTS_CLIENT.connect();if(p.username!=='ximoon')throw new Error('wrong username');if(calls.length!==1||calls[0].method!=='POST'||JSON.parse(calls[0].body).bootstrap_assertion!=='signed.assertion')throw new Error(JSON.stringify(calls));console.log('PASS');}})().catch(e=>{{console.error(e);process.exit(1)}});
    ''')
    proc=subprocess.run(['node','-e',script],capture_output=True,text=True,check=False)
    assert proc.returncode==0, proc.stdout+proc.stderr
    assert 'PASS' in proc.stdout

def test_real_admin_ui_bootstraps_secure_session_but_display_preview_does_not():
    guard=text('assets/js/shared/admin-page-guard.js')
    dash=text('assets/js/pages/dashboard-v2.js')
    tabs=text('assets/js/pages/site-tabs.js')
    assert '!displayOverride && !simulatedOverride && window.P2K_TEAM_POINTS_CLIENT?.connect' in guard
    assert 'oauthMode() !== 1 && session?.displayOnly !== true && window.P2K_TEAM_POINTS_CLIENT?.connect' in tabs
    assert '!displayOverride&&!simulatedOverride&&window.P2K_TEAM_POINTS_CLIENT?.connect' in dash
    for src in (guard,dash,tabs):
        assert 'ADMIN_AUTH_FAILED' in src and 'OAUTH_SESSION_REQUIRED' in src
    # Guard explicitly prevents real-account server bootstrap when ?name= is active.
    assert 'Never use this shortcut for ?name=' in guard

def test_affected_admin_surfaces_cache_bust_and_load_client_before_guard():
    affected=['TeamPointsAdmin.html','TaskLogs.html','DataReconciliation.html','ChallengeListAssistant.html','ClubIntelligence.html','TeamPointsMigration.html','TaskControl.html']
    for rel in affected:
        src=text(rel)
        assert 'team-points-client.js?v=2.10.4.3' in src, rel
        assert 'admin-page-guard.js?v=2.10.4.3' in src, rel
        assert src.index('team-points-client.js') < src.index('admin-page-guard.js'), rel
    assert 'dashboard-v2.js?v=2.10.4.3' in text('ui-v2.html')
    assert 'site-tabs.js?v=2.10.4.3' in text('index.html')
    # No accidental double cache suffix.
    for p in ROOT.glob('*.html'):
        assert '2.10.4.1.1' not in p.read_text(encoding='utf-8',errors='ignore')
