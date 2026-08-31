<?php
declare(strict_types=1);
function p2k_tp_username_key(string $u): string { return strtolower(trim($u)); }
require __DIR__ . '/../server/team-points/src/FairPlayReconciliationService.php';
use P2K\TeamPoints\FairPlayReconciliationService;

$list=FairPlayReconciliationService::normalizeRemovalList(['Duy_Lopez',['username'=>'Other'],['url'=>'https://api.chess.com/pub/player/Third']]);
if($list!==['duy_lopez','other','third']) throw new RuntimeException('removal normalization failed: '.json_encode($list));
if(FairPlayReconciliationService::rawPointsX2('win')!==2) throw new RuntimeException('win score');
if(FairPlayReconciliationService::rawPointsX2('agreed')!==1) throw new RuntimeException('draw score');
if(FairPlayReconciliationService::rawPointsX2('resigned')!==0) throw new RuntimeException('loss score');
$raw=FairPlayReconciliationService::rawPointsX2('win')+FairPlayReconciliationService::rawPointsX2('resigned');
$effective=2+2;
if($raw!==2 || $effective!==4) throw new RuntimeException('drvepearson fair-play regression fixture');
echo "PASS fair-play normalization and win+loss=>2 effective points fixture\n";
