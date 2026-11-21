# Design Decisions Log: HR Backend (نظام إدارة القوى البشرية)

Why the system is the way it is. Each decision has an id (D-0xx) that the code and `code-documentation.md`
refer to. Decisions taken from the recruitment system keep its id in brackets, for example "(recruitment D-017)".
Open questions are at the end (OD-xx): every "Assumed" there needs the owner's answer.

Template at the end of this file.

---

## 1. Foundation

### D-001 Same architecture as the recruitment system, as a separate application

- **Status:** Accepted (owner, Step 1: "build this project as it is … and follow same approach used in the recruitment project")
- **Decision:** one Laravel application, a modular monolith: one folder per business area under `app/Modules/*`,
  discovered by `ModuleServiceProvider` (migrations, `routes.php` under `/api/v1/staff`, console commands).
  Its own PostgreSQL database (`hr`), its own server and deployment. The recruitment system's conventions are kept:
  FormRequest → thin Controller → Action → Eloquent → Resource; constraints in the database; append-only history;
  Arabic messages; Damascus days. (recruitment D-001, D-010)
- **Why separate:** the HR file holds the most sensitive data (national numbers, weapons, security forms, debts) of
  people who never went through recruitment too; the recruitment server is reached from exam halls; and recruitment
  is in production with append-only migrations, so 159 new fields there would put every deployment at risk.
- **Rejected:** new modules inside the recruitment application (less to build, but no isolation); a no-code tool
  (the workbook's logic needs code, see the Step 0 analysis).
- **Consequence:** Identity and Audit are copied, not shared. Connecting the two systems is planned later (OD-6).
- **Laravel sorts every module's migrations by file name:** a migration that references another module's table
  carries a later timestamp.

### D-002 PostgreSQL everywhere, tests included

- **Status:** Accepted (recruitment D-002)
- **Decision:** development, tests and production use PostgreSQL 16; the tests use the database `hr_test`.
- **Why:** the schema relies on PostgreSQL (UUID, CHECK, partial unique indexes, `macaddr`, `inet`, `jsonb`, arrays,
  triggers). SQLite tests would pass while hiding constraint bugs.

### D-003 UUID primary keys; readable numbers are separate columns

- **Status:** Accepted (recruitment D-003)
- **Decision:** UUID keys (Laravel's ordered UUIDs). Readable numbers are their own unique columns. In particular
  **الرقم الذاتي will not be the employees' primary key** (Step 3): a person can be in the file before the number is
  granted (the recruitment system's ملف التثبيت leaves «تاريخ منح الرقم» for شعبة الخدمة), and a key that people
  type can be corrected; a UUID never changes.
- **Exception planned:** the managed lists use integer keys (recruitment D-034), Step 2.

### D-004 Time is stored as timestamptz and the database connection is UTC

- **Status:** Accepted (recruitment D-004)
- **Decision:** `timestampTz` columns, and `'timezone' => 'UTC'` on the `pgsql` connection. Laravel sends timestamps
  without an offset and PostgreSQL reads them in the session zone; forcing the session to UTC stores the right
  instant. `RequestConventionsTest` checks the session zone.

### D-005 JSON keeps Arabic unescaped, for every response

- **Status:** Accepted (recruitment D-018, extended)
- **Decision:** `UnescapedUnicodeJson` sets `JSON_UNESCAPED_UNICODE` on every JSON response. It is a **global**
  middleware here, not the `api` group's: in the recruitment system the authentication and throttle middleware ran
  before the group's own, so their 401 and 429 bodies carried `\u` escapes until they were fixed one by one
  (recruitment D-101). The error bodies built in `bootstrap/app.php` pass the flag as well.

### D-022 Production settings that differ from Laravel's defaults

- **Status:** Accepted (recruitment D-101)
- **Decision:** `SESSION_DRIVER=file` (the API keeps no session), `QUEUE_CONNECTION=sync` (nothing is queued, no
  worker; the jobs table migration is removed), `CACHE_STORE=database` (rate limits, the scheduler's locks), no
  `users` table (staff live in `staff_users`). The deployment guide comes with Step 7.

---

## 2. Identity and access

### D-006 Staff are the only actor; one role each; three roles

- **Status:** Accepted for the structure; the role list is **assumed** (OD-1)
- **Decision:** the people using the system are staff (`staff_users`): data entry employees enter the information
  (owner, Step 1: "there are Data Entry employees enter the information to this project"). Each staff user holds
  **one role** (recruitment D-085); there are no personal grants or denials. The roles:

  | Role | Slug | Default permissions |
  | ---- | ---- | ------------------- |
  | مدخل بيانات | `data_entry` | view and manage employees, records and documents; import; the operations audit |
  | المدير العام | `general_manager` | every read-only permission it is given: employees, records, documents, reports, the whole audit |
  | مدير النظام | `system_admin` | every permission, always (computed, not stored, not editable) |

  The permissions of `data_entry` and `general_manager` are data that the administrator edits (D-015).
- **Why:** the workbook has no roles; the recruitment system's model (offices, a viewing manager, an administrator)
  fits with one office. A second office later is a new role, not a code change.
- **Rejected:** per-user permissions (recruitment D-085 removed them: harder to reason about, harder to audit).

### D-007 No cascading deletes; no ON UPDATE actions

- **Status:** Accepted (recruitment D-007)
- **Decision:** every foreign key is `RESTRICT`. People are deactivated, never deleted; lists are archived. The one
  exception: `device_login_challenges` cascade with their device (derived, short-lived rows).

### D-008 Usernames and passwords

- **Status:** Accepted (recruitment D-035, D-058)
- **Decision:** usernames are Latin only: 3 to 50 of `a-z . _ -`, stored and compared trimmed and lowercase
  (`Username`, a CHECK in the database). Names: at least 3 characters, no digits (CHECK). Personal numbers: digits
  only, 4 to 50 (Arabic digits accepted and stored as ASCII). Passwords: 8+ characters with upper and lower case, a
  digit and a symbol (`StaffPassword`). Not checked against known leaks: that needs the internet (OD-4).

### D-009 Sanctum tokens until the business day ends

- **Status:** Accepted (recruitment D-009, D-036, D-099)
- **Decision:** a login issues a Sanctum personal access token, stored hashed, bound to the device it was issued to,
  that ends at the next 00:00 in Damascus (`BusinessDay::startOfNext`). Sanctum's global expiration is off; the
  lifetime is the token's. Logout deletes the token used; deactivation, a new password and revoking the device
  delete them all.
- **Why:** the desktop app has no cookie origin; database tokens are revoked at once.

### D-010 Thin controllers, Actions for use cases, Resources as output whitelists

- **Status:** Accepted (recruitment D-010, D-011)
- **Decision:** Controller → FormRequest → Action (one use case, one transaction) → Eloquent → Resource. No
  repository layer. Complex reads get query classes. A Resource names every field it returns.

### D-011 Permissions through `Gate::before` and `can:`; one Arabic 401, 403, 404 and 429

- **Status:** Accepted (recruitment D-012, D-094, D-099, D-101)
- **Decision:** `Gate::before` answers an ability named like a permission slug with `true` when the user holds it,
  otherwise `null`, so policies still apply. Routes use `can:slug`, or `can.any:a,b` (`RequireAnyPermission`).
  Errors under `/api/*` are JSON with Arabic messages: 401 «انتهت الجلسة …», 403 «ليست لديك صلاحية لهذا الإجراء.»
  (a refusal that says why keeps its own message), 404 «العنصر المطلوب غير موجود.», 429 «محاولات كثيرة …» with
  `Retry-After`. Validation messages and field names are Arabic (`lang/ar/validation.php`): a new field needs its
  Arabic name there.

### D-012 Business days are Damascus days

- **Status:** Accepted (recruitment D-081, D-093)
- **Decision:** instants are stored in UTC; "today", the start of a day and a list's `from` / `to` are days of
  `config('hr.timezone')` (Asia/Damascus), through `App\Support\BusinessDay`.

### D-013 Staff log in from a trusted device

- **Status:** Accepted (recruitment D-017, D-022; owner: "follow same approach")
- **Decision:** the same protocol as the recruitment system, with its own payload prefix `hr-staff-login-v1`, so a
  signature for one system is never valid on the other:
  1. The desktop app generates an ECDSA P-256 key pair once; the public key (SPKI PEM), a label, the MAC address and
     OS details go to `POST /devices/register` (pending; idempotent by key).
  2. A holder of `devices.approve` compares the fingerprint shown on the employee's screen, types it back and picks the
     device's one owner. One approved device per user (partial unique index).
  3. Login: `POST /auth/challenge` (single use, 60 seconds), then `POST /auth/login` with the signature of
     `prefix \n challenge_id \n challenge \n device_id \n username`.
  4. The MAC address is a label, not a credential; the key is the proof.
- **Limitation:** in Electron the key is protected by the Windows user's protection, not by hardware (recruitment D-017).
- **Changed from the recruitment system:** the challenge's expiry is compared with the application's clock, the same
  clock that set it, instead of the database's `now()`.

### D-014 Throttles, one failure message, the active check

- **Status:** Accepted (recruitment D-013, D-014)
- **Decision:** login 5 per minute per username and IP plus 30 per minute per IP; challenge 60 per minute per IP and
  10 per device; registration 20 per hour per IP; status 30 per minute per IP. Every login failure returns the same
  message; an unknown user still costs a password check. The reason goes to the log (`staff_login_failed`), never the
  username. `EnsureStaffIsActive` refuses a deactivated user's tokens on every request.

### D-015 The administrator edits roles; someone always manages users

- **Status:** Accepted (recruitment D-020, D-085)
- **Decision:** `users.manage` holders add permissions to a role and remove them, change a user's one role, add,
  deactivate and reactivate users, and set a new password for a forgotten one. Rules:
  - the administrator's role is not edited (422); the general manager's takes read-only permissions only (422);
  - only the system administrator gives the system administrator's role (403), so a role given `users.manage` cannot
    make itself administrator;
  - a change that would leave no active user able to manage users is refused and rolled back (422);
  - nobody deactivates themselves;
  - `RolesAndPermissionsSeeder` upserts the catalogue every run but gives a role its defaults only when the role is
    first created: an administrator's change survives every deployment.

### D-016 Mass assignment

- **Status:** Accepted (recruitment D-021)
- **Decision:** `$fillable` holds what the caller supplies; columns decided by rules (status, owner, approver, role,
  activation) are set with `forceFill()` in the Actions. Outside production, assigning a non-fillable column throws.

### D-018 The first administrator and the first device come from the shell

- **Status:** Accepted (recruitment D-101, D-017)
- **Decision:** `php artisan staff:create-admin <username>` creates a system administrator with the API's own rules
  (password asked twice, never an argument; a confirmation when an active administrator exists; refused before the
  roles are seeded; audited `access.user_created` with `via: console`). `php artisan staff:approve-device <device-id>
  <username> --fingerprint=…` approves that administrator's first device (also the break-glass path). Everybody
  else is added from the application.

### D-019 The permission catalogue of the whole system is defined now

- **Status:** Accepted
- **Decision:** the catalogue already holds the permissions of the coming steps (employees, records, documents,
  settings, reports, imports), so the roles get their defaults when they are created. In the recruitment system a
  permission added later had to be attached to roles by hand (recruitment D-020); here that happens only for
  permissions not foreseen now.

| Permission | Read-only | For |
| ---------- | :-------: | --- |
| `employees.view` | ✓ | the employees' files, the per-person forms (Step 3, 6) |
| `employees.manage` | | adding employees, updating their data, review cycles (Step 3) |
| `records.view` | ✓ | movements, attendance, conduct, debts (Step 4) |
| `records.manage` | | entering and correcting those records (Step 4) |
| `documents.view` | ✓ | the 13 attachments (Step 5) |
| `documents.manage` | | uploading and archiving them (Step 5) |
| `settings.manage` | | the lists, the organisational structure, holidays, work settings (Step 2) |
| `reports.view` | ✓ | the dashboards and exports (Step 6) |
| `imports.manage` | | importing existing workbooks (Step 7) |
| `audit.view` | ✓ | the whole audit log |
| `audit.view_related` | ✓ | the operations, without the security events |
| `users.manage` | | users, roles and permissions |
| `devices.approve` | | approving and revoking devices |

### D-020 Fixed test keys

- **Status:** Accepted (recruitment D-023)
- **Decision:** the tests sign with four fixed P-256 key pairs (`tests/Support/TestDeviceKeys.php`): loading and
  signing existing keys needs no `openssl.cnf`, so the suite runs the same on Windows (XAMPP) and Linux.

---

## 3. Audit and API documentation

### D-017 The audit log is an append-only table

- **Status:** Accepted (recruitment D-030, D-078)
- **Decision:** `AuditTrail::record($event, $actor, $context)` writes a row of `audit_events` in the action's
  transaction (a rolled-back action leaves none) and a log line. `category` is `security` for `access.*`, `device.*`
  and `auth.*`, `operations` otherwise (a CHECK keeps them in step). `related_ids` (text array, GIN index) holds the
  context's `id`, `*_id` and `*_ids` values at any depth, so `related_id` finds every event about a record.
  Triggers refuse UPDATE, DELETE and TRUNCATE. `audit.view` reads everything; `audit.view_related` the operations.
  Every recorded event has an Arabic label (`AuditEvents::LABELS`; a test fails otherwise, both ways).
- **Consequences:** failed logins stay in the application log only (a flood must not fill the table). The table grows
  with use; its retention belongs to the deployment. The trigger function is `CREATE OR REPLACE`, because
  `migrate:fresh` drops tables but not functions.

### D-021 API documentation by Scramble

- **Status:** Accepted (recruitment D-019)
- **Decision:** Scramble generates the OpenAPI document from routes, FormRequests and Resources, with a Bearer scheme;
  public endpoints carry `@unauthenticated`. `php artisan scramble:analyze` must report no warnings. The pages
  (`/docs/api`) are open in `local` only; in production nginx serves `/api/…` alone.

---

## Open questions

| ID | Question | Status |
| -- | -------- | ------ |
| OD-1 | Roles: is one data entry role right, or should a supervisor review entries before they count (four eyes), or should data entry be split by section (personnel, attendance, finance)? | **Assumed:** one `data_entry` role, the general manager viewing, the administrator (D-006). New roles are data. |
| OD-2 | Who edits the lists, the organisational structure and the holidays (the الإعدادات sheet)? | **Assumed:** the system administrator only (`settings.manage` is not data entry's by default). One API call gives it to data entry. |
| OD-3 | Which client: the same Electron desktop app with device-bound login as the recruitment system, or a web page? | **Assumed:** the desktop app (D-013). A web fallback is deferred, as in recruitment (OD-3 there). |
| OD-4 | Leaked-password check (`uncompromised()`) needs the internet. | **Off** while the server has none. |
| OD-5 | Do data entry employees see the dashboards? | **Assumed:** no; `reports.view` is the general manager's (and the administrator's). The per-person forms come with `employees.view`. |
| OD-6 | Connection with the recruitment system (and whether "Mawarid", the integration planned there, is this system). | **Deferred** (owner, Step 1: "in the future we will apply the changes"). The schema keeps room for it: UUID keys, الرقم الذاتي not the key, national number unique. |
| OD-7 | Sensitive fields (national number, weapon numbers, security form, debts): stored as given, or encrypted? | **To decide in Step 3.** The recruitment system stores the national number as given (its D-032). |

---

## Template

### D-0xx Title

- **Status:** Proposed / Accepted / Superseded by D-0yy / Deferred
- **Context:** what made the decision necessary
- **Decision:** what was decided
- **Why:** the reasons
- **Rejected:** the alternatives and why not
- **Consequences:** what follows from it
