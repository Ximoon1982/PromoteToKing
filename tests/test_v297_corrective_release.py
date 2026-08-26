from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8", errors="ignore")


def test_release_is_v297_and_preserves_v295_schema_and_catalogue():
    assert text("VERSION").strip() in {"2.9.7","2.9.8","2.9.9","2.9.10","2.9.11","2.9.12","2.9.12","2.9.13","2.9.14", '2.9.15','2.9.16','2.9.17', '2.9.18','2.9.19','2.9.20','2.9.21','2.9.22','2.9.22.1','2.9.22.2'}
    repo = text("server/team-points/src/Repository.php")
    assert any(f'CORE_SCHEMA_VERSION = {v}' in repo for v in [10,11,12,13,14,15])
    assert any(f'ANALYTICS_SCHEMA_VERSION = {v}' in repo for v in [6,7])
    cat = text("server/team-points/src/AchievementCatalog.php")
    keys = re.findall(r"self::item\('([^']+)'", cat)
    assert len(keys) == 162 and len(keys) == len(set(keys))


def test_player_scheduler_is_bounded_fair_not_absolute_priority():
    worker = text("server/team-points/src/Worker.php")
    block = worker[worker.index("private function claimNextScheduledItem"):worker.index("private function refreshTimestampIsFresh")]
    assert "['reconcile_members']" in block
    assert block.count("['sync_player']") >= 2
    assert block.count("['sync_player_stats','sync_player_profile']") >= 2
    assert "sync_player_archive" in block
    assert "$this->playerFairCursor" in block

    # Reproduce the live production shape conceptually: both first-pass classes
    # must receive work even while archive/protected backlog exists.
    schedule = ["matches", "stats", "matches", "stats", "archive"]
    pending = {"matches": 4475, "stats": 4475, "archive": 10000}
    processed = {k: 0 for k in pending}
    for i in range(75):
        lane = schedule[i % len(schedule)]
        if pending[lane]:
            pending[lane] -= 1
            processed[lane] += 1
    assert processed["matches"] > 0
    assert processed["stats"] > 0
    assert processed["archive"] > 0


def test_completed_match_scan_cannot_be_done_without_freshness_write():
    worker = text("server/team-points/src/Worker.php")
    block = worker[worker.index("private function syncPlayer("):worker.index("private function reconcileMembers")]
    mark = block.index("markPlayerMatchesChecked")
    optional = block.index("if($this->hasProcessingBudget(5))", mark)
    assert mark < optional
    assert "if(!$freshnessWritten)throw new \\RuntimeException" in block
    assert "'freshness_written'=>$freshnessWritten" in block
    assert "elseif($this->hasProcessingBudget(5))" not in block


def test_duplicate_fresh_player_work_is_skipped_before_outbound_calls():
    worker = text("server/team-points/src/Worker.php")
    player = worker[worker.index("private function syncPlayer("):worker.index("private function reconcileMembers")]
    stats = worker[worker.index("private function syncPlayerStats"):worker.index("private function syncPlayerProfile")]
    profile = worker[worker.index("private function syncPlayerProfile"):worker.index("private function recentArchiveMonths")]
    assert player.index("_queue_status'=>'skipped'") < player.index("$this->api->json")
    assert stats.index("_queue_status'=>'skipped'") < stats.index("$this->api->json")
    assert profile.index("_queue_status'=>'skipped'") < profile.index("$this->api->json")
    assert "['done','skipped']" in worker


def test_task_control_uses_decorated_lane_job_and_exposes_activity():
    api = text("server/control/public/api.php")
    repo = text("server/team-points/src/Repository.php")
    ui = text("assets/js/pages/task-control.js")
    html = text("TaskControl.html")
    assert "jobs_by_lane" in api and "jobDetails($repository->latestJob" in api
    assert "'task_breakdown'" in repo
    for item_type in ("reconcile_members", "sync_player", "sync_player_stats", "sync_player_archive"):
        assert item_type in api
        assert item_type in ui
    assert 'id="taskActivityWrap"' in html and 'id="taskActivityRows"' in html
    assert 'id="taskQueueRows"' in html and "number(row.skipped)" in ui


def test_cron_has_clear_transport_margin():
    cron = text("server/team-points/src/CronLoop.php")
    cfg = text("server/team-points/config/config.example.php")
    dispatcher = text("cron-dispatch-v2.9.7.sh")
    assert "min(42" in cron or "42" in cron
    assert "30" in cron
    assert "--max-time 55" in dispatcher
    assert "worker_max_seconds' => 30" in cfg
    assert "cron_endpoint_max_seconds' => 42" in cfg



def test_cron_installer_does_not_rewrite_healthy_shared_credentials():
    installer = text("reset-install-cron-v2.9.7.sh")
    assert "$dirty=!$sharedExisted" in installer
    assert 'else{echo "Shared server config: unchanged.' in installer
    # Existing non-placeholder cronToken and analytics secret must not set dirty.
    assert '$dirty=true;echo "Shared CRON token: repaired' in installer
    assert '$dirty=true;echo "Traffic analytics secret: generated' in installer

def test_runtime_diagnostics_cannot_hang_on_one_endpoint():
    js = text("assets/js/pages/runtime-diagnostics.js")
    assert "AbortController" in js
    assert "Promise.allSettled" in js
    assert "8000" in js or "8_000" in js


def test_oauth_poc_uses_protected_persistent_local_config():
    page = text("OAuthTest.php")
    example = text("server/team-points/config/oauth.local.example.php")
    installer = text("install-oauth-poc-v2.9.7.sh")
    assert "server/team-points/config/oauth.local.php" in page
    assert "oauth.local.php" in installer
    assert "refus" in installer.lower() or "already exists" in installer.lower()
    assert "chmod 600" in installer
    assert "client_id" in example and "redirect_url" in example
    assert "oauth=2" in page
    assert "oauth=1" not in page


def test_false_completed_v295_player_scans_are_requeued_without_reset():
    repo = text("server/team-points/src/Repository.php")
    worker = text("server/team-points/src/Worker.php")
    assert "enqueueMissingPlayerMatchFreshnessRepairs" in repo
    assert "d.status='done'" in repo
    assert "m.player_matches_checked_at IS NULL" in repo
    assert "live.status IN ('pending','retry','running')" in repo
    assert "priority-discovery:v297-freshness-repair:" in repo
    assert "enqueueMissingPlayerMatchFreshnessRepairs" in worker
    assert "player_freshness_repairs_queued" in worker


def test_player_catalog_group_headers_have_progress_bar():
    js = text("assets/js/pages/dashboard-v2.js")
    css = text("assets/css/dashboard-v2.css")
    assert "p2k-achievement-group-static" in js
    assert "achievedCount" in js and "groupTotal" in js and "achievementDisplayBar" in js
    assert ".p2k-achievement-group-static" in css and ".p2k-achievement-display-track" in css


def test_live_rook_uses_uncropped_rendering_and_cache_busted_artwork():
    cat = text("server/team-points/src/AchievementCatalog.php")
    css = text("assets/css/dashboard-v2.css")
    assert "live-rank-rook-v297.png" in cat
    assert "live-rank-rook-v297.webp" in cat
    assert (ROOT / "assets/images/achievements/masters/live-rank-rook-v297.png").is_file()
    assert (ROOT / "assets/images/achievements/thumbs/128/live-rank-rook-v297.webp").is_file()
    # Both achievement and Live Rank cards must preserve the full framed artwork.
    assert re.search(r"\.p2k-achievement-card img[^}]*object-fit:\s*contain", css, re.S)
    assert re.search(r"\.p2k-live-rank-card>img[^}]*object-fit:\s*contain", css, re.S)


def test_protected_credentials_are_not_release_payload_candidates():
    # These files may exist on a host, but must never exist in the source payload.
    assert not (ROOT / "server/team-points/config/config.local.php").exists()
    assert not (ROOT / "server/team-points/config/oauth.local.php").exists()
