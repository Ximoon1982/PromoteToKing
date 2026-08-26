#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
api = (ROOT / "assets/js/shared/api-client.js").read_text(encoding="utf-8")
oauth = (ROOT / "assets/js/shared/simulated-oauth.js").read_text(encoding="utf-8")
css = (ROOT / "assets/css/simulated-oauth.css").read_text(encoding="utf-8")

assert 'let concurrentMode = false;' in api
assert 'let configuredConcurrency = 1;' in api
assert 'let adaptiveConcurrency = 1;' in api
assert 'function setConcurrentMode(enabled)' in api
assert 'adaptiveConcurrency = Math.max(1, Math.floor(adaptiveConcurrency / 2));' in api
assert 'nextAllowedRequestAt = Math.max' in api and 'retryAfterMs' in api
assert 'p2k-api-concurrency-change' in api
assert 'get configuredConcurrency()' in api
assert 'apiConcurrent: false' in oauth
assert 'candidate.apiConcurrent === true' not in oauth
assert 'p2kAuthConcurrentApi' not in oauth
assert 'setConcurrentMode?.(false)' in oauth
assert '.p2k-auth-switch' in css
print("Simulated OAuth adaptive API-concurrency tests passed.")
