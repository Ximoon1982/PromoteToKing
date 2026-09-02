# Promote to King verification

Run the canonical v2.11.1 test entry point from the repository root:

```bash
python tools/test-suite/p2k_test_suite.py audit --json
python tools/test-suite/p2k_test_suite.py static
python tools/test-suite/p2k_test_suite.py regression
python tools/test-suite/p2k_test_suite.py browser
python tools/test-suite/p2k_test_suite.py full
```

- `audit` inventories every Python module, pytest function, browser gate, PHP harness and required legacy gate.
- `static` adds Python compilation, JavaScript parsing, PHP linting and the canonical JavaScript feature regression.
- `regression` adds every pytest-style module, then runs every legacy executable `test_*.py` module separately so historical `SystemExit` checks cannot abort pytest collection.
- `browser` executes every discovered `browser*.py` gate without repeating source regressions.
- `full` combines the regression and browser profiles.

Install pinned test-only dependencies with `python -m pip install -r tests/requirements.txt`.

`tests/validate_package.py` remains the exact packaging/archive gate and is intentionally separate from source-tree regression. The GitHub workflow `.github/workflows/p2k-v2111-regression.yml` runs source and browser profiles independently. These gates must not mutate production configuration, databases, caches, or external Chess.com state.
