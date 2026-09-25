# 13 — Implementation Roadmap

> Status: **Phases 0–14 complete; Phase 15 implemented and in local closure.** Phase 12 (reviews, ratings, review
> capability links, in-app notifications) and Phase 13 (booking verification,
> the WhatsApp channel, RAYAN and usage quotas) each closed on a green FULL
> suite. Phase 13's closure covers code, architecture and automated
> verification; its two provider adapters have **not** run against live
> credentials, and that is tracked separately in its section below. Phase 14
> delivered Standard/Advanced Reports. Phase 15's approved typography assets
> are still required before it can be marked closed. Phase 16 has not started.
>
> Phase numbering note: Phase 13 below is new. The former Phase 14 (WhatsApp)
> and Phase 15 (RAYAN) are what it delivered, so Reports moved from 13 to 14 and
> everything after it shifted: Support/CMS 16→15, Flutter 17→16, White Label
> 18→17. The cross-references in the other documents moved with them.

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

- Platform/Super Admin authentication — nothing uses it until SADMIN (Phase 17).
- The control-plane login directory — deferred in favour of the center key
  (`06-AUTH-ROLES-PERMISSIONS.md` §2.2.1).
- Limit and quota entitlement types — with the features that enforce them.
- Field-level masking — with Customers (Phase 5), which is the first PII.
- Idempotency-key infrastructure as a general API concern — registration has its
  own; the shared table lands with the first money-creating endpoint.
- Activation delivery by SMS/WhatsApp — Phase 14.

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

**Manager services library (Phase 15 Manager, `/manager/catalog`)**

- A library, not a table: a category rail (All · each live category with its
  live-service count · Uncategorised · archived categories to restore) beside
  ordered service lists. The unfiltered "All" view is one list per category so
  a service can be dragged from one category to another, or dropped on a
  category in the rail (appended). Rows show the cover photo, the name in the
  VIEWER's language (then the center's primary, then any), duration, price
  (`from` when active variations differ) and status badges.
- **Ordering is one intent per call** (`MoveServiceCategory`, `MoveService`,
  `CatalogOrdering`): the Action locks categories then services, re-derives the
  order from the rows, clamps, renumbers and audits (`catalog.category.moved`,
  `catalog.service.moved`). A service's `sort_order` is its position in the
  WHOLE library — categories in order, uncategorised last — so every flat list
  ordered by `(sort_order, id)` (till, calendar, queue) groups services the way
  the owner arranged them. Filtered lists are never sortable. Drag handles,
  arrow keys and visible Move up / Move down buttons all call the same Action.
- New services and categories append; an edit keeps its place (moving to
  another category appends there). `ServiceInput::$sortOrder` /
  `SaveServiceCategory $sortOrder` null = "the library decides".
- `DuplicateService` copies a service INACTIVE and HIDDEN, right after the
  original, with its active variations (null still inherits), add-ons, branch
  and staff links; the page copies resource requirements through Resources in
  the same transaction. Images and notes are not copied.
- `SetServiceStatus` switches active / on-menu / online booking without a full
  save. `SaveServiceCategory::restore()` and `ArchiveService::restore()` return
  things inactive and hidden, appended.
- **A variation is never deleted**: one left out of a save is deactivated
  (package items reference variations with RESTRICT). The editor switches saved
  variations off; only unsaved rows can be removed.
- The service drawer loads and sends back every field (descriptions, online
  booking, position), texts per ENABLED content language (`x-ui.lang-tabs`),
  prices through `Money::fromMajorString` after Arabic-Indic digit
  normalisation, photos through `StoreMediaItem`/`ManageMedia` (gallery of 8,
  first = cover; a category has one image), and resource requirements through
  `SetServiceResourceRequirements` when the viewer holds `resource.manage`.
- Permissions: `service.view` to open the page (403 otherwise),
  `service.create|update|archive`, `category.manage`, `media.upload`,
  `resource.view|manage` — each re-checked by its Action. A photo change needs
  `media.upload` AND the right to edit its owner (`service.create|update`, or
  `category.manage` for a category image). Quick toggles (`SetServiceStatus`)
  decide on the locked row, so an archived service is never switched back on.

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

- **Phone verification** — no SMS/WhatsApp provider until Phase 14. The column
  exists, nothing writes it, and a test enforces that (ADR-040).
- **A unified CRM timeline** — bookings, sales, invoices and reviews do not
  exist, and a timeline of three event types would duplicate the audit log.
  The profile shows notes and account status; the seam is the customer uuid.
- **Customer merge tooling** — normalised phone identity prevents the common
  duplicate. Merge needs referencing modules to move records between.
- **Import / export** — belongs with Reports (Phase 13).
- **Anonymisation / erasure** — needs per-module rules (which invoices must
  keep, which reviews become anonymous). The path is kept open by holding PII
  in one table behind one uuid.
- **Contextual "customers I served" access** — needs a service history, which
  arrives with Booking (§21).

**Not in scope:** booking, loyalty, memberships, packages, POS, queue, finance,
reviews, campaigns, notifications, WhatsApp, RAYAN, reports.

**Manager CRM (Phase 15 Manager, `/manager/customers`)**

- **List**: search by name, any spelling or part of a phone, or email (contact
  matching only with `customer.contact.view`, as before); filters for status
  (active/archived), account (registered/guest), visits (has / never — a
  COMPLETED visit in a branch the viewer may see) and tag; 25 per page; a
  "last visit" per row from two grouped reads of `service_journeys` by table
  name (the `SqlCustomerReportReader` precedent — Customers imports no module
  above it). **The search is never in the URL** (it may hold a phone or email);
  the filters are.
- **Form** (shared by list and page, `EditsCustomer`): the shared phone picker
  (`x-ui.phone`, Iraq default). `CustomerInput::$phoneCountry` makes
  `SaveCustomer` read the number with `PhoneNumber::fromParts()`; the API keeps
  `parse()`. A number that belongs to someone raises `DuplicateCustomerPhone`
  (still a 422 `ValidationException` on `phone`) naming the owner's uuid, and
  the form offers to open that record — link, never duplicate (ADR-041).
- **Customer page** `/manager/customers/{uuid}` (`Customers\Profile`): header
  with status, account and tags; Edit, Archive/Restore and the **login switch**
  `SetCustomerAccountStatus` (`customer.account.manage`, audited
  `crm.customer_account.activated|deactivated`, refused for an archived
  customer or a guest) — so a restored customer's login can be turned back on.
  Tabs, each behind its own permission and re-authorised by its panel:
  overview · bookings (`CustomerProfileAppointments`, `appointment.view`) ·
  visits (`CustomerProfileVisits`, `journey.view`) · purchases
  (`CustomerProfileSales`, `sale.view`, issued only, never `pos`) · loyalty and
  memberships/packages (`CustomerBenefitsPanel`) · reviews (`ReviewsQuery`
  `customer` filter, `review.view`; the compact plan notice alone when the
  center never collected a review and does not own `reviews`) · notes
  (`NotesPanel`: author and date from
  `CustomerPresenter::notesFor()`, add at a visibility, delete with
  `customer.note.manage`, bodies never audited). Each history read applies the
  viewer's branch scope in SQL; a uuid from another center is a 404.

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
  the WhatsApp and reminder flows that need one arrive in Phase 14.
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
- WhatsApp send — Phase 14. Network printers — later, behind the render seam.

**Not in scope:** Payment gateways, refunds, settlement, reconciliation, finance
reporting, commissions, loyalty, memberships, promotions, reviews, notifications.

---

## Phase 10 — Payments, Gateways, Refunds, Cashier Reconciliation & Center Finance

Full rules: **`docs/19-PAYMENTS.md`** and **`docs/20-FINANCE.md`**. Decisions:
ADR-058, ADR-059, ADR-060.

> **Entitlements: the Phase 9 keys, unchanged, and no package changed.** `pos` =
> cash and staff-confirmed transfers at the desk (cash never needs `payments`);
> `payments` = online payments, gateway configuration, provider refunds;
> `finance` = expenses, counted closes, the dashboard.

**Scope, as built**

- **Payment** — one attempt against one issued invoice; split = several payments;
  `pending → succeeded | failed | cancelled`. Sales holds no payment state and
  never imports Payments.
- Desk collection: cash (the collector's open shift) and staff-confirmed manual
  transfers.
- Online payments through a branch's own merchant account; a pending payment
  **reserves** its amount. Settlement only from a verified callback or an
  authenticated status query, with amount and currency matched.
- Provider contract with explicit capabilities. **FIB** adapter from its
  published documentation (no sandbox transaction yet); **ZainCash, Qi, FastPay**
  explicitly unsupported.
- Refunds as separate rows, partial and repeated, never reopening the invoice;
  provider refunds only where the adapter declares them.
- Voiding a sale with money on it is refused through a neutral Sales contract.
- Paying the remaining balance from the customer's invoice link.
- Finance: an append-only ledger written in the money's own transaction;
  expense categories and expenses (voided with reversals); opening cash and a
  counted shift close with expected cash and variance; a dashboard of invoiced,
  collected, refunded, expenses and net movement.
- Strict separation from SaaS billing; no platform custody, payouts or commission.

**Exit**

1. Funds settle to the **center's** merchant account — the platform holds none.
2. No prohibited payment field exists anywhere (CI-enforced).
3. Callbacks are verified before any write, replay-protected and idempotent.
4. The ledger reconciles to payments and refunds to the minor unit: one entry per
   succeeded movement, committed with it.
5. Cash never needs `payments`; a downgrade never strands money already moving.

**Removed from the original Phase 10 list, deliberately**

- **Card / terminal integrations** — no provider or terminal was verified.
- **Deposits and prepayment** — they need money before an invoice exists, which
  contradicts "a payment settles an issued invoice"; a later phase with a design.
- **Revenue by type, commissions, profit, payment-method reports** — no cost of
  goods, commission rules or tax engine exist; Phase 10 reports money movement and
  never calls it revenue. Advanced reports are a later phase.
- **Daily reconciliation against provider statements** — no provider statement
  API was verified.
- **ZainCash, Qi, FastPay adapters** — unsupported until their documentation and
  sandbox access are verified (P-09).

**Not in scope:** Loyalty, memberships, promotions, reviews, notifications,
WhatsApp, RAYAN AI, advanced reports, SaaS billing.

---

## Phase 11 — Loyalty, Memberships & Service Packages ✅

Full rules: **`docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md`**. Decisions: ADR-061,
ADR-062, ADR-063, ADR-064, ADR-065.

> **Entitlements: existing keys, no package changed.** `loyalty`, `memberships`,
> `packages` — none sold by a seeded plan. Losing one stops NEW operations; paid
> memberships and packages stay usable until their own expiry.

**Scope, as built**

- **Loyalty** — points from money actually COLLECTED (never an invoice) under a
  per-invoice frozen rule, and from completed visits; redemption at checkout on a
  draft through Sales' benefit seam; refund reversals that never make a balance
  negative, with unrecovered points settled from later earnings; tiers from
  qualifying lifetime points; expiry snapshotted per credit, FIFO, lazy; manual
  adjustments with a reason.
- **Memberships** — plans (translated, priced, `duration_days`, discount
  benefits per service or all services, optional uses per term) sold through the
  till; activated when the invoice is settled; renewals stack; benefits applied
  to service lines.
- **Service packages** — definitions of prepaid sessions, sold through the till,
  activated on settlement, consumed by performed service on the bill (never by
  booking), partial coverage per unit, derived expiry, cancellation with
  forfeited sessions as history.
- **Money first** — every benefit reaction to money runs after the money commits
  and is repaired by `metastyle:reconcile`; reads never write.
- Sales seams used by all three: `SaleBenefits`, line-targeted discounts in
  `SalePricing`, `OfferingCatalog`, `SaleFinalizationGuard`, sale events.
- Functional surfaces: staff API, loyalty / memberships / packages pages, the
  till's benefits panel, the customer page's panel, the customer API and account
  page section (AR / EN / CKB).

**Exit**

1. No benefit failure can roll back or misreport a payment or refund; a lost
   reaction is repaired exactly once from the facts.
2. No points for unpaid, pending, failed, voided or refunded money; no balance
   below zero; no retroactive points.
3. Package use goes through `Packages`, from history, never a direct decrement.
4. Tenant isolation, concurrency (second connection) and idempotency proven.

**Hardening, after the phase gate (ADR-064, ADR-065)**

- **A repair reproduces the event-time result.** `loyalty_rule_versions`
  (append-only, effective-from) and `loyalty_earning_observations`; earning never
  reads the current program row. The rule, the date and the expiry come from the
  event's own instant, and the observation answers only the entitlement — so a
  recovery still works when the observation was never written, or when every one
  of them is gone.
- **An entitlement gap neither awards nor destroys.** Money collected while
  `loyalty` was owned stays recoverable after a downgrade; money collected during
  a gap never earns when it is granted back.
- **A benefit on a draft is a hold that expires.** `ReleaseStaleBenefits`, hourly:
  24 untouched hours returns the points, sessions or uses and re-prices the draft.
  Everything its unlocked query decided is re-checked under the sale lock, so a
  release and a finalization can never both consume the same benefit.
- **A package session needs proof of performance.** A visit line's stage must be
  `completed`; a till line needs an explicit staff confirmation, recorded in the
  audit row.

**Closed** — 2026-09-20

| Gate | Result |
|---|---|
| PHASE (`composer check:phase11`) | 597 passed, 3 093 assertions, 0 failed, exit 0 |
| Hardening + durability suites | 291 passed, 1 969 assertions, 0 failed, exit 0 |
| `composer check:fast` | Pint pass · PHPStan level 6 clean · 220 passed, 648 assertions, exit 0 |
| `php artisan metastyle:doctor` | All checks passed, exit 0 |

FULL (`composer check`) was deliberately not run for Phase 11; the last full run
predates it and is not claimed as covering this work.

**Moved out of Phase 11, by instruction**

- **Reviews, ratings, review tokens / QR, low-rating follow-up** — Phase 12.
- **Referrals and gift cards / stored value** — not started.
- **Promo codes and promotions** — not started.

The last two remain unscheduled until a later phase is authorised.

**Not in scope:** Marketing campaigns, notifications, WhatsApp, RAYAN AI,
advanced reports, tier multipliers, branch-scoped benefits.

---

## Phase 12 — Reviews, Ratings, Review Links & In-App Notifications ✅

Full rules: **`docs/22-REVIEWS.md`**, **`docs/23-NOTIFICATIONS.md`**. Decisions:
ADR-066 (a review is a capability over a completed visit), ADR-067
(notifications listen, and can never hold anything up).

> **Entitlements: one new key, no package changed.** `reviews`, with NO
> dependency — a journey is a walk-in as readily as a booked visit, so requiring
> `booking` would have the closure silently drop the key from a walk-in-only
> center. No seeded plan sells it. There is deliberately no `notifications`
> key: operational notifications are part of running a center.
>
> **Permissions: two new codes**, `review.view` and `review.manage` (catalog 86).
> A release that adds a code must run `metastyle:roles:sync --all` on deploy.

**Scope**

- **Reviews** — one review per completed visit, reached through a capability
  link. Eligibility is a completed `ServiceJourney` with at least one completed
  `JourneyStage`; a guest needs no account. Overall rating required, optional
  per-service and per-employee ratings anchored to the stages that actually ran,
  one public comment. Immutable once submitted.
- **Review invitations** — 256-bit capability token, SHA-256 at rest, plaintext
  returned once and never stored, logged or audited. Bounded validity, rotate and
  revoke, single use.
- **Moderation** — `submitted` / `hidden` / `flagged`, with actor, reason and
  audit. The customer's words are never rewritten.
- **Rating summaries** — one canonical read model: count, average, 1–5
  distribution, per-service and per-employee averages, each in one grouped query.
- **Notifications** — in-app only. Staff and customer-account inboxes, explicit
  types, structured allow-listed parameters rendered at read time, read/unread,
  preferences for the optional ones, and per-source idempotency in the schema.
- **Appointment reminders** — a bounded scheduled sweep in branch-local time.
- **Low-rating follow-up** — a configurable threshold raises an internal alert to
  staff who hold the permission in that branch.

**Exit**

1. One completed visit produces exactly one invitation and at most one review,
   proven under real concurrency and enforced by database constraints.
2. No raw capability token reaches a column, a log line, an audit row or a
   notification payload.
3. A notification failure cannot roll back a booking, a payment, an activation or
   a review submission.
4. Hidden reviews leave the visible averages; flagged ones do not.
5. An invitation issued while `reviews` was owned stays usable after a downgrade;
   no new one is issued after it.

**Closed** — 2026-09-20

| Gate | Result |
|---|---|
| PHASE (`composer check:phase12`) | 545 passed, 2 177 assertions, 0 failed, exit 0 |
| Entitlement correction, focused set | 229 passed, 1 010 assertions, 0 failed, exit 0 |
| Query budgets (added after the gate, run standalone) | 3 passed, 10 assertions, 0 failed |
| `composer check:fast` | Pint pass · PHPStan level 6 clean · 234 passed, 692 assertions |
| `php artisan metastyle:doctor` | All checks passed, exit 0 |
| **FULL (`composer check`)** | **1 369 passed, 7 232 assertions, 0 failed, exit 0, 12 291 s** |

**Not in scope:** WhatsApp, SMS, email infrastructure, push (APNs / FCM / web),
marketing campaigns, public testimonial feeds, promo codes, gift cards, loyalty
changes beyond integration, advanced reports, Super Admin broadcasts.

---

---

## Deferred packaging/entitlement decision — the Journey commercial boundary

> **Revisit before introducing a sellable walk-in-only / no-booking center
> configuration.** Not a blocker for any phase: every sellable plan already
> includes `booking`, no supported configuration sells Journey or walk-in
> operations without it, Reviews no longer depends on it, and no later phase
> depends on moving this boundary.
>
> Raised during Phase 12, audited read-only, and deliberately not changed inside
> it. Phase 12 is unaffected either way: `reviews` depends on nothing, and
> `ReviewEntitlementTest` proves a finished walk-in stays reviewable with
> `booking` revoked.

**What is true today.** Eight `ServiceJourney` write Actions call
`Entitlements::ensure('booking')`:

```
AbortJourney            CreateWalkInVisit       SwapStageResource
CheckInAppointment      HandoffStage            TransitionStage
CompleteJourney         ReassignStageEmployee
```

So `booking` is the commercial capability that enables the Journey operational
workflow, including walk-ins — even though a walk-in reserves nothing. That is
the reuse of one key for two sellable capabilities, decided deliberately.

**What it is not.** It is not drift, and it is not a domain dependency. The
separation Meta Style promises is already fully honoured in code and schema:

- `service_journeys.appointment_id` is NULLABLE, and a walk-in never creates an
  Appointment to satisfy it (ADR-051);
- a walk-in carries its own `customer_id` and `branch_id`;
- no Journey Action imports Booking *in order to gate* — the gate is a string
  key. `CheckInAppointment` and `CompleteJourney` import Booking for real work
  (checking in an appointment, and handing its lifecycle back to
  `TransitionAppointment`), and for a walk-in that path is skipped because there
  is no appointment;
- `ResolveBookingCustomer`, which `CreateWalkInVisit` uses to resolve a phone,
  carries no entitlement gate of its own.

What is coupled is the COMMERCIAL key, and that was a deliberate decision
recorded in `docs/16-JOURNEY-RESOURCES.md` §18 — *"the existing `booking`.
Journey is the operational half of booking, and a center that owns one owns the
other. No new entitlement, no plan names."* — and restated in
`docs/17-QUEUE.md` §§2, 30 and `docs/05-ENTITLEMENTS.md`.

**Why nothing was changed.** The edit would be mechanically small (seven
`ensure()` lines; no schema change; no test pins the gate). The decision behind
it is not:

1. Removing the gate leaves those Actions with NO entitlement check, which
   contradicts the rule that enforcement lives in the Action
   (`docs/05-ENTITLEMENTS.md`). Keeping them gated means a new key — which
   changes the plan matrix and its pinned test.
2. It would overturn an explicit decision in two CLOSED phases (7 and 8) and the
   three documents that restate it.
3. It is a PACKAGING question with no observable effect today: all three seeded
   plans (`trial`, `starter`, `business`) already include `booking`, so no
   sellable configuration distinguishes the two. The walk-in-only center it
   would serve does not exist until the SaaS work creates a plan for it.

**The decision to take, when that plan is proposed.** One of:

- **A — keep `booking`** as the commercial umbrella for Appointment *and*
  Journey operations. Nothing changes; a walk-in-only package simply includes
  `booking`, and the key's name stops matching what it sells.
- **B — introduce or restructure a dedicated operational Journey entitlement**,
  move the seven gates onto it, and update the plan closure and the seeded
  matrix accordingly.

Do not invent that key before the packaging question is real: an entitlement
nothing sells is a switch nobody can buy and a branch nobody tests.

**Exactly what would change.**

| | |
|---|---|
| Actions | the seven non-appointment gates above (`CheckInAppointment` legitimately needs Booking) |
| Config | `config/entitlements.php` and `database/seeders/ControlPlaneSeeder.php`, if a key is added |
| Docs | `docs/16` §18, `docs/17` §§2, 30, `docs/05` |
| Tests | none currently assert the gate. `QueueEntitlementTest` ("still takes walk-in VISITS with no queue entitlement at all") relies on the trial granting `booking`, and `SeedsReviews::grantReviews()` grants it so a fixture can build a completed visit |
| Not affected | Reviews, Sales, Resources, Queue's own keys, and the entitlement closure — `reviews` depends on nothing, and no other key depends on `booking` except `whatsapp_booking` |

**Regression tests the correction must add** (they would fail today, which is
the finding): a walk-in journey created, progressed and completed with `booking`
revoked; booked-journey behaviour unchanged; Booking's own Actions still gated;
no Appointment invented; Queue interoperability unchanged.

---

## Phase 13 — WhatsApp Booking Bot, RAYAN & Usage Quotas

**Scope** (entitlement-gated: `whatsapp_booking`, `rayan_ai`)

- Booking reference and verification capability on every new appointment; HMAC
  under a versioned pepper; authorised regeneration only (`docs/24`).
- Conversations: WhatsApp accounts, threads, messages, webhook events, human
  takeover (`docs/25`).
- Meta WhatsApp Cloud API adapter behind a provider-neutral seam.
- RAYAN: an allow-listed tool registry, a bounded run loop, and the OpenAI
  Responses API behind a provider-neutral seam (`docs/27`).
- `Kernel\Usage`: commercial allowances, atomic consumption, metering,
  thresholds, and a Super Admin projection (`docs/26`).
- Five staff notification types; the manager usage dashboard; the staff inbox.

**Exit**

1. Nothing happens on an inbound notification before its signature verifies —
   no conversation, no customer resolution, no AI run, no tool call.
2. RAYAN calls `BookingEngine` for every booking action; no availability or
   booking rule exists in `Modules/Rayan`.
3. The model can request only the ten registered tools, and can name no tenant,
   customer or phone number in any of them.
4. `ai_runs` cannot be overspent under concurrency; 101 of 100 is unreachable.
5. Every failure — provider, quota, loop ceiling — reaches a person, and the
   customer's message survives all of them.
6. A raw verification code exists in no column, audit row, log or message.

**Provider status:** both adapters are implemented against documentation read
2026-09-20 and exercised by contract tests. **Neither has run against live
credentials**; the manual checklist is in `docs/12` §12.

**Closed — 2026-09-21** — code, architecture and automated verification.

| Gate | Result |
|---|---|
| Focused, after the outage: `RayanRunTest` · `ConversationNotificationTest` · `ConversationsIsolationTest` | 12 / 66 · 8 / 35 · 9 / 75 (passed / assertions) |
| Focused, written during verification: `ConversationQueryCountTest` · `ConversationConcurrencyTest` · `BookingConcurrencyTest` | 6 / 16 · 3 / 11 · 8 / 24 |
| PHASE (`composer check:phase13`) | 626 passed, 2 493 assertions, 0 failed, exit 0, 4 356 s |
| `php artisan metastyle:doctor` | All checks passed, including the booking verification key; exit 0 |
| FULL attempt 1 | 1 478 passed, **1 failed**, 7 859 assertions, exit 1 — defect 6 below |
| FULL attempt 2 | **Interrupted** — the machine lost power mid-suite. No result; not counted as an attempt |
| **FULL (`composer check`), attempt 2 restarted** | **1 479 passed, 7 861 assertions, 0 failed, exit 0, 13 358 s** |

Before the outage, `composer check:fast` (253 / 755) and six focused suites —
booking verification, WhatsApp security, usage quotas, the tool surface, the
conversations boundary and tenant migrations — were green. The PHASE and FULL
runs above re-cover all of them.

**Defects verification found.** Each was fixed in code; none by loosening a
test.

1. `AttemptLimiter` shared one cache key across its windows, so every attempt
   counted twice and a five-per-minute limit tripped after three. The key now
   includes the window.
2. The suite made **real HTTPS calls** to `graph.facebook.com`. `TestCase` now
   calls `Http::preventStrayRequests()` and fakes both providers with their
   documented success shapes. A test chooses a provider's behaviour through a
   property, because Laravel merges `Http::fake()` stubs and the first match
   wins — a per-test fake could never override one from `setUp()`.
3. **N+1 in the staff inbox** — one `SELECT` per thread from
   `Customer::isRegistered()`. `ConversationsQuery` now loads
   `customer.account` (`docs/25` §17).
4. **An assistant reply could land after a human takeover**, and a hand-off
   could push `human_active` back to `human_requested`, unassigning the person
   on the thread. The router re-reads the status once the model answers
   (`docs/25` §12). Both halves of the test fail without the fix.
5. `composer check:phase13` **could not run at all**: its
   `disableProcessTimeout` callback was committed over-escaped, and Composer
   fataled before the first test.
6. **FULL attempt 1's failure.** A Phase 10 route guard in
   `Sales/SalesSurfaceTest` treated any URI containing `webhook` as a money
   route, and reported Meta's messaging endpoint as one Payments does not own.
   Every genuine money route already matched on `pay` or `gateway`, so the
   needle was removed and the gateway callback pinned explicitly — a refactor
   that moves it off a payments path still fails there. The file joined
   `check:phase13`, which should have caught this before FULL did.

**Provider verification — separate, and still pending.**

```
OPENAI ADAPTER IMPLEMENTED — LIVE CREDENTIAL TEST PENDING
META CLOUD API ADAPTER IMPLEMENTED — LIVE CREDENTIAL TEST PENDING
```

No automated test reaches either provider — `TestCase` refuses any outbound
request nothing has faked — so no result above says anything about a live one.
The manual checklist is in `docs/12` §12. Neither adapter may be described as
production-verified until it has run there with real credentials.

---

## Phase 14 — Reports, Exports, Advanced Reports

**Status: implemented; verification in progress.** The accepted Phase 14 scope
below supersedes the earlier speculative list: unsupported metrics and export
infrastructure were not fabricated.

**Scope**

- Ten Standard Reports over authoritative source-module readers on Primary.
- Audited CSV and browser print; no Excel/PDF dependency or scheduled exports.
- Ten `reports_advanced` comparison, cohort, funnel and trend reports on the
  Reporting connection only, with no silent fallback to Primary.
- Contextual read-only RAYAN analysis owned by `reports_advanced`, using the
  independent hard `advanced_report_ai_runs` allowance and shared provider/token
  metering.
- Responsive, accessible Standard and Pro Advanced manager surfaces. See
  `docs/28-REPORTS.md`.

**Exit**

1. Every CSV export is permission-checked, branch-scoped, masked/allow-listed,
   formula-injection guarded and audited.
2. Advanced reports run against the replica without touching the operational
   path.
3. Missing replica configuration does not affect Standard Reports or unrelated
   startup, but blocks production activation and fails Advanced execution closed.
4. No unsupported metric or analytical aggregate table is introduced.

---

> **The former Phase 14 (Notifications, WhatsApp) and Phase 15 (RAYAN AI) were
> delivered by Phases 12 and 13.** In-app notifications shipped in Phase 12; the
> WhatsApp channel, the booking bot, the tool-calling assistant and its quota
> metering shipped in Phase 13.
>
> What those sections described and this product still does NOT have, deliberately
> and with no phase yet claiming it:
>
> - push, email and SMS channels — `docs/23-NOTIFICATIONS.md` §2 keeps in-app the
>   only channel, and an architecture test keeps a transport out of the module;
> - Super Admin broadcast targeting (all / selected tenants, role and plan
>   groups), and tenant → customer campaign targeting. Phase 13's notifications
>   are operational facts about one thing that happened, never a campaign;
> - RAYAN roles beyond the customer-facing assistant — Host, Employee, Manager
>   and Support each need their own tool surface and their own permission story;
> - confirmation gates for destructive AI actions beyond the conversational
>   confirmation the prompt asks for;
> - `whatsapp_integration` and `whatsapp_ai` as separate entitlement keys. Phase
>   13 uses `whatsapp_booking` and `rayan_ai`, which already existed.
>
> Any of those is a scoped phase of its own, not a leftover to be absorbed
> quietly.

---

## Phase 15 — Corporate Web and Platform Administration

**Scope**

- APP_URL-derived Corporate, Super Admin and center subdomain architecture,
  with authoritative domain-registry resolution and reserved-host protection.
- Platform identities, permissioned SADMIN, mandatory MFA and audited actions.
- Center registration, verification, provisioning completion, staff login and
  password recovery on authoritative center hosts.
- Centers, plans/pricing, subscriptions/trials, lifecycle history,
  entitlements, usage, manual SaaS billing, support, landing CMS,
  operations/readiness, alerts, audit and platform settings.
- Rose Gold Luxe responsive shell, semantic light/dark themes, accessible
  sidebar/drawer, alert popover and persisted UI state.
- English, Arabic and Kurdish Sorani (`ckb` internally, `KU` visibly), including
  RTL/LTR behavior and localized lifecycle email.

**Exit:** `composer check:phase15` and `metastyle:doctor` are green, real local
HTTP surfaces are verified, and approved Poppins/Noto Sans Arabic local assets
are loaded and confirmed through computed browser styles. The global FULL suite
is deferred to the final web release/pre-production gate.

**Not in scope:** Flutter applications, the complete center customer-site
builder, fabricated provider/replica credentials, or path-based production
tenancy.

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
WhatsApp direct vs BSP (Phase 14) · Sorani TTS availability (Phase 8).

See the pending-decisions table in `DECISIONS.md`.
