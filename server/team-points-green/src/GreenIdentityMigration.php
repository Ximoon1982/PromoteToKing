<?php
declare(strict_types=1);

namespace P2K\Green;

use PDO;
use RuntimeException;
use P2K\TeamPoints\Database;

/**
 * Imports the trusted Blue MIAC/name-transition identity topology without
 * copying any Blue match/board/score facts. The importer is intentionally
 * schema-discovering because MIAC evolved additively across the 2.9.x line.
 */
final class GreenIdentityMigration
{
    private GreenRepository $green;

    public function __construct(GreenRepository $green)
    {
        $this->green = $green;
    }

    private function blue(): PDO
    {
        if (method_exists(Database::class,'core')) return Database::core();
        if (method_exists(Database::class,'connection')) return Database::connection();
        throw new RuntimeException('Blue Core database accessor is unavailable.');
    }

    private static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/',$name)) throw new RuntimeException('Unsafe database identifier.');
        return '`'.$name.'`';
    }

    public function discover(): array
    {
        $pdo=$this->blue();
        $q=$pdo->query("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND (LOWER(table_name) LIKE '%miac%' OR LOWER(table_name) LIKE '%identity%') ORDER BY table_name,ordinal_position");
        $tables=[];
        foreach($q->fetchAll()?:[] as $r)$tables[(string)$r['table_name']][]=(string)$r['column_name'];
        $out=[];
        foreach($tables as $table=>$cols){
            $low=array_map('strtolower',$cols);
            $shape=$this->detectShape($low);
            $out[]=['table'=>$table,'columns'=>$cols,'shape'=>$shape,'supported'=>$shape!==null];
        }
        return ['tables'=>$out,'supported_tables'=>count(array_filter($out,fn($r)=>$r['supported']))];
    }

    private function detectShape(array $cols): ?array
    {
        $pick=static function(array $names)use($cols){foreach($names as $n)if(in_array($n,$cols,true))return $n;return null;};
        $raw=$pick(['username_key','raw_username_key','name_key','alias_key','member_username_key']);
        $canonical=$pick(['canonical_username_key','canonical_key','canonical_username','canonical_name']);
        $component=$pick(['component_id','component_key','identity_id','canonical_id','group_id']);
        $old=$pick(['old_username_key','old_name_key','from_username_key','former_username_key','old_username']);
        $new=$pick(['new_username_key','new_name_key','to_username_key','current_username_key','new_username']);
        $username=$pick(['username','raw_username','name','alias']);
        $player=$pick(['chess_player_id','player_id']);
        if($raw&&$canonical)return ['kind'=>'direct_map','raw'=>$raw,'canonical'=>$canonical,'username'=>$username,'player_id'=>$player];
        if($raw&&$component)return ['kind'=>'component_map','raw'=>$raw,'component'=>$component,'username'=>$username,'player_id'=>$player];
        if($old&&$new)return ['kind'=>'edge','old'=>$old,'new'=>$new];
        return null;
    }

    public function importBlue(): array
    {
        $disc=$this->discover();$supported=array_values(array_filter($disc['tables'],fn($r)=>$r['supported']));
        if(!$supported)throw new RuntimeException('No supported Blue MIAC/identity table shape was detected. Use the JSON identity import fallback from the installer.');
        $pdo=$this->blue();$mappings=[];$edges=[];$used=[];
        foreach($supported as $t){
            $table=(string)$t['table'];$shape=$t['shape'];$kind=$shape['kind'];$used[]=['table'=>$table,'shape'=>$kind];
            if($kind==='direct_map'){
                $select=[self::ident($shape['raw']).' raw_key',self::ident($shape['canonical']).' canonical_key'];
                if($shape['username'])$select[]=self::ident($shape['username']).' username';
                if($shape['player_id'])$select[]=self::ident($shape['player_id']).' player_id';
                foreach($pdo->query('SELECT '.implode(',',$select).' FROM '.self::ident($table))->fetchAll()?:[] as $r){
                    $raw=self::key((string)($r['raw_key']??''));$can=self::key((string)($r['canonical_key']??''));if($raw===''||$can==='')continue;
                    $mappings[$raw]=['username'=>(string)($r['username']??$raw),'canonical_key'=>$can,'canonical_username'=>$can,'player_id'=>self::pid($r['player_id']??null),'component'=>null,'source_ref'=>$table];
                }
            }elseif($kind==='component_map'){
                $select=[self::ident($shape['raw']).' raw_key',self::ident($shape['component']).' component'];
                if($shape['username'])$select[]=self::ident($shape['username']).' username';
                if($shape['player_id'])$select[]=self::ident($shape['player_id']).' player_id';
                $groups=[];
                foreach($pdo->query('SELECT '.implode(',',$select).' FROM '.self::ident($table))->fetchAll()?:[] as $r){$raw=self::key((string)($r['raw_key']??''));if($raw==='')continue;$component=(string)($r['component']??'');if($component==='')continue;$groups[$component][]=['raw'=>$raw,'username'=>(string)($r['username']??$raw),'player_id'=>self::pid($r['player_id']??null)];}
                foreach($groups as $component=>$rows){$can=$this->chooseCanonical($pdo,$rows);foreach($rows as $r)$mappings[$r['raw']]=['username'=>$r['username'],'canonical_key'=>$can['raw'],'canonical_username'=>$can['username'],'player_id'=>$r['player_id'],'component'=>$component,'source_ref'=>$table];}
            }elseif($kind==='edge'){
                $sql='SELECT '.self::ident($shape['old']).' old_key,'.self::ident($shape['new']).' new_key FROM '.self::ident($table);
                foreach($pdo->query($sql)->fetchAll()?:[] as $r){$old=self::key((string)($r['old_key']??''));$new=self::key((string)($r['new_key']??''));if($old&&$new&&$old!==$new)$edges[$old.'|'.$new]=['old'=>$old,'new'=>$new,'source_ref'=>$table];}
            }
        }
        // If edges exist but no explicit canonical map was found, resolve components from the trusted graph.
        if($edges){$graph=$this->componentsFromEdges(array_values($edges));foreach($graph as $rows){$members=array_map(fn($k)=>['raw'=>$k,'username'=>$k,'player_id'=>null],$rows);$can=$this->chooseCanonical($pdo,$members);foreach($members as $r)if(!isset($mappings[$r['raw']]))$mappings[$r['raw']]=['username'=>$r['username'],'canonical_key'=>$can['raw'],'canonical_username'=>$can['username'],'player_id'=>null,'component'=>'edge:'.$can['raw'],'source_ref'=>'trusted_edges'];}}
        $result=$this->store(array_values($mappings),array_values($edges),'blue_miac_trusted',['detected'=>$disc,'used'=>$used]);
        return $result+['discovery'=>$disc,'used_tables'=>$used];
    }

    private function chooseCanonical(PDO $blue,array $rows): array
    {
        $keys=array_column($rows,'raw');
        if($keys&&$this->tableExists($blue,'p2k_tp_members')){
            // Core 16 member storage is trusted, but avoid depending on optional historical
            // timestamp columns: current-member preference is sufficient to select the
            // present name from a trusted MIAC component.
            $ph=implode(',',array_fill(0,count($keys),'?'));$q=$blue->prepare("SELECT username_key,username,current_member FROM p2k_tp_members WHERE username_key IN ({$ph}) ORDER BY current_member DESC,username_key");$q->execute($keys);$r=$q->fetch();if(is_array($r))return ['raw'=>self::key((string)$r['username_key']),'username'=>(string)($r['username']??$r['username_key'])];
        }
        return end($rows)?:['raw'=>$keys[0]??'','username'=>$keys[0]??''];
    }

    private function tableExists(PDO $pdo,string $name): bool{$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$name]);return (int)$q->fetchColumn()>0;}
    private static function key(string $v): string{return strtolower(trim($v));}
    private static function pid($v): ?int{return is_numeric($v)&&(int)$v>0?(int)$v:null;}

    private function componentsFromEdges(array $edges): array
    {
        $parent=[];$find=function($x)use(&$parent,&$find){$parent[$x]??=$x;return $parent[$x]===$x?$x:($parent[$x]=$find($parent[$x]));};$union=function($a,$b)use(&$parent,$find){$ra=$find($a);$rb=$find($b);if($ra!==$rb)$parent[$ra]=$rb;};
        foreach($edges as $e){$union($e['old'],$e['new']);}
        $groups=[];foreach(array_keys($parent) as $k)$groups[$find($k)][]=$k;return array_values($groups);
    }

    public function importJson(array $payload): array
    {
        $m=[];$e=[];
        foreach(is_array($payload['mappings']??null)?$payload['mappings']:[] as $r){if(!is_array($r))continue;$raw=self::key((string)($r['username_key']??$r['raw']??''));$can=self::key((string)($r['canonical_username_key']??$r['canonical']??''));if(!$raw||!$can)continue;$m[]=['username'=>(string)($r['username']??$raw),'canonical_key'=>$can,'canonical_username'=>(string)($r['canonical_username']??$can),'player_id'=>self::pid($r['player_id']??null),'component'=>(string)($r['component_key']??''),'source_ref'=>'json'];}
        foreach(is_array($payload['edges']??null)?$payload['edges']:[] as $r){if(!is_array($r))continue;$old=self::key((string)($r['old']??$r['old_username_key']??''));$new=self::key((string)($r['new']??$r['new_username_key']??''));if($old&&$new&&$old!==$new)$e[]=['old'=>$old,'new'=>$new,'source_ref'=>'json'];}
        // Also accept the MIAC POC/tree shape: chains:[{members:[...],current:[...]}]
        foreach(is_array($payload['chains']??null)?$payload['chains']:[] as $chain){if(!is_array($chain))continue;$members=array_values(array_filter(array_map(fn($x)=>self::key((string)$x),(array)($chain['members']??[]))));if(!$members)continue;$current=array_values(array_filter(array_map(fn($x)=>self::key((string)$x),(array)($chain['current']??[]))));$can=end($current)?:end($members);foreach($members as $member)$m[]=['username'=>$member,'canonical_key'=>$can,'canonical_username'=>$can,'player_id'=>null,'component'=>'json-chain:'.$can,'source_ref'=>'json_chain'];for($i=1;$i<count($members);$i++)$e[]=['old'=>$members[$i-1],'new'=>$members[$i],'source_ref'=>'json_chain'];}
        if(!$m&&!$e)throw new RuntimeException('Identity JSON contains no recognized mappings, edges or chains.');
        return $this->store($m,$e,'trusted_identity_json',['input_keys'=>array_keys($payload)]);
    }

    private function store(array $mappings,array $edges,string $source,array $details): array
    {
        $core=$this->green->core;$core->beginTransaction();try{
            $im=$core->prepare("INSERT INTO p2k_g_identity_map(username_key,username,canonical_username_key,canonical_username,chess_player_id,component_key,source,trusted,source_ref,imported_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),canonical_username_key=VALUES(canonical_username_key),canonical_username=VALUES(canonical_username),chess_player_id=COALESCE(VALUES(chess_player_id),chess_player_id),component_key=VALUES(component_key),source=VALUES(source),trusted=VALUES(trusted),source_ref=VALUES(source_ref),updated_at=UTC_TIMESTAMP()");
            $mapCount=0;foreach($mappings as $r){$raw=self::key((string)($r['raw']??$r['username_key']??$r['username']??''));$can=self::key((string)($r['canonical_key']??''));if(!$raw||!$can)continue;$im->execute([$raw,(string)($r['username']??$raw),$can,(string)($r['canonical_username']??$can),$r['player_id']??null,($r['component']??'')?:null,$source,1,$r['source_ref']??null]);$mapCount++;}
            $ie=$core->prepare("INSERT INTO p2k_g_identity_edges(old_username_key,new_username_key,status,source,source_ref,evidence_json,imported_at) VALUES(?,?,'trusted',?,?,NULL,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status='trusted',source=VALUES(source),source_ref=VALUES(source_ref),imported_at=UTC_TIMESTAMP()");
            $edgeCount=0;foreach($edges as $r){$old=self::key((string)($r['old']??''));$new=self::key((string)($r['new']??''));if(!$old||!$new||$old===$new)continue;$ie->execute([$old,$new,$source,$r['source_ref']??null]);$edgeCount++;}
            $hash=hash('sha256',json_encode([$mappings,$edges],JSON_UNESCAPED_SLASHES));$core->prepare('INSERT INTO p2k_g_identity_imports(source,source_table,detected_shape,mapping_count,edge_count,source_hash,details_json) VALUES(?,?,?,?,?,?,?)')->execute([$source,null,'auto_or_json',$mapCount,$edgeCount,$hash,json_encode($details,JSON_UNESCAPED_SLASHES)]);
            $core->commit();$this->green->rebuildAnalytics((int)($this->green->state()['cycle_no']??0));return ['source'=>$source,'mapping_count'=>$mapCount,'edge_count'=>$edgeCount,'source_hash'=>$hash];
        }catch(\Throwable $e){if($core->inTransaction())$core->rollBack();throw $e;}
    }

    public function status(): array
    {
        $q=$this->green->core->query('SELECT COUNT(*) mappings,COALESCE(SUM(trusted=1),0) trusted FROM p2k_g_identity_map');$map=$q->fetch()?:[];$e=(int)$this->green->core->query('SELECT COUNT(*) FROM p2k_g_identity_edges')->fetchColumn();$last=$this->green->core->query('SELECT * FROM p2k_g_identity_imports ORDER BY import_id DESC LIMIT 1')->fetch()?:null;
        return ['mappings'=>(int)($map['mappings']??0),'trusted_mappings'=>(int)($map['trusted']??0),'edges'=>$e,'last_import'=>$last];
    }
}
