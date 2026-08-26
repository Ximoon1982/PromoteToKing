# v2.9.22.7 focused reconciliation audit

Observed production symptom: backend reported actionable Club differences but the difference table rendered empty with `Cannot read properties of null (reading 'local_status')`.

Root cause: four result builders iterated arrays by reference and then assigned the reference variable to `null`. In PHP this overwrote the final array element with `null`. The browser failed while mapping the resulting rows.

Correction:
- replace reference cleanup assignment with `unset()`;
- filter malformed rows client-side defensively;
- expose staged Club reclassification;
- normalize cancelled 0-0 draws before board-count validation;
- keep cancelled match board count non-authoritative for difference detection;
- expose true issue totals separate from the 500-row detail limit.

Validation is intentionally focused on affected Fresh Reconstruction / incremental reconciliation behavior and package integrity.
