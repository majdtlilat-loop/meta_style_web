# Meta Style — Project Rules

Multi-tenant SaaS platform for barbershops, salons, beauty/laser centers, spas
and hammams. Laravel 12 · PHP ^8.2 · Blade + Livewire · MySQL 8 / MariaDB ·
modular monolith · **one database per tenant**.

Detailed requirements live in `/docs`. This file holds only the rules that apply
to almost every session. Read the relevant `/docs` file before working in an area.

## Current phase

**Phases 0–14 complete; Phase 15 (Super Admin, corporate landing and the Manager web app) is implemented and awaiting the user's review.** Phase 16 (the customer account) has not started.
Phases 0–11 give loyalty, memberships and service packages; Phase 12 adds
reviews, ratings, review capability links and IN-APP notifications. Phase 13
adds booking verification codes, the WhatsApp channel, RAYAN and usage quotas,
and closed its code, architecture and automated verification on a green FULL
suite (1 479 passed, 7 861 assertions, exit 0).

**Neither Phase 13 provider adapter has run against live credentials.** OpenAI
and Meta's Cloud API are both implemented and contract-tested, and both are
LIVE CREDENTIAL TEST PENDING (`docs/12` §12). Never describe either as
production-verified. See `docs/13-ROADMAP.md`.

Phase 14 added separate Standard and Advanced Reports, CSV/browser print,
Reporting-only advanced reads and contextual report RAYAN with its own run
allowance (`docs/28-REPORTS.md`). Still absent: gift cards, promo codes,
referrals, SMS, push, email and Super Admin broadcast targeting. Do not start a phase without
explicit authorisation.

## Stack constraints

- **PHP `^8.2`.** Do not use syntax or stdlib features requiring 8.3+.
- **Blade + Livewire 3.** No React, Vue, Inertia, or separate SPA.
- **Livewire actions POST to `/livewire/update`, not to the route that rendered
  the component.** Middleware a tenant page depends on must be registered with
  `Livewire::addPersistentMiddleware()`, or it silently does not run there
  (ADR-045). `MiddlewareOrderGuard` asserts this at boot.
- **Never name a Livewire action after a `$wire` alias** (`call`, `get`, `set`, `on`,
  `dispatch`, …): the browser runs Livewire's built-in instead of the method, and PHP
  tests cannot see it (ADR-102, `LivewireActionNamesTest`).
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
- Never build a database name by hand or from raw input.
  `TenantDatabaseName::generate()` makes a NEW center's name once, at
  provisioning: its slug (never its display name; no slug → `center`) reduced
  to `[a-z0-9_]` (at most 24 characters) plus the internal sequence as the
  unique suffix (`tenant_drbany_000003`). It is
  stored in `tenants.tenancy_db_name` and never re-derived: a rename or a new
  address never renames a database. Pre-ADR-106 names (`tenant_000003`) stay
  valid (ADR-024, ADR-106).
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
- **Entitlements (locked):** `pos` = sales, checkout, invoices, products, shifts,
  and desk cash/transfer collection; `printing` = the 80mm/A4 paper only;
  `payments` = online payments and gateways; `finance` = expenses, counted
  closes, the dashboard. There is no `invoices` key. **`pos` gates NEW operations** (`SalesAccess::ensure`);
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

## Payments & Finance — `docs/19-PAYMENTS.md`, `docs/20-FINANCE.md`

- **Sales never imports Payments or Finance; Payments never imports Finance.**
  Operational modules import neither. A void asks through
  `Sales\Contracts\SaleVoidGuard` (tag `sales.void_guards`); the ledger hears
  `PaymentSucceeded` / `RefundSucceeded`. No model observers for money.
- **A payment is one attempt against one issued invoice**; split = several.
  Nothing about payment is written to `sales` or `invoices`.
- **Lock order: sale, then invoice** (`InvoicePaymentLock`); a refund locks its
  payment; cash locks the collector's shift. Check what may be collected under
  the lock, through `InvoiceSettlement` — the only place payments are added up.
- **A pending gateway payment reserves its amount.** Never let the desk collect it
  again; never "fix" an overpayment with a refund.
- **Settlement state comes from gross succeeded. Refunds never reopen an invoice.**
- **Only a verified callback or an authenticated status query settles** — amount,
  currency and reference matched. A browser returning from a provider proves
  nothing. A mismatch stays pending and raises a `critical` security audit.
- **Callbacks: no staff auth, no entitlement check, no raw body stored**,
  idempotent by fingerprint. A downgrade never strands money already moving.
- **Never invent a provider integration.** An adapter is built only from verified
  provider documentation, with contract tests; otherwise it is an
  `UnsupportedProvider`. FIB has NOT yet been run against its sandbox. A new
  package needs a `docs/DECISIONS.md` entry before installation.
- **Provider hosts are config, https only.** No credential field may carry a URL.
- **Gateway credentials are per branch, `encrypted:array`, write-only**: never in
  a presenter, API, Livewire state, log or audit row. Read them through
  `readableCredentials()`, which fails closed after a key change.
- **Cash never needs `payments`.** Losing an entitlement blocks the next operation;
  history stays readable.
- **`Ledger::append` is the only writer of `finance_entries`**, inside the money's
  transaction; the ledger and drawer counts are append-only. Correct with a new
  entry, never an edit. Written regardless of the `finance` entitlement.
- **Expected cash counts `method = cash` only.** "From the drawer" is an explicit
  claim, never inferred. A variance never blocks a close.
- **Never call a figure revenue or profit.** Invoiced ≠ collected ≠ net movement.
- **Finance windows are branch-local days** — in code and in tests
  (`SeedsPayments::branchToday()`), never `now()->format('Y-m-d')`.
- The center is the merchant: no platform custody, wallet, payouts or commission.

## Loyalty, Memberships & Packages — `docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md`

- **Money first (ADR-061).** Benefit listeners on `PaymentSucceeded`,
  `RefundSucceeded`, `SaleFinalized`, `JourneyCompleted` only schedule work with
  `Kernel\Database\AfterCommit` — never write in the money's transaction (the
  OPPOSITE of the Finance ledger). Every reaction is idempotent and has a
  `Reconciler`, run hourly by `metastyle:reconcile`. A scan enforces the pattern.
- **Reads never write.** Queries, presenters, panels and the customer API never
  repair or activate (architecture test). Actions that CHANGE a balance —
  redeem, adjust, apply a package or membership benefit — call the module's
  `reconcileCustomer()` first.
- **A repair reproduces the event-time result (ADR-064).** Earning reads
  `loyalty_rule_versions` (append-only, effective-from) and
  `loyalty_earning_observations` (what Loyalty saw, per event), never the current
  program row: the rule, the eligibility, the date and the expiry all come from
  when the money was collected or the visit completed. Money collected while
  `loyalty` was owned stays recoverable after a downgrade; money collected during
  a gap never earns when it is granted back. Debits are never backdated.
  **Observations are evidence, not the record** — the rule and expiry come from
  the versions whether or not an observation survives, and an event with no
  surviving observation is still eligible under the version in force then.
- **Sales never imports the benefit modules.** They use `SaleBenefits`
  (`benefit_discount`, one per line), `OfferingCatalog` (tag
  `sales.offering_catalogs`), `SaleFinalizationGuard` (tag
  `sales.finalization_guards`) and the `SaleVoided` / `SaleDraftDiscarded` events.
  The till and customer page embed their own components (`TillBenefits`,
  `CustomerBenefitsPanel`) rather than importing the modules.
- **Lock order: the sale, then the benefit anchor** (loyalty account, customer
  package, customer membership; activation: the customer row).
- **Histories are append-only and the only truth**: `loyalty_transactions`,
  `package_transactions`, `membership_benefit_usages` (`AppendOnlyHistory`), each
  with one writer (`LoyaltyLedger`, `PackageLedger`, `MembershipUsageLedger`) and
  `unique(source_type, source_uuid, kind)`. Correct with a new row, never an edit.
- **Points balance is never negative (ADR-062).** A refund takes what the balance
  covers; the rest is `unrecovered_points`, settled by `recovery` rows from later
  earnings. Tiers use qualifying lifetime points. Each credit snapshots its own
  `expires_at`; remaining points are FIFO from the history, expired lazily.
- **Packages are consumed by performed service at checkout, never by booking**;
  one unit per session; add-ons stay charged. Memberships and packages activate
  when their invoice is SETTLED and stay usable after a downgrade until expiry.
- **Void / discard give-back is synchronous inside the Sales transaction**
  (ADR-063) — it moves no money; failure refuses the void.
- **A benefit on a draft is a HOLD (ADR-065).** `ReleaseStaleBenefits` (hourly)
  gives back points, sessions and uses held on a draft nobody touched for 24
  hours and re-prices it; a published sale is not a hold. An abandoned cart can
  never hold a customer's benefit for ever. Its unlocked query decides nothing:
  draft, untouched and still-held are all re-checked under the sale lock, so a
  release and a finalization can never both consume the same benefit.
- **A package session needs proof of performance (ADR-065).** A visit line's
  stage must be `completed`; a line typed at the till needs an explicit staff
  confirmation. Adding a line to a cart is not performing a service.
- A generated index or FK name over 64 characters breaks provisioning mid-migration
  and every retry reports "table already exists". Name long ones explicitly; a
  portability test enforces it.

## Reviews & Notifications — `docs/22-REVIEWS.md`, `docs/23-NOTIFICATIONS.md`

- **A review is about a visit that happened (ADR-066).** Eligibility is a
  completed `ServiceJourney` with at least one completed `JourneyStage`. No
  payment required, no customer account required, **and no appointment** — a
  walk-in journey is as reviewable as a booked one. A public form addressed by
  the center's key is a ratings board, not a record — there is none.
- **`reviews` depends on no other entitlement.** Declaring `requires =>
  ['booking']` would have the dependency closure silently drop it from a
  walk-in-only center, and would tie a feature about past visits to one about
  arranging future ones. Reviews never asks about `booking`.
- **The capability IS the identity.** 256-bit token, SHA-256 at rest, plaintext
  returned once and stored nowhere. A later read cannot show the link again:
  reissuing mints a new one and retires the old (the `RotateInvoiceLink`
  pattern). A signed-in customer needs no secret — their invitation is resolved
  by uuid against their own record.
- **A rating names a STAGE**, and the service and the employee are read from it.
  Rating a skipped service, or an employee who was only booked, is unreachable
  rather than refused.
- **One visit, one invitation, one review** — three UNIQUE columns plus the
  invitation row lock.
- **The customer's words are immutable at the model.** Moderation changes
  `status` and records who and why. `hidden` leaves the averages; `flagged`
  stays in them. Nothing deletes a submitted review.
- **`RatingSummary` is the only place an average exists.** No `average_rating`
  column on a branch, a service or an employee.
- **Losing `reviews` stops ISSUING and nothing else.** A link already given to a
  customer keeps working; staff keep reading and moderating.
- **Modules emit facts; Notifications listens (ADR-067).** Nothing imports
  `Modules\Notifications` — three architecture tests enforce the direction. Every
  handler schedules its work with `AfterCommit`, so a notification can never roll
  back a booking, a payment, an activation or a review.
- **IN-APP is the only channel.** Creating the recipient row is delivery; there
  is no delivery status, no outbox and no provider. WhatsApp, SMS, email and push
  are later phases, and a scan keeps their first call out of the module.
- **Idempotency is in the schema**: `unique(type, source_type, source_uuid)` and
  `unique(notification_id, recipient_kind, recipient_id)`. Nothing asks "have I
  already sent this".
- **A recipient is a KIND and an id.** `users` and `customer_accounts` have
  unrelated sequences; every query filters on both. A guest has no inbox and none
  is invented.
- **Staff are targeted by permission and branch, never by role name.**
- **Parameters, not sentences.** Messages render at read time from
  `notifications_inbox.php`; no HTML and no customer text is ever stored in a
  notification.
- **Absent preference means ON**, and only a type that names a preference key can
  be suppressed — never a cancellation, an invoice or a one-star review.

## WhatsApp, RAYAN & Usage — `docs/24`, `docs/25`, `docs/26`, `docs/27`

- **A booking has TWO identifiers (ADR-068).** `reference` (`B-000412`) is
  public, quotable, **enumerable by design** and authenticates NOTHING;
  `verification_code` is 10 Crockford characters that authenticate that ONE
  booking. Never conflate them, and never use a uuid as either.
- **The code is minted in `CreateAppointment`, never in a model event.** A
  one-time secret returns through `BookingResult`; a model event has nowhere to
  return anything to. `BookingEngine::book()` returns a `BookingResult`.
- **`HMAC-SHA256` under a VERSIONED pepper, never a plain hash (ADR-069).** A
  ~50-bit typeable code is brute-forceable offline from a database copy. The
  pepper lives in `config/security.php`, never in a database, and is **not**
  `APP_KEY`. Verify with the version **stored on the row** — never try every key.
  A missing version THROWS (fail closed); public paths turn that into the same
  generic refusal a wrong code gets, and report it. **There is no rehash.**
- **Legacy bookings get a reference and NO code.** Minting one nobody asked for
  creates a credential with no owner. Staff issue one on request.
- **No public "forgot my booking code" endpoint**, ever. A reference alone, or a
  reference plus a typed phone, is not authorisation. Three authorised paths
  only: the customer's own account, authorised staff, a verified WhatsApp sender
  who owns it. Issuing RETIRES the old code.
- **Every failure of a code lookup answers the same null** — no such reference,
  someone else's, wrong code, retired key. Distinguishing them turns the
  enumerable reference space into a map of the center's book.
- **Nothing happens before the webhook signature verifies (ADR-070).** Not a
  conversation, a customer resolution, an AI run or a tool call. HMAC over the
  **raw bytes** — never the re-encoded array. Rejected notifications are still
  recorded; the endpoint answers an empty 403 for every cause.
- **A verified sender is evidence for THAT interaction, never a stored flag.** No
  `whatsapp_verified`, and `phone_verified_at` stays untouched (ADR-040). The
  same number in two centers is two unrelated people. No `Customer` and no
  `CustomerAccount` is created from a conversation — the Booking Engine's
  resolver does that when somebody actually books.
- **`unknown` is a real delivery state and is NEVER auto-retried.** Meta's send
  takes no idempotency key, so a retry is a second copy at the customer's phone.
  Only a `failed` raises an alert.
- **Persist first, send second.** The inbound message commits before anything
  else can fail, so the messages that most need a human are never the ones lost.
- **RAYAN never imports Conversations.** The channel calls the assistant, not the
  reverse. Beware `{@see}` on a cross-module class: Pint turns it into a real
  import, and an architecture test caught exactly that.
- **Ten tools, an enum, an exact allow-list.** No SQL, HTTP, class-name or
  action-name tool. No schema declares `customer_id`, `phone`, `tenant` or
  `user_id` — identity comes from `ToolContext`, and unlisted arguments are
  dropped. **A prompt enforces nothing**; every real check is application code.
- **The loop always terminates**: max turns, max tool calls across the run, max
  output tokens, wall clock. An identical tool call within a run returns the
  first result rather than booking twice.
- **A turn is re-checked after the model answers.** `takeOver()` is allowed from
  `ai_active` and a provider call takes seconds, so the status routing checked is
  stale by the time the reply arrives. `ConversationRouter` re-reads it before
  sending or handing off — otherwise the bot replies underneath a colleague, and
  a hand-off pushes `human_active` back to `human_requested`, unassigning the
  person already on the thread.
- **Every failure ends in a human.** Provider outage, timeout, refusal,
  truncation, exhausted quota, loop ceiling — all hand off, and nothing ever
  invents an answer.
- **A customer is NEVER told the center ran out of a paid allowance.**
- **`Kernel\Usage` stays generic.** Resource codes are strings from
  `config/usage.php`; the Kernel must never learn what RAYAN or WhatsApp is, and
  an architecture test scans for those words.
- **Counted once** (`unique(resource, source_type, source_uuid)` +
  `insertOrIgnore`), **spent atomically** (one conditional UPDATE whose
  affected-row count is the answer). NULL means unlimited — no sentinels. Only
  `ai_runs` is enforced; tokens are metered and refuse nothing (ADR-072).
- **Increase now, decrease next period** unless an audited `enforce_immediately`.
  `AllowanceSync` repairs upward only, keyed on a VERSION not a value.
- **Provider destinations are platform config (ADR-071).** No URL column on any
  tenant table; `phone_number_id` is path-segment validated before it reaches a
  URL; a center never supplies a key or names an unapproved model.
- **Both adapters are implemented, neither is live-verified (ADR-073).** Say so.

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
- **Three gate tiers** (`docs/11-TESTING-STRATEGY.md` §9.1). The full suite takes
  about 3.5–4 hours locally, so it is not the per-change gate:
  - **FAST** — `composer check:fast` (Pint, PHPStan, Architecture, Unit) plus the
    module you touched, e.g. `php vendor/bin/pest tests/Feature/Payments`.
    Normal iterative development.
  - **PHASE** — `composer check:phase<N>` (FAST + TenantIsolation + the phase's
    modules + their direct regression files). Before closing a phase. Each phase
    REPOINTS the script; `check:phase15` is the current one (it replaced `check:phase14`).
  - **FULL** — `composer check` / `composer check:full`, unchanged. Release
    candidates, major architecture changes, CI/nightly, or explicit request.
    Not after every ordinary phase correction.
- **Tiers are chosen, never weakened.** Never delete, skip or loosen a test to
  make a tier faster, and never enable parallel Pest against the shared local
  server. When a lower tier is used, report the last FULL result honestly — never
  imply a full run passed that did not run.

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
composer check:fast     # FAST:  pint --test + phpstan + Architecture + Unit  (~3 min)
composer check:phase15  # PHASE: FAST + TenantIsolation + every Super Admin / Manager module
composer check          # FULL:  pint --test + phpstan + the whole Pest suite  (~3.5–4 h; CI runs this)
composer check:full     # alias of `composer check`
composer fix            # pint (write)
composer test           # pest (whole suite)
composer test:fast      # Architecture + Unit only
composer stan           # phpstan/larastan
```

```bash
php artisan metastyle:control:migrate
php artisan metastyle:tenant:provision "Center Name" --domain=center.test --slug=center-name
php artisan metastyle:tenant:migrate --all
php artisan metastyle:tenant:migrate --retry-failed
php artisan metastyle:tenant:status --drift
php artisan metastyle:roles:sync --all
php artisan metastyle:registration:retry <uuid>
php artisan metastyle:registration:sweep --dry-run
php artisan metastyle:idempotency:sweep
php artisan metastyle:reconcile --days=3
php artisan metastyle:notifications:sweep
php artisan metastyle:usage:project
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

- Ordinary change: run `composer check:fast` and the touched module's tests, and
  confirm they are green.
- Closing a phase: run `composer check:phase<N>` and `php artisan
  metastyle:doctor`, and confirm both are green.
- Run `composer check` (FULL) only for a release candidate, a major architecture
  change, CI/nightly, or when explicitly asked.
- Report failures with the actual output, and say which tier ran. Do not claim
  work is done unverified, and do not claim a FULL pass that did not happen.
