# Verification

Run from the package root:

```text
node tests/run-tests.js
python tests/validate_package.py
python tests/test_tracking_features.py
python tests/test_server_storage.py
python tests/test_php_backend.py
```

The checks cover the unchanged v2.1.2 shell stylesheet, local resource integrity, JavaScript/Python/PHP syntax, null-safe scheduled logs, optimized registration-summary prefiltering, add-and-capture, follow/unfollow with archive retention, finished-data cleanup, history snapshots, v2.1.2-to-unified tracking migration with source removal, archived follow-back after upstream 404, log refresh wiring, and both packaged server implementations.
