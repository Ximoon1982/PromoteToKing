<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;
use RuntimeException;

final class PreviewIsolation
{
    public static function active(): bool
    {
        return trim((string)(getenv('P2K_PREVIEW_ACTIVE') ?: ($_SERVER['P2K_PREVIEW_ACTIVE'] ?? ''))) === '1';
    }

    public static function releaseId(): string
    {
        return trim((string)(getenv('P2K_PREVIEW_RELEASE') ?: ($_SERVER['P2K_PREVIEW_RELEASE'] ?? '')));
    }

    public static function username(): string
    {
        return strtolower(trim((string)(getenv('P2K_PREVIEW_USERNAME') ?: ($_SERVER['P2K_PREVIEW_USERNAME'] ?? ''))));
    }

    public static function adminReadAuthorized(): bool
    {
        if (!self::active()) return false;
        $flag = trim((string)(getenv('P2K_PREVIEW_ADMIN_AUTHORIZED') ?: ($_SERVER['P2K_PREVIEW_ADMIN_AUTHORIZED'] ?? '')));
        $authorized = strtolower(trim((string)(getenv('P2K_PREVIEW_ADMIN_USERNAME') ?: ($_SERVER['P2K_PREVIEW_ADMIN_USERNAME'] ?? ''))));
        return $flag === '1' && $authorized !== '' && hash_equals(self::username(), $authorized);
    }

    public static function sandboxRoot(): string
    {
        if (!self::active()) throw new RuntimeException('Preview sandbox requested outside candidate preview.');
        $path=rtrim(trim((string)(getenv('P2K_PREVIEW_SANDBOX') ?: ($_SERVER['P2K_PREVIEW_SANDBOX'] ?? ''))),'/\\');
        $publicRoot=rtrim(trim((string)(getenv('P2K_PREVIEW_PUBLIC_ROOT') ?: ($_SERVER['P2K_PREVIEW_PUBLIC_ROOT'] ?? ''))),'/\\');
        $normalized=str_replace('\\','/',$path);
        $rootNormalized=rtrim(str_replace('\\','/',$publicRoot),'/');
        $prefix=$rootNormalized.'/data/runtime-v280/release-control/preview-sandboxes/';
        if($path===''||$publicRoot===''||!str_starts_with($normalized,$prefix)) throw new RuntimeException('Candidate preview sandbox is unavailable or outside the protected preview root.');
        return $path;
    }

    public static function applyConfig(array $config): array
    {
        if(!self::active()) return $config;
        $sandbox=self::sandboxRoot(); $runtime=$sandbox.'/runtime';
        $storage=is_array($config['storage']??null)?$config['storage']:[];
        $storage['runtime_dir']=$runtime;
        $storage['cache_dir']=$runtime.'/cache/chesscom';
        $storage['logs_dir']=$runtime.'/logs';
        $storage['archive_dir']=$sandbox.'/archive';
        $config['storage']=$storage;
        $app=is_array($config['app']??null)?$config['app']:[];
        $app['cron_continuous_enabled']=false;
        $app['cron_self_url']='';
        $app['live_ranks_upload_dir']=$sandbox.'/uploads/live-ranks';
        $app['preview_read_only']=true;
        $config['app']=$app;
        $config['_preview']=['active'=>true,'release_id'=>self::releaseId(),'username'=>self::username(),'sandbox'=>$sandbox,'database_mode'=>'read-only'];
        return $config;
    }

    public static function enforceReadOnlyPdo(PDO $pdo): void
    {
        if(!self::active()) return;
        try{$pdo->exec('SET SESSION TRANSACTION READ ONLY');}
        catch(\Throwable $e){throw new RuntimeException('Candidate preview could not enforce a read-only database session.',0,$e);}
        $verified=false;
        foreach(['SELECT @@tx_read_only','SELECT @@transaction_read_only'] as $query){
            try{$verified=(int)$pdo->query($query)->fetchColumn()===1;break;}catch(\Throwable){continue;}
        }
        if(!$verified) throw new RuntimeException('Candidate preview database session did not verify as read-only.');
    }

    public static function sessionDirectory(string $kind): string
    {
        $kind=preg_replace('/[^a-z0-9_-]+/i','-',strtolower(trim($kind)))?:'session';
        $dir=self::sandboxRoot().'/sessions/'.$kind;
        self::ensureProtectedDirectory($dir);
        return $dir;
    }

    public static function mapWritablePath(string $path,string $siteRoot): string
    {
        if(!self::active()) return $path;
        $sandbox=self::sandboxRoot(); $normalized=str_replace('\\','/',$path); $root=rtrim(str_replace('\\','/',$siteRoot),'/');
        if($root!==''&&($normalized===$root||str_starts_with($normalized,$root.'/'))){
            return $sandbox.'/legacy-root/'.ltrim(substr($normalized,strlen($root)),'/');
        }
        return $sandbox.'/external/'.hash('sha256',$normalized).'-'.basename($normalized);
    }

    private static function ensureProtectedDirectory(string $dir): void
    {
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)) throw new RuntimeException('Unable to create candidate preview sandbox directory.');
        $deny=$dir.'/.htaccess';
        if(!is_file($deny)) @file_put_contents($deny,"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        $index=$dir.'/index.html'; if(!is_file($index)) @file_put_contents($index,'');
    }
}
