# Meta Style — Project Rules

Multi-tenant SaaS platform for barbershops, salons, beauty/laser centers, spas
and hammams. Laravel 12 · PHP ^8.2 · Blade + Livewire · MySQL 8 / MariaDB ·
modular monolith · **one database per tenant**.

Detailed requirements live in `/docs`. This file holds only the rules that apply
to almost every session. Read the relevant `/docs` file before working in an area.

## Current phase

**Phase 9 complete** — Phase 8 plus sales, the POS till, journey checkout,
immutable invoices, invoice numbering, cashier shifts, products, the customer
digital invoice and browser-printed 80mm/A4 invoices.
No payments, finance, loyalty or reporting yet.
Do not start a phase without explicit authorisation. See `docs/13-ROADMAP.md`.

## Stack constraints

- **PHP `^8.2`.** Do not use syntax or stdlib features requiring 8.3+.
- **Blade + Livewire 3.** No React, Vue, Inertia, or separate SPA.
- **Livewire actions POST to `/livewire/update`, not to the route that rendered
  the component.** Middleware a tenant page depends on must be registered with
  `Livewire::addPersistentMiddleware()`, or it silently does not run there
  (ADR-045). `MiddlewareOrderGuard` asserts this at boot.
- **Tenancy is `stancl/tenancy` v3** wrapped in Meta Style abstractions.
  Business code depends on `App\Kernel\Tenancy\Contracts\*`, never on package
  internals or package global helpers. `Stancl\*` is confined to
  `App\Kernel\Tenancy\Infrastructure` and `TenancyServiceProvider`, enforced by
  an architecture test.
- **Cache store must be taggable** (`array` locally, `redis` in production).
  `file` and `database` do not support tags and would silently share one
  keyspace across tenants (ADR-025).
- **Production needs a shared rate-limit backend** — `array` is per process and
  `file` is per server, so N workers means N× every published limit, silently
  (ADR-034). Local and test need no Redis. `metastyle:doctor --production`
  enforces this; do not weaken it to make an environment green.
- Redis is configurable but not required to run or test. No Horizon until
  there is a real async workload.
- **Sanctum** for API tokens. Tokens live in the tenant database and carry the
  center's public key (ADR-027). Web uses a normal session.

## Non-negotiables

**Tenancy** — `docs/02-TENANCY.md`
- Never accept a tenant identifier from the client (no param, no body field, no
  header). Resolution is by host, token binding, or web session. Only.
- The ONE exception is the guest electronic menu, which resolves the center's
  public key from the URL path via `ResolvePublicTenant` (ADR-036). That
  middleware may never share a route with authentication — a test enforces it.
  Do not add it to an authenticated group to "let staff preview".
- Never reference `stancl/tenancy` classes or global helpers (`tenant()`,
  `tenancy()`) outside `App\Kernel\Tenancy`. Inject `TenantContext`.
- Never add a fallback or default tenant. Unresolved means the query **fails**;
  it must never silently hit another tenant or the control database.
- Never build a storage path by hand — use `Kernel\Storage\MediaStore`.
- Never derive a database name from user input. `TenantDatabaseName` generates
  every one of them from the internal sequence (ADR-024).
- Migrations never create databases; provisioning does (ADR-026).
- **`dispatch()` inside tenant context**: the returned `PendingDispatch` queues
  the job in its destructor. Do not return it out of the tenant closure — an
  arrow function does exactly that, and the job gets queued after tenancy has
  ended, with no tenant attached.
- Tenant resolution runs BEFORE authentication. If you add middleware that
  authenticates, check `bootstrap/app.php`'s priority list — Laravel reorders
  it otherwise (ADR-027). The anchor is the `AuthenticatesRequests` **interface**;
  anchoring on `Authenticate::class` silently does nothing. `MiddlewareOrderGuard`
  throws at boot if the order is wrong — never relax it to get a boot.
- Anything touching tenant data needs a case in `tests/TenantIsolation`.

**Identity & authorization** — `docs/06-AUTH-ROLES-PERMISSIONS.md`
- Permission codes live in `App\Kernel\Authorization\Permission`, never in
  the database. Only add a code when something actually checks it.
- Never write `if ($user->is_owner) return true`. Owner holds explicit grants
  (ADR-029); `is_owner` is protection, not authorization.
- Permission **and** branch scope. `hasPermission()` alone is not authorization.
- Nobody may grant a permission they do not hold themselves.
- Never put a plaintext or hashed credential in a queue payload (ADR-028).
- **Credential checks are rate-limited in the ACTION** — `AuthenticateStaff` and
  `AuthenticateCustomer`, both through `Kernel\Identity\LoginThrottle`. A route
  throttle is not the protection: a Livewire sign-in posts to `/livewire/update`
  and never reaches it, and an IP limit bounds the attacker rather than the
  account being attacked. Buckets are keyed tenant + `Fingerprint`(identifier)
  and tenant + IP. Never key on a raw identifier; never on a password.
- The registration bootstrap credential lives on the row, encrypted, only while
  the registration is still retryable, and is destroyed on every terminal
  outcome (ADR-031). Do not clear it on failure — that made every failed
  self-registration unrecoverable. Do not keep it past the window either.
- A release that adds a `Permission` case must run `metastyle:roles:sync --all`
  on deploy, or existing owners silently lack it (ADR-032).

**Entitlements** — `docs/05-ENTITLEMENTS.md`
- Never compare plan names (`$plan === 'pro'`). Ask `allows()` / `limit()` /
  `quota()`.
- Enforce inside the Action, not only in route middleware — WhatsApp, AI, jobs
  and commands do not pass through HTTP middleware.

**Migrations** — `docs/03-DATABASE-MIGRATIONS.md`
- Two directories: `database/migrations/control/` and `.../tenant/`. Never both.
- Expand/contract only. No rename, no type change, no NOT NULL without a
  default, no drop of anything current code reads. Contract ships a release later.
- One logical change per migration file. MySQL DDL is not transactional.
- Portable schema only: no MySQL-8-only JSON functional or generated indexes, no
  `default()` on json/text/blob, collation named explicitly. Add an
  engine-specific index only with a real query and an `EXPLAIN` behind it
  (ADR-033, `docs/03` §7.2).
- Backfills are separate queued chunked jobs, never inside a migration.
- Use the `metastyle:*` commands — `control:migrate`, `tenant:migrate`. Plain
  `php artisan migrate` targets the default connection and the wrong migration
  set; it is not blocked, it is simply not the workflow.

**Money & time** — `docs/10-API-FOUNDATION.md` §8–9
- Integer minor units + currency code. Never float, never `decimal` for amounts.
- Currency exponent from `Kernel/Money`. **IQD has 0 decimal places** — never
  assume 2. Parse user input with `Money::fromMajorString()`, never through a
  float: `(int) (25.10 * 100)` is 2509.
- Currency is a CENTER setting, not a per-row column. One center, one price list,
  one currency.
- Store UTC. Compute in the **branch** timezone. Transport ISO-8601 with offset.

**Customers & PII** — `docs/13-ROADMAP.md` Phase 5
- A **Customer is not an account**. `customers` holds no credential; a login is
  a `customer_accounts` row. Guest = no account row, never a flag (ADR-041).
- A **customer is not a staff user**. Separate guard, separate provider, no
  roles. Never authenticate one with the other's middleware.
- A customer belongs to the CENTER. **No `branch_id` on `customers`** — locked.
- Phone is the identity, normalised to E.164 by `Kernel\Contact\PhoneNumber`.
  Never compare raw formatting; never add a global/cross-tenant uniqueness
  constraint or a cross-center directory.
- A signup must **link an existing customer**, never create a second one.
  Everything later hangs off the customer id (ADR-041).
- **Nothing sets `phone_verified_at`.** There is no verification provider; an
  architecture test enforces it (ADR-040).
- Mask contact details in `CustomerPresenter`, never in Blade. Masked fields are
  never query filters (ADR-042).
- Never put a customer's phone or email in an audit row — fingerprint it with
  `Kernel\Privacy\Fingerprint`. Never audit a note's body.

**Security** — `docs/08-AUDIT-SECURITY.md`
- Never store PAN, CVV, PIN, OTP, or track data. Anywhere.
- Never put PII in a URL, query string, or QR code.
- Expose uuids in APIs, never auto-increment ids.
- A record in another tenant returns `404`, not `403`.
- Public-facing resources are ALLOW-LISTS. Name every field emitted; never
  `$model->toArray()` minus a deny-list. A deny-list is defeated by the next
  column somebody adds.
- The electronic menu takes no HTML, CSS or JavaScript from a center. It is a
  guest page, so a center-authored script is stored XSS against that center's
  own customers (ADR-038).
- Masking applies to exports and reports, not just the API.

**Localization** — `docs/07-LOCALIZATION.md`
- Never create `name_ar` / `name_en` / `name_ku` columns. Use JSON + the
  `Translatable` cast. The cast preserves null: absent is not the same as empty.
- Which locales a center has is `TenantLocales`, in tenant `settings`. Admin
  forms render one field per ENABLED locale only. Disabling never deletes
  stored translations.
- Kurdish Sorani is `ckb`, not `ku`.
- Direction comes from the language registry, never a hardcoded list.

## Structure

```
app/Kernel/     cross-cutting platform services — created as each is needed
app/Modules/    business modules, layered — see docs/04-MODULE-BOUNDARIES.md
```

**Create a directory when the phase needs it, not before.** Empty placeholder
folders, interfaces with one implementation, and speculative abstractions are
worse than nothing. `docs/04-MODULE-BOUNDARIES.md` is the target shape, not a
checklist to scaffold up front.

- `Kernel` never imports `Modules`.
- A module imports another module only via its `Contracts/`, `Data/`, or
  `Domain/Events/`. Never its models.
- A module never depends on a higher layer. Upward = domain events only.
- Controllers: validate → call one Action → return a Resource. No business logic.
- Actions are the transaction boundary and where audit and entitlement checks live.
- Domain services take what they need as arguments — no `request()`, `auth()`,
  `session()`, `config()`, or facades.

## Booking — `docs/15-BOOKING.md`

There is exactly **one** Booking Engine. Web, mobile, white label, host, POS,
WhatsApp and RAYAN are all adapters that call it. If you are about to write
availability or booking-rule logic outside `Modules/Booking`, stop.

- A channel may parse input, resolve a customer, present availability and call
  `BookingEngine`. It may NOT compute a slot, decide whether a booking is
  allowed, apply a policy, or write to `appointments`. Three architecture tests
  enforce this.
- **The booking source comes from the ADAPTER, never from a request body.**
  `staff` is a privileged claim.
- **Snapshot price, duration, currency and name on every item.** A later catalog
  change must never rewrite an existing booking.
- **Overlap is `existing.start < new.end AND existing.end > new.start`** —
  strict both sides, so 10:30 after a 10:00–10:30 booking is free. Never
  `whereBetween`: it misses the appointment that encloses the candidate.
- **The authoritative availability check happens inside the booking transaction,
  under a `lockForUpdate()` on the branch row** (ADR-044). Availability returned
  earlier is advisory; a slot it offered may still be refused.
- Only `booked` and `confirmed` occupy the calendar. Completion creates no sale,
  invoice, commission or loyalty. Sales exists since Phase 9 and still is not
  triggered by completion: checkout is an explicit, separate Sales Action.
- Compute in the BRANCH timezone via `Kernel\Time\BranchClock`. A local time
  that does not exist (DST gap) returns null; never let PHP shift it silently.
- Scheduled instants are `DATETIME`, not `TIMESTAMP` (ADR-046).
- Appointment items say what was RESERVED. Journey stages say what HAPPENED.
  Never add stage, queue, room or "in progress" state to the booking tables — an
  architecture test scans every migration for it.

## Journey & Resources — `docs/16-JOURNEY-RESOURCES.md`

- **Planned and actual stay separate.** `appointment_items` = booked employee,
  planned times, reserved rooms. `journey_stages` = who actually did it, when it
  actually ran, which room was actually used. Neither overwrites the other.
- **Booking must never depend on ServiceJourney.** Journey may read Booking;
  Resources is an L1 module both can read (ADR-049). Architecture tests enforce
  all three directions.
- **Journey never writes `appointments.status`.** Completing or cancelling a
  visit calls the Booking lifecycle Action. A second implementation would have
  its own idea of legal transitions and its own missing audit entry.
- **Capacity is a PEAK, never a sum** — `Kernel\Time\Occupancy`. Adding up
  everything that overlaps refuses valid bookings; that class has the example.
- **Runtime capacity = actual occupancy + committed booking reservations**, in
  ONE combined peak, under the branch lock (ADR-050). A room reserved from 10:30
  is not free at 10:10 just because nobody is standing in it. Exclude the stage's
  OWN item's reservation, and any other item whose stage has already started —
  those are represented by their usage rows. Never count a customer twice, and
  never release somebody else's reservation to make an operational request
  succeed.
- **Every mutation that changes bookability takes `BranchLock`** — resource
  capacity or activation, service requirements, availability blocks (ADR-047).
  It throws outside a transaction, because a row lock there protects nothing.
- **Resources and availability blocks belong to exactly one branch.** Both
  `branch_id` columns are NOT NULL; that is what the lock and the conflict
  queries depend on.
- **Archive, never delete.** Retiring a resource stops the NEXT booking and
  leaves existing ones alone; a query lists what is affected.
- **A resource swap closes the old usage row and opens a new one.** Overwriting
  `resource_id` destroys the interval the record exists to preserve.
- Departments route operational work. **Never `service_category_id`** (ADR-037).
- Journey stage `waiting` is NOT a queue ticket. The number, the position and
  the priority live on `queue_tickets`, built on top of these tables.
- Availability collaborators are transient, never `scoped` (ADR-048).
- **A walk-in has no appointment.** `service_journeys.appointment_id` is
  nullable; a walk-in carries `customer_id` + `branch_id` and each stage carries
  its own service snapshot. NEVER invent an appointment to satisfy the column
  (ADR-051). Read through `branchId()` / `customerId()` / `durationMinutes()`,
  never by branching on which kind of visit it is.

## Queue — `docs/17-QUEUE.md`

- **Four concepts, permanently separate.** Appointment = reserved · Journey =
  the visit · JourneyStage = what was performed · QueueTicket = the waiting and
  calling around a stage. A ticket is never the source of truth for execution.
- **`serving` and `completed` are Journey to give.** No queue Action writes
  either: the only writer is `SyncTicketsWithJourney`, reacting to a domain
  event. There is deliberately no "complete ticket" endpoint (ADR-053).
- **The synchronizer is synchronous and runs inside the Journey transaction.**
  Never `ShouldQueue`, never broadcast — a stage `in_service` beside a ticket
  still `called` is exactly what a host and a television would both be showing.
- **Every mutating queue Action locks its ticket `FOR UPDATE`**, validates the
  LOCKED row, and appends exactly one history row whose sequence is allocated
  under that lock. Never `MAX(sequence) + 1` unlocked.
- **Ticket numbers come from a locked sequence row** per branch, per BRANCH-LOCAL
  date, per prefix. Never `MAX(number) + 1`, never a counter in PHP, never Redis
  alone.
- **Queue history is domain data**, separate from Audit. Audit answers "who
  changed what"; `queue_ticket_events` answers "what happened to this customer".
- A queue **skip** means nobody answered and is recoverable (`held`). A stage
  **skip** means the customer declined the service. Never confuse them.
- The public display is **read-only, unauthenticated, allow-listed** — a number
  and a destination, never a name or a phone. It POLLS; correctness never depends
  on delivery (ADR-052).
- Queue is gated on `queue_management` / `queue_display` / `queue_voice`.
  **Walk-ins are gated on `booking`**, so Journey works without the queue.
  **No seeded plan sells the queue** — a phase defines a capability, the SaaS
  work decides which package gets it. Until then it is a per-tenant add-on.
- A translation group file must never be named after a dotless literal:
  `__('Queue')` is parsed as a GROUP and finds `queue.php` on a case-insensitive
  filesystem, returning the whole array. Name the group after its SURFACE
  (`queue_public.php`). Enforced by an architecture test, not by memory.

## Sales — `docs/18-SALES.md`

- **A sale is not a visit, and an invoice is not a cart** (ADR-054). Sale = what
  was CHARGED; Invoice = what was PUBLISHED. Never put financial state on
  `appointments`, `journey_stages`, `service_journeys` or `queue_tickets`.
- **Journey never imports Sales.** `CompleteJourney` creates no sale; checkout is
  `CheckoutJourney`, which READS the visit. The visit board only links to the till.
- **A visit's sale is finalized only once the visit is `completed`.** A draft may
  be prepared while it runs; `VisitLines` adds every performed service at reopen
  and inside finalization. So a visit line is never removed — charge less with a
  reasoned override or a discount. No partial, split or progress invoice.
- **Every sale line is a snapshot** taken when it is added. Finalization re-prices
  from the snapshots and never reads the catalog. A booked visit is charged at its
  BOOKED price. `original_unit_price_minor` is never overwritten.
- **Totals come only from `SalePricing`.** Controllers and Livewire may PARSE what
  a person typed (`Money::fromMajorString`, `SalePricing::basisPoints`); they may
  not add, multiply, round or assign a total. An architecture test scans for it.
- **Every draft mutation goes through `SaleMutation`**: lock the sale, refuse
  unless the LOCKED row is a draft, change, recalculate — one transaction.
- **Invoices are immutable** (`ImmutableDocument`). Never `DB::table('invoices')`
  — model events cannot see it and a scan refuses it. A void is recorded on the
  SALE; the invoice row never changes.
- **Invoice numbers** come from `invoice_sequences` under `FOR UPDATE`, per branch
  per branch-local year, inside the finalization transaction (ADR-055). Never
  `MAX()+1`. Non-main branches need `invoice_prefix`; `INV` is the main branch's.
- **Finalizing requires the cashier's open shift** at that branch.
- **Financial audit commits with the change.** `sale.finalized`, `invoice.issued`,
  `sale.voided`, `sale.discarded` are written inside the Action's transaction
  (same tenant connection). Never move them after COMMIT (ADR-057).
- **Entitlements (locked):** `pos` = sales, checkout, invoices, products, shifts;
  `printing` = the 80mm/A4 paper only; `payments`/`finance` = Phase 10. There is
  no `invoices` key. **`pos` gates NEW operations** (`SalesAccess::ensure`);
  reading issued sales/invoices, the public invoice and link rotation use
  `authorize()`, and printing needs `printing` alone — the Booking downgrade rule.
- **No payment concept** anywhere in Sales — no gateway, intent, refund, tip,
  deposit or payment status. A scan enforces it.
- **The public invoice resolves ONLY by its 64-hex share token** and is an
  allow-list. No notes, reasons, staff names, uuids, original prices or customer
  contact. **Only the SHA-256 `token_hash` is stored**; the plaintext is returned
  once (`IssuedInvoice::$shareToken`, `RotateInvoiceLink`) and never logged,
  audited, put in a session or serialised. A later read cannot show the URL —
  issue a new link.
- The invoice numbering year is `sequence_year` (branch-local calendar year). It
  is not a fiscal year; do not name it one without a verified requirement.
- One sale is capped at 3 000 000 000 minor units so the discount allocation is
  exact in 64-bit integers. Do not raise it without revisiting the allocation.

## Testing — `docs/11-TESTING-STRATEGY.md`

- Pest 3 against a **real MySQL/MariaDB** server. **Never SQLite** — the design
  depends on JSON functions, generated columns, collation and FK semantics.
- No ambient default tenant in tests. Bind explicitly.
- `tests/TenantIsolation` is a release gate. Never skip it.
- Architecture tests enforce the rules above; adding an exception to make CI
  green is not a fix.
- A test whose baseline already satisfies its assertion proves nothing. When a
  test says "this tenant does not own X", something must PIN that — the plan
  matrix is pinned as an exact set for exactly this reason.
- **One needle per negated `toContain`.** `not->toContain('a', 'b')` passes as
  soon as EITHER is missing, and `not->toContain($x, 'message')` treats the
  message as a needle and never fails. Use one needle per negation, or
  `expect(in_array(...))->toBeFalse($message)`. `SafeguardsTest` refuses it.
- Definition of Done includes updating the relevant `/docs` file in the same PR.

## Conventions

- `snake_case` tables and columns, `PascalCase` classes, `camelCase` methods.
- Every entity exposed over the API also has a `uuid`.
- Enums are PHP backed enums; never MySQL `ENUM` columns.
- Events are past-tense facts carrying identifiers, never Eloquent models. Never
  the only place a business invariant is enforced.
- Eloquent models are fine. Do not add repository interfaces.
- Every new Composer package needs an entry in `docs/DECISIONS.md`.

## Commands

```bash
composer check          # pint --test + phpstan + pest  (run this before finishing)
composer fix            # pint (write)
composer test           # pest
composer stan           # phpstan/larastan
```

```bash
php artisan metastyle:control:migrate
php artisan metastyle:tenant:provision "Center Name" --domain=center.test
php artisan metastyle:tenant:migrate --all
php artisan metastyle:tenant:migrate --retry-failed
php artisan metastyle:tenant:status --drift
php artisan metastyle:roles:sync --all
php artisan metastyle:registration:retry <uuid>
php artisan metastyle:registration:sweep --dry-run
php artisan metastyle:idempotency:sweep
php artisan metastyle:doctor --production
```

Catalog conventions worth knowing before touching `Modules/Catalog`:

- **Department ≠ Category.** Department is operational (Queue, Journey, rooms);
  Category is customer-facing menu grouping. Separate tables, on purpose
  (ADR-037).
- A **variation with a null price or duration inherits** from its service and
  keeps inheriting. Null is not zero and not a snapshot.
- **"Available at all branches" is the absence of pivot rows**, so adding a
  branch does not mean touching every service.
- Operational data **archives**, never hard-deletes: appointments and invoices
  will reference it.

Plain `php artisan migrate` is not the workflow — it targets the default
connection and the default path, which is neither migration set.

Requires a running MySQL/MariaDB server; see
`docs/12-DEPLOYMENT-MIGRATION-STRATEGY.md` §10 for local setup.

## Before you finish

- Run `composer check` and confirm it is green.
- Report failures with the actual output. Do not claim work is done unverified.
