# 02 — Tenancy

> Status: **Implemented.** Host resolution and the provisioning/migration
> lifecycle since Phase 2; token-binding and session resolution since Phase 3.
> All three sources participate in the conflict rule.

This is the most safety-critical document in the project. A defect here leaks
one business's customer data to another.

## 1. Model

**Database per tenant.** One MySQL database per center, identical schema in all
of them, plus one control-plane database.

```
meta_style_control      ← platform metadata, plans, subscriptions, SaaS billing
tenant_000001           ← center #1 operational data
tenant_000002           ← center #2 operational data
tenant_000003           ← ...
```

Naming: `tenant_` + the zero-padded internal `tenants.sequence` (an
`AUTO_INCREMENT` column, distinct from the public UUID `tenants.id`). The name
is **generated once at provisioning time and then stored** in
`tenants.tenancy_db_name`; it is read from the control plane thereafter, never
recomputed and never derived from anything a user supplies (`DECISIONS.md`
ADR-024).

**Why database-per-tenant** (full rationale in `DECISIONS.md` ADR-002):

- Isolation is structural, not a `WHERE` clause someone can forget.
- Per-tenant backup, restore, export, and deletion are trivial.
- One tenant's data volume cannot degrade another's query plans.
- A single missing global scope cannot cause a cross-tenant leak.

**Cost we accept:** schema changes must be applied N times (see `03`), and
cross-tenant analytics requires aggregation into the control plane rather than
a single query.

## 2. Tenant resolution

### 2.1 The rule

> **A tenant is never identified by a value the client can choose.**

There is no `tenant_id` request parameter, no `X-Tenant-Id` header a client can
set, and no tenant field in a request body. Anywhere.

### 2.2 Allowed resolution sources

Exactly three, in priority order:

| # | Source | Used by | Mechanism |
|---|---|---|---|
| 1 | **Host** | Meta Style Web, customer web | `domains` lookup on the request host (subdomain or custom domain). **Live since Phase 2.** |
| 2 | **Authenticated principal binding** | Staff mobile app, API clients | The token carries the center's public key and its row lives in that center's database. Server-resolved, never a client claim. **Live since Phase 3** (ADR-027). |
| 2b | **Web session** | Meta Style Web | The center's public key, written to the signed session at login. Server-side state the browser cannot edit. **Live since Phase 3** (ADR-030). |
| 3 | **Signed public key** | White-label apps, embedded menus, QR links | An opaque, revocable public key mapped to a tenant in the control plane. Grants access to public endpoints only. *Phase 17.* |

If more than one source is present and they **disagree**, the request is
rejected with `403` and a security audit event is written. Silent preference
for one source is forbidden — disagreement means either a bug or an attack.

`TenantResolver::guardAgainstConflict()` implements this. With three live
sources the rule is now reachable through a real request — a token for one
center presented against another center's host is refused with
`TENANT.RESOLUTION_CONFLICT` and audited as a critical security event.

**Ordering matters.** Tenant resolution must run before authentication, because
tokens and staff accounts live in the tenant database. Laravel's middleware
priority list puts authentication first unless told otherwise, so
`bootstrap/app.php` inserts the resolver ahead of it — anchored on the
`AuthenticatesRequests` interface, not the concrete class.

If no source resolves, the request is rejected. There is **no default tenant**
and no fallback.

### 2.3 The staff-app login problem

The Meta Style App is one app used by staff of every center, so the tenant is
unknown at the login screen. Solution: a **login directory** in the control
plane.

```
tenant_user_directory
  login_hash      HMAC(app_key, normalised email or E.164 phone)   [indexed]
  tenant_id
  tenant_user_id
  status          active | disabled
```

Flow:

1. App posts identifier + password to a **platform** endpoint.
2. Platform hashes the identifier and looks up candidate tenants.
   The directory stores **no credentials and no plaintext PII**.
3. If one candidate → bind tenant, verify the password against that tenant's
   `users` table, issue a tenant-bound token.
4. If several candidates (a person works at two centers) → return the list of
   center names for a picker, then repeat step 3 against the chosen tenant.
5. If none → generic failure. The response must be **identical** in timing and
   body to a wrong-password response so the directory cannot be used to
   enumerate which phone numbers belong to Meta Style staff.

The directory is written by the tenant plane (on staff create/update/delete)
and is eventually consistent; the tenant `users` table is always the authority.

### 2.4 The public menu — the one identifier that comes from the URL

The electronic menu is opened by a customer with no session, no token and —
for most centers — no dedicated host. Something in the URL has to say which
center, so `GET /m/{center}` and `GET /api/v1/menu/{center}` resolve the
center's **public key** from the path (ADR-036).

This is a deliberate, bounded exception, and the boundary is what makes it safe:

| | |
|---|---|
| Middleware | `ResolvePublicTenant`, separate from `ResolveTenant` |
| May carry auth | **Never** — asserted by `tests/Feature/Menu/PublicRouteBoundaryTest.php` |
| Methods | GET only |
| Throttled | Yes, keyed by tenant and address |
| Conflicts | Host vs key disagreement → 403 + critical audit, same as everywhere |
| Unknown key | Plain 404, indistinguishable from any missing page |

The identifier is the public key precisely because it is already the opaque,
revocable, rotatable identifier designed to be handed out (ADR-027). It is never
the internal id and never the sequence.

## 3. Implementation: `stancl/tenancy` behind Meta Style abstractions

**Decision (ADR-018):** `stancl/tenancy` provides the tenancy infrastructure.
Meta Style does not reimplement it.

The package owns what it already solves well:

- tenant database creation and connection switching
- multi-database tenancy bootstrapping
- tenant-aware queues (context restored on the worker)
- cache and filesystem isolation bootstrappers
- tenant identification middleware
- tenant migration plumbing

Meta Style owns what is genuinely ours:

- the **control-plane data model** (`tenants`, subscriptions, entitlements,
  migration status — the package's `tenants` table is extended, not replaced)
- **migration orchestration** with per-tenant status, batching and failure
  isolation (`03-DATABASE-MIGRATIONS.md` §4)
- **provisioning** as a resumable business pipeline (§8.2)
- **resolution policy** — which sources are trusted and what a conflict means
- **fail-closed guarantees** (§4)

### 3.1 The coupling rule

> Application code must not reference `stancl/tenancy` classes, facades, or
> global helpers (`tenant()`, `tenancy()`).

Everything outside `App\Kernel\Tenancy` depends on Meta Style contracts:

```php
// app/Kernel/Tenancy/Contracts/

interface TenantContext
{
    public function tenant(): ?Tenant;                       // null in platform mode
    public function require(): Tenant;                       // throws TenantNotResolved
    public function id(): ?string;
    public function isBound(): bool;
    public function run(Tenant $t, callable $callback): mixed;   // exception-safe
    public function forget(): void;
}

interface TenantResolver
{
    public function resolve(Request $request): ?Tenant;
    public function resolveWithSource(Request $request): array;  // [?Tenant, ?string]
    public function findByKey(string $key): ?Tenant;
}
```

`TenantProvisioningService` and `TenantMigrator` are concrete classes in
`App\Kernel\Tenancy` rather than interfaces: they have exactly one
implementation and no second one is foreseeable, so an interface would be
ceremony (ADR-011). They depend on the contracts above, not the other way
round.

`Tenant` is a Meta Style **immutable value object**, not the package's Eloquent
model — business code cannot mutate tenant state in passing. The adapters in
`App\Kernel\Tenancy\Infrastructure\` plus `TenancyServiceProvider` are the only
places `Stancl\*` appears, and an architecture test fails the build if that
changes.

**Why the wrapper:** the package's global helpers and ambient state are
convenient and would spread into hundreds of call sites within a year. Wrapped,
the package is a swappable infrastructure detail; unwrapped, it is a permanent
architectural commitment. The wrapper is thin — a handful of interfaces and
adapters — and is enforced by an architecture test.

**Why not build it all ourselves:** the bootstrappers for queue, cache and
filesystem isolation are real work, easy to get subtly wrong, and already
solved. Rebuilding them would be exactly the over-engineering this project is
trying to avoid.

### 3.2 What we do not take from the package

- Its `tenancy()->central()` style call sites — wrapped.
- Its default tenant model as our control-plane model — extended instead.
- Its bare `tenants:migrate` command as the production workflow — our
  orchestrator wraps it to add per-tenant status, batching and failure
  isolation.

## 4. Fail-closed tenant protection (mandatory)

> Tenant database access without an initialised tenant context **must fail**.
> It must never silently fall back to another tenant or to the control database.

This principle is independent of the package and survives any change to it.

There is deliberately **no connection named `tenant` in `config/database.php`.**

The tenancy layer creates `database.connections.tenant` when a tenant is
initialised and **deletes it** when tenancy ends. So while no tenant is bound
the connection does not exist at all, and any attempt to use it fails
immediately — a stronger guarantee than an empty database name.

What the config file does define is `tenant_template`: driver, host,
credentials, charset, collation and strict mode, with **no database name**. It
is copied to build the live connection and is never connected to directly. It
must not be called `tenant`, because the package destroys that name on every
revert.

```php
'tenant_template' => [
    'driver'   => 'mysql',
    'host'     => env('DB_TENANT_HOST', env('DB_HOST')),
    'database' => null,   // copied, never connected to
    // charset utf8mb4, collation utf8mb4_unicode_ci, strict => true
],
```

Three layers enforce fail-closed behaviour:

1. **No `tenant` connection exists** unless a tenant is initialised.
2. **A guard that makes the error legible.** `TenantConnectionGuard` converts
   the resulting driver error into `TenantConnectionNotInitialized` naming the
   model and the missing context, so the cause is obvious rather than a cryptic
   "Database connection [tenant] not configured" three layers away.
3. **Operational tenant models resolve their connection through the guard**
   rather than inheriting the default. `TenantAuditLog` is the reference
   implementation: `getConnectionName()` calls `ensureInitialized()` first, so a
   query with no tenant raises a named exception instead of a driver error.
   Every tenant model added from Phase 4 follows this pattern.

**Exception-safe teardown is part of the guarantee.** The package's own
`Tenant::run()` has no `try/finally`: if the callback throws, tenancy stays
initialised and the next operation inherits the previous tenant's connection,
cache tag and storage prefix. `TenantContext::run()` wraps it with a `finally`
that always restores the prior context. This is one of the concrete reasons the
wrapper exists, and it has its own test.

**Automated tests are mandatory** (`11-TESTING-STRATEGY.md` §4, cases 1–2):
a query on the `tenant` connection with no tenant initialised must throw, and
must not read from the control database.

Phase 1 ships layers 1 and 2 plus their tests, before any tenancy lifecycle
exists — the property is cheapest to guarantee when there is nothing to break.

### 4.1 Credentials

Phase 2 ships a **single shared application database user** with privileges on
`tenant_%`. Per-tenant database users are supported by the schema
(`db_username`, `db_password_encrypted`) but are not used initially.

`db_host` exists in the schema from day one so tenants can later be distributed
across database instances without a migration. (`DECISIONS.md` ADR-004.)

## 5. Isolation matrix

Isolation is not just the database. Every row below must be implemented and
covered by the mandatory isolation test suite.

| Dimension | Mechanism | Failure mode if wrong |
|---|---|---|
| **Database** | Separate MySQL database + fail-closed connection guard | Cross-tenant data leak |
| **Files** | Disk root `tenants/{tenant_uuid}/...`, all paths via `Kernel/Storage` | Cross-tenant file access |
| **Cache** | Redis prefix `t:{tenant_id}:`; platform uses `p:` | Wrong tenant's cached data served |
| **Queue jobs** | `TenantAware` trait captures tenant uuid at dispatch; job middleware rebinds; guard catches unbound | Job writes to the wrong database |
| **Rate limits** | Bucket key includes tenant id | One tenant exhausts another's quota |
| **Scheduled jobs** | Scheduler dispatches one job **per tenant**; never runs tenant work inline | One tenant's failure blocks all tenants |
| **Exports** | Generated into the tenant disk; download via signed, permission-checked URL | Cross-tenant export download |
| **Reports** | Built from the tenant connection only | Aggregated data leak |
| **Broadcasting** | Channel names prefixed `tenant.{uuid}.`; authorisation callback re-checks the bound tenant | Queue display shows another center's tickets |
| **Logs** | `tenant_id` + `request_id` in every structured log line | Unattributable incidents |
| **Search indexes** (future) | One index per tenant, or a mandatory tenant filter at the driver level | Cross-tenant search results |

## 6. Queued jobs

`stancl/tenancy` already restores tenant context on the worker for jobs
dispatched inside a tenant context. Meta Style relies on that rather than
rebuilding it, and adds two things on top:

- Jobs dispatched in **platform** mode stay in platform mode; the fail-closed
  guard (§4) guarantees they cannot silently touch tenant data.
- If a job's tenant is missing, suspended, or archived when it runs, it is
  discarded with a logged reason rather than retried to exhaustion.

Only the tenant **identifier** is ever serialised into a payload — never the
tenant model, connection config, or any tenant data (`DECISIONS.md` ADR-015).

> **Trap: `dispatch()` returns a `PendingDispatch` that queues the job in its
> destructor.** Returning it out of a tenant-context closure — which an arrow
> function does implicitly — means the job is actually queued *after* tenancy
> has ended, with no tenant attached, and it will fail or run centrally. Always
> dispatch as a statement inside the closure body.

Isolation tests run against the real `database` queue driver, not `sync`:
`sync` executes inline with the dispatching tenant still bound, so it cannot
demonstrate that a job restores its tenant (`DECISIONS.md` ADR-025).

Queue tags include the tenant id for triage.

## 7. Scheduled work

The scheduler never does tenant work directly.

```
schedule:run  →  DispatchForEachTenant(job, filter)  →  queue: N tenant jobs
```

`DispatchForEachTenant` iterates **active** tenants in chunks, optionally
filtered by entitlement (e.g. only tenants with `whatsapp_integration`), and
dispatches one `TenantAware` job each, spread over a window to avoid thundering
herds. One tenant's exception cannot affect another's.

## 8. Tenant lifecycle

### 8.1 States

| State | Meaning | DB exists | Login | Writes |
|---|---|---|---|---|
| `provisioning` | Being created | being built | no | no |
| `active` | Normal operation | yes | yes | yes |
| `suspended` | Non-payment or policy | yes | yes | **read-only** |
| `cancelled` | Terminated, in retention window | yes | yes | export only |
| `archived` | Dumped to cold storage, DB dropped | no | no | no |
| `failed` | Provisioning failed | partial | no | no |

Transitions are recorded in `tenant_operations` and audited in the control plane.

### 8.2 Provisioning

Idempotent, resumable, queued on the `provisioning` queue. Each step records
its outcome so a retry resumes rather than restarts.

```
1. Reserve tenants row           status=provisioning, allocate db_name
2. CREATE DATABASE               utf8mb4 / utf8mb4_unicode_ci
3. Run all tenant migrations     record schema_version
4. Seed system data              permission-role defaults, statuses, units,
                                 default templates, default locales
5. Create main branch            from registration input
6. Create owner user             owner role, first login credentials
7. Create storage prefix         tenants/{uuid}/
8. Register login directory      owner identifier hash
9. Create trial subscription     plan + trial entitlement set
10. Mark active                  status=active, provisioned_at
```

Failure at any step → `status=failed`, error recorded, operator alerted. A
partially provisioned tenant is never marked active. Rollback of steps 2–3 is a
`DROP DATABASE` guarded by a name-pattern check and an explicit confirmation.

Idempotency is not a nicety here — the retry path in §8.2.1 depends on it. Every
step above either finds what a previous attempt created or creates it: the
registration keeps its `tenant_id` from the first attempt, so a failure after
step 1 resumes against that same tenant rather than provisioning a second one.

### 8.2.1 Retrying a failed self-registration

Provisioning creates a database and runs migrations, so it fails for ordinary
operational reasons. A failed self-registration must stay recoverable, which
means the registration keeps the owner's **bootstrap credential** — the bcrypt
hash of the password they chose, encrypted at rest — for as long as a retry
could still work (ADR-031).

| Status | Retryable | Credential |
|---|---|---|
| `preparing` | — running | kept |
| `failed` | **yes**, until `credentials_expire_at` | kept |
| `ready` | no | destroyed |
| `cancelled` | no | destroyed |
| `abandoned` | no | destroyed |

The window is `METASTYLE_REGISTRATION_RETRY_WINDOW_HOURS`, default 24. Three
ways to retry, all one code path:

```bash
POST /api/v1/public/registrations/{uuid}/retry   # public, throttled as a submit
# the "try again" button on the registration status page
php artisan metastyle:registration:retry {uuid}  # support
```

The retry endpoint is unauthenticated because the person whose provisioning
failed has no account to sign in to — that is what failed. The uuid is the
capability: unguessable, already what the status endpoint accepts, and it grants
nothing beyond retrying that one registration. `Registration::isRetryable()`
requires the status to be `failed` **and** the window to still be open, so a
retry that could only fail is refused rather than queued.

`metastyle:registration:sweep` runs **hourly** and abandons what has expired,
destroying the credential. The scheduler must actually run in production; without
it the window has no effect (`12` §3.3).

What never happens, at any point: the plaintext is never persisted, the hash
never enters a queue payload, `tenant_operations`, an audit entry, a log line,
an exception message, or any API response.

### 8.3 Deprovisioning

`cancelled` → retention window (default 90 days, configurable per tenant) →
`archived`:

1. Export a full logical dump + storage archive to cold storage.
2. Verify the archive.
3. Drop the database; delete the storage prefix.
4. Retain the `tenants` row, the archive location, and the audit trail.

Hard deletion of the archive is a separate, manual, dual-approval operation.

## 9. Anti-patterns (rejected)

| Anti-pattern | Why rejected |
|---|---|
| Adding `public.tenant` to an authenticated route group | A public key in a URL would become a way to act as a center (ADR-036). |
| `tenant_id` column on every business table in one shared database | The user's explicit constraint; one forgotten scope leaks everything. |
| Reading tenant id from a request parameter or header | Trivially forgeable. |
| `tenant()` / `tenancy()` package helpers in application code | Couples every call site to the package; makes ADR-018 irreversible. Inject `TenantContext`. |
| Reimplementing the package's cache/queue/filesystem bootstrappers | Real work, already solved, easy to get subtly wrong. |
| Falling back to a "default tenant" when resolution fails | Turns a bug into a data leak. |
| Building storage paths by string concatenation in modules | Guarantees an unprefixed path eventually. |
| Different schemas per plan | Makes migrations combinatorial. |
| A separate database per module | No benefit, N× the operational cost. |
| Running tenant loops inside `schedule:run` | One slow tenant stalls the platform. |
| Trusting `config('database.connections.tenant.database')` to be set | Use `TenantContext::require()` and the connection guard. |
| Destroying the bootstrap credential the moment provisioning fails | Makes every failed self-registration permanently unrecoverable. Bound it with a window instead (ADR-031). |

## 10. Phase 2 acceptance criteria

Phase 2 is done when all of the following are true and tested:

1. `metastyle:tenant:provision` creates a working tenant end-to-end.
2. The fail-closed connection guard throws on an unbound tenant query.
3. Two tenants with identical data cannot see each other's rows, files, cache
   entries, or queued job results — proven by the `tests/TenantIsolation` suite.
4. A forged `tenant_id` parameter changes nothing.
5. A queued job dispatched under tenant A always executes under tenant A.
6. A tenant migration failure marks only that tenant as failed.
7. Deprovisioning drops exactly one database and one storage prefix.
