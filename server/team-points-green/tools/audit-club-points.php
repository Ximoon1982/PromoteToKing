<?php
declare(strict_types=1);

/**
 * Read-only comparison of Green-native Club Points and the Insights match projection.
 * This script does not use GreenRepository::open(), because that method may migrate schema.
 * Run from CLI only; it never fetches external URLs, schedules work, or changes match facts.
 */

final class P2KClubPointsAudit
{
    public static function examine(array $r): array
    {
        $g = !empty($r['g_present']);
        $c = !empty($r['c_present']);
        $s = !empty($r['s_present']);
        $gStatus = (string)($r['g_status'] ?? '');
        $cStatus = (string)($r['c_status'] ?? '');
        $gResult = (string)($r['g_result'] ?? '');
        $sResult = (string)($r['s_result'] ?? '');
        $eligible = $g && (int)($r['g_eligible'] ?? 0) === 1;
        $trusted = $g && (int)($r['trusted_legacy'] ?? 0) === 1;
        $greenPoints = $eligible ? (int)($r['g_points'] ?? 0) : 0;
        $compatFinished = $c && (int)($r['c_void'] ?? 0) === 0 && $cStatus === 'finished';
        $insightsPoints = $compatFinished && $s ? (int)($r['s_points'] ?? 0) : 0;
        $greenFinished = $g && (int)($r['g_verified'] ?? 0) === 1
            && (string)($r['g_time_class'] ?? '') === 'daily'
            && $gStatus === 'finished' && (int)($r['g_void'] ?? 0) === 0;
        $gCountResult = $greenFinished && in_array($gResult, ['win','draw','loss'], true) ? $gResult : null;
        $iCountResult = $compatFinished && $s && in_array($sResult, ['win','draw','loss'], true) ? $sResult : null;
        $flags = [];
        if ($g && !$c && (int)($r['g_verified'] ?? 0) === 1 && (string)($r['g_time_class'] ?? '') === 'daily') {
            $flags[] = 'missing_compat_metadata';
        }
        if ($c && !$g) $flags[] = 'orphan_compat_metadata';
        if ($compatFinished && !$s) $flags[] = 'missing_finished_summary';
        if ($greenFinished && !in_array($gResult,['win','draw','loss'],true)) $flags[] = 'green_finished_result_unclassified';
        if ($compatFinished && (!$s || !in_array($sResult,['win','draw','loss'],true))) $flags[] = 'insights_finished_result_unclassified';
        if ($compatFinished && $s && $sResult !== (string)($r['c_result'] ?? '')
            && (string)($r['c_result'] ?? '') !== '') $flags[] = 'compat_result_summary_conflict';
        if ($greenFinished && $compatFinished && $s && $gResult !== $sResult) $flags[] = 'green_insights_result_conflict';
        if ($g && $c && $greenFinished !== $compatFinished) $flags[] = 'finished_status_conflict';
        if ($g && $c && (int)($r['g_void'] ?? 0) !== (int)($r['c_void'] ?? 0)) $flags[] = 'void_status_conflict';
        if ($g && $c && (int)($r['g_boards'] ?? 0) !== (int)($r['c_boards'] ?? 0)) $flags[] = 'board_count_conflict';
        if ($greenPoints !== $insightsPoints) $flags[] = 'club_points_contribution_conflict';
        if ($compatFinished && $s) {
            $cBoards=(int)($r['c_boards'] ?? 0);
            $iFormula=match ($sResult) { 'win'=>$cBoards*5, 'draw'=>$cBoards*2, 'loss'=>0, default=>null };
            if ($cBoards < 1 || $iFormula === null || $iFormula !== $insightsPoints) {
                $flags[]='insights_scoring_invariant_failure';
            }
        }
        $boards = (int)($r['g_boards'] ?? 0);
        if ($g && $eligible) {
            $formula = match ($gResult) { 'win' => $boards * 5, 'draw' => $boards * 2, 'loss' => 0, default => null };
            if (!$greenFinished || $boards < 1 || $formula === null || $greenPoints !== $formula) {
                $flags[] = 'green_scoring_invariant_failure';
            }
        }
        if ($greenFinished && !$eligible && $boards > 0
            && in_array($gResult, ['win','draw','loss'], true)
            && is_numeric($r['g_score'] ?? null) && is_numeric($r['g_opponent_score'] ?? null)) {
            $flags[] = 'finished_match_scoring_ineligible';
        }
        if ($greenFinished && is_numeric($r['g_score'] ?? null) && is_numeric($r['g_opponent_score'] ?? null)) {
            $our = (float)$r['g_score']; $opponent = (float)$r['g_opponent_score'];
            $derived = $our > $opponent + 0.0001 ? 'win' : ($our + 0.0001 < $opponent ? 'loss' : 'draw');
            if ($derived !== $gResult) $flags[] = 'green_result_score_conflict';
        }
        if ($g && $greenFinished && (string)($r['g_index_bucket'] ?? '') === 'finished') {
            $indexResult = strtolower((string)($r['g_index_result'] ?? ''));
            $normal = ['win'=>['win','won'], 'draw'=>['draw','drawn'], 'loss'=>['loss','lost']];
            if ($indexResult !== '' && isset($normal[$gResult]) && !in_array($indexResult, $normal[$gResult], true)) {
                $flags[] = 'index_result_requires_review';
            }
        }
        if ($trusted && $flags) $flags[] = 'trusted_legacy_manual_review_only';
        if ($flags && preg_match('/iran|iranian|persia/i', (string)($r['opponent_name'] ?? '')) === 1) {
            $flags[] = 'historical_iranian_opponent_review';
        }
        return [
            'id'=>(int)($r['match_id'] ?? 0),
            'name'=>(string)($r['name'] ?? ''),
            'opponent'=>(string)($r['opponent_name'] ?? ''),
            'url'=>(string)($r['web_url'] ?? ''),
            'green_status'=>$gStatus,
            'green_result'=>$gResult,
            'insights_status'=>$cStatus,
            'insights_result'=>$sResult,
            'boards'=>$boards,
            'green_points'=>$greenPoints,
            'insights_points'=>$insightsPoints,
            'insights_minus_green'=>$insightsPoints - $greenPoints,
            'green_finished'=>$greenFinished,
            'insights_finished'=>$compatFinished,
            'green_count_result'=>$gCountResult,
            'insights_count_result'=>$iCountResult,
            'trusted_legacy'=>$trusted,
            'green_verified_at'=>$r['g_verified_at'] ?? null,
            'green_updated_at'=>$r['g_updated_at'] ?? null,
            'compat_updated_at'=>$r['c_updated_at'] ?? null,
            'flags'=>$flags,
        ];
    }

    public static function assessRows(iterable $rows, string $since): array
    {
        $totals = [
            'green_points'=>0, 'insights_points'=>0,
            'green_finished'=>0, 'insights_finished'=>0, 'insights_stored_nonvoid'=>0,
            'green_wins'=>0, 'green_draws'=>0, 'green_losses'=>0,
            'insights_wins'=>0, 'insights_draws'=>0, 'insights_losses'=>0,
        ];
        $flagCounts = [];
        $issues = [];
        $records = 0;
        foreach ($rows as $source) {
            ++$records;
            $a = self::examine($source);
            $totals['green_points'] += $a['green_points'];
            $totals['insights_points'] += $a['insights_points'];
            if ($a['green_finished']) ++$totals['green_finished'];
            if ($a['insights_finished']) ++$totals['insights_finished'];
            if (!empty($source['c_present']) && (int)($source['c_void'] ?? 0) === 0) ++$totals['insights_stored_nonvoid'];
            if ($a['green_count_result']) ++$totals['green_'.($a['green_count_result']==='loss'?'losses':$a['green_count_result'].'s')];
            if ($a['insights_count_result']) ++$totals['insights_'.($a['insights_count_result']==='loss'?'losses':$a['insights_count_result'].'s')];
            if (!$a['flags']) continue;
            foreach ($a['flags'] as $flag) $flagCounts[$flag] = ($flagCounts[$flag] ?? 0) + 1;
            $dates = [$a['green_verified_at'], $a['green_updated_at'], $a['compat_updated_at']];
            $a['changed_since_alignment'] = count(array_filter($dates, static fn($v): bool => is_string($v) && $v >= $since)) > 0;
            $issues[] = $a;
        }
        usort($issues, static function(array $a, array $b): int {
            $score = static fn(array $x): int => abs($x['insights_minus_green']) * 100
                + (in_array('green_scoring_invariant_failure', $x['flags'], true) ? 50 : 0)
                + (in_array('finished_match_scoring_ineligible', $x['flags'], true) ? 45 : 0)
                + (in_array('green_insights_result_conflict', $x['flags'], true) ? 30 : 0)
                + ($x['changed_since_alignment'] ? 10 : 0);
            return $score($b) <=> $score($a) ?: $b['id'] <=> $a['id'];
        });
        ksort($flagCounts);
        return [
            'rows_scanned'=>$records,
            'totals'=>$totals,
            'insights_minus_green'=>$totals['insights_points']-$totals['green_points'],
            'flag_counts'=>$flagCounts,
            'issues'=>$issues,
        ];
    }
}

function p2kAuditOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) throw new InvalidArgumentException('Unknown argument: '.$arg);
        $pair = explode('=', substr($arg,2),2);
        $options[$pair[0]] = $pair[1] ?? true;
    }
    return $options;
}

function p2kAuditQuery(PDO $pdo, string $clubSlug): iterable
{
    // No joins to opponents: those could multiply a match's point contribution.
    $sql = "SELECT g.match_id, g.name, g.opponent_name, COALESCE(NULLIF(g.web_url,''),g.api_url) web_url,
       1 g_present, g.club_verified g_verified, g.time_class g_time_class,
       g.status g_status, g.result g_result, g.scoring_eligible g_eligible,
       g.trusted_legacy trusted_legacy, g.board_count g_boards,
       g.competition_points g_points, g.p2k_score g_score, g.opponent_score g_opponent_score,
       g.is_void g_void, g.index_bucket g_index_bucket, g.index_result g_index_result,
       g.last_verified_at g_verified_at, g.updated_at g_updated_at,
       (c.match_id IS NOT NULL) c_present, c.status c_status, c.result c_result,
       c.competition_points c_points, c.board_count c_boards, c.is_void c_void, c.updated_at c_updated_at,
       (s.match_id IS NOT NULL) s_present, s.result s_result, s.competition_points s_points
       FROM p2k_g_matches g
       LEFT JOIN p2k_tp_match_metadata c ON c.match_id=g.match_id AND c.club_slug=?
       LEFT JOIN p2k_tp_match_summaries s ON s.match_id=c.match_id AND s.club_slug=c.club_slug
       ORDER BY g.match_id";
    $q = $pdo->prepare($sql); $q->execute([$clubSlug]);
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) yield $row;
    // Include compatibility-only match IDs that are absent from Green altogether.
    $extra = "SELECT c.match_id, c.match_name name, c.opponent_name,
       c.match_url web_url, 0 g_present, NULL g_verified, NULL g_time_class,
       NULL g_status, NULL g_result, NULL g_eligible, NULL trusted_legacy,
       NULL g_boards, NULL g_points, NULL g_score, NULL g_opponent_score,
       NULL g_void, NULL g_index_bucket, NULL g_index_result,
       NULL g_verified_at, NULL g_updated_at,
       1 c_present, c.status c_status, c.result c_result,
       c.competition_points c_points, c.board_count c_boards, c.is_void c_void, c.updated_at c_updated_at,
       (s.match_id IS NOT NULL) s_present, s.result s_result, s.competition_points s_points
       FROM p2k_tp_match_metadata c
       LEFT JOIN p2k_g_matches g ON g.match_id=c.match_id
       LEFT JOIN p2k_tp_match_summaries s ON s.match_id=c.match_id AND s.club_slug=c.club_slug
       WHERE c.club_slug=? AND g.match_id IS NULL ORDER BY c.match_id";
    $q = $pdo->prepare($extra); $q->execute([$clubSlug]);
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) yield $row;
}

function p2kAuditCsv(string $path, array $issues): void
{
    if ($path === '' || str_contains($path,"\0")) throw new InvalidArgumentException('A valid CSV file path is required.');
    $file = @fopen($path,'xb');
    if ($file === false) throw new RuntimeException('CSV output must be a new writable file (will not overwrite): '.$path);
    try {
        $columns = ['id','name','opponent','url','green_status','green_result','insights_status','insights_result','boards',
            'green_points','insights_points','insights_minus_green','trusted_legacy','changed_since_alignment',
            'green_verified_at','green_updated_at','compat_updated_at','flags'];
        fputcsv($file,$columns);
        foreach ($issues as $row) {
            fputcsv($file,array_map(static fn($k)=>is_array($row[$k] ?? null)?implode('|',$row[$k]):($row[$k] ?? ''),$columns));
        }
    } finally { fclose($file); }
    @chmod($path,0600);
}

function p2kAuditSelfTest(): void
{
    $standard = [
        'match_id'=>1, 'g_present'=>1, 'c_present'=>1, 's_present'=>1, 'g_verified'=>1,
        'g_time_class'=>'daily','g_status'=>'finished','c_status'=>'finished','g_result'=>'draw',
        'c_result'=>'draw','s_result'=>'draw','g_boards'=>1,'c_boards'=>1,'g_points'=>2,
        's_points'=>2,'g_eligible'=>1,'g_void'=>0,'c_void'=>0,'trusted_legacy'=>0,
        'g_score'=>1,'g_opponent_score'=>1,'g_updated_at'=>'2026-10-01 00:00:00',
    ];
    $a = P2KClubPointsAudit::examine($standard);
    if ($a['insights_minus_green'] !== 0 || $a['flags']) throw new RuntimeException('Healthy match incorrectly flagged.');
    $bad = $standard;
    $bad['match_id']=2; $bad['g_result']='loss'; $bad['g_points']=0; $bad['g_score']=0; $bad['g_opponent_score']=2;
    $bad['s_result']='win'; $bad['c_result']='win'; $bad['s_points']=15; $bad['g_boards']=3; $bad['c_boards']=3;
    $a=P2KClubPointsAudit::examine($bad);
    if ($a['insights_minus_green'] !== 15 || !in_array('green_insights_result_conflict',$a['flags'],true)) throw new RuntimeException('Stale Insights result undetected.');
    $missing=$standard; $missing['match_id']=3; $missing['g_eligible']=0; $missing['g_points']=2;
    $missing['c_present']=0; $missing['s_present']=0;
    $a=P2KClubPointsAudit::examine($missing);
    if ($a['insights_minus_green'] !== 0 || !in_array('finished_match_scoring_ineligible',$a['flags'],true)) throw new RuntimeException('Ineligible one-board draw undetected.');
    $trusted=$bad; $trusted['trusted_legacy']=1;
    if (!in_array('trusted_legacy_manual_review_only',P2KClubPointsAudit::examine($trusted)['flags'],true)) throw new RuntimeException('Trusted legacy not protected.');
    $void=$standard; $void['match_id']=4; $void['g_status']='cancelled'; $void['g_void']=1; $void['g_eligible']=0;
    $void['c_void']=1; $void['c_status']='finished';
    $a=P2KClubPointsAudit::examine($void);
    if ($a['insights_points'] !== 0 || $a['green_finished'] || $a['insights_finished']) throw new RuntimeException('Void match contributes points.');
    $all=P2KClubPointsAudit::assessRows([$standard,$bad,$missing], '2026-09-27 00:00:00');
    if ($all['insights_minus_green'] !== 15 || $all['totals']['green_finished'] !== 3 || $all['totals']['green_losses']!==1 || $all['totals']['insights_wins']!==1) throw new RuntimeException('Aggregate audit mismatch.');
    echo "PASS: points, W/D/L, eligible, void, trusted-legacy, projection mismatch, and totals\n";
}

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
try {
    $args = p2kAuditOptions($argv);
    if (isset($args['self-test'])) { p2kAuditSelfTest(); exit(0); }
    if (isset($args['help'])) {
        echo "Usage: php audit-club-points.php [--since=2026-09-27] [--real-points=399253] [--real-wins=9197] [--real-draws=1817] [--real-losses=4228] [--expect-dashboard=399251] [--expect-insights=399265] [--csv=/private/new-file.csv] [--json] [--max-rows=40]\n";
        exit(0);
    }
    $since=(string)($args['since']??'2026-09-27');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/',$since)) throw new InvalidArgumentException('Invalid --since date.');
    if (strlen($since)===10) $since.=' 00:00:00';
    require_once dirname(__DIR__).'/src/bootstrap.php';
    $core=\P2K\Green\GreenConfig::core();
    $club=\P2K\Green\GreenConfig::clubSlug();
    // Forbid accidental writes (including SQL accidentally added to this script).
    $core->exec('SET TRANSACTION READ ONLY');
    $core->beginTransaction();
    try {
        $data=P2KClubPointsAudit::assessRows(p2kAuditQuery($core,$club),$since);
        $core->commit();
    } catch (Throwable $e) { if ($core->inTransaction()) $core->rollBack(); throw $e; }
    $real=[]; $expected=[];
    foreach (['points','wins','draws','losses'] as $k) if (array_key_exists('real-'.$k,$args)) $real[$k]=(int)$args['real-'.$k];
    foreach (['dashboard','insights'] as $k) if (array_key_exists('expect-'.$k,$args)) $expected[$k]=(int)$args['expect-'.$k];
    $t=$data['totals'];
    $report=[
        'audit'=>'club_points_readonly','club'=>$club,'generated_at_utc'=>gmdate('c'),
        'since'=>$since,'rows_scanned'=>$data['rows_scanned'],
        'green'=>['points'=>$t['green_points'],'finished'=>$t['green_finished'], 'wins'=>$t['green_wins'], 'draws'=>$t['green_draws'],'losses'=>$t['green_losses']],
        'insights'=>['points'=>$t['insights_points'],'stored_matches'=>$t['insights_stored_nonvoid'], 'finished'=>$t['insights_finished'], 'wins'=>$t['insights_wins'], 'draws'=>$t['insights_draws'], 'losses'=>$t['insights_losses']],
        'difference'=>['insights_minus_green'=>$data['insights_minus_green']],
        'real_reference'=>$real, 'expected_snapshot'=>$expected,
        'flag_counts'=>$data['flag_counts'],'issues_count'=>count($data['issues']),
        'note'=>'Read-only database comparison; no Chess.com live calls. Real values are caller supplied and may have moved.',
    ];
    foreach ($real as $k=>$v) {
        $report['difference']['real_minus_green_'.$k]=$v-$t['green_'.$k];
        $report['difference']['real_minus_insights_'.$k]=$v-$t['insights_'.$k];
    }
    foreach ($expected as $k=>$v) $report['snapshot_check'][$k]=($k==='dashboard'?$t['green_points']:$t['insights_points'])===$v?'matched':'changed_since_supplied_snapshot';
    if (isset($args['csv'])) { p2kAuditCsv((string)$args['csv'],$data['issues']);$report['csv']=(string)$args['csv']; }
    $max=max(0,min(250,(int)($args['max-rows']??40)));
    $report['top_issues']=array_slice($data['issues'],0,$max);
    if (isset($args['json'])) echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
    else {
        echo "P2K Club Points — READ-ONLY reconciliation\n",str_repeat('=',66),"\n";
        echo "Club: {$club}  UTC: {$report['generated_at_utc']}  rows: {$data['rows_scanned']}\n";
        echo "Green: {$t['green_points']} points, {$t['green_finished']} finished; W/D/L {$t['green_wins']}/{$t['green_draws']}/{$t['green_losses']}\n";
        echo "Insights: {$t['insights_points']} points, {$t['insights_finished']} finished, {$t['insights_stored_nonvoid']} stored; W/D/L {$t['insights_wins']}/{$t['insights_draws']}/{$t['insights_losses']}\n";
        echo "Insights minus Green: {$data['insights_minus_green']}\n";
        foreach ($real as $k=>$v) echo "Real minus Green {$k}: ",($v-$t['green_'.$k]),"; real minus Insights {$k}: ",($v-$t['insights_'.$k]),"\n";
        foreach ($report['snapshot_check']??[] as $k=>$v) echo "Supplied {$k} snapshot: {$v}\n";
        echo "Flagged matches: ",count($data['issues']),"\n";
        foreach ($data['flag_counts'] as $k=>$v) echo "  {$k}: {$v}\n";
        foreach ($report['top_issues'] as $r) echo sprintf("#%d Δ%+d  G %s/%s/%d | I %s/%s/%d | %s%s\n",$r['id'],$r['insights_minus_green'],$r['green_status'],$r['green_result'],$r['green_points'],$r['insights_status'],$r['insights_result'],$r['insights_points'],implode(',',$r['flags']),$r['trusted_legacy']?' [TRUSTED]':'');
        if ($max<count($data['issues'])) echo "... ",(count($data['issues'])-$max)," more; use --csv or --max-rows.\n";
        if (isset($report['csv'])) echo "Full flagged-match CSV: {$report['csv']}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR,"Club Points audit failed: ".$e->getMessage()."\n");
    exit(1);
}
