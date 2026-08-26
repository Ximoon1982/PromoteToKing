from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
P = ROOT / 'server/team-points/public/fresh-init.php'
text = P.read_text(encoding='utf-8')

# Interrupted DDL may leave the exact object set while schema version INSERTs
# never ran. Exact structural identity must be enough to adopt the databases;
# installSchema() completes version markers idempotently afterward.
assert "$exactExisting=(!$emptyPair&&init_exact_v280_schema($core,$coreSchema)&&init_exact_v280_schema($analytics,$analyticsSchema));" in text
segment = text[text.index('$exactExisting='):text.index('if(!$emptyPair&&!$exactExisting)')]
assert 'schemaInstalled()' not in segment
assert '$repo->installSchema();' in text
assert "if(!$repo->schemaInstalled())throw new ApiException('v2.8.0 schema installation did not complete.'" in text
print('v2.8.0 initializer Recovery Fix 2 assertions passed')
