<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use RuntimeException;

final class RecruitmentHistoryStore
{
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;
    public const MAX_CSV_BYTES = 20_000_000;

    public function __construct(private readonly string $directory)
    {
        $this->ensureDirectory();
    }

    public static function fromConfig(array $config): self
    {
        $runtime=rtrim(trim((string)($config['storage']['runtime_dir']??'')),'/\\');
        if($runtime==='') $runtime=dirname(P2K_TP_ROOT,2).'/data/runtime-v280';
        return new self($runtime.'/recruitment-history');
    }

    public function archive(int $matchId,string $matchName,string $csv): array
    {
        if($matchId<=0) throw new RuntimeException('A valid match ID is required.');
        $matchName=trim(preg_replace('/\s+/u',' ',strip_tags($matchName))??'');
        if($matchName==='') $matchName='Match '.$matchId;
        $matchName=mb_substr($matchName,0,300);
        $bytes=strlen($csv);
        if($bytes<=0||$bytes>self::MAX_CSV_BYTES) throw new RuntimeException('Recruitment CSV is empty or exceeds the archive size limit.');

        $id=gmdate('Ymd\\THis\\Z').'-'.$matchId.'-'.bin2hex(random_bytes(3));
        $file=$id.'.csv';
        $path=$this->directory.'/'.$file;
        if(@file_put_contents($path,$csv,LOCK_EX)!==$bytes) throw new RuntimeException('Unable to store recruitment CSV.');
        @chmod($path,0600);

        $entry=[
            'id'=>$id,
            'created_at'=>gmdate(DATE_ATOM),
            'match_id'=>$matchId,
            'match_name'=>$matchName,
            'file'=>$file,
            'bytes'=>$bytes,
            'sha256'=>hash('sha256',$csv),
        ];

        try{
            $this->mutateIndex(static function(array $entries)use($entry):array{
                array_unshift($entries,$entry);
                return $entries;
            });
        }catch(\Throwable $e){
            @unlink($path);
            throw $e;
        }
        return $entry;
    }

    public function page(int $page,int $perPage=self::DEFAULT_PAGE_SIZE): array
    {
        $page=max(1,$page);
        $perPage=max(1,min(self::MAX_PAGE_SIZE,$perPage));
        $entries=$this->readIndex();
        usort($entries,static fn(array $a,array $b):int=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
        $total=count($entries);
        $pages=max(1,(int)ceil($total/$perPage));
        if($page>$pages)$page=$pages;
        $items=array_slice($entries,($page-1)*$perPage,$perPage);
        return ['items'=>$items,'page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages];
    }

    public function get(string $id): ?array
    {
        $id=$this->normalizeId($id);
        foreach($this->readIndex() as $entry) if(hash_equals((string)($entry['id']??''),$id)) return $entry;
        return null;
    }

    public function pathFor(array $entry): string
    {
        $file=basename((string)($entry['file']??''));
        if($file===''||!str_ends_with($file,'.csv')) throw new RuntimeException('Archived recruitment file metadata is invalid.');
        return $this->directory.'/'.$file;
    }

    public function delete(string $id): bool
    {
        $id=$this->normalizeId($id);
        $removed=null;
        $this->mutateIndex(static function(array $entries)use($id,&$removed):array{
            $next=[];
            foreach($entries as $entry){
                if(is_array($entry)&&hash_equals((string)($entry['id']??''),$id)){ $removed=$entry; continue; }
                if(is_array($entry))$next[]=$entry;
            }
            return $next;
        });
        if(!is_array($removed)) return false;
        $path=$this->pathFor($removed);
        if(is_file($path)&&!@unlink($path)) throw new RuntimeException('Unable to delete archived recruitment CSV.');
        return true;
    }

    private function normalizeId(string $id): string
    {
        $id=trim($id);
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D',$id)) throw new RuntimeException('Invalid recruitment archive ID.');
        return $id;
    }

    private function ensureDirectory(): void
    {
        if(!is_dir($this->directory)&&!@mkdir($this->directory,0700,true)&&!is_dir($this->directory)) throw new RuntimeException('Unable to create recruitment archive directory.');
        foreach([
            '.htaccess'=>"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'index.html'=>'',
        ] as $name=>$content){
            $path=$this->directory.'/'.$name;
            if(!is_file($path))@file_put_contents($path,$content,LOCK_EX);
        }
    }

    private function readIndex(): array
    {
        $path=$this->directory.'/index.json';
        if(!is_file($path)) return [];
        $raw=@file_get_contents($path);
        if(!is_string($raw)||trim($raw)==='') return [];
        $decoded=json_decode($raw,true);
        return is_array($decoded)?array_values(array_filter($decoded,'is_array')):[];
    }

    private function mutateIndex(callable $mutator): void
    {
        $lockPath=$this->directory.'/.index.lock';
        $lock=@fopen($lockPath,'c+');
        if($lock===false) throw new RuntimeException('Unable to lock recruitment archive index.');
        try{
            if(!flock($lock,LOCK_EX)) throw new RuntimeException('Unable to lock recruitment archive index.');
            $next=$mutator($this->readIndex());
            $json=json_encode(array_values($next),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if($json===false) throw new RuntimeException('Unable to encode recruitment archive index.');
            $tmp=$this->directory.'/index.json.tmp.'.bin2hex(random_bytes(4));
            if(@file_put_contents($tmp,$json."\n",LOCK_EX)===false) throw new RuntimeException('Unable to write recruitment archive index.');
            @chmod($tmp,0600);
            if(!@rename($tmp,$this->directory.'/index.json')){@unlink($tmp);throw new RuntimeException('Unable to publish recruitment archive index.');}
        }finally{
            @flock($lock,LOCK_UN);
            @fclose($lock);
        }
    }
}
