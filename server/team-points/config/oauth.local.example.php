<?php
declare(strict_types=1);

/*
 * Promote to King Chess.com OAuth host configuration.
 *
 * Copy this file to oauth.local.php on the server and chmod it 600.
 * oauth.local.php is deliberately excluded from release payloads and is
 * preserved by numbered-release updaters, just like config.local.php.
 */
return [
    'name' => 'THE NAME APPROVED BY CHESS.COM',
    'client_id' => 'THE CLIENT ID ISSUED BY CHESS.COM',
    'redirect_url' => 'https://YOUR-HOST/auth/callback',
    'authorize_url' => 'https://oauth.chess.com/authorize',
    'token_url' => 'https://oauth.chess.com/token',
    'scope' => 'openid profile',
];
