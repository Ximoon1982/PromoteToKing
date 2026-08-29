<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

/**
 * Canonical identity and non-destructive deduplication for stored MCA Results sources.
 * Browser/download suffixes such as "arena-123 (2).csv" are alternate copies of arena 123,
 * not separate MCA events. Duplicate bytes remain stored for audit but only one row per
 * recognized arena participates in derived statistics.
 */
final class McaSourceCatalogue
{
    /** @return array{arena_id:int,arena_slug:string,arena_url:string,original_name:string,copy_index:int}|null */
    public static function identityFromName(string $name): ?array
    {
        $stem=preg_replace('/\.csv$/i','',basename(trim($name)))??'';
        if($stem===''||!preg_match('/^(.*-(\d+))(?:\s+\((\d+)\))?$/u',$stem,$m))return null;
        $slug=trim((string)$m[1]);$id=(int)$m[2];$copy=isset($m[3])?(int)$m[3]:0;
        if($slug===''||$id<=0)return null;
        return [
            'arena_id'=>$id,
            'arena_slug'=>$slug,
            'arena_url'=>'https://www.chess.com/tournament/live/arena/'.$slug,
            'original_name'=>$slug.'.csv',
            'copy_index'=>max(0,$copy),
        ];
    }

    /** @return array{arena_id:int,arena_slug:string,arena_url:string,original_name:string,copy_index:int}|null */
    public static function identityFromRow(array $row): ?array
    {
        $byName=self::identityFromName((string)($row['original_name']??''));
        $id=is_numeric($row['arena_id']??null)?(int)$row['arena_id']:0;
        $slug=trim((string)($row['arena_slug']??''));
        if($byName!==null){
            // The filename is the durable historical identity. Prefer its canonical slug,
            // especially when an old browser copy suffix remains in arena_slug.
            if($id<=0)$id=$byName['arena_id'];
            if($slug===''||preg_match('/\s+\(\d+\)$/u',$slug))$slug=$byName['arena_slug'];
            if($id!==$byName['arena_id'])return null; // inconsistent metadata: do not merge it silently
            $byName['arena_id']=$id;$byName['arena_slug']=$slug;
            $byName['arena_url']=trim((string)($row['event_url']??''))?:'https://www.chess.com/tournament/live/arena/'.$slug;
            return $byName;
        }
        if($id<=0||$slug==='')return null;
        $slug=preg_replace('/\s+\(\d+\)$/u','',$slug)??$slug;
        return [
            'arena_id'=>$id,'arena_slug'=>$slug,
            'arena_url'=>trim((string)($row['event_url']??''))?:'https://www.chess.com/tournament/live/arena/'.$slug,
            'original_name'=>$slug.'.csv','copy_index'=>0,
        ];
    }

    /**
     * @return array{
     *   canonical_rows:list<array>,canonical_by_arena:array<int,array>,row_meta:array<int,array>,
     *   stored_records:int,canonical_sources:int,recognized_arena_sources:int,unidentified_records:int,
     *   duplicate_records:int,duplicate_groups:int,conflicting_duplicate_groups:int,groups:list<array>
     * }
     */
    public static function analyze(array $rows): array
    {
        $groups=[];$unidentified=[];$rowMeta=[];
        foreach($rows as $row){
            $id=(int)($row['id']??0);$identity=self::identityFromRow($row);
            if($identity===null){$unidentified[]=$row;if($id>0)$rowMeta[$id]=['canonical'=>true,'arena_id'=>null,'duplicate_of_id'=>null,'copy_index'=>0,'content_conflict'=>false];continue;}
            $arenaId=$identity['arena_id'];$groups[$arenaId]??=[];$groups[$arenaId][]=[$row,$identity];
        }
        ksort($groups,SORT_NUMERIC);
        $canonical=[];$canonicalByArena=[];$groupDetails=[];$duplicateRecords=0;$conflictingGroups=0;
        foreach($groups as $arenaId=>$items){
            usort($items,static function(array $a,array $b):int{
                [$ra,$ia]=$a;[$rb,$ib]=$b;
                // The canonical unsuffixed filename wins whenever it exists. If historical data contains
                // only browser-suffixed copies, preserve the row already carrying the unique arena_id, then
                // prefer the most complete processed export, direct Chess.com Results, later copy index, known date, upload time and row id.
                $rank=static function(array $r,array $i):array{
                    $origin=strtolower(trim((string)($r['source_origin']??'manual')));
                    $originRank=$origin==='auto'?3:($origin==='manual'?2:($origin==='html-fallback'?1:0));
                    return [
                        (int)($i['copy_index']??0)===0?1:0,
                        is_numeric($r['arena_id']??null)&&(int)$r['arena_id']===(int)$i['arena_id']?1:0,
                        (int)($r['row_count']??0),$originRank,(int)($i['copy_index']??0),
                        !empty($r['actual_event_date'])?1:0,(string)($r['uploaded_at']??''),(int)($r['id']??0),
                    ];
                };
                $sa=$rank($ra,$ia);$sb=$rank($rb,$ib);
                for($x=0;$x<count($sa);$x++){if($sa[$x]===$sb[$x])continue;return $sa[$x]<$sb[$x]?1:-1;}
                return 0;
            });
            [$winner,$winnerIdentity]=$items[0];$winnerId=(int)($winner['id']??0);
            $canonical[]=$winner;$canonicalByArena[(int)$arenaId]=$winner;
            $hashes=[];$duplicates=[];
            foreach($items as $index=>[$row,$identity]){
                $rowId=(int)($row['id']??0);$hash=trim((string)($row['sha256']??''));if($hash!=='')$hashes[$hash]=true;
                $isCanonical=$index===0;
                if(!$isCanonical){$duplicateRecords++;$duplicates[]=['id'=>$rowId,'name'=>(string)($row['original_name']??''),'sha256'=>$hash,'copy_index'=>(int)$identity['copy_index']];}
                if($rowId>0)$rowMeta[$rowId]=['canonical'=>$isCanonical,'arena_id'=>(int)$arenaId,'duplicate_of_id'=>$isCanonical?null:$winnerId,'copy_index'=>(int)$identity['copy_index'],'content_conflict'=>false];
            }
            $conflict=count($hashes)>1&&count($items)>1;if($conflict)$conflictingGroups++;
            foreach($items as [$row,$identity]){$rowId=(int)($row['id']??0);if($rowId>0)$rowMeta[$rowId]['content_conflict']=$conflict;}
            if(count($items)>1)$groupDetails[]=[
                'arena_id'=>(int)$arenaId,'arena_slug'=>$winnerIdentity['arena_slug'],'canonical_file_id'=>$winnerId,
                'canonical_name'=>(string)($winner['original_name']??''),'canonical_sha256'=>(string)($winner['sha256']??''),
                'duplicate_count'=>count($items)-1,'content_conflict'=>$conflict,'duplicates'=>$duplicates,
            ];
        }
        // Files that genuinely have no recoverable arena identity remain independent sources;
        // do not drop historical data merely because the old filename predates arena IDs.
        foreach($unidentified as $row)$canonical[]=$row;
        usort($canonical,static fn(array $a,array $b):int=>(int)($a['id']??0)<=>(int)($b['id']??0));
        return [
            'canonical_rows'=>$canonical,'canonical_by_arena'=>$canonicalByArena,'row_meta'=>$rowMeta,
            'stored_records'=>count($rows),'canonical_sources'=>count($canonical),'recognized_arena_sources'=>count($groups),
            'unidentified_records'=>count($unidentified),'duplicate_records'=>$duplicateRecords,
            'duplicate_groups'=>count($groupDetails),'conflicting_duplicate_groups'=>$conflictingGroups,'groups'=>$groupDetails,
        ];
    }
}
