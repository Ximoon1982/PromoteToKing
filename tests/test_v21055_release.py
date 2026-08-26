from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def read(rel): return (ROOT/rel).read_text(encoding="utf-8")

def test_release_identity():
    assert read("VERSION").strip().startswith("2.10.6")
    assert read("MIGRATION_VERSION").strip().startswith("2.10.6")
    assert "public const VERSION = '2.10.6" in read("server/team-points-green/src/GreenConfig.php")
    assert "'release'=>'2.10.6'" in read("server/team-points-green/public/api.php")
    assert 'version: "2.10.6.' in read("assets/js/site-config.js") or 'version: "2.10.6"' in read("assets/js/site-config.js")

def test_gab_projection_canonicalizes_duplicate_lineup_rows_before_board_insert():
    compat=read("server/team-points-green/src/GreenCompatibility.php")
    assert "canonicalBoardProjection" in compat
    assert "p2k_g_point_events e" in compat
    assert "identity_is_canonical" in compat and "identity_trusted" in compat
    assert "$byBoard[$bn][$side][]=$row" in compat
    assert "$duplicates+=max(0,count($p2kCandidates)-1)+max(0,count($oppCandidates)-1)" in compat
    assert "$boards=$projectedBoards" in compat
    assert "foreach($boards as $b)" in compat
    # The old multiplicative direct board->two-lineup joins must not return.
    assert "JOIN p2k_g_match_players mp ON mp.match_id=gb.match_id AND mp.board_no=gb.board_no" not in compat
    assert "lineup_duplicates_resolved" in compat

def test_compat_games_follow_canonical_identity_across_old_new_usernames():
    compat=read("server/team-points-green/src/GreenCompatibility.php")
    assert "COALESCE(im.canonical_username_key,e.username_key)=?" in compat
    assert "$memberKey=(string)($b['canonical_username_key']??$b['username_key'])" in compat
    assert "$memberId->execute([$club,$memberKey])" in compat
    assert "$memberId->execute([$club,$b['username_key']])" in compat

def test_quick_complete_is_recoverable_and_not_a_runnable_new_cycle_stage():
    worker=read("server/team-points-green/src/GreenWorker.php")
    repo=read("server/team-points-green/src/GreenRepository.php")
    assert "recoverQuickCompleteTransition" in repo
    assert "recoverQuickCompleteTransition();$state=$this->repo->state()" in worker
    assert worker.index("recoverQuickCompleteTransition()") < worker.index("if($state['cycle_started_at']!==null)")
    assert "$this->repo->stage('quick_complete')" not in worker
    assert "$this->repo->completeCycle($this->achieved,'quick_index_roster')" in worker

def test_cycle_next_stage_is_durable_before_analytics_rebuild():
    repo=read("server/team-points-green/src/GreenRepository.php")
    start=repo.index("public function completeCycle(array $summary=[],?string $nextStage=null)")
    end=repo.index("public function recoverQuickCompleteTransition",start)
    body=repo[start:end]
    assert "$this->core->beginTransaction()" in body
    assert "cycle_started_at=NULL,cycle_kind=NULL,stage=?" in body
    assert "$this->core->commit()" in body
    assert "$this->rebuildAnalytics($no)" in body
    assert body.index("$this->core->commit()") < body.index("$this->rebuildAnalytics($no)")


def test_zoned_density_visual_smoothing_matches_proof_without_palette_change():
    chart=read("assets/js/pages/opponent-balance-analyzer.js")
    # Exact agreed spatial smoothing: half-size bins + one light 3x3 Gaussian pass.
    assert "innerWidth / 9.5" in chart and "innerHeight / 8" in chart
    assert "const kernel = [[1,2,1],[2,4,2],[1,2,1]]" in chart
    # The missing proof-of-concept step: interpolate that smoothed field before zone quantization.
    assert "const bilinearDensity = (gx, gy) =>" in chart
    assert "const rasterScale = 1.6" in chart
    assert "zoneFor(bilinearDensity(gx, gy))" in chart
    # Do not change the approved zone thresholds or colors.
    for threshold in ["t < .035","t < .10","t < .22","t < .38","t < .58","t < .78"]:
        assert threshold in chart
    for color in ["#163c9a","#22b7e6","#5bcf6a","#f3df46","#f08a28","#d92525"]:
        assert color in chart
