<?php
declare(strict_types=1);
require_once __DIR__.'/../src/TrophyGalleryStore.php';
use P2K\TrophyGallery\TrophyGalleryStore;
try { [$media,$path]=(new TrophyGalleryStore())->media((string)($_GET['id']??'')); header('Content-Type: '.(string)$media['mime']);header('Content-Length: '.filesize($path));header('Cache-Control: public, max-age=31536000, immutable');header('X-Content-Type-Options: nosniff');readfile($path); }
catch(Throwable){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'Artwork not found.';}
