from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def text(relative: str) -> str:
    return (ROOT / relative).read_text(encoding="utf-8")


def test_compatibility_analytics_uses_authoritative_watermarks_before_full_rebuild():
    compatibility = text("server/team-points-green/src/GreenCompatibility.php")
    worker = text("server/team-points-green/src/GreenWorker.php")
    block = compatibility[compatibility.index("public function maybeRebuildAnalytics"):compatibility.index("public function smokeTest")]
    assert "refreshIfNeeded($this->green->clubSlug)" in block
    assert "refreshAchievementsIfNeeded($this->green->clubSlug)" in block
    assert "watermarks_current" in block and "source_changed" in block
    assert "$this->rebuildAnalytics();return true" not in block
    assert "$this->maintenance['compat_analytics']=$detail" in worker
    assert "compat_analytics_skips" in worker
    task_control = text("assets/js/pages/task-control.js")
    assert "compatReason" in task_control and 'replaceAll("_"," ")' in task_control
    assert "finite lock" in task_control and "compatWork" in task_control


def test_compatibility_analytics_remains_post_lock_and_separately_serialized():
    worker = text("server/team-points-green/src/GreenWorker.php")
    post = worker[worker.index("private function runPostLockAnalytics"):worker.index("public function run()")]
    assert "GET_LOCK('p2k_green_analytics_maintenance',0)" in post
    assert "RELEASE_LOCK('p2k_green_analytics_maintenance')" in post
    run = worker[worker.index("public function run()") :]
    assert run.index("RELEASE_LOCK('p2k_green_worker')") < run.index("$this->runPostLockAnalytics()")


def test_gab_exposes_measured_convergence_categories_without_faking_completion():
    gab = text("server/team-points-green/src/GreenAnalyticsBootstrap.php")
    task_control = text("assets/js/pages/task-control.js")
    for key in ("progress_numerator", "progress_denominator", "total_obligations", "completed", "unresolved", "retryable", "terminal_retired", "currently_due", "oldest_unresolved", "last_real_progress_at"):
        assert f"'{key}'" in gab
    assert "display_percent_is_lane_weighted" in gab
    assert "Measured obligations" in task_control
    assert "Lane-weighted display" in task_control
    assert "Last real progress" in task_control


def test_adapter_parity_comes_from_recorded_read_parity_not_a_missing_payload_flag():
    api = text("server/team-points-green/public/api.php")
    task_control = text("assets/js/pages/task-control.js")
    assert "(string)($lane['lane_key'] ?? '') === 'read_parity'" in api
    assert "'green_public_adapter_ready'=>(bool)($compatibility['parity']['ready']??false)" in api
    assert "'mismatches'=>$mismatches" in api and "'lane_status'=>$laneStatus" in api
    assert "payload?.compatibility?.parity" in task_control
    assert "Adapter installed · parity complete" in task_control
    assert "payload?.compatibility?.smoke?.checks" in task_control


def test_stale_invocations_are_classified_read_only_and_error_finalization_is_attempted():
    repository = text("server/team-points-green/src/GreenRepository.php")
    worker = text("server/team-points-green/src/GreenWorker.php")
    api = text("server/team-points-green/public/api.php")
    task_control = text("assets/js/pages/task-control.js")
    recent = repository[repository.index("public function recentInvocations"):repository.index("public function recentCycleDurations")]
    assert "THEN 'stale_running' ELSE status END operational_status" in recent
    assert "UPDATE" not in recent
    failure = worker[worker.index("}catch(\\Throwable $e){", worker.index("public function run()")):]
    assert failure.index("catch(\\Throwable $ignored){}") < failure.index("finishInvocation($invocation,'error'")
    assert "$last['operational_status'] ?? $last['status']" in api
    assert "last.operational_status||last.status" in task_control
