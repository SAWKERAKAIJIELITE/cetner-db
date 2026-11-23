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

### D-006 Staff are the only actor; one role each; four roles

- **Status:** Accepted (owner, Step 2: a supervisor exists; data entry is not split by section for now)
- **Decision:** the people using the system are staff (`staff_users`): data entry employees enter the information
  (owner, Step 1: "there are Data Entry employees enter the information to this project"). Each staff user holds
  **one role** (recruitment D-085); there are no personal grants or denials. The roles:

  | Role | Slug | Default permissions |
  | ---- | ---- | ------------------- |
  | مدخل بيانات | `data_entry` | view, add and update employees; view, add, update and delete records; documents; import; the operations audit |
  | المشرف | `supervisor` | view and update employees and records, adding and deleting nothing; review every entry (D-023); manage the settings; reports; the operations audit |
  | المدير العام | `general_manager` | every read-only permission it is given: employees, records, documents, reports, the whole audit |
  | مدير النظام | `system_admin` | every permission, always (computed, not stored, not editable) |

  The permissions of every role but the administrator's are data that the administrator edits (D-015).
- **Why:** the workbook has no roles; the recruitment system's model (offices, a viewing manager, an administrator)
  fits with one office and its supervisor. Splitting data entry by section later is a new role, not a code change.
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

- **Status:** Accepted (recruitment D-017, D-022; owner: "follow same approach"; Step 2: the desktop app only, OD-3)
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
| `employees.create` | | adding new employees (Step 3) |
| `employees.update` | | updating the employees' data, closing review cycles (Step 3) |
| `entries.review` | | the supervisor's review of every entry and change: approve or send back (Steps 3 and 4, D-023) |
| `records.view` | ✓ | movements, attendance, conduct, debts (Step 4) |
| `records.create` | | entering those records (Step 4) |
| `records.update` | | correcting them (Step 4) |
| `records.delete` | | removing a record entered by mistake (Step 4) |
| `documents.view` | ✓ | the 13 attachments (Step 5) |
| `documents.manage` | | uploading and archiving them (Step 5) |
| `settings.manage` | | the lists, the organisational structure, holidays, work settings (Step 2; administrator and supervisor, OD-2) |
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

### D-023 The supervisor reviews everything; reads and updates, adds and deletes nothing

- **Status:** Accepted (owner, 23 Nov: OD-8, OD-9)
- **Decision:**
  - A `supervisor` role (المشرف) with `entries.review`: the supervisor can review **anything**: every new employee,
    every change to a file, every record (movements, attendance, conduct, debts) and every deletion.
  - Until a change is approved **the file shows the approved (old) value, with the pending change beside it**. An
    approved change replaces the value; a refused one goes back to its author with a reason. Nobody reviews their
    own entry. Built with the data it reviews (Steps 3 and 4).
  - The supervisor **reads and updates; does not add or delete**: `employees.update` and `records.update` are the
    role's, `employees.create`, `records.create`, `records.delete`, `documents.manage` and `imports.manage` are not.
    For that the two "manage" permissions were split (`employees.create` / `.update`; `records.create` / `.update` /
    `.delete`); the migration `split_manage_permissions` carries an already seeded database over.
  - The supervisor keeps `settings.manage` (owner's earlier answer, OD-2), which does include adding list items and
    removing a holiday: the settings are configuration, not the employees' data.
- **Open:** whether the supervisor's own update takes effect at once or waits for another reviewer (OD-11).

---

## 4. Settings (Step 2)

### D-024 The lists of «الإعدادات» are managed data, their kinds are code

- **Status:** Accepted
- **Decision:** `lookup_lists` holds the 27 kinds of the workbook's drop-down lists (gender, blood type, marital
  status, education level, … debt type, work nature), seeded from `ListCatalogue`; nobody adds a kind from the
  application, because each one is used by fields of the code. `lookup_items` holds their items: added, renamed,
  reordered, archived and restored by `settings.manage` holders (the administrator and the supervisor, OD-2); never
  deleted, so a file that uses an item keeps it (D-007). Every signed-in user reads the lists (the forms need them).
  Integer keys (D-003's exception).
  - The same word typed another way is a duplicate: `search_key` (ArabicText, D-029 of the recruitment system) is
    unique in a list; «سكن مع الأهل» is «سكن مع الاهل». An archived duplicate is restored, not added again.
  - Not lists: «نعم / لا» (those fields become booleans) and «دورة التحديث» (the system's cycles, Step 3).
  - The seeder gives a list the workbook's items only when the list is first created; afterwards the items are data.
- **Rejected:** enums in code (a new item would need a release); one free-text column per field (the workbook's
  dashboards count by exact text; typing variants would split the counts).

### D-025 System items carry a code

- **Status:** Accepted
- **Decision:** an item the code's logic refers to has a `code` (unique in its list): gender `male` / `female`,
  employee status `active` / `inactive`, job status `discharged` (مسرح), health `healthy` / `injured` /
  `chronic_illness`, marital status `single`, housing `rented`, current study `none`, movement `reward` / `deduction`.
  It can be renamed; it cannot be archived (422, and a CHECK). The seeder ensures every code on every run: an existing
  item with the same word takes it, otherwise the item is added. Later steps add codes as their rules need them.
- **Why:** the workbook's checks compare text («مسرح» needs a discharge date, «ايجار» a rent, «مصاب» an injury type);
  a renamed item would silently break them. Codes keep the rules working whatever the label says.

### D-026 The organisational structure is a tree of at most six levels

- **Status:** Accepted
- **Decision:** `org_units` (id, parent, level 1 to 6, name, search key, archived). The workbook repeats six names on
  every row and on every employee; here each unit is one row and an employee will point at the deepest unit
  (Step 3). Siblings have distinct names (top-level units among themselves); cousins may share one («فرع المالية»
  under two directorates). A unit is archived once its children are (Step 3 adds: and it has no active employee);
  restored under an active parent; not moved: a reorganisation adds the new unit, moves the people, archives the old
  one, so history keeps the old path. A trigger guards the levels, an active unit's active parent, archiving with active
  children and moving. `GET /settings/org-units` returns every unit with its path (one recursive query), a parent
  before its children.
- **Rejected:** six text columns on the employee (the workbook's way: a typo makes a new unit; renaming a unit means
  editing every employee).

### D-027 Holidays are date ranges

- **Status:** Accepted
- **Decision:** `holidays` (name, `starts_on`, `ends_on` both included, `days` computed by the database, notes),
  at most a year long. Added, corrected and removed by `settings.manage` holders, each change audited with its dates;
  listed by year. Overlapping holidays are allowed: the attendance (Step 4) will count each date once.

### D-028 The branch settings are one row

- **Status:** Accepted
- **Decision:** `work_settings`, one row (id 1) created by the migration with the workbook's values: branch name,
  daily hours, start and end of the working day, lateness minutes, working days per week, currency, annual leave
  balance, and the four review windows (35, 100, 190, 370 days). CHECKs: the day ends after it starts, the windows grow
  (monthly ≤ quarterly ≤ semi-annual ≤ annual); the request says so in Arabic first. `PUT /settings/work` changes the
  fields sent; the audit keeps each change's before and after; the same value again records nothing.

### D-029 The workbook's sample structure and holidays are local only

- **Status:** Accepted
- **Decision:** the lists are the vocabulary of every installation (seeded everywhere). The workbook's eight sample
  units and its 2026 holidays look like examples, so `DevSettingsSeeder` loads them in `local` only; a real
  installation enters its own structure and holidays (or imports them, Step 7).

### D-030 Sensitive fields are stored as given, for now

- **Status:** Accepted (owner, Step 2, OD-7); encryption deferred
- **Decision:** the national number, the weapon numbers, the security form, the guardian's national number and the
  debts are stored as given (Step 3), protected by the server's disk encryption, access control and the audit.
  Encrypting columns is planned later; the employee schema keeps them in their own columns so that it can be done
  column by column (an encrypted column cannot be searched or made unique as plain text: the national number will
  then need a separate hash for uniqueness).

### D-031 Performance: one PostgreSQL server, indexes and history tables; no distributed database, little cache

- **Status:** Accepted as the plan (owner's questions, 23 Nov); measured in Step 3
- **Context:** the system is the central file, updated every month, quarter, half-year and year, and keeps what
  changed. About 4,000 people; at worst a few million history rows a year.
- **Decision:**
  - **One PostgreSQL server** (plus a standby replica and backups for safety, Step 7). This volume is small for
    PostgreSQL; a distributed database would cost the foreign keys, unique constraints, triggers and transactions the
    design relies on, and an operations burden, for a problem the system does not have.
  - **Current values on the employee's row, history in separate append-only tables**: everyday reads stay small
    whatever the history grows to.
  - **Indexes for the real queries**: الرقم الذاتي, the national number, the name's search key (trigram), unit, status,
    contract end; history by employee and date. Lists are paginated and return a few columns, never the 159.
  - **Dashboards count in SQL**; a materialized view refreshed after changes or nightly if a count gets slow.
  - **Cache only what is read on every screen and rarely changes**: the lists and the organisational structure
    (invalidated when they change), and the dashboard's numbers for a short time. Never the employees' files: a stale
    file or a hidden pending change costs more than the few milliseconds saved. The database cache store is enough
    (no Redis).
  - **Partitioning** the history and audit tables by year is kept for later: it pays from hundreds of millions of rows.
  - **Measure before tuning**: Step 3 ships a seeder that generates 4,000 employees with years of history, and the
    slow queries are read with `EXPLAIN ANALYZE`.
- **History as "version control"** (owner's question): each change of a field is a row (old value, new value, who
  entered it, who approved it, when, in which cycle), and each review of a cycle is a row per employee and period, also
  when nothing changed. The workbook's four «آخر مراجعة» dates become those rows, so "what was true in March" and "who
  confirmed the third quarter" are queries. Pending changes are stored beside the approved value until reviewed (D-023).

---

## Open questions

| ID | Question | Status |
| -- | -------- | ------ |
| OD-1 | Roles: a supervisor who reviews entries? data entry split by section? | **Answered** (Step 2): a supervisor exists (D-023); data entry is not split for now (D-006). |
| OD-2 | Who edits the lists, the organisational structure and the holidays? | **Answered** (Step 2): the administrator and the supervisor (`settings.manage`, D-024). |
| OD-3 | Which client? | **Answered** (Step 2): the desktop app only (D-013). |
| OD-4 | Leaked-password check (`uncompromised()`) needs the internet. | **Off** while the server has none. |
| OD-5 | Do data entry employees see the dashboards? | **Answered** (Step 2): no; `reports.view` is the supervisor's, the general manager's and the administrator's. |
| OD-6 | Connection with the recruitment system (and whether "Mawarid" is this system). | **Deferred** (owner, Step 1). |
| OD-7 | Sensitive fields: stored as given, or encrypted? | **Answered** (Step 2): as given now, encrypted later (D-030). |
| OD-8 | What does the supervisor review, and what does the file show until approval? | **Answered** (23 Nov): anything; the old value with the pending change beside it (D-023). |
| OD-9 | Does the supervisor also enter data? | **Answered** (23 Nov): reads and updates; does not add or delete (D-023). |
| OD-10 | «المسمى الوظيفي»: a list or free text? | **Answered** (23 Nov): a drop-down list (`job_title`, filled by the settings managers). |
| OD-11 | The supervisor's own update: does it take effect at once (audited), or wait for another supervisor or the administrator? | **Open: needed for Step 3.** Assumed until answered: at once, audited, since the supervisor is the reviewer. |
| OD-12 | Scale and performance (owner's questions, 23 Nov): cache, distributed database, history. | **Answered in D-031.** |

---

## Template

### D-0xx Title

- **Status:** Proposed / Accepted / Superseded by D-0yy / Deferred
- **Context:** what made the decision necessary
- **Decision:** what was decided
- **Why:** the reasons
- **Rejected:** the alternatives and why not
- **Consequences:** what follows from it
