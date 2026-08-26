#!/usr/bin/env python3
"""Serve Promote to King v2.8.5 with a local Team Points API simulation.

This development server uses only the Python standard library. It serves the
website, intercepts the PHP Team Points API/cron URLs, and stores durable demo
state in SQLite so start/stop/resume and interruption-safe batches can be tested
without PHP or MariaDB.

The local simulation never calls Chess.com. Production PHP files in
server/team-points/ use Chess.com's public API and MariaDB.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import os
import sqlite3
import threading
import time
import uuid
from datetime import UTC, datetime, timedelta
from http import HTTPStatus
from http.cookies import SimpleCookie
from http.client import HTTPConnection
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

import serve_local as baseline_local

API_PATH = "/server/team-points/public/api.php"
SESSION_PATH = "/server/team-points/public/session.php"
PUBLIC_PATH = "/server/team-points/public/public.php"
CRON_PATH = "/server/team-points/public/cron.php"
LOCAL_ADMIN_TOKEN = "local-admin"
LOCAL_CRON_TOKEN = "local-cron"
CLUB_SLUG = "promote-to-king"
MONTHS = [
    f"{year:04d}-{month:02d}"
    for year, first, last in ((2025, 1, 12), (2026, 1, 8))
    for month in range(first, last + 1)
]
MEMBERS = [
    "Ximoon",
    "Promoter",
    "QueenPilot",
    "TacticalLion",
    "QuietRook",
    "KnightVoyager",
    "BoardArchitect",
    "DailyBishop",
]
DRAW_RESULTS = ("agreed", "repetition", "stalemate", "insufficient", "50move", "timevsinsufficient")
LOSS_RESULTS = ("checkmated", "timeout", "resigned", "lose", "abandoned")


def utc_now() -> datetime:
    return datetime.now(UTC).replace(microsecond=0)


def iso_now() -> str:
    return utc_now().isoformat().replace("+00:00", "Z")


def db_now() -> str:
    return utc_now().strftime("%Y-%m-%d %H:%M:%S")


def username_key(value: str) -> str:
    return value.strip().casefold()


def json_bytes(payload: object) -> bytes:
    return json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


class LocalStore:
    def __init__(self, path: Path) -> None:
        self.path = path
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.lock = threading.RLock()
        self._install()

    def connect(self) -> sqlite3.Connection:
        connection = sqlite3.connect(self.path, timeout=30, isolation_level=None)
        connection.row_factory = sqlite3.Row
        connection.execute("PRAGMA foreign_keys=ON")
        connection.execute("PRAGMA journal_mode=WAL")
        return connection

    def _install(self) -> None:
        with self.connect() as db:
            db.executescript(
                """
                CREATE TABLE IF NOT EXISTS members (
                    club_slug TEXT NOT NULL,
                    username_key TEXT NOT NULL,
                    username TEXT NOT NULL,
                    current_member INTEGER NOT NULL DEFAULT 1,
                    first_seen_at TEXT NOT NULL,
                    last_seen_at TEXT NOT NULL,
                    PRIMARY KEY (club_slug, username_key)
                );
                CREATE TABLE IF NOT EXISTS point_events (
                    club_slug TEXT NOT NULL,
                    username_key TEXT NOT NULL,
                    username TEXT NOT NULL,
                    match_id INTEGER NOT NULL,
                    board_url TEXT NOT NULL,
                    game_url TEXT NOT NULL,
                    game_end_utc TEXT NOT NULL,
                    utc_month TEXT NOT NULL,
                    result_code TEXT NOT NULL,
                    points REAL NOT NULL,
                    verified_at TEXT NOT NULL,
                    PRIMARY KEY (club_slug, username_key, game_url)
                );
                CREATE INDEX IF NOT EXISTS idx_local_events_month
                    ON point_events(club_slug, utc_month, username_key);
                CREATE INDEX IF NOT EXISTS idx_local_events_board_summary
                    ON point_events(club_slug, username_key, match_id, board_url);
                CREATE TABLE IF NOT EXISTS match_summaries (
                    club_slug TEXT NOT NULL,
                    match_id INTEGER NOT NULL,
                    board_count INTEGER NOT NULL,
                    game_count INTEGER NOT NULL,
                    team_score REAL NOT NULL,
                    result TEXT NOT NULL,
                    competition_points INTEGER NOT NULL,
                    finalized_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    PRIMARY KEY (club_slug, match_id)
                );
                CREATE TABLE IF NOT EXISTS club_totals (
                    club_slug TEXT PRIMARY KEY,
                    finished_matches INTEGER NOT NULL DEFAULT 0,
                    finished_boards INTEGER NOT NULL DEFAULT 0,
                    finished_games INTEGER NOT NULL DEFAULT 0,
                    club_points INTEGER NOT NULL DEFAULT 0,
                    won_matches INTEGER NOT NULL DEFAULT 0,
                    drawn_matches INTEGER NOT NULL DEFAULT 0,
                    lost_matches INTEGER NOT NULL DEFAULT 0,
                    updated_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS board_states (
                    club_slug TEXT NOT NULL,
                    username_key TEXT NOT NULL,
                    username TEXT NOT NULL,
                    match_id INTEGER NOT NULL,
                    board_url TEXT NOT NULL,
                    source_bucket TEXT NOT NULL DEFAULT 'rediscovered',
                    state TEXT NOT NULL DEFAULT 'newly_discovered',
                    finished_game_count INTEGER NOT NULL DEFAULT 0,
                    first_discovered_at TEXT NOT NULL,
                    last_discovered_at TEXT NOT NULL,
                    last_checked_at TEXT,
                    next_check_at TEXT,
                    completed_at TEXT,
                    failure_count INTEGER NOT NULL DEFAULT 0,
                    last_error TEXT,
                    PRIMARY KEY (club_slug, username_key, board_url)
                );
                CREATE INDEX IF NOT EXISTS idx_local_board_due
                    ON board_states(club_slug, username_key, state, next_check_at);
                CREATE TABLE IF NOT EXISTS jobs (
                    id TEXT PRIMARY KEY,
                    club_slug TEXT NOT NULL,
                    status TEXT NOT NULL,
                    stop_requested INTEGER NOT NULL DEFAULT 0,
                    processed_items INTEGER NOT NULL DEFAULT 0,
                    total_items INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    started_at TEXT,
                    updated_at TEXT NOT NULL,
                    finished_at TEXT,
                    last_error TEXT
                );
                CREATE TABLE IF NOT EXISTS queue_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_id TEXT NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
                    item_type TEXT NOT NULL,
                    item_key TEXT NOT NULL,
                    payload_json TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending',
                    attempts INTEGER NOT NULL DEFAULT 0,
                    available_at TEXT NOT NULL,
                    locked_at TEXT,
                    updated_at TEXT NOT NULL,
                    last_error TEXT,
                    UNIQUE(job_id, item_type, item_key)
                );
                CREATE TABLE IF NOT EXISTS worker_runs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_id TEXT,
                    trigger_type TEXT NOT NULL,
                    started_at TEXT NOT NULL,
                    finished_at TEXT,
                    processed_items INTEGER NOT NULL DEFAULT 0,
                    result_status TEXT NOT NULL,
                    message TEXT
                );
                CREATE TABLE IF NOT EXISTS job_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_id TEXT,
                    worker_run_id INTEGER,
                    level TEXT NOT NULL DEFAULT 'info',
                    task_type TEXT NOT NULL,
                    item_key TEXT,
                    message TEXT NOT NULL,
                    context_json TEXT,
                    created_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_local_job_logs
                    ON job_logs(job_id, created_at, id);
                """
            )
            now = db_now()
            for member in MEMBERS:
                db.execute(
                    """
                    INSERT INTO members(club_slug,username_key,username,current_member,first_seen_at,last_seen_at)
                    VALUES(?,?,?,?,?,?)
                    ON CONFLICT(club_slug,username_key) DO UPDATE SET
                      username=excluded.username,current_member=1,last_seen_at=excluded.last_seen_at
                    """,
                    (CLUB_SLUG, username_key(member), member, 1, now, now),
                )

    def latest_job(self) -> dict | None:
        with self.connect() as db:
            row = db.execute("SELECT * FROM jobs ORDER BY created_at DESC LIMIT 1").fetchone()
            return dict(row) if row else None

    def active_job(self) -> dict | None:
        with self.connect() as db:
            row = db.execute(
                "SELECT * FROM jobs WHERE status IN ('new','running','paused','failed') ORDER BY created_at DESC LIMIT 1"
            ).fetchone()
            return dict(row) if row else None

    def create_job(self) -> dict:
        with self.lock, self.connect() as db:
            active = db.execute(
                "SELECT * FROM jobs WHERE status IN ('new','running','paused') ORDER BY created_at DESC LIMIT 1"
            ).fetchone()
            if active:
                return dict(active)
            job_id = str(uuid.uuid4())
            now = db_now()
            db.execute("BEGIN IMMEDIATE")
            try:
                db.execute(
                    "INSERT INTO jobs(id,club_slug,status,created_at,updated_at,total_items) VALUES(?,?,?,?,?,?)",
                    (job_id, CLUB_SLUG, "running", now, now, len(MONTHS)),
                )
                for month in MONTHS:
                    db.execute(
                        """
                        INSERT INTO queue_items(job_id,item_type,item_key,payload_json,status,available_at,updated_at)
                        VALUES(?,?,?,?,?,?,?)
                        """,
                        (job_id, "sync_month", month, json.dumps({"month": month}), "pending", now, now),
                    )
                db.execute("COMMIT")
            except Exception:
                db.execute("ROLLBACK")
                raise
            self.log(job_id, None, "info", "job_created", CLUB_SLUG, "Local full collection job created.", {"months_queued": len(MONTHS)})
            return self.job(job_id) or {}

    def queue_priority_discovery(self) -> dict:
        job = self.create_job()
        job_id = str(job.get("id") or "")
        batch_id = uuid.uuid4().hex
        item_key = f"priority-discovery:{batch_id}:members"
        now = db_now()
        with self.lock, self.connect() as db:
            db.execute(
                """
                INSERT OR IGNORE INTO queue_items(job_id,item_type,item_key,payload_json,status,available_at,updated_at)
                VALUES(?,?,?,?,?,?,?)
                """,
                (job_id, "sync_month", item_key, json.dumps({"month": MONTHS[-1], "priority_discovery": True, "discovery_batch_id": batch_id}), "pending", now, now),
            )
            queued = db.total_changes > 0
            if queued:
                db.execute("UPDATE jobs SET total_items=total_items+1,updated_at=? WHERE id=?", (now, job_id))
        self.log(job_id, None, "info", "priority_discovery_queued", item_key,
                 "Fresh team-member and member-match discovery queued for the next available work slot.",
                 {"discovery_batch_id": batch_id, "queued": queued})
        return {"job": self.job(job_id), "queued": queued, "item_key": item_key, "discovery_batch_id": batch_id}

    def job(self, job_id: str) -> dict | None:
        with self.connect() as db:
            row = db.execute("SELECT * FROM jobs WHERE id=?", (job_id,)).fetchone()
            return dict(row) if row else None

    def pause(self, job_id: str) -> dict:
        with self.connect() as db:
            db.execute(
                "UPDATE jobs SET stop_requested=1,updated_at=? WHERE id=? AND status IN ('new','running')",
                (db_now(), job_id),
            )
        self.log(job_id, None, "info", "pause_requested", None, "A safe pause was requested from the interface.")
        return self.job(job_id) or {}

    def resume(self, job_id: str) -> dict:
        with self.connect() as db:
            db.execute(
                """
                UPDATE jobs SET status='running',stop_requested=0,
                    started_at=COALESCE(started_at,?),updated_at=?,finished_at=NULL,last_error=NULL
                WHERE id=? AND status IN ('paused','failed')
                """,
                (db_now(), db_now(), job_id),
            )
        self.log(job_id, None, "info", "job_resumed", None, "The local collection job was resumed.")
        return self.job(job_id) or {}

    def _queue_counts(self, db: sqlite3.Connection, job_id: str) -> dict[str, int]:
        counts = {name: 0 for name in ("pending", "running", "retry", "done", "failed", "skipped")}
        for row in db.execute("SELECT status,COUNT(*) n FROM queue_items WHERE job_id=? GROUP BY status", (job_id,)):
            counts[str(row["status"])] = int(row["n"])
        return counts

    def log(self, job_id: str | None, run_id: int | None, level: str, task_type: str,
            item_key: str | None, message: str, context: dict | None = None) -> None:
        with self.connect() as db:
            db.execute(
                """
                INSERT INTO job_logs(job_id,worker_run_id,level,task_type,item_key,message,context_json,created_at)
                VALUES(?,?,?,?,?,?,?,?)
                """,
                (job_id, run_id, level[:12], task_type[:40], item_key, message,
                 json.dumps(context, ensure_ascii=False, separators=(",", ":")) if context else None, db_now()),
            )

    def _task_breakdown(self, db: sqlite3.Connection, job_id: str) -> list[dict]:
        result: dict[str, dict] = {}
        for row in db.execute(
            "SELECT item_type,status,COUNT(*) n FROM queue_items WHERE job_id=? GROUP BY item_type,status ORDER BY item_type,status",
            (job_id,),
        ):
            item_type = str(row["item_type"])
            entry = result.setdefault(item_type, {
                "item_type": item_type, "pending": 0, "running": 0, "retry": 0,
                "done": 0, "failed": 0, "skipped": 0, "total": 0,
            })
            entry[str(row["status"])] = int(row["n"])
            entry["total"] += int(row["n"])
        return list(result.values())

    def summary(self) -> dict:
        with self.connect() as db:
            totals = {
                "current_members": db.execute("SELECT COUNT(*) FROM members WHERE club_slug=? AND current_member=1", (CLUB_SLUG,)).fetchone()[0],
                "known_members": db.execute("SELECT COUNT(*) FROM members WHERE club_slug=?", (CLUB_SLUG,)).fetchone()[0],
                "participations": db.execute("SELECT COUNT(DISTINCT username_key || ':' || match_id) FROM point_events WHERE club_slug=?", (CLUB_SLUG,)).fetchone()[0],
                "games": db.execute("SELECT COUNT(*) FROM point_events WHERE club_slug=?", (CLUB_SLUG,)).fetchone()[0],
                "points": db.execute("SELECT COALESCE(SUM(points),0) FROM point_events WHERE club_slug=?", (CLUB_SLUG,)).fetchone()[0],
                "months": db.execute("SELECT COUNT(DISTINCT utc_month) FROM point_events WHERE club_slug=?", (CLUB_SLUG,)).fetchone()[0],
                "first_month": db.execute("SELECT MIN(utc_month) FROM point_events WHERE club_slug=?", (CLUB_SLUG,)).fetchone()[0],
                "last_month": db.execute("SELECT MAX(utc_month) FROM point_events WHERE club_slug=?", (CLUB_SLUG,)).fetchone()[0],
                "http_cache_entries": 0,
                "worker_runs": db.execute("SELECT COUNT(*) FROM worker_runs").fetchone()[0],
                "jobs": db.execute("SELECT COUNT(*) FROM jobs").fetchone()[0],
            }
            job_row = db.execute("SELECT * FROM jobs ORDER BY created_at DESC LIMIT 1").fetchone()
            job = dict(job_row) if job_row else None
            logs: list[dict] = []
            if job:
                job_id = str(job["id"])
                job["queue"] = self._queue_counts(db, job_id)
                job["task_breakdown"] = self._task_breakdown(db, job_id)
                current = db.execute(
                    "SELECT id,item_type,item_key,attempts,locked_at,updated_at FROM queue_items WHERE job_id=? AND status='running' ORDER BY locked_at DESC,id DESC LIMIT 1",
                    (job_id,),
                ).fetchone()
                job["current_item"] = dict(current) if current else None
                job["next_retry_at"] = db.execute(
                    "SELECT MIN(available_at) FROM queue_items WHERE job_id=? AND status='retry'",
                    (job_id,),
                ).fetchone()[0]
                issues = db.execute(
                    """
                    SELECT id,item_type,item_key,status,attempts,available_at,locked_at,updated_at,last_error
                    FROM queue_items WHERE job_id=? AND status IN ('retry','failed')
                    ORDER BY updated_at DESC LIMIT 100
                    """,
                    (job_id,),
                ).fetchall()
                job["issues"] = [dict(row) for row in issues]
                logs = [dict(row) for row in db.execute(
                    "SELECT id,worker_run_id,level,task_type,item_key,message,context_json,created_at FROM job_logs WHERE job_id=? ORDER BY id DESC LIMIT 150",
                    (job_id,),
                )]
            board_states = {
                "newly_discovered": 0,
                "recent_in_progress": 0,
                "potentially_incomplete": 0,
                "failed_malformed": 0,
                "complete_immutable": 0,
            }
            for row in db.execute("SELECT state,COUNT(*) board_count FROM board_states WHERE club_slug=? GROUP BY state", (CLUB_SLUG,)):
                board_states[str(row["state"])] = int(row["board_count"])
            runs = [dict(row) for row in db.execute("SELECT * FROM worker_runs ORDER BY id DESC LIMIT 20")]
        return {
            "ok": True,
            "club_slug": CLUB_SLUG,
            "server_utc": iso_now(),
            "environment": "local-simulation",
            "schema_version": 5,
            "cron_state": None,
            "manual_updates_supported": True,
            "manual_update_mode": "server_controlled_external_cron_with_optional_immediate_segment",
            "totals": totals,
            "board_states": board_states,
            "job": job,
            "worker_runs": runs,
            "process_logs": logs,
        }

    @staticmethod
    def hall_rank_definitions() -> list[dict]:
        return [
            {"key":"diamond-king","name":"Diamond King","minimum":10000,"maximum":None,"image":"16_Diamond_King.png","framed_image":"16_Diamond_King_10000_points.png"},
            {"key":"ruby-king","name":"Ruby King","minimum":8500,"maximum":10000,"image":"15_Ruby_King.png","framed_image":"15_Ruby_King_8500_points.png"},
            {"key":"sapphire-king","name":"Sapphire King","minimum":7000,"maximum":8500,"image":"14_Sapphire_King.png","framed_image":"14_Sapphire_King_7000_points.png"},
            {"key":"emerald-king","name":"Emerald King","minimum":5500,"maximum":7000,"image":"13_Emerald_King.png","framed_image":"13_Emerald_King_5500_points.png"},
            {"key":"topaz-king","name":"Topaz King","minimum":4000,"maximum":5500,"image":"12_Topaz_King.png","framed_image":"12_Topaz_King_4000_points.png"},
            {"key":"amethyst-king","name":"Amethyst King","minimum":3000,"maximum":4000,"image":"11_Amethyst_King.png","framed_image":"11_Amethyst_King_3000_points.png"},
            {"key":"platinum-king","name":"Platinum King","minimum":2000,"maximum":3000,"image":"10_Platinum_King.png","framed_image":"10_Platinum_King_2000_points.png"},
            {"key":"gold-king","name":"Gold King","minimum":1500,"maximum":2000,"image":"09_Gold_King.png","framed_image":"09_Gold_King_1500_points.png"},
            {"key":"silver-king","name":"Silver King","minimum":1000,"maximum":1500,"image":"08_Silver_King.png","framed_image":"08_Silver_King_1000_points.png"},
            {"key":"bronze-king","name":"Bronze King","minimum":500,"maximum":1000,"image":"07_Bronze_King.png","framed_image":"07_Bronze_King_500_points.png"},
            {"key":"king","name":"King","minimum":250,"maximum":500,"image":"06_King.png","framed_image":"06_King_250_points.png"},
            {"key":"queen","name":"Queen","minimum":150,"maximum":250,"image":"05_Queen.png","framed_image":"05_Queen_150_points.png"},
            {"key":"rook","name":"Rook","minimum":100,"maximum":150,"image":"04_Rook.png","framed_image":"04_Rook_100_points.png"},
            {"key":"bishop","name":"Bishop","minimum":50,"maximum":100,"image":"03_Bishop.png","framed_image":"03_Bishop_50_points.png"},
            {"key":"knight","name":"Knight","minimum":20,"maximum":50,"image":"02_Knight.png","framed_image":"02_Knight_20_points.png"},
            {"key":"pawn","name":"Pawn","minimum":10,"maximum":20,"image":"01_Pawn.png","framed_image":"01_Pawn_10_points.png"},
        ]

    @classmethod
    def hall_rank_for_points(cls, points: float) -> dict:
        for rank in cls.hall_rank_definitions():
            if points >= float(rank["minimum"]):
                return rank
        return {
            "key": "unranked", "name": "Unranked", "minimum": 0, "maximum": 10,
            "image": "../p2k-logo.jpg", "framed_image": "../p2k-logo.jpg",
            "description": "Pawn rank begins at 10 Team Points.",
        }

    def public_member_rows(self) -> list[dict]:
        with self.connect() as db:
            rows = [dict(row) for row in db.execute(
                """
                SELECT m.username,m.username_key,ROUND(COALESCE(SUM(e.points),0),1) points,
                       COUNT(DISTINCT e.match_id) matches,COUNT(e.game_url) games,
                       SUM(CASE WHEN e.points=1.0 THEN 1 ELSE 0 END) wins,
                       SUM(CASE WHEN e.points=0.5 THEN 1 ELSE 0 END) draws,
                       SUM(CASE WHEN e.points=0.0 THEN 1 ELSE 0 END) losses
                FROM members m LEFT JOIN point_events e
                  ON e.club_slug=m.club_slug AND e.username_key=m.username_key
                WHERE m.club_slug=? AND m.current_member=1
                GROUP BY m.username_key,m.username
                ORDER BY points DESC,m.username_key ASC
                """, (CLUB_SLUG,)
            ).fetchall()]
        for row in rows:
            for key in ("points",): row[key] = float(row.get(key) or 0)
            for key in ("matches","games","wins","draws","losses"): row[key] = int(row.get(key) or 0)
        return rows

    def public_player(self, username: str) -> dict:
        key = username_key(username)
        with self.connect() as db:
            row = db.execute(
                """
                SELECT COALESCE(MAX(m.username),?) username,
                       COALESCE(MAX(m.current_member),0) current_member,
                       ROUND(COALESCE(SUM(e.points),0),1) points,
                       COUNT(DISTINCT e.match_id) matches,COUNT(e.game_url) games,
                       SUM(CASE WHEN e.points=1.0 THEN 1 ELSE 0 END) wins,
                       SUM(CASE WHEN e.points=0.5 THEN 1 ELSE 0 END) draws,
                       SUM(CASE WHEN e.points=0.0 THEN 1 ELSE 0 END) losses
                FROM members m LEFT JOIN point_events e
                  ON e.club_slug=m.club_slug AND e.username_key=m.username_key
                WHERE m.club_slug=? AND m.username_key=?
                """,
                (username, CLUB_SLUG, key),
            ).fetchone()
        data = dict(row) if row else {}
        points = float(data.get("points") or 0)
        rank = self.hall_rank_for_points(points)
        team_position = None
        category_position = None
        if bool(data.get("current_member") or 0):
            category_position = 0
            for index, member in enumerate(self.public_member_rows(), 1):
                if self.hall_rank_for_points(float(member["points"]))["key"] == rank["key"]:
                    category_position += 1
                if member["username_key"] == key:
                    team_position = index
                    break
        return {
            "username": data.get("username") or username,
            "current_member": bool(data.get("current_member") or 0),
            "points": points,
            "matches": int(data.get("matches") or 0),
            "games": int(data.get("games") or 0),
            "wins": int(data.get("wins") or 0),
            "draws": int(data.get("draws") or 0),
            "losses": int(data.get("losses") or 0),
            "team_position": team_position,
            "category_position": category_position,
            "rank": rank,
            "available": True,
        }

    def public_team(self) -> dict:
        with self.connect() as db:
            row = db.execute("SELECT * FROM club_totals WHERE club_slug=?", (CLUB_SLUG,)).fetchone()
            if row is None:
                aggregate = db.execute(
                    """
                    SELECT COUNT(*) finished_matches,COALESCE(SUM(board_count),0) finished_boards,
                           COALESCE(SUM(game_count),0) finished_games,COALESCE(SUM(competition_points),0) club_points,
                           SUM(CASE WHEN result='win' THEN 1 ELSE 0 END) won_matches,
                           SUM(CASE WHEN result='draw' THEN 1 ELSE 0 END) drawn_matches,
                           SUM(CASE WHEN result='loss' THEN 1 ELSE 0 END) lost_matches
                    FROM match_summaries WHERE club_slug=?
                    """, (CLUB_SLUG,)
                ).fetchone()
                now = db_now()
                values = (CLUB_SLUG, int(aggregate["finished_matches"] or 0), int(aggregate["finished_boards"] or 0),
                          int(aggregate["finished_games"] or 0), int(aggregate["club_points"] or 0),
                          int(aggregate["won_matches"] or 0), int(aggregate["drawn_matches"] or 0),
                          int(aggregate["lost_matches"] or 0), now)
                db.execute("INSERT OR REPLACE INTO club_totals VALUES(?,?,?,?,?,?,?,?,?)", values)
                row = db.execute("SELECT * FROM club_totals WHERE club_slug=?", (CLUB_SLUG,)).fetchone()
        data = dict(row) if row else {}
        return {
            "club_points": float(data.get("club_points") or 0),
            "finished_matches": int(data.get("finished_matches") or 0),
            "finished_boards": int(data.get("finished_boards") or 0),
            "finished_games": int(data.get("finished_games") or 0),
            "finished_matches_available": True,
            "scored_finished_matches": int(data.get("finished_matches") or 0),
            "won_matches": int(data.get("won_matches") or 0),
            "drawn_matches": int(data.get("drawn_matches") or 0),
            "lost_matches": int(data.get("lost_matches") or 0),
            "scoring_rule": "win=5×boards; draw=2×boards; loss=0",
            "updated_from_database": True,
            "cache_mode": "immutable_match_summaries",
            "cache_updated_at": data.get("updated_at"),
        }

    def public_hall(self, rank_key: str = "", member_search: str = "") -> dict:
        rows = self.public_member_rows()
        ranks = []
        for definition in self.hall_rank_definitions():
            members = [row for row in rows if self.hall_rank_for_points(float(row["points"]))["key"] == definition["key"]]
            leader = members[0] if members else None
            ranks.append({**definition, "members": len(members), "top_member": leader["username"] if leader else None, "top_points": leader["points"] if leader else None})
        hall = {
            "total_members": len(rows),
            "total_points": round(sum(float(row["points"]) for row in rows), 1),
            "leader": rows[0] if rows else None,
            "ranks": ranks,
        }
        selected = None
        found = None
        if member_search:
            needle = username_key(member_search)
            found = next((row for row in rows if row["username_key"] == needle), None)
            if found is None:
                found = next((row for row in rows if needle in row["username_key"]), None)
            if found:
                selected = self.hall_rank_for_points(float(found["points"]))
                hall["search"] = {"query": member_search, "found": True, "member": None}
            else:
                hall["search"] = {"query": member_search, "found": False, "member": None}
        if selected is None and rank_key:
            selected = next((rank for rank in self.hall_rank_definitions() if rank["key"] == rank_key), None)
            if selected is None:
                raise ValueError("Unknown Hall of Fame rank.")
        if selected:
            category = [row for row in rows if self.hall_rank_for_points(float(row["points"]))["key"] == selected["key"]]
            members = []
            for category_position, row in enumerate(category, 1):
                team_position = next((i for i, candidate in enumerate(rows, 1) if candidate["username_key"] == row["username_key"]), None)
                members.append({k: row[k] for k in ("username","points","matches","games","wins","draws","losses")} | {"team_position": team_position, "category_position": category_position, "rank": selected})
            hall["selected_rank"] = {**selected, "members": len(members)}
            hall["members"] = members
            if found:
                member = next(item for item in members if username_key(item["username"]) == found["username_key"])
                hall["search"]["member"] = member
        return hall

    def run_batch(self, job_id: str | None, trigger: str, max_items: int = 3, max_seconds: float = 3.0) -> dict:
        with self.lock:
            job = self.job(job_id) if job_id else self.active_job()
            if not job or job.get("status") in {"completed", "cancelled"}:
                job = self.create_job()
            job_id = str(job["id"])
            started = time.monotonic()
            processed = 0
            with self.connect() as db:
                cursor = db.execute(
                    "INSERT INTO worker_runs(job_id,trigger_type,started_at,result_status) VALUES(?,?,?,'running')",
                    (job_id, trigger, db_now()),
                )
                run_id = int(cursor.lastrowid)
                db.execute(
                    "UPDATE jobs SET status='running',started_at=COALESCE(started_at,?),updated_at=? WHERE id=? AND status IN ('new','running')",
                    (db_now(), db_now(), job_id),
                )
            self.log(job_id, run_id, "info", "worker_started", trigger, "Local worker invocation started.", {
                "trigger": trigger, "max_items": max_items, "max_seconds": max_seconds,
            })

            result_status = "partial"
            message = "No queue item was ready."
            try:
                while processed < max_items and time.monotonic() - started < max_seconds:
                    with self.connect() as db:
                        job_row = db.execute("SELECT * FROM jobs WHERE id=?", (job_id,)).fetchone()
                        if not job_row:
                            raise RuntimeError("The selected local job no longer exists.")
                        if int(job_row["stop_requested"]):
                            db.execute(
                                "UPDATE jobs SET status='paused',stop_requested=0,updated_at=? WHERE id=?",
                                (db_now(), job_id),
                            )
                            result_status = "paused"
                            message = "Safe stop acknowledged after the last committed item."
                            self.log(job_id, run_id, "info", "job_paused", None, message)
                            break
                        item = db.execute(
                            """
                            SELECT * FROM queue_items
                            WHERE job_id=? AND status IN ('pending','retry') AND available_at<=?
                            ORDER BY CASE WHEN item_key LIKE 'priority-discovery:%' THEN 0 WHEN status='retry' THEN 1 ELSE 2 END,id LIMIT 1
                            """,
                            (job_id, db_now()),
                        ).fetchone()
                        if not item:
                            counts = self._queue_counts(db, job_id)
                            if counts["pending"] == 0 and counts["retry"] == 0 and counts["running"] == 0:
                                db.execute(
                                    "UPDATE jobs SET status='completed',finished_at=?,updated_at=? WHERE id=?",
                                    (db_now(), db_now(), job_id),
                                )
                                result_status = "completed"
                                message = "Local simulation update completed."
                                self.log(job_id, run_id, "success", "job_completed", None, message, counts)
                            else:
                                result_status = "waiting"
                                message = "No queue item is currently available."
                                next_retry = db.execute(
                                    "SELECT MIN(available_at) FROM queue_items WHERE job_id=? AND status='retry'",
                                    (job_id,),
                                ).fetchone()[0]
                                self.log(job_id, run_id, "info", "worker_waiting", None, message, {
                                    "queue": counts, "next_retry_at": next_retry,
                                })
                            break
                        item = dict(item)
                        db.execute(
                            "UPDATE queue_items SET status='running',attempts=attempts+1,locked_at=?,updated_at=? WHERE id=?",
                            (db_now(), db_now(), item["id"]),
                        )
                        item["attempts"] = int(item["attempts"]) + 1
                    self.log(job_id, run_id, "info", "task_started", str(item["item_key"]),
                             f"Starting {item['item_type']}.", {"attempt": item["attempts"]})
                    details = self._process_month(job_id, item)
                    self.log(job_id, run_id, "success", str(item["item_type"]), str(item["item_key"]),
                             f"Generated deterministic data for UTC month {details['month']}.", details)
                    processed += 1
                    message = f"Processed {processed} local queue item(s) in this server invocation."
                else:
                    result_status = "partial"

                with self.connect() as db:
                    counts = self._queue_counts(db, job_id)
                    if counts["pending"] == 0 and counts["retry"] == 0 and counts["running"] == 0:
                        db.execute(
                            "UPDATE jobs SET status='completed',finished_at=?,updated_at=? WHERE id=?",
                            (db_now(), db_now(), job_id),
                        )
                        result_status = "completed"
                        message = "Local simulation update completed."
            except Exception as exc:
                result_status = "failed"
                message = str(exc)
                with self.connect() as db:
                    db.execute(
                        "UPDATE jobs SET status='failed',last_error=?,finished_at=?,updated_at=? WHERE id=?",
                        (message, db_now(), db_now(), job_id),
                    )
                self.log(job_id, run_id, "error", "worker_failed", None, message, {"exception": type(exc).__name__})
                raise
            finally:
                elapsed_ms = int((time.monotonic() - started) * 1000)
                self.log(job_id, run_id, "info", "worker_finished", trigger, "Local worker invocation finished.", {
                    "status": result_status, "processed_items": processed, "elapsed_ms": elapsed_ms,
                })
                with self.connect() as db:
                    db.execute(
                        """
                        UPDATE worker_runs SET finished_at=?,processed_items=?,result_status=?,message=? WHERE id=?
                        """,
                        (db_now(), processed, result_status, message, run_id),
                    )
            with self.connect() as db:
                next_retry = db.execute(
                    "SELECT MIN(available_at) FROM queue_items WHERE job_id=? AND status='retry'",
                    (job_id,),
                ).fetchone()[0]
                queue = self._queue_counts(db, job_id)
            wait_seconds = 0
            if result_status == "waiting" and next_retry:
                try:
                    wait_seconds = max(1, int((datetime.strptime(next_retry, "%Y-%m-%d %H:%M:%S").replace(tzinfo=UTC) - utc_now()).total_seconds()))
                except ValueError:
                    wait_seconds = 2
            return {
                "ok": True,
                "status": result_status,
                "processed_items": processed,
                "elapsed_ms": int((time.monotonic() - started) * 1000),
                "message": message,
                "job": self.job(job_id),
                "queue": queue,
                "next_retry_at": next_retry,
                "wait_seconds": wait_seconds,
                "environment": "local-simulation",
            }

    def _process_month(self, job_id: str, item: dict) -> dict:
        payload = json.loads(item["payload_json"])
        month = str(payload["month"])
        year, month_number = map(int, month.split("-"))
        month_start = datetime(year, month_number, 1, tzinfo=UTC)
        if month_number == 12:
            next_month = datetime(year + 1, 1, 1, tzinfo=UTC)
        else:
            next_month = datetime(year, month_number + 1, 1, tzinfo=UTC)
        days = (next_month - month_start).days
        now = db_now()
        events = 0
        matches: set[int] = set()
        with self.connect() as db:
            db.execute("BEGIN IMMEDIATE")
            try:
                for member_index, member in enumerate(MEMBERS):
                    seed = int(hashlib.sha256(f"{member}|{month}".encode()).hexdigest()[:8], 16)
                    match_count = 1 + seed % 4
                    for match_offset in range(match_count):
                        match_id = year * 100000 + month_number * 1000 + member_index * 20 + match_offset + 1
                        matches.add(match_id)
                        board_url = f"https://api.chess.com/pub/match/{match_id}/{member_index + 1}"
                        for game_index in range(2):
                            selector = (seed + match_offset * 5 + game_index * 7) % 10
                            if selector < 4:
                                result = "win"; points = 1.0
                            elif selector < 7:
                                result = DRAW_RESULTS[selector % len(DRAW_RESULTS)]; points = 0.5
                            else:
                                result = LOSS_RESULTS[selector % len(LOSS_RESULTS)]; points = 0.0
                            day = 1 + ((seed // 11 + match_offset * 4 + game_index * 9) % days)
                            hour = (seed + match_offset * 3 + game_index * 13) % 24
                            end = month_start.replace(day=day, hour=hour, minute=(member_index * 7) % 60)
                            game_url = f"https://www.chess.com/game/daily/{match_id}{member_index:02d}{game_index}"
                            db.execute(
                                """
                                INSERT INTO point_events(
                                  club_slug,username_key,username,match_id,board_url,game_url,
                                  game_end_utc,utc_month,result_code,points,verified_at
                                ) VALUES(?,?,?,?,?,?,?,?,?,?,?)
                                ON CONFLICT(club_slug,username_key,game_url) DO UPDATE SET
                                  username=excluded.username,verified_at=excluded.verified_at
                                """,
                                (
                                    CLUB_SLUG, username_key(member), member, match_id, board_url, game_url,
                                    end.strftime("%Y-%m-%d %H:%M:%S"), f"{month}-01", result, points, now,
                                ),
                            )
                            events += 1
                        db.execute(
                            """
                            INSERT INTO board_states(
                              club_slug,username_key,username,match_id,board_url,source_bucket,state,
                              finished_game_count,first_discovered_at,last_discovered_at,last_checked_at,
                              next_check_at,completed_at,failure_count,last_error
                            ) VALUES(?,?,?,?,?,'finished','complete_immutable',2,?,?,?,NULL,?,0,NULL)
                            ON CONFLICT(club_slug,username_key,board_url) DO UPDATE SET
                              username=excluded.username,match_id=excluded.match_id,source_bucket='finished',
                              state='complete_immutable',finished_game_count=2,last_checked_at=excluded.last_checked_at,
                              next_check_at=NULL,completed_at=excluded.completed_at,last_error=NULL
                            """,
                            (
                                CLUB_SLUG, username_key(member), member, match_id, board_url,
                                now, now, now, now,
                            ),
                        )
                for match_id in matches:
                    summary = db.execute(
                        """
                        SELECT COUNT(DISTINCT board_url) board_count,COUNT(*) game_count,COALESCE(SUM(points),0) team_score
                        FROM point_events WHERE club_slug=? AND match_id=?
                        """, (CLUB_SLUG, match_id)
                    ).fetchone()
                    boards = int(summary["board_count"] or 0)
                    games = int(summary["game_count"] or 0)
                    score = float(summary["team_score"] or 0)
                    if boards <= 0 or games < boards * 2:
                        continue
                    result = "win" if score > boards else "draw" if abs(score - boards) < 0.001 else "loss"
                    competition_points = 5 * boards if result == "win" else 2 * boards if result == "draw" else 0
                    inserted = db.execute(
                        """
                        INSERT OR IGNORE INTO match_summaries(
                          club_slug,match_id,board_count,game_count,team_score,result,competition_points,finalized_at,updated_at
                        ) VALUES(?,?,?,?,?,?,?,?,?)
                        """,
                        (CLUB_SLUG, match_id, boards, games, score, result, competition_points, now, now),
                    ).rowcount
                    if inserted:
                        db.execute(
                            """
                            INSERT INTO club_totals(
                              club_slug,finished_matches,finished_boards,finished_games,club_points,won_matches,drawn_matches,lost_matches,updated_at
                            ) VALUES(?,?,?,?,?,?,?,?,?)
                            ON CONFLICT(club_slug) DO UPDATE SET
                              finished_matches=finished_matches+excluded.finished_matches,
                              finished_boards=finished_boards+excluded.finished_boards,
                              finished_games=finished_games+excluded.finished_games,
                              club_points=club_points+excluded.club_points,
                              won_matches=won_matches+excluded.won_matches,
                              drawn_matches=drawn_matches+excluded.drawn_matches,
                              lost_matches=lost_matches+excluded.lost_matches,
                              updated_at=excluded.updated_at
                            """,
                            (CLUB_SLUG, 1, boards, games, competition_points, int(result=="win"), int(result=="draw"), int(result=="loss"), now),
                        )
                db.execute(
                    "UPDATE queue_items SET status='done',locked_at=NULL,updated_at=?,last_error=NULL WHERE id=?",
                    (now, item["id"]),
                )
                db.execute(
                    "UPDATE jobs SET processed_items=processed_items+1,updated_at=? WHERE id=?",
                    (now, job_id),
                )
                db.execute("COMMIT")
            except Exception as exc:
                db.execute("ROLLBACK")
                with self.connect() as retry_db:
                    retry_db.execute(
                        """
                        UPDATE queue_items SET status='retry',locked_at=NULL,available_at=?,updated_at=?,last_error=? WHERE id=?
                        """,
                        ((utc_now() + timedelta(seconds=5)).strftime("%Y-%m-%d %H:%M:%S"), now, str(exc), item["id"]),
                    )
                raise
        return {"month": month, "members": len(MEMBERS), "matches": len(matches), "point_events_upserted": events}

    def results(self, start_month: str, end_month: str, current_only: bool, shape: str,
                member_search: str = "", sort_by: str = "", sort_dir: str = "", limit: int = 500) -> dict:
        self._validate_months(start_month, end_month)
        if shape == "events":
            return self.event_results(start_month, end_month, current_only, member_search, sort_by, sort_dir, limit)
        if shape not in {"totals", "monthly"}:
            raise ValueError("shape must be totals, monthly or events.")
        from_month = f"{start_month}-01"
        end_dt = datetime.strptime(end_month + "-01", "%Y-%m-%d").replace(tzinfo=UTC)
        until = end_dt.replace(year=end_dt.year + 1, month=1) if end_dt.month == 12 else end_dt.replace(month=end_dt.month + 1)
        until_month = until.strftime("%Y-%m-01")
        current_clause = "AND m.current_member=1" if current_only else ""
        search_clause = "AND lower(m.username) LIKE ?" if member_search else ""
        allowed = {"month", "username", "points", "matches", "games", "wins", "draws", "losses"}
        default_sort = "month" if shape == "monthly" else "points"
        default_dir = "asc" if shape == "monthly" else "desc"
        sort_by, sort_dir = self._validated_sort(sort_by, sort_dir, allowed, default_sort, default_dir)
        sort_map = {
            "month": "e.utc_month", "username": "m.username", "points": "points", "matches": "matches",
            "games": "games", "wins": "wins", "draws": "draws", "losses": "losses",
        }
        params: list[object]
        with self.connect() as db:
            if shape == "monthly":
                sql = f"""
                    SELECT substr(e.utc_month,1,7) month,m.username,
                           ROUND(SUM(e.points),1) points,COUNT(e.game_url) games,
                           SUM(CASE WHEN e.points=1.0 THEN 1 ELSE 0 END) wins,
                           SUM(CASE WHEN e.points=0.5 THEN 1 ELSE 0 END) draws,
                           SUM(CASE WHEN e.points=0.0 THEN 1 ELSE 0 END) losses,
                           COUNT(DISTINCT e.match_id) matches
                    FROM members m JOIN point_events e
                      ON e.club_slug=m.club_slug AND e.username_key=m.username_key
                    WHERE e.club_slug=? AND e.utc_month>=? AND e.utc_month<? {current_clause} {search_clause}
                    GROUP BY e.utc_month,m.username_key,m.username
                    ORDER BY {sort_map[sort_by]} {sort_dir.upper()},m.username_key,e.utc_month
                    LIMIT ?
                """
                params = [CLUB_SLUG, from_month, until_month]
            else:
                sql = f"""
                    SELECT m.username,ROUND(COALESCE(SUM(e.points),0),1) points,
                           COUNT(e.game_url) games,
                           SUM(CASE WHEN e.points=1.0 THEN 1 ELSE 0 END) wins,
                           SUM(CASE WHEN e.points=0.5 THEN 1 ELSE 0 END) draws,
                           SUM(CASE WHEN e.points=0.0 THEN 1 ELSE 0 END) losses,
                           COUNT(DISTINCT e.match_id) matches
                    FROM members m LEFT JOIN point_events e
                      ON e.club_slug=m.club_slug AND e.username_key=m.username_key
                     AND e.utc_month>=? AND e.utc_month<?
                    WHERE m.club_slug=? {current_clause} {search_clause}
                    GROUP BY m.username_key,m.username
                    ORDER BY {sort_map[sort_by]} {sort_dir.upper()},m.username_key
                    LIMIT ?
                """
                params = [from_month, until_month, CLUB_SLUG]
            if member_search:
                params.append(f"%{member_search.casefold()}%")
            params.append(max(1, min(5000, int(limit))) + 1)
            rows = [dict(row) for row in db.execute(sql, params).fetchall()]
        truncated = len(rows) > limit
        if truncated:
            rows = rows[:limit]
        return {
            "ok": True,
            "range": {"start_month": start_month, "end_month": end_month},
            "shape": shape,
            "current_members_only": current_only,
            "member_search": member_search,
            "sort": {"column": sort_by, "direction": sort_dir},
            "truncated": truncated,
            "rows": rows,
            "environment": "local-simulation",
        }

    def event_results(self, start_month: str, end_month: str, current_only: bool,
                      member_search: str = "", sort_by: str = "", sort_dir: str = "", limit: int = 500) -> dict:
        rows, truncated, sort_by, sort_dir = self._event_rows_internal(
            start_month, end_month, current_only, member_search, sort_by, sort_dir, limit,
        )
        return {
            "ok": True,
            "range": {"start_month": start_month, "end_month": end_month},
            "shape": "events",
            "current_members_only": current_only,
            "member_search": member_search,
            "sort": {"column": sort_by, "direction": sort_dir},
            "truncated": truncated,
            "rows": rows,
            "environment": "local-simulation",
        }

    def event_rows(self, start_month: str, end_month: str, current_only: bool,
                   member_search: str = "", sort_by: str = "", sort_dir: str = "") -> list[dict]:
        rows, _, _, _ = self._event_rows_internal(
            start_month, end_month, current_only, member_search, sort_by, sort_dir, None,
        )
        return rows

    def _event_rows_internal(self, start_month: str, end_month: str, current_only: bool,
                             member_search: str, sort_by: str, sort_dir: str,
                             limit: int | None) -> tuple[list[dict], bool, str, str]:
        self._validate_months(start_month, end_month)
        end_dt = datetime.strptime(end_month + "-01", "%Y-%m-%d").replace(tzinfo=UTC)
        until = end_dt.replace(year=end_dt.year + 1, month=1) if end_dt.month == 12 else end_dt.replace(month=end_dt.month + 1)
        current_clause = "AND m.current_member=1" if current_only else ""
        search_clause = "AND lower(m.username) LIKE ?" if member_search else ""
        allowed = {"username", "match_id", "game_end_utc", "month", "result_code", "points", "current_member"}
        sort_by, sort_dir = self._validated_sort(sort_by, sort_dir, allowed, "game_end_utc", "asc")
        sort_map = {
            "username": "e.username", "match_id": "e.match_id", "game_end_utc": "e.game_end_utc",
            "month": "e.utc_month", "result_code": "e.result_code", "points": "e.points",
            "current_member": "m.current_member",
        }
        limit_sql = "" if limit is None else " LIMIT ?"
        params: list[object] = [CLUB_SLUG, start_month + "-01", until.strftime("%Y-%m-01")]
        if member_search:
            params.append(f"%{member_search.casefold()}%")
        if limit is not None:
            params.append(max(1, min(5000, int(limit))) + 1)
        with self.connect() as db:
            rows = [dict(row) for row in db.execute(
                f"""
                SELECT e.username,e.match_id,e.board_url,e.game_url,e.game_end_utc,
                       substr(e.utc_month,1,7) month,e.result_code,e.points,m.current_member
                FROM point_events e JOIN members m
                  ON m.club_slug=e.club_slug AND m.username_key=e.username_key
                WHERE e.club_slug=? AND e.utc_month>=? AND e.utc_month<? {current_clause} {search_clause}
                ORDER BY {sort_map[sort_by]} {sort_dir.upper()},e.username_key,e.game_url{limit_sql}
                """,
                params,
            ).fetchall()]
        truncated = limit is not None and len(rows) > limit
        if truncated:
            rows = rows[:limit]
        return rows, truncated, sort_by, sort_dir

    @staticmethod
    def _validated_sort(sort_by: str, sort_dir: str, allowed: set[str], default_column: str,
                        default_direction: str) -> tuple[str, str]:
        column = sort_by if sort_by in allowed else default_column
        direction = sort_dir.casefold() if sort_dir.casefold() in {"asc", "desc"} else default_direction
        return column, direction

    @staticmethod
    def _validate_months(start_month: str, end_month: str) -> None:
        try:
            start = datetime.strptime(start_month, "%Y-%m")
            end = datetime.strptime(end_month, "%Y-%m")
        except ValueError as exc:
            raise ValueError("Invalid month range.") from exc
        if start > end:
            raise ValueError("Invalid month range.")
        if (end.year - start.year) * 12 + end.month - start.month > 240:
            raise ValueError("The selected range cannot exceed 20 years.")


class Handler(SimpleHTTPRequestHandler):
    server_version = "PromoteToKingTeamPointsLocal/2.8.5"

    @property
    def store(self) -> LocalStore:
        return self.server.store  # type: ignore[attr-defined]

    @property
    def admin_token(self) -> str:
        return self.server.admin_token  # type: ignore[attr-defined]

    @property
    def cron_token(self) -> str:
        return self.server.cron_token  # type: ignore[attr-defined]

    @property
    def baseline_port(self) -> int:
        return int(self.server.baseline_port)  # type: ignore[attr-defined]

    def _proxy_baseline(self, method: str) -> None:
        length = int(self.headers.get("Content-Length", "0") or 0)
        body = self.rfile.read(length) if length > 0 else None
        forwarded = {
            key: value
            for key, value in self.headers.items()
            if key.lower() not in {"host", "connection", "content-length", "accept-encoding"}
        }
        connection = HTTPConnection("127.0.0.1", self.baseline_port, timeout=60)
        try:
            connection.request(method, self.path, body=body, headers=forwarded)
            response = connection.getresponse()
            payload = response.read()
            self.send_response(response.status, response.reason)
            for key, value in response.getheaders():
                if key.lower() in {"connection", "transfer-encoding", "server", "date", "content-length"}:
                    continue
                self.send_header(key, value)
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            if method != "HEAD":
                self.wfile.write(payload)
        finally:
            connection.close()

    def end_headers(self) -> None:
        self.send_header("X-Content-Type-Options", "nosniff")
        super().end_headers()

    def do_OPTIONS(self) -> None:  # noqa: N802
        path = urlsplit(self.path).path
        if path in {API_PATH, SESSION_PATH}:
            self.send_response(HTTPStatus.NO_CONTENT)
            self.send_header("Access-Control-Allow-Headers", "Content-Type, X-P2K-Admin-Token, X-P2K-CSRF")
            self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
            self.end_headers()
            return
        if path.startswith("/api/"):
            self._proxy_baseline("OPTIONS")
            return
        self.send_error(HTTPStatus.NOT_FOUND)

    def do_GET(self) -> None:  # noqa: N802
        parsed = urlsplit(self.path)
        if parsed.path == API_PATH:
            self._handle_api("GET", parsed)
            return
        if parsed.path == PUBLIC_PATH:
            self._handle_public(parsed)
            return
        if parsed.path == CRON_PATH:
            self._handle_cron(parsed)
            return
        if parsed.path.startswith("/api/"):
            self._proxy_baseline("GET")
            return
        super().do_GET()

    def do_HEAD(self) -> None:  # noqa: N802
        parsed = urlsplit(self.path)
        if parsed.path.startswith("/api/"):
            self._proxy_baseline("HEAD")
            return
        super().do_HEAD()

    def do_POST(self) -> None:  # noqa: N802
        parsed = urlsplit(self.path)
        if parsed.path == API_PATH:
            self._handle_api("POST", parsed)
            return
        if parsed.path == SESSION_PATH:
            self._handle_session()
            return
        if parsed.path.startswith("/api/"):
            self._proxy_baseline("POST")
            return
        self.send_error(HTTPStatus.NOT_FOUND)

    def do_PUT(self) -> None:  # noqa: N802
        if urlsplit(self.path).path.startswith("/api/"):
            self._proxy_baseline("PUT")
            return
        self.send_error(HTTPStatus.NOT_FOUND)

    def do_DELETE(self) -> None:  # noqa: N802
        if urlsplit(self.path).path.startswith("/api/"):
            self._proxy_baseline("DELETE")
            return
        self.send_error(HTTPStatus.NOT_FOUND)

    def _session(self) -> dict | None:
        cookie = SimpleCookie(self.headers.get("Cookie", ""))
        sid = cookie.get("P2KTPSESSID")
        if not sid:
            return None
        with self.server.session_lock:  # type: ignore[attr-defined]
            session = self.server.sessions.get(sid.value)  # type: ignore[attr-defined]
            if not session or float(session.get("expires", 0)) <= time.time():
                self.server.sessions.pop(sid.value, None)  # type: ignore[attr-defined]
                return None
            session["expires"] = time.time() + 1800
            return dict(session)

    def _admin_authorized(self, method: str) -> bool:
        supplied = self.headers.get("X-P2K-Admin-Token", "")
        if supplied and hashlib.sha256(supplied.encode()).digest() == hashlib.sha256(self.admin_token.encode()).digest():
            return True
        session = self._session()
        if not session:
            return False
        if method.upper() not in {"GET", "HEAD", "OPTIONS"}:
            supplied_csrf = self.headers.get("X-P2K-CSRF", "")
            expected_csrf = str(session.get("csrf") or "")
            return bool(supplied_csrf and expected_csrf and hashlib.sha256(supplied_csrf.encode()).digest() == hashlib.sha256(expected_csrf.encode()).digest())
        return True

    def _handle_session(self) -> None:
        try:
            body = self._json_body()
            username = str(body.get("username") or "").strip().casefold()
            if username not in {"ximoon", "promoter"}:
                self._json(HTTPStatus.FORBIDDEN, {"ok": False, "error": {"code": "ADMIN_AUTH_FAILED", "message": "Use Ximoon or Promoter for the local administrator simulation."}})
                return
            sid = uuid.uuid4().hex
            csrf = uuid.uuid4().hex + uuid.uuid4().hex
            with self.server.session_lock:  # type: ignore[attr-defined]
                self.server.sessions[sid] = {"username": username, "csrf": csrf, "expires": time.time() + 1800}  # type: ignore[attr-defined]
            body_bytes = json_bytes({"ok": True, "username": username, "csrf": csrf, "expires_in": 1800, "authentication": "local_secure_same_origin_session", "schema_version": 5})
            self.send_response(HTTPStatus.OK)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Cache-Control", "no-store")
            self.send_header("Set-Cookie", f"P2KTPSESSID={sid}; Path=/; HttpOnly; SameSite=Strict")
            self.send_header("Content-Length", str(len(body_bytes)))
            self.end_headers()
            self.wfile.write(body_bytes)
        except Exception as exc:
            self._json(HTTPStatus.BAD_REQUEST, {"ok": False, "error": {"code": "BAD_REQUEST", "message": str(exc)}})

    def _handle_public(self, parsed) -> None:
        query = parse_qs(parsed.query, keep_blank_values=True)
        action = (query.get("action", ["team"])[0] or "team").lower()
        if action == "player":
            username = str(query.get("username", [""])[0]).strip()
            if not username:
                self._json(HTTPStatus.BAD_REQUEST, {"ok": False, "error": {"code": "USERNAME_REQUIRED", "message": "username is required."}})
                return
            self._json(HTTPStatus.OK, {"ok": True, "club_slug": CLUB_SLUG, "player": self.store.public_player(username)})
            return
        if action == "team":
            self._json(HTTPStatus.OK, {"ok": True, "club_slug": CLUB_SLUG, "team": self.store.public_team()})
            return
        if action == "hall":
            rank = str(query.get("rank", [""])[0]).strip().casefold()
            member = str(query.get("member", [""])[0]).strip()
            if len(rank) > 80 or len(member) > 80:
                self._json(HTTPStatus.BAD_REQUEST, {"ok": False, "error": {"code": "INVALID_HALL_FILTER", "message": "Hall of Fame filters are too long."}})
                return
            try:
                hall = self.store.public_hall(rank, member)
            except ValueError as exc:
                self._json(HTTPStatus.BAD_REQUEST, {"ok": False, "error": {"code": "UNKNOWN_HALL_RANK", "message": str(exc)}})
                return
            self._json(HTTPStatus.OK, {"ok": True, "club_slug": CLUB_SLUG, "hall": hall})
            return
        self._json(HTTPStatus.NOT_FOUND, {"ok": False, "error": {"code": "UNKNOWN_ACTION", "message": "Unknown public Team Points action."}})

    def _handle_api(self, method: str, parsed) -> None:
        if not self._admin_authorized(method):
            self._json(HTTPStatus.UNAUTHORIZED, {"ok": False, "error": {"code": "ADMIN_SESSION_REQUIRED", "message": "A secured local administrator session or legacy token is required."}})
            return
        query = parse_qs(parsed.query, keep_blank_values=True)
        action = (query.get("action", ["status"])[0] or "status").lower()
        try:
            body = self._json_body() if method == "POST" else {}
            if action == "status" and method == "GET":
                self._json(HTTPStatus.OK, self.store.summary())
            elif action == "start" and method == "POST":
                job = self.store.create_job()
                self._json(HTTPStatus.CREATED, {"ok": True, "job": job, "message": "Local update queue ready. Use Run one segment now or the simulated scheduled endpoint to continue it."})
            elif action == "stop" and method == "POST":
                job_id = str(body.get("job_id") or "")
                if not job_id:
                    raise ValueError("job_id is required.")
                self._json(HTTPStatus.OK, {"ok": True, "job": self.store.pause(job_id), "message": "Safe stop requested."})
            elif action == "resume" and method == "POST":
                job_id = str(body.get("job_id") or "")
                if not job_id:
                    raise ValueError("job_id is required.")
                self._json(HTTPStatus.OK, {"ok": True, "job": self.store.resume(job_id), "message": "Job resumed."})
            elif action == "prioritize_discovery" and method == "POST":
                result = self.store.queue_priority_discovery()
                self._json(HTTPStatus.CREATED, {"ok": True, "message": "Fresh team-member and member-match discovery was placed at the front of the queue for the next available work slot.", **result})
            elif action == "run" and method == "POST":
                job_id = str(body.get("job_id") or "") or None
                self._json(HTTPStatus.OK, self.store.run_batch(job_id, "manual"))
            elif action == "results" and method == "GET":
                shape = query.get("shape", ["totals"])[0]
                if shape not in {"totals", "monthly", "events"}:
                    raise ValueError("shape must be totals, monthly or events.")
                self._json(
                    HTTPStatus.OK,
                    self.store.results(
                        query.get("start_month", [""])[0],
                        query.get("end_month", [""])[0],
                        query.get("current_only", ["1"])[0] != "0",
                        shape,
                        query.get("member", [""])[0],
                        query.get("sort_by", [""])[0],
                        query.get("sort_dir", [""])[0],
                        max(1, min(1000, int(query.get("limit", ["500"])[0] or 500))),
                    ),
                )
            elif action == "export" and method == "GET":
                shape = query.get("shape", ["totals"])[0]
                if shape not in {"totals", "monthly", "events"}:
                    raise ValueError("shape must be totals, monthly or events.")
                start = query.get("start_month", [""])[0]
                end = query.get("end_month", [""])[0]
                current_only = query.get("current_only", ["1"])[0] != "0"
                member = query.get("member", [""])[0]
                sort_by = query.get("sort_by", [""])[0]
                sort_dir = query.get("sort_dir", [""])[0]
                if shape == "events":
                    rows = self.store.event_rows(start, end, current_only, member, sort_by, sort_dir)
                    headers = ["username", "match_id", "board_url", "game_url", "game_end_utc", "month", "result_code", "points", "current_member"]
                else:
                    rows = self.store.results(start, end, current_only, shape, member, sort_by, sort_dir, 5000)["rows"]
                    headers = ["month", "username", "points", "matches", "games", "wins", "draws", "losses"] if shape == "monthly" else ["username", "points", "matches", "games", "wins", "draws", "losses"]
                suffix = "" if not member else "-member-" + "".join(ch if ch.isalnum() or ch in "_-" else "-" for ch in member)
                self._csv(rows, headers, f"p2k-team-points-{shape}-{start}-to-{end}{suffix}.csv")
            else:
                self._json(HTTPStatus.NOT_FOUND, {"ok": False, "error": {"code": "UNKNOWN_ACTION", "message": "Unknown action or HTTP method."}})
        except ValueError as exc:
            self._json(HTTPStatus.BAD_REQUEST, {"ok": False, "error": {"code": "BAD_REQUEST", "message": str(exc)}})
        except Exception as exc:
            self.log_error("Local Team Points error: %s", exc)
            self._json(HTTPStatus.INTERNAL_SERVER_ERROR, {"ok": False, "error": {"code": "SERVER_ERROR", "message": str(exc)}})

    def _handle_cron(self, parsed) -> None:
        token = parse_qs(parsed.query, keep_blank_values=True).get("token", [""])[0]
        if token != self.cron_token:
            self._json(HTTPStatus.FORBIDDEN, {"ok": False, "error": {"code": "INVALID_CRON_TOKEN", "message": "Invalid local cron token."}})
            return
        try:
            self._json(HTTPStatus.OK, self.store.run_batch(None, "cron"))
        except Exception as exc:
            self._json(HTTPStatus.INTERNAL_SERVER_ERROR, {"ok": False, "error": {"code": "SERVER_ERROR", "message": str(exc)}})

    def _json_body(self) -> dict:
        length = int(self.headers.get("Content-Length", "0") or 0)
        if length <= 0:
            return {}
        if length > 1024 * 1024:
            raise ValueError("Request body is too large.")
        payload = json.loads(self.rfile.read(length).decode("utf-8"))
        if not isinstance(payload, dict):
            raise ValueError("JSON body must be an object.")
        return payload

    def _json(self, status: HTTPStatus, payload: object) -> None:
        body = json_bytes(payload)
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def _csv(self, rows: list[dict], headers: list[str], filename: str) -> None:
        buffer = io.StringIO(newline="")
        writer = csv.writer(buffer, lineterminator="\r\n")
        writer.writerow(headers)
        for row in rows:
            writer.writerow([row.get(header, "") for header in headers])
        body = ("\ufeff" + buffer.getvalue()).encode("utf-8")
        self.send_response(HTTPStatus.OK)
        self.send_header("Content-Type", "text/csv; charset=utf-8")
        self.send_header("Content-Disposition", f'attachment; filename="{filename}"')
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)


def create_server(root: Path, host: str, port: int, database: Path, admin_token: str, cron_token: str) -> ThreadingHTTPServer:
    root = root.resolve()
    if not (root / "index.html").is_file():
        raise FileNotFoundError(f"No index.html found in {root}")
    baseline = baseline_local.create_server(root, "127.0.0.1", 0)
    baseline_thread = threading.Thread(target=baseline.serve_forever, name="p2k-baseline-local-api", daemon=True)
    baseline_thread.start()
    handler = lambda *args, **kwargs: Handler(*args, directory=str(root), **kwargs)
    server = ThreadingHTTPServer((host, port), handler)
    server.daemon_threads = True
    server.store = LocalStore(database.resolve())  # type: ignore[attr-defined]
    server.admin_token = admin_token  # type: ignore[attr-defined]
    server.cron_token = cron_token  # type: ignore[attr-defined]
    server.sessions = {}  # type: ignore[attr-defined]
    server.session_lock = threading.RLock()  # type: ignore[attr-defined]
    server.baseline_server = baseline  # type: ignore[attr-defined]
    server.baseline_port = int(baseline.server_address[1])  # type: ignore[attr-defined]
    return server


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("directory", nargs="?", default=".", help="Merged PromoteToKing site directory")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8000)
    parser.add_argument("--database", default="data/team-points-local.sqlite3")
    parser.add_argument("--admin-token", default=LOCAL_ADMIN_TOKEN)
    parser.add_argument("--cron-token", default=LOCAL_CRON_TOKEN)
    parser.add_argument("--reset", action="store_true", help="Delete the local SQLite simulation database before starting")
    args = parser.parse_args()

    root = Path(args.directory).expanduser().resolve()
    database = Path(args.database).expanduser()
    if not database.is_absolute():
        database = root / database
    if args.reset and database.exists():
        database.unlink()
    server = create_server(root, args.host, args.port, database, args.admin_token, args.cron_token)
    actual_host, actual_port = server.server_address[:2]
    display_host = "127.0.0.1" if actual_host in {"0.0.0.0", "::"} else actual_host
    print(f"Serving {root} at http://{display_host}:{actual_port}/?ui=v2")
    print("Log in as Ximoon or Promoter in the local simulated authentication, then open the Admin view and Team Points.")
    print("The browser establishes the Team Points administrator session automatically; no database path or access code is requested in the UI.")
    print(f"Local simulation database: {database}")
    print(f"Optional CRON test URL: http://{display_host}:{actual_port}{CRON_PATH}?token={args.cron_token}")
    print("Team Points simulation is offline and deterministic; the original website local APIs remain available through the same server.")
    print("Use Start/update all data, then Run one segment now or call the simulated scheduled endpoint.")
    print("Press Ctrl+C to stop.")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nStopped.")
    finally:
        baseline = getattr(server, "baseline_server", None)
        if baseline is not None:
            baseline.shutdown()
            baseline.server_close()
        server.server_close()


if __name__ == "__main__":
    main()
