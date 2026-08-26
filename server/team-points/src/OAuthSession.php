<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use P2K\Shared\SharedChessGateway;

final class OAuthSession
{
    public const SESSION_NAME = 'P2KOAUTH';
    private const VERSION = '2.9.22.4';
    private const SESSION_RETENTION_SECONDS = 90000; // 25h: slightly beyond the observed ~24h Chess.com token lifetime.

    public static function config(): array
    {
        $localPath = P2K_TP_ROOT . '/config/oauth.local.php';
        $file = is_file($localPath) ? require $localPath : [];
        if (!is_array($file)) $file = [];
        return [
            'name'=>trim((string)(getenv('P2K_OAUTH_APP_NAME') ?: ($file['name']??''))),
            'client_id'=>trim((string)(getenv('P2K_OAUTH_CLIENT_ID') ?: ($file['client_id']??''))),
            'redirect_url'=>trim((string)(getenv('P2K_OAUTH_REDIRECT_URL') ?: ($file['redirect_url']??''))),
            'authorize_url'=>trim((string)(getenv('P2K_OAUTH_AUTHORIZE_URL') ?: ($file['authorize_url']??'https://oauth.chess.com/authorize'))),
            'token_url'=>trim((string)(getenv('P2K_OAUTH_TOKEN_URL') ?: ($file['token_url']??'https://oauth.chess.com/token'))),
            'scope'=>trim((string)(getenv('P2K_OAUTH_SCOPE') ?: ($file['scope']??'openid profile'))),
        ];
    }

    public static function configured(?array $cfg=null): bool
    {
        $cfg ??= self::config();
        return ($cfg['name']??'')!=='' && ($cfg['client_id']??'')!=='' && ($cfg['redirect_url']??'')!=='';
    }

    public static function start(): void
    {
        if (session_status()===PHP_SESSION_ACTIVE) return;
        $forwarded=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??''));
        $secure=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||$forwarded==='https';
        session_name(self::SESSION_NAME);
        // Persist the opaque session id across browser restarts while keeping the
        // Bearer token server-side. Authentication still ends at token expires_at.
        @ini_set('session.gc_maxlifetime',(string)self::SESSION_RETENTION_SECONDS);
        session_set_cookie_params(['lifetime'=>self::SESSION_RETENTION_SECONDS,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        session_start();
        // session_start() does not reliably re-issue an already existing cookie
        // with a newly configured lifetime on every host. Refresh it explicitly so
        // users upgrading from the old browser-session cookie gain persistence.
        if (session_id() !== '') {
            setcookie(self::SESSION_NAME,session_id(),[
                'expires'=>time()+self::SESSION_RETENTION_SECONDS,
                'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax'
            ]);
        }
        if (!isset($_SESSION['oauth_csrf'])) $_SESSION['oauth_csrf']=self::b64(random_bytes(24));
    }

    public static function sessionInfo(): array
    {
        self::start();
        $cfg=self::config();
        $token=self::accessToken();
        $user=is_array($_SESSION['oauth_user']??null)?$_SESSION['oauth_user']:[];
        $profile=is_array($_SESSION['oauth_profile']??null)?$_SESSION['oauth_profile']:[];
        $claims=is_array($_SESSION['oauth_claims']??null)?$_SESSION['oauth_claims']:[];
        $username=trim((string)($user['username']??$profile['username']??$claims['preferred_username']??''));
        $authenticated=$token!==''&&$username!=='';
        if (!$authenticated) $profile=[];
        $merged=self::profilePayload($username,$profile,$claims,$user);
        $curl=function_exists('curl_multi_init')&&function_exists('curl_init');
        return [
            'ok'=>true,'enabled'=>self::configured($cfg),'authenticated'=>$authenticated,
            'real_oauth'=>$authenticated,'oauth_verified'=>$authenticated,'profile'=>$authenticated?$merged:null,
            'expires_at'=>$authenticated?(int)($user['expires_at']??0):null,'csrf'=>(string)$_SESSION['oauth_csrf'],
            // v2.10.4.2: one short-lived, server-signed handoff lets a freshly
            // loaded frame establish the separate Team Points admin session
            // without reopening P2KOAUTH in a second HTTP request. The assertion
            // proves only the real OAuth username; session.php still re-checks
            // current club-admin membership before issuing P2KTPSESSID.
            'admin_bootstrap'=>$authenticated?self::adminBootstrapAssertion($username,(int)($user['expires_at']??0)):'',
            'transport'=>['batch_available'=>$curl,'http2_capable'=>self::curlHttp2Capable(),'max_concurrency'=>$curl?self::runtimeOpenFileCap():1],
        ];
    }

    /** Return the username proven by the current server-side Chess.com OAuth session.
     *  The OAuth PHP session is closed before returning so the caller may safely
     *  open a different application session namespace (for example P2KTPSESSID).
     */
    public static function authenticatedUsername(bool $closeSession = true): string
    {
        self::start();
        $token = self::accessToken();
        $user = is_array($_SESSION['oauth_user'] ?? null) ? $_SESSION['oauth_user'] : [];
        $profile = is_array($_SESSION['oauth_profile'] ?? null) ? $_SESSION['oauth_profile'] : [];
        $claims = is_array($_SESSION['oauth_claims'] ?? null) ? $_SESSION['oauth_claims'] : [];
        $username = trim((string)($user['username'] ?? $profile['username'] ?? $claims['preferred_username'] ?? ''));
        if ($token === '' || $username === '') $username = '';
        if ($closeSession && session_status() === PHP_SESSION_ACTIVE) session_write_close();
        return strtolower($username);
    }


    /**
     * Create a short-lived signed assertion for the already verified OAuth
     * identity. This is deliberately independent of the PHP session id so a
     * subsequent request can verify it without reopening P2KOAUTH.
     */
    public static function adminBootstrapAssertion(string $username, int $oauthExpiresAt = 0): string
    {
        $username = strtolower(trim($username));
        $key = self::adminBootstrapKey();
        if ($key === '' || !preg_match('/^[a-z0-9_-]{1,80}$/', $username)) return '';
        $now = time();
        $exp = $now + 60;
        if ($oauthExpiresAt > 0) $exp = min($exp, $oauthExpiresAt);
        if ($exp <= $now) return '';
        $payload = self::b64((string)json_encode([
            'v'=>1,'aud'=>'p2k-team-points-admin','u'=>$username,'iat'=>$now,'exp'=>$exp,
        ], JSON_UNESCAPED_SLASHES));
        if ($payload === '') return '';
        $sig = self::b64(hash_hmac('sha256', $payload, $key, true));
        return $payload . '.' . $sig;
    }

    /** Verify a short-lived OAuth identity assertion without opening P2KOAUTH. */
    public static function verifyAdminBootstrapAssertion(string $assertion): string
    {
        $assertion = trim($assertion);
        $key = self::adminBootstrapKey();
        if ($assertion === '' || $key === '') return '';
        $parts = explode('.', $assertion);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return '';
        [$payload, $providedSig] = $parts;
        $expectedSig = self::b64(hash_hmac('sha256', $payload, $key, true));
        if (!hash_equals($expectedSig, $providedSig)) return '';
        $raw = self::b64d($payload);
        if ($raw === false) return '';
        $claims = json_decode($raw, true);
        if (!is_array($claims) || (int)($claims['v'] ?? 0) !== 1 || (string)($claims['aud'] ?? '') !== 'p2k-team-points-admin') return '';
        $now = time();
        $iat = (int)($claims['iat'] ?? 0);
        $exp = (int)($claims['exp'] ?? 0);
        $username = strtolower(trim((string)($claims['u'] ?? '')));
        if ($iat <= 0 || $iat > $now + 5 || $exp <= $now || $exp - $iat > 65) return '';
        if (!preg_match('/^[a-z0-9_-]{1,80}$/', $username)) return '';
        return $username;
    }

    private static function adminBootstrapKey(): string
    {
        try { $app = (array)(\p2k_tp_config()['app'] ?? []); }
        catch (\Throwable) { return ''; }
        // Use an existing protected server-only secret. admin_token is preferred;
        // cron_token is an equally private fallback on installations where the
        // legacy browser admin token was intentionally left unused.
        foreach (['admin_token', 'cron_token'] as $field) {
            $token = trim((string)($app[$field] ?? ''));
            if ($token !== '' && !str_starts_with($token, 'CHANGE_'))
                return hash('sha256', "p2k-oauth-admin-bootstrap-v1\0" . $token, true);
        }
        return '';
    }

    public static function login(string $returnTo): never
    {
        self::start();$cfg=self::config();
        if(!self::configured($cfg))throw new ApiException('Chess.com OAuth is not configured on this host.',503,'OAUTH_NOT_CONFIGURED');
        $returnTo=self::safeReturn($returnTo);
        $verifier=self::b64(random_bytes(64));$state=self::b64(random_bytes(32));
        $_SESSION['oauth_pending']=['state'=>$state,'code_verifier'=>$verifier,'created_at'=>time(),'redirect_uri'=>(string)$cfg['redirect_url'],'return_to'=>$returnTo];
        $scope=self::scope($cfg);$challenge=self::b64(hash('sha256',$verifier,true));
        $query=['client_id'=>$cfg['client_id'],'redirect_uri'=>$cfg['redirect_url'],'response_type'=>'code','scope'=>$scope,'state'=>$state,'code_challenge'=>$challenge,'code_challenge_method'=>'S256'];
        $sep=str_contains((string)$cfg['authorize_url'],'?')?'&':'?';
        header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
        header('Location: '.$cfg['authorize_url'].$sep.http_build_query($query,'','&',PHP_QUERY_RFC3986),true,302);exit;
    }

    public static function handleCallback(): never
    {
        self::start();$cfg=self::config();
        $pending=$_SESSION['oauth_pending']??null;
        $returnTo=self::safeReturn(is_array($pending)?(string)($pending['return_to']??'/ui-v2.html?ui=v2'):'/ui-v2.html?ui=v2');
        try {
            if(isset($_GET['error']))throw new \RuntimeException('Chess.com authorization failed: '.trim((string)($_GET['error_description']??$_GET['error'])));
            if(!self::configured($cfg))throw new \RuntimeException('OAuth configuration is incomplete.');
            if(!is_array($pending))throw new \RuntimeException('OAuth state is missing or expired. Start login again.');
            $state=trim((string)($_GET['state']??''));$expected=trim((string)($pending['state']??''));
            if($state===''||$expected===''||!hash_equals($expected,$state))throw new \RuntimeException('OAuth state validation failed.');
            if((int)($pending['created_at']??0)<=0||time()-(int)$pending['created_at']>600)throw new \RuntimeException('OAuth login attempt expired.');
            $code=trim((string)($_GET['code']??''));$verifier=trim((string)($pending['code_verifier']??''));
            if($code===''||$verifier==='')throw new \RuntimeException('Chess.com did not return a usable authorization code.');
            if(!hash_equals(trim((string)($pending['redirect_uri']??'')),(string)$cfg['redirect_url']))throw new \RuntimeException('OAuth redirect configuration changed during login.');
            $token=self::postForm((string)$cfg['token_url'],['grant_type'=>'authorization_code','client_id'=>$cfg['client_id'],'redirect_uri'=>$cfg['redirect_url'],'code_verifier'=>$verifier,'code'=>$code]);
            $idToken=trim((string)($token['id_token']??''));$accessToken=trim((string)($token['access_token']??''));
            if($idToken===''||$accessToken==='')throw new \RuntimeException('Chess.com token response is incomplete.');
            $jwt=self::decodeJwt($idToken);$header=$jwt['header'];$claims=$jwt['claims'];
            if(!hash_equals('RS256',trim((string)($header['alg']??''))))throw new \RuntimeException('Chess.com returned an unsupported ID token algorithm.');
            $iss=trim((string)($claims['iss']??''));if(!hash_equals('https://oauth.chess.com',$iss))throw new \RuntimeException('ID token issuer is not Chess.com OAuth.');
            $aud=$claims['aud']??null;$audOk=is_string($aud)?hash_equals((string)$cfg['client_id'],$aud):(is_array($aud)&&in_array((string)$cfg['client_id'],array_map('strval',$aud),true));
            if(!$audOk)throw new \RuntimeException('ID token audience does not match this OAuth client.');
            if(isset($claims['exp'])&&(int)$claims['exp']<time()-30)throw new \RuntimeException('ID token is already expired.');
            $username=trim((string)($claims['preferred_username']??$claims['username']??$claims['nickname']??$claims['sub']??''));
            if($username==='')throw new \RuntimeException('Authorization succeeded but no Chess.com identity was returned.');
            $expiresAt=isset($token['expires_in'])?time()+max(0,(int)$token['expires_in']):(isset($claims['exp'])?(int)$claims['exp']:0);
            if(isset($claims['exp'])&&(int)$claims['exp']>0)$expiresAt=$expiresAt>0?min($expiresAt,(int)$claims['exp']):(int)$claims['exp'];
            $_SESSION['oauth_access']=['access_token'=>$accessToken,'refresh_token'=>trim((string)($token['refresh_token']??'')),'id_token'=>$idToken,'token_type'=>trim((string)($token['token_type']??'Bearer')),'scope'=>trim((string)($token['scope']??self::scope($cfg))),'expires_at'=>$expiresAt];
            $_SESSION['oauth_claims']=$claims;
            $_SESSION['oauth_user']=['username'=>$username,'subject'=>trim((string)($claims['sub']??'')),'authenticated_at'=>time(),'expires_at'=>$expiresAt];
            try{$r=self::singleGet('https://api.chess.com/pub/player/'.rawurlencode(strtolower($username)),$accessToken);$_SESSION['oauth_profile']=($r['status']>=200&&$r['status']<300&&is_array($r['json']))?self::publicProfile($r['json']):[];}catch(\Throwable){$_SESSION['oauth_profile']=[];}
            unset($_SESSION['oauth_pending']);session_regenerate_id(true);
            $returnTo=self::withOAuthResult($returnTo,'success');
        } catch(\Throwable $e) {
            unset($_SESSION['oauth_pending']);$_SESSION['oauth_last_error']=substr($e->getMessage(),0,400);
            $returnTo=self::withOAuthResult($returnTo,'fail');
        }
        header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('Location: '.$returnTo,true,303);exit;
    }

    public static function logout(string $csrf): array
    {
        self::start();self::assertCsrf($csrf);
        foreach(['oauth_access','oauth_user','oauth_profile','oauth_claims','oauth_pending','oauth_last_error'] as $key)unset($_SESSION[$key]);
        session_regenerate_id(true);
        return ['ok'=>true,'authenticated'=>false,'csrf'=>(string)($_SESSION['oauth_csrf']??'')];
    }

    public static function batch(array $requests,int $requestedConcurrency,string $csrf,float $requestedRateCps=0.0,string $trafficClass='foreground'): array
    {
        self::start();self::assertCsrf($csrf);$token=self::accessToken();
        if($token==='')throw new ApiException('A valid Chess.com OAuth session is required.',401,'OAUTH_LOGIN_REQUIRED');
        $normalized=[];
        foreach(array_slice($requests,0,512) as $i=>$row){
            if(!is_array($row))continue;$url=self::allowedPubApiUrl((string)($row['url']??''));if($url==='')continue;
            $headers=[];foreach((array)($row['headers']??[]) as $name=>$value){$n=strtolower(trim((string)$name));if(in_array($n,['if-none-match','if-modified-since','accept'],true))$headers[$n]=trim((string)$value);}
            $normalized[]=['id'=>(string)($row['id']??$i),'url'=>$url,'headers'=>$headers];
        }
        if($normalized===[])return ['ok'=>true,'mode'=>'oauth-bearer','results'=>[],'processed'=>0,'successes'=>0,'rate_429'=>0,'errors'=>0,'elapsed_ms'=>0,'cps'=>0,'concurrency'=>0,'peak_in_flight'=>0,'transport_cap'=>self::runtimeOpenFileCap(),'transport_capacity'=>self::runtimeOpenFileCap(),'batch_size'=>0,'requested_rate_cps'=>$requestedRateCps,'rate_cps'=>$requestedRateCps];
        session_write_close();
        return self::multiGet($normalized,$token,$requestedConcurrency,$requestedRateCps,$trafficClass);
    }

    /**
     * Use the logged-in OAuth Bearer token from an already-authorized same-origin
     * server request. The caller must perform its own authorization before using
     * this helper. It deliberately returns null when no real OAuth session cookie
     * is present so CRON/CLI and anonymous requests retain the shared serial gateway.
     */
    public static function batchForAuthorizedRequest(array $requests,int $requestedConcurrency,float $requestedRateCps=0.0,string $trafficClass='foreground'): ?array
    {
        if(PHP_SAPI==='cli'||empty($_COOKIE[self::SESSION_NAME]))return null;
        if(session_status()===PHP_SESSION_ACTIVE&&session_name()!==self::SESSION_NAME)session_write_close();
        self::start();$token=self::accessToken();
        if($token===''){session_write_close();return null;}
        $normalized=[];
        foreach(array_slice($requests,0,512) as $i=>$row){
            if(!is_array($row))continue;$url=self::allowedPubApiUrl((string)($row['url']??''));if($url==='')continue;
            $headers=[];foreach((array)($row['headers']??[]) as $name=>$value){$n=strtolower(trim((string)$name));if(in_array($n,['if-none-match','if-modified-since','accept'],true))$headers[$n]=trim((string)$value);}
            $normalized[]=['id'=>(string)($row['id']??$i),'url'=>$url,'headers'=>$headers];
        }
        session_write_close();
        if($normalized===[])return ['ok'=>true,'mode'=>'oauth-bearer','results'=>[],'processed'=>0,'successes'=>0,'rate_429'=>0,'errors'=>0,'elapsed_ms'=>0,'cps'=>0,'concurrency'=>0,'peak_in_flight'=>0,'transport_cap'=>self::runtimeOpenFileCap(),'transport_capacity'=>self::runtimeOpenFileCap(),'batch_size'=>0,'requested_rate_cps'=>$requestedRateCps,'rate_cps'=>$requestedRateCps];
        return self::multiGet($normalized,$token,$requestedConcurrency,$requestedRateCps,$trafficClass);
    }

    public static function assertCsrf(string $value): void
    {
        $expected=(string)($_SESSION['oauth_csrf']??'');if($expected===''||$value===''||!hash_equals($expected,$value))throw new ApiException('OAuth request validation failed.',403,'OAUTH_CSRF');
    }

    private static function accessToken(): string
    {
        $a=$_SESSION['oauth_access']??null;if(!is_array($a))return '';$token=trim((string)($a['access_token']??''));$exp=(int)($a['expires_at']??0);
        if($token===''||($exp>0&&$exp<=time()+5)){if($exp>0&&$exp<=time()+5)unset($_SESSION['oauth_access'],$_SESSION['oauth_user']);return '';}
        return $token;
    }

    private static function scope(array $cfg): string
    {
        $out=[];foreach(array_merge(['openid','profile'],preg_split('/\s+/',trim((string)($cfg['scope']??'')))?:[]) as $v){$v=trim((string)$v);if($v!=='')$out[$v]=true;}return implode(' ',array_keys($out));
    }
    private static function b64(string $raw): string{return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');}
    private static function b64d(string $raw): string|false{$raw=strtr($raw,'-_','+/');$p=strlen($raw)%4;if($p)$raw.=str_repeat('=',4-$p);return base64_decode($raw,true);}
    private static function decodeJwt(string $jwt): array
    {
        $parts=explode('.',$jwt);if(count($parts)!==3)throw new \RuntimeException('Chess.com returned an invalid ID token.');$h=self::b64d($parts[0]);$p=self::b64d($parts[1]);$s=self::b64d($parts[2]);if($h===false||$p===false||$s===false)throw new \RuntimeException('Chess.com returned an unreadable ID token.');$header=json_decode($h,true);$claims=json_decode($p,true);if(!is_array($header)||!is_array($claims))throw new \RuntimeException('Chess.com returned invalid ID token JSON.');return ['header'=>$header,'claims'=>$claims,'signature_bytes'=>strlen($s)];
    }

    private static function postForm(string $url,array $fields): array
    {
        if(!preg_match('~^https://~i',$url))throw new \RuntimeException('OAuth token endpoint must use HTTPS.');$body=http_build_query($fields,'','&',PHP_QUERY_RFC3986);
        if(function_exists('curl_init')){$ch=curl_init($url);if($ch===false)throw new \RuntimeException('Unable to initialize OAuth token request.');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded','User-Agent: PromoteToKing-OAuth/'.self::VERSION]]);$response=curl_exec($ch);if($response===false){$e=curl_error($ch);curl_close($ch);throw new \RuntimeException('OAuth token request failed: '.$e);}$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);}else{$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\nUser-Agent: PromoteToKing-OAuth/".self::VERSION."\r\n",'content'=>$body,'timeout'=>20,'ignore_errors'=>true]]);$response=@file_get_contents($url,false,$ctx);$status=0;foreach(($http_response_header??[]) as $header)if(preg_match('~^HTTP/\S+\s+(\d{3})~',(string)$header,$m)){$status=(int)$m[1];break;}if($response===false)throw new \RuntimeException('OAuth token request failed.');}
        $json=json_decode((string)$response,true);if(!is_array($json))throw new \RuntimeException('Chess.com returned a non-JSON token response.');if($status<200||$status>=300)throw new \RuntimeException('Chess.com rejected the token exchange: '.trim((string)($json['error_description']??$json['error']??('HTTP '.$status))));return $json;
    }

    private static function allowedPubApiUrl(string $url): string
    {
        $p=@parse_url(trim($url));if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||strtolower((string)($p['host']??''))!=='api.chess.com')return '';$path=(string)($p['path']??'');if(!str_starts_with($path,'/pub/'))return '';return 'https://api.chess.com'.$path.(isset($p['query'])?'?'.$p['query']:'');
    }

    private static function publicProfile(array $p): array
    {
        $country=trim((string)($p['country']??''));if($country!==''){$parts=explode('/',rtrim($country,'/'));$country=strtoupper((string)end($parts));}
        return ['username'=>trim((string)($p['username']??'')),'player_id'=>isset($p['player_id'])?(int)$p['player_id']:null,'name'=>trim((string)($p['name']??'')),'title'=>trim((string)($p['title']??'')),'status'=>trim((string)($p['status']??'')),'avatar'=>trim((string)($p['avatar']??'')),'url'=>trim((string)($p['url']??'')),'country'=>$country,'location'=>trim((string)($p['location']??'')),'followers'=>isset($p['followers'])?(int)$p['followers']:null,'joined'=>isset($p['joined'])?(int)$p['joined']:null,'last_online'=>isset($p['last_online'])?(int)$p['last_online']:null];
    }

    private static function profilePayload(string $username,array $profile,array $claims,array $user): array
    {
        $avatar=trim((string)($profile['avatar']??$claims['picture']??''));$profileUrl=trim((string)($profile['url']??$claims['profile']??''));if($profileUrl===''&&$username!=='')$profileUrl='https://www.chess.com/member/'.rawurlencode(strtolower($username));
        return ['version'=>2,'authMode'=>'real-oauth','realOAuth'=>true,'oauthVerified'=>true,'username'=>$username,'avatar'=>$avatar,'profileURL'=>$profileUrl,'playerId'=>$profile['player_id']??(isset($claims['user_id'])?(int)$claims['user_id']:null),'title'=>(string)($profile['title']??''),'name'=>(string)($profile['name']??''),'status'=>(string)($profile['status']??''),'location'=>(string)($profile['location']??''),'followers'=>$profile['followers']??null,'joined'=>$profile['joined']??null,'lastOnline'=>$profile['last_online']??null,'country'=>(string)($claims['country']??''),'countryCode'=>(string)($claims['country_code']??$profile['country']??''),'membership'=>(string)($claims['membership']??''),'locale'=>(string)($claims['locale']??''),'zoneinfo'=>(string)($claims['zoneinfo']??''),'subject'=>(string)($user['subject']??$claims['sub']??''),'expiresAt'=>(int)($user['expires_at']??0)];
    }

    private static function singleGet(string $url,string $token): array
    {
        $request=[['id'=>'profile','url'=>self::allowedPubApiUrl($url),'headers'=>[]]];$batch=self::multiGet($request,$token,1);$r=$batch['results'][0]??[];return ['status'=>(int)($r['status']??0),'json'=>is_array($r['json']??null)?$r['json']:null];
    }

    private static function runtimeOpenFileCap(): int
    {
        // Transport capacity is a property of the PHP/cURL environment, not of
        // the size of the current gateway batch. A tiny two-request batch must
        // never teach browser clients that the host can only sustain two
        // concurrent connections. The current workload is bounded separately
        // when multiGet() chooses its requested concurrency.
        $cap=256;if(function_exists('posix_getrlimit')){$limits=@posix_getrlimit();if(is_array($limits))foreach(['soft openfiles','soft maxfiles','soft open files'] as $k)if(isset($limits[$k])&&is_numeric($limits[$k])){$cap=min($cap,max(1,(int)$limits[$k]-64));break;}}return max(1,$cap);
    }
    private static function percentile(array $values,float $p): float{if(!$values)return 0.0;sort($values,SORT_NUMERIC);$i=(int)floor((count($values)-1)*max(0,min(1,$p)));return (float)$values[$i];}
    private static function curlHttp2Capable(): bool{if(!function_exists('curl_version'))return false;$c=curl_version();return defined('CURL_VERSION_HTTP2')&&(((int)($c['features']??0)&CURL_VERSION_HTTP2)!==0);}

    private static function retryAfterSeconds(string $value): int
    {
        $value=trim($value);if($value==='')return 0;if(ctype_digit($value))return max(0,min(60,(int)$value));$ts=strtotime($value);return $ts===false?0:max(0,min(60,$ts-time()));
    }

    private static function endpointClassForUrl(string $url): string
    {
        $path=(string)(parse_url($url,PHP_URL_PATH)??'');$path=strtolower($path);
        if(preg_match('~^/pub/match/~',$path))return 'match-detail';
        if(preg_match('~^/pub/club/[^/]+/matches/?$~',$path))return 'club-index';
        if(preg_match('~^/pub/club/[^/]+/members/?$~',$path))return 'roster';
        if(preg_match('~^/pub/club/[^/]+/?$~',$path))return 'club-profile';
        if(preg_match('~^/pub/player/[^/]+/stats/?$~',$path))return 'player-stats';
        if(preg_match('~^/pub/player/[^/]+/matches/?$~',$path))return 'player-matches';
        if(preg_match('~^/pub/player/[^/]+/games/~',$path))return 'archive';
        if(preg_match('~^/pub/player/[^/]+/?$~',$path))return 'player-profile';
        return 'other';
    }

    private static function endpointClassForRequests(array $requests): string
    {
        $class='';foreach($requests as $request){$next=self::endpointClassForUrl((string)($request['url']??''));if($class==='')$class=$next;elseif($class!==$next)return 'mixed';}return $class!==''?$class:'other';
    }

    private static function multiGet(array $requests,string $token,int $requestedConcurrency,float $requestedRateCps=0.0,string $trafficClass='foreground'): array
    {
        if(!function_exists('curl_multi_init')||!function_exists('curl_init'))throw new ApiException('PHP cURL multi support is required for OAuth parallel API access.',503,'OAUTH_CURL_MULTI_REQUIRED');
        $transportCap=self::runtimeOpenFileCap();
        $requested=max(1,min(count($requests),$transportCap,max(1,$requestedConcurrency)));
        // Browser/admin callers may ask for a ceiling, but the shared coordinator is
        // authoritative. A zero requested rate means "use the learned server rate".
        $requestedRate=$requestedRateCps>0?max(0.5,min(120.0,$requestedRateCps)):120.0;
        $trafficClass=strtolower(trim($trafficClass))==='background'?'background':'foreground';
        $endpointClass=self::endpointClassForRequests($requests);
        $coordinator=new OAuthRateCoordinator($token);$coordinator->announceTraffic($trafficClass);
        $controllerBefore=$coordinator->snapshot();
        $currentLimit=$requested;
        $mh=curl_multi_init();if($mh===false)throw new \RuntimeException('Unable to initialize cURL multi.');
        if(defined('CURLMOPT_MAX_TOTAL_CONNECTIONS'))@curl_multi_setopt($mh,CURLMOPT_MAX_TOTAL_CONNECTIONS,$requested);
        if(defined('CURLMOPT_MAX_HOST_CONNECTIONS'))@curl_multi_setopt($mh,CURLMOPT_MAX_HOST_CONNECTIONS,$requested);
        if(defined('CURLMOPT_PIPELINING')&&defined('CURLPIPE_MULTIPLEX'))@curl_multi_setopt($mh,CURLMOPT_PIPELINING,CURLPIPE_MULTIPLEX);

        $next=0;$active=[];$results=[];$latencies=[];$statuses=[];$peak=0;$http2=false;$completionCounts=[];
        $started=microtime(true);$retryAfterMax=0;$rate429Seen=0;$launched=0;$reservation=null;$launchTimes=[];$launchRates=[];$controller=$controllerBefore;$feedbackStatuses=[];$feedbackLatencies=[];$feedbackRates=[];

        $add=static function(array $req,array $slot)use($mh,$token,&$active,&$launchTimes,&$launchRates):void{
            $headers=['Accept: application/json','Accept-Encoding: gzip','Authorization: Bearer '.$token,'User-Agent: PromoteToKing-OAuth/'.self::VERSION.' (https://www.promotetoking.org)'];
            foreach($req['headers'] as $n=>$v){if($v!=='')$headers[]=$n.': '.$v;}
            $ch=curl_init($req['url']);if($ch===false)throw new \RuntimeException('Unable to initialize Chess.com request.');
            $responseHeaders=[];
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers,CURLOPT_ENCODING=>'',CURLOPT_NOSIGNAL=>true,CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$responseHeaders):int{$t=trim($line);if($t!==''&&str_contains($t,':')){[$n,$v]=array_map('trim',explode(':',$t,2));$responseHeaders[strtolower($n)]=$v;}return strlen($line);}]+(defined('CURL_HTTP_VERSION_2TLS')?[CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_2TLS]:[]));
            $launchedAt=microtime(true);$launchTimes[]=$launchedAt;$launchRates[]=(float)($slot['rate_target_cps']??0);
            $id=spl_object_id($ch);$active[$id]=['handle'=>$ch,'req'=>$req,'started'=>$launchedAt,'headers'=>&$responseHeaders,'launch_rate_cps'=>(float)($slot['rate_target_cps']??0)];
            $rc=curl_multi_add_handle($mh,$ch);if($rc!==CURLM_OK){unset($active[$id]);curl_close($ch);throw new \RuntimeException('Unable to add Chess.com request to cURL multi.');}
        };
        $fill=static function()use(&$next,$requests,&$active,&$currentLimit,$add,&$peak,&$launched,&$reservation,$coordinator,$trafficClass,$requestedRate):void{
            while($next<count($requests)&&count($active)<$currentLimit){
                if($reservation===null){$reservation=$coordinator->reserveLaunch($trafficClass);if((float)($reservation['rate_target_cps']??0)>$requestedRate){$reservation['rate_target_cps']=$requestedRate;}}
                $now=microtime(true);$slot=(float)($reservation['slot']??$now);
                if($now+0.0005<$slot)break;
                if(empty($reservation['reserved'])){$reservation=null;continue;}
                $add($requests[$next++],$reservation);$reservation=null;$launched++;
            }
            $peak=max($peak,count($active));
        };
        try{
            $fill();
            do{
                $fill();
                do{$mrc=curl_multi_exec($mh,$running);}while($mrc===CURLM_CALL_MULTI_PERFORM);
                if($mrc!==CURLM_OK)throw new \RuntimeException('cURL multi execution failed.');
                while($info=curl_multi_info_read($mh)){
                    $ch=$info['handle'];$id=spl_object_id($ch);$meta=$active[$id]??null;if(!$meta)continue;
                    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$body=(string)curl_multi_getcontent($ch);
                    $elapsed=(microtime(true)-(float)$meta['started'])*1000;
                    if(defined('CURLINFO_TOTAL_TIME_T')){$us=(int)curl_getinfo($ch,CURLINFO_TOTAL_TIME_T);if($us>0)$elapsed=$us/1000;}
                    $latencies[]=$elapsed;$statuses[]=$status;$sec=time();$completionCounts[$sec]=($completionCounts[$sec]??0)+1;
                    if(defined('CURLINFO_HTTP_VERSION')){$hv=(int)curl_getinfo($ch,CURLINFO_HTTP_VERSION);if((defined('CURL_HTTP_VERSION_2_0')&&$hv===CURL_HTTP_VERSION_2_0)||(defined('CURL_HTTP_VERSION_2TLS')&&$hv===CURL_HTTP_VERSION_2TLS))$http2=true;}
                    $json=json_decode($body,true);$headers=$meta['headers'];$retryAfter=self::retryAfterSeconds((string)($headers['retry-after']??''));
                    $results[]=['id'=>$meta['req']['id'],'url'=>$meta['req']['url'],'status'=>$status,'status_text'=>'','headers'=>['content-type'=>(string)($headers['content-type']??'application/json'),'etag'=>(string)($headers['etag']??''),'last-modified'=>(string)($headers['last-modified']??''),'retry-after'=>(string)($headers['retry-after']??'')],'body'=>$body,'json'=>is_array($json)?$json:null,'elapsed_ms'=>(int)round($elapsed)];
                    curl_multi_remove_handle($mh,$ch);curl_close($ch);unset($active[$id]);
                    $launchRate=max(1.0,(float)($meta['launch_rate_cps']??$controller['rate_target_cps']??30));
                    if($status===429){
                        $rate429Seen++;$retryAfterMax=max($retryAfterMax,max(1,$retryAfter?:1));
                        $controller=$coordinator->feedback([429],[$elapsed],$endpointClass,$launchRate,$retryAfterMax);$reservation=null;
                        $feedbackStatuses=[];$feedbackLatencies=[];$feedbackRates=[];
                    }else{
                        $feedbackStatuses[]=$status;$feedbackLatencies[]=$elapsed;$feedbackRates[]=$launchRate;
                        if(count($feedbackStatuses)>=8){
                            sort($feedbackRates,SORT_NUMERIC);$attempt=$feedbackRates[(int)floor((count($feedbackRates)-1)*.5)]??$launchRate;
                            $controller=$coordinator->feedback($feedbackStatuses,$feedbackLatencies,$endpointClass,(float)$attempt,0);
                            $feedbackStatuses=[];$feedbackLatencies=[];$feedbackRates=[];$reservation=null;
                        }
                    }
                }
                $fill();
                if($active||$next<count($requests)){
                    $now=microtime(true);$untilLaunch=$reservation!==null?max(0.001,(float)($reservation['slot']??$now)-$now):0.01;
                    $wait=min(0.05,max(0.001,$untilLaunch));
                    if($active){$selected=curl_multi_select($mh,$wait);if($selected===-1)usleep(1000);}else{usleep((int)max(1000,min(50000,round($wait*1000000))));}
                }
            }while($next<count($requests)||$active);
        }finally{
            foreach($active as $m){@curl_multi_remove_handle($mh,$m['handle']);@curl_close($m['handle']);}curl_multi_close($mh);
        }
        // CTAR: a successful response already acquired by P2K's own server OAuth
        // transport is server-attested. Seed the shared canonical cache so an
        // immediately-following authoritative worker can reuse it once instead of
        // issuing a duplicate Chess.com request. Stale/error responses are excluded.
        try{
            $attestedGateway=new SharedChessGateway(null,\p2k_tp_config()['app']??[]);
            foreach($results as $attested){$status=(int)($attested['status']??0);if($status<200||$status>=300)continue;$body=(string)($attested['body']??'');if($body==='')continue;$attestedGateway->ingestAttestedOAuth((string)($attested['url']??''),$status,$body,is_array($attested['headers']??null)?$attested['headers']:[],120);}
        }catch(\Throwable){}
        $elapsedMs=max(1,(int)round((microtime(true)-$started)*1000));$processed=count($results);$ok=0;$r429=0;$errors=0;
        foreach($results as $r){$s=(int)$r['status'];if($s>=200&&$s<400)$ok++;elseif($s===429)$r429++;else$errors++;}
        $cps=$processed/($elapsedMs/1000);
        $launchCps=0.0;if(count($launchTimes)>1){$span=max(0.001,end($launchTimes)-$launchTimes[0]);$launchCps=(count($launchTimes)-1)/$span;}elseif(count($launchTimes)===1)$launchCps=1.0/max(0.001,$elapsedMs/1000);
        $attemptedRate=$launchRates!==[]?self::percentile($launchRates,.5):(float)($controllerBefore['rate_target_cps']??30);
        if($feedbackStatuses!==[]){sort($feedbackRates,SORT_NUMERIC);$attempt=$feedbackRates[(int)floor((count($feedbackRates)-1)*.5)]??$attemptedRate;$controller=$coordinator->feedback($feedbackStatuses,$feedbackLatencies,$endpointClass,(float)$attempt,0);}else{$controller=$coordinator->snapshot();}
        RuntimeTelemetry::record('chess_api_batch',['mode'=>'oauth-bearer','calls'=>$processed,'successes'=>$ok,'rate_429'=>$r429,'errors'=>$errors,'elapsed_ms'=>$elapsedMs,'cps'=>round($cps,3),'launch_cps'=>round($launchCps,3),'requested_rate_cps'=>$requestedRate,'rate_cps'=>(float)($controller['rate_target_cps']??$attemptedRate),'requested_concurrency'=>$requested,'concurrency'=>$currentLimit,'peak_in_flight'=>$peak,'retry_after_seconds'=>$retryAfterMax,'completion_counts'=>$completionCounts,'endpoint_class'=>$endpointClass,'traffic_class'=>$trafficClass,'controller'=>$controller]);
        return ['ok'=>true,'mode'=>'oauth-bearer','results'=>$results,'processed'=>$processed,'successes'=>$ok,'rate_429'=>$r429,'errors'=>$errors,'elapsed_ms'=>$elapsedMs,'cps'=>$cps,'launch_cps'=>$launchCps,'requested_rate_cps'=>$requestedRate,'rate_cps'=>(float)($controller['rate_target_cps']??$attemptedRate),'requested_concurrency'=>$requested,'concurrency'=>$currentLimit,'peak_in_flight'=>$peak,'transport_cap'=>$transportCap,'transport_capacity'=>$transportCap,'batch_size'=>count($requests),'retry_after_seconds'=>$retryAfterMax,'median_latency_ms'=>self::percentile($latencies,.5),'p95_latency_ms'=>self::percentile($latencies,.95),'http2_seen'=>$http2,'curl_http2_capable'=>self::curlHttp2Capable(),'endpoint_class'=>$endpointClass,'traffic_class'=>$trafficClass,'controller'=>$controller];
    }

    private static function safeReturn(string $value): string
    {
        $value=trim($value);if($value===''||!str_starts_with($value,'/')||str_starts_with($value,'//'))return '/ui-v2.html?ui=v2';$p=parse_url($value);if(!is_array($p))return '/ui-v2.html?ui=v2';$path=(string)($p['path']??'/ui-v2.html');$query=[];if(isset($p['query']))parse_str((string)$p['query'],$query);unset($query['oauth'],$query['oauth_result'],$query['simulatedOAuth']);return $path.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
    }
    private static function withOAuthResult(string $returnTo,string $result): string{$p=parse_url($returnTo);$path=(string)($p['path']??'/ui-v2.html');$q=[];if(isset($p['query']))parse_str((string)$p['query'],$q);unset($q['oauth'],$q['simulatedOAuth']);$q['oauth_result']=$result;return $path.'?'.http_build_query($q,'','&',PHP_QUERY_RFC3986);}
}
