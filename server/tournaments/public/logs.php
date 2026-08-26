<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/api/_common.php';

try {
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
    $entries=[];
    $end=new DateTimeImmutable('today',new DateTimeZone('UTC'));
    $start=$end->modify('-30 days');
    foreach (log_lines(root_dir().'/logs/scheduled-tasks',$start,$end) as [$line]) {
        if ($line===null) continue;
        $row=json_decode($line,true);
        if(!is_array($row)||(string)($row['taskType']??'')!=='tournaments-update') continue;
        $entries[]=[
            'startedAt'=>(string)($row['startedAt']??$row['timestamp']??''),
            'trigger'=>(string)($row['source']??''),'result'=>(string)($row['status']??''),
            'checked'=>(int)($row['processedReferences']??$row['processedItems']??0),'updated'=>(int)($row['updatedItems']??$row['storedMatches']??0),
            'excluded'=>(int)($row['excludedItems']??0),'message'=>(string)($row['message']??''),
        ];
    }
    usort($entries,fn($a,$b)=>strcmp($b['startedAt'],$a['startedAt']));
    json_response(200,['ok'=>true,'logs'=>array_slice($entries,0,$limit)]);
} catch(Throwable $error){ api_error(500,'TOURNAMENT_LOGS_FAILED',$error->getMessage()); }
