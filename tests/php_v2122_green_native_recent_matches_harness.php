<?php
declare(strict_types=1);

require_once __DIR__ . '/../server/team-points/src/Repository.php';

use P2K\TeamPoints\Repository;

final class RecentGreenStatement extends PDOStatement
{
    private array $resultRows = [];
    private int $cursor = 0;
    public function __construct(private RecentGreenPdo $pdo, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $this->cursor = 0;
        if (str_contains($this->sql, 'FROM p2k_g_state WHERE club_slug=?')) {
            $this->resultRows = (($params[0] ?? '') === $this->pdo->clubSlug) ? [['club_slug'=>$this->pdo->clubSlug]] : [];
            return true;
        }
        if (str_contains($this->sql, 'FROM p2k_g_matches')) {
            $club = (string)($params[0] ?? '');
            $cutoff = (string)($params[1] ?? '');
            $rows = array_values(array_filter($this->pdo->matches, static function(array $row) use ($club,$cutoff): bool {
                $timeClass = trim((string)($row['time_class'] ?? ''));
                if ($timeClass === '') $timeClass = trim((string)($row['index_time_class'] ?? ''));
                return (int)($row['club_verified'] ?? 0) === 1
                    && (string)($row['verified_club_slug'] ?? '') === $club
                    && $timeClass === 'daily'
                    && (string)($row['created_at'] ?? '') >= $cutoff;
            }));
            usort($rows, static fn(array $a,array $b): int => ((string)$b['created_at'] <=> (string)$a['created_at']) ?: ((int)$b['match_id'] <=> (int)$a['match_id']));
            $this->resultRows = $rows;
            return true;
        }
        throw new RuntimeException('Unexpected SQL in recent Green harness: '.$this->sql);
    }
    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->resultRows === []) return false;
        $row=$this->resultRows[0];return array_values($row)[$column] ?? false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->resultRows; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->resultRows[$this->cursor++] ?? false;
    }
}

final class RecentGreenPdo extends PDO
{
    public string $clubSlug='promote-to-king';
    public array $matches=[];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new RecentGreenStatement($this,$query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new RecentGreenStatement($this,$query); }
}

$pdo=new RecentGreenPdo();
$now=time();
$stamp=static fn(int $offset): string => gmdate('Y-m-d H:i:s',$now+$offset);
$pdo->matches=[
    ['match_id'=>2001,'api_url'=>'https://api.chess.com/pub/match/2001','web_url'=>null,'name'=>null,'status'=>'unknown','index_bucket'=>'registered','rules'=>null,'time_control'=>null,'start_epoch'=>null,'end_epoch'=>null,'board_count'=>null,'p2k_score'=>null,'opponent_score'=>null,'opponent_name'=>null,'opponent_url'=>null,'created_at'=>$stamp(-7200),'last_verified_at'=>null,'time_class'=>null,'index_time_class'=>'daily','club_verified'=>1,'verified_club_slug'=>'promote-to-king'],
    ['match_id'=>2002,'api_url'=>'https://api.chess.com/pub/match/2002','web_url'=>'https://www.chess.com/club/matches/promote-to-king/2002','name'=>'PCL Test','status'=>'registered','index_bucket'=>'registered','rules'=>'chess','time_control'=>'1/86400','start_epoch'=>$now+86400,'end_epoch'=>null,'board_count'=>20,'p2k_score'=>0,'opponent_score'=>0,'opponent_name'=>'Opponent X','opponent_url'=>'https://api.chess.com/pub/club/opponent-x','created_at'=>$stamp(-3600),'last_verified_at'=>$stamp(-120),'time_class'=>'daily','index_time_class'=>'daily','club_verified'=>1,'verified_club_slug'=>'promote-to-king'],
    ['match_id'=>1999,'api_url'=>'https://api.chess.com/pub/match/1999','web_url'=>null,'name'=>'Old','status'=>'registered','index_bucket'=>'registered','rules'=>null,'time_control'=>null,'start_epoch'=>null,'end_epoch'=>null,'board_count'=>10,'p2k_score'=>0,'opponent_score'=>0,'opponent_name'=>null,'opponent_url'=>null,'created_at'=>$stamp(-90000),'last_verified_at'=>null,'time_class'=>'daily','index_time_class'=>'daily','club_verified'=>1,'verified_club_slug'=>'promote-to-king'],
    ['match_id'=>2003,'api_url'=>'https://api.chess.com/pub/match/2003','web_url'=>null,'name'=>'Live','status'=>'registered','index_bucket'=>'registered','rules'=>null,'time_control'=>null,'start_epoch'=>null,'end_epoch'=>null,'board_count'=>10,'p2k_score'=>0,'opponent_score'=>0,'opponent_name'=>null,'opponent_url'=>null,'created_at'=>$stamp(-1000),'last_verified_at'=>null,'time_class'=>'rapid','index_time_class'=>'rapid','club_verified'=>1,'verified_club_slug'=>'promote-to-king'],
    ['match_id'=>2004,'api_url'=>'https://api.chess.com/pub/match/2004','web_url'=>null,'name'=>'Foreign','status'=>'registered','index_bucket'=>'registered','rules'=>null,'time_control'=>null,'start_epoch'=>null,'end_epoch'=>null,'board_count'=>10,'p2k_score'=>0,'opponent_score'=>0,'opponent_name'=>null,'opponent_url'=>null,'created_at'=>$stamp(-1000),'last_verified_at'=>null,'time_class'=>'daily','index_time_class'=>'daily','club_verified'=>1,'verified_club_slug'=>'other-club'],
    ['match_id'=>2005,'api_url'=>'https://api.chess.com/pub/match/2005','web_url'=>null,'name'=>'Unverified','status'=>'registered','index_bucket'=>'registered','rules'=>null,'time_control'=>null,'start_epoch'=>null,'end_epoch'=>null,'board_count'=>10,'p2k_score'=>0,'opponent_score'=>0,'opponent_name'=>null,'opponent_url'=>null,'created_at'=>$stamp(-1000),'last_verified_at'=>null,'time_class'=>'daily','index_time_class'=>'daily','club_verified'=>0,'verified_club_slug'=>'promote-to-king'],
];

$rows=(new Repository($pdo))->publicRecentMatches('promote-to-king',24);
$ids=array_column($rows,'match_id');
if($ids!==[2002,2001]){fwrite(STDERR,'Unexpected recent IDs: '.json_encode($ids)."\n");exit(1);}
if(($rows[1]['status']??'')!=='registered'||($rows[1]['name']??'')!=='Match 2001'){fwrite(STDERR,'Index-only match did not retain safe registered fallback.\n');exit(2);}
if(($rows[0]['opponent_slug']??'')!=='opponent-x'||($rows[0]['is_league']??false)!==true){fwrite(STDERR,'Hydrated native fields were not mapped correctly.\n');exit(3);}
if(array_key_exists('max_rating',$rows[0])!==true||$rows[0]['max_rating']!==null){fwrite(STDERR,'max_rating contract key must remain present with native-safe null.\n');exit(4);}
echo "v2.12.2 Green-native recent matches runtime: PASS\n";
