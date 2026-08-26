# Chess.com OAuth proof of concept — v2.9.7

The real OAuth experiment is deliberately isolated from Promote to King's production authentication.

- Test page: `OAuthTest.php?oauth=2`
- Production simulated OAuth remains `?oauth=1` and is unchanged.
- The POC uses Authorization Code + PKCE (S256), state and nonce handling, server-side token exchange and HttpOnly session cookies.
- It is a POC only; it is not a production authorization source.

Chess.com directs developers who need authenticated-user or connected-board integrations to request OAuth access through its Developer Community and provides OAuth 2.0 documentation there. Use only values issued/approved for the P2K application.

## Persistent credentials / registration values

Do not place deployment credentials in `config/oauth-test.php`. That file is a release default/example and can change with a package.

Run:

```bash
./install-oauth-poc-v2.9.7.sh
```

The installer creates:

`server/team-points/config/oauth.local.php`

with mode `0600`. The protected Team Points config directory is server-denied by `.htaccess`. The file is excluded from standalone and incremental release payloads and explicitly protected by the updater. If it already exists, the installer refuses to overwrite it.

Environment variables `P2K_OAUTH_APP_NAME`, `P2K_OAUTH_CLIENT_ID`, `P2K_OAUTH_REDIRECT_URL`, `P2K_OAUTH_AUTHORIZE_URL`, `P2K_OAUTH_TOKEN_URL` and `P2K_OAUTH_SCOPE` may override the protected file for a test host.
