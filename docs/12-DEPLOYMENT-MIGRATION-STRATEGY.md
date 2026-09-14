# 12 — Deployment & Migration Strategy

> Status: **Partly implemented.** Local setup, CI and the migration commands are
> live; backup/restore tooling and observability arrive with production.

## 1. Environments

| Environment | Purpose | Tenants |
|---|---|---|
| **local** | Development | 2–3 seeded, plus one "large" fixture |
| **ci** | Automated tests | Created and dropped per run |
| **staging** | Pre-production verification | A production-shaped set: several small, one large, one on an old schema, one suspended, one mid-trial |
| **production** | Live | Real |

Staging **must** contain a deliberately awkward tenant set. A deploy that only
ever runs against three clean tenants will not surface the batch-migration and
drift problems that matter here.

## 2. Runtime topology

```
                    ┌──────────────┐
   clients ────────▶│ Load balancer│
                    └──────┬───────┘
                           │
        ┌──────────────────┼──────────────────┐
        ▼                  ▼                  ▼
   ┌─────────┐        ┌─────────┐       ┌──────────────────┐
   │ web 1..N│        │ web 1..N│       │ queue workers    │
   │ (PHP-FPM)        │         │       │ default          │
   └────┬────┘        └────┬────┘       │ notifications    │
        │                  │            │ media            │
        │                  │            │ exports          │
        │                  │            │ provisioning     │
        │                  │            │ migrations  ←────┼── isolated
        │                  │            └────────┬─────────┘
        └──────────┬───────┴─────────────────────┘
                   ▼                        ▼
            ┌────────────┐           ┌────────────┐
            │  MySQL 8   │           │  Redis 7   │
            │ control +  │           │ cache/queue│
            │ tenant DBs │           │ lock/rate  │
            └────────────┘           └────────────┘
                   │
                   ▼
            ┌────────────┐
            │ S3-compat  │
            │  storage   │
            └────────────┘
```

Plus one **scheduler** process (single instance, or leader-elected).

The `migrations` and `provisioning` queues run on dedicated workers so a slow
tenant migration cannot starve reminders, invoices, or notifications.

## 3. Release process

```
 1. Merge to main            → CI green (incl. tenant isolation gate)
 2. Tag release              → build artifact (composer install --no-dev, assets)
 3. Deploy to staging        → run steps 5–11 against staging
 4. Verify staging           → smoke tests + migration drift check
 5. Deploy code (production) → rolling; old and new both run briefly
 6. Readiness check          → metastyle:doctor --production    (must exit 0)
 7. Control migrations       → metastyle:control:migrate
 8. Tenant migrations        → metastyle:tenant:migrate --all
 9. System roles             → metastyle:roles:sync --all       (must exit 0)
10. Verify                   → metastyle:tenant:status --drift  (must exit 0)
11. Restart queue workers    → queue:restart (workers hold stale code otherwise)
12. Enable features          → flip entitlements / plan rows
```

**Step 5 before steps 7–8 is deliberate.** New code must work against the
*old* schema, because during step 8 many tenants are still on it. That is the
expand/contract rule from `03` §2.1, and it is what makes a rolling deploy safe
here.

**Step 6 runs after `config:cache`, before traffic.** It catches the
configuration that fails silently — a per-process rate limiter, a non-taggable
cache, an inline queue, `APP_DEBUG` left on. See §11.

**Step 9 is not optional and is easy to forget.** Owner is a set of explicit
grants, never a bypass (ADR-029), so a release that adds a permission leaves
every existing center's Owner role without it until this runs. The failure is
silent: no error, no log — the owner simply finds that a feature they pay for
does nothing. `metastyle:roles:sync --all` is idempotent, isolates per-tenant
failures, never touches custom roles or role assignments, and exits non-zero if
any tenant failed (ADR-032).

If step 10 reports drift, the release is **not complete**. The next release's
contract migrations cannot ship until it is clean.

### 3.3 Scheduled work the deployment must actually run

The scheduler is not decorative. Two entries have a data-retention consequence:

| Command | Cadence | If it does not run |
|---|---|---|
| `metastyle:registration:sweep` | hourly | Failed registrations keep an encrypted bootstrap password hash past its retry window (ADR-031). |
| `metastyle:idempotency:sweep` | hourly | Expired idempotency keys are never deleted. Each one holds the RESPONSE BODY it replays, so the table grows by a row per booking forever and keeps stored responses well past their 24-hour life. Correctness is unaffected — an expired row is also reclaimed by the next request that reuses its key. |
| trial expiry | hourly | Stored subscription status drifts. Enforcement is unaffected — expiry is computed on read. |

Both sweeps walk every center. They attempt each one independently and step over
the ones that fail, so a single unreachable tenant database cannot leave every
center after it unswept — but they exit non-zero when that happens, because a
pass that silently skipped part of the platform is not a success. **Alert on the
exit code, not only on the schedule firing.**

#### Phase 7 release note — the Employee role narrows

`SystemRole::Employee` held `appointment.view`, which is the whole branch's book.
Phase 7 replaces it with `appointment.view_own` plus the journey grants, because
the scope to say "their own" now exists.

`Role::syncPermissions()` REMOVES codes no longer granted, so
`metastyle:roles:sync --all` **revokes branch-wide appointment visibility from
every existing Employee-role user.** That is the intended product change and it
is visible on the next login — announce it, and tell any center that wants the
old behaviour to grant `appointment.view` through the role editor, which is where
that decision belongs.

#### Phase 8 release note — walk-ins relax two NOT NULL columns

`service_journeys.appointment_id` and `journey_stages.appointment_item_id` become
NULLABLE. That is a WIDENING: every existing row stays valid, and it is why this
is an expand-phase migration rather than one needing a release in between
(ADR-051).

The migration drops and re-adds each foreign key around the change, because
MariaDB will not modify a column while a constraint references it. The unique
indexes survive and are what keep check-in idempotent for booked visits.

Verify after deploy:

```bash
php artisan metastyle:tenant:status --drift
```

Nothing else about Phase 8 is destructive: four new tables, one new column on
`departments`, and six new permission codes.

#### Phase 9 release note — expand only

Ten new tenant tables (`products`, `cashier_shifts`, `sales`, `sale_items`,
`sale_item_addons`, `sale_adjustments`, `invoice_sequences`, `invoices`,
`invoice_items`, `invoice_share_links`) and one nullable, unique column,
`branches.invoice_prefix`. No existing column changes, nothing is backfilled.
`invoice_share_links` stores only `token_hash` (SHA-256), and invoice numbering
resets on `sequence_year`; both were settled before release (ADR-057), so no
shipped schema is renamed. A local tenant migrated from a pre-release Phase 9
build must be re-provisioned or its Phase 9 tables rebuilt.

Before go-live at a multi-branch center, set an invoice prefix for every branch
except the main one (ADR-055). Run `metastyle:roles:sync --all`.

### 3.1 Rollback

| Scenario | Action |
|---|---|
| Bad code, schema unchanged | Redeploy the previous artifact. Fast, safe. |
| Bad code after an **expand** migration | Redeploy previous code. Expand migrations are backward compatible by construction — this is the entire reason for the rule. |
| Bad **contract** migration | Not rollback-able by redeploy. Restore affected tenants from backup. Assume this and treat contract migrations as one-way. |

Because contract migrations are one-way, they ship **alone**, in a release with
no other changes, so a failure has one obvious cause.

### 3.2 Maintenance mode

Per-tenant, not global. A tenant being migrated shows a maintenance response;
every other tenant keeps trading. Global maintenance is reserved for control-plane
or infrastructure work and is announced in advance.

## 4. Zero-downtime schema changes

Restated because it is the operational heart of this architecture:

| Do | Don't |
|---|---|
| Add nullable columns | Add NOT NULL without a default |
| Add new tables | Rename tables or columns |
| Backfill in a queued, chunked, resumable job | Backfill inside a migration |
| Dual-write during the transition | Change a column type in place |
| Drop old columns one release later | Drop anything current code reads |
| One logical change per migration file | Combine unrelated DDL |

MySQL DDL is not transactional (`03` §2.2): a failed migration leaves partial
state, so migrations check existence before acting and are safe to re-run.

## 5. Provisioning at scale

Provisioning is idempotent, lock-protected and resumable (`02` §8.2), and runs
**synchronously** today — invoked from a console command, and from Phase 3 from
self-registration.

Requirements that arrive with self-registration, when provisioning moves onto a
queue:

- Rate-limited: a marketing campaign producing 200 signups in an hour must not
  create 200 databases simultaneously.
- Monitored: provisioning duration and failure rate as dashboard metrics.
- Alerted: any tenant left in `failed` pages an operator.

The pieces that make that move safe already exist — per-tenant locks, a
`tenant_operations` record per attempt, and a `--retry` path — so it is a change
of caller, not of design.

## 6. Backups and restore

Database-per-tenant makes this genuinely better than the alternative — provided
the tooling exists.

| Backup | Frequency | Retention |
|---|---|---|
| Full MySQL instance snapshot | Daily | 30 days |
| Per-tenant logical dump (`mysqldump` per database) | Daily | 14 days |
| Control plane logical dump | Every 6 hours | 30 days |
| Binlog / PITR | Continuous | 7 days |
| Object storage | Versioning + lifecycle | Per `09` §9 |

**Per-tenant restore is the capability that matters.** "Center X deleted their
service catalog, restore yesterday's data for them only" is a routine support
request in this product and must not require restoring the whole instance.

Requirements:

1. A `metastyle:tenant:restore --tenant= --at=` command that restores one
   tenant database to a point in time, into a **staging database name first**,
   for verification before swap.
2. **Rehearsed monthly.** An unrehearsed restore procedure does not exist.
3. Restores are audited in `tenant_operations` and the platform audit log.

## 7. Scaling path

Deliberately staged. Do the next step when a measurement demands it, not before.

| Stage | Trigger | Action |
|---|---|---|
| 1 | Launch | One MySQL instance, all tenant databases. Vertical headroom. |
| 2 | Connection or table-cache pressure | Tune `max_connections`, `table_open_cache`, `open_files_limit`. Persistent connections reviewed carefully — every request connects to a *different* database. |
| 3 | Report queries affecting operations | Read replica; reports and analytics target it. |
| 4 | One instance insufficient | Shard by instance using `tenants.db_host` — already in the schema (`02` §4.2), so this is a data move, not a migration. |
| 5 | A single tenant is disproportionately large | Move that tenant to a dedicated instance. Same mechanism. |

Stage 4 is the payoff for putting `db_host` in the schema in Phase 2. Adding it
later would mean touching every tenant record and the connection resolver under
production load.

**Known limit to watch:** each tenant database holds on the order of 60–100
tables. At 2,000 tenants that is up to 200,000 tables in one instance —
`table_open_cache` and `open_files_limit` become the binding constraint well
before disk does. This should be load-tested during Phase 2, not discovered at
tenant 800.

## 8. Observability

| Signal | Tool | Alert on |
|---|---|---|
| Structured logs (`request_id`, `tenant_id`, `principal`) | JSON to a log aggregator | Error rate per tenant |
| Queues | `queue:monitor` + metrics; Horizon when justified (ADR-021) | Depth, wait time, failure rate per queue |
| Migration state | `metastyle:tenant:status` + dashboard | Any `failed`; any `running` past lock TTL |
| Provisioning | `tenant_operations` | Failure, or duration over threshold |
| Tenant health | Control plane | Last activity, DB size, storage usage, error rate |
| MySQL | Instance metrics | Connections, slow queries, open tables, replication lag |
| Redis | Instance metrics | Memory, evictions (evictions on the lock or quota keyspace are a correctness issue, not a performance one) |
| Business | Dashboard | Bookings/hour, failed payments, WhatsApp delivery failures |
| Security | Alerts | Tenant-resolution conflicts, audit chain failures, brute force, entitlement bypass attempts |

Every log line and every job carries `tenant_id`. Diagnosing a tenant-specific
problem without it is guesswork.

## 9. Configuration

- Environment variables for infrastructure. Config caching in production
  (`config:cache`), so nothing may call `env()` outside `config/`.
- Platform behaviour (trial length, default plan, rate limits) is **control-plane
  data**, editable in SADMIN — not environment variables. Changing a trial
  length must not require a deploy.
- Tenant behaviour is tenant `settings` rows.
- Secrets from a secret manager in production; `.env` only in local.

## 10. Local development

**A normal local PHP + MySQL/MariaDB install is a fully supported, first-class
setup.** Docker is not required.

Minimum to contribute:

| Requirement | Notes |
|---|---|
| PHP `^8.2` with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`, `xml` | XAMPP, Laragon, Herd, or a system PHP all work |
| MySQL 8 **or** MariaDB 10.4+ | Must be running; see the engine caveat below |
| Composer 2 | |
| Node | Only once frontend assets exist |

```bash
composer install
cp .env.example .env && php artisan key:generate
# create the control database, then:
php artisan metastyle:control:migrate
composer check
```

Not required: Redis, MinIO, Mailpit, Docker, Horizon.

Driver defaults are chosen so the application runs and the full isolation suite
passes with only a database installed (ADR-025):

| | Local / test | Production |
|---|---|---|
| Cache | `array` — **must be taggable**; tenant isolation works by tagging | `redis` |
| Queue | `database` (`jobs` table in the control database) | `redis` |
| Session | `file` | `redis` |

`file` and `database` cache stores do **not** support tags and are not a
supported configuration: they would leave every tenant sharing one keyspace.

**Docker Compose is optional** and provided only as a reproducible environment
for contributors who want MySQL 8 and Redis without installing them. It is never
the only supported path.

**Engine caveat:** local MariaDB is fine for day-to-day work, but CI and
production run **MySQL 8**. MariaDB's JSON and functional-index behaviour differs
(`11-TESTING-STRATEGY.md` §2), so a locally green suite is not proof for anything
touching JSON columns. CI is the authority.

**Known local environment (2026-09-02):** PHP 8.2.12 and MariaDB 10.4.32 via
XAMPP. Phase 2 runs clean on it — `AUTO_INCREMENT` on a unique non-primary
column, `json` columns, fractional-second timestamps and cascading foreign keys
all behave.

MariaDB 10.4 is past end-of-life and predates several MySQL 8 JSON features. It
remains adequate. Phase 4 introduces translatable JSON columns, but **not**
JSON functional indexes: ADR-033 keeps the schema portable until a measured
query justifies an engine-specific index, so the divergence stays theoretical.
Upgrading is still worthwhile; CI on MySQL 8 is the authority in the meantime,
and `tests/Architecture/DatabasePortabilityTest.php` catches the known
divergences before the push.

## 11. Production readiness

Some misconfigurations do not fail. The application serves traffic, the suite is
green, and something is quietly not doing its job. Those are the ones this
section exists for, and `metastyle:doctor` is how they are caught.

```bash
php artisan metastyle:doctor --production
```

| Check | Failure in production | Why it is silent |
|---|---|---|
| Rate limit backend | `array` (per process) or `file` (per server) | The limiter still returns 200s and 429s. A "5/min" login limit simply becomes 5 × workers per minute. Nothing logs it (ADR-034). |
| Cache tags | `file` or `database` | Tenant cache isolation is tag-based. Without tags every center shares one keyspace (ADR-025). |
| Queue driver | `sync` or `null` | `sync` runs provisioning inside the registration request; `null` accepts registrations and never provisions them. |
| Middleware order | Authentication ahead of tenant resolution | Tokens would be looked up with no tenant bound (ADR-027). Also asserted **fatally at boot** — this is the one condition the application refuses to start with. |
| Debug mode | `APP_DEBUG=true` | Stack traces expose database names, credentials and tenant ids to anyone who can trigger an error. |
| Application key | empty `APP_KEY` | The encrypted bootstrap credential (ADR-031) can neither be written nor read. |
| Session driver | `array` (fails), `file` (warns) | `array` discards the session, so nobody stays signed in; `file` breaks the moment there are two app servers, and on web routes the session is what names the tenant (ADR-030). |

Warnings do not fail the command. Failures exit non-zero, so the deploy stops.

In production the same checks run at boot and log each failure at `critical`.
Logged, not thrown: these are conditions a running site survives, and refusing
to boot would turn a degraded deployment into an outage. Middleware ordering is
the exception and does throw — a pipeline that authenticates before it resolves
the tenant is not a degraded mode.

**Redis in practice.** Combining the first two rows leaves Redis as the only
store that satisfies both: `database` is shared but cannot do tags; `array` can
do tags but is per-process. Local and test runs still require **no** Redis
(ADR-025) — that split is deliberate and is what these checks protect.

## 12. Anti-patterns

| Anti-pattern | Why |
|---|---|
| `php artisan migrate` in the deploy script | Wrong migration set, wrong connection. Use the `metastyle:` commands. |
| Running tenant migrations synchronously in the deploy | One slow tenant blocks the release; a timeout leaves unknown state. |
| Deploying code and schema that require each other | Impossible to roll back; breaks during the migration window. |
| Global maintenance mode for a tenant migration | Every other center stops trading. |
| No per-tenant restore | The main operational benefit of this architecture goes unused. |
| An unrehearsed restore procedure | It does not work. It has never worked. |
| Contract migrations bundled with features | A failure has many possible causes. |
| Forgetting `queue:restart` | Workers run old code against a new schema. |
| Adding `db_host` "later" | Requires touching every tenant under load. |
| Logs without `tenant_id` | Tenant-specific incidents become unsolvable. |
| Trial length as an env var | Business configuration should not need a deploy. |
| Skipping `metastyle:roles:sync` on a release that added a permission | Existing owners silently lack it. Nothing errors. |
| Shipping Phase 7 without reading the role note in §3.3 | Employee-role users lose branch-wide appointment visibility on sync. Intended, and worth announcing. |
| Shipping Phase 8 without `metastyle:roles:sync --all` | Six new queue permissions exist and nobody holds them. The queue screen is simply empty. |
| Shipping Phase 9 without `metastyle:roles:sync --all` | Nine new sales permissions exist and nobody holds them. The till and the sales list are unreachable. |
| A second branch issuing its first invoice | Refused until a manager sets `invoice_prefix` for that branch — deliberate (ADR-055). Set prefixes for every non-main branch before go-live. |
| Expecting a WebSocket server | There is none. The queue display POLLS, deliberately (ADR-052). Nothing new to run, nothing new to supervise. |
| Shipping without `metastyle:doctor` | Every row in §11 fails without saying so. |
| No scheduler in production | Bootstrap credentials outlive their retry window, and stored idempotency responses are never deleted. |
| Treating a non-zero sweep exit as noise | It means centers were skipped. The schedule fired; the work did not happen. |
