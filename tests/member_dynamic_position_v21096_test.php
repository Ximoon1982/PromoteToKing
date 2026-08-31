<?php
declare(strict_types=1);
function p2k_tp_username_key(string $u): string { return strtolower(trim($u)); }
require __DIR__ . '/../server/team-points/src/MemberInsightsTableService.php';
use P2K\TeamPoints\MemberInsightsTableService;

$rows = [
 ['username_key'=>'alpha','username'=>'alpha','current_member'=>true,'points'=>50,'wins'=>30,'draws'=>10,'losses'=>10,'games'=>50,'live_points'=>300,'first_activity'=>'2025-01-01 00:00:00'],
 ['username_key'=>'bravo','username'=>'bravo','current_member'=>true,'points'=>100,'wins'=>55,'draws'=>10,'losses'=>35,'games'=>100,'live_points'=>100,'first_activity'=>'2025-01-01 00:00:00'],
 ['username_key'=>'charlie','username'=>'charlie','current_member'=>true,'points'=>80,'wins'=>50,'draws'=>10,'losses'=>20,'games'=>80,'live_points'=>200,'first_activity'=>'2025-01-01 00:00:00'],
];

$points = MemberInsightsTableService::decorateAndRankRows($rows, 'points', 'desc');
$pointPos = array_column($points, 'team_position', 'username_key');
if ($pointPos !== ['alpha'=>3,'bravo'=>1,'charlie'=>2]) throw new RuntimeException('Daily Points rank must cover the whole population.');

$live = MemberInsightsTableService::decorateAndRankRows($rows, 'live_points', 'desc');
$livePos = array_column($live, 'team_position', 'username_key');
if ($livePos !== ['alpha'=>1,'bravo'=>3,'charlie'=>2]) throw new RuntimeException('Live Points rank must replace Daily Points position when live_points is the active sort.');

$liveAsc = MemberInsightsTableService::decorateAndRankRows($rows, 'live_points', 'asc');
$liveAscPos = array_column($liveAsc, 'team_position', 'username_key');
if ($liveAscPos !== ['alpha'=>3,'bravo'=>1,'charlie'=>2]) throw new RuntimeException('Team position must follow active sort direction.');

$tied = [
 ['username_key'=>'high-net','username'=>'high-net','current_member'=>true,'points'=>100,'wins'=>60,'draws'=>0,'losses'=>40,'games'=>100],
 ['username_key'=>'low-net','username'=>'low-net','current_member'=>true,'points'=>100,'wins'=>50,'draws'=>20,'losses'=>30,'games'=>100],
];
$tied = MemberInsightsTableService::decorateAndRankRows($tied, 'points', 'desc');
$tiedPos = array_column($tied, 'team_position', 'username_key');
if (($tiedPos['high-net'] ?? null) !== 1) throw new RuntimeException('Daily Points ties must retain net-wins tie breaker.');

echo "PASS member dynamic whole-dataset team position\n";
