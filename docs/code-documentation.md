# HR Backend: Technical Documentation (نظام إدارة القوى البشرية)

Status: **Step 2** (Step 1: foundation, staff identity and roles, device-bound login, access and user management,
audit log; Step 2: settings: the managed lists, the organisational structure, holidays, branch settings).
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

**Data entry employees** enter the information; the **supervisor** reviews their entries and manages the settings;
the **general manager** views everything; the **system administrator** runs the system (D-006, D-023). They use the
desktop app only (React + Electron, OD-3) and log in from approved devices (D-013).

## 2. Module status and plan

| Step | Module | Status | What it covers from the workbook |
| ---- | ------ | ------ | -------------------------------- |
| 1 | Foundation | Done | Project, PostgreSQL, module loader, UTC, Arabic JSON and errors, tests on PostgreSQL |
| 1 | Identity | Done | Staff users, one role each (data entry, general manager, administrator), device-bound login, access and user management, first administrator from the shell |
| 1 | Audit | Done | `audit_events`, append-only, written with each action; read API |
| 2 | Settings | Done | «الإعدادات»: 27 managed lists (yes/no and the update cycle are not lists), the six-level organisational structure, holidays, the branch settings with the four review windows |
| 3 | Employees | Planned | «قاعدة بيانات الموظفين» and «دليل الحقول»: the 159 fields with the sheet's checks, field definitions per update cycle, completeness and missing fields, review periods per cycle, change history, the supervisor's review (D-023, OD-8) |
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
    ├── Settings/
    │   ├── Actions/        ManageListItems, ManageOrgUnits, ManageHolidays, UpdateWorkSettings
    │   ├── Database/       Migrations (3), Seeders (SettingsSeeder, DevSettingsSeeder)
    │   ├── Http/           Controllers (LookupList, OrgUnit, Holiday, WorkSettings), Requests, Resources
    │   ├── Models/         LookupList, LookupItem, OrgUnit, Holiday, WorkSettings
    │   ├── Support/        ListCatalogue (the 27 lists, the workbook's items, system codes), OrgUnitTree
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

| Permission | Read-only | Data entry | Supervisor | General manager | System admin |
| ---------- | :-------: | :--------: | :--------: | :-------------: | :----------: |
| `employees.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `employees.create` | | ✓ | — | — | ✓ |
| `employees.update` | | ✓ | ✓ | — | ✓ |
| `entries.review` | | — | ✓ | — | ✓ |
| `records.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `records.create` | | ✓ | — | — | ✓ |
| `records.update` | | ✓ | ✓ | — | ✓ |
| `records.delete` | | ✓ | — | — | ✓ |
| `documents.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `documents.manage` | | ✓ | — | — | ✓ |
| `imports.manage` | | ✓ | — | — | ✓ |
| `settings.manage` | | — | ✓ | — | ✓ |
| `reports.view` | ✓ | — | ✓ | ✓ | ✓ |
| `audit.view` | ✓ | — | — | ✓ | ✓ |
| `audit.view_related` | ✓ | ✓ | ✓ | — | ✓ |
| `users.manage` | | — | — | — | ✓ |
| `devices.approve` | | — | — | — | ✓ |

Role slugs: `data_entry`, `supervisor`, `general_manager`, `system_admin` (`App\Modules\Identity\Enums\StaffRole`). The seeder
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
| `SettingsSeeder` | every environment | The 27 lists; the workbook's items when a list is first created; system codes ensured every run (section 9) |
| `DevUsersSeeder` | `APP_ENV=local` only | `admin` (system administrator), `supervisor`, `manager` (general manager), `entry` and `entry.two` (data entry); password `Password1!` |
| `DevSettingsSeeder` | `APP_ENV=local` only | The workbook's sample structure (21 units) and its 2026 holidays (D-029) |

Production: `php artisan migrate --force`, then `php artisan db:seed --force` (the catalogues only; re-run safe). A
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

## 9. Settings module (Step 2)

«الإعدادات» of the workbook: what every form and later step reads. Every signed-in user reads the settings;
`settings.manage` holders (the administrator and the supervisor, OD-2) change them. Every change is audited
(`settings.*`, category operations).

### 9.1 Managed lists (D-024, D-025)

**`lookup_lists`**: `id`, `key` unique (CHECK `^[a-z_]+$`), `label_ar`, `description_ar` (the fields that use it).
Created by `SettingsSeeder` from `ListCatalogue` only.

**`lookup_items`**: `id`, `lookup_list_id` (RESTRICT), `label` (CHECK not blank), `search_key` (unique in the
list), `code` (CHECK `^[a-z_]+$`, unique in the list when set), `sort_order` (CHECK ≥ 0), `created_by`,
`archived_at`, `archived_by` (CHECK: only when archived), timestamps. CHECK: a coded item is never archived.

The 27 lists, with the fields that use them:

| Key | List | Used by | System codes |
| --- | ---- | ------- | ------------ |
| `gender` | الجنس | الجنس | `male`, `female` |
| `blood_type` | فصيلة الدم | فصيلة الدم | |
| `marital_status` | الحالة الاجتماعية | الحالة الاجتماعية | `single` |
| `education_level` | التحصيل العلمي | الدراسة الأولى، الدراسة الثانية، التحصيل العلمي | |
| `current_study` | الدراسة الحالية | الدراسة الحالية | `none` (لا) |
| `study_year` | السنة الدراسية | السنة الدراسية الحالية | |
| `position_level` | المستوى التنظيمي (المنصب) | المستوى التنظيمي (المنصب) | |
| `job_title` | المسمى الوظيفي | المسمى الوظيفي (التخصص); empty in the workbook (OD-10) | |
| `employee_status` | حالة الموظف | حالة الموظف | `active`, `inactive` |
| `job_status` | الحالة الوظيفية | الحالة الوظيفية | `discharged` (مسرح) |
| `org_stage` | المرحلة التنظيمية | المرحلة التنظيمية | |
| `org_stage_level` | مستوى المرحلة التنظيمية | مستوى المرحلة التنظيمية | |
| `contract_type` | نوع العقد | نوع العقد | |
| `work_schedule` | نوع الدوام | نوع الدوام | |
| `health_status` | الحالة الصحية | الحالة الصحية | `healthy`, `injured`, `chronic_illness` |
| `ability_level` | مستوى القدرة (خطابة / تدريس) | القدرة على الخطابة، القدرة على التدريس | |
| `housing_type` | نوع السكن | نوع السكن الحالي | `rented` (ايجار) |
| `vehicle_ownership` | ملكية المركبة | ملكية السيارة، ملكية الدراجة | |
| `guardian_relation` | صلة القرابة بالوصي | صلة القرابة بالوصي | |
| `external_entity_type` | نوع الجهة الأخرى | نوع الجهة الأخرى | |
| `external_link_type` | نوع الارتباط | نوع الارتباط | |
| `external_schedule` | نظام الدوام بالجهة الأخرى | نظام الدوام بالجهة الأخرى | |
| `movement_type` | نوع الحركة | سجل الحركات | `reward`, `deduction` |
| `conduct_type` | نوع السجل السلوكي | سجل السلوك الوظيفي | |
| `conduct_status` | حالة المعالجة | سجل السلوك الوظيفي | |
| `debt_type` | نوع الدين | سجل الديون | |
| `work_nature` | طبيعة العمل | طبيعة العمل | |

Not lists: «نعم / لا» (booleans) and «دورة التحديث» (the system's cycles, Step 3).

Rules (`ManageListItems`): an item is added at the end unless `sort_order` is given; the same word typed another
way is a duplicate (422, naming the existing item; an archived one is to be restored); renaming checks the same;
a system item is renamed but not archived (422); reordering takes every active item's id once (archived items
follow); each list's changes are serialised (row lock on the list).

### 9.2 Organisational structure (D-026)

**`org_units`**: `id`, `parent_id` (RESTRICT), `level` (CHECK 1 to 6; level 1 has no parent), `name` (CHECK not
blank), `search_key`, `created_by`, `archived_at`, `archived_by`, timestamps. Unique index
`(COALESCE(parent_id, 0), search_key)`: siblings have distinct names. Trigger `org_units_tree`: a child is its
parent's level plus one; an active unit has an active parent; a unit with active children is not archived; a unit
is not moved.

`OrgUnitTree::all()` returns every unit with `path` (names from the top) and `path_ids` in one recursive query,
ordered by path (a parent before its children).

Rules (`ManageOrgUnits`): added under an active parent of level < 6, or at the top; renamed (same duplicate check);
archived once its children are; restored under an active parent; never moved (D-026).

### 9.3 Holidays (D-027) and branch settings (D-028)

**`holidays`**: `id`, `name`, `starts_on`, `ends_on` (CHECK ≥ start, < 366 days apart), `days` (generated:
`ends_on - starts_on + 1`), `notes`, `created_by`, `updated_by`, timestamps. Overlaps allowed.

**`work_settings`**: one row (CHECK `id = 1`), created by the migration with the workbook's values:

| Column | Workbook | Default | Rule |
| ------ | -------- | ------- | ---- |
| `branch_name` | اسم الفرع | القوى البشرية | not blank |
| `daily_hours` | ساعات العمل | 8 | > 0, ≤ 24, two decimals |
| `starts_at`, `ends_at` | بداية الدوام، نهاية الدوام | 08:00, 16:00 | end after start |
| `late_after_minutes` | التأخير (دقيقة) | 10 | 0 to 240 |
| `work_days_per_week` | أيام العمل | 5 | 1 to 7 |
| `currency` | العملة | ل.س | not blank |
| `annual_leave_days` | رصيد الإجازات | 21 | 0 to 365 |
| `review_window_monthly` … `_annual` | مهلة المراجعة | 35, 100, 190, 370 | > 0, growing, ≤ 1000 |

### 9.4 API reference

Base path `/api/v1/staff/settings`. Reading: any signed-in user. Changing: `settings.manage`.

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/lists` | Every list with its active items in order; `include_archived=1` adds the archived ones |
| GET | `/lists/{key}` | One list (same parameter) |
| POST | `/lists/{key}/items` | `label`, `sort_order` (optional). 201 |
| PUT | `/lists/{key}/items/{id}` | `label` and/or `sort_order` |
| POST | `/lists/{key}/items/{id}/archive` | Not offered any more (system items: 422) |
| POST | `/lists/{key}/items/{id}/restore` | Offered again |
| PUT | `/lists/{key}/order` | `item_ids`: every active item, in order |
| GET | `/org-units` | Every unit: `id`, `parent_id`, `level`, `name`, `path`, `path_ids`, `path_label`, `archived`; `include_archived=1` |
| POST | `/org-units` | `name`, `parent_id` (absent: a top-level unit). 201 |
| PUT | `/org-units/{id}` | `name` |
| POST | `/org-units/{id}/archive` | Once its children are archived |
| POST | `/org-units/{id}/restore` | Under an active parent |
| GET | `/holidays` | By start date; `year` for those touching a year |
| POST | `/holidays` | `name`, `starts_on`, `ends_on` (Y-m-d), `notes`. 201 |
| PUT | `/holidays/{id}` | The fields sent, checked with the stored ones |
| DELETE | `/holidays/{id}` | 204 |
| GET | `/work` | The branch settings, `review_windows` grouped |
| PUT | `/work` | The fields sent (`review_window_monthly` …); the rules between fields checked with the stored values |

Item, unit and holiday ids are integers; an item is reached through its own list only (404 otherwise).

### 9.5 Audit events

| Event | Label |
| ----- | ----- |
| `settings.list_item_added`, `_updated`, `_archived`, `_restored` | إضافة / تعديل / أرشفة / استعادة عنصر في قائمة |
| `settings.list_reordered` | إعادة ترتيب قائمة |
| `settings.org_unit_added`, `_renamed`, `_archived`, `_restored` | إضافة / تعديل اسم / أرشفة / استعادة جهة عمل |
| `settings.holiday_added`, `_updated`, `_deleted` | إضافة / تعديل / حذف عطلة رسمية |
| `settings.work_updated` | تعديل إعدادات الفرع (each changed field with `from` and `to`) |

## 10. Testing

`php artisan test` (PostgreSQL database `hr_test`, `RefreshDatabase`, the catalogues seeded through
`DatabaseSeeder` for every test): **84 tests**. Fixed device keys in `tests/Support/TestDeviceKeys.php` (D-020); helpers in
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
| `Feature/Settings/LookupListTest` | The 27 lists seeded in order with their system codes; everybody reads, the supervisor and the administrator change; added at the end and audited; duplicates by search key (and per list); rename and move; archive, restore (archived duplicate restored, not added); system items renamed, never archived (API and CHECK); reorder all active items; the seeder keeps the managers' changes and restores a missing code |
| `Feature/Settings/OrgUnitTest` | Six levels with paths and the seventh refused; sibling names unique, cousins free; archive after the children, restore under an active parent; rename; the database trigger (level, root, archive with children, move); data entry reads only |
| `Feature/Settings/HolidayAndWorkSettingsTest` | Days with both ends, by year; correct and delete (204); ends before start and longer than a year refused; the workbook's branch settings from the start; changes with their rules, each change audited, the same value recording nothing; only settings managers change |
| `Unit/ArabicTextTest`, `Unit/BusinessDayTest` | Digits, search key; the next Damascus midnight, a day's UTC bounds |

API documentation check: `php artisan scramble:analyze` must report no warnings. Code style: `./vendor/bin/pint`.

The whole login was also run over HTTP with `tools/device-client.mjs` against `php artisan serve`: registration,
console approval, signed login, a call with the token, the audit log.

## 11. Known limitations and pending work

- The employees, their records, documents, views and import come in Steps 3 to 7 (section 2).
- The supervisor's review is defined as a role and permissions (D-023: reviews anything; reads and updates, adds and
  deletes nothing); the review itself is built in Steps 3 and 4. Open: OD-11.
- Performance plan: D-031 (one PostgreSQL server, indexes, history tables, little cache); measured in Step 3.
- Archiving an organisational unit does not check its employees yet (Step 3).
- Passwords are not checked against known leaks (OD-4).
- The device key is protected by the Windows user, not by hardware (D-013).
- Failed logins are in the application log only; the audit table's retention belongs to the deployment.
- `/docs/api` is reachable in `local` only; production serves `/api/…` alone (Step 7's nginx).
- Migrations may still change in place until the first production installation; from then on they are append-only
  (recruitment D-101).

## 12. Changelog

| Step | Summary |
| ---- | ------- |
| 1 | Foundation: Laravel 12 modular monolith on PostgreSQL 16, UTC connection, Damascus business days, Arabic unescaped JSON as a global middleware, Arabic 401/403/404/429 and validation messages (D-001 to D-005, D-011, D-012, D-022). Identity: staff users with one role (data entry, general manager, system administrator) and the whole system's permission catalogue (D-006, D-019); device-bound login with the payload prefix `hr-staff-login-v1`, tokens until 00:00 in Damascus (D-009, D-013, D-014); access and user management with the last-manager and no-escalation rules (D-015); `staff:create-admin`, `staff:approve-device` (D-018). Audit: append-only `audit_events` with related ids and a read API (D-017). Reference device client. 63 tests |
| 2 | Owner's answers (OD-1, 2, 3, 5, 7): the `supervisor` role with `entries.review` and `settings.manage` (D-023), the desktop app only, sensitive fields stored as given (D-030). Settings: 27 managed lists with search-key duplicates, system codes and archive / restore / reorder (D-024, D-025); the organisational structure as a six-level tree with paths and database guards (D-026); holidays as date ranges (D-027); the branch settings as one row with the four review windows (D-028); the workbook's sample structure and holidays in local only (D-029). 84 tests |
| 2.1 | Owner's answers of 23 Nov: the supervisor reviews anything, the file shows the old value with the pending change beside it, the supervisor reads and updates without adding or deleting (D-023): `employees.manage` split into `employees.create` / `.update`, `records.manage` into `records.create` / `.update` / `.delete`, with a migration that carries a seeded database over. Job title is a list (OD-10). Performance and history plan (D-031). 84 tests |
