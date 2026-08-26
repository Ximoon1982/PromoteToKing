<?php
declare(strict_types=1);

/*
 * Chess.com OAuth 2.0 proof-of-concept configuration.
 *
 * These three values are the values supplied/approved by Chess.com.
 * They are not passwords. Do not add access tokens, refresh tokens, or user
 * credentials to this file.
 *
 * Environment variables with the same purpose override these values, which is
 * convenient for a production host:
 *   P2K_OAUTH_APP_NAME
 *   P2K_OAUTH_CLIENT_ID
 *   P2K_OAUTH_REDIRECT_URL
 */
return [
    'name' => '',
    'client_id' => '',
    'redirect_url' => '',

    // Chess.com OAuth 2.0 / OIDC PKCE endpoints.
    'authorize_url' => 'https://oauth.chess.com/authorize',
    'token_url' => 'https://oauth.chess.com/token',
    'scope' => 'openid',
];
