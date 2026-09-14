# Architecture Decision Log

Every entry records **what was decided, what was rejected, and what it costs to
reverse.** New decisions append; existing entries are never edited except to
change `Status` and add a `Superseded by` line.

**Status values:** `Proposed` · `Accepted` · `Deprecated` · `Superseded`

| # | Decision | Status | Reversal cost |
|---|---|---|---|
| 001 | Modular monolith, not microservices | Accepted | Low |
| 002 | Database per tenant | Accepted | **Very high** |
| 003 | ~~Custom tenancy kernel, not `stancl/tenancy`~~ | **Superseded by 018** | — |
| 004 | Tenant DBs addressable by host from day one | Accepted | High if deferred |
| 005 | Centralised migrations + mandatory expand/contract | Accepted | Medium |
| 006 | Entitlement catalog defined in code | Accepted | Low |
| 007 | Custom minimal RBAC, not `spatie/laravel-permission` | **Accepted** | Low |
| 008 | JSON columns for translatable content | Accepted | Medium |
| 009 | One storage bucket, per-tenant prefixes | Accepted | Medium |
| 010 | Explicit audit writes in Actions | Accepted | Low |
| 011 | Eloquent + Actions, no repository pattern | Accepted | Low |
| 012 | Control-plane login directory for staff mobile auth | Accepted | Low |
| 013 | Money as integer minor units + currency exponent | Accepted | **Very high** |
| 014 | Idempotency keys from Phase 3 | Accepted | High if deferred |
| 015 | Failed jobs in the control database | Accepted | Low |
| 016 | Real MySQL in tests, never SQLite | Accepted | Low |
| 017 | Docs and `CLAUDE.md` live in `Meta_Style_Web` | Accepted | Trivial |
| 018 | `stancl/tenancy` behind Meta Style abstractions | **Accepted** | Medium |
| 019 | `Meta_Style_Web` is the canonical backend + web repo | **Accepted** | Low |
| 020 | Blade + Livewire 3 for Meta Style Web | **Accepted** | High after Phase 4 |
| 021 | Horizon deferred until an async workload justifies it | **Accepted** | Trivial |
| 022 | PHP `^8.2`, not `^8.3` | **Accepted** | Trivial |
| 023 | Native local environment supported; Docker optional | **Accepted** | Trivial |
| 024 | Tenant database names derived from an internal sequence | **Accepted** | High |
| 025 | Cache must be taggable; queue must round-trip (no Redis required) | **Accepted** | Low |
| 026 | Tenant migrations never create databases | **Accepted** | Trivial |
| 027 | Tenant-bound Sanctum tokens, stored per tenant + key-prefixed | **Accepted** | Medium |
| 028 | Registration secrets never cross the queue | **Accepted** | Low |
| 029 | Owner holds explicit grants, never an authorization bypass | **Accepted** | Low |
| 030 | The web session is a third trusted resolution source | **Accepted** | Low |
| 031 | Registration bootstrap credential lives on the row, in a retry window | **Accepted** | Low |
| 032 | A release adding a Permission case must re-sync system roles | **Accepted** | Trivial |
| 033 | Portable schema only; no MySQL-8-only index features | **Accepted** | Medium |
| 034 | Production requires a shared rate-limit backend | **Accepted** | Trivial |
| 035 | Registration status and retry need a capability, not just the uuid | **Accepted** | Low |
| 036 | The guest menu resolves its center from the URL path | **Accepted** | Medium |
| 037 | Department ≠ Category; a null variation price inherits | **Accepted** | Medium |
| 038 | The electronic menu takes no HTML, CSS or JavaScript from a center | **Accepted** | Low |
| 039 | Phone normalisation without libphonenumber | **Accepted** | Low |
| 040 | Customer accounts ship without phone verification, and say so | **Accepted** | Trivial |
| 041 | Customer is not an account, and neither is a staff user | **Accepted** | **Very high** |
| 042 | PII is masked once, in a presenter, and fingerprinted in audit | **Accepted** | Low |
| 043 | The public menu may take a booking | **Accepted** | Medium |
| 044 | Double booking is prevented by a branch-row lock, not by Redis | **Accepted** | Low |
| 045 | Livewire persistent middleware carries tenant resolution | **Accepted** | Trivial |
| 046 | Scheduled instants are DATETIME, not TIMESTAMP | **Accepted** | Medium |
| 047 | One branch lock guards booking AND every capacity mutation | **Accepted** | Low |
| 048 | Availability collaborators are transient, never scoped | **Accepted** | Trivial |
| 049 | Resources are an L1 module, not part of ServiceJourney | **Accepted** | Medium |
| 050 | Runtime resource capacity counts actual use AND committed reservations | **Accepted** | Medium |
| 051 | Walk-in visits make `appointment_id` nullable, never a fake appointment | **Accepted** | Medium |
| 052 | The queue display polls; no WebSockets in Phase 8 | **Accepted** | Medium |
| 053 | Journey to Queue sync is synchronous and in-transaction | **Accepted** | Medium |
| 054 | A sale is not a visit, and an invoice is an immutable snapshot | **Accepted** | High |
| 055 | Invoice numbers: per branch, per branch-local year, from a locked sequence | **Accepted** | High |
| 056 | No PDF or QR dependency in Phase 9; invoices print through the browser | **Accepted** | Low |
| 057 | Phase 9 financial consistency: visit completion, hashed invoice links, downgrade, in-transaction audit | **Accepted** | High |

---

## ADR-001: Modular monolith, not microservices

**Status:** Accepted · **Date:** 2026-09-01 · **Deciders:** Product owner, architect

### Context
Meta Style spans booking, queue, POS, finance, CRM, reporting, messaging and AI.
That breadth invites a service-per-domain decomposition.

### Decision
One Laravel application, one deployable, with internal module boundaries
enforced by namespaces, a dependency matrix, and automated architecture tests.

### Options

| | Modular monolith | Microservices |
|---|---|---|
| Complexity | Low | High |
| Consistency | ACID transactions | Distributed, eventual |
| Ops cost | One deploy, one runtime | N deploys, service mesh, tracing |
| Team fit | Small team | Needs platform engineering |
| Local dev | Trivial | Docker orchestration |

### Trade-off
The core workflow — book → serve → charge → account — is one transaction.
Splitting it means distributed transactions or accepting inconsistent money,
neither acceptable. Microservices solve independent scaling and independent
deploys; neither is a problem we have.

### Consequences
Easier: correctness, local development, refactoring, onboarding.
Harder: independent scaling of a single hot module; a careless team can erode
boundaries.
Revisit: if one module needs radically different scaling, or team size makes a
single deploy pipeline the bottleneck.

---

## ADR-002: Database per tenant

**Status:** Accepted · **Date:** 2026-09-01

### Context
The alternative is one operational database with `tenant_id` on every business
table. The product owner explicitly excluded that.

### Decision
One MySQL database per tenant, identical schema in all, plus one control-plane
database.

### Options

| | DB per tenant | Shared DB + tenant_id | Schema per tenant |
|---|---|---|---|
| Isolation | Structural | One forgotten scope = breach | Structural |
| Migration cost | N databases | 1 | N schemas |
| Per-tenant restore | Trivial | Very hard | Trivial |
| Noisy neighbour | Contained | Shared indexes and plans | Contained |
| Cross-tenant analytics | Aggregation required | One query | Aggregation required |
| MySQL object count | High | Low | High |

### Trade-off
We pay a real, permanent operational cost (§ADR-005, `12` §7) to remove an
entire class of catastrophic bug. In a product holding customer phone numbers,
medical/allergy notes, and financial records for competing businesses in the
same city, a cross-tenant leak is existential. A migration burden is not.

### Consequences
Easier: isolation, per-tenant backup/restore/export/delete, per-tenant
performance, compliance answers.
Harder: schema changes (N databases), cross-tenant reporting, MySQL object
count at scale.
**Reversal cost: very high.** This shapes everything. It is the one decision
that must be right now.

---

## ADR-003: Custom tenancy kernel, not `stancl/tenancy`

**Status:** ❌ **Superseded by ADR-018** (2026-09-01) · **Date:** 2026-09-01

> Rejected by the product owner on the same day it was proposed. The reasoning
> below is retained because ADR-018 keeps two of its conclusions — the
> abstraction boundary and the fail-closed requirement — and discards the
> "build it ourselves" premise. Read ADR-018 for the decision in force.

### Context
`stancl/tenancy` is the mature Laravel multi-tenancy package and supports
database-per-tenant with bootstrappers for cache, filesystem and queue.

### Decision (proposed)
Build a thin custom kernel (`Kernel/Tenancy`, roughly 6–8 classes): resolver,
context, connection binder, fail-closed guard, `TenantAware` job trait,
`DispatchForEachTenant`.

### Options

| | Custom kernel | `stancl/tenancy` |
|---|---|---|
| Lines we own | ~500 | ~0 |
| Fits our control plane | Exactly | Needs adaptation |
| Migration orchestration with per-tenant status | Ours anyway | Ours anyway |
| Ambient/global tenant state | None by design | Present by design |
| Cache/filesystem/queue bootstrapping | We build it | Provided |
| Upgrade risk | None | Framework-version coupling |
| Community support | None | Good |

### Trade-off
The package's value is the bootstrappers — real work, easy to get wrong. But we
need custom control-plane-driven connection metadata, custom migration
orchestration with per-tenant status tracking, and an explicitly injected
context rather than ambient state (a stated project constraint, and a real
hazard for queue workers). Adopting the package means using perhaps 40% of it
and fighting the rest.

**The bootstrappers are not optional, whichever way this goes.** The isolation
matrix in `02` §5 and the isolation test suite in `11` §4 define the
requirements independently of the implementation.

### Consequences
Easier: exact fit, no ambient state, no framework-version coupling.
Harder: we own the cache/filesystem/queue bootstrapping and its bugs.
**Mitigation:** the mandatory isolation suite is the safety net either way.
Revisit: if the custom kernel exceeds ~800 lines or isolation bugs recur,
adopt the package.
**Reversal cost: medium** — a Phase 2 rewrite, before business modules exist.

---

## ADR-004: Tenant databases addressable by host from day one

**Status:** Accepted · **Date:** 2026-09-01

### Context
At launch every tenant database lives on one MySQL instance. Eventually one
instance will not be enough.

### Decision
`tenants` carries `db_host`, `db_port`, `db_name`, `db_username`,
`db_password_encrypted` from the first migration. The connection resolver reads
all of them, even though every row initially has the same host.

### Trade-off
Five unused columns and a slightly larger resolver, in exchange for sharding
later being a **data move** rather than a schema change plus a resolver rewrite
under production load.

### Consequences
Enables the Stage 4/5 scaling path in `12` §7 at effectively zero present cost.
Adding this later would require touching every tenant record and the most
safety-critical code path in the system while live.

---

## ADR-005: Centralised migration directories + mandatory expand/contract

**Status:** Accepted · **Date:** 2026-09-01

### Context
Tenant databases cannot be migrated atomically. During a deploy, tenants sit on
two different schema versions simultaneously, possibly for hours.

### Decision
1. `database/migrations/control/` and `database/migrations/tenant/`, centralised
   (not per-module), with module-prefixed filenames.
2. Every schema change ships as **expand** then, a release later, **contract**.
3. Per-tenant migration status in the control plane; batched, queued,
   lock-protected, failure-isolated execution.
4. CI enforces the expand/contract rule and migration timestamp ordering.

### Options considered
Per-module migration directories were rejected: ordering across all tenant
databases must be a single deterministic sequence, and per-module directories
make merge-order divergence between tenants migrated at different times
possible — a class of bug that is very hard to detect.

### Consequences
Easier: rolling deploys, isolated failures, resumable batches.
Harder: every rename or type change takes two releases; developers must think
about backward compatibility on every migration.
This discipline is the direct, unavoidable cost of ADR-002.

---

## ADR-006: Entitlement catalog defined in code

**Status:** Accepted · **Date:** 2026-09-01

### Context
Entitlement keys are referenced directly by application code
(`allows('queue_voice')`).

### Decision
Keys, types, categories and dependencies live in `config/entitlements.php`,
synced to a control-plane table for the SADMIN UI. Plans, add-ons, overrides and
usage remain data.

### Trade-off
A database-only catalog lets non-developers invent keys — which is exactly the
failure mode, since a key nothing checks does nothing. Code as source of truth
means a referenced key always exists and every environment matches.

### Consequences
Adding an entitlement is a code change (correct — something must check it).
Adding a **plan** is a data change only (the point of `05`).

---

## ADR-007: Custom minimal RBAC, not `spatie/laravel-permission`

**Status:** Accepted (Phase 3) · **Date:** 2026-09-01 · **Confirmed:** 2026-09-03

### Context
Permissions are tenant-scoped, custom roles are required, and the permission
catalog is code-defined (`06` §3).

### Decision (proposed)
~150 lines in `Kernel/Authorization`: `HasRoles` trait, permission resolver with
a tenant-scoped cache key, base policy with branch scoping, Gate registrar.

### Trade-off
The package's main value is model/table plumbing and seeding — most of which a
code-defined catalog removes. Its permission cache uses a **single global cache
key**; in a database-per-tenant system that must be namespaced per tenant, and
getting it wrong means one tenant's permissions applied to another. That is the
one bug class we cannot risk. We also need branch scoping and field-level
masking, which the package does not provide.

### Consequences
Easier: exact control over the cache key, branch scoping built in.
Harder: we own it, including the parts a mature package has already debugged.
**Reversal cost: low** — adopting the package later is a two-pivot-table
migration.

**Outcome (Phase 3).** Built and shipped. The whole of it is a `Permission`
enum, a `SystemRole` enum, three tables, `User::permissions()` /
`hasPermission()`, and a `BranchScope` value object — roughly 300 lines
including the role synchroniser. No permission cache had to be invented:
permissions are memoised per request and the tenant database is already
isolated, so the global-cache-key hazard that drove this decision does not
exist here.

---

## ADR-008: JSON columns for translatable content

**Status:** Accepted · **Date:** 2026-09-01

### Context
Arabic, English and Sorani at launch; more languages must be addable with no
migration. Fixed `name_ar`/`name_en`/`name_ku` columns are explicitly excluded.

### Options

| | JSON column | `translations` table |
|---|---|---|
| Reads | One row, no join | Join or eager load, N+1 risk |
| Add a language | No schema change | No schema change |
| Search/sort by locale | Functional index needed | Native |
| Copy/version an entity | Translations travel with it | Separate handling |
| Menu render (hot path) | Fast | Slowest option |

### Decision
JSON columns with a `Translatable` cast; MySQL 8 generated columns + functional
indexes only where a named query requires them.

### Trade-off
The electronic menu and service listings are the highest-traffic reads in the
product and are frequently public. A join per translated field on that path is
the wrong default. Cross-locale search is rarer and has a targeted solution.

### Consequences
Easier: reads, adding languages, versioning, exports.
Harder: cross-locale search and sort require deliberate indexing.
Revisit: if multilingual full-text search becomes central, add a search index
(`14` §7) — not a translations table.

---

## ADR-009: One storage bucket, per-tenant prefixes

**Status:** Accepted · **Date:** 2026-09-01

### Decision
Production uses one S3-compatible bucket, prefix `tenants/{tenant_uuid}/`.
Not a bucket per tenant.

### Trade-off
Bucket-per-tenant gives provider-level isolation but adds a failure-prone
provisioning step, hits account bucket limits in the low thousands, and
multiplies lifecycle, CORS and IAM configuration. A prefix is instant, free and
unbounded. Isolation is enforced by a single small, heavily tested `MediaStore`
plus signed URLs.

### Consequences
Easier: provisioning, lifecycle rules, deletion (one prefix operation).
Harder: isolation depends on application correctness, so `Kernel/Storage` must
stay small and covered by the isolation suite.
Revisit: if a regulatory requirement demands provider-level separation for a
specific tenant — `tenants.storage_disk` already allows per-tenant override.

---

## ADR-010: Explicit audit writes in Actions, not model observers

**Status:** Accepted · **Date:** 2026-09-01

### Decision
`Kernel/Audit::write()` is called explicitly inside Actions.

### Trade-off
Observers are less code and catch everything — including thousands of
meaningless `updated_at` changes. They cannot supply a **reason**, cannot
distinguish a customer cancelling from a job expiring, and silently miss
query-builder writes. An audit log nobody can query is not an audit log.
Explicit writes cost boilerplate and can be forgotten; the Definition of Done
(`11` §10) and feature tests are the counterweight.

### Consequences
Easier: meaningful, queryable, reason-carrying entries.
Harder: a developer can omit one — mitigated by required audit assertions on
money and customer-data features.

---

## ADR-011: Eloquent + Actions, no repository pattern

**Status:** Accepted · **Date:** 2026-09-01

### Decision
Eloquent models used directly inside Domain and Application layers. Actions are
the use-case and transaction boundary. No repository interfaces unless a second
real implementation exists.

### Trade-off
Repositories are usually justified as "swappable persistence" and "testability".
We will never swap MySQL, and we test against real MySQL (ADR-016), so both
justifications are absent. What remains is an extra layer to write, name and
navigate.

### Consequences
Easier: less code, idiomatic Laravel, faster onboarding.
Harder: domain code is coupled to Eloquent — accepted deliberately.
Boundary preserved: models stay **private** to their module (`04` §1); other
modules go through `Contracts/`.

---

## ADR-012: Control-plane login directory for staff mobile authentication

**Status:** Accepted · **Date:** 2026-09-01

### Context
One staff app serves every center, so the tenant is unknown at the login screen
and credentials live in tenant databases.

### Options
(a) Ask the user for their center code — poor UX, and users do not know it.
(b) Broadcast the login attempt to every tenant database — O(N) queries per
attempt and a trivial enumeration oracle.
(c) A control-plane directory mapping a hashed identifier to candidate tenants.

### Decision
Option (c). The directory stores an HMAC of the normalised identifier plus
tenant and user ids — **no credentials, no plaintext PII**. Password
verification always happens in the tenant database.

### Consequences
Easier: one login field, multi-center staff supported via a picker.
Harder: a second write path to keep in sync (eventually consistent; the tenant
`users` table remains authoritative).
**Security requirement:** unknown-identifier and wrong-password responses must
be identical in body and timing, or the directory becomes a way to enumerate
which phone numbers belong to Meta Style staff.

---

## ADR-013: Money as integer minor units plus an explicit currency exponent

**Status:** Accepted · **Date:** 2026-09-01

### Decision
`bigInteger` minor units + a `currency` column. Exponent from `Kernel/Money`.
Never float, never `decimal` for amounts, never a hardcoded 2 decimal places.

### Trade-off
The explicit-exponent part matters more than usual here: **IQD has 0 decimal
places.** A codebase that assumes 2 will be wrong by 100× on the primary
currency of the launch market — a bug that is easy to write and very expensive
to find after invoices have been issued.

### Consequences
**Reversal cost: very high.** Changing money representation after invoices,
payments and financial reports exist means migrating and re-verifying every
historical financial record.

---

## ADR-014: Idempotency keys from Phase 3

**Status:** Accepted · **Date:** 2026-09-01

### Context
By Phase 13 the API is called by mobile clients on unreliable connections,
at-least-once WhatsApp webhooks, and an AI that retries on timeout.

### Decision
`Idempotency-Key` is mandatory on money- and commitment-creating POSTs from
Phase 3, before any of those clients exist.

### Trade-off
Building it early costs a table and some middleware. Retrofitting it means
doing so after double bookings and double charges have already happened in
production, with reconciliation work attached.

### Consequences
Every mutating endpoint from Phase 3 onward is designed replay-safe from the
start, which also simplifies webhook and AI integration later.

---

## ADR-015: Failed jobs in the control database

**Status:** Accepted · **Date:** 2026-09-01

### Decision
`failed_jobs` lives in the control plane, not in each tenant database. Queues
are Redis, so no `jobs` table exists at all.

### Trade-off
Per-tenant failed-job tables would mean querying N databases to answer "what is
failing right now". One table gives operators one place to look. Job payloads
carry only a tenant uuid and identifiers — never tenant data (`02` §6) — so this
creates no isolation problem.

**Amended in Phase 2 (see ADR-025).** A `jobs` table now lives in the control
database too. The original "queues are Redis, so no jobs table" assumption made
Redis a hard prerequisite for proving that queued work runs under the right
tenant. The same reasoning that puts `failed_jobs` in the control plane applies
to `jobs`, and Redis remains the production driver.

---

## ADR-016: Real MySQL in tests, never SQLite

**Status:** Accepted · **Date:** 2026-09-01

### Decision
All database tests run against MySQL 8 and Redis, including in CI. A cached
schema-dump template keeps tenant-database creation fast (`11` §3.1).

### Trade-off
SQLite is faster and needs no service. But this system depends on MySQL JSON
functions, generated columns, functional indexes, `utf8mb4` collation, foreign
key behaviour, and locking semantics — none of which SQLite reproduces. A suite
that passes on SQLite and fails in production is worse than no suite.

### Consequences
Slower tests, an infrastructure dependency in CI, and the schema-template
harness must be built in Phase 1. In exchange, a green suite means something.

**Implementation note (Phase 1).** Removing `sqlite` from `config/database.php`
does not remove it: Laravel merges the framework's base config over the
application's, so the framework's `sqlite`, `pgsql` and `sqlsrv` connections
reappear and stay reachable via `DB_CONNECTION=sqlite` or
`DB::connection('sqlite')`. `AppServiceProvider::restrictDatabaseConnections()`
prunes the connection list to `control` and `tenant` so this decision is
actually enforced rather than merely documented. Covered by a test.

---

## ADR-017: Documentation and `CLAUDE.md` live in the `Meta_Style_Web` repository

**Status:** Accepted · **Date:** 2026-09-01

### Context
The workspace is `Desktop/Meta_Style/`, which is not a git repository. The only
repository is `Meta_Style_Web/` (`majdtlilat-loop/meta_style_web`), containing
one `README.md`.

### Decision
`docs/` and `CLAUDE.md` live in `Meta_Style_Web/`. A short pointer `CLAUDE.md`
sits at the workspace root for sessions started there.

### Trade-off
Documentation outside the repository is unversioned, unreviewable and invisible
to collaborators. `CLAUDE.md` belongs beside the code it governs. If the
workspace later holds several repositories (backend, Flutter, SADMIN), backend
architecture docs still belong with the backend.

**Reversal cost: trivial** — `git mv`. Flagged for confirmation in the Phase 0
report.

---

## ADR-018: `stancl/tenancy` behind Meta Style abstractions

**Status:** Accepted · **Date:** 2026-09-01 · **Supersedes:** ADR-003

### Context
ADR-003 proposed building the tenancy kernel from scratch to avoid the package's
ambient global state. The product owner rejected that premise: mature package
functionality should not be reimplemented without a concrete Meta Style
requirement.

### Decision
Adopt **`stancl/tenancy`** as the tenancy infrastructure, and wrap it in a thin
Meta Style layer. Business modules depend on `App\Kernel\Tenancy\Contracts\*` —
`TenantContext`, `TenantResolver`, `TenantProvisioningService` and the storage
abstractions — never on package classes, facades, or the `tenant()` / `tenancy()`
global helpers.

The package owns: database creation and switching, multi-database bootstrapping,
tenant-aware queues, cache and filesystem isolation, tenant identification,
migration plumbing.

Meta Style owns: the control-plane data model, migration orchestration with
per-tenant status and failure isolation, provisioning as a resumable business
pipeline, resolution policy, and the fail-closed guarantees.

### Options

| | Custom kernel (ADR-003) | Package, unwrapped | **Package + thin wrapper** |
|---|---|---|---|
| Code we own | ~500 lines + bootstrappers | ~0 | ~150 lines of adapters |
| Bootstrapper bugs | Ours to find | Solved | Solved |
| Coupling to package | None | Every call site | One directory |
| Replaceable later | N/A | No | Yes |
| Fail-closed guarantee | Built in | Must be added | Added deliberately |

### Trade-off
Unwrapped adoption is the cheapest today and the most expensive in two years —
`tenant()` spreads to hundreds of call sites and the package becomes a permanent
architectural commitment. Building from scratch pays for bootstrappers that are
already solved and easy to get subtly wrong. The wrapper costs a handful of
interfaces and buys both the package's maturity and the option to change our
mind.

The wrapper only holds if it is enforced: an architecture test asserts
`Stancl\*` is used only inside `App\Kernel\Tenancy`. Without that test this
decision decays into "unwrapped" within a few sprints.

### Consequences
Easier: tenant switching, queue/cache/filesystem isolation, provisioning
mechanics — all inherited rather than written and debugged.
Harder: two concepts of "tenant" (ours and the package's) must be kept aligned
in the adapter; package upgrades need a compatibility check at one boundary.
Carried forward from ADR-003: the abstraction boundary and the **mandatory
fail-closed requirement** (`02` §4), which is independent of the package and
must survive any future replacement of it.
**Reversal cost: medium** — replacing the package means rewriting the adapters
in `App\Kernel\Tenancy`, not the application.

---

## ADR-019: `Meta_Style_Web` is the canonical backend and web repository

**Status:** Accepted · **Date:** 2026-09-01 · **Extends:** ADR-017

### Decision
`Meta_Style_Web` contains the Laravel backend, Meta Style Web, center
administration, host/reception web, POS web, and the REST APIs. `/docs` and
`CLAUDE.md` stay here.

Flutter applications get their own repositories when their phases arrive:
Meta Style App (Phase 16), Meta Style SADMIN (Phase 16), White Label Customer
App (Phase 17). **They are not created now.**

### Trade-off
One repository for everything that shares the Laravel runtime and deploys
together; separate repositories for artifacts with independent build tooling,
release cadences and app-store lifecycles. Splitting the web surfaces apart
would mean coordinating deploys across repos for a single transaction path.

### Consequences
Web surfaces share modules, tests and CI. Flutter clients consume the published
API and version independently.

---

## ADR-020: Blade + Livewire 3 for Meta Style Web

**Status:** Accepted · **Date:** 2026-09-01

### Context
Meta Style Web must deliver a highly interactive administration and POS
experience with strong RTL/LTR control, built by a small team.

### Options

| | **Blade + Livewire** | Inertia + Vue/React | Separate SPA |
|---|---|---|---|
| Moving parts | One stack, one deploy | Two ecosystems | Two apps, two deploys |
| RTL control | Server-rendered, direct | Component-library dependent | Component-library dependent |
| Auth/session | Native | Native | Token plumbing |
| Localization | One system (`07`) | Duplicated in JS | Duplicated in JS |
| POS interactivity | Good; needs care on latency | Excellent | Excellent |
| Hiring pool | Laravel developers | Laravel + JS | Laravel + JS |

### Decision
Blade + Livewire 3. No React, Vue, Inertia, or separate SPA for Meta Style Web.

### Trade-off
Livewire round-trips make latency a design concern for the highest-interaction
surface, POS — a real cost, and the main risk of this decision. In exchange:
one language, one localization system, one auth model, no API contract between
our own frontend and backend, and no duplicated RTL work. For a small team
shipping seventeen phases, fewer moving parts wins.

Mitigation for POS: Alpine for local-only interactions, `wire:model.defer`, and
optimistic UI. If POS latency proves unacceptable under real conditions, POS
alone may become a separate client — the API already exists for the mobile apps.

### Consequences
Easier: development speed, localization, RTL, auth, onboarding.
Harder: heavy real-time interactions; offline POS is not on this path.
Revisit: at Phase 9, with POS measured on real hardware and real connections.
**Reversal cost: high after Phase 4**, once significant UI exists.

---

## ADR-021: Horizon deferred until an async workload justifies it

**Status:** Accepted · **Date:** 2026-09-01

### Decision
Do not install Laravel Horizon in Phase 1. The application is queue-ready;
Horizon is added when there are real production async workloads — realistically
Phase 2, when provisioning and tenant migrations run on queues.

### Trade-off
Horizon is genuinely useful once queues matter, and useless before that. In
Phase 1 there is not a single queued job, so it would be a dashboard for an
empty queue, plus a Redis requirement for contributors who do not otherwise
need one.

### Consequences
Contributors can run the project with no Redis. Adding Horizon later is
`composer require` plus config — nothing depends on its absence.

---

## ADR-022: PHP `^8.2`, not `^8.3`

**Status:** Accepted · **Date:** 2026-09-01

### Context
Phase 0 specified PHP 8.3+ without a requirement driving it. The local
environment is PHP 8.2.12.

### Decision
The project requires **PHP `^8.2`** — Laravel 12's own floor. Do not use syntax
or stdlib features requiring 8.3+ without explicit approval.

### Trade-off
8.3 offers typed class constants, `json_validate()` and readonly-class
improvements — convenient, none load-bearing for anything in `/docs`. An
arbitrary version floor that blocks the existing development environment on day
one is a self-inflicted cost.

### Consequences
Wider deployment compatibility. Raising the floor later is a `composer.json`
change plus a CI matrix update, and can happen whenever a real 8.3 feature earns
it.

---

## ADR-023: Native local environment supported; Docker optional

**Status:** Accepted · **Date:** 2026-09-01

### Decision
A normal local PHP + MySQL/MariaDB installation is a fully supported, first-class
development environment. Docker Compose may be provided as an optional
reproducible environment. CI uses a real MySQL-compatible database — **never
SQLite** (ADR-016 unchanged).

### Trade-off
Mandating Docker adds a hard prerequisite and, on Windows, real filesystem
performance costs — for a project whose only infrastructure dependency in Phase 1
is a database the developer already has. The cost of supporting both is keeping
`.env.example` honest and not depending on service names.

### Consequences
Contributors start with `composer install` and a database. Redis, MinIO and
Mailpit are optional throughout.
**Accepted risk:** local MariaDB and production MySQL 8 differ in JSON and
functional-index behaviour. Mitigated by making CI (MySQL 8) the authority
(`11` §2) — not by forcing every developer into Docker.

---

## ADR-024: Tenant database names derived from an internal sequence

**Status:** Accepted · **Date:** 2026-09-02

### Context
Creating a tenant database means executing `CREATE DATABASE <name>`. SQL cannot
bind a database identifier as a parameter — `CREATE DATABASE ?` does not exist —
so every name that reaches that statement is interpolated into it. The tenancy
package's own `MySQLDatabaseManager` interpolates directly.

### Decision
Names are **generated, never accepted**: `tenant_` plus the zero-padded value of
an `AUTO_INCREMENT` `sequence` column on `tenants`. `TenantDatabaseName` is the
only producer, and every path that creates, drops, migrates or binds a tenant
database calls `assertValid()` first. The prefix itself is validated, because
config is the one remaining route by which a string could reach DDL.

### Options

| | **Sequence-derived** | Slugified center name | Tenant UUID |
|---|---|---|---|
| Injection surface | None — no user input reaches it | Requires perfect sanitisation, forever | None |
| Readability in ops | `tenant_000001`, sortable | Readable | 36 opaque chars |
| Length limits | Comfortable | Names can collide after slugging | Near MySQL's 64-char limit |
| Renames | Unaffected | Name change implies database rename | Unaffected |

### Trade-off
A UUID-derived name is equally safe but unreadable in `SHOW DATABASES`, backup
filenames and slow-query logs — the places an operator actually looks. The
sequence gives ordering and brevity for free. Its cost is that the sequence
leaks signup volume, which is why the **public** tenant identity stays the UUID
and the sequence is never exposed in URLs, APIs or storage paths.

### Consequences
Easier: safe DDL by construction, readable operations, trivial sharding later.
Harder: two identifiers per tenant (UUID public, sequence internal) that must
not be confused — the value object exposes `key()` to make the right one
obvious.
**Reversal cost: high** — renaming live tenant databases.

---

## ADR-025: Cache must be taggable; queue must round-trip; Redis not required

**Status:** Accepted · **Date:** 2026-09-02

### Context
Phase 2 must prove two isolation properties locally, without Redis: the same
cache key under two tenants does not collide, and a queued job runs under the
tenant that dispatched it.

Two facts constrain this. Tenant cache isolation in `stancl/tenancy` works by
**tagging**, and only `array`, `redis` and `memcached` support tags — `file` and
`database` do not. And the `sync` queue driver executes inline with the
dispatching tenant still bound, so it cannot demonstrate anything about context
restoration.

### Decision
- **Cache: `array` locally and in tests, `redis` in production.** A non-taggable
  store is not a supported configuration, and a test asserts the configured
  store is taggable.
- **Queue: `database` locally and in tests** (a `jobs` table in the control
  database, amending ADR-015), `redis` in production. Isolation tests dispatch,
  then run a real worker, so the payload genuinely round-trips.

### Trade-off
Configuring `file` or `database` for cache would appear to work while silently
sharing one keyspace across every tenant — the exact failure this architecture
exists to prevent, and invisible at the call site. Making the requirement
explicit and asserted is worth the constraint.

Using `sync` in tests would have been simpler and would have proven nothing:
the test would pass because the tenant never changed, not because the job
restored it.

### Consequences
Easier: a full isolation suite runs on a laptop with only MySQL installed.
Harder: `array` cache does not survive a tenancy switch (each bootstrap builds a
fresh in-memory store), so local cache behaviour differs from production Redis.
Acceptable while nothing depends on cache persistence; revisit at Phase 12,
where report caching becomes real.

---

## ADR-026: Tenant migrations never create databases

**Status:** Accepted · **Date:** 2026-09-02

### Context
Laravel 12's `migrate` command creates a missing MySQL/MariaDB database rather
than failing (`MigrateCommand::createMissingMySqlOrPgsqlDatabase`). With
`--force` it does so silently, and there is no flag to disable it.

That is reasonable for a single-database application. Here it is dangerous: a
stale, mistyped or mis-sharded `tenancy_db_name` would produce a real, empty,
orphaned database that the control plane knows nothing about — and the migration
would report success.

### Decision
`TenantMigrator` checks that the database exists, on the central connection,
before entering tenant context, and throws `TenantDatabaseMissing` if it does
not. Provisioning creates databases; migration only migrates.

### Trade-off
One extra `information_schema` query per tenant per migration run, against
silently creating orphan databases and reporting a green migration. Discovered
by a test that expected a failure and got a success — worth noting that the
framework's helpful default was the bug.

### Consequences
A missing database now surfaces as a named, actionable error pointing at
`metastyle:tenant:provision --retry=<id>` instead of a phantom success.

---

## ADR-027: Tenant-bound API tokens

**Status:** Accepted * **Date:** 2026-09-03

### Context
A Sanctum token issued by one center must be inert against every other. Getting
this wrong means one business acting as another - the failure this architecture
exists to prevent.

### Decision
Two independent layers.

1. **Token rows live in the tenant's own database.** A custom
   `PersonalAccessToken` resolves on the `tenant` connection, so a token issued
   by Tenant A does not exist while Tenant B is initialised. There is no shared
   token table whose scoping could be got wrong.
2. **The issued string carries the tenant's public key** -
   `ctr_abc...|17|k9Xs...`. That makes the token a trusted tenant signal
   *before* any lookup, which is what allows a host/token mismatch to be refused
   and audited as a security event instead of surfacing as a bare 401.

### Options

| | **Tenant DB + prefix** | Tenant DB only | Shared table + tenant_id |
|---|---|---|---|
| Cross-tenant use | Impossible | Impossible | One forgotten `where` away |
| Mismatch detectable | Yes, audited | No - looks like a typo | Yes |
| Sanctum changes | None (prefix stripped in middleware) | None | Custom guard |

### Trade-off
The prefix is not a credential and is never trusted alone: it is resolved
against the control plane, and the Sanctum half must still match a row in that
tenant's database. It costs one middleware step that rewrites the Authorization
header back to Sanctum's own format, in exchange for a diagnosable, auditable
failure mode.

### Consequences
Tenant resolution MUST run before authentication. Laravel's middleware priority
list puts authentication ahead of custom middleware, so ordering the route array
is not enough - `prependToPriorityList()` is required, anchored on the
`AuthenticatesRequests` **interface**. Anchoring on the concrete `Authenticate`
class silently appends to the end of the list instead, which presents as a token
bug and took an hour to find.
**Reversal cost: medium** - issued tokens would need reissuing.

---

## ADR-028: Registration secrets never cross the queue

**Status:** Accepted, retention revised by ADR-031 * **Date:** 2026-09-03

### Context
Provisioning a center takes seconds and can fail, so it runs on a queue. But it
needs the owner's password to create their account, and a **failed job row can
sit in the database indefinitely**.

### Options

| | **Encrypted hash on the row** | Hash in the job payload | No password; activation link |
|---|---|---|---|
| Secret in `jobs` / `failed_jobs` | Never | Yes, potentially forever | Never |
| Secret at rest | Encrypted, for seconds | Readable, indefinite | None |
| Sign-up UX | Password set once | Password set once | Asked to set it twice |

### Decision
The password is hashed **in the web request**; the plaintext is discarded
immediately. The bcrypt hash is stored **encrypted with `APP_KEY`** on the
registration row. The queue payload carries only the registration uuid.

**Revised by ADR-031.** This ADR originally nulled the hash the moment
provisioning settled, success *or* failure. That made every failed
self-registration permanently unrecoverable, and the "Trade-off" section below
named the cost without recognising how often it would be paid. The hash is now
kept while the registration remains retryable and destroyed on every terminal
outcome. Everything else here still holds.

### Trade-off
The third option leaks nothing at all, but makes someone who just chose a
password choose it again, which is a real cost at the top of the funnel. The
chosen design narrows exposure to an encrypted value, in one row, for the
seconds provisioning takes - and accepted that a registration whose credential
has been cleared can no longer be retried into a working account. That
acceptance was wrong; see ADR-031.

### Consequences
Verified by tests that scan every column of `registrations`, `jobs`,
`failed_jobs`, `platform_audit_logs` and `tenant_operations` for the plaintext,
assert the stored value is not a readable `$2y$` string, and assert the column
is null once the registration reaches a terminal state.

---

## ADR-029: Owner is explicit grants, not an authorization bypass

**Status:** Accepted * **Date:** 2026-09-03

### Context
The center owner needs to do everything. The obvious implementation is
`if ($user->is_owner) return true` in the authorization path.

### Decision
The Owner **role** is seeded with an explicit grant for every permission in the
catalog, re-synced from code whenever the catalog grows. `is_owner` marks the
account for *protection* - it cannot be deactivated or have its roles changed by
others - and grants nothing.

### Trade-off
A bypass is one line and never goes stale. But it cannot be audited ("why could
they do that?" has no answer), cannot be narrowed by a center that wants a
restricted owner, and silently swallows every future security boundary the
moment it is added - including ones added for regulatory reasons. Explicit
grants cost a synchroniser and the discipline of remembering it.

### Consequences
`SystemRoleSynchroniser::syncOwner()` must run when the permission catalog
grows, or an owner silently lacks new permissions. It runs at provisioning
today; it must also run on deploy once there are live tenants. That is a Phase 4
deployment-step item and the most likely way this decision bites.

---

## ADR-030: The web session is a third trusted resolution source

**Status:** Accepted * **Date:** 2026-09-03

### Context
The Blade/Livewire UI signs in with a session, not a token. The staff account
lives in the tenant database, so the tenant must be resolved before the session
guard can load the user - but a session carries no host and no bearer token.

### Decision
At login the center's public key is written to the session, and the resolver
treats it as a third trusted source alongside the host and the token. It
participates in the conflict rule like any other source.

### Trade-off
Every additional source widens the surface the conflict rule has to police. The
session earns its place because it is genuinely server-side state - signed and
encrypted, not something the browser can edit - and the alternatives are worse:
either every center needs its own hostname before anyone can sign in, or the
center key travels on every request as a client-supplied parameter, which is
exactly what docs/02-TENANCY.md 2.1 forbids.

### Consequences
Sign-out must clear the key as well as the session, or the next visitor on that
browser resolves the previous center. Handled in the logout route and covered by
the web flow tests.

---

## ADR-031: Registration retry window and bootstrap credential lifecycle

**Status:** Accepted, authorization corrected by ADR-035 · **Date:** 2026-09-04 · **Supersedes part of ADR-028**

### Context
ADR-028 nulled the bootstrap credential "the moment provisioning settles —
success or failure", and named the cost honestly: a registration whose
credential has been cleared can no longer be retried into a working account.

In Phase 3 that cost turned out to be the common case, not the edge case.
Provisioning creates a database and runs migrations; it fails for ordinary
operational reasons — a full disk, a connection limit, a deploy mid-flight, a
misconfigured default plan. Every one of those failures permanently destroyed a
self-registration. The center owner could not retry, support could not rescue
it, and the only path forward was "register again, from the top, choosing a new
password". For a self-service signup funnel that is not acceptable.

The obvious fix — keep the credential — has to answer a real objection. A bcrypt
hash is not a password, but it is bcrypt over *the owner's* password, and owners
reuse passwords. An indefinitely retained hash is an indefinitely retained
liability.

### Decision
The credential is kept for exactly as long as it can still produce a working
center, and destroyed the instant it cannot.

**Kept** while the registration could still succeed:

| Status | Meaning | Credential |
|---|---|---|
| `preparing` | provisioning queued or running | kept |
| `failed` | failed, retryable until `credentials_expire_at` | kept |

**Destroyed** on every terminal outcome, with no exception:

| Status | Meaning | Credential |
|---|---|---|
| `ready` | the center exists; the owner account has its own hash now | destroyed |
| `cancelled` | given up on deliberately | destroyed |
| `abandoned` | the window elapsed; swept | destroyed |

The window is `METASTYLE_REGISTRATION_RETRY_WINDOW_HOURS`, default **24**.
`metastyle:registration:sweep` runs hourly and abandons what has expired.

Unchanged from ADR-028, and non-negotiable:

- the password is hashed **in the web request**; plaintext is never persisted;
- the hash is stored **encrypted with `APP_KEY`** (`encrypted` cast);
- the queue payload carries **only the registration uuid**;
- the credential never appears in audit entries, `tenant_operations`, `jobs`,
  `failed_jobs`, logs, exception text, or any API response — `owner_password_hash`
  is on the model's `$hidden`, and the exception text stored on the row is
  sanitised.

### Retry is a resume, not a restart
The pipeline was already idempotent; the retry path depends on it. A retry
re-enters `ProvisionRegisteredTenant` and produces **no** duplicate tenant,
database, subscription, main branch, owner account, role or grant. The
registration keeps its `tenant_id` from the first attempt, so a failure after
the tenant was created resumes against that same tenant rather than provisioning
a second one.

Three ways to trigger it, all the same code path:

- `POST /api/v1/public/registrations/{uuid}/retry` — public, throttled with the
  submit limiter. Unauthenticated in the sense that the person whose
  provisioning failed has no account to sign in to. **Amended by ADR-035:** this
  ADR originally treated the uuid itself as the capability, which was wrong —
  a uuid travels in redirect URLs, browser history, `Referer` headers and access
  logs. A separate high-entropy access token is now required alongside it.
- The "try again" button on the registration status page.
- `php artisan metastyle:registration:retry {uuid}` for support.

`Registration::isRetryable()` — not the status alone — decides. It requires the
status to be `failed` **and** the window to still be open, so a retry that could
only fail is refused rather than queued.

### Options

| | **Keep until the window closes** | Destroy on failure (ADR-028) | Keep indefinitely |
|---|---|---|---|
| Failed signup recoverable | Yes, for 24h | Never | Always |
| Hash retained | ≤ 24h, encrypted | Seconds | Forever |
| Sweep needed | Yes | No | No |
| Support can rescue | Yes | No | Yes |

### Trade-off
Destroying on failure is the strictly smaller exposure and we chose it first;
it was the wrong call, because it optimised a secondary risk (an encrypted hash,
in one row, behind `APP_KEY`) at the cost of the primary product function
(a person can sign up). Keeping it forever fixes the funnel and never closes the
exposure. The window is the honest middle: bounded, swept automatically, and
long enough that a human notices a failed signup and acts on it.

### Consequences
- New columns: `credentials_expire_at`, `settled_at` (expand-only migration).
- New statuses `cancelled` and `abandoned`; `failed` is no longer terminal.
- `CenterBootstrapper` throws `RegistrationFailed::credentialExpired()` rather
  than creating a passwordless owner if it ever reaches a retry with no
  credential and no existing owner.
- The scheduler must run. Without `metastyle:registration:sweep`, hashes are
  retained past their window — the one failure mode this ADR introduces.
- Proven by `tests/Feature/Saas/RegistrationRetryTest.php` (retryability,
  no-duplicates, destruction on success/retry/cancel/sweep, refusal past the
  window) and `tests/Feature/Saas/RegistrationExposureTest.php` (serialisation,
  logs, exception text, audit, queue payload, API surface).

---

## ADR-032: System roles are synchronised on every deploy

**Status:** Accepted · **Date:** 2026-09-04

### Context
ADR-029 makes Owner a set of explicit grants rather than an
`if ($user->is_owner) return true` bypass. That is the right design and it has a
consequence nobody notices until it bites: the Owner role's grants are rows in
each tenant's database, written once at provisioning.

A release that adds `booking.appointment.cancel` to the catalog therefore leaves
every center provisioned before it with an Owner who does not hold it. Nothing
errors. Nothing logs. The owner of a center that pays for booking finds a button
that does nothing, and the bug reaches support as "the system is broken".

### Decision
`SystemRoleSynchroniser::synchronise()` brings one tenant's system roles in line
with the catalog, and `metastyle:roles:sync --all` runs it across every
provisioned tenant. **It is part of the deploy sequence**, immediately after
`metastyle:tenant:migrate --all` (docs/12 §3).

Deliberately conservative about a center's own decisions:

- **Custom roles are never touched.** Only `is_system` roles are considered.
- **Role assignments are never touched.** Who holds which role is the center's
  business, not the platform's.
- **Non-owner system roles keep the set they were seeded with.** A center that
  narrowed Manager meant it; nobody silently gains access because a feature
  shipped.
- **Owner gains new catalog permissions**, because Owner means "everything" by
  definition, and an owner locked out of a feature they pay for is an incident.

Operationally it behaves like the tenant migrator, for the same reasons: each
tenant is an independent attempt inside its own context, a failure is returned
as a `SystemRoleSyncResult` rather than thrown, one tenant failing neither stops
the run nor affects another, the context is restored by a `finally` even when a
tenant dies mid-sync, and the command exits non-zero if anything failed so a
deploy notices. Unprovisioned tenants are **skipped**, not failed — reporting
them as failures would train operators to ignore the exit code.

Audit entries are written only when something actually changed, or when a tenant
failed. A deploy that syncs a thousand unchanged tenants must not write a
thousand rows saying nothing happened.

### Options

| | **Sync command in the deploy** | Sync lazily on login | Owner bypass |
|---|---|---|---|
| Existing owners get new permissions | Yes, at deploy | Yes, eventually | N/A |
| Cost | One pass per release | Every login | None |
| Failure visible | Non-zero exit | Silent | N/A |
| Keeps ADR-029 | Yes | Yes | **No** |

### Trade-off
The bypass makes this whole problem disappear and is rejected outright: it
destroys the audit story ("what could this account do on that date?" becomes
unanswerable), makes least-privilege untestable, and is precisely what ADR-029
exists to prevent. Lazy sync spreads the cost across every login and hides
failures inside request handling. A deploy-time pass is the one place where the
work is bounded, observable, and already gated.

### Consequences
- `metastyle:roles:sync --tenant=<id> | --all [--dry-run]`.
- The deploy sequence gains a step, and a release that adds a permission but
  skips it ships a silent gap.
- Proven by `tests/Feature/Identity/SystemRoleSyncTest.php`: an existing owner
  gains a catalog permission and can use it, repeat runs change nothing and
  create no duplicates, custom roles and narrowed system roles and role
  assignments survive untouched, one broken tenant does not affect another, and
  no tenant remains bound after a run.

---

## ADR-033: Portable schema first; engine-specific indexes only on evidence

**Status:** Accepted · **Date:** 2026-09-04

### Context
Development runs on MariaDB 10.4 and CI runs on MySQL 8. MySQL 8 can index a
JSON path through a functional or generated-column index; MariaDB 10.4 cannot do
it the same way. Phase 4 introduces the service catalog and the electronic menu,
both built on translatable JSON columns — exactly the place where someone would
reach for a JSON functional index because CI proves it works.

That reach would be a mistake in two directions at once. It would quietly make
MariaDB unsupportable, and it would optimise a query nobody has measured, on a
table that holds a few dozen rows per center.

### Decision
Start portable. **Do not** add a MySQL-8-only JSON functional index, generated
column, or full-text index merely because the engine supports it.

A DB-specific index may be added only when **all three** hold:

1. a real query exists in the codebase that needs it;
2. `EXPLAIN` on representative data demonstrates the problem;
3. the index measurably improves a path that matters.

Then: record the evidence in this file, and allow the construct in
`tests/Architecture/DatabasePortabilityTest.php`, which currently fails the
build on `storedAs`, `virtualAs`, `GENERATED ALWAYS`, `JSON_VALUE`,
`JSON_EXTRACT`, `JSON_UNQUOTE` and `->fullText(` anywhere in a migration.

Nothing here restricts **using** JSON or translatable columns — that is the
approved localisation architecture (ADR-011, docs/07). The restriction is on
indexing them in an engine-specific way.

Two portability rules are enforced alongside it, because they are the other
places the two engines silently disagree:

- **No default on a JSON, TEXT or BLOB column.** MariaDB accepts it; MySQL 8
  rejects it outright, so the migration is green locally and red in CI.
- **`utf8mb4` / `utf8mb4_unicode_ci` named explicitly** on both connections.
  MySQL 8 would otherwise default to `utf8mb4_0900_ai_ci`, which does not exist
  in MariaDB at all.

### Trade-off
This will, eventually, cost a query that would have been faster with a
functional index. That is a smaller cost than losing engine portability for a
menu with a few dozen rows, and it is reversible the moment there is a measured
reason.

### Consequences
CI on MySQL 8 remains the authority on what actually runs; the architecture test
catches the known divergences before the push rather than after it.

---

## ADR-034: Production requires a shared rate-limit backend

**Status:** Accepted · **Date:** 2026-09-04

### Context
Laravel's rate limiter counts in the cache store. Locally and in tests that
store is `array`, which is per **process**. In a production deployment with N
PHP workers, an `array` or `file` store gives every worker its own counter, so a
published limit of "5 login attempts per minute" actually admits 5 × N.

The failure is completely silent. The limiter still returns 200s and 429s. It
still looks like a working limiter in every test. Nothing logs the fact that the
brute-force protection on the login endpoint is not what it claims to be.

### Decision
Local and test runs continue to need **no Redis** — that split is deliberate and
ADR-025 keeps it.

Production **must** use a rate-limit backend shared across every process and
every server. Redis is the recommended one. Combined with ADR-025's requirement
that the cache store be taggable, Redis is in practice the only configuration
that satisfies both: `database` is shared but cannot do tags, `array` can do
tags but is per-process.

This is checked rather than documented-and-hoped:

- `metastyle:doctor --production` fails the deploy on a per-process or
  per-server limiter store, a non-taggable cache store, a `sync`/`null` queue,
  `APP_DEBUG=true`, or a missing `APP_KEY`, and warns on a shared-but-slow
  store or file sessions.
- On boot in production, `AppServiceProvider` logs each failing check at
  `critical`. Logged, not thrown: unlike middleware ordering, these are
  conditions a running site survives, and refusing to boot would turn a degraded
  deployment into an outage.

### Trade-off
A doctor command is one more step that can be skipped. The alternative —
refusing to boot — converts a misconfiguration into downtime, which is worse for
conditions the site can survive. Middleware ordering is the one case where the
opposite is true, and there the boot **does** fail (ADR-027).

### Consequences
`metastyle:doctor` belongs in the deploy pipeline after `config:cache` and
before the app takes traffic (docs/12 §3, §11). Verified by
`tests/Feature/ProductionReadinessTest.php`, including a test that the store the
check inspects is genuinely the one the rate limiter uses — otherwise the whole
check could be reading a config key nothing reads.

---

## ADR-035: A registration's uuid is a locator, not a capability

**Status:** Accepted · **Date:** 2026-09-05 · **Amends ADR-031**

### Context
ADR-031 built the retry path and said, of the public retry endpoint: *"The uuid
is the capability: it is unguessable, it is already what the status endpoint
accepts, and it grants nothing beyond retrying this one registration."*

Every clause of that is true and the conclusion is still wrong. Unguessable is
not the same as secret, and a uuid in this flow is not treated as one anywhere:

- it is in the redirect URL after signing up, so it is in browser history;
- it is in the `Referer` header of any outbound link on the status page;
- it is in the web server's access log, and in any proxy or CDN log in front of it;
- it is what a person pastes into a support chat when provisioning fails.

"Grants nothing beyond retrying this one registration" also understates the
reach. It grants reading a stranger's registration state — center name, owner
identity, failure reason — and it grants enqueueing provisioning work against
their tenant, repeatedly, for as long as their retry window is open.

### Decision
Split the two jobs the uuid was doing:

| | |
|---|---|
| `registrations.uuid` | public **locator**. Identifies. Authorises nothing. |
| access token | secret **capability**. 256 bits from `random_bytes`. |

Stored as `access_token_hash` = `hash('sha256', $plaintext)`. Plaintext is
returned to the registering client **once**, in the `202` body of
`POST /registrations`, and is persisted nowhere.

**Presented in the `X-Registration-Token` header**, never a query parameter —
a capability in a URL reaches exactly the places listed above, which is the
problem this ADR exists to fix. The browser flow keeps it in the session
instead, keyed per registration.

**SHA-256, not bcrypt**, in the same table where `owner_password_hash` is
bcrypt. The difference is what is being hashed. A password is low-entropy and
human-chosen, so the digest needs to be expensive to survive an offline attack.
This is 256 bits of CSPRNG output: there is nothing to guess, and a fast digest
is what makes a constant-time `hash_equals` affordable on an endpoint polled
every two seconds. Sanctum makes the same call for the same reason.

**A wrong token and an unknown uuid return the same 404.** Distinguishing them
would rebuild the enumeration oracle the ADR is closing. `409
REGISTRATION.NOT_RETRYABLE` is reachable only after the capability verifies.

**No token is minted for a repeated submission.** Idempotency returns the
original registration with a null token; otherwise anyone who can guess the
idempotency inputs — a center name and an email address — could mint themselves
a capability for a registration they do not own.

### Lifecycle

| Status | Read | Retry | Capability |
|---|---|---|---|
| `preparing` | yes | — | held |
| `failed` | yes | **yes** | held |
| `ready` | yes, until `access_expires_at` | **no** | held briefly, then swept |
| `cancelled` | no | no | destroyed with the credential |
| `abandoned` | no | no | destroyed with the credential |

The read grace after `ready` (`METASTYLE_REGISTRATION_STATUS_GRACE_MINUTES`,
default 60) exists for one concrete reason: the client is *polling* when
provisioning succeeds, and that next poll is how the owner learns their center
key. Destroying the capability at the instant of success would refuse it.

Retry stays impossible throughout the grace, and not by a status check bolted
onto the endpoint: `isRetryable()` requires status `failed`, and `ready` cannot
return to `failed`. The grace cannot resurrect a retry because there is no path
back to the state retry requires.

### Options

| | **Separate secret capability** | uuid as capability (ADR-031) | Full auth for pending registrations |
|---|---|---|---|
| Survives a leaked URL | Yes | No | Yes |
| Enumerable | No | Effectively no, but readable if seen | No |
| New auth system | No | No | **Yes** |
| Cost | One column, one header | None | An account before there is an account |

### Trade-off
The third option is what a general-purpose answer would reach for and is
rejected: building sign-in for an entity that exists precisely because the user
has no account yet is a second authentication system to secure, and the thing
being protected is a status string and a retry button. One high-entropy token
matched in constant time is proportionate.

The real cost accepted here is that a client which loses its token has lost
access to its own registration, with no recovery path — no email re-send, no
reissue. That is deliberate: every reissue mechanism is a new way to take over
someone else's registration, and the fallback ("register again") is cheap
because nothing has been charged and no account exists yet.

### Consequences
- `RegistrationService::register()` now returns
  `array{registration: Registration, access_token: string|null}` rather than a
  model. Callers must hand the token to the client and forget it.
- `access_token_hash` and `access_expires_at` on `registrations` (expand-only).
- `metastyle:registration:sweep` gained a second, independent sweep for
  capabilities past their grace.
- `token` is already a whole-segment match in `Redactor::SENSITIVE_SEGMENTS`, so
  `access_token_hash` redacts from audit payloads without a change.
- Proven by `tests/Feature/Saas/RegistrationCapabilityTest.php`: uuid alone
  cannot read or retry, a wrong token is byte-identical to an unknown uuid, a
  token from another registration is refused, the correct token works for both
  operations, only the digest is persisted, the plaintext reaches no log, audit
  row or queue payload, ready/cancelled/abandoned cannot be retried, and both
  the authorised and unauthorised paths remain throttled.

---

## ADR-036: The public menu resolves its center from the URL, and only there

**Status:** Accepted · **Date:** 2026-09-05

### Context
The platform rule is absolute everywhere else: a tenant identifier never comes
from the client. Resolution is by host, by API-token prefix, or by web session
(ADR-027, ADR-030).

The electronic menu breaks the assumption those three sources rest on. A
customer scans a QR code on a table. They have no session, no token, and — for
the great majority of centers, who will never buy a domain — no distinct host.
Something in the URL has to say which center, or the menu is unreachable.

### Decision
A **fourth resolution source, confined to guest-facing routes**:
`GET /m/{center}` and `GET /api/v1/menu/{center}`, where `{center}` is the
center's **public key** — already the opaque, revocable, rotatable identifier
used for exactly this purpose (ADR-027). Never the internal id, never the
sequence.

What makes it safe is the boundary, not the identifier:

- **A separate middleware.** `ResolveTenant` is untouched, so nothing
  authenticated gains a path-segment resolution source.
- **It may never share a route with authentication.** Enforced by
  `tests/Feature/Menu/PublicRouteBoundaryTest.php`, not by care.
- **The routes are read-only**, throttled, and locale-resolved. Also asserted.
- **The response is an allow-list.** `PublicMenuResource` names every field it
  emits; a deny-list would be defeated the day someone adds a `cost_price`
  column and forgets the file.
- **Conflicts are still refused.** A request arriving on one center's host with
  another center's key in the path is a bug or an attack, and gets the same
  403-and-audit as everywhere else.

An unknown key returns a plain 404, indistinguishable from any other missing
page, so the endpoint cannot be used to enumerate which centers exist.

### Options

| | **Public key in the path** | Host only | Signed URL per menu |
|---|---|---|---|
| Works with no domain | Yes | **No** | Yes |
| Works on a printed QR code | Yes | Yes | **No** — signatures expire |
| Enumerable | No (unguessable, revocable) | N/A | No |
| Reuses an existing identifier | Yes | Yes | No |

### Trade-off
Host-only resolution keeps the rule pure and makes the product unusable for
every center without a domain, which is most of them. A signed URL is stronger
but cannot be printed on a card that has to work in a year. The public key is
already designed to be handed out; putting it in a URL is what it is for.

The cost accepted: a center's menu URL is guessable to anyone who has seen it,
which is true of every public web page and is the point of publishing one.

### Consequences
`ResolvePublicTenant`, the `public.tenant` alias, and a route-boundary test that
fails the build if the two resolvers ever meet or an auth middleware appears.
The `Tenant` value object now carries `publicKey`.

---

## ADR-037: Service catalog shape — one category, inherited variations, no branch pricing

**Status:** Accepted · **Date:** 2026-09-05

### Context
Three modelling choices in the Phase 4 catalog could each reasonably have gone
the other way, and each would be expensive to reverse once centers have data.

### Decision

**1. A service has ONE menu category and ONE department, both nullable.**
Many-to-many categories buy nothing a center has asked for and make every menu
query a join with ambiguous ordering — "which category does this service sort
under" stops having an answer. Both are nullable because a center that has not
organised itself yet must not be blocked from adding a service.

Department and category stay separate tables. A department is operational (Hair,
Laser, Hammam) and will drive the service journey, queue routing, rooms and
reporting; a category is customer-facing menu grouping, and a center may well
put services from three departments into one category. One table with an
`is_operational` flag would force every future query to remember the flag, and
the one that forgot would route a customer to a menu heading.

Their archive semantics differ, which is the clearest proof they are different
things: archiving a **category** detaches its services and they carry on
selling; archiving a **department** is refused while it still has active
services, because a service without operational routing is a gap Queue and the
Journey will fall into.

**2. A variation with a NULL price or duration INHERITS from its service.**
Not zero, and not a copy taken at creation. A center that raises the base price
of a haircut expects the variations that never had their own price to follow —
the alternative is discovering three months later that two of five variations
kept the old price because they were snapshotted. `effectivePrice()` resolves it
on read, and the public menu emits the resolved value so a customer never sees
a blank.

**3. Branch-specific pricing is DEFERRED**, and documented as deferred.
Availability per branch exists (`available_at_all_branches` plus a pivot, where
"everywhere" is the absence of rows so adding a branch does not mean touching
every service). Per-branch *prices* turn the price list into a matrix, and no
requirement has asked for one. Add it when a center does.

Buffers and preparation time are deferred for the same reason: a field the UI
writes and nothing reads is a promise, not a feature. Booking will introduce
them with the logic that consumes them.

### Trade-off
Single-category will eventually not fit some center that wants a service in both
"Bridal" and "Hair". When that arrives it is an expand migration and a pivot —
cheap, because nothing depended on the cardinality. Doing it now would make
every menu query harder for a requirement nobody has.

### Consequences
Prices are integer minor units in the center's currency; the currency is a
center-level setting rather than a per-service column, so one center cannot end
up with a menu mixing IQD and USD.

---

## ADR-038: The electronic menu is configuration, not a page builder

**Status:** Accepted · **Date:** 2026-09-05

### Context
Centers want their menu to look like theirs. The obvious next step from "choose
a colour" is a custom CSS box, and from there an HTML block, and from there a
page builder.

Every one of those steps is a security decision disguised as a feature request.
The menu is **guest-accessible**: a center-authored `<script>` on it is stored
XSS against that center's own customers, executing on a page the platform
serves. Unrestricted CSS is barely better — it can position an element over a
link, hide a price, or restyle the page into something else entirely.

### Decision
Presentation is a **closed catalog in `config/menu.php`**: four templates, a
fixed set of theme options, and a fixed list of sections. Everything a center
can change is a choice from that catalog. There is no field anywhere that
accepts HTML, CSS or JavaScript, and `MenuPresentation` is the single validator
every surface goes through.

Unrecognised values are **rejected, not dropped**. Silently discarding a value
would leave the stored draft differing from what the owner believed they
configured.

**A section for a module that does not exist is not in the catalog at all.**
No Offers placeholder, no Reviews placeholder. A section that renders an empty
box promising a feature the center has not bought is worse than its absence,
and seeding fake offers to make a template look good would be inventing business
data.

**Draft → publish → rollback** in one table with three statuses. Editing writes
to a draft so the live page does not change under a customer who is reading it;
publishing archives what was live; rollback republishes an archived version as a
NEW version, so history stays append-only and "what was live on the 3rd" keeps
one answer.

### Options

| | **Closed catalog** | Custom CSS field | Full page builder |
|---|---|---|---|
| Stored XSS possible | No | Via CSS tricks | Yes |
| Center can express a brand | Colours, fonts, layout | More | Fully |
| Phase 4 cost | Small | Small | Large |
| Reviewable output | Yes | No | No |

### Trade-off
A center with a strong brand will find this limiting, and that is a real product
cost. It is smaller than the cost of the first support ticket that begins "a
customer's card details were captured on our menu page". Higher-tier custom
styling can be evaluated later, with a review step and a sandbox — deliberately
not in the phase that first exposes a public page.

### Consequences
`config/menu.php` is the catalog; growing a template's options is a change to
that file, not a migration across every tenant database. Verified by
`tests/Feature/Menu/MenuPresentationTest.php`, which asserts that colours,
fonts, section keys and section settings are all rejected when outside the
catalog, and that no markup-bearing field exists.

---

## ADR-039: Phone normalisation without libphonenumber

**Status:** Accepted · **Date:** 2026-09-06

### Context
Phone is the customer's identity inside a center. `0750 123 4567`,
`+964 750 123 4567` and `964-750-123-4567` are one human being, and a CRM that
files them as three customers splits that person's bookings, sales and loyalty
three ways. Nobody notices until they ask why their visits are missing.

The obvious dependency is **`giggsey/libphonenumber-for-php`** — Google's
library, mature, correct, and the answer most codebases reach for.

### Decision
Not adding it. `Kernel\Contact\PhoneNumber` does the normalisation in about a
hundred lines: strip formatting, resolve `00` to `+`, resolve a national trunk
`0` against the configured country, emit E.164.

libphonenumber's real value is **validating and formatting numbers across 200+
countries**, and it carries roughly ten megabytes of metadata to do it. Meta
Style launches in Iraq and needs one thing from that set: collapse the spellings
of one national number to one canonical string. Paying a 10 MB dependency and
its autoload cost for the other 99% of the library is not a trade this phase
justifies.

The escape hatch is deliberate and cheap: **nothing else in the codebase parses
a phone number.** Swapping this class for libphonenumber is one file.

### What it does NOT do
- **It does not validate.** A seven-digit number normalises happily. Deciding a
  number is real and reachable needs a verification provider, which Phase 5
  does not have (ADR-040), and pretending otherwise would be worse than silence.
- **It does not guess a country.** Bare national numbers resolve against the
  deployment's configured default only. Guessing would file customers under the
  wrong country code, and the wrong country code is a different person.

### Options

| | **Small normaliser** | libphonenumber | Store raw |
|---|---|---|---|
| Collapses spellings | Yes | Yes | **No** |
| Validates worldwide | No | Yes | No |
| Dependency size | None | ~10 MB metadata | None |
| Swap cost later | One class | — | Every call site |

### Trade-off
A number from a country not in `config/metastyle.contact.countries` and typed
without a `+` normalises against the default country, which would be wrong. In a
single-country product that case is theoretical; in a multi-country one it is
the reason to adopt libphonenumber. The trigger to switch is a real second
market, not a hypothetical one.

### Consequences
`PhoneNumber` reads `metastyle.contact.default_country` from config, so its
tests live in the Feature suite rather than Unit — the Unit suite deliberately
runs without an application container. Making the class pure would mean
threading a country through every call site to avoid one honest config read.

---

## ADR-040: Customer accounts ship without phone verification, and say so

**Status:** Accepted · **Date:** 2026-09-06

### Context
A customer account is keyed on a phone number, and a phone number is only
meaningful as an identity if the person holding the account actually controls
it. Verifying that needs an SMS or WhatsApp provider — a vendor contract, a
sending identity, delivery-failure handling, rate limiting, and a notification
infrastructure that does not exist until Phase 13.

The tempting shortcut is to add the column and set it at registration, so the
flow "looks finished".

### Decision
**`phone_verified_at` exists, is nullable, and no Phase 5 code path ever writes
a value to it.** `CustomerAccount::hasVerifiedPhone()` therefore returns false
for every account in the system, and every surface that reports it says so
plainly rather than omitting the field.

Password authentication is the foundation that ships. Actions that genuinely
require proven ownership of a number — changing it, recovering an account,
receiving a booking reminder as proof of identity — do not exist yet, and will
gate on `hasVerifiedPhone()` when they arrive.

An architecture test asserts that **no application file assigns a non-null value
to that column**, so "we will add verification later" cannot quietly become "we
marked everyone verified".

### Options

| | **Column, never set** | Set it at registration | No column at all |
|---|---|---|---|
| Honest | Yes | **No** | Yes |
| Verification later | Nullable column already there | Backfill of lies | Migration + call sites |
| Can be trusted by later code | Yes | No | N/A |

### Trade-off
Setting the flag would make the account flow feel complete and would be a lie
the rest of the system then trusts — the first feature to gate on it would be
gating on nothing. Omitting the column entirely is honest too, but costs a
migration across every tenant database plus a sweep of every call site the day
verification lands. A nullable column nobody writes is the cheapest honest
option.

### Consequences
The customer API returns `phone_verified: false` explicitly. A center cannot
distinguish "verified" from "unverified" customers, because none are verified.
When Phase 13 brings a provider, the work is a verification flow and a gate —
not a data migration.

---

## ADR-041: Customer is not an account, and neither is a staff user

**Status:** Accepted · **Date:** 2026-09-06

### Context
Three things could plausibly have been one table: the CRM record of a person,
that person's login, and a member of staff. Merging any pair of them is the
kind of shortcut that is cheap on day one and structural by Phase 9.

### Decision
Three separate concerns, enforced by the schema and by the framework.

**1. `customers` holds no credential.** There is no password column and there
never will be; a login is a `customer_accounts` row, `customer_id` unique, at
most one per customer. This makes **guest the absence of a row** rather than a
flag: `isRegistered()` asks whether an account exists, so the two can never
disagree, and a guest becoming registered is an INSERT rather than a new
customer (Phase 5 §§4, 9).

**2. A customer is not a staff `User`.** Different table, different guard,
different auth provider, and no roles or permissions at all. The separation is
enforced by Sanctum rather than by care: `hasValidProvider()` compares a token's
owner against its guard's provider model, so a `CustomerAccount` token cannot
satisfy `auth:sanctum` (provider `staff`) and a `User` token cannot satisfy
`auth:customer-api`. Both directions are tested.

**3. A customer belongs to the CENTER, not a branch.** No `branch_id` on
`customers` — the same person books at Karrada on Monday and Mansour on Friday
and keeps one profile. Bookings and sales carry the branch; the customer does
not (Phase 5 §20).

Tenant binding is inherited unchanged: customer tokens use the same
`ctr_<key>|<id>|<plain>` format and their rows live in the center's own
database, so a Tenant A customer token finds nothing under Tenant B (ADR-027).

### Web sign-in
Customer sign-in is a **central** route that names its center in the form and
records it in the session — the staff path from ADR-030 — and then redirects to
a tenant-bound account page. It is deliberately NOT under `/m/{center}`:
`ResolvePublicTenant` accepts a public key from the URL path and may never sit
on a route that authenticates anybody (ADR-036). Keeping sign-in central
preserves that boundary exactly.

### Trade-off
Two auth providers and two guards is more configuration than one table with an
`is_customer` flag. The flag version is one forgotten `where` clause away from a
customer holding a staff permission, and there is no test that could
convincingly rule that out across a whole codebase. The framework enforcing the
split is worth the extra config block.

### Consequences
`config/auth.php` gains a `customers` provider and `customer` / `customer-api`
guards. The Sanctum authentication callback now recognises both owner types and
refuses any third — it only ever narrows validity, so it cannot undo the
provider check.

---

## ADR-042: PII is masked once, in a presenter, and fingerprinted in audit

**Status:** Accepted · **Date:** 2026-09-06

### Context
Customers are Meta Style's first real PII domain. Two separate leaks are easy to
create and hard to notice:

1. A member of staff who should be able to **find** a customer being able to
   **read their contact details**.
2. Those details being copied into the audit trail, which has different readers,
   different retention and no masking of its own.

### Decision

**Masking happens in `CustomerPresenter`, which every surface calls.** The API
controller and the Livewire screens both go through it, so there is one
implementation of "may this viewer see a phone number". Masking in a Blade
template would still send the value to the browser, still put it in the JSON a
mobile client receives, and still leave it in whatever export reused the query.
An architecture test fails the build if a template reaches for `->phone` or
`->email` outside the three deliberate exemptions.

`customer.view` finds a customer. `customer.contact.view` shows how to reach
them. Cashiers and employees hold the first and not the second, so masking — not
role design — is what protects the details they do not need.

**Searching by phone requires `customer.contact.view`.** Otherwise the list
becomes an oracle: type a full number, learn whether that person is a customer
here, one query at a time. For a laser clinic or a beauty center, that is
information people have a real interest in keeping private
(docs/06 §6, rule 4).

**Audit records a keyed fingerprint, never a value.** Phone and email are
recorded as `has_phone: true` plus
`hash_hmac('sha256', $value, APP_KEY)` truncated to 16 characters. A bare
`sha256` would not do: the Iraqi mobile space is roughly 10⁹, which a laptop
enumerates in seconds, so an audit log of unkeyed digests is an audit log of
phone numbers. The existing staff `identifier_fingerprint` moves onto the same
helper — leaving one PII fingerprint weak while hardening another would be
incoherent.

**A note's body is never audited.** The trail records that a note was written,
by whom, at what visibility, and how long it was. Copying the text would
duplicate whatever sensitive thing it says into a second table.

The **name** is the one piece of customer PII deliberately kept in audit, as the
target label: a trail of anonymous uuids answers nothing during an
investigation.

### A leak this found
The first implementation defaulted a self-registering customer's name to their
phone number when no name was given. Name is never masked, so that put the
number in front of every viewer the masking was meant to stop — and into the
audit label. Registration now **requires a name** for a new customer, which is
also better data.

### Trade-off
Rotating `APP_KEY` breaks correlation with fingerprints written before the
rotation. They are a diagnostic aid, not a record anyone is entitled to reverse,
so that is the right way round.

### Consequences
`Kernel\Privacy\ContactMasker` and `Kernel\Privacy\Fingerprint`. Masked values
are never used as query filters. Two architecture tests and fourteen feature
tests hold the boundary, including one asserting the API and the web produce the
same masking from the same code.

---

## Pending decisions (must be locked before their phase)

| # | Question | Lock before | Default if unanswered |
|---|---|---|---|
| ~~P-01~~ | ~~Repository layout~~ | — | **Resolved 2026-09-01 → ADR-019** |
| ~~P-02~~ | ~~Frontend for Meta Style Web~~ | — | **Resolved 2026-09-01 → ADR-020** |
| ~~P-03~~ | ~~PHP version~~ | — | **Resolved 2026-09-01 → ADR-022** |
| ~~P-04~~ | ~~Tenancy implementation~~ | — | **Resolved 2026-09-01 → ADR-018** |
| ~~P-05~~ | ~~RBAC implementation~~ | — | **Resolved 2026-09-03 → ADR-007 accepted** |
| P-06 | Hosting, MySQL topology, expected year-one tenant count | Phase 2 | Single managed MySQL 8, vertical scaling |
| ~~P-07~~ | ~~SADMIN placement~~ | - | **Resolved 2026-09-03: separate Flutter app and repo; no platform auth built** |
| P-08 | Iraqi VAT applicability and legal invoice requirements | Phase 9 | Tax-capable schema, tax disabled by default |
| P-09 | Payment provider accounts and sandbox access | Phase 10 | Adapter contract only; no provider promised |
| P-10 | WhatsApp: Meta Cloud API direct vs BSP | Phase 13 | Adapter supports both; pilot with a BSP |
| P-11 | Queue voice: pre-recorded fragments vs TTS (Sorani availability) | Phase 8 | Pre-recorded, TTS fallback |

---

## Template for new entries

```markdown
## ADR-0NN: Title

**Status:** Proposed · **Date:** YYYY-MM-DD · **Deciders:**

### Context
What forces are at play?

### Decision
What we are doing.

### Options
| | Option A | Option B |
|---|---|---|

### Trade-off
Why A over B, honestly.

### Consequences
Easier: …  Harder: …  Revisit if: …
Reversal cost: low | medium | high | very high
```

---

## ADR-043: The public menu may take a booking

**Status:** Accepted · **Date:** 2026-09-07

### Context
ADR-036 allowed the center's public key in the URL path for the guest
electronic menu, on a strict condition: it must never become a way to **act as**
a center. Every route it guarded until now was a read.

Phase 6 needs a guest to be able to book from that page. That is a WRITE on a
path-resolved surface, so the condition deserves re-examining rather than
assuming.

### Decision
Guest booking is allowed on the `public.tenant` surface, in its own route group
with its own throttle and a mandatory idempotency key.

The reasoning is about what the caller can DO, not about how the tenant was
found. What this endpoint lets someone do is create a booking for themselves —
the same thing they could do by walking in. It does not let them read a center's
data, reach an authenticated surface, choose a branch the center has not
published, book a service marked "call to book", or claim a privileged source.

The limits that keep it safe are the same ones ADR-036 relied on, plus two more:

- **No authentication middleware on the route, ever.** Enforced by a test for
  every `public.tenant` route, not by care.
- **The booking source is set by the adapter**, so a request body cannot make a
  guest booking look like a staff one.
- **Entitlement-gated** inside the Action, so a center that does not own
  `booking` has no such endpoint.
- **Throttled per center and per address**, tighter than the menu it sits behind
  because this one writes.
- **Idempotent**, so a retry on a bad connection cannot produce two
  appointments.

### What it deliberately does not return
If the phone number given already belongs to an existing customer, the booking
attaches to that record — one person stays one record — and the response echoes
nothing about them. Otherwise this endpoint would answer "is this number a
customer here, and what is their name" for anyone who could guess a number,
which is exactly the disclosure Phase 5's masking exists to prevent (ADR-042).

### Options

| | **Booking on the public surface** | A separate authenticated flow | No public booking |
|---|---|---|---|
| Guest can book | Yes | No — account required | No |
| Path-resolved write | Yes, bounded | No | — |
| Abandonment | Low | High | Total |

### Trade-off
Requiring an account would remove the write from the path-resolved surface
entirely, and would also remove most of the bookings: an account requirement is
the single biggest reason a customer abandons a booking form. A center that
prints a QR code on a table wants a booking, not a signup.

### Consequences
The public surface now has one write endpoint and one write form. Any future
addition to it faces the same four questions: can the caller read anything, can
they act as the center, is it gated, is it idempotent.

---

## ADR-044: Double booking is prevented by a branch-row lock, not by Redis

**Status:** Accepted · **Date:** 2026-09-07

### Context
Two customers ask for the same stylist at the same time. "Check availability,
then insert" cannot prevent both from succeeding — both checks can pass before
either insert runs. The window is small and it is precisely the window a popular
slot fills.

This is the single most important correctness property in Phase 6
(docs/13-ROADMAP.md §9), and the roadmap's original sketch called for a Redis
lock plus a database constraint.

### Decision
A pessimistic row lock on the **branch** row, taken inside the booking
transaction, with the authoritative availability re-check after it:

```
BEGIN
  SELECT ... FROM branches WHERE id = ? FOR UPDATE   ← serialises here
  re-check every employee against the live table
  INSERT appointment + items + add-ons
COMMIT
```

**Why the branch row and not the employee's.** It works when the employee is not
chosen yet — "any available" is a real customer choice and the most common one —
which a per-employee lock cannot. It is one row, so there is no lock-ordering
deadlock to reason about when a multi-service booking touches several stylists.
And it behaves identically on MariaDB 10.4 and MySQL 8, which a `GET_LOCK` or an
advisory-lock helper does not (ADR-033).

**Why not Redis.** Redis is configurable but not required to run or test
(ADR-025). Making correct booking depend on it would make the product's most
important invariant depend on optional infrastructure — and a distributed lock
that silently degrades when Redis is unreachable is worse than no lock at all,
because it fails open.

**A unique index cannot express this.** The constraint is interval overlap for
one employee, and no portable index expresses "no two rows whose time ranges
intersect". The check has to be a query, and a query needs a lock.

### The cost, stated plainly
Booking creation at one branch is serialised. That is milliseconds of work at a
write rate measured in bookings per hour, and it is the cheapest correct answer
available. If a center ever appears whose booking rate makes it matter, the
narrowing — locking the resolved employee rows in id order — changes
`CreateAppointment` and nothing else. The engine's contract does not move.

### Options

| | **Branch row lock** | Per-employee locks | Redis lock | Optimistic retry |
|---|---|---|---|---|
| Works for "any available" | Yes | **No** | Yes | Yes |
| Deadlock risk | None (one row) | Ordering required | None | None |
| Needs optional infrastructure | No | No | **Yes** | No |
| Fails open if misconfigured | No | No | **Yes** | No |
| Concurrency at one branch | Serialised | Per stylist | Per key | High |

### Consequences
Rescheduling uses the same lock, so a move and a new booking cannot both claim
one slot. The test suite proves the lock is real with a genuine second
connection and a one-second lock timeout — a sequential test alone would pass
against a lock that did nothing.

---

## ADR-045: Livewire persistent middleware carries tenant resolution

**Status:** Accepted · **Date:** 2026-09-07

### Context
Found while building the Phase 6 calendar, and it had been latent since Phase 3.

A Livewire component action does not POST to the route that rendered the
component. It POSTs to `/livewire/update`, which carries only the `web`
middleware group. Livewire then replays a filtered subset of the original
route's middleware — the ones registered as **persistent** — and that default
list includes `Illuminate\Auth\Middleware\Authenticate` but not `ResolveTenant`.

So every Livewire action on `/center/*` authenticated a staff user whose model
lives in the tenant database, with no tenant bound.

**It failed closed**, on `TenantConnectionGuard` — the right failure, and the
wrong outcome: the center admin area's interactivity did not work in a browser
at all. The tests never saw it because `Livewire::test()` bypasses the HTTP
endpoint entirely and binds the tenant itself.

### Decision
`AppServiceProvider` registers `ResolveTenant`, `ResolvePublicTenant` and
`SetLocale` as Livewire persistent middleware, and `MiddlewareOrderGuard` asserts
at boot that it took effect — fatal, like the priority-order assertion beside it.

Both resolvers are registered. Only the middleware actually on the ORIGINAL
route is ever gathered, so this cannot leak a path-resolved tenant onto an
authenticated route; ADR-036's boundary is untouched. `SetLocale` comes too, or a
Livewire update would re-render half a page in the platform fallback language.

### Why a boot assertion rather than a comment
This is the second silent middleware-ordering bug in the project, and it is the
same species as the first (ADR-027): the source looked right, the route arrays
were right, and only the resolved list disagreed. Source inspection cannot catch
that class of bug. A boot-time assertion can.

### Trade-off
A boot assertion means a Livewire upgrade that renames the mechanism takes the
application down instead of quietly reverting the fix. That is the intended
trade: a loud failure at deploy time beats an admin area that half works.

### Consequences
The Phase 6 staff calendar and the customer account page work in a browser. The
public booking flow is deliberately plain server-rendered forms instead, which
keeps the path-resolving middleware out of this machinery entirely — one fewer
place ADR-036 has to hold.

---

## ADR-046: Scheduled instants are DATETIME, not TIMESTAMP

**Status:** Accepted · **Date:** 2026-09-07

### Context
Every earlier Meta Style table used nullable `TIMESTAMP` columns for moments in
time. `appointments` and `appointment_items` need two NOT NULL ones each, and
MariaDB refused the table outright: only the first non-nullable `TIMESTAMP` in a
table gets an implicit default, and every one after it is given
`0000-00-00 00:00:00`, which strict mode rejects.

That was the prompt. The reason for the answer is different and better.

### Decision
`starts_at` and `ends_at` on both tables — and `locked_at` / `expires_at` on
`idempotency_keys` — are `DATETIME`.

MySQL and MariaDB convert a `TIMESTAMP` from the **session** timezone to UTC on
write and back on read. Meta Style already stores UTC deliberately and computes
in the branch timezone (docs/10 §8), so that conversion is a second opinion about
a question already answered — and it silently changes its mind if the database
server's timezone is ever changed, or if a connection is opened with a different
one. `DATETIME` stores exactly the instant that was written.

For a booking system that is not a stylistic preference. A server timezone
change would move every stored appointment relative to the wall clock the
customer was told, with no migration and no error.

### What was NOT changed
The existing nullable lifecycle stamps — `archived_at`, `confirmed_at`,
`cancelled_at`, `marketing_opt_in_at` — stay `TIMESTAMP`. A nullable `TIMESTAMP`
defaults to NULL and has neither problem, and churning five phases of schema for
consistency would be a migration across every tenant database in exchange for
nothing measurable.

### Trade-off
Two column types for moments in time, split by whether the value is a schedule
or a lifecycle stamp. The rule is stated in the migrations and here; the
alternative — one type everywhere — costs a rewrite of settled schema.

### Consequences
`Kernel\Time` reads and writes UTC without depending on any session setting. A
future deployment that moves database servers, or runs a read replica in another
region, cannot shift a customer's appointment.

---

## ADR-047: One branch lock guards booking and every capacity mutation

**Status:** Accepted · **Date:** 2026-09-08

### Context
ADR-044 introduced a pessimistic row lock on `branches` so two bookings could not
both take one slot. Phase 7 added things that CHANGE what is bookable: a
resource's capacity, its active flag, which branch it stands in, a service's
resource requirements, and an employee's availability blocks.

None of those went through the booking path, so none of them took the lock:

```
Request A: books the last place in the hammam
Request B: reduces the hammam's capacity by one
```

Both transactions read a consistent world a millisecond apart, both decide they
are fine, and both commit. The center now holds a reservation against capacity
that no longer exists. Nothing errors, and nobody finds out until somebody
arrives.

### Decision
The lock became a named thing — `Branches\Domain\BranchLock` — and every
mutation that changes bookability takes it inside its own transaction:

- resource capacity, activation, archival and branch moves (both branches);
- a service's resource requirements (every branch, since a service is offered
  everywhere unless a pivot says otherwise);
- employee availability blocks, on create, update and delete.

Locks are always taken in **ascending branch id**. Two callers that each need
branches 3 and 7 would deadlock if one took 7 first; ascending order is arbitrary
and consistent, which is the only property that matters.

`acquire()` **throws when called outside a transaction.** A row lock there is
released the instant the statement finishes, so it protects nothing while looking
exactly like it does — and that mistake is otherwise invisible.

### Rejected
**A lock per resource.** Booking cannot take it: at the moment it locks, it has
not yet chosen a resource or an employee. The branch is the one row both sides
can name in advance, which is also why `resources.branch_id` and
`employee_availability_blocks.branch_id` are both NOT NULL.

**Optimistic concurrency with a version column.** Every writer would need retry
logic, and the failure mode — a booking that silently retried into a different
room — is worse than waiting a millisecond.

**No coordination, and accept the race.** The window is small and it is exactly
the window a busy Saturday morning fills.

### Cost
Writes at one branch are serialised. At a booking rate measured in bookings per
hour and a settings-change rate measured in times per month, that is milliseconds
of contention for a guarantee that would otherwise need a distributed lock
service.

---

## ADR-048: Availability collaborators are transient, never scoped

**Status:** Accepted · **Date:** 2026-09-08

### Context
`BranchCalendar`, `EmployeeAssigner`, `LineResolver` and `ResourceAllocator` each
cache per instance: a branch's opening hours, an employee eligibility set, the
resources of a type. Registering them as `scoped` looked like an obvious
improvement — one instance per request, one cache, fewer queries.

It was tried, and a test caught it within the hour. A `scoped` binding survives
an HTTP request inside a test process (and under Octane). A booking made *after*
a room was archived was still offered that room, from a candidate list built
before the archive.

### Decision
They stay transient. Each injection point gets its own instance, and the cache
lives exactly as long as the computation it belongs to.

### Rejected
**Scoped with explicit invalidation.** Every mutation would have to remember to
call `forget()` on four classes, and the one that forgot would produce a stale
answer nothing failed on.

**Singletons.** Worse: a queued job running for two tenants in one process would
answer the second with the first one's rooms.

### Cost
`AvailabilityEngine` and `CreateAppointment` each build their own collaborators
within one request, so a small number of lookups happen twice. The query-count
tests bound the total, and correctness bought it.

---

## ADR-049: Resources are an L1 module, not part of ServiceJourney

**Status:** Accepted · **Date:** 2026-09-08

### Context
`04-MODULE-BOUNDARIES.md` assigned "rooms/chairs/devices as bookable resources"
to ServiceJourney, and told Branches not to own room inventory.

Phase 7 made that impossible. The Availability Engine must enforce resource
capacity — a booking that ignored rooms would offer slots the center cannot
serve — and Booking must not depend on ServiceJourney, or the reservation system
starts knowing about the floor and Phase 8's queue inherits the tangle.

### Decision
`Resources` is its own module at **L1 Core Records**, beside Branches, Employees,
Customers and Services. It owns `ResourceType`, `OperationalResource` and
`ServiceResourceRequirement`.

```
ServiceJourney (L2) ──▶ Booking (L2) ──▶ Resources (L1)
        │                                    ▲
        └────────────────────────────────────┘
```

Booking reads it downward, which rule 4 already permits and which is how Booking
already reads Catalog and Branches. Journey reads both. Booking never learns
Journey exists, and an architecture test fails the build if it does.

This is a documentation correction rather than a design compromise: resources are
a scheduling and capacity concept that Journey happens to consume, not a Journey
concept.

### Rejected
**Leaving resources in ServiceJourney and letting Booking read it.** That is the
dependency the whole phase is built to avoid.

**Duplicating a thin resource read model inside Booking.** Two sources of truth
for capacity, and the copy is the one that goes stale.

### Cost
`04-MODULE-BOUNDARIES.md` needed updating in two places. No code moved, because
the module was created in the right place to begin with.

---

## ADR-050: Runtime resource capacity counts actual use and committed reservations

**Status:** Accepted · **Date:** 2026-09-12

### Context
Phase 7 checked an actual resource assignment — starting a stage, swapping a
machine mid-service — against other stages' live usage rows only. It shipped that
way deliberately, and the phase report recorded the gap as a risk:

```
Resource capacity 1
committed  10:30 → 11:00   another customer's booked reservation
candidate  10:10 → 10:40   somebody arrived early, a host presses start
```

At 10:10 nothing is physically in the room, so the start was admitted. At 10:30
the customer whose booking it was arrived to find it occupied — and the software
had been the one to put somebody there. Nothing errored, and the only trace was a
complaint at a desk.

The reasoning behind the original choice was sound as far as it went: a booking's
own reservation and its own usage row are the same room held once, so counting
reservations naively would double-count. The mistake was concluding that
reservations therefore could not be counted at all.

### Decision
**Runtime capacity = actual occupancy + committed external booking capacity**,
as one combined peak-occupancy calculation over both sets, in
`ServiceJourney\Domain\StageResources`.

The candidate window is `now → now + the booked duration` on a start, and the
remainder of it on a swap. It is an **admission check**: nothing writes it down,
and the actual record stays `assigned_at → released_at`, free to run longer when
a service overruns.

Two exclusions keep the sets from overlapping:

- the stage's **own** appointment item's reservation — the visit is walking into
  the room it reserved, and it must not compete with itself;
- any other item whose stage has left `waiting` — its reality is the usage row,
  and counting its reservation as well would refuse a second customer from a
  capacity-two room with a place free, and keep refusing after the first customer
  finished early.

Every other item's reservation counts in full, including another item of the same
visit.

Which reservations are "committed" is not re-decided in Journey.
`Booking\Domain\Availability\ResourceFinder::committedLoadsFor()` is the read
seam and it filters on the same conflict set every other booking check uses, so a
cancelled, completed or no-show appointment stops consuming capacity the moment
its status changes. Booking still does not know ServiceJourney exists.

The check runs inside the transaction that writes the usage row, under the same
branch lock booking takes (ADR-047). A swap validates before it closes anything:
lock, prove, close, open — or none of the four.

### Rejected
**A separate check against reservations, after the actual check.** Two passes
that each fit can still describe a room that is full between them. The peak has
to be taken over the union or it is not a peak.

**Excluding the whole appointment rather than the item.** Simpler, and wrong: two
services booked back to back in one exclusive room compete with each other
exactly as two customers would.

**Filtering reservations by appointment status inside Journey.** That is Booking's
rule, and a second copy of it is a second thing to get wrong the next time the
lifecycle gains a state.

**Releasing or moving the competing reservation so the operational request can
succeed.** A reservation is a promise to a customer who is not in the building to
argue. If the capacity is committed, the answer is a different resource or a
refusal.

**A projected completion time written onto the stage.** The expected window would
become a number people read as fact, and the first overrun would make it a lie.
It stays an admission check and nothing else.

### Cost
Two extra bounded reads per resource per start or swap — one for the competing
reservations, one for which of them have already started — both `WHERE IN` over a
set bounded by how many bookings overlap a single service's duration on a single
resource. A regression test asserts the count does not grow with the number of
reservations.

Starting a stage now also serialises on the branch row, which is milliseconds
against a booking rate measured in bookings per hour.

Some starts that used to succeed now fail. That is the point: they were the ones
taking a room somebody else had been promised.

---

## ADR-051: Walk-in visits make `appointment_id` nullable, never a fake appointment

**Status:** Accepted · **Date:** 2026-09-12

### Context
Phase 7 tied a `ServiceJourney` to an `Appointment` with a NOT NULL foreign key.
That was right while every visit was booked. Phase 8's queue is mostly about
people who walked through the door, and a barbershop's Saturday is almost
entirely those people.

### Decision
`service_journeys.appointment_id` becomes nullable, and a walk-in carries the two
facts it can no longer derive:

```
source = appointment   appointment_id set, customer_id and branch_id null
source = walk_in       appointment_id null, customer_id and branch_id set
```

`journey_stages.appointment_item_id` becomes nullable the same way, and a
walk-in stage carries its own service snapshot — name, duration, price,
currency — for the same reason `appointment_items` snapshots.

The invariant is enforced in the Actions and by tests, NOT by a CHECK constraint:
MariaDB 10.4 and MySQL 8 disagree about enforcing them (ADR-033).

Accessors hide the difference. `ServiceJourney::branchId()`, `customerId()`, and
`JourneyStage::serviceId()`, `durationMinutes()`, `serviceName()` each read the
column when it is set and fall back through the appointment. Every Action calls
those rather than branching.

### Rejected
**Create an appointment for the current minute and check it in.** The cheap
option, and it would have put reservations into the booking tables for
reservations nobody made. Availability, the calendar, the conflict queries and
every later report would need a "but not the fake ones" clause forever — and
the first place somebody forgot it would be a customer refused a slot that was
never taken.

**A separate `walk_in_visits` table.** Two operational tables with the same
stages, the same statuses, the same resource rules and the same board, diverging
the first time either changed. The board would have to union them, and Phase 9's
queue metrics would have to as well.

**Copy customer and branch onto BOOKED journeys too, for a uniform shape.** A
second place they can disagree, on the path that already has an authoritative
answer. The accessor gives a uniform READ without a uniform WRITE.

### Cost
Every consumer of `$stage->item` and `$journey->appointment` had to move to an
accessor — nine Actions, the board query, the presenter and two surfaces. That
was the blast radius, and the Phase 7 suites were the control that it landed
safely.

---

## ADR-052: The queue display polls; no WebSockets in Phase 8

**Status:** Accepted · **Date:** 2026-09-12

### Context
Phase 8 is the first phase with a genuine real-time requirement: a television in
a waiting room showing the number being called.

### Decision
**Polling.** The staff board uses `wire:poll.5s`; the display is a static page
polling a bounded JSON feed every three seconds with the database as the source
of truth. No new dependency.

| | Polling | Reverb | SSE |
|---|---|---|---|
| Dependency | none | reverb + echo + pusher-js | none |
| Deployment | none | daemon under supervisor, second port | none |
| Proxy | none | WebSocket upgrade, `wss://` | buffering off |
| Redis | no | yes, past one app server | no |
| Per screen | one short request per poll | one persistent connection | **one PHP-FPM worker all day** |
| Isolation | inherent | per-tenant channels, and the display is unauthenticated | inherent |
| Reconnect | the next poll is a full read | Echo reconnect + resync | resync |

A television showing a number three seconds late is not a defect. A deployment
that needs a supervisor-managed daemon and Redis before a barbershop can show a
queue is.

The domain events ship anyway, so a realtime adapter can listen outward later
without touching an Action.

### Rejected
**Laravel Reverb now.** It would make `metastyle:doctor --production` gain a hard
requirement, and the display is unauthenticated — so it would also need a
public channel keyed by the display's public key, which is a new security surface
for a three-second improvement.

**SSE.** Looks cheaper than Reverb and is worse than polling here: a screen left
on all day pins a PHP-FPM worker for the whole day, and this product has no async
worker pool to absorb that.

### Cost
Up to three seconds of latency on the display and five on the staff board. A
quiet center still makes one request per screen every three seconds, which is
what `throttle:public-display` and the bounded feed are sized for.

---

## ADR-053: Journey to Queue sync is synchronous and in-transaction

**Status:** Accepted · **Date:** 2026-09-12

### Context
`JourneyStage.status` is the truth about service execution; `QueueTicket.state`
is the waiting mechanism around it. They must never disagree — and the ticket,
being the thing on the television, is the one people would believe.

Queue may depend on Journey; Journey must not know Queue exists.

### Decision
Journey dispatches past-tense domain events — `JourneyStageStarted`,
`JourneyStageSettled`, `JourneyAborted` — **inside its Actions'
transactions**. `Queue\Application\SyncTicketsWithJourney` listens.

The listener is a plain class:

- **synchronous**, no `ShouldQueue`;
- **idempotent**, so a replayed fact changes nothing;
- **in the same transaction**, so the stage and the ticket commit together or
  neither does;
- it writes **only queue tables**, which an architecture test enforces.

A failure in the listener therefore takes the Journey mutation down with it. That
is the intended trade: refusing to start a service is recoverable, and a ticket
that quietly disagrees with the floor is not.

The consequence worth having: "start" on the visit board and "start" on the queue
board are the same Action producing the same event, so the two screens cannot
drift. A test asserts exactly that.

### Rejected
**A queued listener.** Eventually consistent, and the intermediate state — a
stage `in_service` beside a ticket still `called` — is precisely what a host and
a television would both be showing. On a center running no queue worker, forever.

**Queue Actions writing `journey_stages` directly.** A second state machine with
its own idea of what is legal and its own missing audit entry, which is the
mistake Phase 7 refused for `appointments.status`.

**Journey calling Queue.** The dependency inverted, and Phase 9's queue metrics
would inherit it.

**Broadcasting as the mechanism.** Delivery is not correctness. Realtime is a
secondary channel and the database is the source of truth (ADR-052).

### Cost
Journey Actions now do a little more work inside their transactions, and a queue
bug can fail a stage start. Both are deliberate: the alternative is two screens
telling a customer different things.

---

## ADR-054: A sale is not a visit, and an invoice is an immutable snapshot

**Status:** Accepted · **Date:** 2026-09-13

### Context
Phase 9 adds money to a product that already separates what was RESERVED
(appointment), the VISIT (journey), what was PERFORMED (stage) and the WAITING
around it (queue ticket). The obvious shortcuts — a `paid` flag on the
appointment, a total on the journey, an invoice that renders from live sale lines
— each merge two of those facts, and each is unrecoverable once real invoices
exist.

### Decision
- **`Sale`** is the commercial transaction: `draft → finalized → voided`, no
  payment state. A draft IS the cart. It may point at a journey; nothing about it
  is written back to any operational table, which a TenantIsolation test scans.
- **Every sale line is a snapshot** — name, variation, add-ons, quantity, unit
  price — taken when the line is added, from the catalog or from the visit's own
  price snapshot. `original_unit_price_minor` is never overwritten; an override
  carries an actor and a reason.
- **`Invoice`** is published once, inside the finalization transaction, from the
  recalculated sale, with its OWN copies of center, branch, customer name, lines,
  adjustments and totals. It is never updated and never deleted: the
  `ImmutableDocument` concern throws on both, no Action edits an invoice, and an
  architecture scan refuses query-builder writes to the invoice tables.
- **A void is a fact about the SALE**, recorded there. The invoice row stays
  byte-identical and renders "VOID" from the sale.
- **The customer share token lives in its own table**, because a rotatable
  credential cannot be a column on a document that is never updated — and only
  as a SHA-256 digest (ADR-057).
- **Journey never learns Sales exists.** Checkout is a Sales Action that reads the
  visit; the visit board only links to it; `CompleteJourney` creates no sale.

### Rejected
**Rendering the invoice from sale lines.** A later code path that touches a line
— or a migration that recalculates — silently changes a document a customer holds.

**A `discarded` status for drafts.** A draft never became financial; keeping
abandoned carts forever puts noise in every future sales query. Discard deletes
the draft and the audit log keeps the fact.

**Repricing a booked visit from the catalog at checkout.** Charges the customer a
price nobody quoted them.

### Cost
Data is duplicated between sale lines and invoice lines. That duplication is the
point: they answer different questions, and only one of them is allowed to
change.

---

## ADR-055: Invoice numbers — per branch, per branch-local year, from a locked sequence

**Status:** Accepted · **Date:** 2026-09-13

### Context
The roadmap requires unique, gapless invoice numbers per branch under concurrent
checkouts. No legal numbering format, and no fiscal or accounting year, is
recorded anywhere in the product.

### Decision
- **Scope:** branch + branch-local calendar year. Not daily — an invoice number
  is a document identity, not a place in a line.
- **Algorithm:** Queue's proven pattern inside the finalization transaction:
  `INSERT IGNORE` the `invoice_sequences` row → `SELECT … FOR UPDATE` → +1.
- **Format:** `{PREFIX}-{YYYY}-{NNNNNN}`, stored on the invoice.
- **Prefix:** `branches.invoice_prefix`, 1–4 uppercase ASCII letters/digits,
  unique. The MAIN branch may leave it empty and uses `INV`; `INV` is reserved for
  it; any other branch must configure one before issuing, and is told so plainly.
  A prefix already printed on another branch's invoices is refused.
- **Column:** `sequence_year` — the branch-local calendar year the numbering
  resets on, named for exactly that. Calling it `fiscal_year` would have encoded
  an accounting claim nothing had verified (amended before release, ADR-057).
- **Backstops:** `unique(branch_id, sequence_year, sequence_number)`,
  `unique(number)`, `unique(sale_id)`.

### The exact guarantee
The increment is in the same transaction as the invoice, so a failed
finalization rolls the number back: **unique and gapless per branch-year as long
as invoices are never deleted and nobody edits `invoice_sequences` by hand.**
Nothing stronger is claimed. This is stronger than AUTO_INCREMENT, which burns
values on rollback.

### Rejected
**A center-wide sequence.** Simpler, but contradicts the recorded per-branch
requirement. **Daily reset.** Borrowed from Queue for no reason. **`MAX()+1`.**
Two tills read the same maximum.

### Cost
A second branch needs one setting before its first invoice. Two tills at one
branch serialise on one row for a few milliseconds.

---

## ADR-056: No PDF or QR dependency in Phase 9; invoices print through the browser

**Status:** Accepted · **Date:** 2026-09-13

### Context
The roadmap listed PDF and QR. Both need a package, and the invoice must render
Arabic and Kurdish correctly.

### Decision
- 80mm, A4 and the digital invoice are **Blade layouts of one allow-listed view
  model**, printed by the browser. "Save as PDF" in the print dialog covers the
  customer who wants a file, and the browser shapes Arabic and Kurdish correctly.
- **No QR in Phase 9.** The printed invoice carries no link rather than a link
  generated by a hand-written encoder.
- **No `Kernel/Templates`.** It does not exist, and a generic template engine is
  not built on speculation.

### Options for later, with their costs
| Option | Arabic / Kurdish | Cost |
|---|---|---|
| dompdf | weak shaping and bidi | low |
| mPDF | good | heavy dependency |
| Browsershot | correct | Node + Chromium on every server |
| QR (`chillerlan/php-qrcode` or a JS library) | n/a | one small dependency, needs a decision |

### Cost
No server-generated PDF to attach to a future WhatsApp message. That arrives with
the channel that needs it, and with an explicit dependency decision.

---

## ADR-057: Phase 9 financial consistency — visit completion, hashed invoice links, downgrade, in-transaction audit

**Status:** Accepted · **Date:** 2026-09-14

### Context
Review of the Phase 9 implementation, before release, found four places where
the financial record could disagree with itself or leak a credential:

1. A visit's sale could be finalized while the visit was still running. Stages
   completed afterwards were missing from an immutable invoice, and the
   one-live-sale rule left nowhere honest to charge them.
2. `invoice_share_links.token` stored the customer's bearer secret in plaintext —
   unlike registration and staff activation tokens (ADR-035).
3. Every read went through the same gate as a mutation, so a center that lost
   `pos` lost sight of invoices it had already issued, and printing needed `pos`
   as well as `printing`.
4. `sale.finalized` and `invoice.issued` were written after COMMIT, so an audit
   failure could leave a published invoice reported as a success with no audit
   record.

### Decision
1. **A visit is invoiced once it is over.** `CheckoutJourney` still prepares and
   reuses a draft for an active visit; `FinalizeSale` refuses unless the journey is
   `completed` (aborted: refused, discard the draft). Inside finalization, and on
   reopening checkout, `VisitLines` adds a line for every completed stage that has
   none. For that to be sound a visit line is never removed — charging less is a
   reasoned price override or a discount. No partial, split, progress or deposit
   invoice.
2. **Hash the link at rest.** `token_hash CHAR(64) UNIQUE` holds SHA-256 of a
   256-bit secret; the public lookup hashes the presented value. The plaintext is
   returned once — `IssuedInvoice::$shareToken` from finalization,
   `RotateInvoiceLink`'s return value — and a desk that needs it again issues a new
   link. `ResolvePublicTenant`'s conflict audit records the route template, not
   the path, because this path carries the secret. No separate public locator: the
   center key is already in the URL and a uuid would add nothing.
3. **`pos` gates what happens next.** `SalesAccess::ensure()` (`pos` + permission
   + branch) guards every new operation; `authorize()` (permission + branch) guards
   reading sales and invoices and rotating a link; `ensurePrinting()` needs
   `printing`, not `pos`. The rule Booking follows (docs/05 §6.2).
4. **Audit inside the transaction.** The tenant audit log is on the same
   connection and docs/08 §7 already prescribes synchronous in-transaction writes,
   so no outbox is needed: `sale.finalized`, `invoice.issued`, `sale.voided` and
   `sale.discarded` commit with the change or not at all.

The unverified `fiscal_year` column is also renamed `sequence_year` (ADR-055).
None of the migrations had shipped, so all of this is a pre-release edit, not an
expand/contract.

### Rejected
- **Refusing finalization when stages are missing, instead of adding them.** The
  desk would have to reopen checkout for no decision of its own; the lines are
  priced from the visit's snapshot either way.
- **Keeping line removal and tracking "declined" stages.** A new table to preserve
  a path that let a cashier without `sale.adjust` waive a performed service.
- **Encrypting the token so staff can see the URL again.** A reversible secret in
  the database is the thing being removed.
- **Gating link rotation on `pos`.** A center that lost POS could not revoke a
  leaked link to its own customer's invoice.
- **An outbox for audit.** Not needed when the audit table shares the
  transaction's connection.

### Cost
The customer URL is visible once; later reads offer "issue a new link", which
invalidates the old one. A visit's bill cannot be published until the visit is
completed on the board. One more read (the journey row) per visit finalization.

---

