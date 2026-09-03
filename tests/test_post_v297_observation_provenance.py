from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')

def test_schema_separates_observed_from_verified_freshness_and_shadow_ratings():
    repo=text('server/team-points/src/Repository.php')
    schema=text('server/team-points/sql/core-schema.sql')
    migration=text('server/team-points/sql/core-migration-post-v2.9.7-observation-provenance.sql')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [11,12,13,14,15])
    for field in ['player_matches_observed_at','player_matches_unverified_since','stats_observed_at','stats_unverified_since','observed_daily_rating','observed_chess960_rating','observed_rating_source']:
        assert field in schema and field in migration
    assert 'VALUES(11)' in migration

def test_acamr_claim_is_bound_to_exact_server_assigned_task():
    plan=text('server/team-points/public/acamr-plan.php')
    client=text('assets/js/shared/authenticated-member-refresh.js')
    api=text('assets/js/shared/api-client.js')+text('assets/js/shared/api-request-coordinator.js')
    observe=text('server/team-points/public/observe.php')
    store=text('server/team-points/src/AcamrClaimStore.php')
    assert 'AcamrClaimStore' in plan and 'bin2hex(random_bytes(24))' in store
    assert "'/claim-'.hash('sha256',$token).'.json'" in store
    assert "'tasks'=>$taskRows" in store and "'expires_at'=>$now+$claimTtl" in store
    assert "'claim_token'=>$issueClaimToken" in plan
    assert 'observationClaimToken' in client and 'observationClaimKind' in client
    assert 'const claimBacked = ["acamr","client_refresh"].includes(source)' in api and 'const claimToken = claimBacked ? String(detail.observationClaimToken || "") : ""' in api and 'claimKind: detail.observationClaimKind || ""' in api
    assert 'claim_task_match' in store and "hash_equals((string)($task['url']??''),$url)" in store
    assert "hash_equals(strtolower((string)($task['kind']??'')),$kind)" in store

def test_invalid_acamr_label_does_not_gain_observed_freshness_privilege():
    observe=text('server/team-points/public/observe.php')
    ingestor=text('server/team-points/src/ObservationIngestor.php')
    assert "'claim_verified'=>!empty($claim['verified'])" in observe
    assert "$claimVerified=in_array($source,['acamr','client_refresh'],true)&&!empty($context['claim_verified'])" in ingestor
    assert 'markPlayerMatchesObserved' in ingestor
    assert 'storeObservedMemberRatings' in ingestor
    # Direct canonical writes remain forbidden in the observation ingestor.
    for forbidden in ['upsertPointEvent(', 'storeMemberRatings(', 'applyMembersObservation(', 'upsertMatchMetadata(']:
        assert forbidden not in ingestor

def test_claimed_player_matches_replace_duplicate_discovery_fetch_but_not_targeted_verification():
    ingestor=text('server/team-points/src/ObservationIngestor.php')
    worker=text('server/team-points/src/Worker.php')
    player=ingestor[ingestor.index('private function playerMatches'):ingestor.index('private function playerArchiveHint')]
    assert 'markPlayerMatchesObserved' in player
    assert "'sync_match'" in player and "'sync_board'" in player
    assert "'verification'=>'targeted_server_verification'" in player
    assert 'claim-backed observed player-match freshness is current' in worker
    assert 'player_matches_authoritative_audit_seconds' in worker
    assert "'authoritative_audit'=>$matchesAuditDue" in worker

def test_claimed_stats_use_shadow_values_and_deferred_server_audit():
    ingestor=text('server/team-points/src/ObservationIngestor.php')
    repo=text('server/team-points/src/Repository.php')
    worker=text('server/team-points/src/Worker.php')
    stats=ingestor[ingestor.index('private function playerStats'):ingestor.index('private function matchDetail')]
    assert 'Repository::ratingsFromStats($payload)' in stats
    assert 'storeObservedMemberRatings' in stats
    assert "'canonical_rating_written'=>false" in stats
    assert "'verification'=>'deferred_authoritative_audit'" in stats
    assert 'player_stats_authoritative_audit_seconds' in worker
    assert 'observed_daily_rating' in repo and 'rating_verified' in repo and "'browser_observed'" in repo

def test_reconciliation_uses_operational_freshness_but_hard_audit_cannot_be_suppressed_forever():
    worker=text('server/team-points/src/Worker.php')
    block=worker[worker.index('private function reconcileMembers'):worker.index('private function syncPlayerStats')]
    assert '$matchesCoverage=max($matchesChecked,$matchesObserved)' in block
    assert '$statsCoverage=max($statsChecked,$statsObserved)' in block
    assert '$matchesRoutineDue=' in block and '$statsRoutineDue=' in block
    assert '$matchesAuditDue=' in block and '$statsAuditDue=' in block
    assert '$matchesDue=$matchesRoutineDue||$matchesAuditDue' in block
    assert '$statsDue=$statsRoutineDue||$statsAuditDue' in block
    assert 'authoritative_audits_queued' in block and 'claim_observation_suppressed' in block

def test_diagnostics_show_operational_and_server_verified_freshness_separately():
    service=text('server/team-points/src/ClubIntelligenceService.php')
    ui=text('assets/js/pages/club-intelligence.js')
    control=text('server/control/public/api.php')
    assert 'player_matches_operational_fresh_percent' in service
    assert 'player_stats_operational_fresh_percent' in service
    assert 'Player matches operational fresh' in ui and 'Player matches server-verified' in ui
    assert 'Player stats operational fresh' in ui and 'Player stats server-verified' in ui
    assert 'browser-observed freshness can suppress duplicate discovery calls' in control.lower()

def test_recruitment_can_use_recent_observed_rating_with_visible_provenance_only():
    repo=text('server/team-points/src/Repository.php')
    ui=text('assets/js/pages/recruit-match.js')
    cfg=text('server/team-points/config/config.example.php')
    assert 'allow_claimed_observed_ratings_for_recruitment' in repo and 'observed_rating_recruitment_max_age_seconds' in repo
    assert "'rating_source'=>$source" in repo and "'rating_verified'=>!$useObserved" in repo
    assert 'browser-observed · pending server audit' in ui
    assert 'allow_claimed_observed_ratings_for_recruitment' in cfg

def test_claim_receipts_are_bounded_and_expire():
    plan=text('server/team-points/public/acamr-plan.php')
    store=text('server/team-points/src/AcamrClaimStore.php')
    assert "glob($this->root.'/claim-*.json')" in store
    assert "ledgerPath('claims'" in store and 'private const SHARDS = 256' in store
    assert "(int)($row['expires_at']??0)<$now-60" in store

def test_passive_observation_cannot_dedupe_away_claim_receipt():
    api=text('assets/js/shared/api-client.js')+text('assets/js/shared/api-request-coordinator.js')
    assert 'const dedupeKey = claimBacked && claimToken' in api
    assert '`${source}:${claimToken}:${url}`' in api
    assert '`${source}:${url}`' in api

def test_stale_if_error_cannot_satisfy_claimed_freshness():
    api=text('assets/js/shared/api-client.js')+text('assets/js/shared/api-request-coordinator.js')
    assert 'if (result.cacheState !== "STALE_IF_ERROR")' in api
    assert 'result.observationClaimToken = options.observationClaimToken' in api
    assert 'result.observationClaimKind = options.observationClaimKind' in api
