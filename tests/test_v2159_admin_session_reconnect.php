<?php
declare(strict_types=1);

namespace P2K\TeamPoints {
    class PreviewIsolation {
        public static function active(): bool { return false; }
        public static function adminReadAuthorized(): bool { return false; }
    }
    class ApiException extends \RuntimeException {
        public function __construct(string $message, public int $httpStatus, public string $errorCode) {
            parent::__construct($message, $httpStatus);
        }
    }
}

namespace {
    use P2K\TeamPoints\Auth;
    use P2K\TeamPoints\ApiException;

    function p2k_tp_config(): array {
        return ['app'=>['allowed_origin'=>'https://www.promotetoking.org',
            'admin_token'=>'fixed-server-token',
            'admin_session_lifetime_seconds'=>1800]];
    }
    require dirname(__DIR__).'/server/team-points/src/Auth.php';

    $dir=sys_get_temp_dir().'/p2k-auth-2159-'.bin2hex(random_bytes(5));
    if (!mkdir($dir,0700,true)) throw new \RuntimeException('Cannot create temporary session directory');
    $orig=(string)session_save_path();
    $origName=(string)session_name();
    $origId=(string)session_id();
    ob_start();
    try {
        session_save_path($dir);
        $_SERVER['HTTPS']='on';
        $_SERVER['HTTP_HOST']='www.promotetoking.org';
        $_SERVER['REQUEST_METHOD']='POST';
        $_SERVER['HTTP_ORIGIN']='https://www.promotetoking.org';
        $_SERVER['HTTP_REFERER']='https://www.promotetoking.org/RecruitMatch.html';
        unset($_SERVER['HTTP_X_P2K_ADMIN_TOKEN'], $_SERVER['HTTP_X_P2K_CSRF']);
        $first=Auth::createAdminSession('ximoon');
        $firstId=session_id();
        if ($first==='' || $firstId==='') throw new \RuntimeException('First admin session missing');
        $second=Auth::createAdminSession('Ximoon');
        if ($first!==$second || session_id()!==$firstId) {
            throw new \RuntimeException('Repeated same-user connect rotated a valid session');
        }
        $_SERVER['HTTP_X_P2K_CSRF']=$first;
        Auth::requireAdmin();
        if ((int)$_SESSION['p2k_tp_expires']<=time()) {
            throw new \RuntimeException('Validated admin session did not slide its expiry');
        }

        // An expired Team Points session must return a recoverable 401,
        // rather than the permanent ADMIN_AUTH_FAILED reserved for a wrong
        // explicitly provided server-to-server token.
        $_SESSION['p2k_tp_expires']=time()-1;
        unset($_SERVER['HTTP_X_P2K_CSRF']);
        try { Auth::requireAdmin(); throw new \RuntimeException('Expired session was accepted'); }
        catch (ApiException $e) {
            if ($e->httpStatus!==401 || $e->errorCode!=='ADMIN_SESSION_REQUIRED') throw $e;
        }

        $replacement=Auth::createAdminSession('ximoon');
        if ($replacement===$first || session_id()===$firstId) {
            throw new \RuntimeException('Expired session did not regenerate session ID and CSRF');
        }

        // Username changes are a new authentication boundary.
        $replacedId=session_id();
        $identityToken=Auth::createAdminSession('another_admin');
        if ($identityToken===$replacement || session_id()===$replacedId) {
            throw new \RuntimeException('Identity switch failed to regenerate session');
        }

        $_SESSION['p2k_tp_expires']=time()-1;
        $_SERVER['HTTP_X_P2K_ADMIN_TOKEN']='incorrect-server-token';
        try { Auth::requireAdmin(); throw new \RuntimeException('Incorrect machine token was accepted'); }
        catch (ApiException $e) {
            if ($e->httpStatus!==403 || $e->errorCode!=='ADMIN_AUTH_FAILED') throw $e;
        }
    } finally {
        if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
        @session_save_path($orig);
        @session_name($origName);
        @session_id($origId);
        foreach (glob($dir.'/*')?:[] as $file) @unlink($file);
        @rmdir($dir);
        ob_end_clean();
    }
    echo "PASS same-admin reconnect preserves CSRF and session ID\n";
    echo "PASS expired/admin-changed sessions regenerate securely\n";
    echo "PASS browser expiry recoverable, wrong machine token permanently denied\n";
}
