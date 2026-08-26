# Migration notes v2.10.6.4

- Source-only MCA acquisition corrective.
- No Core schema change.
- No Analytics schema change.
- No Blue/Green routing change.
- No reset or reseed.
- Existing MCA source files and derived data are retained.
- Run a fresh Source sync after deployment to repopulate queue entries from the authoritative paginated index CSV links.
