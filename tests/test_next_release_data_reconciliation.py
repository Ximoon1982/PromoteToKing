from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def test_data_reconciliation_is_admin_integrated_and_deep_linkable():
    ui=(ROOT/'ui-v2.html').read_text(encoding='utf-8')
    js=(ROOT/'assets/js/pages/dashboard-v2.js').read_text(encoding='utf-8')
    assert 'data-admin-subtab="reconciliation"' in ui
    assert 'adminReconciliationFrame' in ui
    assert '"reconciliation"' in js
    assert 'reconciliation:"adminReconciliationFrame"' in js

def test_reconciliation_conflicts_are_authoritative_sync_only():
    php=(ROOT/'server/team-points/src/DataReconciliationService.php').read_text(encoding='utf-8')
    assert "'conflicting_scores_results_points'=>'authoritative_sync_only'" in php
    assert "'member_absence'=>'never_demote_from_csv'" in php
    assert "'daily_checkpoint'=>'dated_prefix_checksum_not_terminal_total'" in php
    assert "'sync_match'" in php and "'sync_board'" in php and "'sync_members'" in php

def test_reconciliation_checkpoint_is_chronological_prefix_not_end_of_day():
    php=(ROOT/'server/team-points/src/DataReconciliationService.php').read_text(encoding='utf-8')
    assert 'Resolve the dated checkpoint by chronological non-void finish prefix' in php
    assert '$prefixCount===$targetCount' in php
    assert '$prefixPoints===$targetPoints' in php
    assert "'prefix_resolved'=>$prefixFound" in php

def test_reconciliation_does_not_send_full_seed_plan_to_browser():
    php=(ROOT/'server/team-points/src/DataReconciliationService.php').read_text(encoding='utf-8')
    assert 'private function planSummary' in php
    assert "'board_positive_seeds'=>count($plan['board_positive_seeds']??[])" in php
    assert "'plan'=>$this->planSummary($plan)" in php

def test_reconciliation_page_obeys_authenticated_refresh_rule():
    html=(ROOT/'DataReconciliation.html').read_text(encoding='utf-8')
    assert 'assets/js/shared/simulated-oauth.js' in html
    assert 'assets/js/shared/authenticated-member-refresh.js' in html
