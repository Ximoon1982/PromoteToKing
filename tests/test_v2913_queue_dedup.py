from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8', errors='ignore')


def test_core13_schema_has_active_only_canonical_uniqueness():
    schema = text('server/team-points/sql/core-schema.sql')
    mig = text('server/team-points/sql/core-migration-v2.9.13.sql')
    for src in (schema, mig):
        assert 'canonical_scope' in src
        assert 'canonical_key' in src
        assert 'requested_generation' in src
        assert 'requested_payload_json' in src
        assert 'coalesced_count' in src
        assert 'active_dedupe_key' in src
        assert "status IN ('pending','running','retry')" in src
        assert 'uq_tp_active_canonical' in src
    assert 'DROP INDEX uq_tp_job_item' in mig
    assert 'VALUES(13)' in mig


def test_repository_canonicalizes_every_production_queue_type():
    src = text('server/team-points/src/Repository.php')
    assert any(f'CORE_SCHEMA_VERSION = {v}' in src for v in [13,14,15])
    for queue_type in [
        'sync_club_matches','sync_roster','sync_members','sync_match','sync_board',
        'sync_player','sync_player_stats','sync_player_profile','sync_opponent_profile',
        'sync_player_archive','reconcile_members','discover_match_ids'
    ]:
        assert f"'{queue_type}'" in src
    assert "'sync_roster','sync_members'=>'club-members'" in src
    assert "'sync_match'=>" in src and "'match:'" in src
    assert "'sync_board'=>" in src and "'board:'" in src


def test_enqueue_merges_active_work_and_running_requests_only_schedule_one_generation():
    src = text('server/team-points/src/Repository.php')
    assert "status IN ('pending','running','retry')" in src
    assert 'mergeActiveQueueItem' in src
    # A flood while running targets current generation + 1, not +N.
    assert "max((int)$existing['generation']+1,(int)($existing['requested_generation']??1))" in src
    # Completion converts that one requested generation back to pending.
    assert "$requested>$generation" in src
    assert "status='pending',attempts=0" in src
    assert 'payload_json=requested_payload_json' in src


def test_priority_and_scope_survive_coalescing():
    src = text('server/team-points/src/Repository.php')
    assert 'queueScopeForJob' in src
    assert "!empty($payload['freshness_deadline'])" in src
    assert "!empty($payload['priority_discovery'])" in src
    assert 'min((int)' in src and 'priority_rank' in src
    assert "ORDER BY priority_rank" in src
    assert 'coalesced_sources' in src
    assert "if($canonical==='club-members'" in src
    assert "return 'sync_members'" in src


def test_compactor_preserves_terminal_history_and_only_skips_outstanding_duplicates():
    src = text('server/team-points/src/Repository.php')
    start = src.index('public function compactOutstandingQueue')
    end = src.index('public function queuePriorityDiscovery', start)
    block = src[start:end]
    assert "$phases=[['running'],['pending','retry']]" in block
    assert "status='skipped'" in block
    assert "status IN ('pending','retry')" in block
    assert 'legacyRunningDuplicates' in block
    assert "'done'" not in block and "'failed'" not in block


def test_worker_freshness_deadline_uses_payload_after_key_coalescing():
    src = text('server/team-points/src/Worker.php')
    assert src.count("!empty($payload['freshness_deadline']) || str_starts_with($key, 'freshness-deadline:')") >= 2


def test_synthetic_150k_ticket_compaction_reduces_to_entity_workset():
    # Model the migration invariant: 15k actual matches, 10 differently-keyed
    # requests each. Canonical identity is match ID, not batch/source key.
    tickets = []
    for match_id in range(1, 15_001):
        for source_no in range(10):
            tickets.append((f'batch-{source_no}:match:{match_id}', match_id))
    assert len(tickets) == 150_000
    canonical = {}
    for raw_key, match_id in tickets:
        key = f'match:{match_id}'
        canonical.setdefault(key, {'first': raw_key, 'coalesced': 0})
        if canonical[key]['first'] != raw_key:
            canonical[key]['coalesced'] += 1
    assert len(canonical) == 15_000
    assert sum(v['coalesced'] for v in canonical.values()) == 135_000


def test_running_flood_requires_at_most_one_continuation_generation_model():
    generation = 7
    requested_generation = generation
    for _ in range(50_000):
        requested_generation = max(generation + 1, requested_generation)
    assert requested_generation == 8

def test_membership_deadline_promotion_still_forces_authoritative_network_fetch():
    worker = text('server/team-points/src/Worker.php')
    start = worker.index('private function syncMembers')
    end = worker.index('private function syncPlayer', start)
    block = worker[start:end]
    assert "!empty($payload['freshness_deadline'])" in block
    assert "!empty($payload['freshness_hard_overdue'])" in block
    assert '$this->api->json($url, $networkOnly)' in block


def test_task_control_counts_coalesced_rows_as_committed_and_surfaces_efficiency():
    js = text('assets/js/pages/task-control.js')
    api = text('server/control/public/api.php')
    assert 'queue.committed ?? job.processed_items' in js
    assert 'queue_coalesced_requests' in api
    assert 'queue_active_canonical' in api
    assert 'queue_legacy_uncanonical' in api
    assert 'duplicate request(s) coalesced' in api


def test_deadline_priority_survives_merge_model():
    normal_priority = 10
    deadline_priority = -100
    merged_priority = min(normal_priority, deadline_priority)
    assert merged_priority == -100
    priorities = [20] * 15_000 + [merged_priority, merged_priority]
    assert sorted(priorities)[:2] == [-100, -100]
