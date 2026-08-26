# v2.9.22.8 page-load timeout audit

v2.9.22.6/7 could execute expensive reconstruction reconciliation queries during page bootstrap. A ready reconstruction snapshot implicitly called `review()`, Player reconciliation can aggregate large staging/Core board and game sets, Task Control also automatically requested selected-card detail and logs, and the reconstruction module issued a status request at script load. The resulting concurrent PHP/DB work could delay or time out the secured Team Points connection/status requests.

v2.9.22.8 makes page bootstrap status-only and all heavy reconciliation/detail/log work explicit or user-triggered.
