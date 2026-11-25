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
| `identity.review` | | approving the six identity fields, which locks them for good (Step 3, D-034); the administrator's only, by default |
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
  - **The supervisor's own update takes effect at once and is logged** (owner, 23 Nov, OD-11): the supervisor is
    the reviewer. Four eyes for the supervisor's updates may be added later; the change log keeps who made each
    change and who approved it, so that can be switched on without reshaping the data.

---

## 4. Settings (Step 2)

### D-024 Each managed list of «الإعدادات» has its own table

- **Status:** Accepted (owner, 23 Nov, after comparing the two designs); replaces the first Step 2 design
- **Context:** Step 2 first stored every list in one pair of tables (`lookup_lists`, `lookup_items`). The owner asked
  which design is better before the employee's file (about 30 fields that point at lists) is built on it.
- **Decision:** **one table per list**, 27 tables of the same shape (`genders`, `blood_types`, `marital_statuses`,
  `education_levels`, … `debt_types`, `work_natures`): `id`, `label`, `search_key` (unique), `code` (unique when set),
  `sort_order`, `created_by`, `archived_at`, `archived_by`, timestamps. Integer keys (D-003's exception).
  - Every field of the file is then a **plain foreign key to its own list**: the database itself refuses a blood type
    in the gender column. With one shared table that needs a trigger checking some 30 columns.
  - The same as the recruitment system (its nine managed lists are nine tables, its D-031), so the two schemas read
    the same way and map list to list when they are connected (OD-6).
  - A list that later needs a column of its own (a movement type's kind, an education level's rank) gets it without
    touching the others.
  - **The code is shared, not repeated:** `ListCatalogue` names each list's key, table, label and the workbook's
    items; one model (`ListItem`, whose table is set per list: `ListItem::queryFor('gender')`), one Action
    (`ManageListItems`), one controller and one seeder serve the 27 tables. The API did not change:
    `/settings/lists/{key}` and its items.
  - Items are added, renamed, reordered, archived and restored by `settings.manage` holders (the administrator and
    the supervisor, OD-2); never deleted, so a file that uses an item keeps it (D-007). Every signed-in user reads
    the lists (the forms need them). Nobody adds a list from the application: a new list is a new field, so a
    migration and a catalogue entry.
  - The same word typed another way is a duplicate: `search_key` (ArabicText) is unique in the table; «سكن مع الأهل»
    is «سكن مع الاهل». An archived duplicate is restored, not added again. The same word may be an item of two lists
    («عمل حر»): two rows in two tables.
  - Not lists: «نعم / لا» (those fields become booleans) and «دورة التحديث» (the system's cycles, Step 3).
  - The seeder gives a list the workbook's items only while its table is empty; afterwards the items are data.
  - Changes of one list are serialised by an advisory lock on its key.
- **Rejected:** the shared pair `lookup_lists` + `lookup_items` (two tables instead of 27 and the least code, but no
  foreign-key guarantee of the right list, an unreadable ERD with 30 arrows into one table, and awkward when one list
  needs its own column); enums in code (a new item would need a release); free text per field (the workbook's way:
  typing variants split the dashboards' counts).
- **Consequence:** the Step 2 migration `create_lookup_tables` was rewritten in place (no production installation
  yet, "Known limitations" in the code documentation): a development database is rebuilt with `php artisan migrate:fresh
  --seed`.

### D-025 System items carry a code

- **Status:** Accepted
- **Decision:** an item the code's logic refers to has a `code` (unique in its list): gender `male` / `female`,
  employee status `active` / `inactive`, job status `discharged` (مسرح), `fled` (فار), `retired` (متقاعد) (Step 3's warnings), health `healthy` / `injured` /
  `chronic_illness`, marital status `single`, housing `rented`, current study `none`, movement `reward` / `deduction`.
  It can be renamed; it cannot be archived (422, and a CHECK in each table). The seeder ensures every code on every run: an existing
  item with the same word takes it, otherwise the item is added. Later steps add codes as their rules need them.
- **Why:** the workbook's checks compare text («مسرح» needs a discharge date, «ايجار» a rent, «مصاب» an injury type);
  a renamed item would silently break them. Codes keep the rules working whatever the label says.

### D-026 The organisational structure is a tree of at most six levels

- **Status:** Accepted
- **Decision:** `org_units` (id, parent, level 1 to 6, name, search key, archived). The workbook repeats six names on
  every row and on every employee; here each unit is one row and an employee will point at the deepest unit
  (Step 3). Siblings have distinct names (top-level units among themselves); cousins may share one («فرع المالية»
  under two directorates). A unit is archived once its children are and nobody works in it (Step 3: no active file has it as unit or current work unit);
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

- **Status:** Accepted (owner's questions, 23 Nov); **measured in Step 3** (table below)
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

- **Measured** (Step 3, `php artisan employees:generate-demo 4000 --years=3`: 4,000 files, 192,200 change sets,
  144,000 change items, 192,000 cycle confirmations; one PostgreSQL 16, no cache, requests handled in-process, the
  median of six):

  | Request | Median | Queries |
  | ------- | -----: | ------: |
  | `GET /employees` (page 1 of 4,000, by name) | 59 ms | 14 |
  | `GET /employees?q=فاطمه الخطيب` (any spelling) | 78 ms | 20 |
  | `GET /employees?q=500000012` (number prefix) | 76 ms | 15 |
  | `GET /employees?overdue_cycle=monthly` | 104 ms | 14 |
  | `GET /employees?contract_ends_within_days=30` | 74 ms | 15 |
  | `GET /employees?completeness_below=20&sort=completeness` | 6 ms | 7 |
  | `GET /employees/{id}` (125 fields, pending changes, four cycles) | 22 ms | 18 |
  | `GET /employees/{id}/history` (36 months) | 20 ms | 10 |
  | `GET /employee-changes` (the review queue) | 15 ms | 9 |

  The query count per page is fixed (labels resolved in bulk), so it does not grow with the page or the history.
  Nothing calls for a cache, a read replica or partitioning at this size.

- **Measured again with the records** (Step 4, `records:generate-demo --years=3` on the same files: 144,000 months
  of attendance, 72,000 movements, 12,000 conduct records, 1,225 debts, 72,488 record change sets, 216,000 change
  items; the database 349 MB in all):

  | Request | Median | Queries |
  | ------- | -----: | ------: |
  | `GET /records/attendance?year&month` (25 of 4,000) | 22 ms | 12 |
  | the same, 100 a page | 37 ms | 12 |
  | `GET /records/attendance?year&high_absence=1` | 35 ms | 12 |
  | `GET /records/movements?year` | 33 ms | 13 |
  | `GET /records/movements?employee_id` | 14 ms | 13 |
  | `GET /records/debts?debt_status=overdue` | 18 ms | 12 |
  | `GET /records/attendance/{id}` | 10 ms | 11 |
  | `GET /employees/{id}/records/summary?year` | 24 ms | 24 |
  | `GET /record-changes` (the queue) | 13 ms | 7 |
  | `GET /employees?has_debts=1` | 58 ms | 14 |
  | `GET /employees/{id}` (with its debt columns) | 17 ms | 17 |

  Three years of records take about 186 MB with their indexes (the change items 95 MB, the attendance 33 MB, the
  movements 20 MB, the sets 15 MB): about 62 MB a year, so with the file's history about 117 MB a year for 4,000
  people. Still nothing calls for a cache or partitioning.

---

## 5. The employee's file (Step 3)

### D-032 One row per employee with the 125 input fields; «دليل الحقول» as data

- **Status:** Accepted
- **Decision:**
  - `employees` holds one person's **approved** values in 125 typed columns, one per input field of the workbook's
    `قاعدة بيانات الموظفين`. Every list field is a foreign key to its own list (D-024); the workbook's six
    organisational columns (U, AP to AT) are one `org_unit_id` (D-026), and its two «جهة العمل الحالية» columns
    (AV, AW) are one `current_work_unit_id`, the unit someone works in when it differs from their own (OD-15).
    «نعم / لا» fields are booleans, null when unknown. Phones and national numbers keep their leading zeros.
  - Not columns: the workbook's 11 formulas (D-035), its 13 document links (Step 5), its four «آخر مراجعة» dates
    (D-036). 125 + 11 + 13 + 4 + the merged organisational columns = the workbook's 159.
  - `FieldCatalogue` describes each field: workbook column, Arabic label, section, cycle, type and limits, whether it
    counts for completeness (the workbook's ✔: 67 of them, plus 5 documents in Step 5), whether it is one of the six
    identity fields (D-034). The migration and the checks are written out from it.
  - `field_definitions` is the same guide as data: `GET /field-definitions` gives the desktop app every field's
    label, section, type, list, cycle and flags, so the forms are built from it. The settings managers change two
    things, whether a field counts for completeness and its cycle; every active file's completeness is recomputed in
    the same transaction. Labels, types and the identity flag follow the code on every seeding.
  - The database checks what it can: ranges (graduation years 1950 to 2100, rates 0 to 100, Quran parts 0 to 30,
    counts), digits and phone formats, names without digits, dates 1900 to 2200, a contract that does not end before
    it starts, unique national and personal numbers (partial unique indexes), nothing in a file before it is approved.
  - A new file needs the three names (to be found by); everything else may come later.
- **Rejected:** several 1:1 tables by section (more joins for every read of a file that is always read whole, and
  each change set would have to know which table a field lives in); an entity-attribute-value table (no types, no
  constraints, no foreign keys, slow filters).

### D-033 Every entry is a change set: pending beside the approved value, then history

- **Status:** Accepted (owner: the supervisor reviews anything; the file shows the old value with the change pending
  beside it; the supervisor's own update takes effect at once and is logged; D-023)
- **Decision:**
  - Every addition, change and cycle confirmation is a **change set** (`employee_change_sets`: kind, author, time,
    note, status) with one **item** per changed field (`employee_change_items`: field, the approved value when
    submitted, the new value). Only real changes are kept: a value sent unchanged, typed differently («٠٩٩١ ٢٣٤»,
    "176.0") or not, is dropped; a submission with no change is refused.
  - **Pending** sets do not touch the file. `GET /employees/{id}` returns each field's approved `value` with the
    pending proposals beside it (`pending`: value, display, author, time). A new employee's file exists from the
    start, empty and `pending`, with its values in the set; it joins the lists once approved.
  - **Who applies at once:** an author holding `entries.review` (the supervisor, the administrator) applies their own
    set at once, logged as `self_approved` (a CHECK keeps `self_approved` equal to "reviewer is the author"). Data
    entry's sets wait. Nobody approves their own pending set.
  - **Approving** writes the values; **refusing** needs a reason and sends it back (a refused new employee's file
    stays, `rejected`); the author may **withdraw** a pending set (`withdrawn`).
  - **Stale sets are refused, not merged:** approving checks that every item's old value is still the file's value;
    if another change came first, the set is refused with the fields named, and is sent again on the current values.
  - **The approved items are the history:** what changed, from what, to what, who entered it, who approved it, when,
    in which cycle (`GET /employees/{id}/history`). Triggers keep items and confirmations append-only and let a set
    change once, from pending to its outcome; nothing is deleted. Four eyes for the supervisor's own updates can be
    switched on later without reshaping anything: it is the `self_approved` rule.
  - A national or personal number is one person's: refused when another file has it or another pending set
    proposes it; the unique index settles a race at approval, said in Arabic.
- **Rejected:** saving into the file and keeping an audit copy (the reviewer would approve something already in
  effect; "show the old value with the change beside it" would need the old value reconstructed); one pending change
  per field (two data entry employees could not work on one file).

### D-034 The identity lock: once the administrator approves an identity, nobody changes it

- **Status:** Accepted (owner, 24 Nov); replaces the first Step 3 guard (at most two identity fields per change),
  which the owner rightly found useless: split into several changes it stopped nothing that the administrator's
  approval did not stop already.
- **Context:** the supervisor updates at once (D-023). Overwriting a file's names and numbers would erase one person
  and put another in their place, with the first person's history, without anybody approving a deletion or an addition.
- **Decision:**
  - **Six identity fields:** الرقم الذاتي، الرقم الوطني، الاسم، اسم الأب، اسم الأم، العائلة (`identity` in the
    catalogue and in `GET /field-definitions`; not editable from the application). Gender, birth date, birth place and
    registry number are ordinary fields.
  - **Before the lock**, an identity change (data entry's or the supervisor's) waits for `identity.review` (the
    administrator by default); the supervisor applies everything else at once but not this.
  - **The lock:** a file's identity is locked when a holder of `identity.review` approves it: by adding the file,
    by approving its addition, by approving a change of its identity, or with `POST /employees/{id}/identity/lock`
    (for a file whose addition the supervisor approved). `GET /employees?identity_locked=0` is the administrator's
    list of identities to confirm. The lock needs the three names and no identity change waiting.
  - **After the lock, a locked value is refused for everyone, the administrator included**, in the application
    (422, naming the fields) and in the database (a trigger on `employees` refuses changing a locked value, undoing
    the lock or touching the fingerprint). A change sent before the lock and approved after it is refused.
  - **An identity field still empty may be filled once** (الرقم الذاتي is often granted after the file exists, the
    mother's name may come later), approved by `identity.review`; then it is locked too.
  - The history keeps every value an identity field ever had before its lock.
- **No correction after the lock (owner, 24 Nov, OD-14):** a wrong value that was locked cannot be corrected through
  the application at all; the administrator checks the identity against the documents before approving it. The only
  way out is outside the application (a database administrator disabling the trigger), which the nightly fingerprint
  check then reports (D-038).
- **Rejected:** a limit of identity fields per change (useless, see above); locking without the administrator (the
  owner's rule is "once approved from admin"); a reasoned correction by the administrator after the lock (owner: the
  check happens before the lock, not after).

### D-035 What is derived: stored when it depends on the file, computed when it depends on today

- **Status:** Accepted
- **Decision:**
  - **Stored** with every approved change (and when the definitions change): the name's search key (any spelling,
    trigram index), the completeness percentage (the workbook's «نسبة اكتمال البيانات») and the missing counted fields
    by cycle («الحقول الناقصة حسب دورة التحديث»). Lists filter and sort on them without computing anything.
  - **Computed when read**, because they change with the calendar: age, years of service (the workbook's formula:
    from the earliest of assignment, appointment and contract signature to the discharge date or today, in years
    with one decimal), days left on the contract, the unit's path. Today is the Damascus day (D-012).
  - Not yet: the debts (`عليه ديون`, `إجمالي الديون المتبقية`) come with the debts record (Step 4); the documents
    count and the five counted documents with Step 5.

### D-036 Each cycle's confirmation is a row; overdue is computed

- **Status:** Accepted
- **Decision:** the workbook's four «آخر مراجعة» dates become `employee_reviews`: one row per file, cycle and period
  (monthly 2026-11, quarterly 2026-Q4, semi-annual 2026-H2, annual 2026), written when the confirmation is approved,
  with who confirmed and who approved. `POST /employees/{id}/reviews` confirms a cycle for the current period with
  the changes found among that cycle's fields, or none; once per period (a partial unique index). A cycle is
  **overdue** when it was never confirmed or its last confirmation is older than its window in the branch settings
  (35, 100, 190, 370 days), as the workbook colours it; `overdue_cycle` lists those files. "Who confirmed the third
  quarter" and "when was this file last reviewed" are queries.

### D-037 The workbook's coloured cells are warnings, not refusals

- **Status:** Accepted
- **Decision:** the file returns `warnings`: «مسرح» without a discharge date; «فعال» while the job status says the
  person left («مسرح», «فار», «متقاعد», by their codes, D-025); a contract that ended or ends within 30 days; each
  overdue cycle. They never block a save, because the missing piece is often not known yet; hard rules (types,
  ranges, the contract's order, unique numbers) are refused.

### D-038 The identity fingerprint: a keyed hash, for proof, not as the identifier

- **Status:** Accepted (owner's question, 24 Nov: "is a hash useful here, as a thumb fingerprint that doesn't change?")
- **Decision:**
  - **The stable "thumbprint" of a person is the file's UUID** (D-003): it never changes and means nothing outside the
    system. A hash of the identity cannot play that role: it changes with any letter of what it hashes.
  - **What a hash is good for here is proof.** At the lock, `identity_hash` = HMAC-SHA256 of the six identity values,
    keyed with `HR_IDENTITY_HASH_KEY` (its own key, kept with the backups; APP_KEY if unset). `php artisan
    employees:verify-identity`, scheduled nightly at 02:30 Damascus time, recomputes every fingerprint: a mismatch
    means an identity was changed around the application and the trigger (someone with database rights), and is
    logged as critical and recorded in the audit log (`employees.identity_check_failed`).
  - **Keyed, not a plain SHA-256:** a national number has eleven digits; without a key, anyone holding the hashes
    could try every number and read them back. With the key they cannot.
  - **Later uses:** when the national number is encrypted (D-030), the keyed hash is what stays searchable and
    unique; and it lets this system and the recruitment system recognise the same person without sending the
    number itself (OD-6).
- **Rejected:** the hash as the primary key (changes with corrections, reveals the number without a key); a hash of
  the whole file (every monthly change would change it; the history already records each change).

### D-039 History and logs: keep the record, prune the technical, never duplicate

- **Status:** Accepted (owner's question, 24 Nov: the balance between flooding the database with logs and
  preventing security issues)
- **Measured:** 4,000 files with three years of monthly and quarterly confirmations and a change a month take about
  160 MB with their indexes, about 55 MB a year (192,200 change sets at 363 bytes, 192,000 confirmations at 348 bytes,
  144,000 changed fields at 214 bytes). At that rate twenty years fit in a few gigabytes. The volume is not the risk;
  keeping the wrong things, or the same thing twice, is.
- **Decision:**
  - **The record is kept for good:** change sets and their items, cycle confirmations, the audit log. They are the
    security: they answer "who changed this, from what, who approved it". They are append-only (triggers).
  - **Only what changed is stored:** a change keeps its changed fields, never a copy of the file; a value sent
    unchanged is dropped; a confirmation without changes is one small row.
  - **Nothing is stored twice:** the audit log says who did what and points to the change set (`related_ids`); the
    values are only in the change items.
  - **Reads are not logged** (opening a file, a list); exports will be (Step 6), because an export takes data away.
  - **The application log is never deleted either (owner, 24 Nov):** one file a day (`LOG_STACK=daily`,
    `LOG_DAILY_DAYS=0`), in `storage/logs`, which the backups include. It holds every failed login (the reason, the
    device, the username as typed, the address; never the password) and the technical lines. It stays a file and not
    a table, so that someone trying passwords by the thousand fills a cheap file, not the database (the attempts are
    also throttled). Unlike the audit log, a file can be edited by someone with rights on the server; sending it to a
    separate log server is the step after (Step 7, deployment).
  - **Only what is not a record is removed:** login challenges (single-use random numbers) a day after they expire
    (`model:prune`) and expired tokens after two days (`sanctum:prune-expired`). Neither says who did what: the
    successful login is in the audit log (`auth.login`), the failed one in the application log.
  - **When a table grows large** (hundreds of millions of rows, decades away at this scale), the audit log and the
    history are partitioned by year and old years moved to archive storage; nothing is deleted.

---

## 6. The records (Step 4)

### D-040 The four records are four tables, written only by approved changes, never deleted

- **Status:** Accepted
- **Context:** «سجل الحركات» (4,000 rows), «سجل الدوام الشهري» (6,000), «سجل السلوك الوظيفي» (3,000), «سجل الديون»
  (2,000): rows typed by الرقم الذاتي, with the name, unit and job title looked up, a year, month, row number and key
  column for the sheets' formulas.
- **Decision:**
  - **One table per record**, with the workbook's columns typed (`employee_movements`, `employee_attendance`,
    `employee_conduct_records`, `employee_debts`); the list columns are the lists' ids (`movement_type`,
    `conduct_type`, `conduct_status`, `debt_type`). The employee is a foreign key; the name, unit and title are the
    file's, shown beside the record, not copied. The year, month, row number and key columns were the sheets'
    machinery and are gone (the year and month of a dated record are computed).
  - **Written only by an approved change** (D-041). The record's id is chosen when the change is sent, so the history
    names it before it exists; the author is `created_by`, the reviewer `approved_by`.
  - **Never deleted**: «حذف سجل أُدخل خطأً» (`records.delete`) marks the record (`deleted_at`, `deleted_by`) and the
    change that removed it keeps it as it was. A trigger refuses DELETE and TRUNCATE, any change to a removed
    record, and changing whose record it is or who entered it. Removed records drop out of the lists and every count;
    `?deleted=1` lists them.
  - **The database checks the workbook's rules again** (D-042).
- **Rejected:** one generic "records" table with a JSON body (no foreign keys to the lists, no checks, slow sums);
  deleting a record entered by mistake (the owner: the history is kept for good, D-039).

### D-041 A record change is reviewed like a change of the file; a batch is approved whole

- **Status:** Accepted
- **Decision:**
  - **The same rules as the file (D-023, D-033).** Data entry adds, corrects and removes (`records.create`,
    `.update`, `.delete`); each waits for a reviewer. The supervisor corrects (`records.update`) and it applies at
    once, logged as self-approved; the supervisor neither adds nor removes (OD-9) but approves data entry's additions
    and removals, since approving is reviewing, not entering (OD-17). The administrator does everything at once.
    Nobody approves their own; a refusal needs a reason; the author withdraws.
  - **`record_change_sets` and `record_change_items`**: a set is one kind of record and one or more changes; each
    item is an addition (its values), a correction (the approved values beside the new ones, changed fields only) or
    a removal (the record as it was, with the reason as the set's note). Append-only, decided once (triggers).
  - **Pending beside the approved value**: a record shows the changes waiting for it; a pending addition is in the
    review queue, not in the record, so no count includes what nobody approved.
  - **Stale is refused**: a record corrected or removed since a change was sent, or an employee whose file is no longer
    approved, refuses the set; it is sent again against the values as they are.
  - **Batches** (`POST /records/{type}/batch`, at most 500 changes, `HR_RECORDS_BATCH_LIMIT`): a unit's month of
    attendance is one submission, reviewed and approved in one decision instead of hundreds. It is checked whole:
    every error of every change comes back together (`changes.3.values.attended_days`) and nothing is kept unless all
    are right; approved all or none. Removals go one by one, each with its reason.
  - **Only approved files take records** (an employee waiting for approval has no records yet). Files of people who
    left (discharged, retired) still take records: a discharge is itself a movement, a debt outlives the job.
- **Rejected:** approving changes of a batch one by one (500 decisions for one month); records outside review
  (the owner: the supervisor reviews anything, OD-8).

### D-042 Each record's rules and computed columns, as the workbook's

- **Status:** Accepted
- **Decision:**
  - **Movements**: date and type required; «إلى تاريخ» not before «من تاريخ»; amount and course marks ≥ 0; days a whole
    number (entered, as in the workbook). Year and month computed.
  - **Attendance**: year, month, planned and attended days required; attended ≤ planned (the workbook's check);
    planned ≤ the month's days; one month per employee (a partial unique index, checked against the queue too). The
    month is fixed once entered (a trigger): a wrong month is removed and entered again, so a month never moves onto
    another. **Absent days** are generated by the database (planned − attended); **absence rate** = absent ÷ planned
    (0 when nothing was planned); a month at 20% or more is flagged (`high_absence`, the workbook's colour).
  - **Conduct**: date and type required; the decision, its number, date and status («حالة المعالجة») optional; the
    document link a text until Step 5 brings uploaded documents.
  - **Debts**: the amount required, > 0; paid ≤ amount; **what is left** generated by the database (amount − paid);
    **status** computed when read: paid (nothing left), late (its due date passed), outstanding.
  - Values in one normal form, as for the file (Arabic digits, "1500" = "1500.00", empty = null).

### D-043 The month's planned days are suggested from the days off and the holidays

- **Status:** Accepted (D-027 promised it)
- **Decision:** the branch settings gain **«أيام العطلة الأسبوعية»** (`weekly_days_off`, ISO weekdays, Friday and
  Saturday by default; as many as 7 − «أيام العمل», a CHECK). `GET /records/attendance/planned-days?year&month`
  returns the month's working days less the holidays falling on a working day, each date once even where holidays
  overlap. A suggestion for the entry form; the planned days are still entered (a unit may work on a holiday).
- **Assumed:** Friday and Saturday (OD-18).

### D-044 The status report's year and the file's debt columns are computed when read

- **Status:** Accepted
- **Decision:**
  - `GET /employees/{id}/records/summary?year` gives «مؤشرات السنة المختارة» with the workbook's formulas: leaves (a
    movement whose type begins with «إجازة», any spelling, so a leave type added later counts, as in the workbook),
    their days and the balance (`annual_leave_days` less every leave day, sick leave included, as the workbook counts
    it: OD-19), courses, rewards and deductions, the attendance sums and rate, complaints, warnings, punishments and
    thanks, the debts (all of them, not the year's: open, late, what is left); and the report's lists: the last ten
    movements, the last five conduct records, the last month. Newest by date, then by entry (the workbook: by entry).
  - Courses, rewards, deductions and the four conduct counts find their list items by **code** (`course`, `reward`,
    `deduction`, `complaint`, `warning`, `punishment`, `thanks`), so renaming the item changes nothing; those items
    are system items (D-025).
  - **The file's «عليه ديون» and «إجمالي الديون المتبقية»** are computed from the debts when read (`computed.has_debts`,
    `computed.total_remaining` on the file, `debts_remaining` and `?has_debts=` on the list): never stored, so they
    cannot drift from the record.
- **Rejected:** storing the year's indicators on the file (they change with every record and with the calendar).

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
| OD-11 | The supervisor's own update: at once, or reviewed by another? | **Answered** (23 Nov): at once and logged; four eyes for it possibly later (D-023). |
| OD-13 | One pair of lookup tables or one table per list? | **Answered** (23 Nov): one table per list (D-024). |
| OD-14 | The identity lock: can a wrong locked value be corrected? | **Answered** (24 Nov): no. Six fields, locked once the administrator approves them, changed by nobody; the administrator checks them before approving (D-034). |
| OD-15 | The workbook's «جهة العمل الحالية - مستوى تنظيمي 2 / 3» (AV, AW): the unit someone actually works in when it differs from their own? | **Assumed:** yes, one optional `current_work_unit_id` (D-032). |
| OD-16 | What must a new file have? | **Assumed:** the three names only; the rest may come later and shows as missing (D-032). |
| OD-17 | The supervisor does not add or remove records: may they approve data entry's additions and removals? | **Assumed:** yes, approving is reviewing (D-041). |
| OD-18 | The weekly days off. | **Assumed:** Friday and Saturday; editable in the branch settings (D-043). |
| OD-19 | «رصيد الإجازات المتبقي»: does sick or emergency leave come out of the annual balance? | **Assumed:** yes, every leave, as the workbook counts it (D-044). |
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
