<?php
declare(strict_types=1);

// Run the actual OAuthSession::multiGet and rate coordinator against a loopback
// HTTP fixture. Stub only canonical-cache persistence and telemetry; no real
// credentials, production storage, or Chess.com calls are involved.
namespace P2K\Shared {
    final class SharedChessGateway {
        public static int $ingested = 0;
        public function __construct($unused, array $app) {}
        public function ingestAttestedOAuth(string $url, int $status, string $body, array $headers, int $ttl): bool {
            if ($status !== 200 || $ttl !== 120) throw new \RuntimeException('Unexpected cache ingestion');
            usleep(20000);
            self::$ingested++;
            return true;
        }
    }
}
namespace P2K\TeamPoints {
    final class RuntimeTelemetry {
        public static array $events = [];
        public static function record(string $name, array $data): void { self::$events[] = [$name, $data]; }
    }
}
namespace {
    function p2k_tp_config(): array { return ['app'=>[]]; }
    function verify(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    $root = dirname(__DIR__);
    require $root . '/server/team-points/src/OAuthRateCoordinator.php';
    require $root . '/server/team-points/src/OAuthSession.php';
    verify(extension_loaded('curl'), 'cURL is required');
    $dir = sys_get_temp_dir() . '/p2k-lab-fixture-' . bin2hex(random_bytes(6));
    verify(mkdir($dir, 0700), 'Fixture directory failed');
    putenv('P2K_OAUTH_RATE_STATE_DIR=' . $dir . '/rate');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    verify(is_resource($socket), 'Unable to reserve fixture port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $fixture = $dir . '/fixture.php';
    file_put_contents($fixture, <<<'FIXTURE'
<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_ends_with($path, '/missing')) http_response_code(404);
if (str_ends_with($path, '/limited')) { http_response_code(429); header('Retry-After: 120'); }
usleep(15000);
echo '{"status":"basic"}';
FIXTURE
    );
    $server = proc_open([PHP_BINARY, '-S', $address, $fixture], [0=>['file','/dev/null','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']], $pipes, $dir);
    try {
        verify(is_resource($server), 'Fixture process failed');
        $ready = false;
        for ($i=0; $i<100; $i++) {
            $probe = @stream_socket_client('tcp://'.$address, $errno, $errstr, 0.1);
            if (is_resource($probe)) { fclose($probe); $ready=true; break; }
            usleep(20000);
        }
        verify($ready, 'Fixture did not start');
        $requests = [];
        for ($i=0; $i<8; $i++) $requests[] = ['id'=>(string)$i,'url'=>'http://'.$address.'/pub/player/'.($i===7?'missing':'fixture-'.$i),'headers'=>[]];
        $method = new ReflectionMethod(\P2K\TeamPoints\OAuthSession::class, 'multiGet');
        $result = $method->invoke(null, $requests, 'local-fixture-token-not-a-credential', 4, 120.0, 'foreground');
        verify($result['ok'] && $result['mode']==='oauth-bearer', 'Gateway result invalid');
        verify($result['processed']===8 && $result['successes']===7 && $result['rate_429']===0, 'Gateway response counts changed');
        verify(\P2K\Shared\SharedChessGateway::$ingested===7, 'Canonical ingestion was changed');
        $timing = $result['gateway_timing'] ?? [];
        foreach (['acquisition_ms','cache_ingest_ms','reservation_call_ms','reservation_attempts','reservation_misses','first_launch_delay_ms','launch_span_ms'] as $key) {
            verify(isset($timing[$key]) && is_numeric($timing[$key]) && $timing[$key]>=0, 'Missing/invalid timing: '.$key);
        }
        verify($timing['coordinator_io']['calls']===$timing['coordinator_io']['writes']+$timing['coordinator_io']['no_write'],'Coordinator accounting invalid');
        verify($timing['coordinator_io']['no_write']>0,'No read-only coordinator transactions');
        verify(count($timing['curl_intervals_ms'])===8,'Missing cURL admission/completion intervals');
        foreach($timing['curl_intervals_ms'] as $pair)verify(count($pair)===2&&$pair[1]>$pair[0],'Invalid cURL interval');
        verify($timing['acquisition_ms']>0 && $timing['cache_ingest_ms']>=130, 'Timing phases are not measured');
        verify($timing['reservation_attempts']>=8 && $timing['reservation_misses']<=$timing['reservation_attempts'], 'Reservation counts invalid');
        verify($timing['reservation_call_ms']<=$timing['acquisition_ms']+2, 'Reservation duration inconsistent');
        verify(abs($result['elapsed_ms']-$timing['acquisition_ms']-$timing['cache_ingest_ms'])<10, 'Gateway timing does not reconcile');
        verify(count(\P2K\TeamPoints\RuntimeTelemetry::$events)===1, 'Existing telemetry changed');
        verify(!str_contains(json_encode($result), 'local-fixture-token-not-a-credential'), 'Credential leak');
        // An actual 429 with a long Retry-After must not pin PHP for 120s.
        $limited=$requests;$limited[0]['url']='http://'.$address.'/pub/player/limited';
        $before=microtime(true);$throttled=$method->invoke(null,$limited,'limited-fixture-token',1,120.0,'foreground');
        verify(microtime(true)-$before<3.0,'Long cooldown pinned the gateway');
        verify($throttled['rate_429']===1&&$throttled['launched']===1&&$throttled['deferred']===7&&count($throttled['results'])===8,'Throttle/deferred counts invalid');
        $events429=$throttled['gateway_timing']['throttle_events'];verify(count($events429)===1&&$events429[0]['retry_after_seconds']===120&&$events429[0]['response_ms']>$events429[0]['launch_ms'],'Exact throttle timing missing');
        verify($throttled['controller']['recovery_hold_remaining_ms']>170000,'Recovery hold missing');
        verify($throttled['controller']['cooldown_remaining_ms']>110000&&$throttled['controller']['rate_target_cps']<30,'Shared backoff invalid');
        foreach($throttled['results'] as $row){if(!empty($row['deferred']))verify($row['status']===503&&$row['elapsed_ms']===0&&($row['headers']['retry-after']??0)>100,'Deferred result is not explicitly retryable');}
        $before=microtime(true);$held=$method->invoke(null,$requests,'limited-fixture-token',4,120.0,'foreground');
        verify(microtime(true)-$before<2.0&&$held['launched']===0&&$held['deferred']===8&&$held['rate_429']===0,'Second batch launched during shared cooldown');
        verify(\P2K\Shared\SharedChessGateway::$ingested===7,'Throttled/deferred responses entered canonical cache');
        $events=\P2K\TeamPoints\RuntimeTelemetry::$events;verify(count($events)===3&&$events[1][1]['calls']===1&&$events[2][1]['calls']===0,'Deferred entries falsely counted as upstream calls');
        echo 'PASS actual PHP multiGet + rate coordinator, success/error results, cache preservation, reconciled timings, long Retry-After, deferred accounting, credential isolation'.PHP_EOL;
    } finally {
        if (is_resource($server)) { proc_terminate($server); proc_close($server); }
        foreach (glob($dir.'/rate/*') ?: [] as $f) @unlink($f);
        @rmdir($dir.'/rate');
        foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}
