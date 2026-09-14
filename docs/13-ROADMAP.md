# 13 — Implementation Roadmap

> Status: **Phases 0–9 complete.** Phase 10 is next and not started.
> No phase begins without explicit authorisation.

## How to read this

Each phase lists **scope**, **exit criteria**, and **explicitly not in scope**.
The third column is as important as the first — it is what stops a phase from
absorbing the next one.

Phases are sequential by dependency, not by calendar. No durations are given:
estimating 17 phases before Phase 1 has started produces numbers that are wrong
and then get quoted.

**Rule: a phase is not done until its documentation and tests are done.**

---

## Phase 0 — Architecture & Documentation ✅

**Scope:** Repository inspection; product, architecture, tenancy, migration,
module, entitlement, auth, localization, security, storage, API, testing,
deployment and roadmap documentation; decision log; `CLAUDE.md`.

**Exit:** Documents reviewed; open questions in §"Locks required" answered.

**Not in scope:** Any code.

---

## Phase 1 — Laravel Foundation & Engineering Infrastructure ✅

**Scope**

- Laravel 12 skeleton on **PHP `^8.2`**; native local PHP + MySQL/MariaDB
  (no Docker requirement).
- **Blade + Livewire 3** foundation. No business UI.
- `App\Kernel` and `App\Modules` namespaces — created only where Phase 1 uses
  them, not scaffolded for every future module.
- `control` connection + `tenant` connection **template with no database
  assigned**.
- Migration directory split `database/migrations/{control,tenant}`;
  `metastyle:control:migrate` command.
- **Fail-closed tenant protection** (`02` §4) — guard plus tests, before any
  tenancy lifecycle exists.
- Request/correlation IDs; structured logging; exception handler; JSON error
  envelope and API response conventions (`10` §4).
- Pest 3, PHPStan/Larastan (level 5), Pint, `composer check`, CI workflow.
- **Multi-database test harness** (`11` §3) — creates two real tenant databases
  and proves isolation at the connection level.
- Architecture tests + the two justified safeguards (`11` §5).

**Exit**

1. `composer install` → `metastyle:control:migrate` → `composer check` works on
   a clean clone with only PHP and MySQL/MariaDB installed.
2. A test creates two real tenant databases and proves data written to one is
   invisible from the other.
3. A query on the `tenant` connection with no tenant initialised throws, and
   does not read the control database.
4. PHPStan level 5 clean; Pint clean.

**Not in scope:** tenancy lifecycle, provisioning, `stancl/tenancy` (Phase 2),
authentication, any business module, any business UI, Horizon, Redis
requirement.

> The test harness and the fail-closed guard are in Phase 1 on purpose. Both are
> cheapest to guarantee while there is nothing to break, and retrofitting tests
> onto the most safety-critical code in the system is how that code goes wrong.

---

## Phase 2 — Control Plane, Tenancy, Provisioning, Isolation ✅

**Scope**

- **`stancl/tenancy` v3.10** installed and confined behind the adapter (ADR-018).
- Control-plane schema: `tenants`, `domains`, `tenant_operations`,
  `platform_audit_logs`, `jobs`, `failed_jobs` (`03` §10.1).
- `Kernel/Tenancy` abstractions: `TenantContext`, `TenantResolver`,
  `TenantProvisioningService`, `TenantMigrator`, `TenantDatabaseName`,
  infrastructure adapters — plus the architecture test keeping `Stancl\*` inside
  `App\Kernel\Tenancy\Infrastructure`.
- Tenant resolution **by host**; conflict → `403` + security audit. Token
  binding is Phase 3, but the conflict rule is implemented and tested now.
- Isolation for database, storage, cache and queue.
- `Kernel/Storage`: `MediaStore`, `MediaCollection`, tenant-rooted disks.
- `Kernel/Audit`: writer, actor, redaction, correlation, append-only
  enforcement, tenant and platform logs.
- Minimum tenant schema: `settings`, `audit_logs`.
- Commands: `control:migrate`, `tenant:provision` (+ `--retry`),
  `tenant:migrate` (`--tenant`/`--all`/`--retry-failed`/`--limit`),
  `tenant:status` (+ `--drift`).
- Lock-protected, failure-isolated tenant migrations with per-tenant status.
- **`tests/TenantIsolation` — all 14 cases.**
- PHPStan raised to **level 6**.

**Exit** — met

1. ✅ Provisioning creates a tenant end to end and is resumable after failure.
2. ✅ A tenant migration failure marks one tenant failed and the run continues.
3. ✅ `tenant:status --drift` gates correctly.
4. ✅ All 14 tenant isolation cases pass against real databases.
5. ✅ Fail-closed holds: no tenant bound means the query fails, never falls back.

**Deferred, with the phase that needs them**

- Deprovisioning/archival (no tenant has been cancelled yet).
- Per-tenant backup and rehearsed restore — **before the first paying customer**.
- Load-testing table/connection pressure — before tenant counts approach the
  thousands (`12` §7).
- Queued/batched migration rollout — while runs take seconds, `--limit` is enough.

**Not in scope:** self-registration, authentication, entitlements enforcement,
any business module.

> This was the highest-risk phase. Everything after it assumes isolation is
> correct.

---

## Phase 3 — Registration, Trials, Auth, Employees, Roles, Permissions ✅

**Scope**

- **SaaS control plane**: `plans`, `plan_entitlements`, `subscriptions`,
  `tenant_entitlement_overrides`, `platform_settings`, `registrations`;
  `tenants.public_key` and `entitlements_version`.
- **Entitlements engine**: code catalog, plan grants, tenant grant/revoke
  overrides, dependency closure, cycle detection, version-stamped cache,
  `ensure()` callable from Actions, route middleware. Boolean type only.
- **Trials**: platform-setting default with plan and per-tenant overrides;
  expiry computed on read, so a lapsed trial is refused before any sweep runs.
- **Self-registration**: idempotent intake → queued provisioning → poll for
  `preparing | ready | failed`, retryable, never reporting success early.
- **Identity**: staff `users` in the tenant database, session and Sanctum
  guards, tenant-bound tokens (ADR-027), activation links, password change,
  token revocation.
- **Authorization**: code permission catalog, roles + custom roles, branch
  scope, no-escalation rule, Owner as explicit grants (ADR-029).
- **Employees**: profile separate from login, optional link, branch assignment,
  deactivation that actually cuts access.
- **Branches**: minimal main-branch foundation with a translatable name.
- **Localization**: `TranslatedText` + `Translatable` cast.
- **Minimal web UI**: register, status, login, shell, staff, roles.

**Exit** — met

1. ✅ A center self-registers and reaches a working, trial-scoped account.
2. ✅ Entitlement resolution covers plan grants, both override directions,
   dependency closure and cycles; `ensure()` enforces off-HTTP.
3. ✅ Permission × branch-scope behaviour is tested, including escalation
   refusal.
4. ✅ A Tenant A token is inert under Tenant B; a host/token conflict is
   refused and audited.
5. ✅ No plaintext or hashed credential reaches `jobs`, `failed_jobs`, audit or
   `tenant_operations`.

**Deferred, with the phase that needs them**

- Platform/Super Admin authentication — nothing uses it until SADMIN (Phase 16).
- The control-plane login directory — deferred in favour of the center key
  (`06-AUTH-ROLES-PERMISSIONS.md` §2.2.1).
- Limit and quota entitlement types — with the features that enforce them.
- Field-level masking — with Customers (Phase 5), which is the first PII.
- Idempotency-key infrastructure as a general API concern — registration has its
  own; the shared table lands with the first money-creating endpoint.
- Activation delivery by SMS/WhatsApp — Phase 13.

**Not in scope:** services, customers, CRM, booking, POS, queue, finance,
reports, WhatsApp, RAYAN, Flutter.

---

## Phase 3.1 — Core Hardening ✅

A short checkpoint before Phase 4, closing the infrastructure risks Phase 3 left
open while the code was still fresh. No business features.

**Scope**

- **Registration retry (ADR-031).** The bootstrap credential is kept — hashed
  and encrypted — while a failed registration can still be retried, and
  destroyed on every terminal outcome. Public retry endpoint, a "try again"
  button, a support command, and an hourly sweep. Phase 3 destroyed it on
  failure, which made every failed self-registration permanently unrecoverable.
- **System role synchronisation (ADR-032).** `metastyle:roles:sync`, now a
  deploy step: without it, a release that adds a permission leaves existing
  Owners silently without it. Custom roles, narrowed system roles and role
  assignments are never touched.
- **Middleware ordering (`06` §2.5).** A boot-time assertion that refuses to
  start if authentication could run before tenant resolution, plus behaviour
  tests that observe the running pipeline rather than the source — because the
  source looked correct while the ordering was wrong.
- **Production readiness (ADR-034).** `metastyle:doctor` fails a deploy on
  configuration that would otherwise fail silently: a per-process rate limiter,
  a non-taggable cache, an inline queue, `APP_DEBUG`, a missing `APP_KEY`.
- **Engine portability (ADR-033).** The Phase 4 database rule, enforced by an
  architecture test: no MySQL-8-only JSON functional or generated indexes until
  a measured query justifies one.

**Exit** — met

1. ✅ A failed registration is retryable and resumes without duplicating a
   tenant, database, subscription, branch, owner, role or grant.
2. ✅ The credential is destroyed on success, on retry-success, on cancellation
   and on sweep, and never reaches a log line, exception text, audit entry,
   queue payload or API response.
3. ✅ An existing owner gains a newly catalogued permission after a sync; repeat
   runs change nothing; one tenant's failure does not affect another; no tenant
   remains bound afterwards.
4. ✅ The credential lookup provably runs on the tenant connection with the
   expected tenant bound, and the boot guard rejects a misordered pipeline.
5. ✅ `metastyle:doctor --production` fails on a per-process rate-limit store,
   and the store it inspects is provably the one the rate limiter uses.

**Reported honestly:** the suite was **not** executed against MySQL 8. No MySQL
8 server and no container runtime is available on the development machine
(MariaDB 10.4.32 only), so CI remains the sole authority on MySQL 8 behaviour.
The workflow was verified by parsing it: valid YAML, `mysql:8.0` service on the
port and database the suite expects, and `composer check` as the single gate.
Migrations were reviewed by inspection and the divergences are now enforced by
`tests/Architecture/DatabasePortabilityTest.php` (`03` §7.2).

**Not in scope:** everything in Phase 4 and beyond.

---

## Phase 4 — Branches, Departments, Localization, Service Catalog, Menu ✅

**Scope**

- **Branches**: full operational record — contact details, translatable address,
  coordinates, public visibility, ordering, archive. **Working hours as one row
  per interval**, so split shifts (09:00–13:00, 16:00–22:00) and overnight
  opening are ordinary cases rather than schema exceptions. Date exceptions for
  closures and special hours.
- **Departments**: operational organisation (Hair, Laser, Hammam), deliberately
  distinct from menu categories (ADR-037).
- **Localization**: `TenantLocales` — each center enables its own languages and
  picks a default; `SetLocale` middleware implements the documented five-step
  chain; direction still derives from the language registry.
- **Service catalog**: categories, services, variations that **inherit** a null
  price or duration, shared add-ons, per-branch availability where "everywhere"
  is the absence of rows, and employee **eligibility** only.
- **Money**: `Kernel\Money` — integer minor units, IQD at exponent 0, string
  parsing so no float ever touches a price.
- **Media**: `media_items` over the Phase 2 `MediaStore`, validated on the bytes,
  tenant-isolated, with the row and the file deleted together.
- **Public electronic menu**: guest-accessible API and mobile-first page,
  resolved from the center's public key in the URL (ADR-036), rendering only
  explicitly public data through an allow-list resource.
- **Menu presentation**: a closed catalog of templates, theme options and
  sections, with draft → publish → rollback. No HTML, CSS or JS from a center
  (ADR-038).
- **Internal notes** on services and departments — the seam for the future Notes
  Engine, not the engine.
- **Permissions**: 13 new codes, catalog 13 → 26.

**Exit** — met

1. ✅ A center configures branches in two timezones with split and overnight
   hours; overlapping intervals are refused.
2. ✅ A service renders in `ar`, `en` and `ckb` with correct direction, never
   empty when any translation exists, and a disabled locale falls back silently.
3. ✅ The public menu is reachable with no account, leaks no internal field, and
   renders a 25-service catalog in a bounded number of queries.
4. ✅ A menu can be drafted without changing the live page, published, and rolled
   back; invalid templates, colours, sections and settings are all rejected.
5. ✅ Prices are integers in minor units; IQD refuses a decimal place.

**Deferred, with the phase that needs them**

- Branch-specific pricing — no requirement yet, and it turns the price list into
  a matrix (ADR-037).
- Booking buffers and preparation time — a field nothing reads is a promise;
  they arrive with the Booking Engine.
- A generic Notes Engine — seven of its nine owners do not exist yet.
- A control-plane `languages` table — config is adequate until SADMIN needs to
  add a language without a deploy.
- Custom CSS for higher tiers — deliberately not in the phase that first exposes
  a public page.

**Not in scope:** customers, CRM, booking, POS, queue, finance, reports,
WhatsApp, RAYAN, Flutter.

---

## Phase 5 — Customers, CRM & Optional Customer Accounts ✅

**Scope**

- **Customer**: the CRM record. Phone-first identity normalised to E.164, so
  `0750…`, `+964750…` and `964-750…` are one person. Tenant-wide, never
  branch-scoped. Archive, never delete.
- **CustomerAccount**: an optional login, one per customer at most, in a
  separate table with no roles and no permissions. **Guest is the absence of an
  account row**, so a guest becoming registered is an INSERT — never a second
  customer (ADR-041).
- **Guest → registered upgrade**: a signup finds the existing customer by
  normalised phone and links to it. Existing CRM data is never overwritten;
  genuinely empty fields are filled.
- **Authentication**: separate `customer` / `customer-api` guards over a
  separate provider. Sanctum's provider check makes the staff/customer boundary
  structural rather than remembered. Tokens stay tenant-bound (ADR-027).
- **`customer_accounts` entitlement** gates the customer's LOGIN surface only.
  Staff CRM is core and never gated (§26).
- **PII masking**: `customer.view` finds a customer; `customer.contact.view`
  shows how to reach them. One presenter serves the API and the web, and phone
  search requires the contact permission (ADR-042).
- **Notes**: `NoteOwner::Customer` — one enum case on the Phase 4 seam, exactly
  as predicted. Two visibility levels, `internal` and `manager_only`.
- **Tags and communication preferences**, including a consent timestamp.
- **Audit privacy**: keyed HMAC fingerprints, never a phone or email value, and
  never a note's body.
- **9 new permissions**, catalog 26 → 35.

**Exit** — met

1. ✅ One person is one customer across every spelling of their number; a
   duplicate is refused by name rather than silently merged or duplicated.
2. ✅ A guest created by reception keeps the same customer id after signing up.
3. ✅ A Tenant A customer token is inert under Tenant B; a customer token cannot
   reach a staff endpoint, nor a staff token a customer one.
4. ✅ Contact details are masked identically on the API and the web, from one
   implementation; phone search is refused without the permission.
5. ✅ No customer phone, email, password or note body appears in any audit row.

**Deferred, with the phase that needs them**

- **Phone verification** — no SMS/WhatsApp provider until Phase 13. The column
  exists, nothing writes it, and a test enforces that (ADR-040).
- **A unified CRM timeline** — bookings, sales, invoices and reviews do not
  exist, and a timeline of three event types would duplicate the audit log.
  The profile shows notes and account status; the seam is the customer uuid.
- **Customer merge tooling** — normalised phone identity prevents the common
  duplicate. Merge needs referencing modules to move records between.
- **Import / export** — belongs with Reports (Phase 12).
- **Anonymisation / erasure** — needs per-module rules (which invoices must
  keep, which reviews become anonymous). The path is kept open by holding PII
  in one table behind one uuid.
- **Contextual "customers I served" access** — needs a service history, which
  arrives with Booking (§21).

**Not in scope:** booking, loyalty, memberships, packages, POS, queue, finance,
reviews, campaigns, notifications, WhatsApp, RAYAN, reports.

---

## Phase 6 — Central Booking Engine & Calendar ✅

Full rules: **`docs/15-BOOKING.md`**.

**Scope**

- **One Booking Engine**, behind `Booking\Contracts\BookingEngine`:
  availability, book, reschedule, cancel, transition. Every channel is an
  adapter; three architecture tests keep it that way.
- **Appointment → AppointmentItems.** A visit is one arrival by one person
  carrying one or more services, sequential and back to back.
- **Snapshots** of price, duration, currency and translatable name on every item
  and add-on. Tomorrow's price change cannot rewrite yesterday's booking.
- **Availability** from branch hours (split shifts, date exceptions, overnight),
  service and variation durations, add-ons, employee eligibility, branch
  assignment, active status, and existing conflicts.
- **`Kernel/Time`** — store UTC, compute in the branch timezone, refuse a local
  time that does not exist across a DST gap.
- **Concurrency** by a `lockForUpdate()` on the branch row inside the booking
  transaction, with the authoritative re-check under it (ADR-044).
- **Five statuses** with an explicit transition map. Completion creates nothing
  financial.
- **Guest, staff and customer-account booking**, all resolving to one customer
  per person.
- **Idempotency** — one implementation, API middleware and the public form.
- **Staff calendar** (day/week, Livewire) and a **no-JavaScript public booking
  flow**.
- **9 permissions**, catalog 35 → 44. The `booking` entitlement enforced in the
  Actions.
- **Phase 5 follow-ups**: credential throttling moved into the Action and keyed
  by the account under attack — `AuthenticateCustomer` and `AuthenticateStaff`
  share one `LoginThrottle`, so the two forms and the two token endpoints fill
  the same tenant-scoped buckets; an explicit note advisory on every notes
  surface.
- **`metastyle:idempotency:sweep` scheduled hourly**, fault-isolated per center
  so one unreachable database cannot leave the rest unswept.

**Exit** — met

1. ✅ One engine serves the staff, public and customer paths; no availability
   logic exists outside it, and an architecture test proves it.
2. ✅ Two requests for one slot → exactly one succeeds; a second real database
   connection cannot cross the lock while the first holds it.
3. ✅ Every booking rule has a passing and a failing test.
4. ✅ Timezone tests pass across two branches and both DST transitions.
5. ✅ Idempotency prevents duplicate bookings on client retry, on the API and on
   the public form.

**Deferred, with the phase that needs them**

- **`quote()`** — needs deposits, cancellation fees and a payment provider.
  Arrives with Payments (Phase 9).
- **Buffers between appointments, per-service lead times, deposits,
  cancellation fees, no-show penalties** — a policy module built on guesses is
  one nobody can configure. Five settings ship; the rest need a center to ask.
- **"Own appointments only" scope** — the current RBAC expresses branch scope
  cleanly. The seam is `Employee.user_id` plus a future `AppointmentScope`.
- **Automated reassignment after a deactivation** — the affected-appointments
  query ships; moving customers to a different stylist is a decision a center
  makes (§21).
- **Parallel services, gaps, handoffs** — Service Journey (Phase 7). Item
  windows are stored per item, so a later phase writes different windows without
  a schema change.
- **A short human booking reference** — a uuid is unusable over the phone, and
  the WhatsApp and reminder flows that need one arrive in Phase 13.
- **Availability caching** — correct beats fast until there is a measured
  problem and a proven invalidation story.

**Not in scope:** Service Journey, queue, rooms and resources, POS, payments,
deposits, finance, loyalty, reviews, notifications, WhatsApp, RAYAN, reports.

> The engine's contract is fixed here. Phases 8, 9, 13 and 14 are adapters to it.

---

## Phase 7 — Service Journey, Resources & the Operational Board ✅

Full rules: **`docs/16-JOURNEY-RESOURCES.md`**.

**Scope**

- **Resources as an L1 module** (ADR-049): tenant-defined `ResourceType`,
  physical `OperationalResource` with CAPACITY, and per-service requirements
  defined against the type rather than a particular machine.
- **Resource-aware availability.** Capacity is a PEAK, not a sum
  (`Kernel\Time\Occupancy`), re-checked authoritatively inside the booking
  transaction. Concrete reservations are snapshotted onto the item.
- **One branch lock for everything that changes bookability** (ADR-047) —
  booking, resource edits, service requirements and availability blocks now
  queue behind each other instead of racing.
- **Employee availability blocks** — breaks, training, personal time. Not
  attendance; the seam for attendance is documented.
- **Service Journey**: check-in, stages derived from booked items, actual
  employee and actual resource usage, handoffs, abort, completion through the
  Booking lifecycle Action.
- **Actual resource usage as INTERVALS**, so a mid-service swap reads "Laser 1
  until 10:15, Laser 2 from 10:15" rather than "both were involved".
- **Runtime capacity counts actual use AND committed reservations** (ADR-050) —
  a room promised to somebody at 10:30 is not free for an early start at 10:10.
  One combined peak, under the same branch lock, with neither customer counted
  twice.
- **Non-contiguous and parallel item layouts**, staff only, with opening hours
  validated per item rather than against the visit's span.
- **The own-schedule scope** Phase 6 deferred — `AppointmentScope` over
  `Employee.user_id`, never a role-name check.
- **Staff operational board** and resource management screens, Blade + Livewire,
  polling rather than WebSockets.
- **12 permissions**, catalog 44 → 56. No new entitlement: Journey is the
  operational half of `booking`.

**Exit** — met

1. ✅ A multi-stage journey runs with handoffs and per-stage attribution, and the
   actual employee may differ from the booked one without rewriting the booking.
2. ✅ Resource conflicts are prevented by the Booking Engine, capacity is peak
   based, and exactly one contender takes the last unit.
3. ✅ Journey never writes appointment status; three architecture tests enforce
   the module boundaries.
4. ✅ Blocked time leaves availability; existing bookings inside a new block are
   surfaced, never moved.
5. ✅ Repeated and concurrent check-in produce exactly one journey.
6. ✅ An operational start or resource swap cannot take capacity another booking
   already holds, and never mutates that booking to get it.

**Deferred, with the phase that needs them**

- **Walk-in visits with no appointment** — `service_journeys.appointment_id` is
  NOT NULL. A walk-in needs that column nullable plus a customer-resolution path,
  and it arrives with Queue, which is what walk-ins are actually about.
- **A privileged eligibility override on reassignment** — Phase 7 rejects an
  unqualified employee. The override needs a center to have asked, so its rules
  and its reason field are not guessed at.
- **Automated reassignment after a resource is retired** — the affected query
  ships; moving customers is a decision a center makes.
- **Real-time board updates** — polling until there is a stated requirement.
- **Resource maintenance windows, cleaning buffers, room turnaround** — real
  requests that need a center to describe them.

**Not in scope:** queue tickets and displays, POS, payments, deposits, finance,
loyalty, reviews, notifications, WhatsApp, RAYAN, reports, attendance/payroll.

> Planned and actual are separate here, permanently. Every later phase that
> reports on a visit depends on both existing.

---

## Phase 8 - Walk-ins, Queue, Display, Voice & Ticket Printing

Full rules: **`docs/17-QUEUE.md`**.

**Scope**

- **Walk-in visits** (ADR-051): `service_journeys.appointment_id` is nullable, a
  walk-in carries its own customer and branch, and each stage carries its own
  service snapshot. No fake appointments anywhere.
- **Queue tickets** built ON TOP of Journey: `queue_tickets`,
  `queue_ticket_events`, `queue_sequences`, `queue_service_points`,
  `queue_displays`. Four concepts stay separate - reservation, visit, service,
  waiting mechanism.
- **Human-readable numbers** from a locked sequence row per branch, per
  BRANCH-LOCAL day, per prefix. Never MAX+1, never a process counter.
- **A six-state machine** whose `serving` and `completed` are Journey to give -
  no queue Action writes either (ADR-053).
- **A synchronous, in-transaction synchronizer** that keeps a ticket and its
  stage from ever disagreeing, from whichever board the operator used.
- **Every mutating Action locks its ticket** `FOR UPDATE` and appends exactly one
  history row whose sequence is allocated under that lock.
- **Service points** as explicit destinations, optionally linked to a resource
  and never equated with one.
- **A public display** - read-only, unauthenticated, allow-listed, polling, with
  a stable announcement identifier so a screen speaks once per call.
- **Multilingual announcements** as semantic payloads, with the Kurdish Sorani
  limitation stated rather than papered over.
- **80mm ticket printing** through a render seam, with no PII on the paper.
- **6 permissions**, catalog 56 -> 62. Entitlements `queue_management`,
  `queue_display`, `queue_voice` - in the catalog since Phase 3, enforced from
  Phase 8, and in **no plan**: defining a capability and pricing it are separate
  decisions, and this phase only owns the first (docs/05 §9).

**Exit** - met

1. Yes. A walk-in becomes a visit, stages and a number without an appointment
   existing, and the booking tables are untouched.
2. Yes. Two simultaneous issues cannot share a number, and two desks cannot
   perform incompatible transitions on one ticket.
3. Yes. Starting or finishing from the visit board and from the queue board
   produce identical events and identical state.
4. Yes. A queue skip leaves the stage alone; a stage skip closes the ticket.
   They are never confused.
5. Yes. The public feed carries a number and a destination and nothing that
   could identify the customer holding it.
6. Yes. A walk-in cannot take resource capacity a future booking already holds.

**Deferred, with the phase that needs them**

- **Real-time push.** Polling was chosen deliberately (ADR-052); the domain
  events exist so an adapter can listen outward when there is a stated
  requirement and a deployment story for it.
- **Recorded-audio or server-side TTS announcements** - the answer for Kurdish
  Sorani, which browsers cannot speak. Needs a cost decision.
- **Kiosk self-service check-in** - a customer taking their own ticket.
- **Cross-branch transfer**, which needs a design nobody has written.
- **Resource turnaround buffers**, still waiting on a center to describe them.
- **Ticket pre-booking** - handing a number to an appointment before arrival.

**Not in scope:** POS, invoices, payments, deposits, finance, commissions,
loyalty, memberships, packages, reviews, notifications, WhatsApp, RAYAN, reports,
attendance/payroll.

> The queue is a mechanism for getting a customer to the right place. It never
> becomes the record of what happened to them.

---

## Phase 9 — POS, Sales, Invoices, Cashier Shifts & Printing

Full rules: **`docs/18-SALES.md`**.

> **Entitlement decision, locked at the start of Phase 9.** There is no
> `invoices` key and there will not be one. Issuing an invoice is a core
> consequence of a POS sale, not a separately sold module:
>
> | Key | Owns |
> |---|---|
> | `pos` | POS, sales, checkout, invoice issuing, products, cashier shifts — new operations; issued invoices stay readable without it |
> | `printing` | the printable invoice / receipt surfaces (80mm, A4) |
> | `payments` | Phase 10 — payment processing and gateways |
> | `finance` | Phase 10 — center finance, accounting and reporting |
>
> Package assignment is unchanged by this phase: it defines capabilities, not
> pricing (docs/05 §9).

**Scope** (entitlement-gated: `pos`, `printing`)

- **Sale** — the commercial transaction, `draft → finalized → voided`. A draft
  IS the cart. No payment state.
- Sale lines for services (with variation and add-ons), products, and
  permission-gated custom lines — each a SNAPSHOT of what was charged.
- **Journey checkout**: completed stages become candidate lines priced from the
  visit's own snapshot. Skipped stages are never charged automatically. A draft
  may be prepared mid-visit; it is finalized only once the visit is completed,
  carrying every performed service (ADR-057).
- Direct POS sales with no visit and no appointment.
- Manual adjustments only — fixed and percentage discounts, fixed surcharges —
  and explicit, audited per-line price overrides.
- **Invoice** — an immutable published document with its own snapshots,
  issued atomically from a finalized sale.
- Invoice numbering per branch per branch-local calendar year, on the locked
  sequence pattern Queue proved.
- A lightweight **cashier shift** that attributes every finalized sale.
- A minimal **product** catalog: name, SKU/barcode, price. No inventory.
- A read-only customer digital invoice behind a rotatable high-entropy token,
  stored only as its SHA-256 digest.
- Browser-printed 80mm and A4 invoices, RTL-safe.

**Exit**

1. Two desks finalizing one sale produce exactly one invoice; two sales
   finalized at once at one branch receive distinct numbers.
2. Changing a service or product price does not alter a finalized sale, its
   invoice, its printout or its digital invoice.
3. RTL renders correctly in the browser, the 80mm printout and the A4 printout
   (three separate checks). PDF is not a Phase 9 surface — see ADR-056.
4. A sale reconciles to its originating visit without rewriting that visit.
5. An invoice is never published for an unfinished visit, and a failed audit
   write never leaves a published invoice behind (ADR-057).

**Removed from the original Phase 9 list, deliberately**

- Promo codes, loyalty summary, package balance, deposits — Phase 11 and
  Phase 10 concepts respectively.
- Tips, split and partial payment — settlement belongs with Payment (Phase 10).
- Taxes — no tax requirement exists; a zero `tax_total` seam only.
- PDF and QR — both need a dependency; not installed without a decision.
- `Kernel/Templates` — it does not exist, and a generic template engine is not
  built on speculation. Fixed Blade templates behind a Sales-owned renderer.
- WhatsApp send — Phase 13. Network printers — later, behind the render seam.

**Not in scope:** Payment gateways, refunds, settlement, reconciliation, finance
reporting, commissions, loyalty, memberships, promotions, reviews, notifications.

---

## Phase 10 — Payments & Finance

**Scope** (entitlement-gated: `payments`, `finance`)

- Payment adapter contract; per-tenant encrypted merchant credentials.
- Iraqi providers (ZainCash, FIB, Qi, FastPay) — subject to the account and
  documentation lock in §"Locks required".
- Cash, online, card, deposits, prepayment, partial, split, refund, partial
  refund, reconciliation.
- Finance: revenue by type, expenses, discounts, refunds, commissions, cash
  register shifts, payment-method reports, profit.
- Strict separation from SaaS billing.

**Exit**

1. Funds settle to the **center's** merchant account.
2. No prohibited payment field exists anywhere (CI-enforced).
3. Webhooks are signature-verified, replay-protected and idempotent.
4. Finance reports reconcile to invoices and payments to the minor unit.

**Not in scope:** Advanced analytics.

---

## Phase 11 — Reviews, Loyalty, Memberships, Packages

**Scope** (entitlement-gated per feature)

- Review tokens: opaque, single-use, expiring, **no PII in the QR**.
- Verified ratings tied to completed visits/invoices: overall, per service,
  per employee, optional dimensions.
- Low-rating follow-up workflow.
- Loyalty: points, visit- and spend-based rewards, tiers, referrals.
- Memberships, packages, remaining sessions, expiry, gift cards.
- POS integration for sale and redemption (through owning modules).

**Exit**

1. A review link resolves customer, visit, invoice, services and employees
   server-side from an opaque token.
2. A rating cannot be submitted without a genuine completed visit.
3. Package redemption goes through `Packages`, never a direct decrement.

**Not in scope:** Marketing campaigns.

---

## Phase 12 — Reports, Exports, Advanced Reports

**Scope**

- Standard reports: sales, bookings, employees, customers, services, finance,
  reviews, queue.
- Export pipeline: permission → masking → queued build → tenant storage →
  signed URL → audit. PDF, Excel, CSV, print.
- Scheduled reports.
- `reports_advanced`: period and branch comparisons, employee comparison,
  service profitability, retention, cohorts, LTV, booking funnel, occupancy,
  no-show analysis, queue bottlenecks, revenue per employee/room/chair/device,
  custom dashboards, forecasts.
- Read replica routing for reporting.
- Audit table partitioning.

**Exit**

1. Every export is masked, permission-checked and audited with row counts.
2. Advanced reports run against the replica without touching the operational
   path.
3. Report documents are brandable through `Kernel/Templates`.

---

## Phase 13 — Notifications, WhatsApp, WhatsApp Booking

**Scope** (entitlement-gated: `whatsapp_integration`, `whatsapp_booking`)

- Notification engine: in-app, push, email, SMS, WhatsApp.
- Super Admin targeting: all / selected / one tenant, roles, employees, plan and
  entitlement groups.
- Tenant → customer targeting within permissions and entitlements.
- Per-tenant WhatsApp Business connection; localised, tenant-customisable
  templates.
- WhatsApp booking bot: identify, browse, select, availability, book, confirm,
  reschedule, cancel, deposit link, upcoming bookings, rebook, package balance,
  invoices, reminders, review requests.
- AI → human host handoff.

**Exit**

1. The bot calls `BookingEngine` for every booking action; a code search finds
   **zero** booking rules in the WhatsApp module.
2. Entitlement and permission checks apply on the bot path exactly as on HTTP.
3. Webhook delivery is idempotent under at-least-once delivery.
4. Messages are localised per recipient.

---

## Phase 14 — RAYAN AI

**Scope** (entitlement-gated: `rayan_ai`, `whatsapp_ai`)

- Conversational assistant with roles: Customer, Host, Employee, Manager, Support.
- Tool-calling architecture: the model interprets intent and **requests**
  actions; domain engines validate and execute (`14-FUTURE-INTEGRATIONS.md` §4).
- Every mutating AI action is attributable (`Principal{type: ai}`) and audited.
- Confirmation required for destructive or financial actions.
- `ai_requests` quota metering.

**Exit**

1. No AI code path writes to a database directly.
2. An AI action denied by permission or entitlement fails exactly as a human's
   would.
3. Every AI mutation appears in the tenant audit log with the prompt reference.

---

## Phase 15 — Support Tickets & Landing CMS

**Scope**

- Tickets: Center→Platform, Customer→Center, Customer→Platform. Category,
  priority, status, assignment, attachments, internal notes, timeline, SLA,
  optional AI classification/summary.
- Landing CMS in the control plane: hero, features, pricing, apps, screenshots,
  RAYAN, testimonials, FAQ, video, statistics, CTA, contact. Add, delete, hide,
  duplicate, reorder, schedule. Multilingual.

---

## Phase 16 — Flutter: Meta Style App & SADMIN

**Scope**

- Meta Style App (staff): login directory + center picker, bookings, calendar,
  queue, service journey, POS-lite, customers, notifications.
- Meta Style SADMIN: tenants, plans, entitlements, overrides, impersonation,
  support, health, notifications, CMS.
- Generated API clients from OpenAPI. Offline-tolerant behaviour where sensible.

**Exit:** Both apps consume the existing API with **no backend endpoint added
purely for a client's convenience.**

---

## Phase 17 — White Label Customer App

**Scope**

- Per-tenant branding bundle: name, logo, colours, icons, splash, store assets.
- Build and release pipeline; store submission tracking.
- Compiled-in tenant public key; same `/api/v1/public/*` surface.
- `white_label_app` entitlement.

**Exit:** A branded app runs against the shared backend with **zero** backend
forks, zero tenant-specific endpoints, and zero conditional logic keyed to a
tenant id.

---

## Decision status

**Locked 2026-09-01, before Phase 1:**

| Decision | Locked value | ADR |
|---|---|---|
| Repository layout | `Meta_Style_Web` = backend + all web surfaces. Flutter apps get separate repos later. | ADR-017, ADR-019 |
| Web frontend | Blade + Livewire 3 | ADR-020 |
| PHP requirement | `^8.2` | ADR-022 |
| Tenancy implementation | `stancl/tenancy` behind Meta Style abstractions | ADR-018 |
| Local environment | Native PHP + MySQL/MariaDB; Docker optional | ADR-023 |
| Queue dashboard | Horizon deferred until justified | ADR-021 |

**Still open, by phase:** ADR-007 (RBAC, Phase 3) · hosting and year-one tenant
count (Phase 2) · SADMIN same app or separate (Phase 3) · Iraqi VAT and legal
invoice requirements (Phase 9) · payment provider accounts (Phase 10) ·
WhatsApp direct vs BSP (Phase 13) · Sorani TTS availability (Phase 8).

See the pending-decisions table in `DECISIONS.md`.
