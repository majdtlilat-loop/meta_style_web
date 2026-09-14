# 04 — Module Boundaries

> Status: **Phase 0 (design only)**. Modules are created as their phase arrives.

This document defines **who owns what** and **who may call whom**. It is the
contract that keeps a modular monolith from becoming a big ball of mud.

## 1. Module template

```
app/Modules/{Module}/
├── Contracts/          PUBLIC. Interfaces other modules may depend on.
├── Data/               PUBLIC. Readonly DTOs crossing the boundary.
├── Domain/
│   ├── Models/         PRIVATE. Eloquent models.
│   ├── Events/         PUBLIC. Past-tense domain events.
│   ├── Enums/          PUBLIC where referenced in Contracts/Data.
│   └── Services/       PRIVATE. Business rules.
├── Application/
│   └── Actions/        PRIVATE. One use case per class.
├── Http/               PRIVATE. Controllers, requests, resources, routes.
├── Infrastructure/     PRIVATE. External adapters.
└── Providers/          Module service provider.
```

**Public surface = `Contracts/` + `Data/` + `Domain/Events/` + public `Enums/`.**
Everything else is private to the module.

A module that has no cross-module consumers needs no `Contracts/` directory.
Do not create empty ones "for symmetry".

## 2. The four dependency rules

1. **`Kernel` never imports `Modules`.**
2. **A module may import `Kernel` freely.**
3. **A module may import another module only from that module's public surface.**
4. **A module may only depend on layers at or below its own** (see `01` §5).
   Upward communication is via domain events.

Violations fail the build (`tests/Architecture`).

### 2.1 The pragmatic exception

Reading another module's data through its `Contracts/` query service is
allowed and expected. **Writing** another module's tables is never allowed —
cross-module writes go through the owning module's Action.

Example: `POS` may ask `Customers` for a customer read model. `POS` may not
write to `customers`. To record a visit on a customer, `POS` raises
`SaleCompleted` and `CRM` updates its own projections.

## 3. Layer stack

```
L6  Channels          WhatsApp · RayanAI · Printing · WhiteLabel · LandingCMS
L5  Insight           Reports · AdvancedAnalytics
L4  Engagement        CRM · Loyalty · Memberships · Packages · Reviews ·
                      Marketing · Automation · Notifications · Support
L3  Money             POS · Invoices · Payments · Finance · Inventory
L2  Operations        Booking · ServiceJourney · Queue
L1  Core Records      Branches · Employees · Customers · Services
L0  Platform/Kernel   Tenancy · Identity · Authorization · Entitlements ·
                      Localization · Audit · Storage · Templates · SaaS
```

## 4. Module register

### L0 — Platform

| Module | Owns | Public surface | Notes |
|---|---|---|---|
| **Tenancy** | Tenants, domains, provisioning, tenant migrations, tenant lifecycle | `TenantContext`, `TenantRepository`, `TenantProvisioned` | Control plane. The only module that knows tenant DB names exist. |
| **SaaS** | Plans, subscriptions, SaaS billing, trials | `SubscriptionStatus`, `TenantAccessLevel` | Control plane. **Never** touches center finance. |
| **Entitlements** | Entitlement catalog, resolution, overrides, quotas | `Entitlements::allows/limit/quota`, `RequiresEntitlement` middleware | Catalog defined in code. |
| **Identity** | Staff auth, customer auth, tokens, sessions, login directory | `Principal`, `AuthenticatesTenantUser` | Spans both planes. |
| **Authorization** | Permission catalog, roles, policies, branch scope | `Permission` enum, `BranchScope` | Catalog in code; assignments in tenant DB. |
| **Localization** | Language registry, locale resolution, translatable casts | `Translatable` cast, `LocaleResolver` | |
| **Audit** | Audit writing, redaction, correlation, retention | `AuditWriter`, `Auditable` | Append-only. |
| **Storage** | Tenant disks, media paths, signed URLs, quotas | `MediaStore`, `TenantDisk` | No module builds a path itself. |
| **Templates** | Render pipeline: draft → preview → publish → version | `TemplateRenderer`, `TemplateVersion` | Shared by invoices, receipts, tickets, reports, WhatsApp, email, menu. |

### L1 — Core Records

| Module | Owns | Must not own |
|---|---|---|
| **Branches** | Branches, working hours, holidays, branch settings, timezone | Employee schedules (Employees), room inventory (Resources) |
| **Resources** | Resource types, physical resources, capacity, service resource requirements | Availability computation (Booking), actual usage during a visit (ServiceJourney) |
| **Employees** | Staff profiles, skills, shifts, availability, commission rates | Login credentials (Identity), permissions (Authorization) |
| **Customers** | Customer records, contacts, consents, preferences, guest identity | Visit history projections (CRM), loyalty balances (Loyalty) |
| **Services** | Categories, services, durations, prices, price variations, add-ons, service→employee eligibility, branch availability, the Electronic Menu and Menu Builder | Booking rules (Booking), package definitions (Packages) |

> **Menu note.** The Electronic Menu is a *presentation* of the Services
> catalog. It lives in `Services` and renders through `Kernel/Templates`. It is
> not a separate module, and it must not hold its own copy of prices.

### L2 — Operations

| Module | Owns |
|---|---|
| **Booking** | **The Booking Engine.** Availability computation, slot search, booking rules, deposits/policies, appointments, reschedule, cancel, no-show. |
| **ServiceJourney** | Visits (booked AND walk-in), stages, handoffs, transfers, stage status and timings, ACTUAL resource usage, stage notes. |
| **Queue** | Queue tickets, numbering, calling and recalling, holds, transfers, service points, displays, announcements, printable tickets. |

> **Queue depends on Journey; Journey does not know Queue exists** (ADR-053).
> Journey states facts as domain events and Queue listens — synchronously,
> inside the Journey transaction, so the two can never disagree about a customer
> in a chair. Booking depends on neither.

> **Sales (Phase 9)** reads Booking, Journey, Catalog, Customers and Branches and
> is depended on by none of them — enforced by `tests/Architecture/SalesBoundaryTest.php`.
> A center with no POS completes visits exactly as before; checkout is a Sales
> Action that READS the visit (docs/18-SALES.md §2).

> **Resources moved to L1 in Phase 7** (ADR-049). The Booking Engine has to
> enforce resource capacity, and Booking must not depend on ServiceJourney — so
> the resources themselves sit in Core Records where both can read them.
> ServiceJourney keeps what it is actually about: what happened, and which room
> it happened in.
| **Queue** | Tickets, check-in, walk-ins, multiple queues, service points, destinations, call/recall/skip/hold/transfer, priority, display feed, voice announcements. |

#### 4.1 Booking Engine — the single most important boundary

```
    Web    Mobile    White Label    Host UI    POS    WhatsApp bot    RAYAN AI
      │       │           │            │        │          │             │
      └───────┴───────────┴────────────┴────────┴──────────┴─────────────┘
                                    │
                        Booking\Contracts\BookingEngine
                                    │
                  ┌─────────────────┴─────────────────┐
                  │  availability · rules · deposits  │
                  │  conflicts · policies · locking   │
                  └───────────────────────────────────┘
```

Every channel is an **adapter** that translates its input into the engine's
DTOs. A channel may:

- Ask for availability.
- Request a booking.
- Present errors.

A channel may **not**:

- Compute a slot.
- Decide whether a booking is allowed.
- Write to `appointments`.
- Apply a cancellation policy.

**Implemented in Phase 6** as `App\Modules\Booking\Contracts\BookingEngine`:

```php
interface BookingEngine
{
    public function availability(AvailabilityQuery $q, bool $publicChannel = false): array;
    public function book(BookingRequest $r, BookingActor $actor): Appointment;
    public function reschedule(Appointment $a, CarbonImmutable $startsAt, BookingActor $actor): Appointment;
    public function cancel(Appointment $a, BookingActor $actor, ?string $reason = null): Appointment;
    public function transition(Appointment $a, AppointmentStatus $to, BookingActor $actor): Appointment;
}
```

`quote()` was in the sketch and is deliberately absent: it needs deposits,
cancellation fees and a payment provider, none of which exist. Price is already
knowable from availability plus the catalog, so a quote method today would
return the sum of the snapshot prices and call itself a quote. It arrives with
Payments.

The boundary is enforced, not documented: `tests/Architecture/BookingBoundaryTest.php`
fails the build if a controller or Livewire component creates or updates an
appointment, if anything outside `Modules/Booking` reads a branch schedule, or if
the Booking module reaches for HTTP, Livewire, `Auth`, `Request` or `Session`.

See `docs/15-BOOKING.md` for the rules the engine implements.

`Actor` carries who is acting (staff user, customer, guest, bot, AI) and is
what makes every booking attributable regardless of channel. This is also how
RAYAN and the WhatsApp bot are prevented from bypassing rules: they construct
an `Actor` and call the same methods, and the engine applies the same
validation to all of them.

**Booking vs ServiceJourney:** `Booking` is the *plan* (what was promised,
when, by whom). `ServiceJourney` is the *execution* (what actually happened,
stage by stage). They are separate modules with separate tables and separate
lifecycles. A walk-in has a Journey with no Appointment. A no-show has an
Appointment with no Journey.

### L3 — Money

| Module | Owns | Boundary note |
|---|---|---|
| **POS** | Carts, sales, discounts, promo codes, tips, split/partial payment orchestration, shift open/close | Does not compute service prices — asks `Services`. Does not write invoices — asks `Invoices`. |
| **Invoices** | Invoice documents, numbering, totals, taxes, templates (80mm/A4/PDF/digital), QR, versioning | Numbering is a locked, gapless per-branch sequence. Never re-renders a published invoice from live data. |
| **Payments** | Payment provider adapters, transactions, refunds, reconciliation, tenant merchant credentials | Never holds funds. Never stores PAN/CVV/PIN/OTP. |
| **Finance** | Center revenue, expenses, commissions, cash registers/shifts, payment-method reports, profit | **Strictly separate from `SaaS` billing.** No shared tables, no shared reports. |
| **Inventory** | Products, stock, movements, suppliers | |

> **Invoice immutability.** A published invoice stores its rendered snapshot
> (line items, prices, tax, template version). Changing a service price next
> month must not change last month's invoice. This is a hard requirement, not
> an optimisation.

### L4 — Engagement

| Module | Owns |
|---|---|
| **CRM** | Customer timeline, visit/sales projections, LTV, average spend, favourites, no-shows, segmentation |
| **Loyalty** | Points, tiers, visit/spend rewards, referrals |
| **Memberships** | Recurring customer memberships and their benefits |
| **Packages** | Prepaid session bundles, remaining-session balances, expiry |
| **Reviews** | Review tokens/links, verified ratings of visit/service/employee, low-rating follow-up |
| **Marketing** | Campaigns, offers, promo code definitions, targeting |
| **Automation** | Rule-triggered workflows (reminders, win-back, aftercare) |
| **Notifications** | Channel-agnostic notification engine: in-app, push, email, SMS, WhatsApp dispatch |
| **Support** | Tickets: Center→Platform, Customer→Center, Customer→Platform |

> **Packages/Memberships vs POS.** `POS` sells them; `Packages`/`Memberships`
> own the balance and redemption rules. `POS` asks
> `PackageBalance::redeem(...)` — it never decrements a counter itself.

> **CRM owns projections, not source data.** Customer identity stays in
> `Customers`. CRM builds derived read models from events.

### L5 — Insight

| Module | Owns |
|---|---|
| **Reports** | Standard reports, export pipeline (PDF/Excel/CSV/print), scheduled reports |
| **AdvancedAnalytics** | Comparisons, cohorts, retention, profitability, occupancy, funnels, bottlenecks, revenue per employee/room/chair/device, forecasts, custom dashboards, AI insights |

Reports **read** from other modules. They never write business data. Heavy
reports run as queued jobs producing artefacts in tenant storage; they must be
able to target a read replica later without a code change (`12` §7).

### L6 — Channels

| Module | Owns | Hard rule |
|---|---|---|
| **WhatsApp** | Per-tenant WhatsApp connection, inbound webhook, session state, message templates, bot conversation flow, AI→human handoff | Calls `BookingEngine`. Contains **zero** booking rules. |
| **RayanAI** | Conversation, intent interpretation, tool definitions, AI action attribution | Interprets and requests. Domain engines validate and execute. |
| **Printing** | Print jobs, formats (58/80mm, A4), browser/network/Bluetooth targets | Renders through `Kernel/Templates`. |
| **WhiteLabel** | Branded app metadata, per-tenant branding bundles, build/release tracking | One backend. Never a forked API. |
| **LandingCMS** | Public marketing site sections, multilingual content, scheduling | Control plane. Nothing to do with tenants. |

## 5. Shared infrastructure that must not be duplicated

Five things every module is tempted to reinvent. Each has exactly one owner.

| Capability | Owner | Consumers |
|---|---|---|
| Template render pipeline (draft → preview → publish → version → rollback) | `Kernel/Templates` *(not built — Phase 9 used fixed Blade layouts behind a Sales-owned renderer rather than a speculative engine, ADR-056)* | Invoices, receipts, queue tickets, reports, WhatsApp messages, emails, notifications, electronic menu |
| Notes with visibility levels | `Kernel/Notes` (see §6) | Customers, Booking, ServiceJourney, Queue, POS, Invoices, Support |
| Export pipeline (permission check → build → store → signed URL → audit) | `Reports` | Every module offering an export |
| Money arithmetic and formatting | `Kernel/Money` | POS, Invoices, Payments, Finance, Reports |
| Notification dispatch | `Notifications` | Booking, Queue, POS, Reviews, Marketing, Support, SaaS |

## 6. Notes — a first-class kernel concern

Notes are required at many levels with different visibility rules, so they are
**not** a text column on each table and **not** one generic untyped blob.

```
notes
  id, uuid
  notable_type, notable_id        polymorphic: customer, appointment, visit,
                                  stage, invoice, ticket, sale_item, branch,
                                  department, employee
  category                        general | medical | allergy | preference |
                                  handoff | internal | aftercare | complaint
  visibility                      customer | staff | manager | internal
  body                            text
  locale                          nullable
  is_pinned                       boolean
  author_type, author_id          staff | customer | system | ai
  created_at, updated_at
note_revisions                    append-only history of edits
```

Rules:

- **Visibility is enforced at query time**, by policy, not filtered in the UI.
  A `staff`-visible note must never be serialised into a customer-facing
  response, ever.
- Changing or deleting a note in the `medical`, `allergy`, or `complaint`
  category writes an audit entry and a revision row.
- Notes are queryable per level — "all notes on this visit" and "all notes on
  this stage" are different queries with different permissions.
- A note is never silently truncated or merged into another note.

## 7. Anti-patterns

| Anti-pattern | Consequence | Rule |
|---|---|---|
| WhatsApp bot with its own availability logic | Two definitions of "free slot" that drift | All channels call `BookingEngine`. |
| `Reports` writing to business tables | Corrupt data from a read path | Reports are read-only. |
| `POS` decrementing `packages.remaining_sessions` | Redemption rules bypassed | Call the owning module's Action. |
| `Finance` reading `saas_invoices` | Two financial domains merged | Separate planes, separate modules. |
| A `Shared` or `Common` module | Becomes a dumping ground | Put it in the right `Kernel/` service or the owning module. |
| A `Core` module containing everything | Boundaries never enforced | The layer stack is the structure. |
| Module A importing `Modules\B\Domain\Models\X` | Private surface leak | Import from `Contracts/` or `Data/`. |
| An event listener enforcing a business invariant | Silent corruption if the listener fails | Invariants go inside the Action. |
| Duplicating the template pipeline per feature | Six half-built editors | One pipeline in `Kernel/Templates`. |
| A generic `notes` text column per table | Permissions and context impossible | The `notes` model above. |

## 8. Enforcement

`tests/Architecture/` contains Pest architecture tests asserting:

1. `App\Kernel\*` does not reference `App\Modules\*`.
2. For every pair of modules, imports are limited to the public surface.
3. No module imports a module from a higher layer.
4. Controllers do not reference Eloquent models directly.
5. Domain services do not call `request()`, `auth()`, `session()`, `config()`
   or any facade.
6. No file outside `Kernel/Tenancy` references a tenant database name pattern.
7. No file outside `Kernel/Storage` constructs a `tenants/` path.

The allowed-dependency matrix lives in
`tests/Architecture/module-dependencies.php` and is reviewed whenever a module
is added.

## Modules as built (end of Phase 4)

Created because a phase needed them, not scaffolded ahead of time:

```
Kernel/     Audit  Authorization  Database  Diagnostics  Entitlements  Http
            Identity  Localization  Media  Money  Notes  Observability
            SaaS  Storage  Tenancy

Modules/    Branches  Catalog  Departments  Employees  Menu  Onboarding
```

Two placements worth explaining:

- **`Kernel/Media` and `Kernel/Notes` hold models.** They are platform concerns
  rather than business ones — any module attaches media, and the note seam is
  deliberately owner-agnostic — and their owner enums are plain strings, so
  neither imports a module. The Kernel-never-imports-Modules rule holds.
- **`Departments` is its own module despite being one table.** It is operational
  organisation and will grow into Queue, Journey and rooms; folding it into
  `Catalog` would blur exactly the department/category distinction ADR-037 draws.
