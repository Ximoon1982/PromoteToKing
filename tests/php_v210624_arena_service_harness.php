<?php
declare(strict_types=1);
namespace {
function p2k_tp_config(): array { return ['app'=>['club_slug'=>'promote-to-king'],'storage'=>[]]; }
function p2k_tp_json_decode(string $value): array { $v=json_decode($value,true); return is_array($v)?$v:[]; }
}
namespace P2K\TeamPoints {
class ApiException extends \RuntimeException { public function __construct(string $message, public int $httpStatus=400, public string $errorCode='API_ERROR'){ parent::__construct($message); } }
class Repository {}
class ChessApi {}
final class FakeStatement extends \PDOStatement {
    public function __construct(private array $rows=[], private mixed $column=null) {}
    public function execute(?array $params=null): bool { return true; }
    public function fetch(int $mode=\PDO::FETCH_DEFAULT, int $cursorOrientation=\PDO::FETCH_ORI_NEXT, int $cursorOffset=0): mixed { return $this->rows[0]??false; }
    public function fetchAll(int $mode=\PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column=0): mixed { if($this->column!==null)return $this->column; $row=$this->rows[0]??[]; return array_values($row)[$column]??false; }
}
final class FakePDO extends \PDO {
    public function __construct() {}
    public function prepare(string $query, array $options=[]): \PDOStatement|false {
        if(str_contains($query,'FROM p2k_lr_arena_stats s')) return new FakeStatement([
            ['file_id'=>1,'original_name'=>'spring-arena-101.csv','p2k_players'=>2,'p2k_points'=>10,'first_places'=>1,'second_places'=>1,'third_places'=>0,'arena_id'=>101,'arena_slug'=>'spring-arena-101','event_url'=>'https://example/101','actual_event_date'=>'2026-01-01','effective_event_date'=>'2026-01-01','event_date_precision'=>'known','total_players'=>100,'p2k_row_count'=>3,'uploaded_at'=>'2026-01-02 00:00:00','processed_at'=>'2026-01-02 01:00:00'],
            ['file_id'=>2,'original_name'=>'summer-arena-102.csv','p2k_players'=>1,'p2k_points'=>4,'first_places'=>0,'second_places'=>0,'third_places'=>0,'arena_id'=>102,'arena_slug'=>'summer-arena-102','event_url'=>'https://example/102','actual_event_date'=>null,'effective_event_date'=>'2026-02-01','event_date_precision'=>'interpolated','total_players'=>50,'p2k_row_count'=>1,'uploaded_at'=>'2026-02-02 00:00:00','processed_at'=>'2026-02-02 01:00:00'],
        ]);
        if(str_contains($query,'FROM p2k_lr_source_rows r')) return new FakeStatement([
            ['file_id'=>1,'canonical_username_key'=>'alice','canonical_username'=>'Alice','score'=>2,'games'=>2,'wins'=>1,'draws'=>1,'losses'=>0,'streak'=>1,'max_wins'=>1,'max_games'=>2,'rank_value'=>2],
            ['file_id'=>1,'canonical_username_key'=>'alice','canonical_username'=>'Alice','score'=>3,'games'=>2,'wins'=>1,'draws'=>0,'losses'=>1,'streak'=>2,'max_wins'=>1,'max_games'=>2,'rank_value'=>3],
            ['file_id'=>1,'canonical_username_key'=>'bob','canonical_username'=>'Bob','score'=>5,'games'=>4,'wins'=>3,'draws'=>0,'losses'=>1,'streak'=>3,'max_wins'=>3,'max_games'=>4,'rank_value'=>1],
            ['file_id'=>2,'canonical_username_key'=>'alice','canonical_username'=>'Alice','score'=>4,'games'=>4,'wins'=>2,'draws'=>1,'losses'=>1,'streak'=>2,'max_wins'=>2,'max_games'=>4,'rank_value'=>5],
        ]);
        if(str_contains($query,'SELECT COUNT(*) FROM p2k_lr_players')) return new FakeStatement([],2);
        if(str_contains($query,'source_files_json FROM p2k_lr_players')) return new FakeStatement([
            ['username'=>'Alice','username_key'=>'alice','total_points'=>9,'arena_count'=>2,'total_games'=>8,'total_wins'=>4,'total_draws'=>2,'total_losses'=>2,'best_rank'=>2,'first_place_count'=>0,'top3_count'=>1,'top10_count'=>2,'current_member'=>1,'account_state'=>'current_member','source_files_json'=>'["spring-arena-101.csv","summer-arena-102.csv"]'],
            ['username'=>'Bob','username_key'=>'bob','total_points'=>5,'arena_count'=>1,'total_games'=>4,'total_wins'=>3,'total_draws'=>0,'total_losses'=>1,'best_rank'=>1,'first_place_count'=>1,'top3_count'=>1,'top10_count'=>1,'current_member'=>0,'account_state'=>'former_member','source_files_json'=>'["spring-arena-101.csv"]'],
        ]);
        throw new \RuntimeException('Unexpected query: '.$query);
    }
}
require_once __DIR__.'/../server/team-points/src/LiveRanksService.php';
$service=new LiveRanksService(new FakePDO(),new Repository(),new ChessApi());
$all=$service->publicArenasInsights('all',['page'=>1,'page_size'=>25]);
if(($all['summary']['arenas']??0)!==2)throw new \RuntimeException('arenas');
if(($all['summary']['participations']??0)!==3)throw new \RuntimeException('participations');
if(($all['summary']['unique_players']??0)!==2)throw new \RuntimeException('unique');
if(($all['summary']['victories']??0)!==1)throw new \RuntimeException('victories');
if(($all['summary']['podiums']??0)!==2)throw new \RuntimeException('podiums');
if(($all['summary']['top10_finishes']??0)!==3)throw new \RuntimeException('top10');
if(abs(($all['summary']['average_p2k_players']??0)-1.5)>0.001)throw new \RuntimeException('average');
if(($all['trend'][0]['best_finisher']??'')!=='Bob'||($all['trend'][0]['best_rank']??0)!==1)throw new \RuntimeException('best');
if(abs(($all['trend'][0]['cumulative_points']??0)-10)>0.001||abs(($all['trend'][1]['cumulative_points']??0)-14)>0.001)throw new \RuntimeException('cumulative');
if(abs(($all['trend'][0]['p2k_share_percent']??0)-2.0)>0.001)throw new \RuntimeException('share');
if(abs(($all['trend'][0]['score_percent']??0)-68.8)>0.01)throw new \RuntimeException('score');
if(($all['leaders'][0]['username']??'')!=='Alice'||($all['leaders'][0]['longest_participation_streak']??0)!==2||($all['leaders'][0]['current_participation_streak']??0)!==2)throw new \RuntimeException('streak');
$detail=$service->publicArenasInsights('detail',['file_id'=>1]);
if(count($detail['participants']??[])!==2||($detail['participants'][0]['username']??'')!=='Bob'||($detail['participants'][1]['points']??0)!=5)throw new \RuntimeException('detail alias merge');
$table=$service->publicArenasInsights('table',['search'=>'summer','sort'=>'event_date','direction'=>'desc','page'=>1,'page_size'=>25]);
if(($table['pagination']['total_rows']??0)!==1||($table['rows'][0]['file_id']??0)!==2)throw new \RuntimeException('table');
echo "arena-service-harness: PASS\n";
}
