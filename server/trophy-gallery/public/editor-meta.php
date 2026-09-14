<?php
require_once __DIR__.'/../../team-points/src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\Http;

function p2k_trophy_r534_root() {
    $root = dirname(__DIR__, 3).'/data/trophy-gallery';
    if (!is_dir($root)) {
        if (!mkdir($root, 0750, true) && !is_dir($root)) {
            throw new RuntimeException('Trophy metadata directory is unavailable.');
        }
    }
    return $root;
}
function p2k_trophy_r534_file() {
    return p2k_trophy_r534_root().'/editor-meta.json';
}
function p2k_trophy_r534_lock() {
    return p2k_trophy_r534_root().'/.editor-meta.lock';
}
function p2k_trophy_r534_value($array, $key, $default) {
    return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
}
function p2k_trophy_r534_read() {
    $file = p2k_trophy_r534_file();
    if (!is_file($file)) {
        return array('version'=>1, 'records'=>array());
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        throw new RuntimeException('Trophy editor metadata is unavailable.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Trophy editor metadata is invalid.');
    }
    $records = p2k_trophy_r534_value($data, 'records', array());
    return array(
        'version'=>1,
        'records'=>is_array($records) ? $records : array()
    );
}
function p2k_trophy_r534_id($value) {
    $id = strtolower(trim((string)$value));
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,79}$/', $id)) {
        throw new RuntimeException('Invalid Trophy ID.');
    }
    return $id;
}
function p2k_trophy_r534_mode($value) {
    $mode = strtolower(trim((string)$value));
    return in_array($mode, array('custom','vignette','none'), true) ? $mode : 'vignette';
}
function p2k_trophy_r534_tables($value) {
    if (!is_array($value)) return array();
    $out = array();
    $rows = array_slice($value, 0, 20);
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $url = trim((string)p2k_trophy_r534_value($row, 'url', ''));
        if ($url === '') continue;
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string)p2k_trophy_r534_value($parts, 'scheme', '')) : '';
        $host = is_array($parts) ? (string)p2k_trophy_r534_value($parts, 'host', '') : '';
        if (!is_array($parts) || !in_array($scheme, array('http','https'), true) || $host === '') {
            throw new RuntimeException('Result table links must use HTTP or HTTPS.');
        }
        $label = trim((string)p2k_trophy_r534_value($row, 'label', 'Result table'));
        if ($label === '') $label = 'Result table';
        if (strlen($label) > 120) {
            throw new RuntimeException('A result table label is too long.');
        }
        $out[] = array('label'=>$label, 'url'=>$url);
    }
    return $out;
}
function p2k_trophy_r534_save($id, $mode, $tables) {
    $lock = fopen(p2k_trophy_r534_lock(), 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Trophy editor metadata lock is unavailable.');
    }

    $tmp = '';
    try {
        $data = p2k_trophy_r534_read();
        $data['records'][$id] = array(
            'modal_media_mode'=>$mode,
            'result_tables'=>$tables,
            'updated_at'=>gmdate(DATE_ATOM)
        );

        $json = json_encode($data);
        if ($json === false) {
            throw new RuntimeException('Trophy editor metadata could not be encoded.');
        }
        $json .= "\n";

        $file = p2k_trophy_r534_file();
        $tmp = $file.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Trophy editor metadata could not be staged.');
        }
        chmod($tmp, 0640);
        if (!rename($tmp, $file)) {
            throw new RuntimeException('Trophy editor metadata could not be saved.');
        }
        $tmp = '';
        flock($lock, LOCK_UN);
        fclose($lock);
        return $data['records'][$id];
    } catch (Throwable $e) {
        if ($tmp !== '') @unlink($tmp);
        flock($lock, LOCK_UN);
        fclose($lock);
        throw $e;
    }
}

try {
    $action = strtolower(trim((string)p2k_trophy_r534_value($_GET, 'action', 'list')));

    if ($action === 'list') {
        Http::method('GET');
        $payload = p2k_trophy_r534_read();
        $payload['ok'] = true;
        Http::json($payload);
    }
    if ($action === 'get') {
        Http::method('GET');
        $id = p2k_trophy_r534_id(p2k_trophy_r534_value($_GET, 'id', ''));
        $payload = p2k_trophy_r534_read();
        Http::json(array('ok'=>true, 'id'=>$id, 'meta'=>p2k_trophy_r534_value($payload['records'], $id, array())));
    }

    Auth::requireAdmin();

    if ($action === 'save') {
        Http::method('POST');
        $body = Http::body();
        $id = p2k_trophy_r534_id(p2k_trophy_r534_value($body, 'id', ''));
        $mode = p2k_trophy_r534_mode(p2k_trophy_r534_value($body, 'modal_media_mode', 'vignette'));
        $tables = p2k_trophy_r534_tables(p2k_trophy_r534_value($body, 'result_tables', array()));
        Http::json(array(
            'ok'=>true,
            'id'=>$id,
            'meta'=>p2k_trophy_r534_save($id, $mode, $tables)
        ));
    }

    throw new ApiException('Unknown Trophy editor metadata action.', 404, 'NOT_FOUND');
} catch (ApiException $e) {
    Http::json(array(
        'ok'=>false,
        'error'=>array('code'=>$e->errorCode, 'message'=>$e->getMessage())
    ), $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K Trophy editor metadata: '.$e);
    Http::json(array(
        'ok'=>false,
        'error'=>array('code'=>'TROPHY_EDITOR_META_ERROR', 'message'=>$e->getMessage())
    ), 400);
}
