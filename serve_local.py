#!/usr/bin/env python3
"""Serve the club tools locally with storage, logs and match tracking APIs."""
from __future__ import annotations

import argparse
import hashlib
import hmac
import ipaddress
import json
import os
import re
import secrets
import shutil
import tempfile
import threading
import time
from datetime import date, datetime, timedelta, timezone
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlsplit
from urllib.request import Request, urlopen

CHALLENGE_API = "/api/challenge-club-list"
MATCH_LOG_API = "/api/match-assistant-log"
MATCH_LOGS_API = "/api/match-assistant-logs"
TASK_LOG_API = "/api/scheduled-task-log"
TASK_LOGS_API = "/api/scheduled-task-logs"
TRACKING_REFS_API = "/api/league-match-references"
RECORD_MATCH_API = "/api/record-league-match"
TRACK_API = "/api/track-upcoming-league-matches"
HISTORY_API = "/api/match-history"
TRACKED_API = "/api/tracked-match-data"
DIAGNOSTICS_API = "/api/diagnostics"

DEFAULT_CLUB_SLUG = "promote-to-king"
SUPPORTED_LEAGUES = ("1WL", "TCMAC", "KOTML", "TMCL", "WKCL", "PCL", "CW")
MAX_BODY = 2 * 1024 * 1024
MAX_LOG_BODY = 16 * 1024
MAX_DAYS = 366
MAX_ENTRIES = 2000
MAX_SNAPSHOTS = 2000
MAX_CLUBS = 10000
SLUG_RE = re.compile(r"^[a-z0-9-]+$")
LOG_RE = re.compile(r"^\d{4}-\d{2}-\d{2}\.jsonl$")


def chess_api_base() -> str:
    return os.environ.get("CLUB_TOOLS_CHESS_API_BASE", "https://api.chess.com/pub").rstrip("/")


def chess_match_url(match_id_value: str) -> str:
    return f"{chess_api_base()}/match/{match_id_value}"


def chess_club_matches_url() -> str:
    return f"{chess_api_base()}/club/{DEFAULT_CLUB_SLUG}/matches"


def now() -> datetime:
    return datetime.now(timezone.utc)


def stamp(moment: datetime | None = None) -> str:
    return (moment or now()).isoformat(timespec="seconds").replace("+00:00", "Z")


def snapshot_name(moment: datetime | None = None) -> str:
    current = moment or now()
    return current.strftime("%Y%m%dT%H%M%S") + f"{current.microsecond:06d}Z.json"


def normalize_path(value: str) -> str:
    return urlsplit(value).path.rstrip("/") or "/"


def match_id(value: Any) -> str:
    text = str(value or "").strip()
    if not text:
        raise ValueError("Enter a Chess.com match ID, URL, or slug.")
    if text.isdigit():
        return text
    path = urlsplit(text).path if "://" in text else text
    match = re.search(r"(?:^|[\/-])(\d+)(?:/?$)", path.rstrip("/"))
    if match:
        return match.group(1)
    match = re.search(r"(?:^|[-_])(\d{4,})(?:$|[?#])", text)
    if match:
        return match.group(1)
    matches = re.findall(r"\d{4,}", text)
    if matches:
        return matches[-1]
    raise ValueError("The match reference does not contain a numeric Chess.com match ID.")


def normalize_username(value: Any) -> str:
    if not isinstance(value, str):
        raise ValueError("username must be a string")
    result = value.strip().lower()
    if not result or len(result) > 128 or any(ord(char) < 32 or ord(char) == 127 for char in result):
        raise ValueError("invalid username")
    return result


def normalize_count(value: Any) -> int:
    if isinstance(value, bool):
        raise ValueError("matchesFound must be an integer")
    result = int(value)
    if result < 0 or result > 1_000_000:
        raise ValueError("matchesFound is outside the accepted range")
    return result


def normalize_clubs(value: Any) -> list[str]:
    if not isinstance(value, list) or len(value) > MAX_CLUBS:
        raise ValueError("clubs must be a JSON array within the size limit")
    result: list[str] = []
    seen: set[str] = set()
    for index, raw in enumerate(value):
        if not isinstance(raw, str):
            raise ValueError(f"clubs[{index}] must be a string")
        slug = raw.strip().lower()
        if not slug or len(slug) > 128 or not SLUG_RE.fullmatch(slug) or not any(char.isalnum() for char in slug):
            raise ValueError(f"invalid club slug: {raw!r}")
        if slug not in seen:
            seen.add(slug)
            result.append(slug)
    return result


def parse_date(value: str, name: str) -> date:
    try:
        return date.fromisoformat(value)
    except ValueError as error:
        raise ValueError(f"{name} must use YYYY-MM-DD") from error


def league_codes(value: Any) -> list[str]:
    text = str(value or "").upper()
    return [
        code
        for code in SUPPORTED_LEAGUES
        if re.search(rf"(^|[^A-Z0-9]){re.escape(code)}([^A-Z0-9]|$)", text)
    ]


def atomic_json(path: Path, value: Any, backup: bool = False) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(value, ensure_ascii=False, indent=2) + "\n"
    temp_name: str | None = None
    try:
        with tempfile.NamedTemporaryFile(
            "w",
            encoding="utf-8",
            newline="\n",
            dir=path.parent,
            prefix=f".{path.name}.",
            suffix=".tmp",
            delete=False,
        ) as handle:
            temp_name = handle.name
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        if backup and path.exists():
            shutil.copy2(path, path.with_suffix(path.suffix + ".bak"))
        os.replace(temp_name, path)
        temp_name = None
    finally:
        if temp_name:
            try:
                os.unlink(temp_name)
            except FileNotFoundError:
                pass


def read_json(path: Path, default: Any = None) -> Any:
    if not path.is_file():
        return default
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError, UnicodeError):
        return default


def append_jsonl(directory: Path, entry: dict[str, Any]) -> None:
    directory.mkdir(parents=True, exist_ok=True)
    line = json.dumps(entry, ensure_ascii=False, separators=(",", ":")) + "\n"
    with (directory / f"{now().date().isoformat()}.jsonl").open("a", encoding="utf-8", newline="\n") as handle:
        handle.write(line)
        handle.flush()
        os.fsync(handle.fileno())


def etag(value: Any) -> str:
    payload = json.dumps(value, sort_keys=True, separators=(",", ":")).encode()
    return '"' + hashlib.sha256(payload).hexdigest() + '"'


def chess_json(url: str) -> dict[str, Any]:
    request = Request(
        url,
        headers={
            "Accept": "application/json",
            "Accept-Encoding": "gzip",
            "User-Agent": "ClubTools/2.8.5",
        },
    )
    try:
        with urlopen(request, timeout=30) as response:
            raw = response.read()
            if response.headers.get("Content-Encoding", "").lower() == "gzip":
                import gzip

                raw = gzip.decompress(raw)
    except HTTPError as error:
        raise RuntimeError(f"Chess.com returned HTTP {error.code} for {url}") from error
    except URLError as error:
        raise RuntimeError(f"Unable to reach Chess.com: {error.reason}") from error
    try:
        value = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise RuntimeError("Chess.com returned invalid JSON") from error
    if not isinstance(value, dict):
        raise RuntimeError("Chess.com returned an unexpected payload")
    return value


def read_challenge(path: Path) -> dict[str, Any]:
    raw = read_json(path, {}) or {}
    revision = int(raw.get("revision", 0))
    if revision < 0:
        raise RuntimeError("Stored challenge list has an invalid revision")
    return {
        "schemaVersion": 1,
        "revision": revision,
        "updatedAt": raw.get("updatedAt"),
        "clubs": normalize_clubs(raw.get("clubs", [])),
    }


def read_registry_file(path: Path) -> dict[str, Any]:
    raw = read_json(path, {}) or {}
    matches = raw.get("matches", {})
    if isinstance(matches, list):
        matches = {
            str(entry.get("matchId")): entry
            for entry in matches
            if isinstance(entry, dict) and entry.get("matchId")
        }
    if not isinstance(matches, dict):
        matches = {}
    migration = raw.get("migration", {})
    if not isinstance(migration, dict):
        migration = {}
    return {
        "schemaVersion": 3,
        "revision": max(0, int(raw.get("revision", 0))),
        "updatedAt": raw.get("updatedAt"),
        "migration": migration,
        "matches": matches,
    }


def write_registry_file(path: Path, registry: dict[str, Any], *, backup: bool = True) -> None:
    registry = dict(registry)
    registry["schemaVersion"] = 3
    registry["revision"] = max(0, int(registry.get("revision", 0))) + 1
    registry["updatedAt"] = stamp()
    matches = registry.get("matches", {})
    if not isinstance(matches, dict):
        matches = {}
    registry["matches"] = dict(sorted(matches.items(), key=lambda item: str(item[0])))
    atomic_json(path, registry, backup=backup)


def migration_empty() -> dict[str, Any]:
    return {
        "convertedMatches": 0,
        "convertedSnapshots": 0,
        "quarantinedFiles": 0,
        "removedLegacyRegistry": False,
        "completedAt": None,
    }


def normalize_snapshot_record(raw: dict[str, Any], identifier: str, fallback_tracked_at: str) -> dict[str, Any] | None:
    detail = raw.get("match") if isinstance(raw.get("match"), dict) else None
    if detail is None and any(key in raw for key in ("@id", "teams", "name")):
        detail = dict(raw)
    if not isinstance(detail, dict):
        return None
    try:
        summary = match_summary(detail)
    except Exception:
        detail = dict(detail)
        detail.setdefault("@id", chess_match_url(identifier))
        try:
            summary = match_summary(detail)
        except Exception:
            return None
    tracked_at = str(raw.get("trackedAt") or raw.get("capturedAt") or fallback_tracked_at).strip() or fallback_tracked_at
    acronyms = raw.get("leagueAcronyms")
    if not isinstance(acronyms, list):
        acronyms = summary["leagueAcronyms"]
    return {
        "schemaVersion": 2,
        "trackedAt": tracked_at,
        "matchId": summary["matchId"] or identifier,
        "leagueAcronyms": acronyms,
        "source": str(raw.get("source") or "legacy-v2.1.2"),
        "match": detail,
    }


def migration_target_path(directory: Path, name: str, record: dict[str, Any]) -> Path:
    safe = name if re.fullmatch(r"[A-Za-z0-9._-]+\.json", name) else snapshot_name()
    candidate = directory / safe
    if not candidate.exists():
        return candidate
    existing = read_json(candidate, {}) or {}
    if existing == record:
        return candidate
    digest = hashlib.sha256(json.dumps(record, ensure_ascii=False, sort_keys=True).encode("utf-8")).hexdigest()[:10]
    return directory / f"{candidate.stem}-legacy-{digest}.json"


def first_valid_snapshot(directory: Path) -> dict[str, Any] | None:
    if not directory.is_dir():
        return None
    for path in sorted(directory.glob("*.json")):
        record = read_json(path, {}) or {}
        if isinstance(record.get("match"), dict):
            return record
    return None


def latest_valid_snapshot(directory: Path) -> dict[str, Any] | None:
    if not directory.is_dir():
        return None
    for path in sorted(directory.glob("*.json"), reverse=True):
        record = read_json(path, {}) or {}
        if isinstance(record.get("match"), dict):
            return record
    return None


def migrate_legacy_tracking(history: Path, registry_path: Path) -> dict[str, Any]:
    result = migration_empty()
    tracking_root = registry_path.parent
    data_root = tracking_root.parent
    legacy_history = data_root / "match-history"
    legacy_registry_path = data_root / "followed-matches.json"
    legacy_registry_backup_path = data_root / "followed-matches.json.bak"
    legacy_registry_source = legacy_registry_path if legacy_registry_path.is_file() else legacy_registry_backup_path
    quarantine = tracking_root / "quarantine"
    registry = read_registry_file(registry_path)
    legacy_registry = read_registry_file(legacy_registry_source)
    migrated_ids: set[str] = set()

    if legacy_history.is_dir():
        for source_directory in sorted(legacy_history.iterdir()):
            if not source_directory.is_dir() or not source_directory.name.isdigit():
                continue
            identifier = source_directory.name
            target_directory = history / identifier
            target_directory.mkdir(parents=True, exist_ok=True)
            converted_for_match = 0
            for source_path in sorted(source_directory.iterdir()):
                if not source_path.is_file():
                    continue
                fallback = datetime.fromtimestamp(source_path.stat().st_mtime, timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")
                raw = read_json(source_path, {}) or {}
                record = normalize_snapshot_record(raw, identifier, fallback)
                if record is None:
                    quarantine_directory = quarantine / identifier
                    quarantine_directory.mkdir(parents=True, exist_ok=True)
                    shutil.move(str(source_path), str(quarantine_directory / source_path.name))
                    result["quarantinedFiles"] += 1
                    continue
                target = migration_target_path(target_directory, source_path.name, record)
                if not target.exists():
                    atomic_json(target, record)
                if not target.exists():
                    raise RuntimeError("Unable to verify a converted tracking snapshot")
                source_path.unlink()
                result["convertedSnapshots"] += 1
                converted_for_match += 1
            if converted_for_match or target_directory.is_dir():
                migrated_ids.add(identifier)
            try:
                source_directory.rmdir()
            except OSError:
                pass
        for leftover in list(legacy_history.iterdir()):
            if leftover.is_file() and leftover.name.lower() in {"readme.md", ".gitkeep"}:
                leftover.unlink(missing_ok=True)
        try:
            legacy_history.rmdir()
        except OSError:
            pass

    for raw_identifier, entry in legacy_registry["matches"].items():
        if not isinstance(entry, dict):
            continue
        identifier = str(raw_identifier)
        if not isinstance(registry["matches"].get(identifier), dict):
            registry["matches"][identifier] = entry
        migrated_ids.add(identifier)

    if history.is_dir():
        migrated_ids.update(
            path.name
            for path in history.iterdir()
            if path.is_dir() and path.name.isdigit() and not isinstance(registry["matches"].get(path.name), dict)
        )

    for identifier in sorted(migrated_ids):
        directory = history / identifier
        latest = latest_valid_snapshot(directory)
        first = first_valid_snapshot(directory)
        existing = registry["matches"].get(identifier)
        if not isinstance(existing, dict):
            existing = {}
        if latest:
            summary = match_summary(latest["match"])
        else:
            summary = {
                "matchId": identifier,
                "name": str(existing.get("name") or f"Match {identifier}"),
                "url": str(existing.get("url") or f"https://www.chess.com/club/matches/{identifier}"),
                "apiUrl": str(existing.get("apiUrl") or chess_match_url(identifier)),
                "leagueAcronyms": existing.get("leagueAcronyms") if isinstance(existing.get("leagueAcronyms"), list) else [],
                "status": str(existing.get("status") or "registration"),
                "startTime": existing.get("startTime"),
                "endTime": existing.get("endTime"),
                "boardCount": int(existing.get("boardCount") or 0),
                "teams": existing.get("teams") if isinstance(existing.get("teams"), list) else [],
            }
        explicitly_unfollowed = existing.get("followed") is False and bool(existing.get("unfollowedAt"))
        registry["matches"][identifier] = {
            **existing,
            **summary,
            "matchId": identifier,
            "followed": not explicitly_unfollowed,
            "source": str(existing.get("source") or "legacy-v2.1.2"),
            "addedAt": existing.get("addedAt") or (first.get("trackedAt") if first else stamp()),
            "lastCapturedAt": (latest.get("trackedAt") if latest else None) or existing.get("lastCapturedAt"),
            "unfollowedAt": existing.get("unfollowedAt") if explicitly_unfollowed else None,
        }

    has_legacy_registry = legacy_registry_path.is_file() or legacy_registry_backup_path.is_file()
    needs_write = not registry_path.is_file() or bool(migrated_ids) or has_legacy_registry
    if needs_write:
        result["convertedMatches"] = len(migrated_ids)
        result["removedLegacyRegistry"] = has_legacy_registry
        result["completedAt"] = stamp()
        registry["migration"] = {
            **(registry.get("migration") if isinstance(registry.get("migration"), dict) else {}),
            **result,
            "sourceVersion": "2.1.2",
        }
        write_registry_file(registry_path, registry, backup=True)
    if has_legacy_registry:
        legacy_registry_path.unlink(missing_ok=True)
        legacy_registry_backup_path.unlink(missing_ok=True)
        result["removedLegacyRegistry"] = True
    return result


def read_follow_registry(path: Path) -> dict[str, Any]:
    history = path.parent / "matches"
    migrate_legacy_tracking(history, path)
    return read_registry_file(path)


def write_follow_registry(path: Path, registry: dict[str, Any]) -> None:
    write_registry_file(path, registry, backup=True)


def team_rows(detail: dict[str, Any]) -> list[dict[str, Any]]:
    raw = detail.get("teams", [])
    if isinstance(raw, dict):
        raw = list(raw.values())
    if not isinstance(raw, list):
        return []
    return [item for item in raw if isinstance(item, dict)]


def team_names(detail: dict[str, Any]) -> list[str]:
    result: list[str] = []
    for team in team_rows(detail):
        name = str(team.get("name") or team.get("team_name") or team.get("club_name") or "").strip()
        if name and name not in result:
            result.append(name)
    return result


def match_status(detail: dict[str, Any]) -> str:
    for key in ("status", "state", "match_status"):
        raw = str(detail.get(key) or "").strip().lower()
        if not raw:
            continue
        if re.search(r"finish|complete|closed|ended", raw):
            return "finished"
        if re.search(r"progress|ongoing|started|active", raw):
            return "ongoing"
        if re.search(r"register|upcoming|pending|open", raw):
            return "registration"
    if int(detail.get("end_time") or 0) > 0:
        return "finished"
    start = int(detail.get("start_time") or 0)
    if start > 0:
        return "ongoing" if start <= int(time.time()) else "registration"
    return "registration"


def board_count(detail: dict[str, Any]) -> int:
    for key in ("boards", "board_count", "size", "max_players"):
        value = detail.get(key)
        if isinstance(value, (int, float)) and not isinstance(value, bool) and int(value) > 0:
            return int(value)
    counts = [len(team.get("players", [])) for team in team_rows(detail) if isinstance(team.get("players"), list)]
    positive = [value for value in counts if value > 0]
    return min(positive) if positive else 0



def monitoring_epoch(value: Any) -> int | None:
    if value in (None, ""):
        return None
    if isinstance(value, (int, float)):
        return int(value) if int(value) > 0 else None
    text = str(value).strip()
    if text.isdigit():
        return int(text) if int(text) > 0 else None
    try:
        return int(datetime.fromisoformat(text.replace("Z", "+00:00")).timestamp())
    except (TypeError, ValueError):
        return None


def continuous_tracking_expired(entry: dict[str, Any], now_epoch: int | None = None) -> bool:
    current = int(now_epoch or datetime.now(timezone.utc).timestamp())
    start = monitoring_epoch(entry.get("startTime") or entry.get("start_time"))
    return start is not None and current >= start + 86400


def apply_tracking_start_expiry(entry: dict[str, Any], now_epoch: int | None = None) -> dict[str, Any]:
    if not entry.get("followed", False) or not continuous_tracking_expired(entry, now_epoch):
        return entry
    updated = dict(entry)
    timestamp = stamp()
    updated.update({
        "followed": False,
        "unfollowedAt": timestamp,
        "autoStoppedAt": timestamp,
        "autoStopReason": "started-over-24h",
        "samplingDue": False,
        "samplingPhase": "auto-stopped",
        "samplingLabel": "Continuous tracking stopped 24 hours after match start",
        "samplingIntervalSeconds": 0,
        "nextCaptureAt": None,
    })
    return updated


def match_monitoring_schedule(entry: dict[str, Any], now_epoch: int | None = None) -> dict[str, Any]:
    current = int(now_epoch or datetime.now(timezone.utc).timestamp())
    if continuous_tracking_expired(entry, current):
        return {
            "due": False,
            "phase": "auto-stopped",
            "label": "Continuous tracking stopped 24 hours after match start",
            "intervalSeconds": 0,
            "nextCaptureAt": None,
        }
    added = monitoring_epoch(entry.get("addedAt") or entry.get("firstDiscoveredAt"))
    last = monitoring_epoch(entry.get("lastCapturedAt"))
    start = monitoring_epoch(entry.get("startTime") or entry.get("start_time"))
    age = 0 if added is None else max(0, current - added)
    until = None if start is None else start - current
    if added is None or age < 86400:
        interval, phase, label = 3600, "first-24-hours", "Hourly during the first 24 hours after discovery"
    elif until is not None and 0 < until <= 172800:
        interval, phase, label = 3600, "within-48-hours", "Hourly within 48 hours of the start"
    elif until is not None and 0 < until <= 345600:
        interval, phase, label = 21600, "within-96-hours", "Every 6 hours within 96 hours of the start"
    else:
        interval, phase, label = 43200, "standard", "Every 12 hours"
    due = last is None or last + interval <= current
    next_epoch = current if last is None else max(current, last + interval)
    return {
        "due": due,
        "phase": phase,
        "label": label,
        "intervalSeconds": interval,
        "nextCaptureAt": datetime.fromtimestamp(next_epoch, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }


def decorate_monitoring_reference(reference: dict[str, Any], existing: dict[str, Any] | None = None) -> dict[str, Any]:
    entry = {**(existing or {}), **reference}
    entry["addedAt"] = entry.get("addedAt") or stamp()
    schedule = match_monitoring_schedule(entry)
    return {
        **entry,
        "samplingDue": schedule["due"],
        "samplingPhase": schedule["phase"],
        "samplingLabel": schedule["label"],
        "samplingIntervalSeconds": schedule["intervalSeconds"],
        "nextCaptureAt": schedule["nextCaptureAt"],
    }


def match_summary(detail: dict[str, Any]) -> dict[str, Any]:
    identifier = match_id(detail.get("@id") or detail.get("url"))
    return {
        "matchId": identifier,
        "name": str(detail.get("name") or f"Match {identifier}"),
        "url": str(detail.get("url") or f"https://www.chess.com/club/matches/{identifier}"),
        "apiUrl": str(detail.get("@id") or chess_match_url(identifier)),
        "leagueAcronyms": league_codes(detail.get("name")),
        "status": match_status(detail),
        "startTime": int(detail.get("start_time") or 0) or None,
        "endTime": int(detail.get("end_time") or 0) or None,
        "boardCount": board_count(detail),
        "teams": team_names(detail),
    }


def latest_snapshot_record(history: Path, identifier: str) -> dict[str, Any] | None:
    directory = history / identifier
    if not directory.is_dir():
        return None
    for path in sorted(directory.glob("*.json"), reverse=True):
        record = read_json(path, {}) or {}
        if isinstance(record.get("match"), dict):
            return record
    return None


def update_follow_from_detail(
    registry_path: Path,
    detail: dict[str, Any],
    followed: bool = True,
    source: str = "manual",
) -> dict[str, Any]:
    summary = match_summary(detail)
    registry = read_follow_registry(registry_path)
    identifier = summary["matchId"]
    existing = registry["matches"].get(identifier, {})
    if not isinstance(existing, dict):
        existing = {}
    timestamp = stamp()
    entry = {
        **existing,
        **summary,
        "followed": followed,
        "source": source if followed else str(existing.get("source") or source),
        "addedAt": existing.get("addedAt") or timestamp,
        "lastCapturedAt": timestamp,
        "unfollowedAt": None if followed else existing.get("unfollowedAt") or timestamp,
    }
    schedule = match_monitoring_schedule(entry)
    entry.update({
        "samplingPhase": schedule["phase"],
        "samplingLabel": schedule["label"],
        "samplingIntervalSeconds": schedule["intervalSeconds"],
        "nextCaptureAt": schedule["nextCaptureAt"],
    })
    entry = apply_tracking_start_expiry(entry)
    registry["matches"][identifier] = entry
    write_follow_registry(registry_path, registry)
    return entry


def ensure_registry_entry(registry_path: Path, history: Path, identifier: str) -> dict[str, Any]:
    registry = read_follow_registry(registry_path)
    existing = registry["matches"].get(identifier)
    if isinstance(existing, dict):
        return existing
    snapshot = latest_snapshot_record(history, identifier)
    timestamp = stamp()
    if snapshot:
        summary = match_summary(snapshot["match"])
    else:
        summary = {
            "matchId": identifier,
            "name": f"Match {identifier}",
            "url": f"https://www.chess.com/club/matches/{identifier}",
            "apiUrl": chess_match_url(identifier),
            "leagueAcronyms": [],
            "status": "registration",
            "startTime": None,
            "endTime": None,
            "boardCount": 0,
            "teams": [],
        }
    entry = {
        **summary,
        "followed": True,
        "source": "legacy",
        "addedAt": snapshot.get("trackedAt") if snapshot else timestamp,
        "lastCapturedAt": snapshot.get("trackedAt") if snapshot else None,
        "unfollowedAt": None,
    }
    registry["matches"][identifier] = entry
    write_follow_registry(registry_path, registry)
    return entry


def set_follow_state(
    registry_path: Path,
    history: Path,
    identifier: str,
    followed: bool,
    source: str = "manual",
) -> dict[str, Any]:
    registry = read_follow_registry(registry_path)
    existing = registry["matches"].get(identifier)
    if not isinstance(existing, dict):
        existing = ensure_registry_entry(registry_path, history, identifier)
        registry = read_follow_registry(registry_path)
    timestamp = stamp()
    entry = {
        **existing,
        "followed": followed,
        "source": source,
        "unfollowedAt": None if followed else timestamp,
    }
    if followed and not entry.get("addedAt"):
        entry["addedAt"] = timestamp
    entry = apply_tracking_start_expiry(entry)
    registry["matches"][identifier] = entry
    write_follow_registry(registry_path, registry)
    return entry


def save_snapshot(
    history: Path,
    registry_path: Path,
    detail: dict[str, Any],
    ensure_follow: bool = True,
    source: str = "capture",
    tracked: datetime | None = None,
) -> dict[str, Any]:
    summary = match_summary(detail)
    moment = tracked or now()
    record = {
        "schemaVersion": 1,
        "trackedAt": stamp(moment),
        "matchId": summary["matchId"],
        "leagueAcronyms": summary["leagueAcronyms"],
        "match": detail,
    }
    path = history / summary["matchId"] / snapshot_name(moment)
    atomic_json(path, record)
    if ensure_follow:
        update_follow_from_detail(registry_path, detail, True, source)
    return {**summary, "file": path.name, "trackedAt": record["trackedAt"]}


def follow_and_capture(history: Path, registry_path: Path, reference: Any) -> dict[str, Any]:
    migrate_legacy_tracking(history, registry_path)
    identifier = match_id(reference)
    registry = read_follow_registry(registry_path)
    has_archive = latest_snapshot_record(history, identifier) is not None
    has_registry = isinstance(registry["matches"].get(identifier), dict)
    if has_archive or has_registry:
        set_follow_state(registry_path, history, identifier, True, "manual")
    try:
        detail = chess_json(chess_match_url(identifier))
        stored = save_snapshot(history, registry_path, detail, True, "manual")
        return {"captured": True, "stored": stored, "match": ensure_registry_entry(registry_path, history, identifier)}
    except RuntimeError as error:
        if not has_archive and not has_registry:
            raise
        return {
            "captured": False,
            "stored": None,
            "match": set_follow_state(registry_path, history, identifier, True, "manual"),
            "captureWarning": str(error),
        }


def automatic_league_references(registry_path: Path) -> dict[str, Any]:
    migrate_legacy_tracking(registry_path.parent / "matches", registry_path)
    data = chess_json(chess_club_matches_url())
    registered = data.get("registered", []) if isinstance(data.get("registered"), list) else []
    registry = read_follow_registry(registry_path)
    references: dict[str, dict[str, Any]] = {}
    registry_changed = False
    for raw in registered:
        if not isinstance(raw, dict):
            continue
        codes = league_codes(raw.get("name"))
        if not codes:
            continue
        api_url = str(raw.get("@id") or "")
        if not api_url:
            continue
        identifier = match_id(api_url)
        existing = registry["matches"].get(identifier)
        if isinstance(existing, dict) and existing.get("followed", True) is False:
            continue
        existing_dict = existing if isinstance(existing, dict) else {}
        decorated = decorate_monitoring_reference({
            "matchId": identifier,
            "name": str(raw.get("name") or f"Match {identifier}"),
            "apiUrl": api_url,
            "url": str(raw.get("url") or ""),
            "startTime": int(raw.get("start_time") or 0) or None,
            "leagueAcronyms": codes,
            "status": str(existing_dict.get("status") or "registration"),
            "endTime": existing_dict.get("endTime"),
            "boardCount": int(existing_dict.get("boardCount") or 0),
            "teams": existing_dict.get("teams") if isinstance(existing_dict.get("teams"), list) else [],
            "followed": True,
            "source": "automatic-league",
            "addedAt": existing_dict.get("addedAt") or stamp(),
            "lastCapturedAt": existing_dict.get("lastCapturedAt"),
            "unfollowedAt": None,
        }, existing_dict)
        references[identifier] = decorated
        if not isinstance(existing, dict) or decorated != existing:
            registry["matches"][identifier] = decorated
            registry_changed = True
    if registry_changed:
        write_follow_registry(registry_path, registry)
    return {"registeredReferences": len(registered), "references": list(references.values())}


def expire_started_tracking(registry_path: Path, now_epoch: int | None = None) -> int:
    registry = read_follow_registry(registry_path)
    changed = 0
    for identifier, raw in list(registry["matches"].items()):
        if not isinstance(raw, dict) or not raw.get("followed", False):
            continue
        updated = apply_tracking_start_expiry(raw, now_epoch)
        if updated != raw:
            registry["matches"][str(identifier)] = updated
            changed += 1
    if changed:
        write_follow_registry(registry_path, registry)
    return changed


def tracking_references(registry_path: Path) -> dict[str, Any]:
    automatic = automatic_league_references(registry_path)
    expired_after_start = expire_started_tracking(registry_path)
    registry = read_follow_registry(registry_path)
    references = {entry["matchId"]: entry for entry in automatic["references"] if not continuous_tracking_expired(entry)}
    followed_count = 0
    for identifier, entry in registry["matches"].items():
        if not isinstance(entry, dict) or not entry.get("followed", False):
            continue
        if entry.get("status") == "finished":
            continue
        followed_count += 1
        references.setdefault(
            str(identifier),
            decorate_monitoring_reference({
                "matchId": str(identifier),
                "name": str(entry.get("name") or f"Match {identifier}"),
                "apiUrl": str(entry.get("apiUrl") or chess_match_url(str(identifier))),
                "url": str(entry.get("url") or ""),
                "startTime": entry.get("startTime"),
                "leagueAcronyms": entry.get("leagueAcronyms") if isinstance(entry.get("leagueAcronyms"), list) else [],
                "source": "followed",
            }, entry),
        )
    return {
        "registeredReferences": automatic["registeredReferences"],
        "autoLeagueReferences": sum(1 for entry in automatic["references"] if not continuous_tracking_expired(entry)),
        "followedReferences": followed_count,
        "autoStoppedAfterStart": expired_after_start,
        "references": list(references.values()),
    }


def task_identifier(value: Any, fallback: str = "") -> str:
    clean = re.sub(r"[^a-z0-9._:-]+", "-", str(value or "").strip().lower()).strip("-")
    return (clean or fallback)[:120]


def task_run_id(task_id: str) -> str:
    return f"{task_identifier(task_id, 'task')}-{datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')}-{secrets.token_hex(4)}"


def task_entry(source: str, status: str, **values: Any) -> dict[str, Any]:
    def integer(key: str) -> int:
        return max(0, int(values.get(key, 0) or 0))

    def identifiers(key: str) -> list[str]:
        output: list[str] = []
        for raw in values.get(key, []) if isinstance(values.get(key), list) else []:
            match = re.search(r"(\d+)", str(raw))
            if match and match.group(1) not in output:
                output.append(match.group(1))
            if len(output) >= 200:
                break
        return output

    tracked = integer("trackedReferences") or integer("leagueReferences")
    task_type = task_identifier(values.get("taskType"), "match-tracking")
    task_id = task_identifier(values.get("taskId"), "track-active-matches")
    run_id = task_identifier(values.get("runId"), task_run_id(task_id))
    return {
        "schemaVersion": 3,
        "event": "match-snapshot-task",
        "timestamp": str(values.get("endedAt") or stamp()),
        "startedAt": str(values.get("startedAt") or ""),
        "endedAt": str(values.get("endedAt") or ""),
        "taskType": task_type,
        "taskId": task_id,
        "runId": run_id,
        "source": source,
        "status": status,
        "registeredReferences": integer("registeredReferences"),
        "autoLeagueReferences": integer("autoLeagueReferences"),
        "followedReferences": integer("followedReferences"),
        "trackedReferences": tracked,
        "leagueReferences": tracked,
        "processedReferences": integer("processedReferences"),
        "dueReferences": integer("dueReferences"),
        "deferredReferences": integer("deferredReferences"),
        "storedMatches": integer("storedMatches"),
        "skippedMatches": integer("skippedMatches"),
        "failedMatches": integer("failedMatches"),
        "durationMs": integer("durationMs"),
        "storedMatchIds": identifiers("storedMatchIds"),
        "failedMatchIds": identifiers("failedMatchIds"),
        "message": str(values.get("message") or "")[:500],
    }


def track_all(history: Path, registry_path: Path, task_logs: Path, source: str) -> dict[str, Any]:
    started = time.perf_counter()
    started_at = stamp()
    task_id = "track-upcoming-league-matches"
    run_id = task_run_id(task_id)
    listing = tracking_references(registry_path)
    stored: list[dict[str, Any]] = []
    errors: list[dict[str, str]] = []
    due_references = [reference for reference in listing["references"] if reference.get("samplingDue", True)]
    deferred = len(listing["references"]) - len(due_references)
    for reference in due_references:
        try:
            detail = chess_json(reference["apiUrl"])
            stored.append(save_snapshot(history, registry_path, detail, True, reference.get("source", source)))
        except Exception as error:  # task must continue with the other matches
            errors.append({"match": str(reference.get("apiUrl") or reference.get("matchId")), "message": str(error)})
    status = "success" if not errors else ("partial" if stored else "failed")
    entry = task_entry(
        source,
        status,
        taskType="match-tracking",
        taskId=task_id,
        runId=run_id,
        startedAt=started_at,
        endedAt=stamp(),
        registeredReferences=listing["registeredReferences"],
        autoLeagueReferences=listing["autoLeagueReferences"],
        followedReferences=listing["followedReferences"],
        trackedReferences=len(listing["references"]),
        processedReferences=len(due_references),
        dueReferences=len(due_references),
        deferredReferences=deferred,
        storedMatches=len(stored),
        skippedMatches=max(0, listing["registeredReferences"] - listing["autoLeagueReferences"]) + deferred,
        failedMatches=len(errors),
        durationMs=round((time.perf_counter() - started) * 1000),
        storedMatchIds=[item.get("matchId", "") for item in stored],
        failedMatchIds=[item.get("match", "") for item in errors],
    )
    append_jsonl(task_logs, entry)
    return {
        "ok": status != "failed",
        "status": status,
        "trackedAt": entry["timestamp"],
        **{key: entry[key] for key in (
            "registeredReferences", "autoLeagueReferences", "followedReferences", "trackedReferences",
            "leagueReferences", "processedReferences", "storedMatches", "skippedMatches",
            "failedMatches", "durationMs", "taskType", "taskId", "runId", "startedAt", "endedAt", "storedMatchIds", "failedMatchIds"
        )},
        "stored": stored,
        "errors": errors,
    }


def available_log_dates(directory: Path) -> list[str]:
    if not directory.is_dir():
        return []
    return sorted(path.stem for path in directory.glob("*.jsonl") if LOG_RE.fullmatch(path.name))


def log_lines(directory: Path, start: date, end: date):
    current = start
    while current <= end:
        path = directory / f"{current.isoformat()}.jsonl"
        if path.is_file():
            try:
                with path.open("r", encoding="utf-8") as handle:
                    for line in handle:
                        if line.strip():
                            yield line, current.isoformat()
            except (OSError, UnicodeError):
                yield None, current.isoformat()
        current += timedelta(days=1)


def read_match_logs(directory: Path, start: date, end: date, query: str) -> dict[str, Any]:
    query = query.strip().lower()
    daily: dict[str, dict[str, Any]] = {}
    current = start
    while current <= end:
        daily[current.isoformat()] = {"date": current.isoformat(), "analyses": 0, "matchesFound": 0, "users": set()}
        current += timedelta(days=1)
    entries: list[dict[str, Any]] = []
    users: dict[str, dict[str, Any]] = {}
    invalid = 0
    for line, day in log_lines(directory, start, end):
        if line is None:
            invalid += 1
            continue
        try:
            record = json.loads(line)
            username = normalize_username(record.get("username"))
            count = normalize_count(record.get("matchesFound"))
            timestamp = str(record.get("timestamp") or "")
            if not timestamp:
                raise ValueError
        except (ValueError, TypeError, json.JSONDecodeError):
            invalid += 1
            continue
        if query and query not in username:
            continue
        entries.append({"timestamp": timestamp, "username": username, "matchesFound": count})
        daily[day]["analyses"] += 1
        daily[day]["matchesFound"] += count
        daily[day]["users"].add(username)
        user = users.setdefault(username, {"username": username, "analyses": 0, "matchesFound": 0})
        user["analyses"] += 1
        user["matchesFound"] += count
    entries.sort(key=lambda item: item["timestamp"], reverse=True)
    dates = available_log_dates(directory)
    return {
        "ok": True,
        "range": {"from": start.isoformat(), "to": end.isoformat()},
        "filter": {"username": query},
        "summary": {
            "analyses": len(entries),
            "matchesFound": sum(item["matchesFound"] for item in entries),
            "distinctUsers": len(users),
        },
        "daily": sorted(
            [
                {
                    "date": item["date"],
                    "analyses": item["analyses"],
                    "matchesFound": item["matchesFound"],
                    "distinctUsers": len(item["users"]),
                }
                for item in daily.values()
            ],
            key=lambda item: item["date"],
            reverse=True,
        ),
        "users": sorted(users.values(), key=lambda item: (-item["analyses"], -item["matchesFound"], item["username"])),
        "entries": entries[:MAX_ENTRIES],
        "truncated": len(entries) > MAX_ENTRIES,
        "invalidLines": invalid,
        "available": {"from": dates[0] if dates else None, "to": dates[-1] if dates else None},
    }


def read_task_logs(directory: Path, start: date, end: date, source: str, status: str, task_type: str = "") -> dict[str, Any]:
    entries: list[dict[str, Any]] = []
    invalid = 0
    source_filter = {"scheduled": "cron"}.get(source, source)
    status_filter = status
    task_type_filter = task_identifier(task_type)
    for line, _day in log_lines(directory, start, end):
        if line is None:
            invalid += 1
            continue
        try:
            raw = json.loads(line)
        except json.JSONDecodeError:
            invalid += 1
            continue
        if not isinstance(raw, dict):
            invalid += 1
            continue

        event = str(raw.get("event") or "")
        if event == "scheduled-task-run":
            old_type = str(raw.get("entryType") or "").lower()
            old_status = str(raw.get("status") or "").lower()
            current_source = "cron" if old_type == "scheduled" else old_type
            current_status = "failed" if old_status == "error" else old_status
            timestamp = str(raw.get("startedAt") or raw.get("timestamp") or "")
            normalized = {
                "schemaVersion": 1,
                "event": "match-snapshot-task",
                "timestamp": timestamp,
                "source": current_source,
                "status": current_status,
                "registeredReferences": max(0, int(raw.get("registeredReferences", 0) or 0)),
                "autoLeagueReferences": 0,
                "followedReferences": 0,
                "trackedReferences": max(0, int(raw.get("registeredReferences", 0) or 0)),
                "leagueReferences": max(0, int(raw.get("registeredReferences", 0) or 0)),
                "processedReferences": max(0, int(raw.get("recordedMatches", 0) or 0)) + max(0, int(raw.get("failedMatches", 0) or 0)),
                "storedMatches": max(0, int(raw.get("recordedMatches", 0) or 0)),
                "skippedMatches": max(0, int(raw.get("skippedMatches", 0) or 0)),
                "failedMatches": max(0, int(raw.get("failedMatches", 0) or 0)),
                "durationMs": max(0, int(raw.get("durationMs", 0) or 0)),
                "message": str(raw.get("message") or "")[:500],
                "startedAt": timestamp,
                "endedAt": str(raw.get("endedAt") or ""),
                "taskType": "legacy-tracking",
                "taskId": "legacy-scheduled-task",
                "runId": "",
                "storedMatchIds": [],
                "failedMatchIds": [],
                "legacySchema": True,
            }
        elif event in {"league-snapshot-task", "match-snapshot-task"}:
            normalized = dict(raw)
            current_source = str(normalized.get("source") or "").lower()
            current_status = str(normalized.get("status") or "").lower()
            timestamp = str(normalized.get("timestamp") or "")
            for key in (
                "registeredReferences", "autoLeagueReferences", "followedReferences", "trackedReferences",
                "leagueReferences", "processedReferences", "storedMatches", "skippedMatches", "failedMatches", "durationMs"
            ):
                normalized[key] = max(0, int(normalized.get(key, 0) or 0))
            if not normalized["trackedReferences"]:
                normalized["trackedReferences"] = normalized["leagueReferences"]
            normalized["taskType"] = task_identifier(normalized.get("taskType"), "legacy-tracking")
            normalized["taskId"] = task_identifier(normalized.get("taskId"), "legacy-scheduled-task")
            normalized["runId"] = task_identifier(normalized.get("runId"))
            normalized["startedAt"] = str(normalized.get("startedAt") or timestamp)
            normalized["endedAt"] = str(normalized.get("endedAt") or timestamp)
            normalized["storedMatchIds"] = normalized.get("storedMatchIds") if isinstance(normalized.get("storedMatchIds"), list) else []
            normalized["failedMatchIds"] = normalized.get("failedMatchIds") if isinstance(normalized.get("failedMatchIds"), list) else []
        else:
            invalid += 1
            continue

        if current_source not in {"cron", "manual"} or current_status not in {"success", "partial", "failed"} or not timestamp:
            invalid += 1
            continue
        normalized["taskType"] = task_identifier(normalized.get("taskType"), "legacy-tracking")
        normalized["taskId"] = task_identifier(normalized.get("taskId"), "legacy-scheduled-task")
        normalized["runId"] = task_identifier(normalized.get("runId"))
        normalized["startedAt"] = str(normalized.get("startedAt") or timestamp)
        normalized["endedAt"] = str(normalized.get("endedAt") or timestamp)
        normalized["storedMatchIds"] = normalized.get("storedMatchIds") if isinstance(normalized.get("storedMatchIds"), list) else []
        normalized["failedMatchIds"] = normalized.get("failedMatchIds") if isinstance(normalized.get("failedMatchIds"), list) else []
        if source_filter and current_source != source_filter:
            continue
        if task_type_filter and normalized["taskType"] != task_type_filter:
            continue
        if status_filter == "errors" and current_status == "success":
            continue
        if status_filter not in {"", "errors"} and current_status != status_filter:
            continue
        normalized["source"] = current_source
        normalized["status"] = current_status
        normalized["timestamp"] = timestamp
        entries.append(normalized)

    entries.sort(key=lambda item: str(item.get("timestamp", "")), reverse=True)
    return {
        "ok": True,
        "summary": {
            "runs": len(entries),
            "storedMatches": sum(item["storedMatches"] for item in entries),
            "failedMatches": sum(item["failedMatches"] for item in entries),
            "manualRuns": sum(item["source"] == "manual" for item in entries),
            "cronRuns": sum(item["source"] == "cron" for item in entries),
        },
        "entries": entries[:MAX_ENTRIES],
        "truncated": len(entries) > MAX_ENTRIES,
        "invalidLines": invalid,
    }


def history_data(history: Path, identifier: str, registry_path: Path | None = None) -> dict[str, Any]:
    migration = migrate_legacy_tracking(history, registry_path) if registry_path is not None else migration_empty()
    directory = history / identifier
    snapshots: list[dict[str, Any]] = []
    invalid = 0
    files = list(directory.glob("*.json")) if directory.is_dir() else []
    for path in sorted(files):
        raw = read_json(path, {}) or {}
        if not isinstance(raw, dict) or not isinstance(raw.get("match"), dict) or not raw.get("trackedAt"):
            invalid += 1
            continue
        snapshots.append({"trackedAt": str(raw["trackedAt"]), "match": raw["match"]})
    snapshots.sort(key=lambda item: item["trackedAt"])
    truncated = len(snapshots) > MAX_SNAPSHOTS
    if truncated:
        snapshots = snapshots[-MAX_SNAPSHOTS:]
    return {
        "ok": True,
        "matchId": identifier,
        "snapshots": snapshots,
        "fileCount": len(files),
        "invalidFiles": invalid,
        "truncated": truncated,
        "migration": migration,
    }


def tracked_records(history: Path, registry_path: Path) -> list[dict[str, Any]]:
    migrate_legacy_tracking(history, registry_path)
    expire_started_tracking(registry_path)
    registry = read_follow_registry(registry_path)
    identifiers = {str(identifier) for identifier in registry["matches"]}
    if history.is_dir():
        identifiers.update(path.name for path in history.iterdir() if path.is_dir() and path.name.isdigit())
    result: list[dict[str, Any]] = []
    for identifier in identifiers:
        entry = registry["matches"].get(identifier)
        if not isinstance(entry, dict):
            entry = None
        directory = history / identifier
        files = sorted(directory.glob("*.json")) if directory.is_dir() else []
        valid: list[dict[str, Any]] = []
        for path in files:
            record = read_json(path, {}) or {}
            if isinstance(record.get("match"), dict):
                valid.append(record)
        first = valid[0] if valid else None
        last = valid[-1] if valid else None
        summary = match_summary(last["match"]) if last else (entry or {
            "matchId": identifier,
            "name": f"Match {identifier}",
            "url": f"https://www.chess.com/club/matches/{identifier}",
            "apiUrl": chess_match_url(identifier),
            "leagueAcronyms": [],
            "status": "registration",
            "startTime": None,
            "endTime": None,
            "boardCount": 0,
            "teams": [],
        })
        record = {
            **(entry or {}),
            **summary,
            "matchId": identifier,
            "followed": bool(entry.get("followed", False)) if entry else True,
            "source": str(entry.get("source") or "legacy") if entry else "legacy",
            "fileCount": len(files),
            "validFileCount": len(valid),
            "hasData": bool(valid),
            "firstTrackedAt": str(first.get("trackedAt") or "") if first else "",
            "lastTrackedAt": str((last.get("trackedAt") if last else "") or (entry.get("lastCapturedAt") if entry else "") or ""),
        }
        record["started"] = record.get("status") in {"ongoing", "finished"}
        result.append(record)
    result.sort(key=lambda item: str(item.get("name", "")).lower())
    return result


def remove_match_data(history: Path, identifier: str) -> dict[str, Any]:
    directory = history / identifier
    count = sum(path.is_file() for path in directory.rglob("*")) if directory.is_dir() else 0
    if directory.is_dir():
        shutil.rmtree(directory)
    return {"matchId": identifier, "deletedFiles": count}


def remove_finished_data(history: Path, registry_path: Path) -> dict[str, Any]:
    deleted_matches = 0
    deleted_files = 0
    identifiers: list[str] = []
    for record in tracked_records(history, registry_path):
        if record.get("status") != "finished" or int(record.get("fileCount", 0)) <= 0:
            continue
        deletion = remove_match_data(history, record["matchId"])
        deleted_matches += 1
        deleted_files += deletion["deletedFiles"]
        identifiers.append(record["matchId"])
    return {"deletedMatches": deleted_matches, "deletedFiles": deleted_files, "matchIds": identifiers}


def loopback(value: str) -> bool:
    try:
        return ipaddress.ip_address(value).is_loopback
    except ValueError:
        return False


def load_config(path: Path) -> dict[str, Any]:
    raw = read_json(path, {}) or {}
    token = str(raw.get("cronToken") or "")
    return {"cronToken": token}


def create_handler(
    root: Path,
    challenge_path: Path,
    match_logs: Path,
    task_logs: Path,
    history: Path,
    registry_path: Path,
    config: Path,
    lock: threading.RLock,
    allow_remote_write: bool,
    allow_remote_log_read: bool,
):
    class Handler(SimpleHTTPRequestHandler):
        server_version = "ClubToolsLocal/2.8.5"

        def log_message(self, format_string: str, *args: Any) -> None:
            print(f"[{stamp()}] {self.client_address[0]} {format_string % args}")

        def path_only(self) -> str:
            return normalize_path(self.path)

        def protected(self) -> bool:
            path = self.path_only()
            return path.startswith("/data/") or path.startswith("/logs/") or path.endswith("/.htaccess")

        def send_json(self, status: int, value: dict[str, Any], tag: str | None = None) -> None:
            raw = json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(raw)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            if tag:
                self.send_header("ETag", tag)
            self.end_headers()
            self.wfile.write(raw)

        def error_json(self, status: int, code: str, message: str, **extra: Any) -> None:
            self.send_json(status, {"ok": False, "error": {"code": code, "message": message}, **extra})

        def same_origin(self) -> bool:
            origin = self.headers.get("Origin", "")
            if not origin:
                return True
            parts = urlsplit(origin)
            expected = self.headers.get("Host", "").lower()
            actual = parts.netloc.lower()
            if parts.scheme not in {"http", "https"} or actual != expected:
                self.error_json(403, "ORIGIN_MISMATCH", "Cross-origin requests are not allowed.")
                return False
            return True

        def write_allowed(self, expected: str) -> bool:
            if not allow_remote_write and not loopback(self.client_address[0]):
                self.error_json(403, "REMOTE_WRITE_DISABLED", "Saving is restricted to the local computer.")
                return False
            if not self.same_origin():
                return False
            neutral = self.headers.get("X-Club-Tools-Request", "")
            legacy = self.headers.get("X-P2K-Request", "")
            if neutral != expected and legacy != expected:
                self.error_json(400, "REQUEST_HEADER_REQUIRED", "Missing X-Club-Tools-Request header.")
                return False
            return True

        def read_body(self, limit: int = MAX_BODY) -> dict[str, Any]:
            if self.headers.get_content_type() != "application/json":
                raise TypeError("Content-Type must be application/json")
            length = int(self.headers.get("Content-Length", "0"))
            if length < 0 or length > limit:
                raise OverflowError("Request body is too large")
            value = json.loads(self.rfile.read(length).decode("utf-8"))
            if not isinstance(value, dict):
                raise ValueError("request body must be a JSON object")
            return value

        def dates(self):
            query = parse_qs(urlsplit(self.path).query, keep_blank_values=True)
            today = now().date()
            end = parse_date(query.get("to", [today.isoformat()])[0], "to")
            start = parse_date(query.get("from", [(end - timedelta(days=6)).isoformat()])[0], "from")
            if start > end or (end - start).days + 1 > MAX_DAYS:
                raise ValueError(f"The selected period must contain 1 to {MAX_DAYS} days")
            return query, start, end

        def challenge_get(self) -> None:
            with lock:
                record = read_challenge(challenge_path)
            tag = etag(record)
            if self.headers.get("If-None-Match") == tag:
                self.send_response(304)
                self.send_header("ETag", tag)
                self.end_headers()
                return
            self.send_json(200, {"ok": True, "exists": record["revision"] > 0, **record}, tag)

        def challenge_put(self) -> None:
            if not self.write_allowed("challenge-club-list"):
                return
            request = self.read_body()
            clubs = normalize_clubs(request.get("clubs"))
            if not clubs:
                raise ValueError("clubs must contain at least one club")
            with lock:
                current = read_challenge(challenge_path)
                expected = int(request.get("revision", 0))
                if expected != current["revision"]:
                    self.error_json(409, "REVISION_CONFLICT", "The server default changed after it was loaded.", current=current)
                    return
                record = {"schemaVersion": 1, "revision": expected + 1, "updatedAt": stamp(), "clubs": clubs}
                atomic_json(challenge_path, record, backup=True)
            self.send_json(200, {"ok": True, "exists": True, **record}, etag(record))

        def match_log_post(self) -> None:
            if not self.write_allowed("match-assistant-log"):
                return
            request = self.read_body(MAX_LOG_BODY)
            entry = {
                "schemaVersion": 1,
                "event": "match-assistant-analysis",
                "timestamp": stamp(),
                "username": normalize_username(request.get("username")),
                "matchesFound": normalize_count(request.get("matchesFound")),
            }
            with lock:
                append_jsonl(match_logs, entry)
            self.send_json(201, {"ok": True, "entry": entry})

        def match_logs_get(self) -> None:
            if not allow_remote_log_read and not loopback(self.client_address[0]):
                self.error_json(403, "REMOTE_LOG_READ_DISABLED", "Log exploration is restricted to the local computer.")
                return
            query, start, end = self.dates()
            user = query.get("user", [""])[0]
            if len(user) > 128:
                raise ValueError("user filter is too long")
            with lock:
                response = read_match_logs(match_logs, start, end, user)
            self.send_json(200, response)

        def task_log_post(self) -> None:
            if not self.write_allowed("scheduled-task-log"):
                return
            request = self.read_body(MAX_LOG_BODY)
            if str(request.get("source") or "") != "manual":
                raise ValueError("Browser-created task logs must use source manual")
            status = str(request.get("status") or "")
            if status not in {"success", "partial", "failed"}:
                raise ValueError("invalid task status")
            payload = {key: value for key, value in request.items() if key not in {"source", "status"}}
            entry = task_entry("manual", status, **payload)
            with lock:
                append_jsonl(task_logs, entry)
            self.send_json(201, {"ok": True, "entry": entry})

        def task_logs_get(self) -> None:
            if not allow_remote_log_read and not loopback(self.client_address[0]):
                self.error_json(403, "REMOTE_LOG_READ_DISABLED", "Log exploration is restricted to the local computer.")
                return
            query, start, end = self.dates()
            source = query.get("source", query.get("type", [""]))[0]
            status = query.get("status", [""])[0]
            task_type = query.get("taskType", [""])[0]
            if source not in {"", "cron", "manual", "scheduled"} or status not in {"", "success", "partial", "failed", "errors"}:
                raise ValueError("invalid scheduled task filter")
            with lock:
                response = read_task_logs(task_logs, start, end, source, status, task_type)
            self.send_json(200, response)

        def refs_get(self) -> None:
            with lock:
                response = tracking_references(registry_path)
            self.send_json(200, {"ok": True, **response})

        def record_post(self) -> None:
            if not self.write_allowed("record-league-match"):
                return
            request = self.read_body(MAX_LOG_BODY)
            identifier = match_id(request.get("match"))
            with lock:
                stored = save_snapshot(history, registry_path, chess_json(chess_match_url(identifier)), True, "manual-recording")
            self.send_json(201, {"ok": True, "stored": stored})

        def track_get(self) -> None:
            query = parse_qs(urlsplit(self.path).query, keep_blank_values=True)
            configured = load_config(config)["cronToken"]
            supplied = query.get("token", [""])[0]
            if not configured:
                self.error_json(503, "CRON_TOKEN_NOT_CONFIGURED", "Configure data/server-config.json before enabling the tracking cron.")
                return
            if not hmac.compare_digest(configured, supplied):
                self.error_json(403, "INVALID_CRON_TOKEN", "The cron token is missing or invalid.")
                return
            with lock:
                response = track_all(history, registry_path, task_logs, "cron")
            self.send_json(200 if response["status"] == "success" else (206 if response["status"] == "partial" else 502), response)

        def track_post(self) -> None:
            if not self.write_allowed("track-upcoming-league-matches"):
                return
            with lock:
                response = track_all(history, registry_path, task_logs, "manual")
            self.send_json(200 if response["status"] == "success" else (206 if response["status"] == "partial" else 502), response)

        def history_get(self) -> None:
            query = parse_qs(urlsplit(self.path).query, keep_blank_values=True)
            identifier = match_id(query.get("match", [""])[0])
            with lock:
                response = history_data(history, identifier, registry_path)
            self.send_json(200, response)

        def tracked_get(self) -> None:
            with lock:
                migration = migrate_legacy_tracking(history, registry_path)
                records = tracked_records(history, registry_path)
            self.send_json(
                200,
                {
                    "ok": True,
                    "migration": migration,
                    "matches": records,
                    "summary": {
                        "matches": len(records),
                        "files": sum(int(item.get("fileCount", 0)) for item in records),
                        "followed": sum(bool(item.get("followed")) for item in records),
                        "registration": sum(item.get("status") == "registration" for item in records),
                        "ongoing": sum(item.get("status") == "ongoing" for item in records),
                        "finished": sum(item.get("status") == "finished" for item in records),
                    },
                },
            )

        def tracked_post(self) -> None:
            if not self.write_allowed("tracked-match-data"):
                return
            request = self.read_body(MAX_LOG_BODY)
            if str(request.get("action") or "follow") != "follow":
                raise ValueError("Unsupported tracked match action.")
            with lock:
                result = follow_and_capture(history, registry_path, request.get("match"))
            self.send_json(201, {"ok": True, **result})

        def tracked_delete(self) -> None:
            if not self.write_allowed("tracked-match-data"):
                return
            query = parse_qs(urlsplit(self.path).query, keep_blank_values=True)
            mode = query.get("mode", ["data"])[0]
            with lock:
                if mode == "finished-data":
                    result = remove_finished_data(history, registry_path)
                    self.send_json(200, {"ok": True, **result})
                    return
                identifier = match_id(query.get("match", [""])[0])
                if mode == "unfollow":
                    result = set_follow_state(registry_path, history, identifier, False, "manual")
                    self.send_json(200, {"ok": True, "match": result})
                    return
                if mode == "data":
                    result = remove_match_data(history, identifier)
                    self.send_json(200, {"ok": True, **result})
                    return
            raise ValueError("Unsupported tracked match deletion mode.")

        def diagnostics_get(self) -> None:
            writable = True
            try:
                root.mkdir(parents=True, exist_ok=True)
                test = root / ".club-tools-write-test"
                test.write_text("ok", encoding="utf-8")
                test.unlink()
            except OSError:
                writable = False
            migration = migrate_legacy_tracking(history, registry_path)
            registry = read_follow_registry(registry_path)
            self.send_json(
                200,
                {
                    "ok": True,
                    "backend": "Python standard-library server",
                    "writable": writable,
                    "cronConfigured": bool(load_config(config)["cronToken"]),
                    "followRegistryEntries": len(registry["matches"]),
                    "trackingMigration": migration,
                    "serverTime": stamp(),
                },
            )

        def dispatch(self, method: str) -> bool:
            routes = {
                ("GET", CHALLENGE_API): self.challenge_get,
                ("PUT", CHALLENGE_API): self.challenge_put,
                ("POST", MATCH_LOG_API): self.match_log_post,
                ("GET", MATCH_LOGS_API): self.match_logs_get,
                ("POST", TASK_LOG_API): self.task_log_post,
                ("GET", TASK_LOGS_API): self.task_logs_get,
                ("GET", TRACKING_REFS_API): self.refs_get,
                ("POST", RECORD_MATCH_API): self.record_post,
                ("GET", TRACK_API): self.track_get,
                ("POST", TRACK_API): self.track_post,
                ("GET", HISTORY_API): self.history_get,
                ("GET", TRACKED_API): self.tracked_get,
                ("POST", TRACKED_API): self.tracked_post,
                ("DELETE", TRACKED_API): self.tracked_delete,
                ("GET", DIAGNOSTICS_API): self.diagnostics_get,
            }
            handler = routes.get((method, self.path_only()))
            if not handler:
                return False
            try:
                handler()
            except TypeError as error:
                self.error_json(415, "JSON_REQUIRED", str(error))
            except OverflowError as error:
                self.error_json(413, "PAYLOAD_TOO_LARGE", str(error))
            except (ValueError, UnicodeDecodeError, json.JSONDecodeError) as error:
                self.error_json(400, "INVALID_REQUEST", str(error))
            except RuntimeError as error:
                self.error_json(502, "UPSTREAM_FAILED", str(error))
            except OSError as error:
                self.error_json(500, "STORAGE_FAILED", str(error))
            except Exception as error:  # keep API failures structured
                self.error_json(500, "SERVER_ERROR", str(error))
            return True

        def do_GET(self) -> None:
            if self.dispatch("GET"):
                return
            if self.protected():
                self.send_error(404)
                return
            super().do_GET()

        def do_PUT(self) -> None:
            if not self.dispatch("PUT"):
                self.send_error(404)

        def do_POST(self) -> None:
            if not self.dispatch("POST"):
                self.send_error(404)

        def do_DELETE(self) -> None:
            if not self.dispatch("DELETE"):
                self.send_error(404)

        def do_HEAD(self) -> None:
            if self.path_only().startswith("/api/") or self.protected():
                self.send_error(405)
                return
            super().do_HEAD()

        def do_OPTIONS(self) -> None:
            allowed = {
                CHALLENGE_API: "GET, PUT, OPTIONS",
                MATCH_LOG_API: "POST, OPTIONS",
                MATCH_LOGS_API: "GET, OPTIONS",
                TASK_LOG_API: "POST, OPTIONS",
                TASK_LOGS_API: "GET, OPTIONS",
                TRACKING_REFS_API: "GET, OPTIONS",
                RECORD_MATCH_API: "POST, OPTIONS",
                TRACK_API: "GET, POST, OPTIONS",
                HISTORY_API: "GET, OPTIONS",
                TRACKED_API: "GET, POST, DELETE, OPTIONS",
                DIAGNOSTICS_API: "GET, OPTIONS",
            }.get(self.path_only())
            if not allowed:
                self.send_error(404)
                return
            self.send_response(204)
            self.send_header("Allow", allowed)
            self.send_header("Cache-Control", "no-store")
            self.end_headers()

    return partial(Handler, directory=str(root))


def create_server(
    directory: str | Path,
    host: str = "127.0.0.1",
    port: int = 8000,
    allow_remote_write: bool = False,
    allow_remote_log_read: bool = False,
):
    root = Path(directory).expanduser().resolve()
    if not root.is_dir():
        raise NotADirectoryError(root)
    lock = threading.RLock()
    server = ThreadingHTTPServer(
        (host, port),
        create_handler(
            root,
            root / "data/challenge-club-list.json",
            root / "logs/match-assistant",
            root / "logs/scheduled-tasks",
            root / "data/match-tracking/matches",
            root / "data/match-tracking/index.json",
            root / "data/server-config.json",
            lock,
            allow_remote_write,
            allow_remote_log_read,
        ),
    )
    server.daemon_threads = True
    server.club_tools_root = root
    return server


def main() -> None:
    parser = argparse.ArgumentParser(description="Serve the club tools and local JSON APIs.")
    parser.add_argument("directory", nargs="?", default=".")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8000)
    parser.add_argument("--allow-remote-write", action="store_true")
    parser.add_argument("--allow-remote-log-read", action="store_true")
    args = parser.parse_args()
    server = create_server(args.directory, args.host, args.port, args.allow_remote_write, args.allow_remote_log_read)
    host, port = server.server_address[:2]
    display = "127.0.0.1" if host in {"0.0.0.0", "::"} else host
    print(f"Serving {server.club_tools_root} at http://{display}:{port}/")
    token = load_config(server.club_tools_root / "data/server-config.json")["cronToken"]
    if token:
        print(f"CRON URL: http://{display}:{port}/api/track-upcoming-league-matches/?token={token}")
    else:
        print("CRON token is not configured.")
    print("Press Ctrl+C to stop.")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nStopped.")
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
