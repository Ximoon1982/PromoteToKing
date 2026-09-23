<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\Green\GreenCompatibility;
use P2K\Green\GreenRepository;

try{
    $repo=GreenRepository::open();
    $native=$repo->ensureRuntimeSchema();
    $compat=(new GreenCompatibility($repo))->ensureSchema();
    $caps=$repo->maxRatingBackfillSnapshot();
    echo json_encode(['ok'=>true,'native'=>$native,'compatibility'=>$compat,'max_rating_backfill'=>$caps],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),PHP_EOL;
}catch(Throwable $e){
    fwrite(STDERR,'Schema convergence failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
