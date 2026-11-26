# HR Backend: Technical Documentation (نظام إدارة القوى البشرية)

Status: **Step 5** (Step 1: foundation, staff identity and roles, device-bound login, access and user management,
audit log; Step 2: settings; Step 3: the employee's file with review, history, identity lock and cycles; Step 4: the
four records with their review and the status report's year; Step 5: the employees' documents).
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
| 2 | Settings | Done | «الإعدادات»: 28 managed lists (yes/no and the update cycle are not lists), the six-level organisational structure, holidays, the branch settings with the four review windows |
| 3 | Employees | Done | «قاعدة بيانات الموظفين» and «دليل الحقول»: the 159 fields with the sheet's checks, field definitions per update cycle, completeness and missing fields, review periods per cycle, change history, the supervisor's review (D-023, OD-8) |
| 4 | Records | Done | «سجل الحركات», «سجل الدوام الشهري», «سجل السلوك الوظيفي», «سجل الديون» with the same review, batches for a month's attendance, the status report's year («مؤشرات السنة»), the file's debt columns |
| 5 | Documents | Done | The 13 attachments (photo, ID, family statement, certificate, …) as uploaded files instead of links, reviewed, with their history; the conduct record's document; the five counted ones in the completeness (D-049) |
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
    │   ├── Database/       Migrations (5), Seeders (SettingsSeeder, DevSettingsSeeder)
    │   ├── Http/           Controllers (LookupList, OrgUnit, Holiday, WorkSettings), Requests, Resources
    │   ├── Models/         ListItem (one model for the 28 list tables), OrgUnit, Holiday, WorkSettings
    │   ├── Support/        ListCatalogue (each list's key, table, label, the workbook's items, system codes),
    │   │                   ManagedList (a list and the way to its items), ListUsage, OrgUnitTree
    │   └── routes.php
    ├── Employees/
    │   ├── Actions/        SubmitEmployeeChanges (create, update, review), ApplyChangeSet, DecideChangeSet
    │   │                   (approve, reject, withdraw), UpdateFieldDefinition
    │   ├── Console/        GenerateDemoEmployeesCommand (employees:generate-demo, development only)
    │   ├── Database/       Migrations (2), Seeders (FieldDefinitionsSeeder)
    │   ├── Enums/          Cycle, ChangeSetKind, ChangeSetStatus, FileStatus
    │   ├── Http/           Controllers (Employee, ChangeSet, FieldDefinition), Requests, Resources
    │   ├── Models/         Employee, EmployeeChangeSet, EmployeeChangeItem, EmployeeReview, FieldDefinition
    │   ├── Support/        FieldCatalogue (the 125 fields), FieldDefinitions, FieldValues (normal forms, rules),
    │   │                   ReviewRights, EmployeeDerived, EmployeeComputed, EmployeeWarnings, ReviewStatus,
    │   │                   EmployeeQuery, ValueLabels
    │   └── routes.php
    ├── Records/
    │   ├── Actions/        SubmitRecordChanges (one or a batch), ApplyRecordChangeSet, DecideRecordChangeSet,
    │   │                   ApplyMovementToFile
    │   ├── Console/        ApplyDueMovementsCommand (records:apply-due-movements), GenerateDemoRecordsCommand
    │   │                   (records:generate-demo, development only)
    │   ├── Database/       Migrations (3)
    │   ├── Enums/          RecordType, RecordAction, DebtStatus
    │   ├── Http/           Controllers (Record, RecordChange, EmployeeRecords), Requests, Resources
    │   ├── Models/         EmployeeRecord (base), EmployeeMovement, EmployeeAttendance, EmployeeConductRecord,
    │   │                   EmployeeDebt, EmployeeDebtPayment, RecordChangeSet, RecordChangeItem
    │   ├── Support/        RecordCatalogue (each record's columns), RecordValues, RecordComputed, RecordLabels,
    │   │                   RecordPage, RecordRegister, RecordSummary, DebtTotals, PlannedDays, PlannedMonth,
    │   │                   LeaveTypes, MovementEffects, RecordChange
    │   └── routes.php
    ├── Documents/
    │   ├── Actions/        UploadDocument, DecideDocument (approve, reject, withdraw, remove)
    │   ├── Database/       Migrations (1)
    │   ├── Http/           DocumentController, Requests, Resources (Document, DocumentKind, DocumentSlot)
    │   ├── Models/         EmployeeDocument
    │   ├── Support/        DocumentCatalogue (the 13 kinds and the conduct document), DocumentStorage,
    │   │                   DocumentSlots, DocumentSlot, DocumentKind, DocumentQueries
    │   └── routes.php
    ├── Notifications/
    │   ├── Console/        RemindExpiringContractsCommand (employees:remind-expiring-contracts)
    │   ├── Database/       Migrations (1)
    │   ├── Http/           NotificationController, NotificationIndexRequest, NotificationResource
    │   ├── Notifications/  StaffNotice, ChangeRejected, ContractEndingSoon
    │   ├── Support/        Badges
    │   └── routes.php
    └── Audit/
        ├── Database/Migrations (2)
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
| `identity.review` | | — | — | — | ✓ |
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
| POST | `/new` | Add a staff user (the hiring system's path, D-047): `username`, `first_name`, `last_name`, `personal_number`, `role`, `is_active` (optional), `password` + `password_confirmation`. 201 |
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
| `SettingsSeeder` | every environment | The 28 lists' items: the workbook's while a list's table is empty; system codes ensured every run (section 9) |
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

**One table per list**, 27 tables of the same shape, so every field of the employee's file is a plain foreign key
to its own list:

| Column | Notes |
| ------ | ----- |
| id | bigint |
| label | CHECK not blank |
| search_key | `ArabicText::searchKey(label)`, unique: the same word typed another way is one item |
| code | set for a system item (D-025); CHECK `^[a-z_]+$`; unique |
| sort_order | CHECK ≥ 0; the order the forms show |
| created_by | staff user (RESTRICT); null for seeded items |
| archived_at, archived_by | CHECK: `archived_by` only when archived; CHECK: a coded item is never archived |

The code is shared: `ListCatalogue::LISTS` maps each key to `[table, label, used by, items]`; `ListItem` is one
Eloquent model whose table is set per list (`ListItem::forList($key)`, `ListItem::queryFor($key)`); `ManagedList`
is a list as an object (`find`, `findOrFail`, `all`, `query()`, `withItems()`). There is no table of lists: the
lists are the catalogue's. The migration names the 27 tables itself (a migration keeps doing what it did).

The 28 lists, with the fields that use them:

| Key | Table | List | Used by | System codes |
| --- | ----- | ---- | ------- | ------------ |
| `gender` | `genders` | الجنس | الجنس | `male`, `female` |
| `blood_type` | `blood_types` | فصيلة الدم | فصيلة الدم | |
| `marital_status` | `marital_statuses` | الحالة الاجتماعية | الحالة الاجتماعية | `single` |
| `education_level` | `education_levels` | التحصيل العلمي | الدراسة الأولى، الدراسة الثانية، التحصيل العلمي | |
| `current_study` | `current_studies` | الدراسة الحالية | الدراسة الحالية | `none` (لا) |
| `study_year` | `study_years` | السنة الدراسية | السنة الدراسية الحالية | |
| `position_level` | `position_levels` | المستوى التنظيمي (المنصب) | المستوى التنظيمي (المنصب) | |
| `job_title` | `job_titles` | المسمى الوظيفي | المسمى الوظيفي (التخصص); empty in the workbook, filled by the settings managers (OD-10) | |
| `employee_status` | `employee_statuses` | حالة الموظف | حالة الموظف | `active`, `inactive` |
| `job_status` | `job_statuses` | الحالة الوظيفية | الحالة الوظيفية | `discharged` (مسرح) |
| `org_stage` | `org_stages` | المرحلة التنظيمية | المرحلة التنظيمية | |
| `org_stage_level` | `org_stage_levels` | مستوى المرحلة التنظيمية | مستوى المرحلة التنظيمية | |
| `contract_type` | `contract_types` | نوع العقد | نوع العقد | |
| `work_schedule` | `work_schedules` | نوع الدوام | نوع الدوام | |
| `health_status` | `health_statuses` | الحالة الصحية | الحالة الصحية | `healthy`, `injured`, `chronic_illness` |
| `ability_level` | `ability_levels` | مستوى القدرة (خطابة / تدريس) | القدرة على الخطابة، القدرة على التدريس | |
| `housing_type` | `housing_types` | نوع السكن | نوع السكن الحالي | `rented` (ايجار) |
| `vehicle_ownership` | `vehicle_ownerships` | ملكية المركبة | ملكية السيارة، ملكية الدراجة | |
| `guardian_relation` | `guardian_relations` | صلة القرابة بالوصي | صلة القرابة بالوصي | |
| `external_entity_type` | `external_entity_types` | نوع الجهة الأخرى | نوع الجهة الأخرى | |
| `external_link_type` | `external_link_types` | نوع الارتباط | نوع الارتباط | |
| `external_schedule` | `external_schedules` | نظام الدوام بالجهة الأخرى | نظام الدوام بالجهة الأخرى | |
| `movement_type` | `movement_types` | نوع الحركة | سجل الحركات | all 15: `leave_internal`, `leave_external`, `leave_sick`, `leave_emergency`, `reward`, `deduction`, `transfer`, `status_change`, `promotion`, `course`, `assignment`, `appointment`, `confirmation`, `resignation`, `discharge` |
| `conduct_type` | `conduct_types` | نوع السجل السلوكي | سجل السلوك الوظيفي | `complaint` (شكوى), `warning` (إنذار), `alert` (تنبيه), `punishment` (عقوبة), `violation` (مخالفة), `thanks` (شكر وتقدير) |
| `conduct_status` | `conduct_statuses` | حالة المعالجة | سجل السلوك الوظيفي | `under_investigation` (قيد التحقيق) |
| `debt_type` | `debt_types` | نوع الدين | سجل الديون | |
| `payment_method` | `payment_methods` | طريقة السداد | دفعات الديون (D-045) | |
| `work_nature` | `work_natures` | طبيعة العمل | طبيعة العمل | |

Not lists: «نعم / لا» (booleans) and «دورة التحديث» (the system's cycles, Step 3).

Rules (`ManageListItems`): an item is added at the end unless `sort_order` is given; the same word typed another
way is a duplicate (422, naming the existing item; an archived one is to be restored); renaming checks the same;
a system item is renamed but not archived (422); reordering takes every active item's id once (archived items
follow); each list's changes are serialised (an advisory lock on the list's key).

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
| `daily_hours` | ساعات العمل | 8 | computed by the database: the day's end less its start (Step 4.1); not sent |
| `starts_at`, `ends_at` | بداية الدوام، نهاية الدوام | 08:00, 16:00 | end after start |
| `late_after_minutes` | التأخير (دقيقة) | 10 | 0 to 240 |
| `work_days_per_week` | أيام العمل | 5 | 1 to 7 |
| `weekly_days_off` | (new) أيام العطلة الأسبوعية | `[5, 6]` (Friday, Saturday) | ISO weekdays, distinct, 7 − `work_days_per_week` of them; changed together with it (D-043) |
| `currency` | العملة | ل.س | not blank |
| `annual_leave_days` | رصيد الإجازات | 21 | 0 to 365 |
| `high_absence_rate` | (new) نسبة الغياب المرتفعة | 20 (percent) | > 0, ≤ 100; a month of attendance at or above it is flagged (D-042) |
| `review_window_monthly` … `_annual` | مهلة المراجعة | 35, 100, 190, 370 | > 0, growing, ≤ 1000 |

### 9.4 API reference

Base path `/api/v1/staff/settings`. Reading: any signed-in user. Changing: `settings.manage`.

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/lists` | Every list (`key`, `name_ar`, `used_by`) with its active items in order (`id`, `name_ar`, `code`, `sort_order`, `archived`, `archived_at`, `usage_count`); `include_archived=1` adds the archived ones |
| GET | `/lists/{key}` | One list (same parameter) |
| POST | `/lists/{key}/items` | `name_ar`, `sort_order` (optional). 201 |
| PUT | `/lists/{key}/items/{id}` | `name_ar` and/or `sort_order` |
| POST | `/lists/{key}/items/{id}/archive` | Not offered any more (system items: 422) |
| POST | `/lists/{key}/items/{id}/restore` | Offered again |
| PUT | `/lists/{key}/order` | `item_ids`: every active item, in order |
| GET | `/org-units` | Every unit: `id`, `parent_id`, `level`, `name_ar`, `path`, `path_ids`, `path_label`, `archived`, `active_employees` (with the units under it), `direct_active_employees`; `include_archived=1` |
| POST | `/org-units` | `name_ar`, `parent_id` (absent: a top-level unit). 201 |
| PUT | `/org-units/{id}` | `name_ar` |
| POST | `/org-units/{id}/archive` | Once its children are archived |
| POST | `/org-units/{id}/restore` | Under an active parent |
| GET | `/holidays` | By start date; `year` for those touching a year |
| POST | `/holidays` | `name`, `starts_on`, `ends_on` (Y-m-d), `notes`. 201 |
| PUT | `/holidays/{id}` | The fields sent, checked with the stored ones |
| DELETE | `/holidays/{id}` | 204 |
| GET | `/work` | The branch settings, `review_windows` grouped |
| PUT | `/work` | The fields sent (`review_window_monthly` …); the rules between fields checked with the stored values |

Item, unit and holiday ids are integers. An item's id is its own list's (each list has its own table and ids);
an unknown list key or an id not in that list is 404.

### 9.5 Audit events

| Event | Label |
| ----- | ----- |
| `settings.list_item_added`, `_updated`, `_archived`, `_restored` | إضافة / تعديل / أرشفة / استعادة عنصر في قائمة |
| `settings.list_reordered` | إعادة ترتيب قائمة |
| `settings.org_unit_added`, `_renamed`, `_archived`, `_restored` | إضافة / تعديل اسم / أرشفة / استعادة جهة عمل |
| `settings.holiday_added`, `_updated`, `_deleted` | إضافة / تعديل / حذف عطلة رسمية |
| `settings.work_updated` | تعديل إعدادات الفرع (each changed field with `from` and `to`) |

## 10. Employees module (Step 3)

The workbook's `قاعدة بيانات الموظفين` and `دليل الحقول`. Decisions D-032 to D-037.

### 10.1 Tables

**`employees`**: `id` uuid; `file_status` (`pending` added and waiting, `active`, `rejected`, `withdrawn`);
derived and stored (D-035): `search_name` (trigram index), `completeness` (0 to 100), `missing_fields` (cycle =>
keys); the **125 input fields** as typed columns (list fields are foreign keys to their list's table, units to
`org_units`); `created_by`, `activated_at`, `identity_locked_at`, `identity_locked_by`, `identity_hash` (D-034, D-038),
timestamps. 43 CHECKs (ranges, formats, names, dates, contract order,
an unapproved file is empty); partial unique indexes on `national_number` and `personal_number`; indexes on status,
job status, unit, contract end, completeness.

**`field_definitions`**: `key` (the column), `label_ar`, `section_ar`, `workbook_column`, `type`, `list_key`,
`cycle` (CHECK), `counts_for_completeness`, `identity`, `sort_order`, `updated_by`. Seeded from `FieldCatalogue`;
`counts_for_completeness` and `cycle` are the settings managers' after the first seeding.

**`employee_change_sets`**: `id` uuid, `employee_id`, `kind` (`create`, `update`, `review`), `cycle` and `period`
(reviews only), `status` (`pending`, `approved`, `rejected`, `withdrawn`), `needs_identity_review`, `note`,
`submitted_by`, `submitted_at`, `self_approved`, `reviewed_by`, `reviewed_at`, `review_note`. CHECKs: the decision's
columns follow the status; a refusal has a reason; `self_approved` ⇔ reviewer is the author. Partial unique index:
one pending or approved confirmation per file, cycle and period. Triggers: never deleted; changed once, from
pending, and only the decision's columns.

Trigger `employees_identity_locked` on `employees`: once `identity_locked_at` is set, a non-empty identity value is
not changed, the lock is not undone, and the fingerprint changes only when an empty identity field is filled.

**`employee_change_items`**: `change_set_id`, `field` (foreign key to `field_definitions`), `old_value`,
`new_value` (jsonb scalars in the normal form of `FieldValues`). Unique per set and field. Append-only.

**`employee_reviews`**: `employee_id`, `cycle`, `period`, `reviewed_on`, `change_set_id` (unique),
`confirmed_by`, `approved_by`. Unique per file, cycle and period. Append-only.

### 10.2 The fields

125 input fields in 18 sections; 67 count for completeness, and the unit counts twice, as the workbook's levels 1
and 2 (`org_unit_level_2` among the missing fields when the unit is not at level 2 or below), and the five counted
documents (section 13.2): 73 counted; 6 are the identity fields (الرقم الذاتي، الرقم الوطني،
الاسم، اسم الأب، اسم الأم، العائلة), locked once the administrator approves them (D-034). By cycle: once 32,
monthly 26, quarterly 26, semi-annual 29, annual 12. The full list with types, limits, lists and flags is
`GET /field-definitions` (and `FieldCatalogue::FIELDS`).

Values (`FieldValues`): text trimmed, spaces collapsed; Arabic digits as ASCII in numbers, dates, phones and
national numbers; phones without spaces, dashes or brackets; e-mails lowercase; decimals with their scale ("176.0",
"350000.00"); booleans true / false / null; list and unit fields by id. Errors are keyed `values.<field>` and name
the field in Arabic. A list item or unit must be active (an archived one already in the file stays).

### 10.3 Rules

| Who | Adds a file | Changes a file | Confirms a cycle | Approves, refuses | Locks an identity |
| --- | ----------- | -------------- | ---------------- | ----------------- | ----------------- |
| Data entry | waits | waits | waits | no | no |
| Supervisor | no | at once (identity: waits for the administrator) | at once | yes, not identity, not their own | no |
| General manager | no | no | no | no | no |
| Administrator | at once (locks) | at once | at once | yes, identity too (locks), not their own | yes |

- A new file needs «الرقم الذاتي», «الرقم الوطني» and the three names (D-047; the national number: owner, 25 Nov).
- An approved movement updates the file as a change «تطبيق حركة» (D-048).
- Only real changes are kept; nothing changed → 422.
- Identity lock (D-034): any identity field → `needs_identity_review`, approved by `identity.review` only, which locks
  the identity. Once locked, a non-empty identity value is refused for everyone (422, and the trigger); an empty one
  may be filled once, the same way. A set waiting when the lock happened is refused at approval.
- Approval refuses a stale set (a field changed since) → 422 naming the fields.
- A national or personal number in another file or another pending set → 422.
- A confirmation takes only its cycle's fields, once per period.
- Archiving a unit with active files in it → 422 (Settings).

### 10.4 API reference

Base path `/api/v1/staff`.

| Method | Path | Permission | Purpose |
| ------ | ---- | ---------- | ------- |
| GET | `/field-definitions` | signed in | The guide: `key`, `label`, `section`, `workbook_column`, `type`, `list`, `min`, `max`, `cycle`, `counts_for_completeness`, `identity` |
| PUT | `/field-definitions/{key}` | `settings.manage` | `counts_for_completeness` and/or `cycle`; every active file's completeness follows |
| GET | `/employees` | `employees.view` | 25 per page. `q` (a name in any spelling, or the start of a personal or national number), `org_unit_id` (+ `include_sub_units`, default true), `employee_status_id`, `job_status_id`, `work_schedule_id`, `position_level_id`, `org_stage_id`, `gender_id`, `file_status` (default active), `contract_ends_within_days`, `overdue_cycle`, `completeness_below`, `identity_locked`, `has_debts`, `has_pending`, `sort` (`name`, `personal_number`, `completeness`, `contract_ends_on`, `created_at`), `direction`, `per_page` 5 to 100. Row: numbers, name, unit path, statuses, contract end and days left, completeness, missing count, overdue cycles, pending count |
| POST | `/employees` | `employees.create` | `values` (field => value; «الرقم الذاتي», «الرقم الوطني» and the three names required), `note`. 201 with the change set (pending, or applied for a reviewer). The document lists every field with its type (`StoreEmployeeRequest`, generated from `FieldCatalogue::valuesShape()`; a test keeps it equal) |
| GET | `/employees/{id}` | `employees.view` | `application_date` («تاريخ التقديم»: the day the file was approved), `identity` (locked, when, by whom), `fields` (each: label, section, cycle, type, identity, `locked`, `value`, `display`, `item` (a list or unit value as `{id, name_ar, archived}`), `pending` [value, display, author, time, identity]), `computed` (full name, age, service years with `service_started_on` and `service_ended_on`, contract days left, unit paths, `has_debts`, `total_remaining`, `open_debts`, `overdue_debts`), `completeness` with `completeness_filled` / `completeness_counted`, `missing_fields` by cycle, `warnings`, `reviews` (per cycle: `cycle_label`, period, last confirmation, `days_since`, `window`, due date, overdue, current period done), `pending_change_sets` |
| PATCH | `/employees/{id}` | `employees.update` | `values`, `note`. 201 with the change set |
| POST | `/employees/{id}/reviews` | `employees.update` | `cycle` (monthly, quarterly, semi_annual, annual), `values` (that cycle's fields, optional), `note`. 201 |
| POST | `/employees/{id}/identity/lock` | `identity.review` | Confirm and lock the identity (needs the three names, no identity change waiting); never undone |
| GET | `/employees/{id}/reviews` | `employees.view` | The four cycles and every confirmation |
| GET | `/employees/{id}/history` | `employees.view` | Approved changes newest first, a resource collection (`meta`, `links`); `field` for one field. Old and new value with display, kind, cycle, period, author, approver, time, self-approved |
| GET | `/employee-changes` | `employees.view` | The queue: `status` (default pending, oldest first), `kind`, `employee_id`, `mine`, `needs_identity_review`. Each set with its items (approved and proposed value, display, identity) and `can_decide` |
| GET | `/employee-changes/{id}` | `employees.view` | One set |
| POST | `/employee-changes/{id}/approve` | `entries.review` | `note` (optional). Identity sets need `identity.review`; not one's own |
| POST | `/employee-changes/{id}/reject` | `entries.review` | `reason` required (3 to 2,000) |
| POST | `/employee-changes/{id}/withdraw` | the author | Take one's own pending set back |

### 10.5 Audit events

| Event | Label | Context |
| ----- | ----- | ------- |
| `employees.change_submitted` | إرسال إضافة أو تعديل لملف موظف | kind, cycle, period, fields, needs identity review, applied at once |
| `employees.change_approved` | اعتماد إضافة أو تعديل لملف موظف | self-approved or not |
| `employees.change_rejected` | ردّ إضافة أو تعديل لملف موظف | the reason |
| `employees.change_withdrawn` | سحب إضافة أو تعديل لملف موظف | |
| `employees.identity_locked` | تثبيت هوية موظف (لا تُعدّل بعدها) | the change set, or the fields locked |
| `employees.identity_check_failed` | تحقق البصمة: هوية مثبتة تغيّرت خارج النظام | the files, the count checked (no actor: the nightly check) |
| `employees.field_definition_updated` | تعديل تعريف حقل في دليل الحقول | from, to, files recomputed |

The values themselves are in the change items, not in the audit log.

### 10.6 Scheduled work and the fingerprint

`routes/console.php` (the deployment's cron runs `php artisan schedule:run` every minute):

| Command | When (Damascus) | Does |
| ------- | --------------- | ---- |
| `employees:verify-identity` | 02:30 | Recomputes every locked identity's fingerprint (D-038); a mismatch is a critical log line and an audit event, and the command fails |
| `model:prune` | 03:00 | Login challenges expired for a day |
| `sanctum:prune-expired --hours=48` | 03:10 | Tokens expired for two days |
| `records:apply-due-movements` | 00:10 | Approved movements whose day has come update their files (D-048) |
| `employees:remind-expiring-contracts` | 06:00 | Contracts ending within 30 days, told once (D-046) |

The fingerprint is HMAC-SHA256 of the six identity values with `HR_IDENTITY_HASH_KEY` (`.env`; APP_KEY if unset).
Generate it once at installation and keep it with the backups: with another key every fingerprint reads as broken.

The application log (`storage/logs/laravel-YYYY-MM-DD.log`, one a day) is never deleted (`LOG_DAILY_DAYS=0`, D-039):
include `storage/logs` in the backups. A failed login is one `staff_login_failed` line with `reason`, `device_id`,
`username` (as typed) and `ip`; never the password.

### 10.7 Measuring

`php artisan employees:generate-demo 4000 --years=3` (development only) fills 4,000 files with three years of
monthly and quarterly confirmations, a change every month and a queue of 200 pending sets, in bulk SQL (about 30
seconds). The timings are in D-031.

## 11. Records module (Step 4)

`app/Modules/Records`: the four records of the workbook (D-040), their changes and review (D-041), their rules and
computed columns (D-042), the planned days (D-043) and the status report's year (D-044).

### 11.1 Tables

Each record: `id` (uuid, chosen when the change is sent), `employee_id`, its columns, `notes`, `created_by` (the
author of the addition), `approved_by`, `deleted_at` and `deleted_by` (both or neither), timestamps. Trigger
`records_guard`: no DELETE, no change to a removed record, `id`, `employee_id`, `created_by`, `approved_by` and
`created_at` fixed; no TRUNCATE.

| Table | Workbook | Columns | Checks |
| ----- | -------- | ------- | ------ |
| `employee_movements` | سجل الحركات | `occurred_on`, `movement_type_id`, `description`, `identified_by` (المعرف بها), `entity`, `course_mark`, `amount`, `starts_on`, `ends_on`, `days` (generated: the period's calendar days, both ends included), `status` (الحالة, text); what the file becomes (D-048): `new_org_unit_id`, `new_job_status_id`, `new_position_level_id`, `new_job_title_id`; `applied_to_file_at`, `file_change_set_id` | both ends or neither, in order, under 400 days; amount ≥ 0; marks 0–1000; once applied to the file, it stays applied (trigger) |
| `employee_attendance` | سجل الدوام الشهري | `year`, `month`, `planned_days`, `attended_days` (one decimal), `absent_days` (generated), `exceptional_skills` | year 2000–2100, month 1–12; planned ≤ the month's days; attended ≤ planned; one per employee and month among kept rows (partial unique index); the month fixed (trigger) |
| `employee_conduct_records` | سجل السلوك الوظيفي | `occurred_on`, `conduct_type_id`, `subject`, `reported_by`, `decision`, `decision_number`, `decision_on`, `conduct_status_id`, `document_link` | |
| `employee_debts` | سجل الديون | `creditor`, `debt_type_id`, `reason`, `incurred_on`, `amount`, `paid_amount` (paid when entered), `payments_total` (the trigger's), `monthly_installment`, `due_on`, `remaining_amount` (generated: amount − paid − payments) | amount > 0; paid ≥ 0; paid + payments ≤ amount; installment ≥ 0; `payments_total` written by the payments' trigger only |
| `employee_debt_payments` | دفعات الديون (D-045) | `debt_id`, `paid_on`, `amount`, `payment_method_id` | amount > 0; of a kept debt of the same employee (trigger); never edited, only removed (trigger) |

**`record_change_sets`**: `id`, `record_type` (movements, attendance, conduct, debts, payments), `status`, `items_count`,
`note` (the reason, for a removal), `submitted_by`, `submitted_at`, `self_approved`, `reviewed_by`, `reviewed_at`,
`review_note`. The same CHECKs and triggers as the file's change sets (decided once, reason for a refusal, never
deleted).

**`record_change_items`**: `change_set_id`, `position` (1…), `action` (create: `new_values` only; update: both, the
changed fields; delete: `old_values`, the whole record), `record_id`, `employee_id`, `old_values`, `new_values`
(jsonb, the normal form). Unique per set by position and by record; indexed by record, employee and, for additions,
the attendance month. Append-only.

### 11.2 Fields

`RecordCatalogue` holds each record's columns with the workbook's labels and their type (date, text, longtext,
int, days (one decimal), decimal, money, list, debt), `required` on adding and `fixed` (set once: the attendance's
year and month, a payment's debt).
`GET /records/{type}/fields` serves it for the forms. `RecordValues` normalises and checks values as `FieldValues`
does for the file and checks the rules between fields on the record as it would be. `RecordComputed` gives the
formula columns: a dated record's year and month, a movement's days; a month's absent days, rate and
`high_absence` (≥ the branch's `high_absence_rate`); a debt's `paid_total`, `payments_total`, `remaining_amount`,
`installments_left` and `status` with `status_label` (paid, overdue, outstanding, as of today in Damascus).
`LeaveTypes` says which movement types are leaves (the four codes, or a name beginning with «إجازة»).
`MovementEffects` says what each movement type makes of the file (D-048), which targets it takes and needs, and
`ApplyMovementToFile` applies it: at approval when its day has come, otherwise `records:apply-due-movements` that
morning; once; a later movement already taken wins. A movement's `computed` has `file_fields`, `applied_to_file_at`
and `file_change_set_id`.

`RecordRegister` builds a register: the approved records and, unless `review_status=approved`, the waiting
additions read from their change items with the same columns, in one UNION ALL (D-041). The waiting half takes the
waiting sets as an array first and casts every value to the table's exact column type, so PostgreSQL merges the
halves and pushes the filters into the indexes (D-031).

### 11.3 Rules

| Who | Adds | Corrects | Removes | Approves, refuses |
| --- | ---- | -------- | ------- | ----------------- |
| Data entry | waits | waits | waits (reason required) | no |
| Supervisor | no | at once | no | yes, not their own |
| General manager | no | no | no | no |
| Administrator | at once | at once | at once | yes, not their own |

- Only real changes are kept; nothing changed → 422. A fixed field changed → 422.
- An addition names its employee by `employee_id` or by `personal_number` (any digits); an unknown one → 422. A
  payment may name neither: its debt's employee.
- A leave needs its period; its days are counted from it.
- A payment is never corrected (422): removed with its reason and entered again; it pays at most what is left (when
  sent and when approved); a debt with payments is not removed, nor corrected below what was paid of it.
- Only approved files take records; the employee's file may be of someone who left.
- A month of attendance once per employee: refused when approved, waiting in another submission, or twice in a batch.
- A batch (`POST /records/{type}/batch`, ≤ `HR_RECORDS_BATCH_LIMIT`, 500): additions and corrections of one kind;
  every error of every change at once under `changes.{i}`; kept whole or not at all; approved whole.
- Approval refuses a stale set (a record corrected or removed since, a file no longer approved) → 422 naming the
  positions; the rules between fields are checked again on the record as it is.
- A removed record is in no list, count or total; `?deleted=1` lists them.

### 11.4 API reference

| Method | Path | Permission | Notes |
| ------ | ---- | ---------- | ----- |
| GET | `/records/{type}/fields` | `records.view` | The columns for the form |
| GET | `/records/{type}` | `records.view` | The register: approved records and waiting additions. Filters: `q` (the employee's name or number), `employee_id`, `personal_number`, `org_unit_id` (+ `include_sub_units`), `review_status` (approved, pending), `year`, `month`, `from`, `to` (attendance: by month), `type_id` (the record's list), `conduct_status_id`, `debt_status`, `creditor`, `debt_id` (payments), `high_absence`, `no_absence`, `deleted`; `sort` (`date`, `created_at`, `employee`, and each register's own: `amount`, `days`, `absent_days`, `absence_rate`, `remaining_amount`, `due_on`), `direction`, `page`, `per_page` 5 to 100. Each record with `review_status` and `review_status_label` («معتمد», «قيد التدقيق»), `change_set_id` (a waiting one's), `employee` (number, name, unit, title), `fields` (label, value, display), `computed`, `pending` (corrections waiting) |
| GET | `/records/{type}/{id}` | `records.view` | Removed ones too, and a waiting addition |
| GET | `/records/{type}/{id}/history` | `records.view` | Every approved change, oldest first |
| POST | `/records/{type}` | `records.create` | `employee_id` or `personal_number`, `values`, `note`; 201 with the set |
| PATCH | `/records/{type}/{id}` | `records.update` | `values`, `note`; 201 with the set (not for payments) |
| DELETE | `/records/{type}/{id}` | `records.delete` | `reason` required (3 to 2,000); 201 with the set |
| POST | `/records/{type}/batch` | `records.create` for additions, `.update` for corrections | `changes: [{action, employee_id or personal_number or record_id, values}]`, `note` |
| GET | `/record-changes` | `records.view` | The queue: `status` (pending by default), `record_type`, `employee_id`, `mine`, `sort` (`submitted_at`, `reviewed_at`), `direction`; without the items |
| GET | `/record-changes/{id}` | `records.view` | With every item: approved and proposed values and their display |
| POST | `/record-changes/{id}/approve` | `entries.review` | `note` optional; not one's own |
| POST | `/record-changes/{id}/reject` | `entries.review` | `reason` required (3 to 2,000); its author is notified |
| POST | `/record-changes/{id}/withdraw` | the author | |
| GET | `/records/attendance/planned-days?year&month` | `records.view` | The month's suggestion (D-043) |
| GET | `/employees/{id}/records/summary?year` | `records.view` | The status report's year and latest records (D-044) |

`{type}` is `movements`, `attendance`, `conduct`, `debts` or `payments`. The file (`GET /employees/{id}`) gains
`computed.has_debts`, `total_remaining`, `open_debts`, `overdue_debts`; the list gains `debts_remaining` and the
`has_debts` filter.

### 11.5 Audit events

| Event | Label | Context |
| ----- | ----- | ------- |
| `records.change_submitted` | إرسال إضافة أو تصحيح أو حذف في السجلات | the kind, the set, the count of each action, the employees, whether applied at once |
| `records.change_approved` | اعتماد إضافة أو تصحيح أو حذف في السجلات | the kind, the set, self-approved |
| `records.change_rejected` | ردّ إضافة أو تصحيح أو حذف في السجلات | the kind, the set, the reason |
| `records.change_withdrawn` | سحب إضافة أو تصحيح أو حذف في السجلات | the kind, the set |

The values are in the change items only (D-039).

### 11.6 Measuring

`php artisan records:generate-demo --years=3` (development only, after `employees:generate-demo`) adds three years
of records with their change history (the attendance as a unit's month, 500 files a submission) in bulk SQL, about
three minutes. The timings are in D-031.

## 12. Notifications module (Step 4.1, D-046)

`app/Modules/Notifications`: Laravel database notifications in `notifications` (uuid, `type`, `notifiable` uuid
morph, `data` jsonb, `read_at`), sent by `StaffNotice` subclasses inside the transaction that causes them.

| Kind | When | To | Details |
| ---- | ---- | -- | ------- |
| `change_rejected` | a submission of the file or of a record, or an uploaded document, is refused | its author | `area` (employees, records, documents; for a document `change_set_id` is the upload's id), `change_set_id`, `record_type`, `employee_id`, `employee_name`, `reason`, `reviewer_name` |
| `contract_ending_soon` | `employees:remind-expiring-contracts`, 06:00 Damascus: contracts ending within 30 days, once per file and end date | active users with `employees.update`, not the administrator or the general manager | `employee_id`, `personal_number`, `employee_name`, `ends_on`, `days_left` |

| Method | Path | Notes |
| ------ | ---- | ----- |
| GET | `/notifications` | Mine, newest first; `unread=1`, `per_page` 5 to 100 |
| GET | `/notifications/unread-count` | `{unread}` |
| POST | `/notifications/{id}/read` | One of mine (404 for another's) |
| POST | `/notifications/read-all` | `{marked}` |
| GET | `/badges` | `notifications.unread`; `reviews` (with `entries.review`: `employee_changes`, `record_changes`, `documents`, `total`, not one's own); `identity` (with `identity.review`: `to_review`, `to_lock`); `mine.pending` (my waiting submissions and uploads); `employees` (with `employees.view`: `contracts_ending_soon`, `monthly_overdue`); null for a section not seen |

Any signed-in active staff user; nobody sees another's.

## 13. Documents module (Step 5, D-049)

`app/Modules/Documents`: the workbook's 13 attachments as uploaded files, and a conduct record's document.

### 13.1 Table and storage

**`employee_documents`**: one row per upload. `id` (uuid), `employee_id`, `kind`, `conduct_record_id` (only for
`conduct_document`), `status` (pending, approved, rejected, withdrawn), `disk`, `path` (unique), `original_name`,
`mime`, `size_bytes`, `sha256`, `note`, `uploaded_by`, `uploaded_at`, `self_approved`, `reviewed_by`, `reviewed_at`,
`review_note`, `superseded_at` and `superseded_by_id` (both or neither), `removed_at`, `removed_by`,
`removal_reason`, `content_purged_at`, timestamps.

- CHECKs: the 14 kinds; a conduct record exactly for `conduct_document`; the four types; 0 < size ≤ 50 MB (the API
  allows 5); a hex SHA-256; decided with a reviewer (withdrawn without); a reason for a refusal; `self_approved`
  exactly when the reviewer is the uploader; only an approved upload superseded or removed, removed with a reason;
  purged only when refused, withdrawn or removed.
- Partial unique indexes: one current file (approved, not superseded, not removed) per employee and kind, and one
  per conduct record.
- Trigger `employee_documents_guard`: no DELETE (and no TRUNCATE); what was uploaded (employee, kind, record, disk,
  path, name, type, size, hash, note, uploader, time) never changes; a decision is taken once; superseded, removed
  and purged once.
- The content is on the `documents` disk (`config/filesystems.php`: `storage/app/documents`, private, not served;
  `HR_DOCUMENTS_DISK` for another disk, `HR_DOCUMENTS_MAX_KB` for the limit, 5120). `storage/app` is not in git. A
  refused, withdrawn or removed upload's file is deleted after the commit; a superseded one is kept.
- The migration `2026_11_29_000050` (Audit) lets `documents.downloaded` be a security event in
  `audit_events_category_follows_event`.

### 13.2 Kinds and completeness

| Key | Label | Workbook | Column | Cycle | Counts |
| --- | ----- | -------- | ------ | ----- | ------ |
| `identity_copy` | صورة الهوية | رابط الهوية | AL | once | ✔ |
| `contract_terms` | وثيقة أحكام المتعاقد | رابط وثيقة أحكام المتعاقد | AM | once | |
| `security_form` | الإستمارة الأمنية | رابط الاستمارة الأمنية | AN | once | ✔ |
| `signature_form` | نموذج التوقيع | رابط صورة التوقيع | AO | once | |
| `course_certificates` | شهادات الدورات | رابط الدورات | EB | semi-annual | |
| `portrait` | الصورة الشخصية | رابط الصورة الشخصية | EI | annual | ✔ |
| `family_statement` | البيان العائلي | رابط البيان العائلي | EJ | annual | ✔ |
| `practical_certificate` | الشهادة العملية | رابط الشهادة | EK | annual | ✔ |
| `graduation_certificate` | مصدقة التخرج | رابط مصدقة التخرج | EL | annual | |
| `contract_copy` | صورة العقد الوظيفي | رابط صورة العقد | EM | annual | |
| `cv` | السيرة الذاتية | رابط السيرة الذاتية | EN | annual | |
| `inquiry_record` | نشرة الإستعلامات | رابط نشرة الاستعلامات | EO | annual | |
| `sham_cash_copy` | صورة حساب شام كاش | صورة حساب الشام كاش | EP | annual | |
| `conduct_document` | وثيقة القيد السلوكي | (the conduct record's «رابط الوثيقة») | | | |

`FieldDefinitionsSeeder` adds the 13 after the 125 fields as definitions of type `document` (section «الوثائق
والمرفقات»): their cycle and ✔ are set once and then belong to the settings managers (`PUT /field-definitions/{key}`).
`FieldDefinitions::fields()` is the guide without them (the file's `fields`). `EmployeeDerived` counts a counted
document as filled when the employee has a current file of its kind; approving, replacing or removing one
recomputes an active file's completeness. The workbook's total is 73.

### 13.3 Rules

| Who | Uploads | Approves, refuses | Withdraws | Removes a current one | Reads, downloads |
| --- | ------- | ----------------- | --------- | --------------------- | ---------------- |
| Data entry | waits | no | their own | no | yes |
| Supervisor | no | yes, not their own | | no | yes |
| General manager | no | no | | no | yes |
| Administrator | at once | yes, not their own | | yes, with a reason | yes |

- Uploads go to a file waiting for approval or approved; not to a refused or withdrawn file.
- The type is the content's (`finfo`): PDF, JPEG, PNG, WebP; anything else → 422 whatever its name. The download
  name keeps the uploaded name with the real extension.
- The same content (SHA-256) as the kind's current file, or as one of its uploads waiting → 422.
- A conduct document names a kept conduct record of the same employee; another kind names none → 422.
- Approving supersedes the current file in the same transaction; a decided upload is not decided again (422).
- The download is 410 when the content was purged, 404 (and reported) when the file is missing from the disk.

### 13.4 API reference

| Method | Path | Permission | Notes |
| ------ | ---- | ---------- | ----- |
| GET | `/document-kinds` | `documents.view` | The 14 kinds with the guide's cycle and ✔ |
| GET | `/employees/{id}/documents` | `documents.view` | `attached`, `total` (13), `slots` (each kind: `kind`, `current` or null, `pending`), `conduct` |
| GET | `/employees/{id}/documents/history` | `documents.view` | Every upload, newest first; `kind`, `page`, `per_page` 5 to 100 |
| POST | `/employees/{id}/documents` | `documents.manage` | multipart: `kind`, `file`, `conduct_record_id`, `note`; 201 |
| GET | `/documents` | `documents.view` | The queue: `status` (pending by default), `kind`, `employee_id`, `mine`, `sort` (`uploaded_at`, `reviewed_at`), `direction`, `per_page` |
| GET | `/documents/{id}` | `documents.view` | |
| GET | `/documents/{id}/download` | `documents.view` | The content; `inline=1` to show it; logged |
| POST | `/documents/{id}/approve` | `entries.review` | `note` optional; not one's own |
| POST | `/documents/{id}/reject` | `entries.review` | `reason` required (3 to 2,000); the uploader is notified |
| POST | `/documents/{id}/withdraw` | the uploader | |
| DELETE | `/documents/{id}` | `documents.manage` and `entries.review` | `reason` required (3 to 2,000) |

A document has `status` and `status_label`, `state` and `state_label` (pending, current, superseded, removed,
rejected, withdrawn), its uploader and reviewer, `content_available`, `can_download`, `can_decide`, `can_withdraw`,
`can_remove`. The file (`GET /employees/{id}`) gains `computed.documents_attached`; a conduct record
(`/records/conduct`) gains `document` (`current` or null, `pending`). The download needs the bearer token, so the
desktop app fetches it and shows the blob.

### 13.5 Audit events

| Event | Label | Context |
| ----- | ----- | ------- |
| `documents.uploaded` | رفع وثيقة لملف موظف | the upload, employee, kind, record, type, size, hash |
| `documents.approved` | اعتماد وثيقة لملف موظف | the upload, employee, kind, self-approved, the one it superseded |
| `documents.rejected` | ردّ وثيقة مرفوعة | the upload, employee, kind, reason |
| `documents.withdrawn` | سحب وثيقة مرفوعة | the upload, employee, kind |
| `documents.removed` | إزالة وثيقة حالية من ملف موظف | the upload, employee, kind, reason |
| `documents.downloaded` | تنزيل وثيقة أو عرضها | the upload, employee, kind, inline; **security** category (`AuditTrail::SECURITY_EVENTS`) |

## 14. Testing

`php artisan test` (PostgreSQL database `hr_test`, `RefreshDatabase`, the catalogues seeded through
`DatabaseSeeder` for every test; the field guide's per-request cache cleared): **171 tests**. Fixed device keys in `tests/Support/TestDeviceKeys.php` (D-020); helpers in
`tests/Support/StaffTestHelpers.php` (`staffUser($role)`, `admin()`, `pendingDevice()`, `approvedDevice()`,
`loginThrough()` (challenge and signed login through the API), `actingAsStaff()`).

| Test file | Covers |
| --------- | ------ |
| `Feature/Identity/DeviceLoginTest` | Signed login (token bound to the device, until 00:00 in Damascus, last seen and last login, audit), the token refused after midnight, the username signed as sent and found lowercase, single-use and expired challenges, the same message for every failure (other key, wrong password, unknown user, another user's device, inactive user), a failed login logged with reason, device, username and address but not the password, challenges for approved devices only, logout of one session, a deactivated user's token refused, every role logs in the same way |
| `Feature/Identity/DeviceManagementTest` | Registration idempotent by key, key and MAC checks, pending cap, status without the owner, approval after the fingerprint (any case and spacing, audit, once), one approved device per user (API and database), none for the inactive, revocation deletes tokens, list filter, permissions, console approval, mass-assignment guard |
| `Feature/Identity/AccessManagementTest` | Each role's defaults, the administrator holds everything, the general manager read-only, a roleless user holds nothing, catalogue and roles listed, permissions added and removed (404 when not held), the administrator's role fixed, the general manager's read-only, the seeder keeps the administrator's changes, a role changed and audited, the last user manager kept, only the administrator gives the administrator's role, deactivation (tokens, not oneself, once) and reactivation, a new password signs out and keeps the device, users listed and filtered (Arabic digits), data entry and the general manager do not manage access |
| `Feature/Identity/StaffUserRegistrationTest` | A user added (normalised username, Arabic digits, hashed password, audit), created inactive with a reason, Arabic messages and field names, unique username and personal number, database CHECKs (username, deactivation) |
| `Feature/Identity/CreateAdminCommandTest` | `staff:create-admin` with the API's rules, audited `via: console`; weak or mismatched password refused; confirmation when an administrator exists; refused before the roles are seeded |
| `Feature/Audit/AuditEventApiTest` | Row with actor, category, related ids, address; nothing after a rollback; append-only (update, delete, truncate); category follows the event; newest first with filters (kind, event, related id, actor, Damascus days, bad input); operations only for `audit.view_related`; permission needed; every recorded event has a label and every label is recorded |
| `Feature/RequestConventionsTest` | Arabic 401, 403, 404 and 429 (with `Retry-After`), Arabic validation messages, all without `\u` escapes; the database session in UTC |
| `Feature/Settings/LookupListTest` | The 28 lists, each in its own table, seeded in order with their system codes; the same word in two lists is two rows; everybody reads, the supervisor and the administrator change; added at the end and audited; duplicates by search key (and per list); rename and move; archive, restore (archived duplicate restored, not added); system items renamed, never archived (API and the table's CHECK); an id of another list or an unknown list is 404; reorder all active items; the seeder keeps the managers' changes and restores a missing code |
| `Feature/Settings/OrgUnitTest` | Six levels with paths and the seventh refused; sibling names unique, cousins free; archive after the children, restore under an active parent; rename; the database trigger (level, root, archive with children, move); data entry reads only |
| `Feature/Settings/HolidayAndWorkSettingsTest` | Days with both ends, by year; correct and delete (204); ends before start and longer than a year refused; the workbook's branch settings from the start (Friday and Saturday off); changes with their rules (the days off follow the working days, no day twice), each change audited, the same value recording nothing; only settings managers change |
| `Feature/Employees/EmployeeReviewFlowTest` | Data entry adds: an empty pending file with the values beside it, out of the lists, approved by the supervisor; the administrator adds at once, the supervisor and the general manager cannot add; a change beside the approved value until approved, then history; the supervisor's update at once and logged; refusal needs a reason, withdrawal is the author's; data entry does not approve, nobody approves their own; a stale set is refused; values checked with Arabic field names (dates, ranges, phone, archived and unknown items, unknown fields, contract order, nothing changed); unique national number in the file and in the queue; names required; the history cannot be rewritten (items, decided sets); the general manager reads only |
| `Feature/Employees/IdentityLockTest` | A locked identity changed by nobody (every role, three fields); the database refuses it, never unlocks, never touches the fingerprint, other fields stay changeable; an empty identity field filled once by the administrator's approval (fingerprint renewed, intact), then locked; the supervisor's approval does not lock, approving an identity change does; the lock action is the administrator's, once; a change waiting when the identity is locked is refused at approval; the administrator approving a new file locks it; the fingerprint catches a change made around the trigger (`employees:verify-identity` fails, audit event); the fingerprint is keyed and does not reveal the number |
| `Feature/Employees/CycleReviewTest` | Confirmed once per period, counts when approved (due date, history); without changes and only the cycle's fields; overdue again after the window (and the list filter); the four periods |
| `Feature/Employees/EmployeeFileTest` | The guide (125 fields and the 13 documents, sections, cycles, lists, 72 counted, the identity fields); completeness of 73; completeness and missing by cycle follow the definitions (settings managers only); age, service years, contract days left for today in Damascus; the workbook's warnings; list search (spellings, Arabic digits, numbers), unit and sub-units, contract, completeness, pending, sort; a unit with people is not archived; the definitions' flags survive reseeding |
| `Feature/Records/RecordReviewFlowTest` | Data entry adds: nothing in the record until approved, the id chosen at submission, author and approver kept, audit; a correction waits beside the approved value, the supervisor's applies at once, the history; the supervisor neither adds nor removes (also in a batch), the general manager reads only; a removal needs a reason, marks the record, lists it with `deleted=1`; the database refuses deleting a record, changing a removed one and rewriting the history; nobody approves their own, a refusal needs a reason, the author withdraws; a stale correction is refused; the queue by employee and kind |
| `Feature/Records/RecordRulesTest` | Each kind's columns for the form; required, typed and listed values with Arabic labels, one normal form (Arabic digits, decimals, spaces), unknown fields, archived items, a range in order; only approved files; attendance: attended ≤ planned, planned ≤ the month, the month fixed, nothing changed, one month once, absence computed and flagged, the database's checks; debts: amount > 0, paid ≤ amount (on correction too), what is left and the status, the file's debt columns and the list filter; a conduct record with its decision |
| `Feature/Records/AttendanceBatchTest` | A month for three files sent, approved together; the supervisor's batch of corrections at once; every error of a batch reported together and nothing kept; a month twice in a batch, or waiting elsewhere; one stale change refuses the whole batch; the batch limit; no removals in a batch; the planned days from the days off and overlapping holidays, each date once |
| `Feature/Records/RecordSummaryTest` | The year's leaves (any «إجازة», a new leave type and a spelling without the hamza, the balance), courses, rewards and deductions by code (renamed items too), attendance sums and rate, conduct counts by code, debts open, late and left; the latest movements, conduct and month; another year; an employee with nothing; a removed debt counts for nothing |
| `Feature/Records/DebtPaymentTest` | A payment waits, then is the debt's (its employee the debt's; the total, what is left, the instalments left, the file's columns); at most what is left, when sent and when two are approved; a debt not corrected below what was paid; a payment never corrected, only removed; a debt with payments not removed; the database refuses editing a payment, writing the total, and a payment of another's debt |
| `Feature/Records/RecordRegisterTest` | The register by name or number (any spelling, Arabic digits), the personal number instead of the id (unknown refused), unit with sub-units, period, type, sort and direction, `per_page` 5 and filters in the links, bad sort refused; attendance by months, half days, no absence, the branch's absence threshold; conduct by status, debts by creditor and remaining; a waiting addition in the register but in no total |
| `Feature/Notifications/NotificationTest` | A refusal of the file or a record tells its author with the reason; unread count, read one, read all, nobody reads another's; the badges by role; a contract ending within 30 days told once to data entry and the supervisor, not the general manager or the administrator |
| `Feature/Settings/SettingsCountsTest` | A list item's use by files and records; the payment methods list; a unit's active employees with its sub-units |
| `Feature/Records/MovementToFileTest` | An approved discharge updates the file (date, «مسرح», «غير فعال») as «تطبيق حركة» in its history by the reviewer, nothing while waiting; each type's targets required or refused; the administrator's movements apply at once, a reward changes nothing; a movement for a later day waits for it (the daily job, once); an old movement entered late does not undo a later one; a waiting change of the same field becomes stale; a taken movement stays taken |
| `Feature/Documents/DocumentTest` | The 14 kinds and the five counted; data entry's upload waits (stored by its id, the queue, the badges), the supervisor approves and the completeness follows; a reviewer's upload replaces the current file, the old one superseded and readable, the same content refused; a refusal needs a reason, purges the content, tells the uploader, 410 on download; the uploader alone withdraws; the type from the content, the size limit; the administrator alone removes, with a reason; the download's headers and its security log, `documents.view` needed; a conduct record's document on its record; a document's ✔ from the guide; the database refuses deleting or changing an upload |
| `Unit/ArabicTextTest`, `Unit/BusinessDayTest` | Digits, search key; the next Damascus midnight, a day's UTC bounds |

API documentation check: `php artisan scramble:analyze` must report no warnings. Code style: `./vendor/bin/pint`.

The whole login was also run over HTTP with `tools/device-client.mjs` against `php artisan serve`: registration,
console approval, signed login, a call with the token, the audit log.

## 15. Known limitations and pending work

- The views, reports and import come in Steps 6 and 7 (section 2).
- Uploads are checked for their type only; there is no antivirus scan on the server. The workbook's existing links
  come with the import (Step 7). The status report, the dashboards and the exports read the records in Step 6.
- A document's content is a file on the server's disk: backing up `storage/app/documents` with the database belongs
  to the deployment (Step 7).
- A removed or corrected movement does not undo what the file took from it (D-048): the file is corrected as any file.
- Open for the owner (from the frontend's questions): the dashboard's period, complete attendance and what an import
  brings (OD-26). Instalments have no schedule, so «متأخر قسط واحد» is not computed (OD-21).
- A wrong value in a locked identity cannot be corrected in the application, by the owner's decision (D-034): the
  administrator checks it before approving.
- Assumed until the owner says otherwise: the current work unit (OD-15), the three names as the minimum of a new
  file (OD-16).
- Sensitive fields are stored as given (D-030).
- Passwords are not checked against known leaks (OD-4).
- The device key is protected by the Windows user, not by hardware (D-013).
- Failed logins are in the application log only; the audit table's retention belongs to the deployment.
- `/docs/api` is reachable in `local` only; production serves `/api/…` alone (Step 7's nginx).
- Migrations may still change in place until the first production installation; from then on they are append-only
  (recruitment D-101).

## 16. Changelog

| Step | Summary |
| ---- | ------- |
| 1 | Foundation: Laravel 12 modular monolith on PostgreSQL 16, UTC connection, Damascus business days, Arabic unescaped JSON as a global middleware, Arabic 401/403/404/429 and validation messages (D-001 to D-005, D-011, D-012, D-022). Identity: staff users with one role (data entry, general manager, system administrator) and the whole system's permission catalogue (D-006, D-019); device-bound login with the payload prefix `hr-staff-login-v1`, tokens until 00:00 in Damascus (D-009, D-013, D-014); access and user management with the last-manager and no-escalation rules (D-015); `staff:create-admin`, `staff:approve-device` (D-018). Audit: append-only `audit_events` with related ids and a read API (D-017). Reference device client. 63 tests |
| 2 | Owner's answers (OD-1, 2, 3, 5, 7): the `supervisor` role with `entries.review` and `settings.manage` (D-023), the desktop app only, sensitive fields stored as given (D-030). Settings: 28 managed lists with search-key duplicates, system codes and archive / restore / reorder (D-024, D-025); the organisational structure as a six-level tree with paths and database guards (D-026); holidays as date ranges (D-027); the branch settings as one row with the four review windows (D-028); the workbook's sample structure and holidays in local only (D-029). 84 tests |
| 2.1 | Owner's answers of 23 Nov: the supervisor reviews anything, the file shows the old value with the pending change beside it, the supervisor reads and updates without adding or deleting (D-023): `employees.manage` split into `employees.create` / `.update`, `records.manage` into `records.create` / `.update` / `.delete`, with a migration that carries a seeded database over. Job title is a list (OD-10). Performance and history plan (D-031). 84 tests |
| 2.2 | The managed lists moved from one pair of tables to **one table per list** (27 tables, plain foreign keys, the recruitment system's way; D-024), with shared code (`ListCatalogue`, `ListItem`, `ManagedList`) and the same API; `LookupList` and `LookupItem` removed. The supervisor's own update takes effect at once and is logged (OD-11, D-023). Development continues from the owner's repository. 85 tests |
| 3 | The employee's file: 125 typed input fields from «دليل الحقول» with 41 database checks, field definitions as data (D-032); every entry a change set, pending beside the approved value, reviewers applying at once, stale sets refused, the approved items as append-only history (D-033); the identity guard: two identity fields per change at most, identity changes approved by `identity.review` only (D-034); completeness and search key stored, age and service computed (D-035); cycle confirmations per period with overdue (D-036); the workbook's warnings (D-037); `fled` and `retired` codes; a unit with people is not archived; `employees:generate-demo` and the measurements (D-031). Also the Step 2.2 leftovers: `LookupList` and `LookupItem` removed, the Step 2.2 documentation. 113 tests |
| 3.1 | The identity limit replaced by the **identity lock** (owner, 24 Nov): six identity fields (الرقم الذاتي، الرقم الوطني، الاسم، الأب، الأم، العائلة); locked when the administrator approves them; then changed by nobody, enforced by a trigger; empty ones filled once (D-034). The keyed identity fingerprint and its nightly check (D-038). History and logs: the record and the application log kept for good, only changes stored, measured at 55 MB a year for 4,000 files; expired challenges and tokens pruned; a failed login logged with the device, the username and the address (D-039). No correction after the lock (OD-14 answered). 117 tests |
| 4 | The records: «سجل الحركات», «سجل الدوام الشهري», «سجل السلوك الوظيفي», «سجل الديون» as four typed tables, written only by approved changes, never deleted (D-040); their changes reviewed like the file's, batches of up to 500 for a month's attendance, approved whole, stale sets refused (D-041); the workbook's checks and formula columns, in the API and the database (D-042); the weekly days off and the month's planned days suggested (D-043); the status report's year and the file's debt columns, computed (D-044); `course` and four conduct codes; `records:generate-demo` and the measurements (D-031). 138 tests |
| 4.1 | Aligned with the frontend (D-047): `*_label` beside every enum, `reason` for refusals and removals, `per_page` 5–100 with `sort`/`direction` and filters in the links, `/api/v1` as the documented base, every response typed. Owner's answers of 25 Nov: waiting additions in the registers, counted nowhere (D-041); debt payments as entries of their own with a payment methods list (D-045); leave days computed as calendar days, a leave needs its period; only internal and external leave from the balance (D-042, D-044); OD-17, OD-18 answered. Notifications and `/badges` (D-046). From the requirements: «الرقم الذاتي» required, the register's filters, filled out of counted (68, 73 with Step 5), service dates, debt counts, list items' use, units' active employees, computed daily hours, the absence threshold as a setting, codes for the leave, movement and conduct types; half days of attendance; the registers' filters by employee, unit, period. 153 tests |
| 4.2 | The hiring system's `name_ar` for list items and units (and `{id, name_ar, archived}` values in the file, the register and the records) and `POST /new` for staff users (D-047). «الرقم الوطني» required on a new file; every field of a new file and of a change in the API document, generated from the catalogue; «تاريخ التقديم» = the file's approval date (OD-24). A movement updates the file (D-048, OD-23): targets on the movement, effects by type, applied when approved and due or by `records:apply-due-movements`, «تطبيق حركة» in the file's history. 160 tests |
| 5 | The employees' documents (D-049): the workbook's 13 attachments as uploads on a private disk, one row each, never deleted, content checked by type and hash; reviewed like the file (data entry's wait, a reviewer's at once, a refusal notifies, the uploader withdraws), the approved one replacing the current file and the old kept as superseded; removal by the administrator with a reason; downloads streamed safely and logged as security events; the 13 in the field guide as `document` definitions, so completeness counts the workbook's 73; a conduct record's document; the file's `documents_attached`, the badges' `reviews.documents`. «صلة السكن بجهة الطوارئ» not added (OD-25). 171 tests |
