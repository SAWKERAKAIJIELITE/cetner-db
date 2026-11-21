# HR Backend: Technical Documentation (نظام إدارة القوى البشرية)

Status: **Step 1** (foundation, staff identity and roles, device-bound login, access and user management, audit log).
Updated at the end of every step.

Audience: developers joining or reviewing the backend.
Companion file: `decisions.md` explains *why* things are the way they are. Decision ids (D-0xx) refer to it; open
questions are its OD-xx.

---

## 1. Overview

The backend and database of the personnel system that replaces the workbook «نظام إدارة القوى البشرية — ملف واحد»:
one file per person (159 fields updated on monthly, quarterly, semi-annual and annual cycles), the records of
movements, monthly attendance, conduct and debts, the per-person forms and the two dashboards. A Laravel REST API on
PostgreSQL, built the same way as the recruitment system (D-001), as a separate application with its own database.

**Data entry employees** enter the information; the **general manager** views it; the **system administrator** runs
the system (D-006). They use a desktop app (React + Electron, assumed, OD-3) and log in from approved devices only
(D-013).

## 2. Module status and plan

| Step | Module | Status | What it covers from the workbook |
| ---- | ------ | ------ | -------------------------------- |
| 1 | Foundation | Done | Project, PostgreSQL, module loader, UTC, Arabic JSON and errors, tests on PostgreSQL |
| 1 | Identity | Done | Staff users, one role each (data entry, general manager, administrator), device-bound login, access and user management, first administrator from the shell |
| 1 | Audit | Done | `audit_events`, append-only, written with each action; read API |
| 2 | Settings | Planned | «الإعدادات»: the 29 lists, the six-level organisational structure, holidays, work settings (hours, leave balance, the four review periods) |
| 3 | Employees | Planned | «قاعدة بيانات الموظفين» and «دليل الحقول»: the 159 fields with the sheet's checks, field definitions per update cycle, completeness and missing fields, review periods per cycle, change history |
| 4 | Records | Planned | «سجل الحركات», «سجل الدوام الشهري», «سجل السلوك الوظيفي», «سجل الديون» |
| 5 | Documents | Planned | The 13 attachments (photo, ID, family statement, certificate, …) as uploaded files instead of links |
| 6 | Views and reports | Planned | «عرض الفرد» and the three forms as PDF, «داش بورد الموظفين», «داش بورد الحركات», Excel exports |
| 7 | Import and deployment | Planned | Importing existing workbooks (staged, reviewed, all-or-nothing); the on-premises deployment guide |
| later | Integration | Deferred | The recruitment system (OD-6) |

## 3. Tech stack

- PHP 8.2+ (developed on 8.3) with `pdo_pgsql`, `mbstring`, `openssl`, `intl`
- Laravel 12, PostgreSQL 16, Laravel Sanctum 4 (personal access tokens)
- Scramble (API documentation)
- PostgreSQL features used: UUID, `macaddr`, `inet`, `jsonb`, `text[]` with GIN indexes, CHECK constraints, partial
  unique indexes, PL/pgSQL triggers
- PHPUnit through `php artisan test`; Pint for the code style
- Node 18+ only for the reference device client (`tools/device-client.mjs`)

## 4. Local setup

```bash
composer install
cp .env.example .env            # then check the DB_ values
php artisan key:generate
createdb hr && createdb hr_test # or: psql -c "CREATE DATABASE hr;" -c "CREATE DATABASE hr_test;"
php artisan migrate --seed      # local: the catalogue and four demo accounts (section 7.8)
php artisan serve
php artisan test
```

Key `.env` values:

```dotenv
APP_LOCALE=ar
APP_FALLBACK_LOCALE=en
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=hr
DB_USERNAME=postgres
DB_PASSWORD=secret
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
CACHE_STORE=database
HR_TIMEZONE=Asia/Damascus        # optional, the default
```

Framework files changed from the Laravel defaults:

| File | Change |
| ---- | ------ |
| `bootstrap/providers.php` | Registers `ModuleServiceProvider` |
| `bootstrap/app.php` | `UnescapedUnicodeJson` as a global middleware (D-005); aliases `staff.active`, `can.any`; guests are never redirected; errors under `api/*` as JSON with Arabic 401, 403, 404 and 429 (D-011) |
| `config/database.php` | `pgsql` by default, `'timezone' => 'UTC'` (D-004) |
| `config/auth.php` | Guards `web` (kept for Sanctum) and `staff` (Sanctum), provider `staff_users` |
| `config/sanctum.php` | No stateful guard; global `expiration` null: the lifetime is the token's (D-009) |
| `config/staff_auth.php` | Login payload prefix, challenge lifetime (60 s), pending devices cap (100) |
| `config/hr.php` | The business time zone (Asia/Damascus, D-012) |
| `config/scramble.php` | Published, defaults |
| `app/Providers/AppServiceProvider.php` | Sanctum token model, mass-assignment guard (D-016), `Gate::before` (D-011), rate limiters (D-014), Scramble Bearer scheme (D-021) |
| `phpunit.xml` | Tests on PostgreSQL `hr_test` |
| `database/migrations` | Laravel's users and jobs migrations removed; the cache table kept |
| `lang/ar/*`, `lang/en/*` | `auth.php` (login, 401, 403, 404, 429), `identity.php` (business rules), Arabic `validation.php` with the field names, `pagination.php`, `passwords.php` |

## 5. Architecture

### 5.1 Request flow (D-010)

```
HTTP request
  → global middleware (UnescapedUnicodeJson, D-005)
  → route (middleware: throttle, auth:staff, staff.active, can:permission)
  → FormRequest    (input validation, Arabic messages)
  → Controller     (thin: passes validated input to an Action, returns a Resource)
  → Action         (business rules for one use case, one transaction, audit row inside it)
  → Eloquent models → PostgreSQL (constraints and triggers as the last guard)
  → Resource       (whitelist of fields returned)
```

### 5.2 Layout

```
app/
├── Http/Middleware/UnescapedUnicodeJson.php
├── Providers/AppServiceProvider.php, ModuleServiceProvider.php
├── Support/BusinessDay.php (Damascus days), ArabicText.php (search key, digits)
└── Modules/
    ├── Identity/
    │   ├── Actions/        RegisterDevice, ApproveDevice, RevokeDevice, IssueLoginChallenge, LoginStaff,
    │   │                   ManageRolePermissions, ManageStaffAccess, RegisterStaffUser
    │   ├── Console/        ApproveDeviceCommand (staff:approve-device), CreateAdminCommand (staff:create-admin)
    │   ├── Database/       Migrations (2), Seeders (RolesAndPermissionsSeeder, DevUsersSeeder)
    │   ├── Enums/          DeviceStatus, StaffRole
    │   ├── Http/
    │   │   ├── Controllers/ StaffAuthController, StaffDeviceController, AccessController, StaffUserController
    │   │   ├── Middleware/  EnsureStaffIsActive, RequireAnyPermission
    │   │   ├── Requests/    Register device, Challenge, Login, Approve device, Reason, Device index,
    │   │   │                Add role permissions, Set user role, User index, Register user, Reset password
    │   │   └── Resources/   StaffUser, StaffUserSummary, Role, Permission, StaffDevice, DeviceRegistration, Login
    │   ├── Models/         StaffUser, Role, Permission, StaffDevice, DeviceLoginChallenge, AccessToken
    │   ├── Support/        Username, StaffPassword, DeviceCrypto, AccessInvariants, LoginResult
    │   └── routes.php
    └── Audit/
        ├── Database/Migrations (1)
        ├── Http/            AuditEventController, AuditEventIndexRequest, AuditEventResource
        ├── Models/AuditEvent.php
        ├── Support/         AuditTrail (the writer), AuditEvents (Arabic labels)
        └── routes.php
```

`ModuleServiceProvider` loads each module's `Database/Migrations`, its `routes.php` under the prefix `/api/v1/staff`
with the `api` middleware group, and every `Console/*Command.php`.

## 6. Conventions

- **Primary keys:** UUID (D-003); integer keys for the managed lists from Step 2. Readable numbers are separate columns.
- **Time:** `timestamptz`, the connection in UTC (D-004); business days are Damascus days (D-012).
- **Numbers:** `NUMERIC` for money and rates, never float (from Step 2).
- **Foreign keys:** real foreign keys, all `RESTRICT`, no cascade, no `ON UPDATE` (D-007). People are deactivated.
- **Constraints:** business rules PostgreSQL can express (CHECK, UNIQUE, partial indexes, triggers) are enforced
  there as well as in PHP.
- **Mass assignment:** `$fillable` holds caller-supplied columns only; rule-controlled columns are set with
  `forceFill()` in Actions (D-016).
- **Responses:** Resource classes; literal status codes; return types on controller methods (D-021).
- **Authorization:** permission slugs (`area.action`) through `can:`; resource-level rules through Policies (D-011).
- **Errors:** login failures are deliberately generic (D-014); every error body is Arabic JSON (D-011).
- **Arabic digits:** personal numbers (and later national numbers, phones) are normalised to ASCII digits.
- **Messages:** business-rule messages in `lang/*/identity.php` (later one file per module); field names in
  `lang/ar/validation.php`.
- **Audit:** every action that changes data calls `AuditTrail::record()` inside its transaction, with an Arabic label
  in `AuditEvents::LABELS` (D-017).

## 7. Identity module

### 7.1 Actor and guard

| Actor | Table | Model | Guard |
| ----- | ----- | ----- | ----- |
| Staff | `staff_users` | `StaffUser` | `auth:staff` (Sanctum) |

The token table is Sanctum's `personal_access_tokens` with uuid owners and `staff_device_id` (custom model
`AccessToken`). There is no other actor.

### 7.2 Database schema

**`roles`**: `id`, `slug` unique, `label_ar`, timestamps.

**`permissions`**: `id`, `slug` unique, `label_ar`, `read_only` (it only shows data), timestamps.

**`permission_role`**: composite key, foreign keys `RESTRICT`. The administrator's role has no rows.

**`staff_users`**: `id` uuid; `personal_number` unique (CHECK digits, 4 to 50); `username` unique (CHECK
`^[a-z._-]{3,50}$`); `first_name`, `last_name` (CHECK at least 3 characters, no digits); `password` (bcrypt);
`is_active`; `last_login_at`; `deactivated_at`, `deactivated_by`, `deactivation_reason` (CHECK: none when active; a
date and a non-blank reason when inactive); `role_id` (the one role), `role_assigned_by`, `role_assigned_at`
(CHECK: nothing assigned without a role); timestamps.

**`staff_devices`**

| Column | Notes |
| ------ | ----- |
| id | uuid |
| label, os_info | from the device |
| public_key | canonical SPKI PEM |
| fingerprint | SHA-256 of the key's DER bytes, lowercase hex (CHECK), unique |
| reported_mac | `macaddr`, required, informational only |
| status | `pending` / `approved` / `revoked` (varchar + CHECK), default pending |
| staff_user_id | the one owner, set at approval |
| approved_by, approved_at | `approved_by` null for console approvals |
| revoked_by, revoked_at, revoke_reason | |
| last_seen_at | each successful login |

CHECKs: an approved device has `approved_at` and an owner; a revoked one `revoked_at`. Partial unique index: one
approved device per user (a revoked device frees the slot and stays as history).

**`device_login_challenges`**: `id`, `staff_device_id` (cascade: the one exception to D-007), `challenge`,
`expires_at`, `used_at`, `created_at`. Expired for a day: pruned by `php artisan model:prune`.

**`personal_access_tokens`**: Sanctum's columns with `tokenable_id` uuid, `expires_at`, and `staff_device_id`.

### 7.3 Roles and permissions (D-006, D-019)

`✓` = given by the role by default. The system administrator holds every permission, always (computed, not stored,
not editable). The general manager's role takes read-only permissions only.

| Permission | Read-only | Data entry | General manager | System admin |
| ---------- | :-------: | :--------: | :-------------: | :----------: |
| `employees.view` | ✓ | ✓ | ✓ | ✓ |
| `employees.manage` | | ✓ | — | ✓ |
| `records.view` | ✓ | ✓ | ✓ | ✓ |
| `records.manage` | | ✓ | — | ✓ |
| `documents.view` | ✓ | ✓ | ✓ | ✓ |
| `documents.manage` | | ✓ | — | ✓ |
| `imports.manage` | | ✓ | — | ✓ |
| `settings.manage` | | — | — | ✓ |
| `reports.view` | ✓ | — | ✓ | ✓ |
| `audit.view` | ✓ | — | ✓ | ✓ |
| `audit.view_related` | ✓ | ✓ | — | ✓ |
| `users.manage` | | — | — | ✓ |
| `devices.approve` | | — | — | ✓ |

Role slugs: `data_entry`, `general_manager`, `system_admin` (`App\Modules\Identity\Enums\StaffRole`). The seeder
(`RolesAndPermissionsSeeder::PERMISSIONS`, `ROLES`) upserts the catalogue and gives a role its defaults only when the
role is first created; afterwards the administrator owns the data (D-015).

`StaffUser::permissionSlugs()` is the role's permissions (all for the administrator, none without a role), cached on
the instance; `refresh()` and `forgetPermissions()` clear it. `Gate::before` answers a permission slug with `true`
when held, otherwise `null`.

### 7.4 Rules of access management (D-015)

- The administrator's role is not edited: 422 on `role`.
- The general manager's role refuses a permission that is not read-only: 422 on `permissions`, naming them.
- Only the system administrator gives the system administrator's role: 403 with its reason.
- A change that would leave no active user holding `users.manage` is refused and rolled back: 422.
- Nobody deactivates themselves: 422 on `user`. Deactivating deletes every token of the user.
- A new password (a forgotten one) deletes every token of the user; the device stays approved.
- Removing a permission a role does not hold: 404.
- Every change is audited (`access.*`, section 8).

### 7.5 API reference

Base path: `/api/v1/staff`. Send `Accept: application/json`. Protected routes need `Authorization: Bearer <token>`.
Interactive documentation: `/docs/api` (local only, D-021).

**Public (device enrolment and login)**

| Method | Path | Throttle | Purpose |
| ------ | ---- | -------- | ------- |
| POST | `/devices/register` | 20/hour per IP | Register a device: `label`, `public_key` (P-256 SPKI PEM), `reported_mac`, `os_info`. 201 new, 200 existing (by key): `{id, status, fingerprint}` |
| GET | `/devices/{device}/status` | 30/min per IP | `{id, status, fingerprint}` for the waiting screen; 404 unknown |
| POST | `/auth/challenge` | 60/min per IP, 10/min per device | `{device_id}` → 201 `{challenge_id, challenge, expires_at}`; approved devices only (generic 422 otherwise) |
| POST | `/auth/login` | 5/min per username and IP, 30/min per IP | `{device_id, challenge_id, signature, username, password}` → `{token, token_type, expires_at, user}` (user with role and permissions) |

**Signed-in staff**

| Method | Path | Permission | Purpose |
| ------ | ---- | ---------- | ------- |
| POST | `/auth/logout` | none | End this session |
| GET | `/auth/me` | none | The user with role and permissions |

**Devices** (`devices.approve`)

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/devices?status=pending` | Newest first, 25 per page (`per_page` 10 to 100), with owner, approver and revoker |
| POST | `/devices/{device}/approve` | `fingerprint` (as shown; spaces, colons and case ignored), `staff_user_id` (active, no other approved device) |
| POST | `/devices/{device}/revoke` | `reason`; deletes the device's tokens |

**Access and users** (`users.manage`)

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/permissions` | The catalogue with `read_only` |
| GET | `/roles` | Each role: `slug`, `label`, `administrator`, `read_only`, `permissions`, `users_count` |
| POST | `/roles/{role}/permissions` | `permissions: [slug]` |
| DELETE | `/roles/{role}/permissions/{permission}` | Remove one (404 when not held) |
| GET | `/users` | By username; `q` (username, names, personal number in any digits), `role`, `is_active`, `per_page` |
| POST | `/users` | `username`, `first_name`, `last_name`, `personal_number`, `role`, `is_active` (optional), `password` + `password_confirmation`. 201 |
| GET | `/users/{user}` | One user with the effective permissions |
| PUT | `/users/{user}/role` | `role`: replaces the one role |
| POST | `/users/{user}/deactivate` | `reason` (not yourself) |
| POST | `/users/{user}/activate` | Reactivate |
| PUT | `/users/{user}/password` | `password` + `password_confirmation`: a forgotten password |

`{role}` and `{permission}` are slugs; `{device}` and `{user}` are UUIDs (anything else is 404).

**Errors** (D-011): 401 `{"message": "انتهت الجلسة أو لم يُسجَّل الدخول: سجّل الدخول من جديد."}`; 403
`{"message": "ليست لديك صلاحية لهذا الإجراء."}` (or the refusal's own reason); 404
`{"message": "العنصر المطلوب غير موجود."}`; 422 Laravel's `{message, errors}` in Arabic; 429
`{"message": "محاولات كثيرة: انتظر قليلًا ثم أعد المحاولة."}` with `Retry-After`.

### 7.6 Device-bound login protocol (for the desktop app team)

1. **Enrol once.** Generate an ECDSA P-256 key pair. Send the public key (SPKI PEM), a label, the MAC address
   (required) and OS info to `POST /devices/register`. Show the returned **fingerprint** to the employee; an
   administrator compares it and approves the device for one staff user. Poll `GET /devices/{id}/status` until
   `approved`.
2. **Log in.** Call `POST /auth/challenge {device_id}`. Build the payload:

```
payload   = "hr-staff-login-v1" \n challenge_id \n challenge \n device_id \n username
            (joined with "\n"; challenge exactly as returned)
signature = base64( ECDSA-P256-SHA256( payload as UTF-8 ) ), DER-encoded
```

   Send `device_id`, `challenge_id`, `signature`, `username`, `password` to `POST /auth/login`.

3. **Sign what you send.** The username is signed exactly as sent: trim it, do not change its case. The server
   lowercases it only for the lookup (D-008).
4. **DER, not raw.** Node's `crypto.sign` produces DER by default. WebCrypto's `subtle.sign` returns raw `r||s`,
   which must be converted to DER first.
5. **Challenges** are single use and live 60 seconds (`staff_auth.challenge_ttl_seconds`). Any failure returns the
   same generic 422; request a new challenge and retry.
6. **The prefix differs from the recruitment system's** (`recruitment-staff-login-v1`): a signature for one system is
   never valid on the other. A computer used for both registers one key per system, or the same key twice.
7. The private key never leaves the device; store it encrypted with the OS per-user protection (D-013 limitation).

`tools/device-client.mjs` is a working reference client:

```bash
node tools/device-client.mjs register http://127.0.0.1:8000          # prints id and fingerprint
node tools/device-client.mjs status   http://127.0.0.1:8000 <device-id>
node tools/device-client.mjs login    http://127.0.0.1:8000 <device-id> admin 'Password1!'
```

### 7.7 Login internals (`LoginStaff`)

1. Consume the challenge atomically: one `UPDATE … RETURNING` (unexpired, unused, bound to this device), compared
   with the application's clock (D-013).
2. Load the device (must be approved) and verify the signature over the payload built from stored values and the
   username as sent.
3. Only then look up the user by `Username::normalize()`; an unknown user still costs one password check.
4. Check the password, the active flag, and that the device belongs to the user.
5. Update `last_seen_at` / `last_login_at`; issue a token bound to the device that ends at the next 00:00 in
   Damascus (D-009); record `auth.login`.

Every failure writes `staff_login_failed` to the log with the real reason (never the username) and returns the same
422. `EnsureStaffIsActive` refuses a deactivated user's tokens on every request (401).

### 7.8 Seeding and the first administrator

| Seeder | Runs | Purpose |
| ------ | ---- | ------- |
| `RolesAndPermissionsSeeder` | every environment | The catalogue (upsert) and the roles' defaults when first created |
| `DevUsersSeeder` | `APP_ENV=local` only | `admin` (system administrator), `manager` (general manager), `entry` and `entry.two` (data entry); password `Password1!` |

Production: `php artisan migrate --force`, then `php artisan db:seed --force` (the catalogue only; re-run safe). A
new installation has no user:

```bash
php artisan staff:create-admin <username> --first-name=… --last-name=… --personal-number=…   # password asked twice
node tools/device-client.mjs register https://hr.example.local                                 # on the admin's PC (or the desktop app)
php artisan staff:approve-device <device-id> <username> --fingerprint="XXXX XXXX …"
```

Everybody else is added from the application.

## 8. Audit module (D-017)

`AuditTrail::record($event, $actor, $context)` writes a row of `audit_events` in the transaction of the action and a
log line.

**`audit_events`**: `id` uuid; `event` (dotted, CHECK); `category` (`security` for `access.*`, `device.*`, `auth.*`,
otherwise `operations`; CHECK); `actor_id` (null: the console or the system); `context` jsonb (GIN index);
`related_ids` text[] (GIN index; the context's `id`, `*_id`, `*_ids` at any depth; not shown); `ip` (`inet`);
`created_at`. Append-only: triggers refuse UPDATE, DELETE and TRUNCATE.

Events of Step 1:

| Event | Label | When |
| ----- | ----- | ---- |
| `auth.login` | تسجيل دخول | a successful login |
| `auth.logout` | تسجيل خروج | logout |
| `device.approved` | اعتماد جهاز | API or console (`via`) |
| `device.revoked` | إلغاء جهاز | with the reason and the tokens deleted |
| `access.user_created` | إضافة مستخدم | API, console or seeder (`via`) |
| `access.role_changed` | تغيير دور مستخدم | from, to |
| `access.role_permissions_added` | إضافة صلاحيات إلى دور | the permissions added |
| `access.role_permission_removed` | إزالة صلاحية من دور | |
| `access.user_deactivated` | إيقاف مستخدم | with the reason |
| `access.user_activated` | إعادة تفعيل مستخدم | |
| `access.password_reset` | تعيين كلمة مرور جديدة لمستخدم | |

| Method | Path | Permission | Purpose |
| ------ | ---- | ---------- | ------- |
| GET | `/audit-events` | `audit.view` or `audit.view_related` | Newest first, 50 per page (`per_page` 10 to 200); `event` (exact, or ending with `.` for a kind), `actor_id`, `related_id`, `category`, `from`, `to` (Damascus days). Each event: `id`, `event`, `event_label`, `category`, `actor` (summary), `context`, `ip`, `created_at` |
| GET | `/audit-events/events` | same | The events with `label` and `category`, for the filter |

`audit.view` reads everything; `audit.view_related` the operations only (the security events are hidden). Failed
logins are in the application log only.

## 9. Testing

`php artisan test` (PostgreSQL database `hr_test`, `RefreshDatabase`, the catalogue seeded for every test):
**63 tests**. Fixed device keys in `tests/Support/TestDeviceKeys.php` (D-020); helpers in
`tests/Support/StaffTestHelpers.php` (`staffUser($role)`, `admin()`, `pendingDevice()`, `approvedDevice()`,
`loginThrough()` (challenge and signed login through the API), `actingAsStaff()`).

| Test file | Covers |
| --------- | ------ |
| `Feature/Identity/DeviceLoginTest` | Signed login (token bound to the device, until 00:00 in Damascus, last seen and last login, audit), the token refused after midnight, the username signed as sent and found lowercase, single-use and expired challenges, the same message for every failure (other key, wrong password, unknown user, another user's device, inactive user), challenges for approved devices only, logout of one session, a deactivated user's token refused, every role logs in the same way |
| `Feature/Identity/DeviceManagementTest` | Registration idempotent by key, key and MAC checks, pending cap, status without the owner, approval after the fingerprint (any case and spacing, audit, once), one approved device per user (API and database), none for the inactive, revocation deletes tokens, list filter, permissions, console approval, mass-assignment guard |
| `Feature/Identity/AccessManagementTest` | Each role's defaults, the administrator holds everything, the general manager read-only, a roleless user holds nothing, catalogue and roles listed, permissions added and removed (404 when not held), the administrator's role fixed, the general manager's read-only, the seeder keeps the administrator's changes, a role changed and audited, the last user manager kept, only the administrator gives the administrator's role, deactivation (tokens, not oneself, once) and reactivation, a new password signs out and keeps the device, users listed and filtered (Arabic digits), data entry and the general manager do not manage access |
| `Feature/Identity/StaffUserRegistrationTest` | A user added (normalised username, Arabic digits, hashed password, audit), created inactive with a reason, Arabic messages and field names, unique username and personal number, database CHECKs (username, deactivation) |
| `Feature/Identity/CreateAdminCommandTest` | `staff:create-admin` with the API's rules, audited `via: console`; weak or mismatched password refused; confirmation when an administrator exists; refused before the roles are seeded |
| `Feature/Audit/AuditEventApiTest` | Row with actor, category, related ids, address; nothing after a rollback; append-only (update, delete, truncate); category follows the event; newest first with filters (kind, event, related id, actor, Damascus days, bad input); operations only for `audit.view_related`; permission needed; every recorded event has a label and every label is recorded |
| `Feature/RequestConventionsTest` | Arabic 401, 403, 404 and 429 (with `Retry-After`), Arabic validation messages, all without `\u` escapes; the database session in UTC |
| `Unit/ArabicTextTest`, `Unit/BusinessDayTest` | Digits, search key; the next Damascus midnight, a day's UTC bounds |

API documentation check: `php artisan scramble:analyze` must report no warnings. Code style: `./vendor/bin/pint`.

The whole login was also run over HTTP with `tools/device-client.mjs` against `php artisan serve`: registration,
console approval, signed login, a call with the token, the audit log.

## 10. Known limitations and pending work

- Everything of the workbook itself comes in Steps 2 to 7 (section 2).
- The roles and their defaults are assumed (OD-1, OD-2, OD-5); the client is assumed to be the desktop app (OD-3).
- Passwords are not checked against known leaks (OD-4).
- The device key is protected by the Windows user, not by hardware (D-013).
- Failed logins are in the application log only; the audit table's retention belongs to the deployment.
- `/docs/api` is reachable in `local` only; production serves `/api/…` alone (Step 7's nginx).
- Migrations may still change in place until the first production installation; from then on they are append-only
  (recruitment D-101).

## 11. Changelog

| Step | Summary |
| ---- | ------- |
| 1 | Foundation: Laravel 12 modular monolith on PostgreSQL 16, UTC connection, Damascus business days, Arabic unescaped JSON as a global middleware, Arabic 401/403/404/429 and validation messages (D-001 to D-005, D-011, D-012, D-022). Identity: staff users with one role (data entry, general manager, system administrator) and the whole system's permission catalogue (D-006, D-019); device-bound login with the payload prefix `hr-staff-login-v1`, tokens until 00:00 in Damascus (D-009, D-013, D-014); access and user management with the last-manager and no-escalation rules (D-015); `staff:create-admin`, `staff:approve-device` (D-018). Audit: append-only `audit_events` with related ids and a read API (D-017). Reference device client. 63 tests |
