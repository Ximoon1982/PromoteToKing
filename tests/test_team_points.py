#!/usr/bin/env python3
from __future__ import annotations
import json, tempfile, threading
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
import sys
sys.path.insert(0, str(ROOT))
import serve_team_points_local as local


def call(base: str, path: str, method: str = "GET", token: str | None = None, payload=None):
    data = None if payload is None else json.dumps(payload).encode()
    headers = {}
    if token: headers["X-P2K-Admin-Token"] = token
    if data is not None: headers["Content-Type"] = "application/json"
    req = Request(base + path, data=data, headers=headers, method=method)
    with urlopen(req, timeout=10) as response:
        body = response.read()
        return response.status, response.headers, body


def main() -> None:
    with tempfile.TemporaryDirectory() as tmp:
        db = Path(tmp) / "team.sqlite3"
        server = local.create_server(ROOT, "127.0.0.1", 0, db, "local-admin", "local-cron")
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        host, port = server.server_address[:2]
        base = f"http://127.0.0.1:{port}"
        try:
            status, _, body = call(base, "/?admin=1")
            assert status == 200 and b"tab-teamPoints" in body

            # Existing baseline API remains available through the combined server.
            status, _, body = call(base, "/api/diagnostics/")
            assert status == 200 and json.loads(body)["ok"] is True

            try:
                call(base, local.API_PATH + "?action=status")
                raise AssertionError("Team Points API accepted a missing token")
            except HTTPError as error:
                assert error.code == 401

            status, _, body = call(base, local.API_PATH + "?action=status", token="local-admin")
            initial_status = json.loads(body)
            assert status == 200 and initial_status["environment"] == "local-simulation"
            assert initial_status["schema_version"] == 5 and "cron_state" in initial_status
            assert initial_status["manual_update_mode"] == "server_controlled_external_cron_with_optional_immediate_segment"

            # Public dashboard reads never expose the administrator API secret.
            status, _, body = call(base, local.PUBLIC_PATH + "?action=team")
            team_public = json.loads(body)["team"]
            assert status == 200 and team_public["finished_matches_available"] is True
            assert team_public["scoring_rule"] == "win=5×boards; draw=2×boards; loss=0"
            status, _, body = call(base, local.PUBLIC_PATH + "?action=player&username=Ximoon")
            assert status == 200 and json.loads(body)["player"]["username"].lower() == "ximoon"

            _, _, body = call(base, local.API_PATH + "?action=start", "POST", "local-admin", {})
            job = json.loads(body)["job"]
            assert job["status"] == "running"

            # A fresh roster/member-match discovery can be inserted ahead of legacy work
            # without rewriting any existing queue item or payload.
            _, _, body = call(base, local.API_PATH + "?action=prioritize_discovery", "POST", "local-admin", {})
            priority = json.loads(body)
            assert priority["ok"] is True and priority["queued"] is True
            assert priority["item_key"].startswith("priority-discovery:")

            # Each compatibility invocation is bounded; normal processing is server-controlled by external CRON.
            _, _, body = call(base, local.API_PATH + "?action=run", "POST", "local-admin", {"job_id": job["id"]})
            run = json.loads(body)
            assert run["ok"] is True and run["processed_items"] > 0

            # Stop/resume are safe at a checkpoint.
            _, _, body = call(base, local.API_PATH + "?action=stop", "POST", "local-admin", {"job_id": job["id"]})
            assert json.loads(body)["job"]["stop_requested"] == 1
            call(base, local.API_PATH + "?action=run", "POST", "local-admin", {"job_id": job["id"]})
            _, _, body = call(base, local.API_PATH + "?action=status", token="local-admin")
            assert json.loads(body)["job"]["status"] == "paused"
            _, _, body = call(base, local.API_PATH + "?action=resume", "POST", "local-admin", {"job_id": job["id"]})
            assert json.loads(body)["job"]["status"] == "running"

            # CRON runs the same bounded worker.
            status, _, body = call(base, local.CRON_PATH + "?token=local-cron")
            assert status == 200 and json.loads(body)["ok"] is True

            # Finish enough batches for a query.
            for _ in range(10):
                _, _, body = call(base, local.API_PATH + "?action=run", "POST", "local-admin", {"job_id": job["id"]})
                if json.loads(body).get("job", {}).get("status") == "completed":
                    break
            _, _, body = call(base, local.API_PATH + "?action=status", token="local-admin")
            final_status = json.loads(body)
            assert final_status["process_logs"] and final_status["job"]["task_breakdown"]
            assert any(log["task_type"] == "sync_month" for log in final_status["process_logs"])
            assert final_status["board_states"]["complete_immutable"] > 0
            assert sum(final_status["board_states"].values()) * 2 == int(final_status["totals"]["games"])

            # Public Hall of Fame is derived read-only from the existing member/event schema.
            _, _, body = call(base, local.PUBLIC_PATH + "?action=hall")
            hall = json.loads(body)["hall"]
            assert len(hall["ranks"]) == 16 and hall["ranks"][0]["name"] == "Diamond King"
            assert hall["ranks"][-1]["name"] == "Pawn" and hall["ranks"][-1]["minimum"] == 10
            _, _, body = call(base, local.PUBLIC_PATH + "?action=hall&member=Ximoon")
            searched = json.loads(body)["hall"]
            assert searched["search"]["found"] is True and searched["selected_rank"]
            assert searched["members"] == sorted(searched["members"], key=lambda row: (-float(row["points"]), row["username"].lower()))
            assert all("category_position" in row and "team_position" in row for row in searched["members"])

            _, _, body = call(base, local.API_PATH + "?action=results&start_month=2025-01&end_month=2026-08&shape=totals&current_only=1&member=xim&sort_by=username&sort_dir=asc", token="local-admin")
            result = json.loads(body)
            assert result["ok"] is True and result["rows"]
            assert all("xim" in row["username"].lower() for row in result["rows"])
            assert result["sort"] == {"column": "username", "direction": "asc"}

            _, _, body = call(base, local.API_PATH + "?action=results&start_month=2025-01&end_month=2026-08&shape=events&current_only=1&sort_by=points&sort_dir=desc&limit=10", token="local-admin")
            events = json.loads(body)
            assert events["ok"] is True and events["rows"] and len(events["rows"]) <= 10
            assert events["rows"] == sorted(events["rows"], key=lambda row: (-float(row["points"]), row["username"].lower(), row["game_url"]))

            status, headers, body = call(base, local.API_PATH + "?action=export&start_month=2025-01&end_month=2026-08&shape=monthly&current_only=1&member=xim&sort_by=points&sort_dir=desc", token="local-admin")
            assert status == 200 and headers.get_content_type() == "text/csv" and b"username" in body and b"Ximoon" in body

            worker_php = (ROOT / "server/team-points/src/Worker.php").read_text()
            repository_php = (ROOT / "server/team-points/src/Repository.php").read_text()
            core_schema = (ROOT / "server/team-points/sql/core-schema.sql").read_text()
            analytics_schema = (ROOT / "server/team-points/sql/analytics-schema.sql").read_text()
            schema_sql = core_schema + "\n" + analytics_schema
            assert "$data['in_progress']" in worker_php and "source_bucket' => 'in_progress'" in worker_php
            assert "in_progress_matches_scanned" in worker_php and "$seenBoards" in worker_php
            assert "complete_immutable" in worker_php and "potentially_incomplete" in worker_php
            assert "recent_in_progress" in worker_php and "failed_malformed" in worker_php
            assert "pointEventCount" in worker_php and "api_request_skipped" in worker_php
            assert "dueBoardRediscoveriesForClub" in worker_php and "dueBoardRediscoveries" in worker_php
            assert "enqueueBoard($jobId, $board, true)" in worker_php and "board_tasks_rediscovered" in worker_php
            # Old queued sync_board payloads remain valid: source_bucket is optional and
            # username/match_id/board_url are still the only mandatory fields.
            assert "$payload['source_bucket'] ?? $knownState['source_bucket'] ?? 'rediscovered'" in worker_php
            assert "registerBoardDiscovery" in repository_php and "dueBoardRediscoveriesForClub" in repository_php
            assert "queuePriorityDiscovery" in repository_php and "item_key LIKE 'priority-discovery:%'" in repository_php
            assert "sync_player_archive" in worker_php and "roster_only_plus_due_archives" in worker_php
            assert "queueFullMemberHistoryRepair" in repository_php and "queueRawHistoryRepair" in repository_php
            assert "upgradeExistingSchema" in repository_php and "schemaVersion" in repository_php
            assert "backfillBoardStatesBatch" in repository_php
            assert "return $query->rowCount()" in repository_php
            assert "CREATE TABLE IF NOT EXISTS p2k_tp_boards" in core_schema
            assert "CREATE VIEW p2k_tp_board_states" in core_schema
            assert "GROUP BY club_slug, username_key, board_url" not in core_schema
            assert "p2k_core_schema_version" in core_schema
            assert "CREATE TABLE IF NOT EXISTS p2k_tp_cron_state" in core_schema
            assert "CREATE VIEW p2k_tp_match_summaries" in core_schema
            assert "CREATE TABLE IF NOT EXISTS p2k_tp_club_totals" in analytics_schema
            assert "p2k_analytics_schema_version" in analytics_schema
            assert "idx_tp_game_board_end" in core_schema
            assert "p2k_tp_http_cache" not in schema_sql and "p2k_shared_http_cache" not in schema_sql
            assert "finalizeMatchSummaryIfComplete" in repository_php and "backfillMatchSummariesBatch" in repository_php
            assert "rebuildClubTotalsFromSummaries" in repository_php and "AnalyticsBuilder" in repository_php
            assert "p2k_tp_games" in repository_php and "points_x2" in repository_php and "verified_at" in repository_php
            assert "match_summary_finalized" in worker_php and "match_summaries_finalized" in worker_php
            assert "ALTER TABLE p2k_tp_jobs" not in core_schema and "ALTER TABLE p2k_tp_job_items" not in core_schema

            api_php = (ROOT / "server/team-points/public/api.php").read_text()
            cron_php = (ROOT / "server/team-points/public/cron.php").read_text()
            config_php = (ROOT / "server/team-points/config/config.example.php").read_text()
            assert "upgradeExistingSchema" in api_php and "upgradeExistingSchema" in cron_php and "not initialized or upgraded" in cron_php
            assert "prioritize_discovery" in api_php
            assert "board_rediscovery_limit_per_job" in config_php
            assert "cron_loop_max_seconds" in config_php and "cron_reschedule_delay_seconds" in config_php
            assert "CronLoop" in cron_php and "acquireCronChain" in cron_php
            cron_loop_php = (ROOT / "server/team-points/src/CronLoop.php").read_text()
            assert "registerNextInvocation" not in cron_loop_php and "fireAndForget" not in cron_loop_php
            assert "max(15, min(50" in cron_loop_php and "nextDelaySeconds" in cron_loop_php
            assert "workerSeconds" in cron_loop_php and "external five-minute invocation" in cron_loop_php
            assert "?int $maxSecondsOverride = null" in worker_php
            assert "acquireCronChain" in repository_php and "finishCronInvocation" in repository_php
            assert "source_bucket' => $sourceBucket" in worker_php
            assert "$payload['source_bucket'] ?? $knownState['source_bucket'] ?? 'rediscovered'" in worker_php

            session_php = (ROOT / "server/team-points/public/session.php").read_text()
            public_php = (ROOT / "server/team-points/public/public.php").read_text()
            auth_php = (ROOT / "server/team-points/src/Auth.php").read_text()
            assert "createAdminSession" in session_php and "clubProfileHasAdmin" in session_php
            assert "publicPlayerSummary" in public_php and "publicClubDashboard" in public_php and "publicHallOfFame" in public_php
            assert "publicHallOfFame" in repository_php and "hallRankDefinitions" in repository_php
            assert "httponly" in auth_php and "samesite" in auth_php and "X-P2K-CSRF" in auth_php

            client_js = (ROOT / "assets/js/pages/team-points-admin.js").read_text()
            assert "runManualContinuously" in client_js and "while (state.manualRunning" not in client_js
            assert "Running one bounded compatibility segment" in client_js
            assert "requestSafePause" in client_js and "action: 'stop'" not in client_js
            assert "Safe pause" in client_js or "safe pause" in client_js
            assert "data-sort" in client_js and "tpMemberSearch" in client_js
            assert "process_logs" in client_js and "task_breakdown" in client_js
            assert "tpPrioritizeDiscovery" in client_js and "prioritize_discovery" in client_js
        finally:
            server.shutdown()
            baseline = getattr(server, "baseline_server", None)
            if baseline is not None:
                baseline.shutdown(); baseline.server_close()
            server.server_close()
    print("Team Points integration tests passed.")

if __name__ == "__main__":
    main()
