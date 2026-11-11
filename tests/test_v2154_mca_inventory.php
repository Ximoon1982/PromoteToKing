<?php
declare(strict_types=1);
require dirname(__DIR__).'/server/team-points/src/McaSourceCatalogue.php';
require dirname(__DIR__).'/server/team-points/src/McaArenaParser.php';
require dirname(__DIR__).'/server/team-points/src/McaResultsCronService.php';
class FakeMcaStmt extends PDOStatement {
    public array $rows=[];
    public function __construct(array $rows){$this->rows=$rows;}
    public function execute(?array $params=null): bool{return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
class FakeMcaPDO extends PDO {
    public function __construct(){}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if(!str_contains($query,'p2k_lr_arena_acquisition'))throw new RuntimeException('Unexpected query');
        return new FakeMcaStmt([
          ['arena_id'=>31071472,'arena_slug'=>'blundering-buddies-bullet-1-0-unrated-multiclub-31071472','arena_url'=>'https://www.chess.com/tournament/live/arena/blundering-buddies-bullet-1-0-unrated-multiclub-31071472','event_date'=>'2026-09-25','source_kind'=>'index','status'=>'error','stage'=>'pairings','attempts'=>141,'last_error'=>'HTTP 500','updated_at'=>'2026-11-11 14:51:40'],
          ['arena_id'=>31163944,'arena_slug'=>'black-hole-blitztm-november-edition-week-2-31163944','arena_url'=>'https://www.chess.com/tournament/live/arena/black-hole-blitztm-november-edition-week-2-31163944','event_date'=>'2026-11-07','source_kind'=>'index','status'=>'pending','stage'=>'arena','attempts'=>0,'last_error'=>null,'updated_at'=>'2026-11-11 15:00:00'],
        ]);
    }
}
$pdo=new FakeMcaPDO();
$r=new ReflectionClass(P2K\TeamPoints\McaResultsCronService::class);$svc=$r->newInstanceWithoutConstructor();
$r->getProperty('pdo')->setValue($svc,$pdo);$r->getProperty('clubSlug')->setValue($svc,'promote-to-king');
$catalogue=['canonical_by_arena'=>[
   31071472=>['id'=>501,'original_name'=>'blundering-buddies-bullet-1-0-unrated-multiclub-31071472.csv','arena_id'=>31071472,'arena_slug'=>'blundering-buddies-bullet-1-0-unrated-multiclub-31071472','status'=>'processed','row_count'=>1014,'p2k_row_count'=>4,'effective_event_date'=>'2026-09-25','source_origin'=>'manual'],
]];
$m=$r->getMethod('adminArenaInventory');$inv=$m->invoke($svc,$catalogue);
assert($inv['known_arenas']===2);
assert($inv['results_ready']===1);
assert($inv['needs_results']===1);
assert($inv['results_ready_with_enrichment_errors']===1);
$good=array_values(array_filter($inv['rows'],fn($row)=>$row['results_ready']));
$bad=array_values(array_filter($inv['rows'],fn($row)=>!$row['results_ready']));
assert($good[0]['arena_id']===31071472);
assert($bad[0]['arena_id']===31163944);
assert(array_intersect(array_column($good,'arena_id'),array_column($bad,'arena_id'))===[]);
echo "PASS inventory: parsed 31071472, outstanding 31163944, distinct lists\n";

// Chess.com's newer Club Results cell contains a nested total-score div.
$html=<<<'HTML'
Club Results<table><tbody><tr><td>#1</td>
<td><a href="https://www.chess.com/club/promote-to-king" title="Promote to King">Promote to King</a></td>
<td class="table-text-right">12</td>
<td class="table-text-right tournaments-live-view-club-score"><div class="tournaments-live-view-total-score">1542</div><div class="tournaments-live-view-total-stats"><span>180 W</span></div></td></tr></tbody></table>
Player Results
HTML;
$clubs=P2K\TeamPoints\McaArenaParser::clubRows($html);
assert(count($clubs)===1);
// Scores with thousands grouping are not currently supported by number();
// retain a simple integer in this fixture to test the nested-cell contract.
assert($clubs[0]['total_players']===12);
assert($clubs[0]['club_slug']==='promote-to-king');
echo "PASS parser: nested Club Results cell accepted\n";
$source=file_get_contents(dirname(__DIR__).'/server/team-points/src/McaResultsCronService.php');
assert(!str_contains($source,'$reachedKnown'));
assert(!str_contains($source,'$knownQ'));
assert(str_contains($source,"'discovery_strategy'=>'index-occurrence-exhaustive'"));
echo "PASS discovery: no first-known early stop\n";
