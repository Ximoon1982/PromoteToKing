<?php
declare(strict_types=1);
function p2k_tp_username_key(string $u): string { return strtolower(trim($u)); }
require __DIR__ . '/../server/team-points/src/MemberInsightsTableService.php';
use P2K\TeamPoints\MemberInsightsTableService;

$rows = [
 ['username_key'=>'old-import','username'=>'old-import','current_member'=>true,'points'=>100,'wins'=>90,'draws'=>20,'losses'=>10,'games'=>120,'first_activity'=>'2024-01-01 12:00:00','first_seen_at'=>'2026-08-20 00:00:00'],
 ['username_key'=>'joined-zero','username'=>'joined-zero','current_member'=>true,'points'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'games'=>0,'first_activity'=>null,'first_seen_at'=>'2026-08-20 00:00:00'],
 ['username_key'=>'genuine-new','username'=>'genuine-new','current_member'=>true,'points'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'games'=>0,'first_activity'=>null,'first_seen_at'=>'2026-08-20 00:00:00'],
];
$joined = ['old-import'=>null,'joined-zero'=>'2026-01-15 00:00:00','genuine-new'=>'2026-08-20 00:00:00'];
$map = MemberInsightsTableService::positionMap($rows,'2026-07-31',$joined);
if (($map['old-import'] ?? null) !== 1) throw new RuntimeException('historical activity must override recent import first_seen_at');
if (($map['joined-zero'] ?? null) !== 2) throw new RuntimeException('authoritative joined_at before cutoff must keep zero-point member eligible');
if (isset($map['genuine-new'])) throw new RuntimeException('member joining after cutoff must remain NEW');
echo "PASS member historical ranking eligibility\n";
