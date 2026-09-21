from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT/rel).read_text(encoding='utf-8')

def function_block(source,name):
    marker=f"function {name}("
    start=source.index(marker)
    candidates=[p for p in (source.find("\n    public function ",start+len(marker)),source.find("\n    private function ",start+len(marker)),source.find("\n    protected function ",start+len(marker))) if p!=-1]
    end=min(candidates) if candidates else len(source)
    return source[start:end]

def test_recent_matches_are_native_green_and_pre_hydration_visible():
    repo=text('server/team-points/src/Repository.php')
    block=function_block(repo,'publicRecentMatches')
    assert 'FROM p2k_g_matches' in block
    assert 'p2k_tp_match_metadata' not in block
    assert 'club_verified=1' in block
    assert 'verified_club_slug=?' in block
    assert "COALESCE(NULLIF(time_class,''),NULLIF(index_time_class,''))='daily'" in block
    assert 'created_at>=?' in block
    assert "'first_discovered_at'=>$r['created_at']" in block
    assert "'max_rating'=>null" in block
    runtime=ROOT/'tests/php_v2122_green_native_recent_matches_harness.php'
    result=subprocess.run(['php',str(runtime)],cwd=ROOT,text=True,capture_output=True)
    assert result.returncode==0, result.stdout+'\n'+result.stderr
    assert 'PASS' in result.stdout

def test_green_discovery_index_is_installable_and_upgradeable():
    schema=text('server/team-points-green/sql/core-schema.sql')
    green=text('server/team-points-green/src/GreenRepository.php')
    expected='idx_g_match_discovered (verified_club_slug,club_verified,created_at,match_id)'
    assert expected in schema
    assert "indexExists('p2k_g_matches','idx_g_match_discovered')" in green
    assert 'ADD INDEX '+expected in green

def test_green_club_resolution_precedes_compatibility_state():
    repo=text('server/team-points/src/Repository.php')
    block=function_block(repo,'resolveDataClubSlug')
    assert block.index('FROM p2k_g_state') < block.index('FROM p2k_tp_state')
