# POS regression checks

Run the pure suites without any application configuration or database:

```sh
php tests/pricing.php
php tests/receipts.php
php tests/business-day.php
node tests/money.js
node tests/actions.js
```

CI checks **PHP 7.4 and 8.3** with both **MariaDB 10.11 and MySQL 8.0**, plus
PHP/JavaScript syntax, pricing, receipts and real application endpoints. Branch
and pull-request runs test only; deployment is restricted to `main` after all
four matrix jobs pass. Dependencies are uploaded before callers. This ordered
FTP release is not atomic. Hosting `config.php` is preserved; database migrations
are manual.

The database suite deliberately refuses anything except an **empty** `pos_test`
database at `127.0.0.1`, explicitly enabled by `POS_TEST_MODE=1`, with the
disposable workflow credentials. It never reads or copies a developer's
`public_html/config.php`, never uses deployment secrets, and never drops or
resets an existing database. A temporary application copy receives only the
isolated test configuration. The CI service is discarded after the job.

With a new empty database and a user granted privileges only on that test DB:

```sh
POS_TEST_MODE=1 \
POS_TEST_DB_HOST=127.0.0.1 \
POS_TEST_DB_PORT=3306 \
POS_TEST_DB_NAME=pos_test \
POS_TEST_DB_USER=pos_test \
POS_TEST_DB_PASSWORD=pos-test-only \
php tests/integration.php
```

Covered: mandatory 10% after discount; all takeaway names 1–10; cash/card/mixed;
fixed, percent and full discounts; tetri rounding; amounts above 1,000 GEL;
legacy reprints; original close timestamps; saved fee/report consistency;
unchanged database schema; stale client/amount/order rejection; unsent-item
rejection; sequential repeat closes without additional charges.

Additional coverage: eight concurrent adds; concurrent send and add/close;
lock timeout and same-session reads; edit/cancel/day lifecycle; report totals and
complete CSV beyond 500 rows; page routing/config scope; redacted DB failures;
and idempotent index migration. Migration tests alter **only the explicitly
guarded test database**. JavaScript tests check action ordering, body-read
deadlines, no automatic retries, known validation failures and unknown outcomes.

For an optional scale comparison, add `POS_TEST_BENCHMARK=1` to the integration
command. It inserts 10,000 additional orders and 100,000 items, then compares
the new report with the actual report source from commit
`b825ff75ad84253524459dc8b63f50ca8a420454` on the same database. That git object must
be present locally. It prints three timing samples, median time and PHP peak
memory for each version. Only files in the disposable app are temporarily
replaced. Timings are environment dependent; the suite checks memory and
correctness, not a fragile millisecond speed threshold.

These checks do **not** certify physical printer delivery, Apache rewrite rules,
real browser behavior, production resource limits, or a five-day soak test.
Mutations have transactional locking and client-side duplicate guards, but no
durable request-idempotency ledger. After an uncertain response the operator
must inspect the table/history before attempting another write.
