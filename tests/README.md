# Service-charge regression checks

Run the pure suites without any application configuration or database:

```sh
php tests/pricing.php
php tests/receipts.php
node tests/money.js
```

The deployment workflow runs PHP syntax checks on **PHP 7.4 and 8.3**, JavaScript
syntax checks, the shared pricing fixtures, receipt/reprint checks, and actual
JSON/legacy close endpoint checks on an isolated MariaDB 10.11 service. Branch
pushes run tests only; deployment is restricted to `main` after both test jobs
pass. The new helper is uploaded first, then its pricing backends, then the full
site. This ordered FTP release is not atomic: old checkout screens can briefly
be asked to refresh before the new checkout is uploaded.

The database suite deliberately refuses anything except an **empty** `pos_test`
database at `127.0.0.1`, explicitly enabled by `POS_TEST_MODE=1`, with the
disposable workflow credentials. It never reads or copies a developer's
`public_html/config.php`, never uses deployment secrets, and never drops or
resets an existing database. A temporary application copy receives only the
isolated test configuration. The CI service is discarded after the job.

Covered: mandatory 10% after discount; all takeaway names 1–10; cash/card/mixed;
fixed, percent and full discounts; tetri rounding; amounts above 1,000 GEL;
legacy reprints; original close timestamps; saved fee/report consistency;
unchanged database schema; stale client/amount/order rejection; unsent-item
rejection; sequential repeat closes without additional charges.

These checks do **not** certify physical printer delivery, the user's browser,
production MySQL resource limits, or full concurrent add/close safety. The
minimal schema-preserving release does not add request idempotency or redesign
order locking.
