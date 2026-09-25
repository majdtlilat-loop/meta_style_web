# 01 — Architecture

> Status: **Phase 0 (design only)**.

## 1. Architecture style

**Modular monolith, one deployable, two data planes, database per tenant.**

- One Laravel 12 application. One codebase. One deploy artifact.
- Internal boundaries are enforced by namespaces, dependency rules and
  automated architecture tests — not by network calls.
- No microservices. No message bus between services. No distributed transactions.

**Why:** the entire product is one transactional business workflow (book →
serve → charge → account). Splitting it across services buys nothing at this
stage and costs consistency, latency, and operational headcount we do not have.
The modular boundaries below are what would make a future extraction possible
if it ever becomes necessary — but extraction is not a goal.

## 2. The two planes

```
                         ┌────────────────────────────────┐
                         │      Meta Style application    │
                         │        (single Laravel 12)     │
                         └────────────────────────────────┘
                                        │
                 ┌──────────────────────┴──────────────────────┐
                 │                                             │
        CONTROL PLANE                                   TENANT PLANE
  connection: "control"                          connection: "tenant"
                 │                                             │
     ┌───────────▼───────────┐                    ┌────────────▼────────────┐
     │   meta_style_control  │                    │  tenant_drbany_000005   │
     │                       │                    │  tenant_salon_000006    │
     │ tenants               │  ── metadata ──▶   │  tenant_000001 (legacy) │
     │ tenant_domains        │     resolves       │  ...                    │
     │ plans / entitlements  │                    │                         │
     │ subscriptions         │                    │  identical schema in    │
     │ platform_users        │                    │  every tenant database  │
     │ saas_billing          │                    └─────────────────────────┘
     │ platform_audit_logs   │
     └───────────────────────┘
```

**Control plane** answers: *who is this tenant, where is their data, what may
they do, are they paid up.*

**Tenant plane** answers: *everything about running this one business.*

A request operates in exactly one of two modes:

| Mode | Control connection | Tenant connection | Used by |
|---|---|---|---|
| `platform` | read/write | **forbidden** | SADMIN, platform APIs, provisioning |
| `tenant` | read-only (metadata/entitlements) | read/write | everything else |

Writing tenant business data from a `platform`-mode request is a bug. Writing
control-plane business data (plans, subscriptions) from a `tenant`-mode request
is a bug. Both are caught by tests.

## 3. Request lifecycle (tenant mode)

```
HTTP request
  │
  ├─ 1. AssignRequestId          → X-Request-Id, bound to logs/jobs/audit
  ├─ 2. ResolveTenant            → host / token binding → Tenant (control plane)
  ├─ 3. BindTenantContext        → configures "tenant" connection, cache prefix,
  │                                storage disk, locale, timezone
  ├─ 4. EnforceTenantAccessLevel → subscription status → active/read-only/blocked
  ├─ 5. Authenticate             → staff | customer | guest
  ├─ 6. ResolveLocale            → Accept-Language ∩ tenant enabled locales
  ├─ 7. RateLimit                → per tenant + per principal + per IP
  ├─ 8. RequireEntitlement       → route-level capability gate
  ├─ 9. Authorize (Policy)       → permission ∩ branch scope
  ├─ 10. Controller              → validates input, calls one Action
  │        └─ Action (Application layer) → domain rules, persistence, events
  └─ 11. API Resource            → serialisation + PII masking by permission
```

Steps 2–4 are the tenancy kernel and are described in `02-TENANCY.md`.
Steps 8–9 are two *independent* gates: entitlement asks "does this tenant own
the feature", permission asks "may this person use it". Both must pass.

## 4. Layering inside the application

```
app/
├── Kernel/                     ← cross-cutting platform services
│   ├── Tenancy/                   tenant resolution, context, connections
│   ├── Entitlements/              capability resolution and gates
│   ├── Identity/                  authentication, guards, tokens
│   ├── Authorization/             permission catalog, policies, branch scope
│   ├── Localization/              locale resolution, translatable casts
│   ├── Audit/                     audit writer, correlation, redaction
│   ├── Storage/                   tenant-scoped disks and media paths
│   ├── Templates/                 shared render pipeline (docs, messages)
│   ├── Money/                     Money value object, currency exponents
│   ├── Time/                      branch-local time, UTC boundaries
│   └── Support/                   shared primitives (IDs, DTOs, results)
│
└── Modules/                    ← business modules
    └── {Module}/
        ├── Contracts/          ← THE PUBLIC SURFACE. Other modules may import
        │                          only from here (+ Domain/Events + Data).
        ├── Data/               ← DTOs crossing the boundary (readonly classes)
        ├── Domain/
        │   ├── Models/            Eloquent models (tenant connection)
        │   ├── Events/            domain events (public)
        │   ├── Enums/
        │   └── Services/          pure business rules, no HTTP, no facades
        ├── Application/
        │   └── Actions/           one use case per class, invokable
        ├── Http/
        │   ├── Controllers/       thin: validate → action → resource
        │   ├── Requests/
        │   ├── Resources/
        │   └── routes.php
        ├── Infrastructure/     ← adapters to the outside world (gateways, APIs)
        └── Providers/          ← module service provider (bindings, routes)
```

**Rules**

1. `Kernel` may not import from `Modules`. Ever.
2. A module may import from `Kernel` freely.
3. A module may import from another module **only** via that module's
   `Contracts/`, `Data/`, or `Domain/Events/`.
4. Controllers contain no business logic. They validate, call one Action,
   return a Resource.
5. Actions are the transaction boundary. One Action = one use case.
6. Domain services are framework-light: no `request()`, no `auth()`, no
   `session()`. Everything they need is passed in.
7. Eloquent models are allowed and encouraged. **No repository interfaces**
   unless a second implementation genuinely exists. (See `DECISIONS.md` ADR-011.)

## 5. Module dependency direction

Modules are stacked in layers. **A module may only depend on layers below it.**

```
L6  Channels & Presentation   WhatsApp · RayanAI · Printing · WhiteLabel · LandingCMS
L5  Insight                   Reports · AdvancedAnalytics
L4  Engagement                CRM · Loyalty · Memberships · Packages · Reviews ·
                              Marketing · Automation · Notifications · Support
L3  Money                     POS · Invoices · Payments · Finance · Inventory
L2  Operations                Booking · ServiceJourney · Queue
L1  Core Records              Branches · Employees · Customers · Services
L0  Kernel + Platform         Tenancy · Identity · Authorization · Entitlements ·
                              Localization · Audit · Storage · Templates · SaaS
```

Upward communication happens through **domain events only**. For example,
`Booking` (L2) never calls `Invoices` (L3). It raises
`AppointmentCompleted`; `Invoices` listens.

Sideways communication within a layer goes through `Contracts/`.

This is verified by an automated architecture test (`11-TESTING-STRATEGY.md` §5).

## 6. Cross-cutting concerns and where they live

| Concern | Owner | Notes |
|---|---|---|
| Tenant resolution & context | `Kernel/Tenancy` | Only place that knows about tenant databases. |
| Entitlement checks | `Kernel/Entitlements` | Middleware + in-Action guard. |
| Permissions & policies | `Kernel/Authorization` | Permission catalog defined in code. |
| Audit trail | `Kernel/Audit` | Actions call it explicitly; models do not auto-audit everything. |
| Money | `Kernel/Money` | Integer minor units + currency. Never float. |
| Time | `Kernel/Time` | Store UTC, compute in branch timezone. |
| Localization | `Kernel/Localization` | Translatable casts + locale resolution. |
| Files | `Kernel/Storage` | No module builds a path string itself. |
| Document/message rendering | `Kernel/Templates` | One pipeline for invoices, receipts, tickets, reports, WhatsApp, email. |

## 7. Events policy

Events are used **only** where they genuinely decouple layers. They are not a
default. Three legitimate uses:

1. **Upward notification across layers** — `AppointmentCompleted`,
   `SaleCompleted`, `ServiceStageCompleted`, `InvoicePaid`.
2. **Fan-out to optional/entitlement-gated modules** — loyalty, reviews,
   notifications, analytics may or may not exist for a tenant.
3. **Audit and integration hooks.**

Rules:

- Events are **past-tense facts**, never commands. No `SendInvoiceEvent`.
- Events carry **identifiers plus a minimal immutable payload**, never Eloquent
  models (they must survive queue serialisation and tenant re-binding).
- Every queued listener carries tenant context (see `02-TENANCY.md` §6).
- A listener may never be the only place a business invariant is enforced. If
  correctness depends on it, call it directly inside the Action.
- Do not emit an event for a call that has exactly one synchronous consumer in
  the same layer. Call the Action.

## 8. Queues, cache, Redis

Redis is used where it earns its place:

| Use | Justified because |
|---|---|
| Queues | Provisioning, migrations, notifications, exports, media — all genuinely async. |
| Locks | Per-tenant migration locks, booking slot locks, invoice numbering. Correctness-critical. |
| Rate limiting | Per-tenant/IP counters, public booking abuse. |
| Entitlement cache | Read on every request; changes rarely; versioned invalidation. |
| Session / broadcast | Standard. |

Redis is **not** used for: caching business queries before a measured problem
exists, or as a source of truth for anything.

Queue names: `default`, `notifications`, `media`, `exports`, `provisioning`,
`migrations`. Migration and provisioning workers are isolated so a slow tenant
migration cannot starve customer-facing work.

Redis is **configurable from Phase 1 but not required to run the application.**
A local install with no Redis must work; cache, session and queue fall back to
file/database drivers. Redis becomes the default in Phase 2, when tenant cache
isolation and the provisioning queue are real.

## 9. Directory layout (repository root)

```
app/
  Kernel/
  Modules/
  Console/Commands/
  Providers/
bootstrap/
config/
  tenancy.php          ← tenant connection template, naming, resolution rules
  entitlements.php     ← entitlement keys, types, dependencies (code-defined)
  permissions.php      ← permission catalog (code-defined)
database/
  migrations/
    control/           ← run on meta_style_control only
    tenant/            ← run on every tenant database
  seeders/
    control/
    tenant/
docs/
resources/
  lang/                ← UI strings: en, ar, ckb
routes/
  platform.php         ← /api/v1/platform/*   (SADMIN)
  tenant.php           ← /api/v1/tenant/*     (staff apps, POS)
  public.php           ← /api/v1/public/*     (customer web, guest)
  web.php
tests/
  Unit/
  Feature/
  Architecture/
  TenantIsolation/     ← mandatory suite, see 11-TESTING-STRATEGY.md
```

Module routes live in `app/Modules/{Module}/Http/routes.php` and are loaded by
the module's service provider into the correct route group.

**Migrations stay centralised** (not per-module) so that ordering across all
tenant databases is a single deterministic timestamp sequence. Filenames are
module-prefixed: `2026_03_01_120000_create_booking_appointments_table.php`.

## 10. Technology choices

| Concern | Choice | Notes |
|---|---|---|
| Framework | Laravel 12 | |
| Language | **PHP `^8.2`** | Do not use 8.3+ only features. Local is 8.2.12. |
| Database | MySQL 8.0+ (prod), MySQL/MariaDB (local) | JSON columns + functional indexes needed for localization — verify on the production engine, not only locally. |
| Multi-tenancy | **`stancl/tenancy`** behind Meta Style abstractions | Added in Phase 2. See ADR-018. |
| Frontend | **Blade + Livewire 3** | No React/Vue/Inertia/SPA. Best RTL control, fewest moving parts. |
| Cache / queue / lock | Redis 7+ where it earns its place | Not the default for everything. |
| Queue dashboard | Deferred | Horizon only when async workload justifies it (ADR-021). |
| API auth | Laravel Sanctum | Phase 3. Tokens for mobile, cookie session for first-party web. |
| Tests | Pest 3 (on PHPUnit) | Includes architecture tests. Real MySQL/MariaDB, never SQLite. |
| Static analysis | PHPStan + Larastan | Start level 5, ratchet to 8. |
| Style | Laravel Pint | |

**Dependency policy:** every new Composer package requires a line in
`DECISIONS.md` stating what it does, what it replaces, and the cost of removing
it. Prefer framework primitives.

## 11. What we are deliberately not building

| Not building | Instead |
|---|---|
| Microservices | Modular monolith with enforced boundaries. |
| Event sourcing / CQRS | Normal tables + an append-only audit log. |
| A generic plugin system | Modules are compiled in; entitlements gate them. |
| Per-module databases | One operational database per tenant. |
| Per-plan schemas | One identical schema for all tenants. |
| Repository + Unit-of-Work over Eloquent | Eloquent models and Actions. |
| A custom ORM/query builder layer | Eloquent. |
| A service-locator "God" container of managers | Explicit constructor injection. |
| Domain-wide interfaces "for testability" | Real database in feature tests. |
| A message broker (Kafka/RabbitMQ) | Redis queues. |

## 12. Known architectural risks

Tracked in full in `DECISIONS.md`; summarised here.

| Risk | Impact | Mitigation |
|---|---|---|
| Schema change across N tenant databases | High | Expand/contract discipline, batched queued migrations, per-tenant status tracking (`03`). |
| MySQL connection/table pressure at scale | Medium | Tenant DBs addressable by host from day one; shard by instance later (`12`). |
| Tenant context leaking into a queued job | Critical | Fail-closed connection guard + mandatory isolation test suite (`02`, `11`). |
| Entitlement checks bypassed in a non-HTTP channel (WhatsApp, AI) | High | Gate inside Actions, not only in route middleware (`05`). |
| Booking logic duplicated per channel | High | One Booking Engine; channels are adapters only (`04`). |
| Reporting queries on tenant DBs degrading the operational workload | Medium | Read replica / async report jobs from Phase 13 (`12`). |
| Cost of per-tenant backups and restores | Medium | Logical per-DB dumps; documented restore runbook (`12`). |
