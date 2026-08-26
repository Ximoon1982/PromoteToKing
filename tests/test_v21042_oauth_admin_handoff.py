from pathlib import Path
import json, os, subprocess, tempfile, textwrap

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

def test_release_identity():
    assert text('VERSION').strip()in {'2.10.4.2','2.10.4.3'}
    assert text('MIGRATION_VERSION').strip()in {'2.10.4.2','2.10.4.3'}
    assert text('BLUE_BASELINE_VERSION').strip()=='2.9.22.10'

def test_oauth_session_emits_short_lived_signed_admin_handoff():
    oauth=text('server/team-points/src/OAuthSession.php')
    assert "'admin_bootstrap'=>$authenticated?self::adminBootstrapAssertion" in oauth
    assert "'aud'=>'p2k-team-points-admin'" in oauth
    assert 'hash_hmac(\'sha256\'' in oauth
    assert '$exp = $now + 60;' in oauth
    assert 'verifyAdminBootstrapAssertion' in oauth

def test_server_prefers_signed_handoff_and_never_trusts_raw_username():
    session=text('server/team-points/public/session.php')
    assert "$body['bootstrap_assertion']" in session
    assert 'OAuthSession::verifyAdminBootstrapAssertion($assertion)' in session
    assert "$body['username']" not in session
    assert 'Auth::clubProfileHasAdmin($profile, $username)' in session
    assert 'OAuthSession::authenticatedUsername(true)' in session  # compatibility only

def test_signed_handoff_round_trip_is_session_independent_and_tamper_safe(tmp_path):
    cfg=tmp_path/'config.php'
    cfg.write_text("<?php return ['app'=>['admin_token'=>'unit-test-admin-secret-123456789']];\n",encoding='utf-8')
    php=textwrap.dedent(f'''\
      <?php
      putenv('P2K_TP_CONFIG={str(cfg)}');
      require {json.dumps(str(ROOT/'server/team-points/src/bootstrap.php'))};
      $a=\\P2K\\TeamPoints\\OAuthSession::adminBootstrapAssertion('Ximoon', time()+3600);
      if($a===''){{fwrite(STDERR,"empty assertion\\n");exit(2);}}
      $u=\\P2K\\TeamPoints\\OAuthSession::verifyAdminBootstrapAssertion($a);
      if($u!=='ximoon'){{fwrite(STDERR,"roundtrip:$u\\n");exit(3);}}
      // The verification path must not require an active PHP session.
      if(session_status()===PHP_SESSION_ACTIVE){{fwrite(STDERR,"unexpected active session\\n");exit(4);}}
      $bad=substr($a,0,-1).(substr($a,-1)==='A'?'B':'A');
      if(\\P2K\\TeamPoints\\OAuthSession::verifyAdminBootstrapAssertion($bad)!==''){{fwrite(STDERR,"tamper accepted\\n");exit(5);}}
      echo "PASS\\n";
    ''')
    proc=subprocess.run(['php','-r',php.replace('<?php','',1)],capture_output=True,text=True,check=False)
    assert proc.returncode==0, proc.stdout+proc.stderr
    assert 'PASS' in proc.stdout

def test_client_waits_for_oauth_assertion_and_posts_only_signed_handoff():
    client=text('assets/js/shared/team-points-client.js')
    assert 'oauthBootstrapAssertion' in client
    assert 'getAdminBootstrap' in client
    assert 'bootstrap_assertion: bootstrapAssertion' in client
    assert 'JSON.stringify({ username:' not in client
    script=textwrap.dedent(f'''
      global.window=global;
      global.location={{href:'https://p2k.test/LiveRanks.html'}};
      global.P2K_SITE_CONFIG={{serverStorage:{{teamPointsEndpoint:'server/team-points/public/api.php',teamPointsSessionEndpoint:'server/team-points/public/session.php',teamPointsPublicEndpoint:'server/team-points/public/public.php'}}}};
      let bootstrap='';
      global.P2K_AUTH={{getAdminBootstrap(){{return bootstrap;}},getSession(){{return null;}}}};
      let readyResolve; global.P2K_REAL_OAUTH_READY=new Promise(r=>readyResolve=r);
      global.dispatchEvent=()=>true; global.addEventListener=()=>{{}};
      global.CustomEvent=class CustomEvent{{constructor(type,init){{this.type=type;this.detail=init?.detail;}}}};
      let calls=[];
      global.fetch=async(url,opt={{}})=>{{calls.push({{url:String(url),method:opt.method||'GET',body:opt.body||''}});return new Response(JSON.stringify({{ok:true,csrf:'csrf-2',username:'ximoon'}}),{{status:200,headers:{{'Content-Type':'application/json'}}}});}};
      eval({json.dumps(client)});
      setTimeout(()=>{{bootstrap='signed.assertion';readyResolve({{username:'ximoon'}});}},20);
      (async()=>{{
        const p=await P2K_TEAM_POINTS_CLIENT.connect();
        if(p.username!=='ximoon')throw new Error('wrong username');
        const b=JSON.parse(calls[0].body||'{{}}');
        if(b.bootstrap_assertion!=='signed.assertion'||Object.keys(b).length!==1)throw new Error(JSON.stringify(calls));
        console.log('PASS');
      }})().catch(e=>{{console.error(e);process.exit(1)}});
    ''')
    proc=subprocess.run(['node','-e',script],capture_output=True,text=True,check=False)
    assert proc.returncode==0, proc.stdout+proc.stderr
    assert 'PASS' in proc.stdout

def test_display_identity_remains_ui_only():
    oauth=text('assets/js/shared/real-oauth.js')
    assert 'authenticatedUsername: session.username' in oauth
    assert 'getAdminBootstrap: () => adminBootstrap' in oauth
    assert 'requestedDisplayName' in oauth
    # The signed assertion is generated server-side from OAuth session username, never from ?name=.
    server=text('server/team-points/src/OAuthSession.php')
    assert 'adminBootstrapAssertion($username' in server

def test_all_guarded_pages_load_secured_client_before_guard_when_both_are_present():
    for page in list(ROOT.glob('*.html')) + list(ROOT.glob('*.htm')):
        src=page.read_text(encoding='utf-8',errors='ignore')
        if 'admin-page-guard.js' in src and 'team-points-client.js' in src:
            assert src.index('team-points-client.js') < src.index('admin-page-guard.js'), page.name

def test_reload_critical_surfaces_force_v21042_oauth_and_team_points_cache_busters():
    for rel in ['TeamPointsAdmin.html','TeamPointsMigration.html','TaskControl.html','index.html','ui-v2.html']:
        src=text(rel)
        assert 'real-oauth.js?v=2.10.4.3' in src, rel
        assert 'team-points-client.js?v=2.10.4.3' in src, rel
    assert 'real-oauth.js?v=2.10.4.3' in text('LiveRanks.html')
