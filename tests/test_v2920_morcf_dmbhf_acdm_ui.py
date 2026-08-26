from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(path):
    return (ROOT / path).read_text(encoding='utf-8', errors='ignore')


def test_morcf_captures_requested_oauth_rate_and_preserves_reprocess_path():
    php = text('server/team-points/public/live-ranks-admin.php')
    assert 'use (&$transport, $requestedConcurrency, $requestedRate)' in php
    assert 'batchForAuthorizedRequest($requests, $requestedConcurrency, $requestedRate)' in php
    assert "if ($action === 'process_step')" in php


def test_name_transition_and_other_club_intelligence_tables_use_25_rows():
    js = text('assets/js/pages/club-intelligence.js')
    css = text('assets/css/club-intelligence.css')
    assert 'TABLE_PAGE_SIZE=25,MEMBER_PAGE_SIZE=25' in js
    assert 'function applyTablePagination' in js
    assert 'function installTablePagination' in js
    assert 'content.querySelectorAll("table.ci-table")' in js
    assert 'if(tableEl.id!=="ciMembersTable")applyTablePagination(tableEl)' in js
    assert 'applyTablePagination(tableEl,1)' in js  # sorting resets to page 1
    assert '.ci-table-pagination' in css
    assert '.ci-table tr[hidden]{display:none!important}' in css


def test_name_transition_modal_tracks_visible_parent_viewport_when_embedded():
    js = text('assets/js/pages/club-intelligence.js')
    for marker in (
        'function miacPositionModal',
        'window.frameElement',
        'getBoundingClientRect',
        'window.parent.innerHeight',
        'visibleTop=Math.max(0,-rect.top)',
        'modal.style.position="absolute"',
        'window.parent.addEventListener("scroll",reposition',
        'window.parent.addEventListener("resize",reposition',
        'miacPositionModal(modal)',
    ):
        assert marker in js


def test_dmbhf_hydrates_current_match_index_rows_from_authoritative_match_detail():
    js = text('assets/js/pages/dashboard-v2.js')
    hydration = text('assets/js/shared/dashboard-match-board-hydration.js')
    for marker in (
        'const detailUrl = match =>',
        'https://api.chess.com/pub/match/${Math.trunc(id)}',
        'const boardCount = match =>',
        'function hydrateDashboardMatchBoards',
        '["registered", "ongoing"]',
        'trafficClass: "background"',
        'client.processPriority',
        'Object.assign(entry.match, detail)',
        'Loading authoritative board totals',
        'p2k-dashboard-match-boards-hydrated',
    ):
        assert marker in (js + hydration)
    # The club match index is not allowed to manufacture a board count for current matches.
    assert 'status === "finished" ? matchListTotals(matches) : authoritativeMatchListTotals(matches)' in js
    assert 'games: boards * 2' in js


def test_acdm_server_planner_exposes_debt_urgency_lane_and_adaptive_pulse_contract():
    php = text('server/control/public/api.php')
    for marker in (
        'function p2k_control_acdm_snapshot',
        "'mode' => $total > 0 ? 'canonical-drain' : 'idle'",
        "'recommended_lane' => $recommended",
        "'suggested_quota' => $quota",
        "'suggested_max_seconds' => $seconds",
        "'suggested_next_delay_ms' => $delay",
        "'yield_to_foreground' => true",
        "'canonical_drain'=>$canonicalDrain",
        "'mode'=>'acsr-canonical-drain'",
        "'planner'=>'debt-and-urgency-aware'",
        "'productive'=>$processed>0",
    ):
        assert marker in php


def test_acdm_client_chains_only_while_productive_and_yields_to_p0_foreground():
    js = text('assets/js/shared/client-continuous-refresh.js')
    for marker in (
        'ACDM_MAX_CHAIN_PULSES = 6',
        'ACDM_LOW_BROWSER_TASKS = 8',
        'function canonicalForegroundPressure',
        'function drainCanonicalBacklog',
        'canonical_drain_mode',
        'canonical_drain_productive_pulses',
        'canonical_drain_yields',
        'canonical_recommended_lane',
        'if (canonicalForegroundPressure())',
        'if (processed <= 0',
        'suggested_next_delay_ms',
        'drainCanonicalBacklog(batch, "empty-plan")',
    ):
        assert marker in js
    # The worker transport stays subordinate to the shared API controller.
    assert 'setConcurrency(' not in js
    assert 'setConcurrentMode' not in js
