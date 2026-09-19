# Nineteen Pleats POS

Visual demo file: `demo.html`

Use this demo only for checking layout and design. Real saving, login, MySQL, orders, and reports will work on PHP and MySQL hosting.

## Main app

Simple PHP and MySQL web POS for a small restaurant. It is made for normal cPanel/shared hosting.

## What is included

- Admin and cashier login
- Open and close business day
- 10 tables
- Product management
- Table orders
- Browser printing for cashier and kitchen receipts
- Kitchen receipt without prices
- Cash, card, and mixed payments
- Daily reports
- Responsive design for small laptop screens

## Hosting setup

1. Upload everything inside `public_html` to your hosting `public_html` folder.
2. Create a MySQL database and user in cPanel.
3. Import `database/schema.sql` in phpMyAdmin.
4. Copy `public_html/config.example.php` as `public_html/config.php`.
5. Put your MySQL database name, user, and password in `config.php`.
6. Open your domain.

## Performance and reliability audit

See [the detailed Georgian audit](docs/PERFORMANCE_AUDIT.ka.md) for confirmed
findings, measurements, remaining risks, and the production rollout procedure.
See [test instructions](tests/README.md) for local and CI verification.

Existing installations: review and apply the additive
`database/migrations/2026-09-19-audit-indexes.sql` off-peak after a backup.
Do not re-import `schema.sql` over a live database. Web requests and FTP deploys
never run schema migrations automatically.

Deployment preserves the hosting copy of `config.php`; a first installation
must create it manually using the steps above. Optional diagnostics and database
wait limits have defaults in `config.example.php`. Slow requests and errors are
written to the PHP error log as `GARBALIA` JSON records. Keep the log outside the
public document root and rotate it using the hosting settings.
