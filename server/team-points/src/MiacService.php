<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

final class MiacService
{
    public function __construct(private readonly PDO $core, private readonly string $clubSlug)
    {
    }

    public function generation(): int
    {
        $this->ensureState();
        $q=$this->core->prepare('SELECT identity_map_generation FROM p2k_miac_state WHERE club_slug=?');
        $q->execute([$this->clubSlug]);
        return max(1,(int)($q->fetchColumn()?:1));
    }

    private function ensureState(): void
    {
        $q=$this->core->prepare("INSERT IGNORE INTO p2k_miac_state(club_slug,identity_map_generation,updated_at) VALUES(?,1,UTC_TIMESTAMP())");
        $q->execute([$this->clubSlug]);
    }

    public function importSeedIfNeeded(?string $path=null): array
    {
        $this->ensureState();
        $dataSeed=dirname(__DIR__,3).'/data/miac/seed/miac-seed.json';$resourceSeed=dirname(__DIR__,3).'/resources/miac/miac-seed.json';
        $path=$path?:((is_file($dataSeed)?$dataSeed:$resourceSeed));
        if(!is_file($path)) return ['imported'=>false,'reason'=>'seed_missing'];
        $seed=json_decode((string)file_get_contents($path),true);
        if(!is_array($seed)||($seed['format']??'')!=='p2k-miac-seed-v1') throw new \RuntimeException('MIAC seed is invalid.');
        $sha=(string)($seed['source_archive_sha256']??'');
        $q=$this->core->prepare('SELECT seed_archive_sha256 FROM p2k_miac_state WHERE club_slug=?');$q->execute([$this->clubSlug]);
        if($sha!=='' && hash_equals($sha,(string)($q->fetchColumn()?:''))) return ['imported'=>false,'reason'=>'already_imported','generation'=>$this->generation()];

        $names=[];
        $addName=static function(array &$names,string $name,array $meta=[]):void{
            $name=trim($name);$key=\p2k_tp_username_key($name);if($key==='')return;
            $row=$names[$key]??['username'=>$name,'joined_epoch'=>null,'current_member'=>false,'first_seen'=>null,'last_seen'=>null,'flags'=>[]];
            if(!empty($meta['joined']))$row['joined_epoch']=(int)$meta['joined'];
            if(!empty($meta['joined_epoch']))$row['joined_epoch']=(int)$meta['joined_epoch'];
            if(!empty($meta['current'])||($meta['status']??'')==='current')$row['current_member']=true;
            foreach(['first_seen','first','old_first','new_first'] as $f)if(!empty($meta[$f])&&($row['first_seen']===null||strcmp((string)$meta[$f],$row['first_seen'])<0))$row['first_seen']=(string)$meta[$f];
            foreach(['last_seen','last','old_last','new_last'] as $f)if(!empty($meta[$f])&&($row['last_seen']===null||strcmp((string)$meta[$f],$row['last_seen'])>0))$row['last_seen']=(string)$meta[$f];
            $row['flags']['seed']=true;$names[$key]=$row;
        };
        foreach(($seed['chains']??[]) as $ci=>$chain){
            foreach(($chain['member_meta']??[]) as $m)$addName($names,(string)($m['name']??''),$m);
            foreach(($chain['members']??[]) as $name)$addName($names,(string)$name,['current'=>in_array($name,$chain['current']??[],true)]);
        }
        foreach(($seed['candidates']??[]) as $c){$addName($names,(string)($c['old']??''),$c);$addName($names,(string)($c['new']??''),$c);}
        foreach(($seed['board_only']??[]) as $m){ if(is_array($m))$addName($names,(string)($m['name']??$m['username']??''),$m); else $addName($names,(string)$m); }
        foreach(($seed['former']??[]) as $m){ if(is_array($m))$addName($names,(string)($m['name']??$m['username']??''),$m); else $addName($names,(string)$m); }

        $this->core->beginTransaction();
        try{
            $nq=$this->core->prepare("INSERT INTO p2k_miac_names(club_slug,username_key,username,joined_epoch,current_member,first_seen_at,last_seen_at,source_flags,updated_at) VALUES(?,?,?,?,?,?,?,'seed',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),joined_epoch=COALESCE(joined_epoch,VALUES(joined_epoch)),current_member=GREATEST(current_member,VALUES(current_member)),first_seen_at=CASE WHEN first_seen_at IS NULL THEN VALUES(first_seen_at) WHEN VALUES(first_seen_at) IS NULL THEN first_seen_at ELSE LEAST(first_seen_at,VALUES(first_seen_at)) END,last_seen_at=CASE WHEN last_seen_at IS NULL THEN VALUES(last_seen_at) WHEN VALUES(last_seen_at) IS NULL THEN last_seen_at ELSE GREATEST(last_seen_at,VALUES(last_seen_at)) END,source_flags=CASE WHEN source_flags IS NULL OR source_flags='' THEN 'seed' WHEN LOCATE('seed',source_flags)=0 THEN CONCAT(source_flags,',seed') ELSE source_flags END");
            foreach($names as $key=>$n){$nq->execute([$this->clubSlug,$key,$n['username'],$n['joined_epoch'],$n['current_member']?1:0,$this->sqlTime($n['first_seen']),$this->sqlTime($n['last_seen'])]);}
            $eq=$this->core->prepare("INSERT INTO p2k_miac_edges(club_slug,old_username_key,new_username_key,confidence,shared_boards,same_joined,roster_handover,coexists,status,evidence_source,evidence_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?, 'candidate','seed',?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE confidence=VALUES(confidence),shared_boards=VALUES(shared_boards),same_joined=VALUES(same_joined),roster_handover=VALUES(roster_handover),coexists=VALUES(coexists),evidence_json=VALUES(evidence_json),updated_at=UTC_TIMESTAMP()");
            foreach(($seed['candidates']??[]) as $c){$old=\p2k_tp_username_key((string)($c['old']??''));$new=\p2k_tp_username_key((string)($c['new']??''));if($old===''||$new===''||$old===$new)continue;$eq->execute([$this->clubSlug,$old,$new,(string)($c['confidence']??'Possible'),(int)($c['shared_boards']??0),!empty($c['same_joined'])?1:0,!empty($c['handover'])?1:0,!empty($c['coexists'])?1:0,json_encode($c,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);}
            $sq=$this->core->prepare("UPDATE p2k_miac_state SET seed_format=?,seed_archive_sha256=?,seed_imported_at=UTC_TIMESTAMP(),last_change_reason='seed_import',updated_at=UTC_TIMESTAMP() WHERE club_slug=?");$sq->execute([(string)$seed['format'],$sha,$this->clubSlug]);
            $this->core->commit();
        }catch(\Throwable $e){if($this->core->inTransaction())$this->core->rollBack();throw $e;}
        $this->rebuildCanonicalMap(false,'seed_import');
        return ['imported'=>true,'names'=>count($names),'candidates'=>count($seed['candidates']??[]),'generation'=>$this->generation(),'archive_sha256'=>$sha];
    }

    private function sqlTime(?string $v): ?string
    {
        if(!$v)return null;$t=strtotime($v);return $t===false?null:gmdate('Y-m-d H:i:s',$t);
    }

    public function observePlayerId(string $username,int $playerId): array
    {
        $key=\p2k_tp_username_key($username);if($key===''||$playerId<=0)return ['changed'=>false];
        $this->ensureName($username);
        $q=$this->core->prepare('SELECT player_id FROM p2k_miac_names WHERE club_slug=? AND username_key=?');$q->execute([$this->clubSlug,$key]);$old=$q->fetchColumn();
        if($old!==false&&$old!==null&&(int)$old>0&&(int)$old!==$playerId){
            $u=$this->core->prepare('UPDATE p2k_miac_names SET hard_conflict=1,player_id_checked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND username_key=?');$u->execute([$this->clubSlug,$key]);
            $this->bumpGeneration('player_id_conflict');$this->rebuildCanonicalMap(false,'player_id_conflict');
            return ['changed'=>true,'conflict'=>true,'old_player_id'=>(int)$old,'new_player_id'=>$playerId,'generation'=>$this->generation()];
        }
        if((int)$old===$playerId)return ['changed'=>false,'generation'=>$this->generation()];
        $u=$this->core->prepare('UPDATE p2k_miac_names SET player_id=?,player_id_checked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND username_key=?');$u->execute([$playerId,$this->clubSlug,$key]);
        $m=$this->core->prepare('UPDATE p2k_tp_members SET player_id=? WHERE club_slug=? AND username_key=? AND (player_id IS NULL OR player_id=?)');$m->execute([$playerId,$this->clubSlug,$key,$playerId]);
        $peer=$this->core->prepare('SELECT COUNT(*) FROM p2k_miac_names WHERE club_slug=? AND player_id=? AND username_key<>? AND hard_conflict=0');$peer->execute([$this->clubSlug,$playerId,$key]);
        $topologyChanged=(int)$peer->fetchColumn()>0;
        if($topologyChanged){$this->bumpGeneration('player_id_link');$this->rebuildCanonicalMap(false,'player_id_link');}
        return ['changed'=>true,'topology_changed'=>$topologyChanged,'generation'=>$this->generation()];
    }

    public function resolve(string $username): array
    {
        $key=\p2k_tp_username_key($username);if($key==='')return ['raw_username_key'=>'','canonical_username_key'=>'','canonical_username'=>'','reason'=>'self','conflict'=>false,'generation'=>$this->generation()];
        $this->ensureName($username);
        $q=$this->core->prepare('SELECT canonical_username_key,canonical_username,resolution_reason,conflict,identity_map_generation FROM p2k_miac_canonical_map WHERE club_slug=? AND username_key=?');$q->execute([$this->clubSlug,$key]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($r)){$this->rebuildCanonicalMap(false,'resolve_missing_map');$q->execute([$this->clubSlug,$key]);$r=$q->fetch(PDO::FETCH_ASSOC);}
        return ['raw_username_key'=>$key,'canonical_username_key'=>(string)($r['canonical_username_key']??$key),'canonical_username'=>(string)($r['canonical_username']??$username),'reason'=>(string)($r['resolution_reason']??'self'),'conflict'=>(bool)($r['conflict']??false),'generation'=>(int)($r['identity_map_generation']??$this->generation())];
    }

    private function ensureName(string $username): void
    {
        $key=\p2k_tp_username_key($username);if($key==='')return;$this->ensureState();
        $m=$this->core->prepare('SELECT username,current_member,UNIX_TIMESTAMP(joined_at) joined_epoch,first_seen_at,last_seen_at,player_id FROM p2k_tp_members WHERE club_slug=? AND username_key=? LIMIT 1');$m->execute([$this->clubSlug,$key]);$row=$m->fetch(PDO::FETCH_ASSOC)?:[];
        $q=$this->core->prepare("INSERT INTO p2k_miac_names(club_slug,username_key,username,player_id,joined_epoch,current_member,first_seen_at,last_seen_at,source_flags,updated_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),player_id=COALESCE(player_id,VALUES(player_id)),current_member=IF(VALUES(source_flags)='core_member',VALUES(current_member),current_member),last_seen_at=COALESCE(VALUES(last_seen_at),last_seen_at),updated_at=UTC_TIMESTAMP()");
        $q->execute([$this->clubSlug,$key,(string)($row['username']??$username),isset($row['player_id'])?(int)$row['player_id']:null,isset($row['joined_epoch'])?(int)$row['joined_epoch']:null,!empty($row['current_member'])?1:0,$row['first_seen_at']??null,$row['last_seen_at']??null,$row?'core_member':'mca_source']);
    }

    public function reviewEdge(string $old,string $new,string $decision,string $reviewer='admin'): array
    {
        $old=\p2k_tp_username_key($old);$new=\p2k_tp_username_key($new);$decision=strtolower($decision);
        if(!in_array($decision,['confirmed','rejected'],true)||$old===''||$new==='')throw new \InvalidArgumentException('Invalid MIAC review.');
        $q=$this->core->prepare('SELECT edge_id,status FROM p2k_miac_edges WHERE club_slug=? AND old_username_key=? AND new_username_key=?');$q->execute([$this->clubSlug,$old,$new]);$edge=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($edge))throw new \RuntimeException('MIAC edge not found.');
        if($decision==='confirmed' && $this->edgePlayerIdConflict($old,$new))$decision='conflict';
        if((string)$edge['status']===$decision)return ['status'=>$decision,'generation'=>$this->generation(),'changed'=>false];
        $u=$this->core->prepare('UPDATE p2k_miac_edges SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND old_username_key=? AND new_username_key=?');$u->execute([$decision,substr($reviewer,0,80),$this->clubSlug,$old,$new]);
        $this->bumpGeneration('edge_'.$decision);$this->rebuildCanonicalMap(false,'edge_'.$decision);
        return ['status'=>$decision,'generation'=>$this->generation(),'changed'=>true];
    }

    private function edgePlayerIdConflict(string $old,string $new): bool
    {
        $q=$this->core->prepare('SELECT username_key,player_id FROM p2k_miac_names WHERE club_slug=? AND username_key IN (?,?)');$q->execute([$this->clubSlug,$old,$new]);$ids=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)if(!empty($r['player_id']))$ids[(int)$r['player_id']]=true;return count($ids)>1;
    }

    /** Candidate edges backed by the same physical Daily board are authoritative historical identity evidence.
     * Explicitly rejected edges are never touched; contradictory known player IDs become conflicts.
     * All changes in one pass share one generation bump.
     */
    public function applyAutomaticEvidencePolicy(): array
    {
        $q=$this->core->prepare("SELECT old_username_key,new_username_key,status FROM p2k_miac_edges WHERE club_slug=? AND status='candidate' AND shared_boards>0 ORDER BY edge_id");
        $q->execute([$this->clubSlug]);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        if($rows===[])return ['changed'=>0,'confirmed'=>0,'conflicts'=>0,'generation'=>$this->generation()];
        $confirmed=0;$conflicts=0;$changed=0;
        $u=$this->core->prepare("UPDATE p2k_miac_edges SET status=?,reviewed_by='auto:shared_board',reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND old_username_key=? AND new_username_key=? AND status='candidate'");
        foreach($rows as $row){$old=(string)$row['old_username_key'];$new=(string)$row['new_username_key'];$status=$this->edgePlayerIdConflict($old,$new)?'conflict':'confirmed';$u->execute([$status,$this->clubSlug,$old,$new]);if($u->rowCount()>0){$changed++;if($status==='confirmed')$confirmed++;else$conflicts++;}}
        if($changed>0){$this->bumpGeneration('auto_shared_board');$this->rebuildCanonicalMap(false,'auto_shared_board');}
        return compact('changed','confirmed','conflicts')+['generation'=>$this->generation()];
    }

    /** Persist definitive old→new historical substitution evidence before the caller replaces source ownership.
     * Supported sources are MCA same-arena substitution and Daily same-physical-board substitution.
     */
    public function recordDefinitiveSubstitution(string $oldUsername,string $newUsername,string $source,array $evidence=[]): array
    {
        $old=\p2k_tp_username_key($oldUsername);$new=\p2k_tp_username_key($newUsername);$source=strtolower(trim($source));
        if($old===''||$new===''||$old===$new)throw new \InvalidArgumentException('Invalid definitive MIAC substitution.');
        if(!in_array($source,['mca_substitution','daily_board_substitution'],true))throw new \InvalidArgumentException('Unsupported MIAC substitution source.');
        $this->ensureName($oldUsername);$this->ensureName($newUsername);
        $q=$this->core->prepare('SELECT edge_id,status,evidence_json FROM p2k_miac_edges WHERE club_slug=? AND old_username_key=? AND new_username_key=?');$q->execute([$this->clubSlug,$old,$new]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        // Human rejection is an explicit veto. Keep the evidence attached but never auto-reverse it.
        $existingStatus=is_array($existing)?(string)$existing['status']:'';
        $status=$existingStatus==='rejected'?'rejected':($this->edgePlayerIdConflict($old,$new)?'conflict':'confirmed');
        $prior=[];if(is_array($existing)&&!empty($existing['evidence_json'])){$decoded=json_decode((string)$existing['evidence_json'],true);if(is_array($decoded))$prior=$decoded;}
        $history=is_array($prior['definitive_history']??null)?$prior['definitive_history']:[];$history[]=['source'=>$source,'observed_at'=>gmdate(DATE_ATOM)]+$evidence;
        $payload=$prior+$evidence;$payload['definitive_history']=$history;$payload['definitive_source']=$source;$payload['one_to_one']=true;
        $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(is_array($existing)){
            $u=$this->core->prepare('UPDATE p2k_miac_edges SET confidence=?,shared_boards=GREATEST(shared_boards,?),status=?,evidence_source=?,evidence_json=?,reviewed_by=CASE WHEN ? IN (\'confirmed\',\'conflict\') THEN CONCAT(\'auto:\',?) ELSE reviewed_by END,reviewed_at=CASE WHEN ? IN (\'confirmed\',\'conflict\') THEN UTC_TIMESTAMP() ELSE reviewed_at END,updated_at=UTC_TIMESTAMP() WHERE edge_id=?');
            $u->execute(['Very strong',(int)($evidence['shared_boards']??($source==='daily_board_substitution'?1:0)),$status,$source,$json,$status,$source,$status,(int)$existing['edge_id']]);
        }else{
            $u=$this->core->prepare('INSERT INTO p2k_miac_edges(club_slug,old_username_key,new_username_key,confidence,shared_boards,same_joined,roster_handover,coexists,status,evidence_source,evidence_json,reviewed_by,reviewed_at,created_at,updated_at) VALUES(?,?,?,?,?,0,0,0,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $u->execute([$this->clubSlug,$old,$new,'Very strong',(int)($evidence['shared_boards']??($source==='daily_board_substitution'?1:0)),$status,$source,$json,$status==='rejected'?null:'auto:'.$source]);
        }
        $topologyChanged=$existingStatus!==$status && in_array($status,['confirmed','conflict'],true);
        if($topologyChanged){$this->bumpGeneration('definitive_'.$source);$this->rebuildCanonicalMap(false,'definitive_'.$source);}
        return ['status'=>$status,'changed'=>$topologyChanged,'generation'=>$this->generation(),'source'=>$source];
    }

    public function aliasesFor(string $username): array
    {
        $resolved=$this->resolve($username);$canonical=(string)$resolved['canonical_username_key'];
        $q=$this->core->prepare('SELECT username_key FROM p2k_miac_canonical_map WHERE club_slug=? AND canonical_username_key=? AND conflict=0 ORDER BY username_key');$q->execute([$this->clubSlug,$canonical]);
        $keys=array_values(array_unique(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)?:[])));if($keys===[])$keys=[\p2k_tp_username_key($username)];return $keys;
    }

    private function seedPayload(): array
    {
        $dataSeed=dirname(__DIR__,3).'/data/miac/seed/miac-seed.json';$resourceSeed=dirname(__DIR__,3).'/resources/miac/miac-seed.json';$path=is_file($dataSeed)?$dataSeed:$resourceSeed;
        if(!is_file($path))return [];$seed=json_decode((string)file_get_contents($path),true);return is_array($seed)?$seed:[];
    }

    private function bumpGeneration(string $reason): int
    {
        $this->ensureState();$q=$this->core->prepare('UPDATE p2k_miac_state SET identity_map_generation=identity_map_generation+1,last_change_reason=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=?');$q->execute([$reason,$this->clubSlug]);return $this->generation();
    }

    public function rebuildCanonicalMap(bool $bump=false,string $reason='map_rebuild'): array
    {
        if($bump)$this->bumpGeneration($reason);$gen=$this->generation();
        $q=$this->core->prepare('SELECT username_key,username,player_id,current_member,last_seen_at,hard_conflict FROM p2k_miac_names WHERE club_slug=?');$q->execute([$this->clubSlug]);$names=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$names[(string)$r['username_key']]=$r;
        $parent=[];foreach($names as $k=>$r)$parent[$k]=$k;
        $find=function($x)use(&$parent,&$find){if(($parent[$x]??$x)!==$x)$parent[$x]=$find($parent[$x]);return $parent[$x]??$x;};
        $union=function($a,$b)use(&$parent,&$find){$ra=$find($a);$rb=$find($b);if($ra!==$rb)$parent[$rb]=$ra;};
        $e=$this->core->prepare("SELECT old_username_key,new_username_key FROM p2k_miac_edges WHERE club_slug=? AND status='confirmed'");$e->execute([$this->clubSlug]);foreach($e->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)if(isset($parent[$r['old_username_key']],$parent[$r['new_username_key']]))$union($r['old_username_key'],$r['new_username_key']);
        $byPid=[];foreach($names as $k=>$r)if(!empty($r['player_id'])&&!$r['hard_conflict'])$byPid[(string)$r['player_id']][]=$k;foreach($byPid as $ks)for($i=1;$i<count($ks);$i++)$union($ks[0],$ks[$i]);
        $groups=[];foreach(array_keys($names) as $k)$groups[$find($k)][]=$k;
        $rows=[];
        foreach($groups as $ks){$ids=[];$hard=false;foreach($ks as $k){$r=$names[$k];if(!empty($r['player_id']))$ids[(string)$r['player_id']]=true;if(!empty($r['hard_conflict']))$hard=true;}$conflict=$hard||count($ids)>1;
            usort($ks,function($a,$b)use($names){$ra=$names[$a];$rb=$names[$b];$c=(int)$rb['current_member']<=>(int)$ra['current_member'];if($c)return$c;$la=strtotime((string)($ra['last_seen_at']??''))?:0;$lb=strtotime((string)($rb['last_seen_at']??''))?:0;return $lb<=>$la ?: strcmp($a,$b);});$canonical=$conflict?null:$ks[0];
            $reasonType=count($ks)>1?(count($ids)===1&&count($ids)>0?'player_id':'admin_confirmed'):'self';
            foreach($ks as $k){$ck=$conflict?$k:$canonical;$rows[]=[$this->clubSlug,$k,$ck,(string)$names[$ck]['username'],$conflict?'hard_conflict':$reasonType,$gen,$conflict?1:0];}
        }
        $this->core->beginTransaction();try{$this->core->prepare('DELETE FROM p2k_miac_canonical_map WHERE club_slug=?')->execute([$this->clubSlug]);$ins=$this->core->prepare('INSERT INTO p2k_miac_canonical_map(club_slug,username_key,canonical_username_key,canonical_username,resolution_reason,identity_map_generation,conflict,updated_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())');foreach($rows as $r)$ins->execute($r);$this->core->prepare('UPDATE p2k_miac_state SET map_rebuilt_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);$this->core->commit();}catch(\Throwable $x){if($this->core->inTransaction())$this->core->rollBack();throw$x;}
        return ['generation'=>$gen,'names'=>count($names),'components'=>count($groups),'conflicts'=>count(array_filter($rows,fn($r)=>$r[6]===1))];
    }

    public function summary(int $limit=500,string $search=''): array
    {
        $this->ensureState();$auto=$this->applyAutomaticEvidencePolicy();$gen=$this->generation();$search=trim($search);$params=[$this->clubSlug];$where='club_slug=?';if($search!==''){$where.=' AND (old_username_key LIKE ? OR new_username_key LIKE ?)';$params[]='%'.strtolower($search).'%';$params[]='%'.strtolower($search).'%';}
        $limit=max(1,min(500,$limit));$q=$this->core->prepare("SELECT old_username_key,new_username_key,confidence,shared_boards,same_joined,roster_handover,coexists,status,evidence_source,evidence_json,reviewed_by,reviewed_at FROM p2k_miac_edges WHERE {$where} ORDER BY FIELD(status,'conflict','candidate','confirmed','rejected'),FIELD(confidence,'Very strong','Strong','Possible'),shared_boards DESC LIMIT {$limit}");$q->execute($params);$edges=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($edges as &$edge){$decoded=json_decode((string)($edge['evidence_json']??''),true);$edge['evidence']=is_array($decoded)?$decoded:[];}unset($edge);
        $counts=$this->core->prepare('SELECT status,COUNT(*) n FROM p2k_miac_edges WHERE club_slug=? GROUP BY status');$counts->execute([$this->clubSlug]);$by=[];foreach($counts->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$by[$r['status']]=(int)$r['n'];
        $n=$this->core->prepare('SELECT COUNT(*) names,SUM(current_member=1) current_names,SUM(player_id IS NOT NULL) player_id_names,SUM(hard_conflict=1) conflicts FROM p2k_miac_names WHERE club_slug=?');$n->execute([$this->clubSlug]);$nr=$n->fetch(PDO::FETCH_ASSOC)?:[];
        $seed=$this->seedPayload();$seedSummary=is_array($seed['summary']??null)?$seed['summary']:[];$chains=is_array($seed['chains']??null)?$seed['chains']:[];
        $statusByEdge=[];foreach($edges as $edge)$statusByEdge[(string)$edge['old_username_key'].'|'.(string)$edge['new_username_key']]=['status'=>(string)$edge['status'],'validation_basis'=>(string)($edge['reviewed_by']?:$edge['evidence_source']),'shared_boards'=>(int)$edge['shared_boards']];
        foreach($chains as &$chain){$members=array_values(array_map('strval',$chain['members']??[]));$transitions=[];for($i=1;$i<count($members);$i++){$key=\p2k_tp_username_key($members[$i-1]).'|'.\p2k_tp_username_key($members[$i]);$transitions[]=['old'=>$members[$i-1],'new'=>$members[$i]]+($statusByEdge[$key]??['status'=>'candidate','validation_basis'=>'seed','shared_boards'=>0]);}$chain['transitions']=$transitions;}unset($chain);
        return ['generation'=>$gen,'names'=>(int)($nr['names']??0),'current_names'=>(int)($nr['current_names']??0),'player_id_names'=>(int)($nr['player_id_names']??0),'hard_conflict_names'=>(int)($nr['conflicts']??0),'edge_counts'=>$by,'edges'=>$edges,'chains'=>$chains,'seed_summary'=>$seedSummary,'automatic_policy'=>$auto];
    }

}
