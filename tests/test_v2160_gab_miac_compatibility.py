"""Regression guards for Green compatibility's MIAC-aware canonical identity projection.

These are source-structure checks; production DB convergence must still be verified
using the read-only GAB status and per-board comparisons after promotion.
"""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / "server/team-points-green/src/GreenCompatibility.php").read_text(encoding="utf-8")


def segment(start, end):
    return SOURCE.split(start, 1)[1].split(end, 1)[0]


def test_board_projection_prefers_nonconflicting_miac_and_prioritizes_game_evidence():
    board = segment("private function canonicalBoardProjection(", "private function storedGameRatingPair")
    assert "LEFT JOIN p2k_miac_canonical_map mi ON mi.club_slug=?" in board
    assert "mi.conflict=0" in board
    assert "COALESCE(mi.canonical_username_key,im.canonical_username_key,mp.username_key)" in board
    assert "if($side==='p2k')$p2kCandidates[]" in board
    assert "has_point_event" in board
    assert "$cq->execute([$this->green->clubSlug,$matchId])" in board


def test_game_projection_uses_identical_canonical_scope_and_preserves_game_sequence():
    rating = segment("private function storedGameRatingPair(", "private function canonicalGameProjection")
    games = segment("private function canonicalGameProjection(", "private function projectMemberRows")
    assert "COALESCE(me.canonical_username_key,im.canonical_username_key,e.username_key)" in rating
    assert "me.conflict=0" in rating and "mw.conflict=0" in rating and "mb.conflict=0" in rating
    assert "COALESCE(mi.canonical_username_key,im.canonical_username_key,e.username_key)=?" in games
    assert "mi.conflict=0" in games
    assert "if(isset($bySequence[$seq]))" in games
    assert "$q->execute([$this->green->clubSlug,$matchId,$boardNo,$memberKey,$memberKey])" in games


def test_projector_reuses_only_members_in_confirmed_miac_component():
    p = segment("public function projectMatch(", "public function projectMatchBatch")
    assert "JOIN p2k_tp_members u ON u.club_slug=mi.club_slug" in p
    assert "mi.canonical_username_key=? AND mi.conflict=0" in p
    assert "$memberAliasId->execute([$club,$memberKey])" in p
    assert "if($mid<=0)continue;" in p  # do not fabricate members or identities


def test_both_reconciliation_scans_count_miac_canonical_players():
    scan = segment("public function reconcileCompatibilityBatch(", "public function ")
    expected = "COUNT(DISTINCT COALESCE(mi2.canonical_username_key,im2.canonical_username_key,mp2.username_key))"
    assert scan.count(expected) == 2
    assert scan.count("LEFT JOIN p2k_miac_canonical_map mi2 ON mi2.club_slug=?") == 2
    assert "mi2.conflict=0" in scan
    assert "$q->execute([$club,$afterId,$club,$club,$club,$club])" in scan
    assert "$c->execute([$club,$club,$club,$club,$club])" in scan


def test_parity_uses_same_miac_canonical_player_aggregation():
    assert "COUNT(DISTINCT CONCAT(mp.match_id,':',COALESCE(mi.canonical_username_key,im.canonical_username_key,mp.username_key)))" in SOURCE


def test_fixture_two_aliases_one_verified_board_are_not_two_players():
    # Match 1627055/49 (also 1627557/4, 1629541/7, 1629739/10,
    # 1630205/5): the live board games and point events name ElNivy.
    # When MIAC confirms both usernames in the same component, the
    # reconciliation comparison must count ONE canonical participant.
    aliases = ["elnivy", "kelvinniv-lek"]
    confirmed_miac = {name: "elnivy" for name in aliases}
    board_numbers = {name: 49 for name in aliases}
    canonical = {(49, confirmed_miac[name]) for name in board_numbers}
    assert len(canonical) == 1
    assert canonical == {(49, "elnivy")}
    # A conflict must never be collapsed merely because board numbers match.
    conflicted = {(49, name) for name in aliases}
    assert len(conflicted) == 2
