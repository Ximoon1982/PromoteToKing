<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/api/_common.php';
require_once dirname(__DIR__) . '/src/EventsShowcaseStore.php';

use P2K\EventsShowcase\EventsShowcaseStore;
use P2K\EventsShowcase\RevisionConflict;

$store = new EventsShowcaseStore(root_dir());
$action = trim((string)($_GET['action'] ?? 'state'));
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($action === 'state' && $method === 'GET') json_response(200, array_merge(['ok'=>true], $store->read()));
    if ($action === 'metrics' && $method === 'GET') json_response(200, ['ok'=>true,'metrics'=>$store->metrics()]);
    if ($action === 'save' && in_array($method, ['PUT','POST'], true)) {
        require_admin_write('events-showcase');
        $body = body_json(262144);
        $expected = filter_var($body['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($expected === false || $expected < 0) throw new InvalidArgumentException('revision must be a non-negative integer');
        json_response(200, array_merge(['ok'=>true], $store->save($body, (int)$expected)));
    }
    api_error(405, 'METHOD_NOT_ALLOWED', 'Method/action not allowed.');
} catch (RevisionConflict $error) {
    api_error(409, 'REVISION_CONFLICT', $error->getMessage(), ['current'=>$error->current]);
} catch (\P2K\TeamPoints\ApiException $error) {
    api_error($error->httpStatus, $error->errorCode, $error->getMessage(), $error->details);
} catch (InvalidArgumentException $error) {
    api_error(400, 'INVALID_REQUEST', $error->getMessage());
} catch (Throwable $error) {
    api_error(500, 'SERVER_ERROR', $error->getMessage());
}
