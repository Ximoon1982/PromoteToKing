<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use P2K\Shared\FilesystemCache;

/**
 * Bounded filesystem store for short-lived ACAMR claims and member leases.
 *
 * New state is stored in a fixed 256-shard namespace (at most 256 claim-ledger
 * files + 256 member-ledger files) rather than one file per token/member.
 * Legacy claim-*.json/member-*.json receipts remain readable long enough to
 * expire and are removed opportunistically/through housekeeping.
 */
final class AcamrClaimStore
{
    private const SHARDS = 256;
    private string $root;

    public function __construct(array $storage=[])
    {
        $this->root=FilesystemCache::runtimeRoot($storage).'/acamr';
        FilesystemCache::ensureProtectedDirectory($this->root);
    }

    public function root():string{return $this->root;}

    /** Shared fixed-window archive budget used by ACAMR and Continuous Refresh. */
    public function claimArchiveSlot(int $windowSeconds=600,int $cap=12):bool
    {
        $windowSeconds=max(60,min(3600,$windowSeconds));$cap=max(1,min(100,$cap));$path=$this->root.'/archive-acquisition-budget.json';$now=time();$accepted=false;
        $handle=@fopen($path,'c+');if(!$handle)return false;
        try{
            if(!@flock($handle,LOCK_EX))return false;
            rewind($handle);$raw=stream_get_contents($handle);$state=is_string($raw)&&$raw!==''?json_decode($raw,true):null;
            $started=is_array($state)?(int)($state['window_started']??0):0;$used=is_array($state)?(int)($state['used']??0):0;
            if($started<=0||$now-$started>=$windowSeconds){$started=$now;$used=0;}
            if($used<$cap){$used++;$accepted=true;}
            ftruncate($handle,0);rewind($handle);fwrite($handle,json_encode(['window_started'=>$started,'used'=>$used,'window_seconds'=>$windowSeconds,'cap'=>$cap],JSON_UNESCAPED_SLASHES));fflush($handle);@chmod($path,0600);
        } finally {@flock($handle,LOCK_UN);@fclose($handle);}
        return $accepted;
    }

    /**
     * Bounded cleanup. Producers can pass small limits; housekeeping uses the
     * defaults to drain legacy one-file-per-item state aggressively.
     *
     * @return array<string,int>
     */
    public function cleanup(
        int $legacyClaimLimit=20000,
        int $legacyMemberLimit=20000,
        int $memberMaxAgeSeconds=86400,
        int $ledgerFileLimit=512
    ):array {
        $now=time();
        $claimLedger=$this->cleanupLedgerFiles('claims',$now,0,max(1,$ledgerFileLimit));
        $memberLedger=$this->cleanupLedgerFiles('members',$now,max(3600,$memberMaxAgeSeconds),max(1,$ledgerFileLimit));
        $legacyClaims=$this->cleanupLegacyClaims($now,max(1,$legacyClaimLimit));
        $legacyMembers=$this->cleanupLegacyMembers($now,max(3600,$memberMaxAgeSeconds),max(1,$legacyMemberLimit));
        return [
            'claim_ledger_files'=>$claimLedger['files'],
            'claim_entries'=>$claimLedger['entries'],
            'claim_entries_removed'=>$claimLedger['removed'],
            'member_ledger_files'=>$memberLedger['files'],
            'member_entries'=>$memberLedger['entries'],
            'member_entries_removed'=>$memberLedger['removed'],
            'legacy_claim_files_removed'=>$legacyClaims['removed'],
            'legacy_claim_files_remaining_hint'=>$legacyClaims['remaining_hint'],
            'legacy_member_files_removed'=>$legacyMembers['removed'],
            'legacy_member_files_remaining_hint'=>$legacyMembers['remaining_hint'],
        ];
    }

    /**
     * Claim one member without producing a per-member file.
     * A recent legacy member lease is honored during migration.
     */
    public function claimMember(array $row,int $claimTtl,string $actor,string $mode):bool
    {
        $key=(string)($row['username_key']??'');
        if($key==='')return false;
        $claimTtl=max(60,$claimTtl);$now=time();
        $legacy=$this->legacyMemberPath($key);
        if(is_file($legacy)){
            $handle=@fopen($legacy,'c+');
            if($handle){
                try{
                    if(@flock($handle,LOCK_EX)){
                        rewind($handle);$raw=stream_get_contents($handle);$previous=is_string($raw)&&$raw!==''?json_decode($raw,true):null;
                        $claimedAt=is_array($previous)?(int)($previous['claimed_at']??0):0;
                        if($claimedAt>0&&$now-$claimedAt<$claimTtl)return false;
                    }
                } finally {@flock($handle,LOCK_UN);@fclose($handle);}
            }
        }

        $id=hash('sha256',$key);$path=$this->ledgerPath('members',$id);
        $accepted=false;
        $this->mutateLedger($path,function(array &$entries) use($id,$key,$claimTtl,$actor,$mode,$now,&$accepted):void {
            $cut=$now-max(3600,$claimTtl*4);
            foreach($entries as $entryId=>$entry){if(!is_array($entry)||(int)($entry['claimed_at']??0)<$cut)unset($entries[$entryId]);}
            $previous=is_array($entries[$id]??null)?$entries[$id]:null;
            $claimedAt=is_array($previous)?(int)($previous['claimed_at']??0):0;
            if($claimedAt>0&&$now-$claimedAt<$claimTtl){$accepted=false;return;}
            $entries[$id]=['username_key'=>$key,'actor_hash'=>substr(hash('sha256',strtolower($actor)),0,16),'mode'=>$mode,'claimed_at'=>$now];
            $accepted=true;
        });
        if($accepted&&is_file($legacy))@unlink($legacy);
        return $accepted;
    }

    /** @param list<array{kind:string,url:string}> $tasks */
    public function issue(?string $username,array $tasks,int $claimTtl,string $actor,string $mode):string
    {
        $token=bin2hex(random_bytes(24));$taskRows=[];
        foreach($tasks as $task){if(!is_array($task))continue;$kind=strtolower(trim((string)($task['kind']??'')));$url=trim((string)($task['url']??''));if($kind!==''&&$url!=='')$taskRows[]=['kind'=>$kind,'url'=>$url];}
        $now=time();$claimTtl=max(60,$claimTtl);$id=hash('sha256',$token);$path=$this->ledgerPath('claims',$id);
        $record=['username'=>$username,'username_key'=>$username?\p2k_tp_username_key($username):'','actor_hash'=>substr(hash('sha256',strtolower($actor)),0,16),'mode'=>$mode,'issued_at'=>$now,'expires_at'=>$now+$claimTtl,'tasks'=>$taskRows];
        $this->mutateLedger($path,function(array &$entries) use($id,$record,$now):void {
            foreach($entries as $entryId=>$entry){if(!is_array($entry)||(int)($entry['expires_at']??0)<$now-60)unset($entries[$entryId]);}
            $entries[$id]=$record;
        });
        return $token;
    }

    /** @return array{verified:bool,reason:string,username?:string,username_key?:string} */
    public function verify(string $token,string $url,string $kind):array
    {
        $token=trim($token);$kind=strtolower(trim($kind));if(!preg_match('/^[a-f0-9]{48}$/',$token))return ['verified'=>false,'reason'=>'missing_or_invalid_claim_token'];
        $id=hash('sha256',$token);$path=$this->ledgerPath('claims',$id);$claim=null;$now=time();
        if(is_file($path)){
            $this->mutateLedger($path,function(array &$entries) use($id,$now,&$claim):void {
                $candidate=is_array($entries[$id]??null)?$entries[$id]:null;
                if(is_array($candidate)&&(int)($candidate['expires_at']??0)>=$now){$claim=$candidate;return;}
                if(array_key_exists($id,$entries))unset($entries[$id]);
            },false);
        }
        // Rolling migration: legacy receipt is still accepted until it expires.
        if(!is_array($claim)){
            $legacy=$this->legacyClaimPath($token);$raw=@file_get_contents($legacy);$candidate=is_string($raw)&&$raw!==''?json_decode($raw,true):null;
            if(is_array($candidate)){
                if((int)($candidate['expires_at']??0)<$now){@unlink($legacy);return ['verified'=>false,'reason'=>'claim_expired'];}
                $claim=$candidate;
            }
        }
        if(!is_array($claim))return ['verified'=>false,'reason'=>'claim_not_found'];
        foreach(is_array($claim['tasks']??null)?$claim['tasks']:[] as $task){if(!is_array($task))continue;if(hash_equals((string)($task['url']??''),$url)&&hash_equals(strtolower((string)($task['kind']??'')),$kind))return ['verified'=>true,'reason'=>'claim_task_match','username'=>(string)($claim['username']??''),'username_key'=>(string)($claim['username_key']??'')];}
        return ['verified'=>false,'reason'=>'claim_task_mismatch'];
    }

    private function ledgerPath(string $kind,string $id):string
    {
        $prefix=substr($id,0,2);
        return $this->root.'/'.$kind.'-'.$prefix.'.json';
    }

    /**
     * Lock/read/mutate/rewrite one shard. If the shard becomes empty it is
     * retained even when empty. Keeping fixed shard files avoids unlink/recreate races
     * between concurrent planners while the namespace remains strictly bounded.
     *
     * @param callable(array<string,mixed>&):void $mutator
     */
    private function mutateLedger(string $path,callable $mutator,bool $create=true):void
    {
        if(!$create&&!is_file($path))return;
        $handle=@fopen($path,$create?'c+':'r+');if(!$handle)return;
        try{
            if(!@flock($handle,LOCK_EX))return;
            rewind($handle);$raw=stream_get_contents($handle);$decoded=is_string($raw)&&$raw!==''?json_decode($raw,true):null;
            $entries=is_array($decoded['entries']??null)?$decoded['entries']:[];
            $mutator($entries);
            $payload=json_encode(['version'=>1,'entries'=>$entries],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            ftruncate($handle,0);rewind($handle);fwrite($handle,$payload);fflush($handle);@chmod($path,0600);
        } finally {@flock($handle,LOCK_UN);@fclose($handle);}
    }

    /** @return array{files:int,entries:int,removed:int} */
    private function cleanupLedgerFiles(string $kind,int $now,int $memberMaxAgeSeconds,int $fileLimit):array
    {
        $files=glob($this->root.'/'.$kind.'-??.json')?:[];sort($files,SORT_STRING);$files=array_slice($files,0,$fileLimit);$entriesTotal=0;$removed=0;$filesRemaining=0;
        foreach($files as $path){$before=0;$after=0;$this->mutateLedger($path,function(array &$entries) use($kind,$now,$memberMaxAgeSeconds,&$before,&$after):void {
            $before=count($entries);
            foreach($entries as $id=>$entry){$expired=$kind==='claims' ? (!is_array($entry)||(int)($entry['expires_at']??0)<$now-60) : (!is_array($entry)||(int)($entry['claimed_at']??0)<$now-$memberMaxAgeSeconds);if($expired)unset($entries[$id]);}
            $after=count($entries);
        },false);$removed+=max(0,$before-$after);$entriesTotal+=$after;if($after>0)$filesRemaining++;}
        return ['files'=>$filesRemaining,'entries'=>$entriesTotal,'removed'=>$removed];
    }

    /** @return array{removed:int,remaining_hint:int} */
    private function cleanupLegacyClaims(int $now,int $limit):array
    {
        $files=glob($this->root.'/claim-*.json')?:[];$removed=0;$scanned=0;
        foreach($files as $path){if($scanned++>=$limit)break;$raw=@file_get_contents($path);$row=is_string($raw)&&$raw!==''?json_decode($raw,true):null;if(!is_array($row)||(int)($row['expires_at']??0)<$now-60){if(@unlink($path))$removed++;}}
        return ['removed'=>$removed,'remaining_hint'=>max(0,count($files)-$removed)];
    }

    /** @return array{removed:int,remaining_hint:int} */
    private function cleanupLegacyMembers(int $now,int $maxAgeSeconds,int $limit):array
    {
        $files=glob($this->root.'/member-*.json')?:[];$removed=0;$scanned=0;
        foreach($files as $path){if($scanned++>=$limit)break;$handle=@fopen($path,'c+');if(!$handle)continue;$delete=false;try{if(!@flock($handle,LOCK_EX|LOCK_NB))continue;rewind($handle);$raw=stream_get_contents($handle);$row=is_string($raw)&&$raw!==''?json_decode($raw,true):null;$delete=!is_array($row)||(int)($row['claimed_at']??0)<$now-$maxAgeSeconds;}finally{@flock($handle,LOCK_UN);@fclose($handle);}if($delete&&@unlink($path))$removed++;}
        return ['removed'=>$removed,'remaining_hint'=>max(0,count($files)-$removed)];
    }

    private function legacyClaimPath(string $token):string{return $this->root.'/claim-'.hash('sha256',$token).'.json';}
    private function legacyMemberPath(string $key):string{return $this->root.'/member-'.hash('sha256',$key).'.json';}
}
