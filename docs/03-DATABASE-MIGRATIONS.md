# 03 — Database & Migrations

> Status: **Implemented (Phase 2)** for the control plane and the tenant
> migration lifecycle. Queued/batched rollout is deferred until tenant counts
> justify it — see §3.3.

## 1. Two migration categories

```
database/migrations/
├── control/    → runs once, on meta_style_control
└── tenant/     → runs once per tenant database, on every tenant database
```

A file lives in exactly one directory. There is no shared directory and no
migration that runs on both.

Each tenant database owns its **own `migrations` table**. Laravel's standard
migrator does the work; only the orchestration around it is ours.

**Do not use plain `php artisan migrate`.** It targets the default connection
and the default migration path, which is neither of the two sets above. It is
not blocked or patched — hacking Laravel's core migration behaviour to stop
developers is worse than documenting the correct workflow. Use the `metastyle:*`
commands in §3; they are the only supported path.

Where the underlying mechanics are already solved by `stancl/tenancy`
(iterating tenants, switching connections, running a migration path against a
tenant database), the Meta Style commands **wrap** the package rather than
reimplement it. What we add is orchestration the package does not provide:
per-tenant status tracking, batching, locking, failure isolation and drift
reporting.

## 2. The central constraint

> Tenant databases cannot be migrated atomically. During a deploy, some tenants
> are on schema version N and some on N+1, possibly for hours.

Everything else in this document follows from that sentence.

### 2.1 Expand / contract is mandatory

Every schema change ships in **two releases**:

| Release | Phase | Contains | Safe because |
|---|---|---|---|
| R1 | **Expand** | Add nullable columns, add tables, add indexes, backfill. Code writes both old and new, reads old (or new-with-fallback). | Old code still works against the new schema. |
| R2 | **Contract** | Drop old columns/tables, add NOT NULL, add constraints. Code reads/writes new only. | Every tenant is already on N+1 and running R1 code. |

R2 may only ship once **every** tenant reports the R1 schema version.
`metastyle:tenant:status --drift` gates this.

**Forbidden in a single release:** renaming a column, changing a column type
in place, adding a NOT NULL column without a default, dropping anything that
current production code still reads.

Renames are done as: add new → backfill → dual-write → switch reads → drop old.
Four steps, at least two releases.

### 2.2 MySQL DDL is not transactional

A failed migration leaves partially applied DDL. Therefore:

- **One logical change per migration file.** Never combine "create table" and
  "alter another table" in one file.
- Migrations must be safe to re-run after a mid-way failure — check existence
  before creating (`Schema::hasTable`, `hasColumn`).
- Large `ALTER`s on big tenant tables use `ALGORITHM=INPLACE, LOCK=NONE` where
  MySQL supports it, and are flagged in the migration's docblock.

### 2.3 Backfills are not migrations

A migration may create the column. Filling millions of rows happens in a
**separate queued, chunked, resumable job**, not inside the migration. A
migration that runs for ten minutes on a large tenant will block that tenant's
deploy and time out the worker.

## 3. Commands

All under the `metastyle:` namespace.

| Command | Purpose | Status |
|---|---|---|
| `metastyle:control:migrate` | Migrate the control database. | Live |
| `metastyle:tenant:provision "<name>" --domain=<host>` | Full provisioning pipeline (`02-TENANCY.md` §8.2). | Live |
| `metastyle:tenant:provision "<name>" --retry=<id>` | Resume a failed provision. | Live |
| `metastyle:tenant:migrate --tenant=<id>` | Migrate one tenant, with output. | Live |
| `metastyle:tenant:migrate --all` | Migrate every migratable tenant, one independent attempt each. | Live |
| `metastyle:tenant:migrate --retry-failed` | Migrate only tenants whose last migration failed. | Live |
| `metastyle:tenant:migrate --limit=<n>` | Bound a run to `n` tenants. | Live |
| `metastyle:tenant:status` | Table of tenant / schema_version / status / last error. | Live |
| `metastyle:tenant:status --drift` | Non-zero exit if any tenant is behind or failed. | Live |
| `metastyle:tenant:seed`, `:rebuild`, `--batch`, `--dry-run` | Convenience and rollout tooling. | Deferred (§3.3) |

`metastyle:tenant:migrate` exits non-zero if any tenant failed — but only after
every tenant has had its turn.

### 3.1 Guard rails

- Every destructive command refuses to run when `APP_ENV=production` unless
  `--force` **and** an interactive typed confirmation of the tenant's name.
- Every command that drops a database validates the name against the
  `tenant_[0-9]{6}` pattern first.
- `--all` refuses to run if the control database itself has pending migrations.

### 3.2 Migrations never create databases

Laravel 12's `migrate` command **creates a missing MySQL/MariaDB database**
rather than failing, silently when run with `--force`, and there is no flag to
turn it off.

That is helpful in a single-database application and dangerous here: a stale,
mistyped or mis-sharded `tenancy_db_name` would produce a real, empty, orphaned
database the control plane knows nothing about — and the migration would report
success.

`TenantMigrator` therefore checks the database exists, on the central
connection, *before* entering tenant context, and fails with
`TenantDatabaseMissing` if it does not. Provisioning creates databases;
migration only migrates (`DECISIONS.md` ADR-026).

### 3.3 Phase availability

| Command | Available from |
|---|---|
| `metastyle:control:migrate` | Phase 1 |
| `metastyle:tenant:provision` (incl. `--retry`) | **Phase 2** |
| `metastyle:tenant:migrate` (`--tenant`, `--all`, `--retry-failed`, `--limit`) | **Phase 2** |
| `metastyle:tenant:status` (incl. `--drift`) | **Phase 2** |
| `--batch`, `--dry-run`, `tenant:seed`, `tenant:rebuild` | Later, when tenant counts justify them |

Batching is deliberately deferred: `--limit` bounds a run today, and the
migrator already isolates failures per tenant, which is the property that
matters. A full rollout platform is not yet earning its cost.

## 4. Orchestration

```
metastyle:tenant:migrate --all
   │
   ├── select tenants: status IN (active, suspended, cancelled)
   │                   AND provisioning_status = completed
   │
   └── for each tenant  (TenantMigrator::migrateMany)
           │
           ├── acquire lock  metastyle:tenant:migrate:{id}   TTL 30 min
           │      └── already held → result = skipped (NOT a failure)
           ├── open tenant_operations row (type=migrate, attempt=n)
           ├── mark migration_status = running
           ├── assert database name matches the naming policy
           ├── assert the database EXISTS  (migrations never create one)
           ├── enter tenant context → run Laravel migrator on migrations/tenant
           ├── success → schema_version = target
           │             migration_status = succeeded
           │             last_migration_at = now, last_migration_error = null
           ├── failure → migration_status = failed
           │             last_migration_error = sanitised message
           │             (returns a failed RESULT — never rethrows)
           └── release lock, close tenant_operations row
```

**Failure isolation is the point.** Tenant 417 failing must not stop tenants
418..900, and must not mark them failed. Each tenant is an independent
try/catch returning a `TenantMigrationResult`; the run completes and reports
every tenant's fate, and the operator then works the failure list with
`--retry-failed`. This is covered by a test that migrates three tenants with a
deliberately broken one in the middle.

Execution is **synchronous** today. That is a deliberate simplification: with
tens of tenants a sequential run is measured in seconds, and a queued rollout
platform would be infrastructure ahead of need. The design does not preclude
it — `migrateMany()` takes any iterable and each tenant is already independent
and locked, so moving the loop body onto a queue is a contained change when
tenant counts justify it.

## 5. Per-tenant migration state

Stored in the control plane, on `tenants`:

| Column | Purpose |
|---|---|
| `schema_version` | Last successfully applied migration batch identifier (the target version string, e.g. `2026.04.1`). |
| `migration_status` | `pending` \| `running` \| `succeeded` \| `failed` |
| `last_migration_at` | Timestamp of last successful run. |
| `last_migration_error` | Truncated error message + file + line. Null on success. |
| `last_migration_batch_id` | FK to `migration_batches`. |

Plus one control table:

```
tenant_operations
  id, tenant_id, type (provision|migrate|seed|archive), status, attempt,
  error (sanitised), correlation_id, started_at, finished_at, timestamps
```

`tenant_operations` is the single record of everything the platform does *to* a
tenant, and is what the future SADMIN "tenant health" screen reads.

A `migration_batches` table was **not** built. The per-tenant columns above
already answer "what is the state of every tenant", which is the question that
matters; a batch table would only describe runs, and nothing consumes that yet.
Add it when a rollout UI needs it.

**Alerting:** any tenant in `failed`, or in `running` for longer than the lock
TTL, raises an operational alert.

## 6. Adding a new tenant while a migration batch is running

A tenant provisioned mid-batch runs the **full current** migration set and is
stamped with the current target version. It is therefore already at N+1 and is
excluded from the batch. No special handling required — provisioning always
runs all migrations from empty.

## 7. Schema conventions

Applies to both planes unless noted.

| Concern | Convention |
|---|---|
| Engine / charset | InnoDB, `utf8mb4`, `utf8mb4_unicode_ci`. |
| Primary keys | `bigIncrements` internally. |
| External identifiers | Every entity exposed over the API also has a `uuid` (indexed, unique). **APIs expose uuids, never auto-increment ids** — sequential ids leak business volume and invite enumeration. |
| Timestamps | `timestamp` columns, **always UTC**. `created_at` / `updated_at` on everything. |
| Soft deletes | Only where a business restore story exists. Never on financial or audit records. |
| Money | `bigInteger` **minor units** + a `currency` char(3) column beside it. Never `decimal`, never `float`. Currency exponent comes from `Kernel/Money` (IQD = 0 decimals, USD = 2). |
| Percentages / rates | `decimal(8,4)`. |
| Booleans | `boolean`, non-nullable, with an explicit default. |
| Enums | PHP backed enums in code; `varchar` + a check-style validation in the model. **No MySQL `ENUM` columns** (changing them is a table rebuild). |
| Translatable text | `json` column holding `{"ar": "...", "en": "..."}` (see `07-LOCALIZATION.md`). |
| Foreign keys | Declared, with explicit `onDelete` behaviour. Cross-plane FKs are impossible and must never be simulated. |
| Naming | snake_case, plural tables, singular columns, `{singular}_id` for FKs. |
| Indexes | Every FK indexed. Composite indexes ordered by selectivity. Every index added in a migration must name the query it serves in a comment. |
| Phone numbers | Stored E.164 (`varchar(20)`), plus a `country` column on the owning entity. |

### 7.1 Tables that must exist in every tenant database from Phase 2

Minimum viable tenant schema — everything else comes later:

```
migrations                 (Laravel)
settings                   key/value, json values, tenant-wide + per-branch
branches
users                      staff
roles                      system + custom, tenant-scoped
role_permissions           role → permission key (catalog lives in code)
user_roles
user_branches              branch scoping (+ all_branches flag on users)
audit_logs                 append-only
idempotency_keys           API replay protection
```

`jobs` and `failed_jobs` are **not** in tenant databases. Queues are Redis;
failed jobs are recorded in the **control** database so operators have one
place to look. Job payloads carry a tenant uuid, never tenant data.

### 7.2 Engine portability (MariaDB and MySQL 8)

Development runs on MariaDB 10.4 and CI runs on MySQL 8. Three constructs
diverge, and all three are enforced by
`tests/Architecture/DatabasePortabilityTest.php` rather than left to review:

| Rule | Why |
|---|---|
| No `storedAs`, `virtualAs`, `GENERATED ALWAYS`, `JSON_VALUE`/`JSON_EXTRACT`/`JSON_UNQUOTE` index, or `fullText()` | MySQL 8 can index a JSON path this way; MariaDB 10.4 cannot do it the same way. Reaching for one because CI runs MySQL 8 quietly makes MariaDB unsupportable (ADR-033). |
| No `default()` on a `json`, `text` or `blob` column | MariaDB accepts it, MySQL 8 rejects it. Green locally, red in CI. |
| `utf8mb4` / `utf8mb4_unicode_ci` named explicitly | MySQL 8 would default to `utf8mb4_0900_ai_ci`, which does not exist in MariaDB. |
| A scheduled instant is `dateTime()`, not `timestamp()` | Only the FIRST non-nullable `TIMESTAMP` in a table gets an implicit default; MariaDB gives every later one `0000-00-00 00:00:00`, which strict mode rejects. And a `TIMESTAMP` is converted through the SESSION timezone on read and write, which silently changes answers if the server's timezone does (ADR-046). Nullable lifecycle stamps stay `timestamp()` — they default to NULL and have neither problem. |

**The Phase 4 rule.** Services, categories and the electronic menu are built on
translatable JSON columns. Using JSON is fine and is the approved localisation
architecture (`07`). *Indexing* it in an engine-specific way is not, until:

1. a real query exists that needs it,
2. `EXPLAIN` on representative data demonstrates the problem, and
3. the index measurably improves a path that matters.

Then record the evidence in `DECISIONS.md` and allow the construct in the
portability test. A menu with a few dozen rows per center does not need one.

Nothing here proves a migration runs on MySQL 8 — only CI does. These rules
catch the known divergences before the push.

## 8. Seeding

| Seeder type | Runs | Idempotent | Contents |
|---|---|---|---|
| **Control system** | On deploy | Yes | Languages, entitlement definitions, payment provider definitions, plans. |
| **Tenant system** | At provisioning, and re-runnable | **Yes, required** | Default roles, default document/message templates, default settings, units, statuses. |
| **Tenant demo** | Manual, non-production | No | Sample branches, services, employees, customers for demos and tests. |

System seeders use `updateOrCreate` on a stable natural key so re-running after
a migration that adds a new default is safe.

**Reference data that changes with releases (entitlement keys, permission keys)
lives in code, not in seeded tenant rows.** This removes the entire class of
"tenant 312 is missing the new permission row" drift. See `05` and `06`.

## 9. Testing migrations

Covered in `11-TESTING-STRATEGY.md`; the migration-specific requirements are:

1. **Fresh build** — `tenant/` migrates cleanly from empty. Runs in CI on every
   push.
2. **Upgrade path** — migrate to the previous release tag, seed, then migrate
   to `HEAD`. Catches migrations that only work on empty databases.
3. **Expand/contract lint** — CI fails a PR whose `tenant/` migration drops a
   column, renames a column, changes a type, or adds a NOT NULL column without
   a default, unless the PR description contains an explicit
   `CONTRACT-MIGRATION:` marker naming the release that shipped the expand half.
4. **Order stability** — CI fails if a migration filename's timestamp is
   earlier than one already merged to `main` (prevents divergent ordering
   between tenants migrated at different times).

Point 4 matters more than it looks: two developers merging migrations with
out-of-order timestamps produce different schemas on tenants migrated before
and after the merge.

## 10. Control-plane schema

### 10.1 Built in Phase 2

```
tenants                 id (uuid, PK, public identity)
                        sequence (AUTO_INCREMENT, unique, internal — names the DB)
                        name, status, provisioning_status
                        tenancy_db_name (unique), db_host
                        schema_version, migration_status,
                        last_migration_at, last_migration_error
                        provisioned_at, suspended_at, archived_at
                        data (json, virtual-column overflow), timestamps

domains                 id, domain (unique), tenant_id -> tenants.id, is_primary

tenant_operations       id, tenant_id, type, status, attempt, error,
                        correlation_id, started_at, finished_at

platform_audit_logs     see 08-AUDIT-SECURITY.md §3

jobs                    queue backing store (ADR-025)
failed_jobs             one place for operators to look (ADR-015)
```

Two identifiers per tenant, deliberately: `id` is the **public** UUID used in
storage prefixes, cache tags, logs and (later) APIs; `sequence` is **internal**
and exists only to generate a safe database name. The sequence leaks signup
volume and must never be exposed (ADR-024).

`db_host` is unused today and present on purpose, so tenants can be sharded
across database instances later without a schema change (ADR-004).

### 10.2 Arriving with their phases

Not built yet, and not stubbed — an empty table is a promise the code has not
made:

```
Phase 3   plans · entitlements · entitlement_dependencies · plan_entitlements
          subscriptions · subscription_addons · tenant_entitlement_overrides
          tenant_usage_counters · platform_users · tenant_user_directory
          tenant_public_keys · impersonation_sessions
Phase 4   languages
Phase 10  saas_invoices · saas_invoice_lines · saas_payments · saas_credit_notes
          payment_providers
Phase 17  white_label_apps
```

Note what is **not** in the control plane at all: tenant payment credentials.
Those live in the tenant's own database because they are the center's property,
not the platform's (`09-STORAGE.md` §7, `14-FUTURE-INTEGRATIONS.md` §2).
