<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\Http;

try {
    Auth::requireAdmin();
    throw new ApiException(
        'Data reconciliation was retired in Promote to King 2.11.0 because it targets the legacy Blue Team Points schema. Green production integrity is handled by the Team Points freshness, GFFL and GQAC workflows.',
        410,
        'RECONCILIATION_RETIRED'
    );
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]], $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K retired data reconciliation endpoint: ' . $e);
    Http::json(['ok'=>false,'error'=>['code'=>'RECONCILIATION_RETIRED','message'=>'Data reconciliation is retired in Promote to King 2.11.0.']], 410);
}
