<?php
declare(strict_types=1);

namespace P2K\Shared;

/**
 * First-party, cookieless traffic analytics.
 *
 * Privacy contract:
 * - raw IP addresses and full referrer URLs are never persisted;
 * - no browser identifier is set or read;
 * - a rotating daily HMAC is used only in short-lived server state;
 * - aggregate files are compacted to remove pseudonymous visitor/session keys;
 * - query strings/fragments are stripped from stored paths.
 */
final class TrafficAnalytics
{
    private const SESSION_IDLE_SECONDS = 1800;
    private const PSEUDONYM_RETENTION_SECONDS = 86400;
    private const MAX_PATH_LENGTH = 180;
    private const MAX_REFERRER_HOST_LENGTH = 180;

    public static function record(string $root, array $payload, array $server): array
    {
        $root = rtrim($root, '/\\');
        if (self::privacySignalOptOut($server, $payload)) {
            return ['ok'=>true,'recorded'=>false,'reason'=>'privacy_signal'];
        }
        $ua = substr(trim((string)($server['HTTP_USER_AGENT'] ?? '')), 0, 320);
        if (self::isBot($ua)) return ['ok'=>true,'recorded'=>false,'reason'=>'bot'];

        $ip = self::clientIp($server);
        if ($ip === '') return ['ok'=>true,'recorded'=>false,'reason'=>'no_ip'];
        $secret = self::secret($root);
        if ($secret === '') return ['ok'=>true,'recorded'=>false,'reason'=>'not_configured'];

        $now = time();
        $day = gmdate('Y-m-d', $now);
        // Deliberately avoid browser fingerprinting: the short-lived daily pseudonym
        // is based only on the transient network address, not UA/client feature entropy.
        // NAT/shared-address visitors may therefore be undercounted; uniques are estimates.
        $visitor = hash_hmac('sha256', $ip, hash_hmac('sha256', $day, $secret, true));
        // Do not retain the raw IP past this point.
        $country = self::countryFromIp($ip, $root, $server);
        unset($ip);

        $path = self::normalizePath((string)($payload['path'] ?? '/'));
        $ref = self::normalizeReferrer((string)($payload['referrer'] ?? ''), (string)($server['HTTP_HOST'] ?? ''));
        $kind = strtolower(trim((string)($payload['event'] ?? 'pageview')));
        if (!in_array($kind, ['pageview','pagehide','visibility'], true)) $kind = 'pageview';

        $dir = $root.'/data/traffic';
        $runtimeDir = $dir.'/runtime';
        $aggregateDir = $dir.'/aggregates';
        if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0700, true) && !is_dir($runtimeDir)) throw new \RuntimeException('Unable to create traffic runtime directory.');
        if (!is_dir($aggregateDir) && !@mkdir($aggregateDir, 0700, true) && !is_dir($aggregateDir)) throw new \RuntimeException('Unable to create traffic aggregate directory.');

        $lockPath = $runtimeDir.'/analytics.lock';
        $lock = @fopen($lockPath, 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Unable to lock traffic analytics state.');
        try {
            $sessionsPath = $runtimeDir.'/sessions.json';
            $sessions = self::readJson($sessionsPath, ['updated_at'=>null,'sessions'=>[]]);
            if (!is_array($sessions['sessions'] ?? null)) $sessions['sessions'] = [];
            foreach ($sessions['sessions'] as $id=>$session) {
                $last=(int)($session['last']??0);
                if ($last <= 0 || $last < $now-self::PSEUDONYM_RETENTION_SECONDS) unset($sessions['sessions'][$id]);
            }

            $aggregatePath = $aggregateDir.'/'.$day.'.json';
            $agg = self::readJson($aggregatePath, self::newAggregate($day));
            $session = is_array($sessions['sessions'][$visitor] ?? null) ? $sessions['sessions'][$visitor] : null;
            $newSession = !$session || (int)($session['last']??0) < $now-self::SESSION_IDLE_SECONDS;
            if ($newSession) {
                $session = ['started'=>$now,'last'=>$now,'last_path'=>$path,'active_seconds'=>0,'bucket'=>'0','day'=>$day,'country'=>$country];
                $agg['sessions']=(int)($agg['sessions']??0)+1;
                $agg['entries'][$path]=(int)($agg['entries'][$path]??0)+1;
                $agg['_session_ids'][$visitor]=1;
                self::moveDurationBucket($agg, null, '0');
            } else {
                $delta=max(0,min(self::SESSION_IDLE_SECONDS,$now-(int)($session['last']??$now)));
                if ($delta>0) {
                    $oldBucket=(string)($session['bucket']??'0');
                    $session['active_seconds']=(int)($session['active_seconds']??0)+$delta;
                    $newBucket=self::durationBucket((int)$session['active_seconds']);
                    self::moveDurationBucket($agg,$oldBucket,$newBucket);
                    $session['bucket']=$newBucket;
                    $agg['duration_total_seconds']=(int)($agg['duration_total_seconds']??0)+$delta;
                }
            }

            $agg['_visitor_ids'][$visitor]=1;
            $agg['estimated_unique_visitors']=count($agg['_visitor_ids']);
            if ($kind === 'pageview') {
                $agg['pageviews']=(int)($agg['pageviews']??0)+1;
                $agg['pages'][$path]=(int)($agg['pages'][$path]??0)+1;
                $agg['countries'][$country]=(int)($agg['countries'][$country]??0)+1;
                if ($ref['type']==='external') $agg['referrers'][$ref['value']]=(int)($agg['referrers'][$ref['value']]??0)+1;
                elseif ($ref['type']==='direct') $agg['referrers']['direct / unknown']=(int)($agg['referrers']['direct / unknown']??0)+1;
                $previous=(string)($session['last_path']??'');
                if (!$newSession && $previous!=='' && $previous!==$path) {
                    $transition=$previous.' → '.$path;
                    $agg['transitions'][$transition]=(int)($agg['transitions'][$transition]??0)+1;
                }
                $session['last_path']=$path;
            } elseif ($kind === 'pagehide' || $kind === 'visibility') {
                $exit=(string)($session['last_path']??$path);
                if ($exit!=='') $agg['exits'][$exit]=(int)($agg['exits'][$exit]??0)+1;
            }

            $session['last']=$now;$session['day']=$day;$session['country']=$country;
            $sessions['sessions'][$visitor]=$session;$sessions['updated_at']=gmdate(DATE_ATOM,$now);
            $agg['updated_at']=gmdate(DATE_ATOM,$now);
            $diagPath=$runtimeDir.'/diagnostics.json';
            $diag=self::readJson($diagPath,['event_times'=>[]]);
            $times=array_values(array_filter(array_map('intval',is_array($diag['event_times']??null)?$diag['event_times']:[]),static fn(int $t):bool=>$t>=$now-3600));
            $times[]=$now;
            $diag=['last_received_at'=>gmdate(DATE_ATOM,$now),'last_event'=>$kind,'events_last_hour'=>count($times),'event_times'=>$times];
            self::writeJson($diagPath,$diag,0600);
            self::writeJson($sessionsPath,$sessions,0600);
            self::writeJson($aggregatePath,$agg,0600);
            self::compactOldAggregates($aggregateDir,$now);
        } finally {
            flock($lock,LOCK_UN);fclose($lock);
        }
        return ['ok'=>true,'recorded'=>true];
    }

    public static function report(string $root, int $days=90): array
    {
        $root=rtrim($root,'/\\');$days=max(1,min(366,$days));$dir=$root.'/data/traffic/aggregates';
        $rows=[];$totals=['pageviews'=>0,'sessions'=>0,'estimated_unique_visitors'=>0,'duration_total_seconds'=>0];
        $pages=[];$entries=[];$exits=[];$countries=[];$referrers=[];$transitions=[];$hist=[];
        if (is_dir($dir)) {
            $files=glob($dir.'/*.json')?:[];sort($files,SORT_STRING);$files=array_slice($files,-$days);
            foreach($files as $file){$a=self::readJson($file,[]);if(!$a)continue;$row=['date'=>(string)($a['date']??basename($file,'.json')),'pageviews'=>(int)($a['pageviews']??0),'sessions'=>(int)($a['sessions']??0),'estimated_unique_visitors'=>(int)($a['estimated_unique_visitors']??0),'duration_total_seconds'=>(int)($a['duration_total_seconds']??0)];$row['pages_per_session']=$row['sessions']?round($row['pageviews']/$row['sessions'],2):0;$row['average_duration_seconds']=$row['sessions']?round($row['duration_total_seconds']/$row['sessions']):0;$rows[]=$row;foreach($totals as $k=>$_)$totals[$k]+=$row[$k];foreach(['pages'=>&$pages,'entries'=>&$entries,'exits'=>&$exits,'countries'=>&$countries,'referrers'=>&$referrers,'transitions'=>&$transitions,'duration_histogram'=>&$hist] as $key=>&$target)foreach(is_array($a[$key]??null)?$a[$key]:[] as $name=>$count)$target[(string)$name]=($target[(string)$name]??0)+(int)$count;unset($target);}
        }
        // Daily pseudonyms rotate deliberately, so cross-day de-duplication is not
        // possible after privacy compaction. Never mislabel the sum of daily uniques
        // as period uniques: expose it explicitly as visitor-days and report today's
        // (latest day) daily unique estimate separately.
        $totals['estimated_unique_visitor_days']=$totals['estimated_unique_visitors'];
        $totals['latest_daily_unique_visitors']=$rows ? (int)($rows[count($rows)-1]['estimated_unique_visitors']??0) : 0;
        $totals['average_daily_unique_visitors']=$rows ? round($totals['estimated_unique_visitor_days']/count($rows),1) : 0;
        unset($totals['estimated_unique_visitors']);
        $totals['pages_per_session']=$totals['sessions']?round($totals['pageviews']/$totals['sessions'],2):0;
        $totals['average_duration_seconds']=$totals['sessions']?round($totals['duration_total_seconds']/$totals['sessions']):0;
        $totals['median_duration_seconds']=self::approxMedian($hist);
        return ['days'=>$days,'summary'=>$totals,'trend'=>$rows,'top_pages'=>self::top($pages,50),'entry_pages'=>self::top($entries,30),'exit_pages'=>self::top($exits,30),'countries'=>self::top($countries,50),'referrers'=>self::top($referrers,50),'transitions'=>self::top($transitions,50),'duration_histogram'=>$hist,'privacy'=>['mode'=>'first-party cookieless','persistent_client_identifier'=>false,'raw_ip_persisted'=>false,'query_strings_persisted'=>false,'full_external_referrers_persisted'=>false,'pseudonym_retention_hours'=>24,'session_idle_minutes'=>30,'geography'=>'country when local GeoIP/trusted server country signal is available']];
    }

    public static function diagnostics(string $root): array
    {
        $root=rtrim($root,'/\\');$dir=$root.'/data/traffic';$runtime=$dir.'/runtime';$aggregates=$dir.'/aggregates';$secret=self::secret($root);$now=time();
        $diag=self::readJson($runtime.'/diagnostics.json',[]);$times=array_values(array_filter(array_map('intval',is_array($diag['event_times']??null)?$diag['event_times']:[]),static fn(int $t):bool=>$t>=$now-3600));
        $files=glob($aggregates.'/*.json')?:[];sort($files,SORT_STRING);$latest=$files?end($files):false;$latestAgg=$latest?self::readJson((string)$latest,[]):[];
        $parent=$root.'/data';$storageWritable=is_dir($dir)?is_writable($dir):(is_dir($parent)&&is_writable($parent));
        return ['configured'=>$secret!=='','storage_writable'=>$storageWritable,'storage_exists'=>is_dir($dir),'last_event_received_at'=>$diag['last_received_at']??($latestAgg['updated_at']??null),'last_event_type'=>$diag['last_event']??null,'events_last_hour'=>count($times),'latest_day'=>$latestAgg['date']??null,'latest_day_pageviews'=>(int)($latestAgg['pageviews']??0),'latest_day_sessions'=>(int)($latestAgg['sessions']??0)];
    }

    public static function selfTest(string $root): array
    {
        $base=rtrim((string)sys_get_temp_dir(),'/\\').'/p2k-traffic-selftest-'.getmypid().'-'.bin2hex(random_bytes(3));
        try{
            if(!@mkdir($base.'/data',0700,true)&&!is_dir($base.'/data'))throw new \RuntimeException('Unable to create isolated self-test directory.');
            self::writeJson($base.'/data/server-config.json',['trafficAnalyticsSecret'=>bin2hex(random_bytes(32))],0600);
            $result=self::record($base,['event'=>'pageview','path'=>'/__p2k_traffic_self_test__','referrer'=>''],['REMOTE_ADDR'=>'127.0.0.2','HTTP_USER_AGENT'=>'P2K-Traffic-Diagnostic','HTTP_HOST'=>'localhost']);
            $report=self::report($base,1);$ok=!empty($result['recorded'])&&(int)($report['summary']['pageviews']??0)===1;
            return ['ok'=>$ok,'recorded'=>(bool)($result['recorded']??false),'aggregate_pageviews'=>(int)($report['summary']['pageviews']??0),'tested_at'=>gmdate(DATE_ATOM),'isolated'=>true,'error'=>$ok?null:'Synthetic event did not reach the isolated aggregate.'];
        }catch(\Throwable $e){return ['ok'=>false,'recorded'=>false,'aggregate_pageviews'=>0,'tested_at'=>gmdate(DATE_ATOM),'isolated'=>true,'error'=>$e->getMessage()];}
        finally{self::removeTree($base);}
    }

    private static function removeTree(string $path): void
    {
        if(!is_dir($path))return;foreach(scandir($path)?:[] as $name){if($name==='.'||$name==='..')continue;$p=$path.'/'.$name;if(is_dir($p))self::removeTree($p);else @unlink($p);}@rmdir($path);
    }

    private static function secret(string $root): string
    {
        $cfg=self::readJson($root.'/data/server-config.json',[]);$value=trim((string)($cfg['trafficAnalyticsSecret']??''));
        if($value!=='')return $value;$cron=trim((string)($cfg['cronToken']??''));return $cron!==''?hash_hmac('sha256','p2k-traffic-analytics-v1',$cron):'';
    }
    private static function clientIp(array $server): string
    {
        $raw=trim((string)($server['REMOTE_ADDR']??''));return filter_var($raw,FILTER_VALIDATE_IP)?$raw:'';
    }
    private static function countryFromIp(string $ip,string $root,array $server): string
    {
        if((string)getenv('P2K_TRUST_COUNTRY_HEADER')==='1'){$v=strtoupper(trim((string)($server['HTTP_CF_IPCOUNTRY']??$server['HTTP_X_COUNTRY_CODE']??'')));if(preg_match('/^[A-Z]{2}$/',$v))return $v;}
        if(function_exists('geoip_country_code_by_name')){$v=@geoip_country_code_by_name($ip);if(is_string($v)&&preg_match('/^[A-Z]{2}$/i',$v))return strtoupper($v);}
        try{if(class_exists('GeoIp2\\Database\\Reader')){$db=(string)(getenv('P2K_GEOIP_COUNTRY_DB')?:$root.'/data/GeoLite2-Country.mmdb');if(is_file($db)){$reader=new \GeoIp2\Database\Reader($db);$v=(string)($reader->country($ip)->country->isoCode??'');$reader->close();if(preg_match('/^[A-Z]{2}$/i',$v))return strtoupper($v);}}}catch(\Throwable){}
        return 'Unknown';
    }
    private static function normalizePath(string $value): string
    {
        $parts=@parse_url($value);$path=is_array($parts)?(string)($parts['path']??'/'):$value;$path='/'.ltrim($path,'/');$path=preg_replace('~/+~','/',$path)?:'/';return substr($path,0,self::MAX_PATH_LENGTH);
    }
    private static function normalizeReferrer(string $value,string $host): array
    {
        if(trim($value)==='')return ['type'=>'direct','value'=>'direct / unknown'];$p=@parse_url($value);if(!is_array($p))return ['type'=>'direct','value'=>'direct / unknown'];$rh=strtolower(trim((string)($p['host']??'')));$self=strtolower(preg_replace('/:\d+$/','',$host)??$host);if($rh===''||$rh===$self)return ['type'=>'internal','value'=>self::normalizePath((string)($p['path']??'/'))];return ['type'=>'external','value'=>substr($rh,0,self::MAX_REFERRER_HOST_LENGTH)];
    }
    private static function privacySignalOptOut(array $server,array $payload): bool
    {
        $gpc=strtolower(trim((string)($server['HTTP_SEC_GPC']??'')));$dnt=strtolower(trim((string)($server['HTTP_DNT']??'')));return $gpc==='1'||$dnt==='1'||!empty($payload['opt_out']);
    }
    private static function isBot(string $ua): bool
    {
        if($ua==='')return true;return (bool)preg_match('/bot|crawl|spider|slurp|bingpreview|headless|lighthouse|uptime|monitor|curl|wget|python-requests|httpclient/i',$ua);
    }
    private static function newAggregate(string $day): array
    { return ['schema_version'=>1,'date'=>$day,'updated_at'=>null,'pageviews'=>0,'sessions'=>0,'estimated_unique_visitors'=>0,'duration_total_seconds'=>0,'pages'=>[],'entries'=>[],'exits'=>[],'countries'=>[],'referrers'=>[],'transitions'=>[],'duration_histogram'=>[],'_visitor_ids'=>[],'_session_ids'=>[]]; }
    private static function durationBucket(int $seconds): string
    { if($seconds<30)return '0–29s';if($seconds<60)return '30–59s';if($seconds<120)return '1–2m';if($seconds<300)return '2–5m';if($seconds<600)return '5–10m';if($seconds<1800)return '10–30m';return '30m+'; }
    private static function moveDurationBucket(array &$agg,?string $old,string $new): void
    { if($old!==null&&isset($agg['duration_histogram'][$old]))$agg['duration_histogram'][$old]=max(0,(int)$agg['duration_histogram'][$old]-1);$agg['duration_histogram'][$new]=(int)($agg['duration_histogram'][$new]??0)+1; }
    private static function approxMedian(array $hist): int
    { $order=['0–29s'=>15,'30–59s'=>45,'1–2m'=>90,'2–5m'=>210,'5–10m'=>450,'10–30m'=>1200,'30m+'=>1800];$total=array_sum(array_map('intval',$hist));if($total<=0)return 0;$target=($total+1)/2;$n=0;foreach($order as $k=>$mid){$n+=(int)($hist[$k]??0);if($n>=$target)return $mid;}return 0; }
    private static function top(array $map,int $limit): array
    { arsort($map,SORT_NUMERIC);$out=[];foreach(array_slice($map,0,$limit,true) as $name=>$count)$out[]=['name'=>(string)$name,'count'=>(int)$count];return $out; }
    private static function compactOldAggregates(string $dir,int $now): void
    { foreach(glob($dir.'/*.json')?:[] as $file){if(@filemtime($file)>=$now-self::PSEUDONYM_RETENTION_SECONDS)continue;$a=self::readJson($file,[]);if(!$a)continue;unset($a['_visitor_ids'],$a['_session_ids']);self::writeJson($file,$a,0600);} }
    private static function readJson(string $path,array $default): array
    { if(!is_file($path))return $default;$v=json_decode((string)@file_get_contents($path),true);return is_array($v)?$v:$default; }
    private static function writeJson(string $path,array $value,int $mode): void
    { $dir=dirname($path);if(!is_dir($dir))@mkdir($dir,0700,true);$tmp=$path.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(3));$json=json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false||@file_put_contents($tmp,$json."\n",LOCK_EX)===false)throw new \RuntimeException('Unable to write traffic analytics state.');@chmod($tmp,$mode);if(!@rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Unable to replace traffic analytics state.');}}
}
