<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;
use Throwable;

/** Independent, protected recruitment campaigns; no writes to Green databases. */
final class RecruitmentAnalysisStore
{
    public const LEASE_SECONDS = 180;
    private PDO $db;

    public function __construct(private readonly string $directory)
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new ApiException('Recruitment requires PHP PDO SQLite.', 503, 'SQLITE_UNAVAILABLE');
        }
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new ApiException('Recruitment storage is unavailable.', 503, 'STORAGE_UNAVAILABLE');
        }
        $path = $directory . '/analyses.sqlite3';
        if (is_link($path)) throw new ApiException('Unsafe recruitment storage path.', 503, 'STORAGE_UNAVAILABLE');
        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        @chmod($path, 0600);
        $this->db->exec('PRAGMA busy_timeout=10000; PRAGMA foreign_keys=ON');
        $this->db->exec("CREATE TABLE IF NOT EXISTS analyses (
            id TEXT PRIMARY KEY, name TEXT NOT NULL, created_by TEXT NOT NULL,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL, criteria TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('draft','paused','running','completed')),
            lease_hash TEXT NOT NULL DEFAULT '', lease_until INTEGER NOT NULL DEFAULT 0,
            total INTEGER NOT NULL DEFAULT 0, checked INTEGER NOT NULL DEFAULT 0,
            selected INTEGER NOT NULL DEFAULT 0, errors INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS candidates (
            analysis_id TEXT NOT NULL REFERENCES analyses(id) ON DELETE CASCADE,
            username_key TEXT NOT NULL, username TEXT NOT NULL, ordinal INTEGER NOT NULL,
            decision TEXT NOT NULL DEFAULT 'pending' CHECK(decision IN ('pending','selected','rejected','error')),
            checked_at TEXT, data TEXT, reason TEXT NOT NULL DEFAULT '',
            PRIMARY KEY(analysis_id,username_key)
        );
        CREATE INDEX IF NOT EXISTS candidates_order ON candidates(analysis_id,ordinal);
        CREATE INDEX IF NOT EXISTS candidates_decision ON candidates(analysis_id,decision,ordinal);
        CREATE INDEX IF NOT EXISTS analyses_updated ON analyses(updated_at DESC,id);
        CREATE TABLE IF NOT EXISTS recruitment_meta (key TEXT PRIMARY KEY,value TEXT NOT NULL);
        CREATE TRIGGER IF NOT EXISTS candidate_insert_count AFTER INSERT ON candidates BEGIN
            UPDATE analyses SET total=total+1,
                checked=checked+(NEW.decision!='pending'), selected=selected+(NEW.decision='selected'),
                errors=errors+(NEW.decision='error') WHERE id=NEW.analysis_id;
        END;
        CREATE TRIGGER IF NOT EXISTS candidate_update_count AFTER UPDATE OF decision ON candidates BEGIN
            UPDATE analyses SET checked=checked+(NEW.decision!='pending')-(OLD.decision!='pending'),
                selected=selected+(NEW.decision='selected')-(OLD.decision='selected'),
                errors=errors+(NEW.decision='error')-(OLD.decision='error') WHERE id=NEW.analysis_id;
        END;
        CREATE TRIGGER IF NOT EXISTS candidate_delete_count AFTER DELETE ON candidates BEGIN
            UPDATE analyses SET total=total-1,checked=checked-(OLD.decision!='pending'),
                selected=selected-(OLD.decision='selected'),errors=errors-(OLD.decision='error') WHERE id=OLD.analysis_id;
        END;");
    }

    private function transaction(callable $work): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try { $result=$work(); $this->db->exec('COMMIT'); return $result; }
        catch (Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    private function query(string $sql, array $parameters = []): \PDOStatement
    {
        $q=$this->db->prepare($sql);$q->execute($parameters);return $q;
    }
    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    }
    private function now(): string {return gmdate(DATE_ATOM);}
    private function raw(string $id): array
    {
        $row=$this->query('SELECT * FROM analyses WHERE id=?',[$id])->fetch();
        if (!$row) throw new ApiException('Recruitment analysis not found.',404,'ANALYSIS_NOT_FOUND');
        return $row;
    }
    private function live(array $row): bool
    {return $row['status']==='running' && (int)$row['lease_until']>time();}
    private function summary(array $row): array
    {
        return [
            'id'=>$row['id'],'name'=>$row['name'],'createdBy'=>$row['created_by'],'createdAt'=>$row['created_at'],
            'updatedAt'=>$row['updated_at'],'criteria'=>json_decode($row['criteria'],true,512,JSON_THROW_ON_ERROR),
            'status'=>$row['status']==='running'&&!$this->live($row)?'paused':$row['status'],
            'leaseUntil'=>$this->live($row)?(int)$row['lease_until']:null,
            'summary'=>['total'=>(int)$row['total'],'checked'=>(int)$row['checked'],
                'pending'=>(int)$row['total']-(int)$row['checked'],'selected'=>(int)$row['selected'],
                'errors'=>(int)$row['errors'],'rejected'=>(int)$row['checked']-(int)$row['selected']-(int)$row['errors']]
        ];
    }
    private function requireIdle(array $row): void
    {if ($this->live($row)) throw new ApiException('Pause the active scan before changing this analysis.',409,'SCAN_ACTIVE');}
    private function requireLease(string $id,string $token): array
    {
        $row=$this->raw($id);
        if (!$this->live($row) || $token==='' || !hash_equals($row['lease_hash'],hash('sha256',$token))) {
            throw new ApiException('Scan ownership expired or changed. Reopen the analysis and resume.',409,'LEASE_LOST');
        }
        return $row;
    }
    private function name(string $name): string
    {
        $name=trim($name);
        if ($name==='' || strlen($name)>240 || preg_match('/[\x00-\x1f\x7f]/',$name)) {
            throw new ApiException('Enter an analysis name (up to 240 bytes).',400,'INVALID_NAME');
        }
        return $name;
    }
    private function insertAnalysis(string $name,string $actor,array $criteria,array $candidates,?string $date=null): string
    {
        $id=bin2hex(random_bytes(16));$now=$date?:$this->now();
        $this->query('INSERT INTO analyses(id,name,created_by,created_at,updated_at,criteria,status) VALUES(?,?,?,?,?,?,?)',
            [$id,$this->name($name),$actor,$now,$this->now(),$this->json($criteria),'draft']);
        $this->insertCandidates($id,$candidates);
        return $id;
    }
    private function insertCandidates(string $id,array $candidates): void
    {
        $q=$this->db->prepare('INSERT OR IGNORE INTO candidates(analysis_id,username_key,username,ordinal) VALUES(?,?,?,?)');
        foreach ($candidates as $index=>$username) $q->execute([$id,strtolower($username),$username,$index]);
    }
    private function legacyFile(string $file): array
    {
        if (!file_exists($file)) return [];
        if (is_link($file) || !is_file($file)) throw new ApiException('Legacy recruitment state is unsafe; migration stopped.',503,'MIGRATION_FAILED');
        $raw=file_get_contents($file);
        $data=is_string($raw)?json_decode($raw,true):null;
        if (!is_array($data)) throw new ApiException('Legacy recruitment state cannot be decoded; original files retained.',503,'MIGRATION_FAILED');
        return $data;
    }

    /** Import once, transactionally; retain both original JSON files for rollback. */
    public function migrate(callable $normalize,callable $criteria): void
    {
        if ($this->query("SELECT value FROM recruitment_meta WHERE key='legacy_import_v1'")->fetchColumn()!==false) return;
        $this->transaction(function()use($normalize,$criteria){
            if ($this->query("SELECT value FROM recruitment_meta WHERE key='legacy_import_v1'")->fetchColumn()!==false) return;
            $pool=$this->legacyFile($this->directory.'/pool.json');
            $run=$this->legacyFile($this->directory.'/run.json');
            $names=$normalize($run['candidates']??[]);
            $saved=$normalize($pool['candidates']??[]);
            if ($run!==[] && $names===[]) throw new ApiException('Legacy analysis has no valid candidate list; migration stopped.',503,'MIGRATION_FAILED');
            if ($names!==[]) {
                $id=$this->insertAnalysis('Imported recruitment analysis','legacy import',$criteria((array)($run['criteria']??[])),$names,(string)($run['createdAt']??'')?:null);
                $q=$this->db->prepare('UPDATE candidates SET decision=?,checked_at=?,data=?,reason=? WHERE analysis_id=? AND username_key=?');
                foreach ((array)($run['results']??[]) as $record) {
                    if (!is_array($record)) continue;
                    $data=is_array($record['data']??null)?$record['data']:[];
                    $decision=trim((string)($data['error']??''))!==''?'error':(!empty($record['selected'])?'selected':'rejected');
                    $q->execute([$decision,(string)($record['checkedAt']??$this->now()),$this->json($data),(string)($record['reason']??''),$id,strtolower((string)($record['username']??''))]);
                }
                $this->query("UPDATE analyses SET status=CASE WHEN total=checked THEN 'completed' ELSE 'paused' END WHERE id=?",[$id]);
            }
            if ($saved!==[] && array_map('strtolower',$saved)!==array_map('strtolower',$names)) {
                $this->insertAnalysis('Imported saved candidate list','legacy import',$criteria((array)($run['criteria']??[])),$saved);
            }
            $this->query("INSERT INTO recruitment_meta(key,value) VALUES('legacy_import_v1',?)",[$this->now()]);
        });
    }
    public function page(string $search='',int $page=1,int $size=10): array
    {
        $size=max(1,min(50,$size));$search=trim(substr($search,0,240));
        $params=[$search];$where=' WHERE instr(lower(name),lower(?))>0';
        $total=(int)$this->query('SELECT COUNT(*) FROM analyses'.$where,$params)->fetchColumn();
        $pages=max(1,(int)ceil($total/$size));$page=max(1,min($pages,$page));
        $offset=($page-1)*$size;
        $rows=$this->query("SELECT * FROM analyses".$where." ORDER BY updated_at DESC,id DESC LIMIT $size OFFSET $offset",$params)->fetchAll();
        $aggregate=$this->query('SELECT COUNT(*) analyses,COALESCE(SUM(total),0) total,COALESCE(SUM(checked),0) checked,COALESCE(SUM(selected),0) selected FROM analyses')->fetch();
        $active=$this->query("SELECT id,name,lease_until FROM analyses WHERE status='running' AND lease_until>? LIMIT 1",[time()])->fetch();
        return ['items'=>array_map(fn($r)=>$this->summary($r),$rows),'total'=>$total,'page'=>$page,'pages'=>$pages,'aggregate'=>$aggregate,'active'=>$active?:null];
    }
    public function detail(string $id,bool $includeCandidates=false): array
    {
        $out=$this->summary($this->raw($id));
        if ($includeCandidates) $out['candidates']=$this->query('SELECT username FROM candidates WHERE analysis_id=? ORDER BY ordinal',[$id])->fetchAll(PDO::FETCH_COLUMN);
        return $out;
    }
    public function create(string $name,string $actor,array $criteria,array $candidates): array
    {
        if ($candidates===[]) throw new ApiException('Enter at least one valid candidate.',400,'EMPTY_POOL');
        return $this->transaction(fn()=>$this->detail($this->insertAnalysis($name,$actor,$criteria,$candidates),true));
    }
    public function updateDraft(string $id,string $name,array $criteria,array $candidates): array
    {
        if ($candidates===[]) throw new ApiException('Enter at least one valid candidate.',400,'EMPTY_POOL');
        return $this->transaction(function()use($id,$name,$criteria,$candidates){
            $row=$this->raw($id);
            if ($row['status']!=='draft') throw new ApiException('Duplicate this analysis to change its candidate list or criteria.',409,'DRAFT_REQUIRED');
            $this->query('UPDATE analyses SET name=?,criteria=?,updated_at=? WHERE id=?',[$this->name($name),$this->json($criteria),$this->now(),$id]);
            $this->query('DELETE FROM candidates WHERE analysis_id=?',[$id]);
            $this->insertCandidates($id,$candidates);return $this->detail($id,true);
        });
    }
    public function duplicate(string $id,string $actor,string $name): array
    {
        return $this->transaction(function()use($id,$actor,$name){
            $source=$this->detail($id,true);
            return $this->detail($this->insertAnalysis($name,$actor,$source['criteria'],$source['candidates']),true);
        });
    }
    public function resume(string $id): array
    {
        return $this->transaction(function()use($id){
            $row=$this->raw($id);
            if ((int)$row['checked']===(int)$row['total']) throw new ApiException('No pending candidates. Retry errors or duplicate to run again.',409,'NO_PENDING');
            $active=$this->query("SELECT id,name FROM analyses WHERE status='running' AND lease_until>? LIMIT 1",[time()])->fetch();
            if ($active) throw new ApiException('A scan is already active: '.$active['name'].'. Pause it or wait for its lease to expire.',409,'SCAN_ACTIVE');
            $this->query("UPDATE analyses SET status='paused',lease_hash='',lease_until=0 WHERE status='running' AND lease_until<=?",[time()]);
            $token=bin2hex(random_bytes(24));
            $this->query("UPDATE analyses SET status='running',lease_hash=?,lease_until=?,updated_at=? WHERE id=?",
                [hash('sha256',$token),time()+self::LEASE_SECONDS,$this->now(),$id]);
            return ['run'=>$this->detail($id),'lease'=>$token,'pending'=>$this->query("SELECT username FROM candidates WHERE analysis_id=? AND decision='pending' ORDER BY ordinal",[$id])->fetchAll(PDO::FETCH_COLUMN)];
        });
    }
    public function heartbeat(string $id,string $token): array
    {
        return $this->transaction(function()use($id,$token){
            $this->requireLease($id,$token);
            $this->query('UPDATE analyses SET lease_until=? WHERE id=?',[time()+self::LEASE_SECONDS,$id]);return $this->detail($id);
        });
    }
    public function pause(string $id,string $token): array
    {
        return $this->transaction(function()use($id,$token){
            $row=$this->raw($id);
            if ($row['status']==='completed') return $this->summary($row);
            $this->requireLease($id,$token);
            $this->query("UPDATE analyses SET status='paused',lease_until=0,lease_hash='',updated_at=? WHERE id=?",[$this->now(),$id]);return $this->detail($id);
        });
    }
    public function checkpoint(string $id,string $token,array $rows,callable $evaluate): array
    {
        return $this->transaction(function()use($id,$token,$rows,$evaluate){
            $row=$this->requireLease($id,$token);$criteria=json_decode($row['criteria'],true,512,JSON_THROW_ON_ERROR);
            $q=$this->db->prepare("UPDATE candidates SET decision=?,checked_at=?,data=?,reason=? WHERE analysis_id=? AND username_key=? AND decision='pending'");
            foreach ($rows as $record) {
                $data=$record['data'];$evaluation=$evaluate($data,$criteria);
                $decision=trim((string)($data['error']??''))!==''?'error':(!empty($evaluation['selected'])?'selected':'rejected');
                $q->execute([$decision,$this->now(),$this->json($data),(string)$evaluation['reason'],$id,strtolower($record['username'])]);
            }
            $this->query("UPDATE analyses SET status=CASE WHEN total=checked THEN 'completed' ELSE 'running' END,
                lease_hash=CASE WHEN total=checked THEN '' ELSE lease_hash END,
                lease_until=CASE WHEN total=checked THEN 0 ELSE ? END,updated_at=? WHERE id=?",
                [time()+self::LEASE_SECONDS,$this->now(),$id]);
            return $this->detail($id);
        });
    }
    public function retryErrors(string $id): array
    {
        return $this->transaction(function()use($id){
            $row=$this->raw($id);$this->requireIdle($row);
            if ((int)$row['errors']===0) throw new ApiException('No API errors to retry.',409,'NO_ERRORS');
            $this->query("UPDATE candidates SET decision='pending',checked_at=NULL,data=NULL,reason='' WHERE analysis_id=? AND decision='error'",[$id]);
            $this->query("UPDATE analyses SET status='paused',lease_until=0,lease_hash='',updated_at=? WHERE id=?",[$this->now(),$id]);return $this->detail($id);
        });
    }
    public function delete(string $id): void
    {
        $this->transaction(function()use($id){
            $this->requireIdle($this->raw($id));$this->query('DELETE FROM analyses WHERE id=?',[$id]);
        });
    }
    private function resultRow(array $r): array
    {
        return ['username'=>$r['username'],'checkedAt'=>$r['checked_at'],'decision'=>$r['decision'],
            'selected'=>$r['decision']==='selected','reason'=>$r['reason'],'data'=>$r['data']?json_decode($r['data'],true,512,JSON_THROW_ON_ERROR):[]];
    }
    public function results(string $id,string $filter,string $search,int $page,int $size): array
    {
        $this->raw($id);$size=max(1,min(100,$size));$params=[$id,trim(substr($search,0,64))];
        $where=' WHERE analysis_id=? AND instr(username_key,lower(?))>0';
        if (in_array($filter,['selected','rejected','error','pending'],true)) {$where.=' AND decision=?';$params[]=$filter;}
        else $where.=" AND decision!='pending'";
        $total=(int)$this->query('SELECT COUNT(*) FROM candidates'.$where,$params)->fetchColumn();
        $pages=max(1,(int)ceil($total/$size));$page=max(1,min($pages,$page));$offset=($page-1)*$size;
        $rows=$this->query("SELECT * FROM candidates".$where." ORDER BY ordinal LIMIT $size OFFSET $offset",$params)->fetchAll();
        return ['items'=>array_map(fn($r)=>$this->resultRow($r),$rows),'page'=>$page,'pages'=>$pages,'total'=>$total];
    }
    public function export(string $id,bool $selectedOnly): iterable
    {
        $this->raw($id);
        $q=$this->query("SELECT * FROM candidates WHERE analysis_id=? AND ".($selectedOnly?"decision='selected'":"decision!='pending'")." ORDER BY ordinal",[$id]);
        while ($r=$q->fetch()) yield $this->resultRow($r);
    }
}
