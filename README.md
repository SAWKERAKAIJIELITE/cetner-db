# HR Backend (نظام إدارة القوى البشرية)

The backend of the personnel system that replaces the workbook «نظام إدارة القوى البشرية — ملف واحد».
A Laravel 12 REST API on PostgreSQL 16, built the same way as the recruitment system: a modular monolith
(`app/Modules/*`), constraints in the database, append-only history, Arabic messages, device-bound staff login.

- **Status:** Step 3 of 7 (Step 1: foundation, staff accounts and roles, device-bound login, audit log; Step 2: settings; Step 3: the employee's file).
- **Documentation:** [`docs/code-documentation.md`](docs/code-documentation.md) (what and how),
  [`docs/decisions.md`](docs/decisions.md) (why, and the open questions).

## Quick start (development)

```bash
composer install
cp .env.example .env && php artisan key:generate
psql -U postgres -c "CREATE DATABASE hr;" -c "CREATE DATABASE hr_test;"
php artisan migrate --seed          # demo accounts: admin, supervisor, manager, entry, entry.two — password Password1!
php artisan serve
php artisan test                    # 160 tests on PostgreSQL
```

Log in like the desktop app will, with the reference client:

```bash
node tools/device-client.mjs register http://127.0.0.1:8000            # prints the device id and fingerprint
php artisan staff:approve-device <device-id> admin --fingerprint="…"
node tools/device-client.mjs login http://127.0.0.1:8000 <device-id> admin 'Password1!'
```

API documentation (local only): <http://127.0.0.1:8000/docs/api>.

## Plan

| Step | Builds |
| ---- | ------ |
| 1 ✓ | Foundation, staff identity and roles, device-bound login, access management, audit log |
| 2 ✓ | Settings: 27 managed lists (28 with the payment methods of 4.1), the organisational structure, holidays, branch settings |
| 3 ✓ | Employees: the 159 workbook columns, review and history, the identity guard, cycles, completeness |
| 4 ✓ | Records: movements, monthly attendance, conduct, debts and their payments; notifications and the sidebar counts (4.1) |
| 5 | Documents: the 13 attachments as uploaded files |
| 6 | Views and reports: the per-person forms as PDF, the two dashboards, Excel exports |
| 7 | Import of existing workbooks; on-premises deployment guide |
