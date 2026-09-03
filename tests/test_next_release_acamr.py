from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')

def block(src,start,end):
    a=src.index(start); b=src.index(end,a); return src[a:b]


def test_acamr_client_activation_contract_real_oauth_beats_flag():
    js=text('assets/js/shared/authenticated-member-refresh.js')
    assert 'realOAuthSession(session)' in js
    assert 'return { active: true, mode: "oauth"' in js
    assert 'oauthFlagEnabled()' in js
    simulated=block(js,'function authState()','function pageActive()')
    assert 'realOAuthSession(session)' in simulated
    assert 'simulated && oauthFlagEnabled()' in simulated
    assert simulated.index('realOAuthSession(session)') < simulated.index('oauthFlagEnabled()')


def test_acamr_scope_excludes_tournaments_and_registration():
    js=text('assets/js/shared/authenticated-member-refresh.js')
    endpoint=text('server/team-points/public/acamr-plan.php')
    assert "['kind'=>'matches'" in endpoint and "['kind'=>'stats'" in endpoint and "['kind'=>'archive'" in endpoint
    assert "'tournaments'=>false" in endpoint and "'match_registration'=>false" in endpoint
    tasks=block(endpoint,"$tasks=[","$claims[]=['username'")
    assert '/tournament/' not in tasks.lower()
    assert 'registered' not in tasks.lower() and 'registration' not in tasks.lower()
    assert '/tournament\\//i' in js


def test_acamr_is_low_pulse_and_cross_context_single_leader():
    js=text('assets/js/shared/authenticated-member-refresh.js')
    endpoint=text('server/team-points/public/acamr-plan.php')
    assert 'LEADER_KEY' in js and 'LEADER_TTL_MS = 30_000' in js
    assert 'acquireLeader()' in js and 'releaseLeader()' in js
    assert "'acamr_pulse_ms']??20000" in endpoint
    assert 'priority: -140' in js


def test_acamr_server_claims_distribute_adaptively_without_acamr_schema_table():
    endpoint=text('server/team-points/public/acamr-plan.php')
    repo=text('server/team-points/src/Repository.php')
    assert 'acamrCandidateMembers' in endpoint and 'acamrCandidateMembers' in repo
    assert 'priority_score' in repo and 'matches_due' in endpoint and 'stats_due' in endpoint
    store=text('server/team-points/src/AcamrClaimStore.php')
    assert "AcamrClaimStore" in endpoint and "'/acamr'" in store and '$claimStore->claimMember(' in endpoint and "ledgerPath('members'" in store
    assert "fopen($path,$create?'c+':'r+')" in store and 'flock($handle,LOCK_EX)' in store
    assert 'FROM p2k_tp_' not in endpoint and 'INSERT INTO p2k_tp_' not in endpoint  # endpoint adds no ACAMR persistence table
    candidate=block(repo,'public function acamrCandidateMembers','public function recoverFailedBoardsToLane')
    assert "mm.status IN ('in_progress','finished')" in candidate and "'registered'" not in candidate


def test_acamr_observations_are_tagged_and_server_verification_remains_authoritative():
    api=text('assets/js/shared/api-client.js')+text('assets/js/shared/api-request-coordinator.js')
    observe=text('server/team-points/public/observe.php')
    ingestor=text('server/team-points/src/ObservationIngestor.php')
    assert 'result.observationSource = options.observationSource' in api
    assert '["acamr","client_refresh"].includes(detail.observationSource)' in api
    assert "in_array($source,['acamr','client_refresh'],true)" in observe and '$ingestor->ingest($url,$payload,$source,[' in observe
    assert "canonical_point_events_from_browser'=>false" in ingestor
    assert 'upsertPointEvent' not in ingestor
    assert "'verification'=>'server_required'" in ingestor


def test_acamr_player_matches_explicitly_ignore_registered_bucket():
    ingestor=text('server/team-points/src/ObservationIngestor.php')
    player=block(ingestor,'private function playerMatches','private function playerArchiveHint')
    assert "$source==='acamr'?[['finished','finished'],['in_progress','in_progress']]" in player
    assert "['registered','registered']" in player
    assert "'match_registration_processed'=>$source!=='acamr'" in player


def test_acamr_archive_hints_target_authoritative_board_work_instead_of_refetching_archive():
    ingestor=text('server/team-points/src/ObservationIngestor.php')
    archive=block(ingestor,'private function playerArchiveHint','private function usernameFromPlayer')
    assert "if($source!=='acamr')" in archive
    assert "'sync_player_archive'" in archive
    assert "'sync_board'" in archive and 'participationForMatch' in archive and 'boardState' in archive
    assert "'acamr'=>true" in archive
    assert 'upsertPointEvent' not in archive


def test_acamr_loaded_anywhere_simulated_auth_is_loaded():
    offenders=[]
    for p in list(ROOT.glob('*.html'))+list(ROOT.glob('*.htm')):
        s=p.read_text(encoding='utf-8',errors='ignore')
        if 'assets/js/shared/simulated-oauth.js' in s and 'assets/js/shared/authenticated-member-refresh.js' not in s:
            offenders.append(p.name)
    assert offenders==[]


def test_acamr_config_and_simulated_session_mode_are_explicit():
    cfg=text('assets/js/site-config.js')
    oauth=text('assets/js/shared/simulated-oauth.js')
    example=text('server/team-points/config/config.example.php')
    assert 'acamrPlanEndpoint' in cfg
    assert 'mode: "simulated"' in oauth and 'authMode: "simulated"' in oauth
    for key in ['acamr_claim_ttl_seconds','acamr_pulse_ms','acamr_scan_batch_size']:
        assert key in example


def test_simulated_provider_never_overwrites_preexisting_real_oauth():
    oauth=text('assets/js/shared/simulated-oauth.js')
    assert 'const existingAuth = window.P2K_AUTH' in oauth
    assert 'if (existingRealOAuth)' in oauth
    assert oauth.index('if (existingRealOAuth)') < oauth.index('window.P2K_AUTH = authAPI')
