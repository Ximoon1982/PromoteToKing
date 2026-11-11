<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/server/team-points/src/McaRenameRecheckService.php';
use P2K\TeamPoints\McaRenameRecheckService;

function row(int $rank,string $username,float $score=24.0,string $club='Promote to King',int $wins=6):array {
    return ['rank'=>$rank,'username'=>$username,'score'=>$score,'club'=>$club,
            'wins'=>$wins,'draws'=>1,'losses'=>2,'streak'=>3,'most_wins'=>6,'rating'=>1700];
}
function check(bool $condition,string $name):void {
    if (!$condition) throw new RuntimeException('FAIL '.$name);
    echo "PASS ".$name.PHP_EOL;
}
$before=[row(1,'alpha'),row(2,'missing_old',21.5),row(3,'gamma',19.0)];
$after=[row(1,'alpha'),row(2,'renamed_new',21.5),row(3,'gamma',19.0)];
$ok=McaRenameRecheckService::correlateRows($before,$after,'missing_old');
check($ok['status']==='correlated' && $ok['new_username']==='renamed_new' && $ok['position']===2,'single positional substitution with exact facts auto-correlates');
check(McaRenameRecheckService::correlateRows($before,$before,'missing_old')['status']==='unchanged','unchanged remote CSV is not an alias');
$ratingChanged=$after;$ratingChanged[1]['rating']=2200;
check(McaRenameRecheckService::correlateRows($before,$ratingChanged,'missing_old')['status']==='correlated','rating drift does not invalidate historical name substitution');
$swap=$after;$swap[0]['username']='someone_else';
check(McaRenameRecheckService::correlateRows($before,$swap,'missing_old')['status']==='ambiguous','two changed rows are never auto-linked');
$rank=$after;$rank[1]['rank']=4;
check(McaRenameRecheckService::correlateRows($before,$rank,'missing_old')['status']==='ambiguous','rank changed is not conclusive');
$score=$after;$score[1]['score']=21;
check(McaRenameRecheckService::correlateRows($before,$score,'missing_old')['status']==='ambiguous','score changed is not conclusive');
$wdl=$after;$wdl[1]['wins']=7;
check(McaRenameRecheckService::correlateRows($before,$wdl,'missing_old')['status']==='ambiguous','wins changed is not conclusive');
$club=$after;$club[1]['club']='Another Club';
check(McaRenameRecheckService::correlateRows($before,$club,'missing_old')['status']==='ambiguous','club changed is not conclusive');
$collision=$after;$collision[1]['username']='gamma';
check(McaRenameRecheckService::correlateRows($before,$collision,'missing_old')['status']==='ambiguous','existing other username is not a new alias');
$reorder=$after;$reorder[0]=$before[2];$reorder[2]=$before[0];
check(McaRenameRecheckService::correlateRows($before,$reorder,'missing_old')['status']==='ambiguous','reordered CSV is not safe for positional attribution');
$short=$after;array_pop($short);
check(McaRenameRecheckService::correlateRows($before,$short,'missing_old')['status']==='ambiguous','participant count mismatch is not conclusive');
$absent=$after;$absent[1]['username']='renamed_new';
check(McaRenameRecheckService::correlateRows($before,$absent,'an_unrelated_missing_name')['status']==='ambiguous','only the actual unavailable username qualifies');
echo "PASS 12 correlation safety fixtures".PHP_EOL;
