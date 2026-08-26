<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\Http;
Http::json([
  'ok'=>false,
  'error'=>[
    'code'=>'LEGACY_SEED_IMPORT_RETIRED',
    'message'=>'The pre-v2.8 staging importer is retired. v2.8.0 uses fresh-init.php with two empty databases and no SQL staging tables.'
  ]
],410);
