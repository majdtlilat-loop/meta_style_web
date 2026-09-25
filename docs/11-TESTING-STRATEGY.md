# 11 — Testing Strategy

> Status: **Implemented.** Harness and architecture tests since Phase 1; the
> tenant isolation suite covers all 14 cases as of Phase 2, extended in Phase 3
> with authentication and token-binding isolation.

## 1. What we optimise for

Correctness first, and specifically **tenant isolation**, **money**, and
**booking rules**. Those three are where a bug is unrecoverable: leaked data
cannot be un-leaked, a wrong charge damages trust, a double booking is a
customer standing in a shop.

Everything else is a normal bug.

Coverage percentage is a diagnostic, not a target. A suite that proves
isolation and money at 65% coverage beats one at 90% that doesn't.

## 2. Test types

| Type | Location | Database | Speed | What it proves |
|---|---|---|---|---|
| **Unit** | `tests/Unit` | none | ms | Pure logic: availability maths, money, policy calculation, entitlement resolution |
| **Feature** | `tests/Feature` | real MySQL | ~100ms | Endpoint → action → database → response |
| **Architecture** | `tests/Architecture` | none | ms | Module boundaries and layering hold |
| **Tenant Isolation** | `tests/TenantIsolation` | 2+ tenant DBs | slow | **Release gate.** No cross-tenant access on any dimension |
| **Migration** | `tests/Migration` | real MySQL | slow | Fresh build + upgrade path both work |
| **Contract** | `tests/Contract` | real MySQL | fast | Responses match the OpenAPI spec |

Tooling: **Pest 3** on PHPUnit, against a **real MySQL-compatible server** —
never SQLite. SQLite does not have MySQL's JSON functions, generated columns,
foreign key behaviour, or collation, and this system depends on all four. A test
suite that passes on SQLite and fails in production is worse than no test suite.

Redis is used in tests only where a test is about Redis. Nothing in the suite
requires a Redis server to be running before Phase 2.

**Engine note:** local development may use MySQL 8 or MariaDB. CI and production
target **MySQL 8**, and CI is the authority — MariaDB's JSON handling and
functional-index support differ enough that a locally green suite is not proof.
Anything touching JSON columns or generated columns (`07-LOCALIZATION.md` §3)
must be verified on MySQL 8 in CI before it is considered working.

## 3. The multi-database problem

`RefreshDatabase` assumes one database. We have one control database and N
tenant databases created at runtime. This is the hardest part of testing this
architecture and needs to be solved **in Phase 1**, before there is code that
depends on it.

### 3.1 Approach: provision real tenants

Tests run the **real provisioning pipeline**, so a regression in provisioning
fails the isolation suite too rather than hiding behind a fixture:

```
Suite start
  ├─ DROP + CREATE the test control database   (a crashed run can leave tables
  │                                             present but migration history
  │                                             gone; recreating avoids a
  │                                             confusing "already exists")
  ├─ sweep orphan tenant databases by prefix
  └─ metastyle:control:migrate

Per test
  ├─ truncate control tables
  └─ provisionTenant() → real database, real migrations, real seed

Per test end
  └─ DROP the databases that test created
```

Every database the suite touches is prefixed — `phpunit.xml` overrides
`METASTYLE_TENANT_DB_PREFIX` — and `TestDatabaseManager` **refuses** to create
or drop anything without that prefix. A mistyped `DROP DATABASE` against a
developer's real tenant data is unrecoverable, so the guard is not optional.

**Why not a cached schema dump.** The Phase 0 design proposed dumping the tenant
schema once and importing it per test. With two tenant tables, provisioning
takes milliseconds and the dump machinery would be pure overhead — and it would
stop exercising the pipeline that matters most. Revisit when the tenant schema
is large enough for the suite to feel it; the seam is `provisionTenant()`.

### 3.2 Test helpers

```php
$alpha = $this->provisionTenant('Barbershop Alpha', ['alpha.metastyle.test']);
$beta  = $this->provisionTenant('Laser Center Beta');

$this->asTenant($alpha, fn () => writeSetting('center_label', 'ALPHA'));

// asTenant() goes through the real TenantContext, including its
// exception-safe teardown.
expect($this->asTenant($beta, fn () => readSetting('center_label')))->toBeNull();
```

**There is no ambient default tenant in tests.** A test that forgets to bind
one hits the fail-closed connection guard and errors loudly. This mirrors
production and is the point.

## 4. Tenant isolation suite (mandatory)

`tests/TenantIsolation` is a **release gate**: a red suite blocks deploy, no
exceptions, no `--filter` around it.

Every one of these must exist and pass:

| # | Test | Covered by |
|---|---|---|
| 1 | Tenant A queries return only A's data. | `TenantDatabaseIsolationTest` |
| 2 | Tenant B queries return only B's data. | `TenantDatabaseIsolationTest` |
| 3 | Unbound tenant access fails closed and never reads the control database. | `FailsClosedTest` |
| 4 | Ending A's context does not leak A into B. | `TenantDatabaseIsolationTest` |
| 5 | Sequential tenant switching resets the connection every time. | `TenantDatabaseIsolationTest` |
| 6 | Tenant A's storage namespace differs from B's, and neither can read the other's path. | `TenantStorageAndCacheIsolationTest` |
| 7 | The same logical cache key under A and B does not collide. | `TenantStorageAndCacheIsolationTest` |
| 8 | A tenant-aware job executes as the tenant that dispatched it. | `TenantQueueIsolationTest` |
| 9 | Queue context is reset after the worker finishes. | `TenantQueueIsolationTest` |
| 10 | An invalid or unresolvable tenant fails; a client-supplied tenant id is ignored. | `TenantResolutionTest` |
| 11 | Conflicting trusted resolution sources fail and are audited. | `TenantResolutionTest` |
| 12 | A tenant migration applies to the selected tenant only. | `TenantMigrationTest` |
| 13 | A failed tenant migration does not mark unrelated tenants failed. | `TenantMigrationTest` |
| 14 | A provisioning failure is observable and retryable, and never marks the tenant ready. | `TenantProvisioningTest` |

Added in Phase 3 (`AuthenticationIsolationTest`): a Tenant A token is rejected
under Tenant B; a bare token with no center prefix resolves no tenant; a
host/token conflict is refused and audited; a deleted token row and a
deactivated user both lose access immediately; authentication state does not
survive a context switch; login rate limits are keyed per center as well as per
address.

Added in Phase 3.1 (`MiddlewareOrderTest`): the credential lookup runs on the
**tenant** connection with the expected tenant already bound; a route without
the `tenant` middleware cannot authenticate at all; a host/token and a
host/session conflict are both refused *before* any authentication attempt; the
tenant context is cleared after the request, including when it failed inside the
pipeline; and the boot-time ordering guard rejects a misordered, an
unprioritised, and an unanchored priority list.

Added in Phase 4 (`CatalogIsolationTest`): services, departments, categories,
variations, add-ons, internal notes and menu versions are all per-center; one
center's media rows and stored files are unreachable from another and resolve to
different storage roots; one center's catalog never appears on another's public
menu; and a guest menu request leaves no tenant bound.

Added in Phase 5 (`CustomerIsolationTest`): the same real person exists
independently in two centers with the same phone number and neither may see the
other; customers, accounts, tags and notes are all per-center; a Tenant A
customer token is inert under Tenant B; a customer token cannot reach a staff
endpoint and a staff token cannot reach a customer one; a deactivated or revoked
customer token is refused; and no tenant remains bound after a customer request.

Added in Phase 6 (`BookingIsolationTest`): two centers' appointments and items
stay separate; one center's bookings never block another's availability — proved
with two centers whose employees share an id, which is exactly the shape that
breaks if a conflict query escapes its connection; an appointment query with no
tenant bound fails closed; no tenant remains bound after a booking request; the
same idempotency key works independently at both centers; and neither an
appointment nor public availability is reachable through the other center's key.

Added in Phase 7 (`ResourceAndJourneyIsolationTest`): two centers' resources stay
apart when their ids collide; one center's booking never consumes another's room
capacity; journeys and stages stay inside one center; a journey or resource query
with no tenant bound fails closed; no tenant remains bound after operational
work; and every Phase 7 table exists in the center's own database with no
redundant `tenant_id` column.

Added in Phase 8 (`QueueIsolationTest`): two centers keep their tickets apart
when their ids and their NUMBERS collide; one center sequence never touches
another; service points, displays and ticket history stay inside one center; a
queue query with no tenant bound fails closed; no tenant remains bound after
queue work; and every queue table exists in the center own database with no
`tenant_id`.

> A cross-tenant test must pass DISTINCT names and emails to `registerCenter()`.
> It is idempotent on its arguments, so calling it twice with the defaults
> returns the SAME center — a test that did that would be asserting nothing
> about isolation while appearing to pass. One Phase 7 test had this defect and
> was corrected in Phase 8.

Added in Phase 9 (`SalesIsolationTest`): sales and invoices stay apart when their
ids and invoice NUMBERS collide; an invoice share token never resolves inside
another center; numbering is independent per center; shifts and products stay
inside one center; sales queries fail closed with no tenant; and no sales table
carries `tenant_id` — nor does any operational table carry a financial column.

Phase 9 financial-consistency tests (ADR-057): a visit's sale cannot be finalized
until the visit completes, and then carries every performed service
(`JourneyCheckoutTest`); every tenant table, the platform audit, the job tables
and every log line are searched for the plaintext of a revoked and a live invoice
link (`PublicInvoiceTest`); history stays readable and new operations are refused
after POS is withdrawn (`SalesDowngradeTest`); and the audit insert is made to
fail mid-finalization, mid-void and mid-discard, proving the financial change
rolls back with it (`SalesAuditAtomicityTest` — the failure is injected on the
`eloquent.creating` event of the audit model, where a real insert failure
surfaces).

**A negated multi-needle `toContain` proves nothing.** Pest runs
`toContain('a', 'b')` and the negation passes as soon as it fails — which it
does when EITHER needle is missing. `not->toContain($secret, 'message')` is worse:
the message is a second needle, never present, so the assertion can never fail.
Phase 9 found eight such assertions — in Booking availability and role tests, the
Phase 8 plan-matrix check and two Phase 9 tests — and split them into one needle
per negation. `SafeguardsTest` now tokenizes every test file and refuses a
negated `toContain`/`toContainEqual` with more than one argument, with a
companion test proving the detector fires.

Two older tests also asserted that the `sales` and `invoices` TABLES do not exist
after a visit or appointment completes. Phase 9 created those tables; one test
began failing and the other — a multi-needle negation — kept passing without
checking anything. Both now pin that the center owns `pos` and assert that
completion wrote no row to the Sales tables.

Phase 9 concurrency tests run the REAL finalization and shift Actions against a
lock held by a genuinely separate MySQL connection, with a one-second lock
timeout on the Action's own session. "It waited, rolled back and consumed no
number" is observable that way; a single PHP process cannot race itself
(docs/18-SALES.md §§47–49).

Added in Phase 10 (`PaymentsIsolationTest`, `FinanceIsolationTest`): two centers
holding the SAME provider payment reference and colliding row ids — a signed
callback settles only the center it names; one center's gateway account uuid under
another center's key is refused and changes neither; an invoice link does not
resolve for payment in another center; ledgers, expenses, drawer counts and
dashboards stay apart when ids collide; payments and finance queries fail closed;
no payments or finance table carries `tenant_id` or exists in the control database.

Added in Phase 11 (`BenefitsIsolationTest`): the same phone number at two
centers never shares a points balance; one center's package or membership uuid is
unknown at another and changes nothing there; benefit queries fail closed; no
benefit table carries `tenant_id`.

Phase 11 benefit tests add two patterns of their own:

- **Failure injection on the benefit side, never the money side.** A model
  `creating` listener makes the loyalty, membership or package store fail; the
  test asserts the payment or refund is committed and reported as succeeded,
  `AfterCommitFailed` was reported with its label, and running the reconciler
  twice repairs it exactly once (`LoyaltyReconciliationTest`,
  `MembershipActivationTest`, `PackageActivationTest`).
- **Time travel past the trial.** Expiry and term tests move weeks ahead; the
  seeded trial lasts 21 days, after which the center is read-only and every
  Action refuses for an unrelated reason. `SeedsBenefits::outlastTrial()` extends
  it explicitly.
- **Event-time replay** (`LoyaltyEventTimeTest`): the rules are changed, or the
  entitlement granted and revoked, BETWEEN the event and its repair — so a test
  that only checked "the points arrived" would pass while the customer got the
  wrong number. These assert the amount, the date and the expiry. Two of them
  also DELETE every `loyalty_earning_observations` row first, because a recovery
  that depended on the evidence it is recovering from would look identical until
  the day it was needed.
- **Guard state between requests** (`NotificationSurfaceTest`): one application
  instance serves every request in a test and the auth guard caches whoever it
  resolved last, so a second request with a different token can be answered as
  the FIRST user. The negative check runs first, and
  `$this->app['auth']->forgetGuards()` separates the two — the test is refreshed,
  never relaxed.
- **A performer on a walk-in stage** (`SeedsReviews::customerVisit`): a walk-in
  reserves nobody, so its stages start with no employee. An employee rating is
  impossible without assigning one, which is the rule rather than a fixture
  detail (docs/22-REVIEWS.md §11).

A harness caveat found here: within ONE test, a later request reuses the user a
guard already resolved for an earlier request in the same application instance.
A staff-token-on-customer-route assertion must therefore run before any customer
request in that test (`LoyaltySurfaceTest`) — production handles each request in
a fresh application.

Phase 10 money tests follow the Phase 9 patterns and add three:

- **Concurrency** (`PaymentConcurrencyTest`) holds the sale, payment or shift lock
  on a separate MySQL connection: a second desk collecting the last balance waits
  and is then refused; a second refund waits and cannot exceed the payment; cash
  waits behind a counted close.
- **A test-only provider** (`tests/Support/FakeGatewayProvider`, never registered
  outside tests) exercises what no verified adapter reaches yet: HMAC-signed
  callbacks, provider refunds, scripted declines and outages. FIB is covered by
  contract tests built from its documented request and response examples
  (`FibProviderTest`, `Http::fake`) — which prove the adapter matches the
  documentation, not that FIB's sandbox behaves as documented.
- **Atomicity by injection**: the ledger insert is made to fail on the
  `eloquent.creating` event of `FinanceEntry`, proving the payment rolls back with
  it (`FinanceLedgerTest`); credentials encrypted under a foreign key prove every
  surface fails closed after a key rotation (`GatewayAccountTest`).

**Finance windows are branch-local days — so are the tests'.** Phase 10's first
dashboard tests built "today" with `now()->format('Y-m-d')`, the UTC date. They
passed all afternoon and failed after 21:00 UTC, when it is already tomorrow in
Baghdad and the window missed everything just recorded. Tests now use
`SeedsPayments::branchToday()`.

Still to add, with the phases that make them reachable: broadcast channel
authorisation (whenever realtime ships — ADR-052 chose polling for now), export
download scoping (Phase 13), deprovisioning leaving other tenants untouched (when
archival ships).

**A third harness caveat, and the bug it hid.** `Livewire::test()` does not go
through `/livewire/update`, and it binds the tenant itself. That is convenient
and it concealed a real defect for three phases: Livewire component actions POST
to an endpoint carrying only the `web` group, and `ResolveTenant` was not
registered as persistent middleware, so every Livewire action in the center area
ran with no tenant bound (ADR-045). A component test cannot catch that class of
bug — only a structural assertion about what Livewire will actually replay, which
is what `MiddlewareOrderGuard::assertLivewirePersists()` now makes fatal at boot.

**Concurrency, as realistically as one process allows.** A PHP test is
single-threaded, so `BookingConcurrencyTest` proves the two properties that
matter separately: sequential requests for one slot produce exactly one booking
(the in-transaction re-check), and a genuine SECOND database connection with a
one-second lock timeout cannot cross the lock while the first holds it (the
serialisation). Without the second, a sequential test would pass against a lock
that did nothing.

**A second harness caveat.** The test client keeps ONE application instance
across requests in a test, so a guard that resolved a user on the first request
still holds it on the second. A test asserting that a token stops working —
revoked, deactivated, forged — must call `$this->app['auth']->forgetGuards()`
between the two requests, or it passes against a cached user and proves nothing.

**One harness caveat worth knowing.** `queue:work` stops itself once the PHP
process exceeds `--memory` (128 MB by default). By the time a full suite run
reaches the queue tests the process is well past that, so the worker exits after
one job and the second sits unprocessed — a test-environment artifact that looks
exactly like a queue bug. The queue tests pass `--memory=4096`.

New modules add rows to this table. This is the checklist that must grow.

## 5. Architecture tests

Executable versions of `04-MODULE-BOUNDARIES.md` and ADR-018. Implemented:

```php
arch('the kernel never depends on business modules')
    ->expect('App\Kernel')->not->toUse('App\Modules');

arch('stancl/tenancy stays behind the Meta Style tenancy adapter')
    ->expect('Stancl')->toOnlyBeUsedIn([
        'App\Kernel\Tenancy\Infrastructure',
        'App\Providers\TenancyServiceProvider',
    ]);

arch('the tenancy contracts do not leak the package into their signatures')
    ->expect('App\Kernel\Tenancy\Contracts')->not->toUse('Stancl');

arch('storage and audit kernels depend on tenancy only through its contracts')
    ->expect(['App\Kernel\Storage', 'App\Kernel\Audit'])
    ->not->toUse('App\Kernel\Tenancy\Infrastructure');

arch('env() is only read in config, so config caching is safe')
    ->expect('env')->not->toBeUsed();

arch('no debugging leftovers reach the repository')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])->not->toBeUsed();
```

The `Stancl` rule is what keeps ADR-018 true. Without it the wrapper decays into
"unwrapped" within a few sprints and nothing announces it.

Module-to-module rules (`toOnlyUse(allowedDependencies())`, controllers not
touching models, framework-free domain services) are added with the first
modules in Phase 4 — an arch test over an empty namespace passes for the wrong
reason.

**Prefer an architecture test or a static-analysis rule over a grep.** Greps are
brittle: they fire on comments, strings and unrelated matches, and they are
routinely silenced rather than fixed. A safeguard is only worth adding when it
targets a mistake this architecture is specifically designed to prevent, and
when it can express that mistake precisely.

Four rules justify the exception because no type-level rule can express them:

- **Plan-name comparison** in business code (`05` §1) — the failure is a string
  literal compared against a plan code, invisible to static analysis.
- **Client-supplied tenant identifier** used as an authorization boundary
  (`02` §2.1) — reading `tenant_id` from request input.
- **Internal tenant sequence** in an outward payload. Phase 8 added a second,
  unrelated `sequence` — the per-ticket history counter — so the scan allows
  `app/Modules/Queue/` and a companion rule proves that module never touches
  `TenantModel`. A word-level scan cannot tell two identically spelled concepts
  apart; a pair of rules can.
- **Translation group colliding with a dotless translation key** (Phase 8).

All are focused source scans with explicit allow-lists, live in the test suite
(not a shell script), and report file and line.

### The translation collision rule

Laravel's `parseKey()` treats a key with no dot as a **group**: `__('Queue')`
asks for the whole of `lang/<locale>/Queue.php`. On Linux that file does not
exist, the key returns unchanged, and the label renders correctly by accident.
On Windows and macOS the same lookup finds `lang/en/queue.php`, returns the
ARRAY, and the page fatals in `htmlspecialchars()`.

A bug whose existence depends on the developer's filesystem is the worst kind:
**green in CI, broken on half the team's machines.** Phase 8 shipped
`lang/*/queue.php` next to a `__('Queue')` nav link and hit exactly this.

The guard collects every group basename under `lang/*/*.php`, collects every
dotless literal passed to `__()`, `trans()`, `trans_choice()` or `@lang()` in
`app/`, `routes/` and `resources/views/`, and compares them **case-insensitively**
— because that is the comparison the filesystem makes. It names both sides, with
file and line.

Deliberately narrow: dotless literals stay legal, groups stay legal, only the
collision fails. The fix is to rename the GROUP after its surface
(`queue.php` → `queue_public.php`), never to rename the literal, and never to
ban `__('Word')` across the codebase.

## 6. Migration tests

1. **Fresh build** — `database/migrations/tenant/` migrates cleanly from empty.
2. **Upgrade path** — check out the previous release tag, migrate, seed, then
   migrate to `HEAD`. Catches migrations that only work on an empty schema.
3. **Expand/contract lint** — fails a PR whose tenant migration drops a column,
   renames a column, changes a type, or adds NOT NULL without a default, unless
   the PR body carries an explicit `CONTRACT-MIGRATION:` marker (`03` §9).
4. **Timestamp ordering** — fails if a new migration's timestamp precedes one
   already on `main`.
5. **Seeder idempotency** — running tenant system seeders twice changes nothing.

## 7. What gets tested where

| Concern | Test type | Notes |
|---|---|---|
| Availability computation | Unit | Table-driven: overlaps, buffers, breaks, holidays, DST, multi-timezone branches |
| Booking rules and policies | Unit + Feature | Every rule has a passing and a failing case |
| Double-booking under concurrency | Feature | Parallel requests for the same slot → exactly one succeeds |
| Money arithmetic and rounding | Unit | Property-based where practical; IQD (exponent 0) and USD (exponent 2) both |
| Invoice totals, taxes, discounts | Unit + Feature | Golden-file totals |
| Invoice immutability | Feature | Change a service price; last month's invoice is unchanged |
| Invoice numbering | Feature | Concurrent checkout → gapless, unique per branch |
| Entitlement resolution | Unit | Matrix: plan × addon × override × dependency × status |
| Entitlement enforcement | Feature | Every gated endpoint returns `403` without the entitlement |
| Entitlement enforcement off-HTTP | Feature | Job/command/bot path is gated too (`05` §6.2) |
| Permissions | Feature | Policy matrix: role × action × branch scope |
| Field masking | Feature | API **and** export paths both masked |
| Localization fallback | Unit | Missing translation never renders empty |
| RTL rendering | Manual + snapshot | Browser, PDF, and 80mm receipt separately (`07` §6) |
| Idempotency | Feature | Replay, conflict, and in-flight cases |
| Audit | Feature | Required actions write an entry with actor, reason, before/after |
| Audit append-only | Feature | Update/delete attempts throw |
| Queue display realtime | Feature | Channel auth rejects wrong tenant and wrong branch |

## 8. Test data

- **Factories for everything**, including tenants. `Tenant::factory()` provisions
  a real test database.
- No shared fixture file that every test depends on — it becomes untouchable.
- Seed data is deterministic: fixed dates, fixed uuids where asserted. A suite
  that fails on the 1st of the month is a broken suite.
- Time is frozen with `travelTo()` in anything touching schedules. Include DST
  transitions and a non-DST timezone (Baghdad is UTC+3 year-round, so an
  explicit DST-observing branch is needed to catch DST bugs).

## 9. CI pipeline

```
push / PR  →  composer check
  ├─ Pint (style)                            ~10s
  ├─ PHPStan + Larastan                      ~60s
  └─ Pest
      ├─ Architecture + safeguard tests      ~10s
      ├─ Unit                                ~30s
      ├─ Feature (MySQL 8 service)           ~4min
      ├─ Tenant Isolation                            ← blocking
      └─ Contract (OpenAPI)                  (Phase 4+)
```

CI runs exactly `composer check` — **the same FULL gate developers run locally
for a release candidate** (§9.1). One full gate with one definition; splitting CI
into its own sequence of tools is how CI and local slowly stop agreeing, and
`tests/Architecture/DatabasePortabilityTest.php` asserts both that CI invokes it
and that it still means lint + stan + the full suite.

CI runs **MySQL 8** as a service container, regardless of what developers run
locally. That is what makes it the authority on engine behaviour: local MariaDB
proves the code works, CI proves the schema does (`03` §7.2). The same test file
asserts the service is `mysql:8`, on the port the app expects, with the database
the suite is configured to use.

- PHP 8.2 primary (the supported floor); 8.3 in the matrix once CI is stable.
- PHPStan starts at level 5 and ratchets one level per phase to 8. The
  ratchet is enforced by config, so it cannot silently slip back.
- Coverage reported, not gated, for the first three phases. From Phase 4:
  85% minimum on `app/Kernel` and `Domain/` directories, unenforced elsewhere.
- Target: the full pipeline under 12 minutes. Beyond that, developers stop
  waiting for it and start merging around it.

### 9.1 Local gate tiers

By Phase 13 the full suite takes **about 3.5–4 hours** on the local shared
MariaDB (1 479 tests, 13 358 s in the last full run): nearly every Feature test
provisions a real center database and runs every tenant migration. That is the
right design for isolation and far too slow to be the per-change gate. So there
are three tiers. They differ in **what runs**, never in how strict a test is.

| Tier | Command | Scope | Measured | When |
|---|---|---|---|---|
| **FAST** | `composer check:fast` | Pint, PHPStan, `tests/Architecture` (includes `SafeguardsTest` and the portability checks), `tests/Unit` — then the module in hand, e.g. `php vendor/bin/pest tests/Feature/Payments` | ~3 min + the module | Normal iterative development |
| **PHASE** | `composer check:phase13` | FAST + `tests/TenantIsolation` + the phase's modules + the files that directly regress on them (below) | ~75 min | Before closing a development phase, with `metastyle:doctor` |
| **FULL** | `composer check` (`composer check:full` is an alias) | Pint, PHPStan, the whole Pest suite — **unchanged** | ~3.5–4 h | Release candidate, major architecture change, CI/nightly, explicit request |

Phase 15 uses `composer check:phase15`: lint, PHPStan, Architecture, Unit, the
whole `tests/TenantIsolation` suite, and every feature suite the Super Admin and
the Manager surface (Phase15, Manager, SaaS, Identity, Booking, Journey, Queue,
Conversations, RAYAN, Customers, Loyalty, Memberships, Packages, Reviews,
Employees, Resources, Catalog, Sales, Payments, Finance, Printing, Reports, Charts,
Usage, Notifications, Menu, CenterSite, Media, Localization) plus Foundation and
ProductionReadiness. The Manager exposes almost every module, so this gate is
close to the whole suite; only the API/Audit foundation and database-name unit
files stay out. Local Phase 15 closure additionally requires
`php artisan metastyle:doctor`. The global FULL suite is intentionally deferred
to the final web release/pre-production verification unless explicitly
requested. The architecture suite needs `memory_limit=2G` (phpunit.xml) since
the Manager build.

`check:phase13` runs, in one sequential Pest process: `tests/Architecture`,
`tests/Unit`, `tests/TenantIsolation`, `tests/Feature/Conversations`,
`tests/Feature/Rayan`, `tests/Feature/Usage`, `tests/Feature/Notifications`;
`Booking/BookingVerificationTest`, `Booking/AppointmentLifecycleTest`,
`Booking/GuestAndCustomerBookingTest`, `Booking/BookingSurfaceTest` and
`Booking/BookingConcurrencyTest` (every booking now mints a reference and a
code, and `BookingEngine::book()` returns a `BookingResult`);
`Customers/CustomerAccountTest` and `Customers/CustomerSurfaceTest` (the
customer-facing booking surfaces); `Saas/TrialAndEntitlementTest` (the channel
and assistant entitlements); `Identity/SystemRoleSyncTest` (the permission
catalog grew); `Sales/SalesSurfaceTest` (it scans EVERY route, and Phase 13
added an unauthenticated webhook); `Menu/PublicRouteBoundaryTest` (the webhook
is a public write); and `ProductionReadinessTest` (the doctor gained the
verification-key check). It replaced `check:phase12`.

Suites for modules Phase 13 did not change are not in it; a FULL run covers the
rest. `Sales/SalesSurfaceTest` was added after the phase's first FULL run found
what PHASE had missed — a file that scans every route is a direct regression
file for any phase that adds one.

Rules:

- **A FULL run is not required after every ordinary phase correction.** A
  correction runs FAST plus its affected suites; closing the phase runs PHASE.
- **Tiers never weaken tests.** Nothing is skipped, deleted, loosened or marked
  slow to make a tier faster. The only lever is which files a tier runs.
- **No parallel Pest** against the shared local server.
- **Independent runs are namespaced (ADR-085).** Two terminals, or several
  agents, may run Pest at the same time only when each run has its own
  namespace, which owns only its own databases:
  `METASTYLE_TEST_DB_NAMESPACE=x DB_CONTROL_DATABASE=meta_style_test_ns_x_control METASTYLE_TENANT_DB_PREFIX=meta_style_test_ns_x_t php vendor/bin/pest …`.
  A default run never drops a namespaced database, and a namespaced run never
  drops anything outside `meta_style_test_ns_x_`.
- **Report which tier ran, and the last FULL result as it was.** Phase 10 closed
  on PHASE with the last FULL result recorded as "1205 passed / 4 obsolete tests
  failed; all four were corrected and their affected regression suites
  subsequently passed", not as a full pass.
- **Run a new gate script once before relying on it.** `check:phase13` was
  committed with its `disableProcessTimeout` callback over-escaped and fataled
  before the first test. A gate that has never executed is not a gate.
- **Read the runner's exit code, never the wrapper's.** A background command
  ending in `echo` or `date` reports THAT command's status: Phase 13's first
  FULL notification said exit 0 while the log said `EXIT_CODE=1`. End a wrapper
  with `exit $rc`, and read the code from the log.
- **An interrupted run has no result.** Phase 13's second FULL run was lost to a
  power cut mid-suite. It is recorded as interrupted — neither a pass nor a
  failure — and is not counted as an attempt. Before restarting: the server's
  own crash recovery has completed (never `innodb_force_recovery`), no runner
  process remains, and stale `meta_style_test_%` databases are removed only
  through `TestDatabaseManager::drop()`, whose prefix guard is the point. Never
  delete a data directory by hand.
- **Each phase repoints its script** (`check:phase11` replaces `check:phase10`)
  instead of accumulating one per phase. The directories a phase owns go in
  whole; from other modules, only the files that exercise the new coordination.

## 10. Definition of Done

A feature is not done until:

1. Unit tests cover the domain logic, including failure paths.
2. Feature tests cover the endpoint, including `403` and `422` cases.
3. If it touches tenant data, a row exists in the isolation suite table.
4. If it is entitlement-gated, both the granted and the denied case are tested.
5. If it is permission-gated, the policy matrix includes it.
6. If it mutates money or customer data, an audit assertion exists.
7. If it adds a migration, the fresh **and** upgrade tests pass.
8. If it adds an endpoint, the OpenAPI spec is updated and the contract test passes.
9. Architecture tests pass without a new exception being added.
10. The relevant `/docs` file is updated in the same PR.
11. The right gate tier is green (§9.1): FAST plus the touched module for a
    change; PHASE plus `metastyle:doctor` to close a phase; FULL for a release
    candidate, a major architecture change, CI/nightly, or on request.

Item 10 is what keeps this documentation set alive rather than becoming
archaeology. Item 11 keeps the gate honest without making a three-hour run the
price of every correction.

## 11. Anti-patterns

| Anti-pattern | Why |
|---|---|
| SQLite in tests | No JSON functions, generated columns, or real FK/collation behaviour. |
| Mocking the database | Tests pass while the query is wrong. |
| An ambient default tenant in the test harness | Hides exactly the bug the guard exists to catch. |
| Skipping isolation tests when they are slow | They are the release gate. |
| Coverage as a target | Produces tests for getters, not for booking rules. |
| Asserting on rendered UI strings | Breaks with every copy change and every locale. |
| `sleep()` in tests | Flaky and slow. Freeze time instead. |
| Tests that depend on today's date | Fail on month boundaries and DST changes. |
| One giant shared fixture | Nobody dares change it. |
| Mocking `Entitlements` in feature tests | The gate under test is the thing being mocked away. |
| Adding an architecture-test exception to make CI green | The exception list is the erosion of the boundary. |
