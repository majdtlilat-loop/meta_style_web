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
| 024 | Tenant database names derived from an internal sequence | **Accepted** · amended by ADR-106 | High |
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
| 058 | A payment is one attempt against one invoice; pending gateway payments reserve; refunds never reopen | **Accepted** | High |
| 059 | The center ledger is written by Finance, from domain events, inside the money's transaction | **Accepted** | High |
| 060 | Provider honesty: FIB from its documentation, callbacks as pointers, no raw bodies, synchronous settlement | **Accepted** | Medium |
| 061 | Money first; benefits react after commit and reconcile | **Accepted** | Medium |
| 062 | Points ledger prevents negative balance and snapshots expiry | **Accepted** | High |
| 063 | Sales owns generic benefit seams and performed-service consumption | **Accepted** | Medium |
| 064 | Benefit repair reproduces event-time rules | **Accepted** | Medium |
| 065 | Draft benefits are expiring holds; till lines require performed work | **Accepted** | Medium |
| 066 | Reviews use a completed-visit capability | **Accepted** | Medium |
| 067 | Notifications listen and never block source transactions | **Accepted** | Low |
| 068 | Bookings expose a reference separate from the secret code | **Accepted** | Medium |
| 069 | Booking codes use HMAC with a versioned pepper | **Accepted** | Medium |
| 070 | A verified WhatsApp sender is per-interaction evidence | **Accepted** | Low |
| 071 | Provider destinations are platform configuration | **Accepted** | Low |
| 072 | Commercial quotas use tenant counters with snapshotted allowances | **Accepted** | Medium |
| 073 | OpenAI Responses and Meta Cloud API are the Phase 13 providers | **Accepted** | Low |
| 074 | Standard and Advanced Reports are separate products and read targets | **Accepted** | Medium |
| 075 | Advanced Report AI has an independent commercial allowance | **Accepted** | Low |
| 076 | APP_URL-derived hosts plus registry-backed center identity | **Accepted** | High |
| 077 | Platform identities require permission checks and MFA | **Accepted** | Medium |
| 078 | Locale and non-sensitive UI preferences persist independently | **Accepted** | Low |

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
By Phase 14 the API is called by mobile clients on unreliable connections,
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
Meta Style App (Phase 17), Meta Style SADMIN (Phase 17), White Label Customer
App (Phase 18). **They are not created now.**

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

**Status:** Accepted · **Date:** 2026-09-02 · Amended by ADR-106 (new centers carry their slug as a label)

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
Acceptable while nothing depends on cache persistence; revisit at Phase 13,
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
infrastructure that does not exist until Phase 14.

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
When Phase 14 brings a provider, the work is a verification flow and a gate —
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
| P-09 | Payment provider accounts and sandbox access | Phase 10 → **partly resolved 2026-09-15 (ADR-060)** | FIB adapter from published documentation, not yet run against its sandbox; ZainCash, Qi and FastPay stay explicitly unsupported until their documentation and sandbox access are verified |
| P-10 | WhatsApp: Meta Cloud API direct vs BSP | Phase 14 | Adapter supports both; pilot with a BSP |
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

## ADR-058: A payment is one attempt against one invoice; pending gateway payments reserve; refunds never reopen

**Status:** Accepted · **Date:** 2026-09-15

### Context
Phase 10 makes money real on top of an immutable invoice (ADR-054). Three
shortcuts were available and each corrupts the record: a `paid` flag or balance on
the sale or invoice; a payment "intent" that is later mutated into whatever the
provider says; and treating a refund as the invoice becoming unpaid again. And one
race is specific to online payments: a customer paying through a provider while
the desk takes cash for the same balance.

### Decision
- **A `Payment` is one attempt** against **one issued invoice**: `pending →
  succeeded | failed | cancelled`, final states never move. Desk payments (cash,
  staff-confirmed transfer) are created `succeeded`; only gateway payments are ever
  `pending`. A split bill is several payments. Nothing is written to `sales` or
  `invoices`; Sales never imports Payments.
- **Reservation.** `available = total − succeeded − pending` (0 when the sale is
  voided). A pending gateway payment holds its amount until a verified outcome, so
  the desk cannot collect the same money while the provider might.
- **Locks, one order everywhere:** sale, then invoice (`InvoicePaymentLock`); a
  refund locks its payment; cash locks the collector's shift.
- **Settlement state from gross succeeded** (`unpaid | partial | paid`). A
  `Refund` is a separate row against one payment, bounded by `payment − succeeded
  − pending refunds`, and never changes the payment or reopens the invoice; net
  collected goes down.
- **Voiding a sale with money on it is refused**, through a neutral Sales contract
  (`SaleVoidGuard`, container-tagged) that `PaymentsVoidGuard` implements. No
  automatic refund or cancel, no model observer.
- Entitlements are the existing keys: `pos` for desk money, `payments` for online
  money. Cash never needs `payments`.

### Rejected
- **A balance column on the invoice or sale.** A second record of money that
  disagrees with the payments the first time a write path forgets it — and the
  invoice is immutable anyway.
- **Letting the desk collect while a gateway payment is pending, and "fixing" an
  overpayment later with a refund.** Moves the customer's money twice to repair a
  race the reservation prevents.
- **Refunds reopening the invoice.** The customer does not owe the money again;
  the center gave it back.
- **Voiding auto-refunds.** A void that moves money nobody decided to move.

### Cost
A stuck pending payment blocks its amount until someone refreshes or cancels it
(both exist, both go through the provider). A void needs an explicit refund first.

---

## ADR-059: The center ledger is written by Finance, from domain events, inside the money's transaction

**Status:** Accepted · **Date:** 2026-09-15

### Context
Finance needs a record of money that moved — collections, refunds, expenses — that
agrees with Payments to the minor unit, without Payments depending on Finance and
without a reconciliation job discovering disagreements after the fact.

### Decision
- **`finance_entries` is append-only**, one row per movement, positive amounts
  with a direction, `unique(source_type, source_uuid, kind)`. `AppendOnlyRecord`
  refuses update and delete; `Ledger::append` is the only writer (scanned), throws
  outside a transaction and is idempotent per source.
- **Payments raises `PaymentSucceeded` / `RefundSucceeded` synchronously inside
  its transaction; Finance's listener appends the entry in that same transaction**
  — the ADR-053 pattern. A failed ledger write rolls the payment back. Issuing an
  invoice, a pending or failed payment and a failed refund write nothing.
- **The ledger is written whatever the `finance` entitlement** — it is a fact
  about money, not the product being sold. `finance` gates expenses, drawer counts
  and the dashboard; history stays readable after a downgrade.
- **Expenses** are posted and voided (never edited or deleted) with a reversing
  entry; "from the drawer" is an explicit claim tied to the poster's own open shift.
- **Drawer reconciliation** is a Finance-owned, append-only snapshot written with
  Sales' own shift close in one transaction, under the shift lock every cash
  movement takes. Expected cash counts only `method = cash`. A variance never
  blocks the close. Opening cash is a nullable Sales column.
- **The dashboard reports five separate figures** — invoiced, collected, refunded,
  expenses, net movement — plus outstanding, and never calls anything revenue or
  profit.

### Rejected
- **A queued listener or an outbox.** Money reported without its entry for as long
  as the queue lags, and a second mechanism to monitor. The ledger shares the
  tenant connection, so the transaction is enough.
- **Computing the dashboard from payments and expenses directly.** Works until the
  first correction; an append-only ledger makes corrections visible.
- **Finance importing Payments' Actions** or Payments calling Finance. Either
  couples money collection to the finance product.
- **A `cashier_shift.reconcile` permission.** The existing manage/supervise pair
  already says whose drawer you may close.

### Cost
Every payment and refund transaction does one more insert. Adding a new money
movement means adding an event and a listener, not just a row.

---

## ADR-060: Provider honesty — FIB from its documentation, callbacks as pointers, no raw bodies, synchronous settlement

**Status:** Accepted · **Date:** 2026-09-15

### Context
The roadmap named four Iraqi providers. Their documentation quality varies and no
sandbox access was available in Phase 10 (P-09). A provider integration that
guesses endpoints, signatures or refund APIs is worse than none: it fails on real
money. docs/10 §11 and docs/14 §1 also prescribed "persist the raw webhook, answer
200, process asynchronously" — written before any provider or queue workload
existed.

### Decision
- **FIB has a real adapter**, built only from FIB's public web-payments
  documentation and FIB's own PHP SDK (read 2026-09-14, in agreement), pinned by
  contract tests from the documented examples. **No sandbox transaction has been
  run**; that is stated in the code, the docs and the phase report.
- **FIB refunds are unsupported**: the public documentation has none, and an
  endpoint only an SDK calls is not a contract.
- **ZainCash, Qi and FastPay are registered as explicitly unavailable**
  (`UnsupportedProvider`): configuration and payment attempts refuse.
- **Capabilities are explicit** (refunds, cancellation, whether a callback needs a
  status query, environments, currencies) and the UI offers only what is declared.
- **An unsigned callback is only a pointer.** FIB's callback carries nothing that
  proves FIB sent it, so the status is read back with the center's credentials,
  and only that — reference, amount and currency matched — settles. A mismatch
  never settles and raises a critical security audit.
- **No raw callback body, headers or signature is stored.** A webhook event row is
  facts and codes, and its fingerprint makes processing idempotent.
- **Settlement runs synchronously in the callback request**: verify, (for FIB)
  query status, then one short local transaction. The provider receives `202`, or
  `503` when its status could not be read so that it retries.
- **Provider hosts are platform configuration** (https only; FIB live host is an
  operator setting). No credential field may carry a destination.
- **Credentials are per branch, encrypted with the application key, write-only.**
  If they stop decrypting, the account fails closed instead of erroring.

### Rejected
- **Adapters for ZainCash, Qi or FastPay from memory or unofficial sources.**
- **Persisting raw bodies "for debugging".** They carry payer data and replayable
  signed messages, and nothing in the product reads them.
- **Asynchronous processing now.** There is no queue workload or Horizon (ADR-021);
  a job adds a delivery path to monitor while the synchronous path is a verified
  read and a millisecond transaction. The docs/10 §11 anti-pattern (timeouts,
  duplicates) is bounded here by a 10-second provider timeout, the 503 retry, and
  the fingerprint claim.
- **Trusting the browser's return from the provider.** Proves nothing.

### Consequences
Easier: every provider added later follows one contract and its tests. Harder: a
slow FIB status endpoint occupies a web worker for up to 10 seconds per callback.
Revisit when: a second provider ships, callback volume makes synchronous status
queries costly, or FIB publishes signed callbacks or a refund API. Before a center
goes live on FIB: run a real sandbox payment, cancellation and status query.

Reversal cost: medium

---


## ADR-061: Money first — benefits react after commit, repair by reconciliation, and reads never write

**Status:** Accepted · **Date:** 2026-09-19

### Context
Loyalty earning, membership activation and package activation are consequences
of money. The Finance ledger reacts to `PaymentSucceeded` inside the payment's
transaction (ADR-059) because it IS part of the money fact. Doing the same for
benefits would let a loyalty bug roll back a payment the customer already made,
or turn its success into an error at the desk.

### Decision
- **Benefit listeners only schedule work with `Kernel\Database\AfterCommit`**, run
  after the root tenant transaction commits. A failure is reported
  (`AfterCommitFailed`) and never rethrown.
- **Every such reaction is idempotent and replayable from canonical facts**, with
  a `Reconciler` (`LoyaltySync`, `ActivatePackages`, `ActivateMemberships`) run
  per center by `metastyle:reconcile`, hourly, over a 3-day window.
- **Reads are read-only.** Queries, presenters, the customer API and panels never
  repair or activate — an architecture test enforces it. **Actions that change a
  balance** (redeem, adjust, apply a package or membership benefit) sync that one
  customer first.
- **No retroactive earning.** `spend_since` / `visit_since` mark when each rule was
  switched on and move past any moment a sync finds the center without `loyalty`;
  replays respect them.

### Rejected
- **Synchronous in-transaction benefits** (the Finance pattern) — a benefit failure
  would undo real money.
- **A queue or outbox** — a second delivery mechanism to monitor, with no workload
  to justify it (ADR-021); a transaction callback plus a reconciler covers the
  crash window.
- **Self-healing reads** — a query that writes surprises every caller, takes locks
  on a GET, and hides the failure the reconciler should surface.

### Consequences
A failed callback leaves a benefit missing until the hourly reconcile or the
customer's next redemption/adjustment/use; staff can see the gap in the meantime.
A callback that failed while `loyalty` was on and was replayed only after a
revocation is lost rather than over-awarded — the conservative side.

Reversal cost: medium

---

## ADR-062: The points ledger — no negative balance, unrecovered points recovered from earnings, expiry snapshotted per credit

**Status:** Accepted · **Date:** 2026-09-19

### Context
A refund must take back the points its money earned, but the customer may already
have spent them. A negative balance is a debt the customer never agreed to;
silently forgiving it lets refunds be gamed. Expiry that follows the program's
CURRENT setting would rewrite points already earned.

### Decision
- **`loyalty_transactions` is append-only**; `LoyaltyLedger` is its only writer and
  keeps `balance == Σ in − Σ out`. The balance is never negative.
- **A refund reversal covers what the balance can**; the rest is recorded as
  `unrecovered_points` on the reversal and the account. **Every later earning
  first writes a `recovery` row** settling up to its own points.
- **Qualifying lifetime points** (tiers) = Σ earned − Σ refunded earnings,
  including their unrecovered part. Redemptions, expiry and recovery do not change it.
- **Each credit snapshots its own `expires_at`.** Remaining points are derived FIFO
  from the history; expiry is written lazily under the account lock, idempotently,
  before any movement. Returned points keep the latest expiry of what they came from.
- **Per-invoice frozen spend rule** (`loyalty_invoice_rules`): earning converges on
  `rule(net collected)` whatever order payments and refunds are synced in.

### Rejected
- **Proportional reversal per refund** — not order-independent once syncs run
  after commit and can replay.
- **Negative balances or silent forgiveness.**
- **A nightly expiry job** — derivation is exact and needs no scheduler.

### Consequences
A customer with a refund shortfall sees earnings "absorbed" until it is settled;
staff see the unrecovered figure. FIFO derivation reads the account's history on
each movement — bounded per customer, fine at salon volumes.

Reversal cost: high

---

## ADR-063: Benefits at the till — Sales' generic seams, performed-service consumption, synchronous give-back

**Status:** Accepted · **Date:** 2026-09-19

### Context
Points, member prices and package sessions all change what a sale charges. Sales
must stay the only pricing engine and must not import the benefit modules.

### Decision
- **Sales offers generic seams**: `SaleBenefits` (a `benefit_discount` adjustment
  with an opaque source, optionally on one line, one per line), line-targeted
  discounts in `SalePricing`, the `OfferingCatalog` contract for selling
  memberships and packages as `offering` lines, `SaleFinalizationGuard`, and the
  `SaleFinalized` / `SaleVoided` / `SaleDraftDiscarded` events.
- **Packages are consumed by performed service** on the bill — never by booking;
  one unit per session; add-ons stay charged. Membership discounts are taken from
  the service price too.
- **Memberships and packages activate when their invoice is settled** (or free),
  and **stay usable after a downgrade** until their own expiry.
- **Giving benefits back on void or discard is synchronous** inside the Sales
  transaction: it moves no money, and a void that left a benefit spent would be
  wrong. Failure refuses the void.
- No branch scoping and no tier multiplier in Phase 11.

### Rejected
- **Benefit modules writing sale adjustments directly** — a second pricing path.
- **Decrementing a package at booking** — bookings are cancelled and moved.
- **Asynchronous give-back** — a voided sale would show the benefit spent until a
  job ran.

### Consequences
Sales carries three contracts and three events it does not itself use. A line with
a benefit must be un-benefited before it can be edited.

Reversal cost: medium

---

## ADR-064: A repaired benefit reproduces the event-time result — versioned rules, observed eligibility

**Status:** Accepted · **Date:** 2026-09-20

### Context
ADR-061 made benefit reactions run after the money commits, with reconciliation to
repair what a failure or a crash left undone. That left the repair reading the
CURRENT world: the rule the manager has since changed, and the entitlement the
center may have since lost or regained. A customer would then earn the new rate
for old money, lose points the center owed them, or be paid for money collected
while loyalty was switched off.

### Decision
- **Earning rules are versioned.** `loyalty_rule_versions` is append-only, one row
  per change, each with the instant it took effect. `ConfigureLoyalty` writes one.
  Earning reads versions; the mutable `loyalty_programs` row answers only for what
  happens now (redemption value, minimum, manual adjustments).
- **Eligibility is observed, not inferred.** `loyalty_earning_observations` records
  what Loyalty saw — did the center own `loyalty`, which version was effective —
  either tied to a source event (captured by a READ inside that event's own
  transaction, written just after it commits) or free-standing, from a
  configuration change or a reconciliation run. An event is judged by its own
  observation, or by the last observation before it.
- **Observations are EVIDENCE, never the record.** The durable source is the rule
  history: `at()` takes the rule and its expiry from `loyalty_rule_versions`, keyed
  by the event's own instant, whether or not any observation survives. An
  observation answers only the question the versions cannot — the entitlement,
  which lives in the control plane and keeps no history there. When no observation
  at or before the event survives at all, a rule version that was in force is
  itself the record that earning was running, and the event is judged eligible:
  losing the evidence must never take a customer's points away.
- **Therefore**: a repair uses the rule in force when the money was collected;
  points are dated by their payment or visit and expire from that date; money
  collected while `loyalty` was owned stays recoverable after it is taken away;
  money collected during a gap never earns when it is granted again.
- **An invoice earns under one version** — the one effective at its first earning
  payment — and its money is replayed in event order, so each payment earns the
  increase it caused whatever order the syncs run in. Debits are never backdated.

### Rejected
- **Reading the current program row on repair.** The failure mode this ADR exists
  to prevent.
- **A generic entitlement-history platform in the control plane.** Far larger than
  the question being asked, and Loyalty is the only module that needs the answer.
- **Inferring the entitlement timeline from override rows.** They are overwritten
  in place and keep no history; `updated_at` cannot distinguish a grant from a
  revoke.
- **Writing the eligibility snapshot inside the money's transaction.** A loyalty
  write there could roll a payment back (ADR-061). Reading is safe; the write
  happens after the commit.

### Cost
Two more tenant tables and one extra row per qualifying event. One case stays
inexact: an event whose own observation never happened AND whose state changed
since the previous observation is judged by that earlier observation; hourly
reconciliation bounds the window. Losing the observation table entirely degrades
to "every event under its own rule version is eligible" — the rules, dates and
expiries stay exact, and `LoyaltyEventTimeTest` proves it for both a payment and
a visit.

Reversal cost: high

---

## ADR-065: A benefit on a draft is a hold that expires, and a till line must be confirmed performed

**Status:** Accepted · **Date:** 2026-09-20

### Context
Points, package sessions and membership uses are consumed on a DRAFT sale.
Withdrawing, discarding and voiding all give them back, but Sales has no draft
expiry: a cart nobody finishes would hold a customer's benefits for ever. And a
package session was being spent on any service line, when only a completed
journey stage actually proves the service happened.

### Decision
- **`ReleaseStaleBenefits`** (a Sales reconciler, hourly): a benefit adjustment on
  a sale that is still a draft, where neither the benefit nor the sale has been
  touched for `HOLD_HOURS` (24), is removed; the sale is re-priced; and
  `SaleBenefitReleased` — dispatched inside that transaction, like a void — tells
  the granting module to give it back. The draft stays open at full price.
- **Nothing the unlocked query decided is trusted.** The release takes the sale
  lock and re-checks, from the locked rows, that the sale is still a draft
  (`SaleMutation`), that it is still untouched, and that the adjustment is still
  the same hold. A till that came back to the draft, or finalized it, in the
  window between the query and the lock keeps the benefit — so a release and a
  finalization can never both consume it.
- **A published sale is not a hold**: its redemption stands until the sale is
  voided.
- **Package use requires proof of performance**: a visit line's journey stage must
  be `completed`; a line typed at the till needs an explicit staff confirmation
  (`performed`). The audit row records which of the two it was.

### Rejected
- **Expiring the whole draft.** Deleting somebody's cart is a bigger, separate
  decision; releasing the hold is enough to protect the customer.
- **A reservation subsystem with its own state machine.** The adjustment already
  IS the reservation; it only needed a deadline.
- **Consuming a session whenever a service line is added.** Adding a line to a
  cart is not performing a service, and carts are abandoned.

### Cost
A cashier who leaves a draft overnight finds the benefit gone and must apply it
again. One more scheduled reconciler, and one more field on the package endpoint.

Reversal cost: low

---

## ADR-066: A review is a capability over a completed visit, not an opinion about a center

**Status:** Accepted · **Date:** 2026-09-20

### Context
Phase 12 had to let customers rate their visits. The obvious shape — a public
form addressed by the center's key — is a ratings board: anybody can fill it in,
a competitor can flood it in an afternoon, and the number a center shows the
world is no longer about anything that happened. The other obvious shape, tying
a review to a customer ACCOUNT, silences most of a center's customers, because
most of them will never have a login.

### Decision
- **Eligibility is a completed `ServiceJourney` with at least one completed
  `JourneyStage`.** The customer arrived and something was performed. An
  abandoned visit and a visit where every service was declined produce nothing.
  Payment is NOT required: a complimentary visit and a bill settled next week are
  real visits, and tying feedback to money silences exactly the customers worth
  hearing from.
- **The capability is the identity.** A 256-bit token, SHA-256 at rest,
  plaintext returned once by the minting call and stored nowhere — the invoice
  share-link design (ADR-035, ADR-058). No account is needed, and "send it
  again" mints a new link and retires the old one.
- **A rating names a STAGE**, and the service and employee are read from that
  stage. So a request cannot rate a skipped service, an employee who was merely
  booked, or anything from another customer's visit: those are unreachable
  rather than refused.
- **One visit, one invitation, one review** — three UNIQUE columns, plus the
  invitation's row lock. Two simultaneous submissions end with one review.
- **The customer's words are immutable at the model.** Moderation changes
  visibility and records who and why; it never rewrites content. `hidden` leaves
  the averages, `flagged` stays in them.
- **A downgrade stops issuing and nothing else.** A link the center already gave
  a customer keeps working until its own expiry, and staff keep reading and
  moderating what they already collected.
- **The `reviews` entitlement depends on nothing.** A journey is a walk-in as
  readily as a booked visit (`service_journeys.appointment_id` is nullable,
  ADR-051), so `requires => ['booking']` would have had the dependency closure
  silently drop `reviews` from a walk-in-only center — and would have tied a
  feature about visits that already happened to a capability about arranging
  future ones. Runtime eligibility proves itself from the journey (§3).

### Rejected
- **A public rating form on the center's key.** A ratings board, not a record.
- **Requiring a customer account.** It would exclude most customers.
- **Requiring payment.** See above; also makes Reviews depend on Finance.
- **Storing an average on the branch, service or employee row.** A second source
  of truth that is wrong between recomputations; `RatingSummary` is the one
  place an average exists.
- **A moderation workflow with a pending queue.** A center that has to approve
  its own reviews before anyone sees them is running a testimonial page.
- **A QR dependency.** None is installed; the URL is the feature, and a package
  needs its own decision entry first.
- **`reviews` requiring `booking`.** Written first and corrected before the
  phase closed: it silently excludes walk-in-only centers, which is the case the
  nullable `appointment_id` exists for.

### Cost
Three tenant tables, one public route pair with its own limiter, and a link a
desk must reissue rather than look up. A guest with no account cannot be chased
automatically — Phase 12 builds no messaging provider.

Reversal cost: medium

---

## ADR-067: Notifications listen, and can never hold anything up

**Status:** Accepted · **Date:** 2026-09-20

### Context
Every module now has something worth telling somebody: a confirmed booking, an
issued invoice, an activated package, a one-star review. The tempting shape is a
notifier that Booking, Sales and the rest call directly — which makes every one
of them depend on it, and makes a failure in it able to roll back a cancellation
or lose a customer's review.

### Decision
- **Modules emit facts; Notifications listens.** Booking gained
  `AppointmentConfirmed` / `Rescheduled` / `Cancelled`, Memberships
  `MembershipActivated`, Packages `PackageActivated`, Reviews its two. NOTHING
  imports `Modules\Notifications` — an architecture test names every module.
- **Every handler schedules its work with `AfterCommit`** (the ADR-061 rule, for
  the same reason). The booking, the payment, the activation and the review
  commit first; a failure is reported and dropped.
- **Idempotency is in the schema**: `unique(type, source_type, source_uuid)` and
  `unique(notification_id, recipient_kind, recipient_id)`. Nothing asks "have I
  already sent this?", because that question races with itself.
- **In-app is the only channel.** Creating the recipient row IS delivery, so
  there is no delivery-status model to lie about. WhatsApp, SMS, email, push are
  later phases, and a scan keeps their first call out of this module.
- **Parameters, not sentences.** The message is built at read time from the
  translation files, so a customer who switches language sees their whole inbox
  in it. No HTML, no customer-authored text, no internal ids.
- **A recipient is a KIND and an id**, because `users` and `customer_accounts`
  are different tables with unrelated sequences. Every query filters on both.
- **Staff are targeted by permission and branch**, never by role name (ADR-029).
- **Absent preference means ON**, and only types that name a preference key can
  be suppressed — a switch can never hide a cancellation, an invoice or a
  one-star review.
- **Retention is six months, and never takes an unread `important` row.**

### Rejected
- **A `Notifier` service other modules call.** The dependency direction this ADR
  exists to prevent.
- **Laravel's notification system spread across the codebase.** Explicit models
  and Actions instead: the inbox is application state, not framework magic.
- **A delivery-status column for in-app.** A status that cannot be false.
- **A job per appointment for reminders.** One per booking a center ever takes,
  each holding a time a reschedule silently invalidates, with no way to find the
  stale ones. A bounded hourly sweep instead.
- **Storing rendered text.** It freezes a customer's inbox in last month's
  language and is where markup would first appear.

### Cost
Three tenant tables, one hourly command, and seven new domain events on modules
that previously emitted none. A guest still receives nothing, because they have
no inbox and this phase adds no provider.

Reversal cost: low

---
## ADR-068: A booking has a public reference and a separate secret code

**Status:** Accepted · **Date:** 2026-09-20

### Context
Phase 13 lets a customer reach one booking from a channel that has no session —
WhatsApp, or a phone call to the desk. That needs two things a uuid cannot be at
once: something a person can read aloud, and something that proves they hold the
booking. Using one value for both makes it either unquotable or insecure.

### Decision
- **`reference`** (`B-000412`) is derived from the primary key. Public, printed,
  quotable, ENUMERABLE BY DESIGN, and it authenticates nothing. Backfilled onto
  every historical row in one `UPDATE`.
- **`verification_code`** is ten Crockford Base32 characters (~50 bits) from the
  CSPRNG. Stored only as a keyed digest; the raw value is returned once.
- They are independent of each other, of the primary key, of the uuid, of the
  phone number and of the invoice number.
- The code is generated **explicitly inside `CreateAppointment`**, before its
  transaction, and written in the same `INSERT` as the appointment. Not in a
  model event: a one-time secret has to be handed back through a return value,
  and a model event has nowhere to return anything to.
- `BookingEngine::book()` therefore returns a `BookingResult`. A channel may
  ignore the code; it may not assume it can fetch it later.
- Legacy rows get a reference and **no code**. Minting one for a booking nobody
  asked about creates a credential with no owner.

### Consequences
Sixty-six call sites moved from `Appointment` to `BookingResult`, all mechanical
and all caught by PHPStan. A center can talk about old bookings the same way it
talks about new ones. Anybody can count upwards through the reference space and
learn nothing, because knowing a reference grants nothing.

Reversal cost: medium — the contract change is wide, though shallow.

---

## ADR-069: Booking codes are HMAC under a versioned pepper, never a plain hash

**Status:** Accepted · **Date:** 2026-09-20

### Context
A review link and an invoice share token are 256 random bits, so SHA-256 at rest
is fine: there is no dictionary to slow down against. A booking verification code
is ten typeable characters, because it is read aloud. A plain digest of ~50 bits
is brute-forceable offline from a database copy — 10^15 hashes is a weekend on
rented hardware.

### Decision
- Store `HMAC-SHA256(normalise(code), pepper[version])`, with the pepper in
  `config/security.php` and **never in any database**. A stolen copy is useless
  on its own.
- The pepper is NOT `APP_KEY`. `APP_KEY` encrypts recoverable data and is rotated
  by re-encrypting it; this protects values that can never be re-derived, so the
  lifecycles differ. Coupling them would make an `APP_KEY` rotation silently
  invalidate every outstanding code.
- Every row records the key version that wrote it, and verification uses **that
  version and no other**. Trying every configured key would quietly turn a
  retired key into a live one and make a rotation unobservable.
- A row naming an unconfigured version raises `MissingKeyVersion` rather than
  answering false. Public paths turn that into the same generic refusal a wrong
  code gets, and report it.
- Rotation is: add v2, make it active, keep v1 while its codes may be used,
  remove v1 only when they are intentionally retired. **There is no rehash** —
  the raw code does not exist to rehash from.
- `metastyle:doctor --production` fails closed on a missing active version, a
  version with no key, or any configured key under 32 bytes — checking every
  version, not only the active one.

### Consequences
A new operational secret to deploy, and a doctor check that fails a deployment
without it. In exchange, a database leak does not hand over every booking.

Reversal cost: high — the digests cannot be recomputed.

---

## ADR-070: A signature-verified WhatsApp sender is evidence, not a stored flag

**Status:** Accepted · **Date:** 2026-09-20

### Context
Meta signs every webhook notification with the center's app secret. The sender's
number inside a verified notification is therefore good evidence of control of
that number. The tempting next step — recording that the customer is "verified" —
is what turns evidence into a claim that outlives it.

### Decision
- A signature-verified sender number proves control of that phone **for that
  inbound interaction**, in that tenant. It is resolved through the existing
  E.164 rules against customers in the already-resolved tenant only.
- **No permanent flag.** No `whatsapp_verified`, no `verified_phone`, and nothing
  writes `phone_verified_at` — ADR-040 is unchanged, and this evidence is
  channel-specific.
- A conversation may retain a resolved `customer_id` for context. Every future
  inbound message is still verified from its own signature; an established thread
  is not an easier target than a new one.
- The same number in two centers is two unrelated people. No global search, no
  cross-center directory.
- No `CustomerAccount` is ever created, and no `Customer` either — a record is
  created later by the Booking Engine's own resolver, when somebody actually
  books.
- Identity may never come from message text, an AI tool argument, a query
  parameter or an added JSON field.

### Consequences
A customer who changes phone is a new thread until staff link them, which is the
honest outcome. Nothing anywhere can be read as "this person's number is
verified" on the strength of a WhatsApp message.

Reversal cost: low

---

## ADR-071: A provider destination is platform configuration, never tenant data

**Status:** Accepted · **Date:** 2026-09-20

### Context
Phase 13 makes outbound requests to Meta and OpenAI carrying secrets — a center's
WhatsApp access token, the platform's API key. A configurable destination on a
tenant row would be server-side request forgery by configuration: a center, or
anybody who could edit that row, could aim a credentialed request anywhere.

### Decision
- **No URL column exists** on any Phase 13 tenant table, and an architecture test
  scans every migration for one. Base URLs and API versions are platform
  configuration.
- The only tenant-supplied value that reaches a URL is Meta's `phone_number_id`,
  which is path-segment validated (`[A-Za-z0-9_-]{1,64}`) in the Action AND in the
  adapter.
- A center never supplies an API key, and a center never names an unapproved
  model — `Rule::in()` against the adapter's approved list.
- The Graph API version is PINNED rather than floating, so the day an adapter
  breaks is decided by a deploy rather than by a provider's release calendar.

### Consequences
Adding a provider host is a deploy. That is the right friction for a value that
decides where credentials are sent.

Reversal cost: low

---

## ADR-072: Commercial quotas are tenant-side counters with snapshotted allowances

**Status:** Accepted · **Date:** 2026-09-20

### Context
Entitlements are boolean: does this center own the feature. Phase 13 sells
something measured — AI runs — which needs a number, a period, and a decision on
the hot path of every inbound message. The allowance is control-plane data; the
decision must not be a cross-database check-then-increment.

### Decision
- `Kernel\Usage`, beside `Kernel\Entitlements`: `plan_limits` and
  `tenant_limit_overrides` in the control plane, `usage_events`, `usage_counters`
  and `usage_alerts` in each tenant.
- **The Kernel stays generic.** Resource codes are strings validated against
  `config/usage.php`; the modules own their meaning. An architecture test refuses
  the words `Rayan`, `WhatsApp`, `Conversation`, `ai_runs` and `wa_*` anywhere
  under `app/Kernel/Usage/`.
- **Counted once** by `unique(resource, source_type, source_uuid)` with
  `insertOrIgnore`; **spent atomically** by one conditional `UPDATE` whose
  affected-row count is the answer. 101 of 100 is unreachable.
- The event and the counter move in one transaction, event first, so a refusal
  rolls the evidence back with it.
- The allowance is resolved once per period and **snapshotted** onto the tenant
  counter, so the hot path never crosses databases.
- NULL means unlimited, everywhere. No sentinel numbers.
- **Only `ai_runs` is enforced.** Tokens are metered exactly and refuse nothing: a
  hard monthly token cap needs reserve-then-settle semantics, and guessing at that
  produces a limit that both overcounts and leaks.
- An increase applies immediately; a decrease waits for the next period unless an
  audited `enforce_immediately` says otherwise. `AllowanceSync` repairs a stale
  snapshot upward only, using a VERSION rather than a value comparison — which
  could not tell a stale copy from a correctly deferred decrease.
- The Super Admin projection is derived, lagging and never consulted for a
  decision.

### Consequences
A second control-plane concept to administer. A center's quota decision is one
statement against one row in their own database, and cannot be raced.

Reversal cost: medium

---

## ADR-073: OpenAI and Meta WhatsApp Cloud are the selected Phase 13 providers

**Status:** Accepted · **Date:** 2026-09-20

### Context
`docs/14-FUTURE-INTEGRATIONS.md` left the WhatsApp route open (Cloud API versus a
BSP) and named Anthropic for AI with an undecided SDK. Phase 13 needs both
decided, and ADR-059's rule still applies: never invent a provider integration.

### Decision
- **AI: the OpenAI Responses API**, through Laravel's HTTP client. No SDK — it is
  one JSON endpoint, and a dependency for one POST is a package to keep updated,
  audit and justify.
- **WhatsApp: the Meta Cloud API directly.** No BSP, no intermediary.
- Both were implemented against documentation read on **2026-09-20**, recorded
  field by field in `docs/27-RAYAN.md` §6 and `docs/25-WHATSAPP.md` §16 so a
  future reader can re-verify rather than assume.
- `store: false` on every OpenAI request. The default retains responses for 30
  days in a dashboard, and these carry a center's customers' messages.
- The provider-neutral interfaces stay, with `Unsupported*` implementations and
  deterministic test fakes, so a second provider is an adapter and a registry
  line.
- **Neither adapter has run against real credentials in this environment.** Both
  are exercised by contract tests against recorded response shapes, and the Phase
  13 report says so rather than implying otherwise.

### Consequences
Two real integrations that will need a live smoke test before a center is
onboarded. The honest limitation is stated in the docs, the report and the
provider capability objects rather than discovered in production.

Reversal cost: low — the seams are the point.

---

## ADR-074: Standard and Advanced Reports are separate products and read targets

**Status:** Accepted · **Date:** 2026-09-21

### Decision
Standard Reports require `reports_standard` and read Primary. Advanced Reports
require `reports_advanced` (which depends on Standard) and read Reporting only.
The Reporting connection is assembled lazily from replica credentials plus the
bound tenant database. Missing credentials warn while the product is inactive,
fail production readiness once it is activated, and always fail Advanced
execution closed. There is no Primary fallback. Reports consume public readers
owned by source modules and introduce no aggregate tables.

CSV and browser print are the only export formats in Phase 14. Metrics without
authoritative source facts are stated as unavailable instead of inferred.

### Consequences
Standard reporting remains operational during replica outages or before replica
rollout. Advanced availability is honest and independently operable.

Reversal cost: medium

---

## ADR-075: Advanced Report AI has an independent commercial run allowance

**Status:** Accepted · **Date:** 2026-09-21

### Decision
Contextual report analysis is owned by `reports_advanced`; it does not require
`rayan_ai`. It consumes the enforced `advanced_report_ai_runs` resource, never
the customer-facing `ai_runs` resource. Both paths reuse `Kernel\Usage`, the
OpenAI provider seam, and token/failure resources. Report runs carry source
`advanced_report_analysis`, receive an exact authorized report context, and have
no tools or write path.

### Consequences
Customer-chat use cannot exhaust report-analysis allowance and report analysis
cannot exhaust the bot allowance, while provider usage remains comparable.

Reversal cost: low

---

## ADR-076: APP_URL-derived hosts plus registry-backed center identity

**Status:** Accepted · **Date:** 2026-09-22

### Context
Corporate, SADMIN, registration emails and center links cannot safely maintain
separate host settings. A valid-looking subdomain also is not proof that a
center exists, and legacy tenant rows may lack usable domain data.

### Decision
`APP_URL` is the only base-origin setting. `PlatformHosts` derives Corporate,
`superadmin` and one-label center hosts while preserving scheme and port.
Center requests additionally resolve through the authoritative `domains`
registry and fail closed on unknown, reserved, malformed, nested or conflicting
hosts. Production tenancy is subdomain-only.

SADMIN presentation uses `RegisteredCenterAddress`. It generates links only
from a valid registered host that agrees with any stored slug. Missing or
invalid legacy data is shown as unavailable; UUIDs, names and arbitrary host
text are never converted into center URLs.

### Consequences
Local development needs only `php artisan serve` and an APP_URL origin.
Production needs wildcard DNS and TLS, which readiness reports explicitly.
Incomplete legacy centers remain manageable without becoming routable by
guesswork.

Reversal cost: high

---

## ADR-077: Platform identities require permission checks and MFA

**Status:** Accepted · **Date:** 2026-09-22

### Context
SADMIN can change commercial limits, lifecycle state, content and operational
settings across every center. Reusing a tenant identity or adding an owner-style
bypass would collapse the control-plane boundary.

### Decision
Platform users, roles, password resets and sessions live in the control plane
behind the `platform` guard. Every SADMIN capability names a platform
permission; system-role synchronization grants the declared catalog rather than
bypassing checks. MFA enrollment is mandatory on first sign-in and the normal
challenge remains mandatory afterward. Mutations use audited application
actions.

The known development Super Admin is seed data only, hashed, idempotent and
limited to local/development/testing environments. It receives no MFA bypass
and is never automatically created in production.

### Consequences
Platform compromise has a separate credential and session boundary, and access
policy stays testable. Local setup remains reproducible while production cannot
inherit a known credential.

Reversal cost: medium

---

## ADR-078: Locale and non-sensitive UI preferences persist independently

**Status:** Accepted · **Date:** 2026-09-22

### Context
A query-only language switch reset on the next navigation. Theme and sidebar
state also flashed or reset during Livewire navigation. None of those display
preferences should become an authorization or tenant-resolution input.

### Decision
A validated explicit locale is persisted in the server session and takes
precedence over user preference, `Accept-Language` and application defaults.
The internal Sorani key remains `ckb`; its visible abbreviation is `KU`.
Language changes presentation only and preserve the current route where safe.

Theme and sidebar expansion are non-sensitive client preferences stored
locally and applied before first paint. The authenticated session remains
server-side. The responsive drawer adds Escape handling, focus containment and
focus restoration rather than treating collapsed desktop state as mobile
navigation.

### Consequences
Navigation no longer resets language or shell state, including across Livewire
updates, while no client preference can select a tenant or grant permission.

Reversal cost: low

---

## ADR-079: SaaS billing documents are server-rendered HTML with mPDF, and issued invoices snapshot their issuer

**Status:** Accepted · **Date:** 2026-09-23

### Context
Platform staff need to print and download every SaaS invoice and a center's
account statement, in English, Arabic and Kurdish Sorani, on A4, with a
configurable template. Browser screenshots and headless-browser automation are
not deterministic and add an unmanaged runtime. Invoices are financial records:
a template edit must never change what an issued invoice says it charged or who
issued it.

### Decision
- **Package: `mpdf/mpdf` ^8.2** (GPL-2.0-only library used unmodified as a
  server-side dependency; installed 8.3.1). It renders the same Blade HTML the
  print view shows, supports RTL and OpenType shaping, and runs in-process.
  Paper and margins are set in `PdfRenderer`; the templates avoid `@page size`
  (it breaks mPDF's layout) and inline borders on spans (mPDF warns).
- **Fonts:** Latin runs use the brand's Poppins. Arabic and Kurdish runs use
  mPDF's bundled **XB Riyaz**: mPDF cannot apply GPOS type-8 / mark-filtering
  lookups in Noto Sans Arabic (any word with a harakah is refused) and Noto
  Sans Arabic has no Latin digits or brackets for mixed runs. The print view in
  the browser keeps Noto Sans Arabic. `ArabicScriptFonts` maps `ckb` (absent
  from mPDF's own table) to the same font as Arabic.
- **Historical safety:** money is read only from the invoice and payment rows.
  The issuer block (company name, address, contacts, tax number) is copied into
  `saas_invoices.issuer_snapshot` when the invoice is issued; an invoice issued
  before the column existed shows the current identity. Layout, accent, logo
  size/alignment and header/footer texts are presentation and follow the
  current template whenever a document is printed.
- **Template:** structured values only (`InvoiceTemplate`): plain text per
  language (no tags or angle brackets), validated email/website, and choices
  from fixed lists (three layouts, five named accents, logo sizes and
  alignments, A4/Letter, show/hide toggles). No HTML, CSS or script can reach a
  document. The logo is the one platform logo (ADR-080).
- **Statement:** SaaS billing only, never the center's POS/finance. One section
  per currency; amounts in different currencies are never summed. Opening and
  running balances only when unfiltered; a status or method filter shows lines
  and totals without balances.

### Consequences
PDFs are deterministic for the same data and need no browser. Arabic-script
PDF text uses a different face from the web print view; this is a typography
difference only. Editing the billing identity affects invoices issued from then
on. Audit: `platform.billing_identity.updated` (warning) for issuer changes,
`platform.invoice_template.updated` for presentation.

Reversal cost: medium (another renderer would need the same HTML subset)

---

## ADR-080: One platform branding source with a structured, token-based theme

**Status:** Accepted · **Date:** 2026-09-23

### Context
The Super Admin must change Meta Style's own logo, favicon and colours without
editing CSS, and without touching a center's own branding. Free-form CSS or SVG
uploads would be stored XSS against platform staff.

### Decision
- `Kernel\Platform\Branding\PlatformBranding` is the single source for the
  platform logo (light, optional dark), favicon, "show platform name" and the
  theme. It is read by the corporate site, the Super Admin, its sign-in pages
  (`platform.partials.brand-head`, `x-brand.logo` on platform hosts only) and
  SaaS documents. A center host always gets the bundled mark.
- Uploads are PNG/JPEG (logo, ≤1 MB, 32–4000 px) and PNG/ICO (favicon, square,
  16–512 px, ≤200 KB), checked by sniffed MIME and image header, stored on the
  public disk under `platform/branding/` with random names. No SVG upload.
  Replacing or resetting deletes the previous file; nothing else references it.
- `PlatformTheme` holds twelve named colours per theme and four gradients (two
  or three `#rrggbb` stops and one of eight angles). It maps them onto the
  existing semantic tokens (`--color-canvas`, `--color-primary`, …) plus new
  `--color-secondary` and `--gradient-primary|hero|accent|cta`. A colour equal
  to its Rose Gold Luxe default emits nothing, so the default renders exactly as
  tokens.css; a changed colour emits its token and derived supporting tokens.
- Contrast below WCAG AA is reported in the settings preview; colours are never
  adjusted automatically. "Reset to Meta Style defaults" is confirmed and
  audited.
- Permission `platform.branding.manage`; audit actions
  `platform.branding.{logo,favicon}.{updated,removed}`,
  `platform.branding.identity.updated`, `platform.branding.theme.{updated,reset}`.

### Consequences
No stylesheet edits for a rebrand, no CSS injection surface, and center pages
are unaffected. Derived tokens for a changed colour are computed, not hand-tuned.

Reversal cost: low

---

## ADR-081: Center Users directory as a control-plane read model; a phone for every center user account

**Status:** Accepted · **Date:** 2026-09-23

### Context
The Super Admin needs to list and search every center's owners, managers and
staff. Opening every tenant database per page view does not scale and widens
the blast radius of a query. Separately, the product rule is that every center
user account has a phone number, entered with a country picker (Iraq +964 by
default) and stored canonically.

### Decision
- `center_user_directory` (+ `center_user_directory_branches`) in the control
  database is a read model written only by `Kernel\Platform\Directory\CenterUserDirectory`,
  which reads each center's `users`, roles and branches inside that center's own
  context (UsageProjector pattern). Projected on provisioning, after every
  platform change to a center user, and every 15 minutes
  (`metastyle:center-users:project`). Never used to authorize; never holds
  customers, credentials or tokens.
- Phone search uses the normalised number: `phone_e164` and `phone_national`
  (digits after the calling code), so national, trunk-prefixed and
  international spellings find the same account.
- Platform actions: edit name/email/phone (`UpdateCenterUserIdentity`), block,
  reactivate, send an access link (`ManageCenterAccount`). The owner cannot be
  blocked from here. No impersonation. Audit records changed field names and
  fingerprints of contact values, never the values. Permissions:
  `platform.center_user.view`, `platform.center_user.manage`.
- Phone rule: `RegistrationService`, `CreateCenterForPlatform` and
  `CreateEmployee` (whenever a login is created) refuse a missing or invalid
  phone; forms use one component (`x-ui.phone`) and `PhoneRule`, and store
  `PhoneNumber::fromParts()` (E.164). An employee without a login is not an
  account and needs no phone. Existing accounts without a phone are shown as
  "Phone missing" and completed through the edit form; nothing is back-filled
  and `users.phone` stays nullable (no NOT NULL migration on legacy data).
- **Asset: flag-icons 7.5.0 (MIT)** — the 257 two-letter country SVGs copied to
  `public/icons/flags/` with its licence; served as static images, no remote
  requests. Country names come from ICU (`intl`) in the reader's language,
  including Kurdish. `PhoneCountries` holds calling codes, trunk prefixes and a
  few well-established length rules; the Manager staff form gained only the
  phone field needed for its logins to keep working.

### Consequences
The directory can lag a center by up to 15 minutes for changes made inside the
center; changes made from the platform are immediate. Staff logins without a
phone are refused everywhere, including the tenant API.

Reversal cost: medium

---

## ADR-082: The platform chooses AI models freely; centers still choose only from an allow-list

**Status:** Accepted · **Date:** 2026-09-23

### Context
The Super Admin was limited to three shipped model ids, so older or cheaper
models the account supports could not be used without a deploy.

### Decision
The platform's assistant model and an optional report-analysis model accept any
syntactically valid identifier (`[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}`). The
provider's own catalog (`GET /models` with the platform key) can be refreshed
and searched; when it cannot be loaded, an identifier can be typed. Whatever
the platform configures is added to the models a center may choose from, so a
center still never names an arbitrary model (ADR-071). No price or capability
claim is shown: the catalog carries identifiers, dates and owners only.
Permission `platform.ai.manage`; the key itself still needs
`platform.security.manage`, is stored encrypted and is never rendered.

### Consequences
A model change is a setting, audited (`platform.settings.ai.updated`,
`platform.ai.models.refreshed`), not a deploy. A mistyped identifier is only
caught by the provider at run time — the settings page shows whether it was in
the last catalog.

Reversal cost: low

---


## ADR-083: A center's public media is served from its own host, and Livewire uploads run inside the center

**Status:** Accepted · **Date:** 2026-09-23

### Context
The tenancy filesystem bootstrapper roots the `local` and `public` disks at
`storage/tenants/{key}/app/…`. `MediaItem::url()` returned the disk URL
(`APP_URL/storage/…`), which the global `public/storage` link resolves to the
CENTRAL root, so every center image (logo, catalog, site gallery) was a broken
link. Livewire's temporary upload (`/livewire/upload-file`) and preview
endpoints ran without the center bound, so a file written by the upload request
could not be found by the component request that followed (it looked under
`tenants/{key}`), and a preview could read the shared central directory.

### Decision
- `CenterMediaController` serves `GET /media/{collection}/{uuid}.{ext}` on the
  center host (`public.tenant` group, `throttle:public-media`: 900/min per
  tenant+IP). Only the public collections (`branding`, `catalog`), only
  MediaStore-shaped paths (uuid names, allow-listed extensions), each served as
  its allow-listed content type with `nosniff`, `Content-Security-Policy:
  default-src 'none'; sandbox`, `Cross-Origin-Resource-Policy: same-site` and
  `Cache-Control: public, max-age=31536000, immutable` (names are never reused).
  Another center's host resolves another disk: the file is simply not found.
- `MediaItem::url()` returns that URL when a center is bound and the request is
  on a center host; elsewhere (queues, control plane) it keeps the disk URL.
- `ResolveLivewireTenant` also resolves the center for `livewire.upload-file`
  and `livewire.preview-file`, so the temporary file lands in, and is read from,
  the center's own disk.

### Consequences
Center imagery works on every center surface without a symlink per tenant and
without exposing private collections. A CDN can front `/media/*` later (the
responses are immutable). Tested in `tests/Feature/Media/CenterMediaServingTest.php`
(the upload test fails without the resolver change).

Reversal cost: low

---

## ADR-084: Locked plan features stay visible to the people who could use them, as an upgrade — never as a route

**Status:** Accepted · **Date:** 2026-09-23

### Context
The product owner wants plan features the center has not bought to stay visible
in the Manager sidebar as an upsell, with real plan names and prices, while the
feature stays inaccessible. Earlier ADRs (docs/05, docs/20, docs/21) keep
HISTORY readable after a downgrade, and tests pin that.

### Decision
- Visibility is two independent questions. Permission ("can this person use
  it?") hides the item entirely when missing — an employee is never sold a
  module they could never open. Entitlement ("has this center bought it?")
  turns a permitted item into a LOCKED item.
- `App\View\Manager\FeatureOffer` builds everything shown from data only: the
  entitlement catalog, the center's `CurrentSubscription`, and `PlanOffers`
  (public, active plans in a total commercial order — sort order, then
  monthly-equivalent price, then id — with effective codes = entitlements plus
  dependency closure). "Available from X" is the first such plan other than the
  current one; "Contact us" when none includes it; "Not enabled" when the
  current plan includes it but an override removed it. "Recommended" only for
  `is_featured` plans. Prices in the plan's own currency. No plan name in code.
- Clicking a locked item opens the upgrade dialog; its no-JS fallback is the
  plan page. Suspended/cancelled/expired subscriptions show one account banner,
  not locks on every item.
- Pages use `RequiresFeature`: a center that does not own the feature gets the
  upgrade state and the page loads no data. A module with HISTORY (bookings,
  sales, finance, expenses, loyalty, memberships, packages) stays read-only with
  a compact notice when records exist, and hides create actions. Actions keep
  refusing on the server (`Entitlements::ensure`); `EntitlementRequired` on a
  web request renders a 403 upgrade page instead of a 500.

### Consequences
Marketing and authorisation never mix: the upsell is presentation over the same
server-side checks. Plan catalog changes (Super Admin) change the labels
immediately, with no deploy.

Reversal cost: low

---

## ADR-085: Namespaced test databases make parallel test runs isolated

**Status:** Accepted · **Date:** 2026-09-23

### Context
`TestDatabaseManager::dropAll()` drops every `meta_style_test_*` database at
suite start, so two Pest processes on one server destroy each other's control
and tenant databases (deadlocks, "table not found", bogus failures). Parallel
work (several agents, several terminals) needs independent runs.

### Decision
A run started with `METASTYLE_TEST_DB_NAMESPACE=x` (1–12 lowercase letters or
digits) owns only databases prefixed `meta_style_test_ns_x_`, and must name its
control database and tenant prefix inside it:

    METASTYLE_TEST_DB_NAMESPACE=x DB_CONTROL_DATABASE=meta_style_test_ns_x_control METASTYLE_TENANT_DB_PREFIX=meta_style_test_ns_x_t php vendor/bin/pest …

A default run (no namespace) never touches namespaced databases; the guard still
refuses any name outside the run's own prefix. This is isolation, not a shared
server — Pest's `--parallel` against one database stays forbidden.

### Consequences
Independent runs can proceed at the same time. Storage fakes
(`storage/framework/testing`) are still shared on disk, so a test that fakes the
same disk in two runs at once can rarely flake; none depends on that today.

Reversal cost: low

---

## ADR-086: Center appearance has its own permissions; drag-and-drop ordering is a small in-house directive

**Status:** Accepted · **Date:** 2026-09-23

### Decision
- `appearance.view` / `appearance.manage` (Permission::AppearanceView/Manage,
  group `appearance`) gate the center's own site: brand, landing page, and the
  booking, cart and print presentation. The public MENU keeps `menu.view` /
  `menu.manage`. The Manager system role receives both; owners through the role
  sync. Deploy runs `metastyle:roles:sync --all` (ADR-032).
- Ordering by drag and drop uses `resources/js/platform/sortable.js`, a
  dependency-free Alpine directive (`x-sortable="method"`): pointer events
  (mouse, pen, touch), optimistic DOM move, one Livewire call with
  `(uuid, index[, targetList])` per drop, ArrowUp/Down/Home/End on the handle.
  The server re-derives the order under a lock in an Action; the client only
  states an intent. Visible Move up / Move down buttons remain on every list
  (WCAG 2.2 dragging movements). No npm package was added (Livewire 3.8 does not
  ship Alpine's sort plugin).

Reversal cost: low

## ADR-087: Manager shell: navigation, locked features, plan page, notifications and center support

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager build (area `shell`)

### ADR-S1 — Support attachments live on a control-plane disk (`platform_support`)

**Context.** Support tickets are control-plane rows (tenant_id column), read by
the platform team on the platform host and by the center on its own host. The
tenancy filesystem bootstrapper suffixes the `local` and `public` disks per
tenant, so a file a center stores on `local` is invisible to the platform, and
a file the Super Admin stores on `local` (central root) is invisible from a
center host.

**Decision.** A new disk `platform_support` (config/filesystems.php; root
`storage/app/private/platform-support`, `serve => false`) that is deliberately
NOT in `config/tenancy.php`'s tenant disks. Center uploads go through
`CenterSupportAttachments` (type from content via finfo, PDF/PNG/JPEG/text,
5 MB, 3 per message, path = `tickets/{ticket uuid}/{new uuid}.{ext}`).
Downloads: `ManagerSupportAttachmentController` (center host, tenant-scoped via
`CenterSupportDesk::attachment`, non-internal messages only) and the existing
`SupportAttachmentController` (platform host) — both attachment + `nosniff`.

**Consequences.** One shared store, readable from both hosts, isolation by the
tenant-scoped query (TenantIsolation test). The Super Admin reply form writes to the same
`platform_support` store (through `CenterSupportAttachments`), so a file Meta
Style attaches to a public reply is one the center can download. Production needs the same disk on shared
storage if the app runs on several servers (same as `local` today).

### ADR-S2 — `EntitlementRequired` on the web is a 403 page with the offer

**Context.** An Action's `Entitlements::ensure()` escaping on a web route was a
500. Pages with history stay readable after a downgrade (docs/05), so route
middleware is not the answer.

**Decision.** A second render callback in `bootstrap/app.php`, after the API
renderer (JSON keeps its envelope), maps `EntitlementRequired` on a bound
center to `errors.feature-locked` (403) fed by `FeatureOffer`: inside the
Manager shell for page loads, a bare page for a Livewire update overlay.

**Consequences.** No page is unlocked by this; it only explains the refusal.
The exception is still reported by the handler (logging unchanged).

### ADR-S3 — Navigation: permission hides, entitlement locks, status bans locks

**Decision.** `ManagerNavigation` (PHP, composed into the layout) hides items
without permission, shows items the center does not own as LOCKED with a
catalog-derived label (`FeatureOffer::lockLabel`, `PlanOffers` commercial
order + dependency closure), and shows no lock at all when the access level is
not Full (suspended / cancelled / expired → `SubscriptionBanner`). Locked
items are plain links to `center.plan?feature=KEY` (no `wire:navigate`); JS
opens `UpgradePrompt`, which re-validates the key (catalog + nav map + the
viewer's permissions + actually locked).

**Consequences.** Downgraded centers reach history pages through the prompt's
"View existing records" link (history features) or the URL; the pages decide
read-only vs locked themselves (RequiresFeature).

### ADR-S4 — Bell "new notification" mark lives in the session

**Decision.** The chime compares `Inbox::latestUnreadId()` with a high-water
mark stored in the session (`manager_bell.seen`), never in the Livewire
snapshot, so no auto-increment id reaches the page, and several tabs chime once.

### ADR-S5 — Shared shell components carry no PHP blocks

**Context.** `x-navigation.language-switcher` and `x-shell.account-menu` are
shared by the Manager, Super Admin and the public platform pages, and both
computed values in `@php` blocks (registry lookups, URLs, initials).

**Decision.** The language switcher stays an ANONYMOUS component; a view
composer (`App\View\Composers\LanguageSwitcherComposer`, registered in
AppServiceProvider) hands it presented arrays (code, EN/AR/KU short label,
native name, direction, flag, href, active). A class component was tried and
rejected: Blade bakes the component class into every compiled layout, so
switching anonymous -> class (or back) leaves stale compiled views that 500
until `view:clear`. The account menu takes an optional `initials` prop; the
Manager passes it from `ManagerShell`, other callers fall back to
`App\View\Manager\PersonInitials::of($name)`. Markup is unchanged.

**Consequences.** No caller had to change (backward compatible); the views
hold no logic; Super Admin output is identical (SuperAdminUiStateTest green).

### ADR-S6 — An upgrade offer only links where the viewer may go

**Decision.** `FeatureOffer::for()` returns the "View plans" link only for a
viewer holding `settings.view` (the Plan page's own gate) and the support link
only for `platform_support.view` (prefilled subject for `.manage`). When
neither applies, the upgrade prompt says to ask whoever manages the center's
subscription instead of offering a dead-end link. The nav's no-JS fallback
stays `center.plan?feature=KEY` (a 200 page either way).

Reversal cost: medium

---

## ADR-088: Manager overview and reports: period figures from report contracts, "now" from module reads

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager build (area `dashboard-reports`)

### Context

The Manager overview (`/manager`) shows two kinds of fact: PERIOD figures for a
date range compared with the like-for-like previous range, and NOW facts
(today's book, who is waiting, visits in progress, the latest invoices). The
period figures were already built from the Standard Reports' read contracts
(`ManagerOverview`) so the dashboard and Reports cannot disagree; the "now"
blocks were inline Eloquent queries in the Livewire component — one of which
(`Appointment::query()`) broke the Booking boundary, another counted queue
tickets from every business date.

Every Livewire round-trip (even opening the custom-range form) re-ran all
period scans: current + previous range, ~12 reader calls, several of them
cursoring through every row of the range.

### Decision

1. PERIOD figures stay in `ManagerOverview` (Reports module), extended with
   hourly buckets for one-day ranges, previous-period series, sales/payment
   series per currency, sales by category, customer growth, queue trend and
   benefit activity. Readers gained `hourly` maps (and sales `billed_invoices`
   per currency, the average-ticket denominator). `PaymentReportReader::totals()`
   is a lean path without outstanding balances or customer values.
2. NOW facts are read by NEW module classes named `Dashboard*`
   (`Booking\DashboardAppointments`, `Queue\DashboardQueueSnapshot`,
   `ServiceJourney\DashboardFloor`, `Sales\DashboardRecentSales`,
   `Customers\DashboardNewestCustomers`, `Employees\DashboardTeamSnapshot`,
   `Catalog\DashboardServiceOptions`), each applying branch scope (and the
   `view_own` scope where the module has one) in SQL and returning arrays. No
   Livewire class touches a module model.
3. The PERIOD result is cached for 60 s under a key naming the tenant, viewer,
   branch scope, gates, range, branch and locale, through the tenant-tagged
   cache facade; a Refresh control drops it. NOW reads are never cached.
4. The plan chip (translated status, plan name, trial days left or renewal
   date, link to `center.plan`) is read from `CurrentSubscription` for
   `settings.view` only. A limiting subscription state (past due, suspended,
   ended) is NOT repeated on the overview: the shell's `SubscriptionBanner`
   already shows it on every Manager page.
5. No activity feed: `audit_logs` has no `branch_id`, so a feed could not be
   branch-scoped for a branch manager. Revisit only with a schema change.

### Consequences

- Opening the custom-range form, switching tabs back or re-rendering after a
  UI-only action costs the NOW reads only.
- Period numbers may be up to a minute old; the page says "Figures as of HH:mm"
  and offers Refresh. Operational "now" is always current.
- A new `Dashboard*` read must live in its module (boundary tests), be branch
  scoped in SQL, and get a TenantIsolation case (`DashboardIsolationTest`).
- "Employees active today" is defined as "employees with bookings today" — no
  attendance is claimed until an attendance module exists.

---

# Proposed ADR — Report pages translate by key and format by column type

### Context

The report domain (`StandardReports`, `AdvancedReports`) returns English
labels that the API, CSV and RAYAN rely on. The Manager pages printed them
raw (English in ar/ckb), showed minor units as integers and enum codes as-is,
offered a booking-source filter value (`public`) the engine never writes, and
carried a disabled "comparison" select that did nothing.

### Decision

1. The domain keeps its English identity and ADDS stable keys
   (`unavailable_keys`, `metric_key`, `stage_key`, KPI `key`); the Livewire
   `ReportPresenter` translates by key from `lang/*/manager_reports.php` and
   formats every column by type (money through the platform currency
   formatter / `x-ui.money`, enums through labels, durations, local dates).
2. `StandardReportCatalog` declares which optional filters (`source`,
   `employee`, `service`) each report's readers support; `filtersFor()` drops
   the rest for the page, CSV and API alike. Source options come from
   `BookingSource::cases()`.
3. The Advanced comparison is stated ("compared with <previous period>"), not
   offered as a control, because the domain always compares with the equal
   preceding period.
4. RAYAN report analysis answers in the viewer's UI language (ckb named
   "Kurdish (Sorani)"); the Livewire `ask()` is rate-limited like
   `throttle:report-analysis` because Livewire bypasses route middleware.
5. Report styles moved from inline `<style>` to `resources/css/manager/dashboard.css`
   and charts to `x-chart.*`; `ReportsUiContractTest` reads the literals from
   where they now live (and additionally pins that print hides the real shell).

### Consequences

- A new KPI/column/unavailable key needs a `manager_reports` entry in all three
  languages, or the page falls back to the English domain label.
- API/CSV consumers are unchanged.

Reversal cost: medium

---

## ADR-089: Manager bookings desk

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager build (area `booking`)

### ADR-P-BK-1 — Move availability is a form of the ONE availability question

**Context.** The Phase 6 calendar offered reschedule slots by re-running
`AvailabilityEngine::slots()` with lines rebuilt from the catalog (no variation,
no add-ons, no offsets) and WITHOUT ignoring the appointment being moved. So a
60-minute booking was never offered "+30 minutes" (it collided with itself), a
booking whose service got longer in the catalog was offered the wrong times,
rooms held by the booking were re-allocated in the advisory answer, and the desk
could only search the booking's own day. `RescheduleAppointment` (the
authoritative path) already re-validates from the STORED items and ignores the
appointment; the advisory path disagreed with it.

**Decision.** Add `AvailabilityQuery::forMove(branchUuid, appointmentUuid, from[, to])`
(an optional `movingAppointmentUuid` on the existing DTO). `AvailabilityEngine`
answers it through `slotsForMove()`: layout from `StoredLayout` (item offsets and
snapshot durations; candidates by the reschedule's rules; held resources),
staffing through `EmployeeAssigner::assignFromCandidates()` and rooms through
`ResourceAllocator::heldFit()` — the in-memory twins of the reschedule's
`reassign()` and `revalidateUnderLock()` — against busy/load maps that ignore the
appointment (`ConflictFinder`/`ResourceFinder` already took that parameter). The
public channel refuses it. `BookingEngine` keeps its five methods;
`RescheduleAppointment` is unchanged.

**Consequences.** One availability engine still answers every question; the desk
is offered exactly the moves the reschedule accepts (a test reschedules to every
offered slot). If the reschedule's staffing or room rules change,
`StoredLayout`/`assignFromCandidates`/`heldFit` must change with them — the
consistency test is the tripwire.

### ADR-P-BK-2 — Changing the booked team member is a Booking Action

**Context.** docs/15 §17 says reassigning bookings of a deactivated employee "is
a decision the center makes", and the calendar has always listed them ("Needs a
new team member"), but no Action existed to make that decision — only
`ReassignStageEmployee` after check-in, which changes what HAPPENED, not what
was RESERVED.

**Decision.** `ReassignAppointmentEmployee(appointment, itemUuid, ?employeeUuid, user)`:
`booking` entitlement, `appointment.update` + branch scope, open appointments
only; the person must be in `EmployeeAssigner::eligibleIds()` (active, eligible,
at the branch); "any" = lowest free eligible id; "free" is checked under
`BranchLock` in the transaction against appointments and availability blocks
(ignoring this appointment) and against the visit's OTHER items (parallel
layouts); the item's window and the appointment's status are RE-READ under the
lock, so a move or cancellation that committed a moment earlier is never
answered from stale times. Only `employee_id` and `employee_selection` change (Specific when a
person is named, so a later move keeps them). Audited
`booking.appointment.reassigned` with `AppointmentSnapshot` before/after. Not
part of the `BookingEngine` interface (like notes and verification codes).

**Consequences.** Changing the services or the customer note of an existing
booking is still NOT supported — a service change re-prices a snapshot and
needs its own design; the desk moves or cancels-and-rebooks instead. Rooms are
covered by ADR-P-BK-5.

### ADR-P-BK-3 — The desk never names the Appointment model

**Context.** `BookingBoundaryTest` exempted `Livewire\Center\Calendar` from the
"no Appointment model outside Booking" rule for read-only use; the split into
child components would have needed more exemptions, and `Calendar::open()` loaded
any uuid without scope (a branch-limited or view-own user could read another
branch's booking with a crafted request).

**Decision.** `CalendarQuery::find(uuid, viewer)` is the scoped finder
(permission, branch scope, own scope, detail relations eager-loaded; anything
else is `ModelNotFoundException`). Every Livewire booking class resolves through
it and passes the result straight to the engine/Actions without naming the
model. `BookingVerificationController` likewise moved its lookups into
`IssueVerificationCode::forStaffByUuid/forAccountByUuid`. The `Calendar`
exemption in `BookingBoundaryTest` is now unused and can be removed by the
coordinator (the test file is not in this area's ownership).

### ADR-P-BK-4 — The desk records no preferred language for a customer it creates

**Context.** The desk's first draft passed the Manager interface locale as the
new customer's preferred locale. That is the RECEPTIONIST's language, not the
customer's, and it would silently steer that customer's notifications.

**Decision.** A customer created at the desk (and by the staff API) carries no
preferred locale; the center default applies until the customer chooses one.

**Consequences.** Same behaviour on every staff channel; nothing to migrate.

### ADR-P-BK-5 — Changing a reserved room or device is a Booking Action

**Context.** A resource reservation is a snapshot (docs/15 §36): allocation
picks a concrete room at booking time and nothing moves it afterwards. A desk
whose Room 2 is out of service had no way to give a booked customer Room 3
except cancelling and rebooking (losing the reference and the verification
code).

**Decision.** `ReassignAppointmentResource(appointment, itemUuid,
fromResourceUuid, toResourceUuid, user)`: `booking` entitlement,
`appointment.update` + branch scope, open appointments only; the held
reservation must belong to that item; the target must be in
`ResourceAllocator::candidates(type, branch)` (same type, active, at the
branch) and not already held by the item; under `BranchLock`, with the item and
status re-read, the target's live loads (`ResourceFinder::loadsFor`, which
include the visit's own other services) must fit the reserved quantity by
`Occupancy::fits` for the item's window. Only `resource_id` and the two name
snapshots change; audited `booking.appointment.resource_changed` with the
resource before/after (no PII). The desk offers it through
`BookingOptions::roomCandidates()` and `AppointmentActions['change_room']`,
hidden while a visit is running (the board swaps actual usage).

**Consequences.** One more Booking Action outside the five-method engine
interface (like reassigning a person). If allocation ever starts splitting one
requirement across rooms differently, this Action's "one row per resource per
service" refusal must be revisited with it.

Reversal cost: medium

---

## ADR-090: Manager queue, visit board and conversations

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager build (area `queue-journey`)

### ADR-P-QJ-1 — The visit board keeps finished bookings on their day

**Context.** `JourneyBoardQuery::forDay()` selected the day's appointments with
`->blocking()` (booked/confirmed). `CompleteJourney` moves the appointment to
`completed` and `CancelVisit` to `cancelled`, so every booked visit vanished from
the board the moment it finished; the Completed and Left columns only ever held
walk-ins. The API board (mobile) had the same gap.

**Decision.** The appointment half of the board is "still expected OR already a
visit": `blocking()` OR `id IN (SELECT appointment_id FROM service_journeys)`,
inside the same branch + day window and scope filters. A booking cancelled or
no-showed before anybody arrived (no journey) still leaves the board.

**Consequences.** The API board payload now includes completed/cancelled booked
visits that have a journey (additive; `JourneySurfaceTest` count unchanged for a
single not-arrived booking). Sorting unchanged. No migration; the subquery rides
the existing `service_journeys.appointment_id` unique index.

### ADR-P-QJ-2 — Queue lock: upgrade page, or read-only history when tickets exist

**Context.** The wave brief says the queue page renders
`lockedView('queue_management')` when not owned. docs/17 §19 says withdrawal
blocks NEW operations and historical queue data stays readable to management —
the same "reads survive downgrade" rule bookings and sales follow.

**Decision.** Without `queue_management`: if the center has never issued a
ticket, the page is the upgrade offer (loads no data). If tickets exist, the
board renders read-only — every action hidden, a compact history notice with the
offer, no walk-in, no "no number yet" list, no polling — and any crafted action
is refused by the Action (`QueueAccess::ensure`) and shown as a translated
notice. The same rule is applied to the visit board (`booking`) and the inbox
(`whatsapp_booking`/`rayan_ai`; take over and close stay available because those
Actions use `authorize()`, replies need the channel).

**Consequences.** Matches docs/17 §19 and the Booking downgrade rule; a center
that never bought the queue still sees only the offer. One extra `exists()`
query when the feature is not owned.

### ADR-P-QJ-3 — Operational refusals become translated notices in one place

**Context.** Queue/Journey/Conversations Actions refuse in English, and
`EntitlementRequired` escaped the three screens as a Livewire 500.

**Decision.** `App\Livewire\Center\Queue\OperationalFailure` maps the known
refusals (exact messages and the "state changed under you" family) to
`manager_queue.errors.*`, maps `EntitlementRequired`, authorization, not-found
and throttling to fixed messages, and falls back to the Action's English text
in `en` and a generic line elsewhere. `RunsDeskActions::attempt()` is the only
catch site for the queue, visit and inbox components.

**Consequences.** A new refusal message in an Action shows in English until a
key is added — never a raw key and never a 500. The map is presentation only;
it decides nothing.

### Note (not a decision) — print route receives the host parameter positionally

Found while verifying `QueueTicketPrintTest`: routes under
`Route::domain('{center}.…')` pass `center` as the FIRST route parameter, and
Laravel binds scalar controller parameters positionally, so
`QueueTicketPrintController::__invoke(Request $request, string $uuid, …)`
receives the slug as `$uuid` and answers 404 for every ticket (reproduced with
`ControllerDispatcher::resolveMethodDependencies`: args = [Request, 'demo',
TicketPrinter, PrintAppearance, TenantContext, '<uuid>']).
`InvoicePrintController` has the same shape. `QueueDisplayPageController`
already takes `string $center` first. Fix belongs to the print-appearance
owner: add `string $center` as the first scalar parameter (or read the uuid by
name with `$request->route('uuid')`). Queue-journey does not touch that file.

Reversal cost: medium

---

## ADR-091: Manager customers, loyalty, memberships, packages and reviews

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager build (area `customers`)

### A. The customer page reads history through its owners' modules

**Context.** The Manager customer page needs bookings, visits, purchases,
points, memberships, packages and reviews. `Modules\Customers` sits below all of
those modules (BenefitsBoundaryTest, SalesBoundaryTest, ReviewsBoundaryTest), so
it may import none of them, and a presenter field in Customers would invert the
dependency.

**Decision.** Each history is a read class in the module that OWNS the data,
named after the surface so no other area collides:
`Booking\Application\CustomerProfileAppointments` (`appointment.view`),
`ServiceJourney\Application\CustomerProfileVisits` (`journey.view`),
`Sales\Application\CustomerProfileSales` (`sale.view`, issued only, never
`pos`), plus a `customer` filter on `ReviewsQuery` (`review.view`). Each takes
the viewer and the customer's internal id (resolved by `CustomerQuery::find`,
which authorised `customer.view`), applies the viewer's branch scope in SQL, and
is rendered by its own Livewire panel under `App\Livewire\Center\Customers`.
"Has visited" / "last visit" on the CRM list read `service_journeys` BY TABLE
NAME inside Customers, the precedent `SqlCustomerReportReader` already set.

**Consequences.** No new module dependency; every tab is re-authorised on the
server; a uuid from another center is a 404. The visit reads join on the
existing `customer_id` / `appointment_id` indexes. A future unified CRM timeline
can compose these reads instead of adding a new table.

### B. A plain review date is a branch-local day, and `to` includes it

**Context.** `ReviewsQuery` compared `submitted_at <= 'Y-m-d'` (midnight UTC),
so the last day asked for was excluded and days were UTC, not the branch's.

**Decision.** `Reviews\Application\ReviewDays` turns plain `Y-m-d` bounds into
one half-open UTC window PER BRANCH TIMEZONE (`[local midnight of from, local
midnight after to)`), OR-ed per timezone group — one query, indexed columns, no
SQL timezone functions. `ReviewsQuery` uses it for plain dates (the Manager page
and the API alike); a full timestamp from an API caller is still compared as
given. `RatingSummary::overall/byService/byEmployee` accept an optional
`ReviewDays` so the summary follows the same days.

**Consequences.** The API's `?to=2026-09-24` now includes the 24th (a fix, not
a contract change for timestamps). Adds one small `branches` read when a date
filter is present.

### C. A duplicate phone names its owner in a typed exception

**Context.** `SaveCustomer` refuses a phone that belongs to someone else, naming
them in the message, but a screen could not link to the existing record.

**Decision.** `Customers\Domain\Exceptions\DuplicateCustomerPhone extends
ValidationException` (still `phone`, still 422 on the API) carries the owner's
uuid and name; the Manager form offers "Open customer" instead of creating a
second record (ADR-041). `CustomerInput::$phoneCountry` lets the Manager send
the shared picker's country; `SaveCustomer` then uses `PhoneNumber::fromParts`
and stores the international form as `phone_display`. The API keeps `parse()`.

**Consequences.** Existing tests asserting a `ValidationException` still pass;
no schema change.

### D. The downgrade rule applies per module inside the customer page

**Context.** The Manager pages for loyalty, memberships, packages and reviews
follow the history rule (upgrade page when the center never had data; read-only
with the compact notice when it has). The customer page embeds the same data
as tabs (`CustomerBenefitsPanel`, `Customers\ReviewsPanel`), which showed an
empty history and a "paused" note for a feature the center never owned.

**Decision.** Each embedded panel asks `RequiresFeature::lockedFeature()` per
module. Not owned + no center-wide history (`LoyaltyQuery::hasHistory`,
`MembershipsQuery::hasHistory`, `PackagesQuery::hasHistory`,
`ReviewsQuery::anyCollected`): the compact plan notice alone, and that module
is not read. Not owned + history: the compact history notice above the
read-only data (no adjust/cancel controls beyond what the Actions allow).
The tab itself stays visible (permission-gated only), so the upsell is found
where the data would be.

**Consequences.** One extra `exists` query per locked module per render;
nothing changes for a center that owns the feature. Actions still refuse on
the server (`ensure`) whatever the panel shows.

### E. A review link is published on the center's own host

**Context.** Since Phase 15 every public center page (menu, booking, invoice,
review) lives on `{slug}.{base}` and `ResolvePublicTenant` answers only when the
host is the center's registered domain AND the `{center}` segment equals its
slug. `InvoiceLinks::url()` was moved to the resolved host's slug then;
`ReviewInvitations::url()` still passed the PUBLIC KEY as `{center}`, so every
newly minted review link (`http://ctr_….{base}/r/{token}`) was a 404 — the
customer could never leave the review. `ReviewSurfaceTest` still requested the
old `/r/{key}/{token}` path and failed.

**Decision.** `ReviewInvitations::url()` takes `{center}` from the URL
generator's default parameter (set by `ResolveTenant` on a center host — the
Manager, or an API call made on the center's host), falling back to the public
key only when there is no center host, byte-for-byte the `InvoiceLinks` rule.
The tests now open the page on the center host.

**Consequences.** Links minted from the Manager or from a center-host API call
work. A link minted by an API client on a non-center host (no slug to use)
still carries the public key and does not resolve — the same open gap invoice
links have; closing it needs a Kernel way to read a tenant's registered slug
(`RegisteredCenterAddress` takes an Infrastructure model), so it is left to the
platform owner.

Reversal cost: medium

---

## ADR-092: Manager team, roles, branches and resources

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager build (area `staff`)

### ADR-S1 — No-escalation applies to the TARGET as well as the grant ("rank")

**Context.** The existing rule "nobody may grant a permission they do not hold" covered only what is
granted (role codes). A manager with `staff.access.manage` / `staff.deactivate` could still deactivate,
re-scope, re-role or re-issue an activation link for an account holding MORE than they do — e.g. a
pending account holding the Owner role: re-issuing its link and activating it themselves is a way up.

**Decision.** Every staff-access or lifecycle Action also refuses when the target holds any permission
the actor lacks (`StaffGuard::outranks` in Employees, `StaffAccessRules::assertNotOutranked` in the
Kernel), and when the target reaches a branch outside the actor's scope ("reach"). The owner account and
the actor's own account are never targets (owner may keep changing their own roles but must keep Owner).

**Consequences.** Scoped managers cannot manage staff who also work at other branches; the owner (who
holds everything, unrestricted) can manage everyone. The Manager UI computes the same flags in
`EmployeePresenter` so it only draws buttons that can succeed.

### ADR-S2 — Activation links are re-issued only before first activation

**Context.** The plaintext activation token is shown to the manager (needed to hand it over). If a link
could be re-issued for an ACTIVATED account, the manager could set that person's password.

**Decision.** `ReissueActivationLink` refuses once `users.password` is set; a forgotten password goes
through the person's own "Forgot password" flow. Redemption (`ManageStaffActivation::redeem`) is a
transaction with `lockForUpdate` on the token row, refuses a disabled account, and never signs in.
The guest page `/activate/{token}` is `guest:web` + `throttle:login`; the token is never logged,
flashed or stored in session.

### ADR-S3 — Employee service eligibility from the employee side lives in Catalog, gated by `staff.update`

**Context.** `employee_service` is Catalog's pivot; Employees must not import Catalog.

**Decision.** New `Catalog\Application\Actions\SetEmployeeServices(Employee, serviceUuids, actor)`:
permission `staff.update` + branch scope over every branch the person works at (not `service.update` —
the profile must not become a back door into editing services); takes `BranchLock` on the person's
branches (eligibility changes bookability, ADR-047); retired services may stay but are never newly
assigned; audited `catalog.employee_services.updated`.

### ADR-S4 — Owner system role is read-only

**Decision.** `UpdateRolePermissions` refuses the Owner role, and a code the editor does not hold can be
neither ADDED nor REMOVED by them (only added codes are checked for escalation; codes they lack are merged
back into whatever the form sent, so a partial editor can never save a role at all nor strip a superior's
role of what they cannot grant back). Owner means "everything"; the deploy sync
re-adds missing codes anyway, and removing `role.permissions.manage` from it is the lock-out path.
Other system roles stay editable (docs/06 §4). System roles cannot be renamed or deleted; custom roles
cannot be deleted while held.

### ADR-S5 — Branch web edits round-trip everything the Actions replace

**Context.** `SaveBranchSchedule` replaces hours AND date exceptions; `SaveBranch` force-fills
coordinates and sort order. The Livewire form passed `[]` exceptions and omitted lat/long/sort, so every
web edit wiped holidays and coordinates.

**Decision.** The form loads upcoming exceptions for editing and carries past ones through unchanged
(locked property), and always sends latitude/longitude/sort order. `SaveBranchSchedule` additionally
refuses invalid and duplicate exception dates. Branch phone/WhatsApp use `x-ui.phone` and are stored
E.164. `RestoreBranch` un-archives a branch as active but hidden from the public menu.

### Not done (needs a product/domain decision)

- Per-employee working hours/shifts: no table exists; Booking availability = branch hours − appointments −
  blocks (docs/15). Adding it is a Booking-engine change + migration under BranchLock.
- Linking an existing login (e.g. the owner) as a bookable employee: needs a new Action.

### ADR-S6 — Only an unrestricted actor opens a new branch

**Context.** `SaveBranch` checked scope only for existing branches, so a manager scoped to some branches
could create one outside their own scope — a branch they could then neither see nor edit.

**Decision.** `SaveBranch` refuses a CREATE unless the actor's branch scope is unrestricted; the Manager
hides "Add branch" for scoped managers. `SetUserBranchScope` likewise lets a branch archived since it was
granted stay in a login's scope, but a NEW grant must be to a live branch.

**Consequences.** Opening branches is an owner / all-branches action; a scoped manager asks them.

Reversal cost: medium

---

## ADR-093: Manager POS, sales, receipts and center finance

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager build (area `pos-finance`)

### A7-1 · Domain refusals are translated at the Livewire boundary, keyed by a slug of the English sentence

**Context.** The Sales, Payments and Finance Actions refuse in English sentences
(`SaleFailed::policy('…')`, `AuthorizationException('You may not …')`). The API
contract and ~20 existing tests read those exact sentences. 172 of 173 had no
Arabic or Kurdish translation, so AR/KU tills showed English errors. `lang/ar.json`
and `lang/ckb.json` are shared files this wave may not edit, and a dotted group key
cannot hold a sentence that ends in a full stop.

**Decision.** `App\Livewire\Center\PosFinance\Refusals::text()` is the one translation
step, called by `GuardsMoneyActions::attempt()` (all money components):
English is returned exactly as the Action said it; otherwise a JSON line is used
if one exists; otherwise `manager_pos.refusals.<slug>` where the slug is
`Str::limit(Str::slug($english, '_'), 96, '')`; a handful of interpolated refusals
(ranges, maxima, a provider name, a phone owner, a sale state) are matched by
pattern and re-rendered from `manager_pos.refusal_patterns.*`. Unknown text is
shown unchanged, never invented. `EntitlementRequired` becomes
`manager_pos.errors.entitlement` with the feature's name — the raw `[pos]` key
never reaches a page. The three language files are generated from one
three-column source so their keys are identical; a test checks parity and that
every catalogued key round-trips through `Refusals::key()`.

**Consequences.** Actions keep their API contract and tests; staff read refusals in
their language. A new English refusal needs a line in the catalog (otherwise it
shows in English — visible, not broken). A future move to parameterised
refusals can replace the pattern table.

### A7-2 · Products and invoice prefixes live on a POS settings page

**Context.** The Sales history screen also hosted product create/archive (one name
copied into all three locales) and the branch invoice prefix. Neither the catalog
nor the branches area owns products; the prefix refusal already told managers to
"set one in the POS settings".

**Decision.** `/manager/pos/settings` (`Center\PosSettings`, `pos`) holds products
(per ENABLED content language names that never drop a switched-off language's
text; create, edit, off-sale, archive via `SaveProduct`) and each branch's prefix
(`SetBranchInvoicePrefix`). The Sales screen is history only. The pinned test was
relocated to the new page, same Actions and outcome.

### A7-3 · Customer invoice links are published under the center's own host

**Context.** Since Phase 15 a center answers only on `{slug}.<base>`; the public
route's `{center}` segment must equal that slug. `InvoiceLinks::url()` still
passed the public key (`ctr_…`), so every link minted at the till pointed at a
host the domains registry does not know (verified read-only against the dev
registry). `Reviews\ReviewInvitations::url()` has the same defect (not this area).

**Decision.** `InvoiceLinks::url()` uses the request's `center` URL default (set by
`ResolveTenant` from the host, and by `asCenter()` in tests); only a hostless API
call falls back to the public key as before.

**Consequences.** Links minted from the Manager open. An API client minting a link
off-host still gets the old form; a tenant-slug read in the Kernel contract
(`TenantContext`) would close that — proposed for the coordinator.

### A7-4 · One money workspace with branch-local windows

**Decision.** Sales · Receipts · Shifts · Overview · Expenses share tabs
(`PosFinance\MoneyTabs`, per permission) and one window control
(`PosFinance\HasMoneyWindow`: today, yesterday, last 7 days, this month, last
month, custom). The trait only chooses calendar dates in the branch's timezone;
each module query converts them to a half-open UTC window and refuses more than
92 days or a malformed date with a domain message. Totals are summed inside the
modules per currency (`receiptTotals`, `expenseTotals`, `historyTotals`) and only
formatted in Livewire.

### A7-5 · Who performed a till service line

**Decision.** `AddSaleLine` accepts `employee` for a service line and
`ChangeSaleLine::update` can change or clear it on a service line rung up at the
till (never on a visit line, whose performer comes from its stage). Eligibility is
Booking's: active, assigned to the sale's branch, allowed to perform the service
(`Sales\Application\LineEmployees`). The staff presenter shows it; the public
invoice never names staff. No migration (`sale_items.employee_id` existed).
Commission and staff-attributed revenue remain out of scope.

### A7-6 · The downgrade rule for money screens reads each module's own history

**Context.** Sales, cashier shifts, finance, expenses, gateways and receipts are
history that must stay readable after a downgrade (docs/05 §6.2), while a
center that never had the feature should see the upgrade state instead of an
empty table. The Finance ledger is written regardless of `finance`, so "has
data" differs per screen.

**Decision.** Each module answers for its own records with a cheap `exists()`
read: `SalesQuery::hasHistory()` (a non-draft sale — Sales, Shifts),
`FinanceQuery::hasHistory()` (a ledger entry or an expense — Overview,
Expenses), `PaymentsQuery::hasHistory()` (a payment — Receipts; locked only
when the center has neither `pos` nor `payments`), and a configured gateway
account for Gateways. A page with history renders read-only under
`<x-manager.feature-locked compact history />`; Actions still refuse on the
server. The reads run in the bound tenant only (TenantIsolation case).

**Consequences.** A pos-only center that collected cash keeps a readable Finance
ledger after it never owned `finance` (the ledger is legitimately its money
record); the dashboard summary itself still needs `finance`.

### A7-7 · Provider callbacks are published under the center's own host too

**Context.** The payments webhook route (`api.payments.webhook`) sits behind
`public.tenant`, which since Phase 15 resolves a center ONLY from its own host
and requires the `{center}` segment to equal that host's slug.
`GatewayConnections::callbackUrl()` still minted
`<platform>/api/v1/payments/{publicKey}/gateways/{account}/webhook`, an address
that now answers 404 — a provider's callback could never settle a payment
(the status query still could). Every webhook test posted to the same stale
form.

**Decision.** Same rule as A7-3: `callbackUrl()` uses the request's `center`
URL default (set by host resolution on the till's and the invoice page's
requests), so the callback is `{slug}.<base>/api/v1/payments/{slug}/...`; only
an off-host API call falls back to the public key. The webhook and public
invoice tests post to the center host with its slug.

**Consequences.** Callbacks for payments started at the till or from the
customer's page reach the center. A payment started by an off-host API client
still carries the unresolvable form until the Kernel exposes the tenant's
registered slug (`TenantContext`), proposed to the coordinator.

Reversal cost: medium

---

## ADR-094: Services library: one library order; variations are deactivated, never deleted

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager build (area `catalog`)


### Context

The Manager services library needs drag-and-drop ordering of categories and of
services inside a category, plus moving services between categories. Before this,
`services.sort_order` and `service_categories.sort_order` existed but were never set
by the UI (every row was 0) and `SaveService` reset `sort_order` to 0 on every edit.
Several other surfaces order services FLAT by `(sort_order, id)`: the till
(PointOfSale), the calendar, the queue board, Resources and the public menu's
"featured services".

Separately, `SaveService::syncVariations()` hard-deleted variations the caller left
out. `package_definition_items` and `customer_package_items` reference
`service_variations` with `restrictOnDelete`, so removing such a variation threw a
`QueryException` and the Manager page errored; `appointment_items` / `sale_items`
use `nullOnDelete`, so history silently lost its link.

### Decision

1. **One order for the whole library.** Categories are ordered 0..n-1 among live
   (non-archived) categories. A service's `sort_order` is its position in the whole
   library: the first category's services, then the second's, …, uncategorised last,
   renumbered 0..N-1 after every change (`App\Modules\Catalog\Application\Ordering`).
   Flat lists therefore group services the way the owner arranged them, and
   category-grouped lists (the menu) still get each category's own order.
2. **Intent, not lists.** `MoveServiceCategory(category, toIndex)`,
   `MoveService(service, toIndex)`, `MoveService::toCategory(service, ?target, ?toIndex)`
   and `step()` variants take ONE intent. The Action locks categories then services
   (`lockForUpdate`, same order for every writer), re-derives the order from the rows,
   clamps the index, writes only rows whose position changed and audits
   `catalog.category.moved` / `catalog.service.moved`. A client-sent full order is never
   accepted; filtered lists are never offered for sorting.
3. Create appends to the end of the category; an edit keeps its place unless the
   category changes (then it appends there). `ServiceInput::$sortOrder` and
   `SaveServiceCategory`'s `$sortOrder` become nullable: null = "the library decides";
   an explicit integer (API) is written as-is and normalised by the next move.
4. **Variations are deactivated, never deleted.** A variation left out of a save is set
   `is_active = false`. No schema change: consumers already filter active variations.
5. `DuplicateService` creates the copy inactive and not public (like restore), right
   after the source. Resource requirements are copied by the Livewire caller through
   `SetServiceResourceRequirements` in the same tenant transaction (Resources imports
   Catalog, not the reverse).
6. **A photo belongs to its owner.** The Manager gallery writes through the media
   kernel (`StoreMediaItem`, `ManageMedia`, which require `media.upload`) AND
   requires the right to edit the owner: `service.create|update` for a service's
   gallery, `category.manage` for a category's image. `media.upload` alone no
   longer lets someone restyle a service they may not edit.
7. Quick status toggles (`SetServiceStatus`) decide on the row read under
   `lockForUpdate` in a tenant transaction, so a service archived in another tab
   is refused rather than switched back on.

### Consequences

- Moving a category rewrites the positions of the services between the old and new
  places (one UPDATE per changed row; a center has hundreds of services, not
  thousands). Catalog reorders serialise on the category/service row locks.
- The POS tiles, calendar and queue service lists now follow category order, and
  "featured services" on the public menu take the first services of the first
  category. This is intended but visible to other areas.
- Inactive variations accumulate; the editor shows them switched off with a hint,
  and they can be switched back on. Hard deletion of never-referenced variations is
  deliberately not offered (it would need Catalog to know about Packages/Booking/Sales).
- The API keeps working: absent `sort_order` no longer resets the position.

Reversal cost: medium

---

## ADR-095: The center's public site is a tenant module (CenterSite), versioned like the menu

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager build (area `site-builder`)

### Context

Centers need their own public home page (`/` on the center host) and their own
brand (logo, favicon, colours) — built by the owner, previewed, published,
rolled back — as rich as the Super Admin's corporate landing CMS. The
corporate CMS (`LandingCms`) is control plane ("nothing to do with tenants",
docs/04 §4 L6) and stores platform paths; the platform brand
(`PlatformBranding`) is control plane too. A center page is a guest page, so
center-authored markup would be stored XSS against the center's customers
(ADR-038).

### Decision

1. A new tenant module `app/Modules/CenterSite` owns the brand, the site
   content, the publisher and the public renderer's view model.
2. Site content is stored in one tenant table `site_versions`
   (draft / published / archived), mirroring `menu_versions`. Restore copies a
   version FORWARD into the draft (never flips an archived row back live).
3. The brand is a tenant `settings` row (`site_brand`), no migration.
4. `Domain\SiteContent::normalize()` is a strict allow-list: plain text per
   language with the center's PRIMARY content language required; links are a
   section anchor, a page KEY (home/list/booking) or an https URL; media and
   records are uuids validated against THIS center's own references (read
   through each module's contract). Unknown keys/values are refused with a
   path, not dropped. Colours never appear in page content — backgrounds name
   brand colours or gradients.
5. Media is referenced by `MediaItem` uuid under new `MediaOwner::Brand` /
   `MediaOwner::Site`, stored in the public `Branding` collection.
   `StoreMediaItem` gains a `MediaKind` (image / video mp4-webm sniffed from the
   container / favicon square PNG-ICO). No SVG.
6. Cross-module reads go through NEW contracts (Catalog `SiteCatalogReader`,
   Employees `PublicTeamReader`, Branches `SiteBranchReader`, Memberships
   `SiteMembershipReader`, Packages `SitePackageReader`, Reviews
   `PublicRatingReader`) returning allow-listed arrays. Reviews expose the
   aggregate only.
7. The draft preview is an authenticated Manager route
   (`center.appearance.site.preview`), never under `public.tenant` (ADR-036),
   with a `lang` parameter that does not touch the staff UI locale.
8. Permissions reuse the existing `appearance.view` / `appearance.manage`
   (no new codes, so no roles:sync on deploy); uploads also need
   `media.upload`.
9. Public contact numbers in the site (footer phone / WhatsApp) are E.164,
   entered through the one phone field (country + national number) and
   converted with `PhoneNumber::fromParts()`; the renderer links `tel:` /
   `wa.me` only from a canonical number, never guessing a country code.
10. Upload refusals from `Kernel\Media\Application\StoreMediaItem` are
    translated through a new `media_upload` lang group (en/ar/ckb), so the
    catalog and site builders show them in the staff member's language.

### Consequences

- A never-published center renders a default page built from translations and
  real data only (no seeding, no backfill). Publishing is required only to
  customise.
- Removing an image from the page only unreferences it; site media is not yet
  garbage-collected (a later sweep may purge site media referenced by no
  version).
- Video uploads are capped by `config('site.media.video_max_kb')` and pass
  through PHP upload limits; very large videos need an infrastructure decision
  (direct-to-object-storage uploads) later.
- The map embed provider is platform config (`config/site.php`, OpenStreetMap
  by default); a center-typed map URL is only ever a link.
- White Label (Phase 17) can read the same `CenterBrandReader`.

Reversal cost: medium

---

## ADR-096: Menu, booking, cart and print appearance; center settings

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager build (area `appearance-settings`)

### A. Closed appearance documents in `Kernel\Appearance`

**Context.** Booking-page, cart-page and print appearance are all "a few
colours, a few choices, a few switches and some per-language copy" that land on
a guest page or on paper. The menu already solved this with a code-owned
catalog (`MenuPresentation`), but that class is menu-specific, and Printing
cannot import the Menu module.

**Decision.** A small generic kernel: `AppearanceSchema` (built from config:
colours with defaults, choice lists, flags, text length caps), `Appearance`
(`fromInput` strict — unknown key, bad colour, unlisted choice, markup, over-long
text, unsupported language are rejected with a field + reason;
`fromStored` lenient — a bad stored value falls back to its default so a guest
page never fails), `AppearanceRejected`, and `AppearanceStore` (one tenant
`settings` row per document, no cache). No markup, CSS or URL field exists.
Texts are stored for every supported language (disabling never deletes).

**Consequences.** New documents are config + one Action. The kernel knows no
module. Colours are always `#rrggbb` and choices enums, so renderers may print
them into custom properties/classes. Audits record changed KEYS, never copy.

### B. `Modules\Printing` owns the center's print appearance

**Context.** Receipts/A4 (Sales) and queue tickets (Queue) both need it; neither
module may import the other. docs/04 lists Printing as an L6 channel module.

**Decision.** Create `app/Modules/Printing` with `PrintAppearance` (read +
render view + filtered copy of an invoice/ticket view model) and
`Actions\UpdatePrintAppearance` (`appearance.manage` + `printing`). Applied only
by the print controllers at render time; the immutable invoice is never
written. Sales and Queue do not import Printing.

**Consequences.** docs/04's Printing row now has a first resident. A future
thermal-printer renderer consumes the same `$print` array.

### C. A center edits its own control-plane profile

**Context.** Name, contact and timezone were Super-Admin-only
(`SaasAdmin\UpdateCenterProfile`). Centers need to maintain their own contact
details; the Kernel may not import SaasAdmin.

**Decision.** `Kernel\Tenancy\Actions\UpdateOwnCenterProfile` writes ONLY the
bound tenant's row (id from `TenantContext`, never the request): name, contact
person/email/phone (E.164), timezone, and currency while no sale/payment exists
(the same rule as `currencyLocked`, re-stated against the tenant DB). Slug stays
platform-only. `settings.manage`. Audited to tenant AND platform logs with
contact values fingerprinted.

**Consequences.** Two writers of the same columns (platform + center), both
audited. The currency-lock rule exists twice (SaasAdmin and Kernel) — a shared
kernel helper could replace both later.

### D. Booking/cart appearance and customer policies live in `Modules\Menu`

**Context.** They are presentation of the guest pages reached from the menu
(`menu/book.blade.php`, center-public cart/checkout). The Booking module owns
rules, not copy.

**Decision.** `Menu\Application\PublicPageAppearance` + `SavePageAppearance`
(booking needs `booking`) + `SavePublicPolicies` (`settings.manage`). The
booking RULES stay in Booking (`UpdateBookingSettings`).

**Consequences.** One place renders every guest page's appearance; brand
inheritance is read once through `CenterBrandReader` (`PublicBrand`).

### E. Menu template = defaults + preset

**Decision.** A template's `theme` keeps the pre-Phase-15 look for every new key
(so older published menus render unchanged); its `preset` is applied only when
the owner picks the template in the editor, and reaches customers only through
a publish.

Reversal cost: medium

---

## ADR-097: Links name the center's own host from its registered slug; the booking boundary is checked per namespace

**Status:** Accepted · **Date:** 2026-09-24

### Context
Since Phase 15 a center answers only on `{slug}.<base domain>`. Invoice, review
and payment-callback links minted WITHOUT a request host (an API client on
another host, a queued job) fell back to the center's public key — an address
that no longer opens. Separately, `BookingBoundaryTest` checked controllers and
Livewire in ONE multi-target `not->toUse()`, which fails only when every target
namespace uses the model: new Livewire classes held Appointment models while the
test stayed green.

### Decision
- The Kernel `Tenant` value object carries the optional registered `slug`
  (mapped by `TenantModel::toValueObject()`). `InvoiceLinks::url()`,
  `ReviewInvitations::url()` and `GatewayConnections::callbackUrl()` use the
  request host's slug, else the bound center's registered slug, and fall back to
  the public key only for a center registered before hosts existed.
- `BookingBoundaryTest` asserts controllers and Livewire separately. The Manager
  reads bookings only through Booking-module queries returning plain arrays
  (`CalendarQuery`, `CustomerProfileAppointments::rowsForCustomer`,
  `AppointmentsInsideBlocks`); the stale Livewire exemptions were removed.

### Consequences
Links work from every entry point. A new component that holds an appointment
model fails the architecture suite on its own (verified with a probe class).

Amendment (browser QA, 2026-09-24): invoice and review routes are bound to the center's
domain, so `route()` already names its host; the payment callback is an API route with
no domain, so `GatewayConnections::callbackUrl()` now places it on the center host with
`PlatformHosts::centerUrl()`, exactly like the WhatsApp webhook — off-host it used to
name the platform's own host, where the route does not resolve.

Reversal cost: low

---

## ADR-098: Manager WhatsApp settings over the Phase 13 channel; Super Admin alone grants the feature

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager (area `integrations`)

### ADR-P-WA-1 — Receiving is gated after the signature, not before

Context: `ConversationsAccess::channelEnabled()` existed but nothing called it. A center that
lost `whatsapp_booking` still had inbound messages persisted and — with `rayan_ai` still owned —
answered by the assistant (an AI run spent). A disabled account likewise routed messages to the
assistant, which spent a run and then failed to send. docs/25 §19 says losing the entitlement
stops receiving.

Decision: `ReceiveWhatsAppWebhook::message()` checks, after the signature verified and the replay
claim, `channelEnabled()` and `$account->enabled`; if either is false the event row is settled
`ignored` with `channel_inactive` / `account_disabled` and the item is dropped. The endpoint still
answers 200 (Meta retries non-2xx forever). Status callbacks are still applied. The handshake
(`challenge()`) is refused without the entitlement.

Consequences: no thread, customer lookup or AI run for a switched-off channel; nothing before the
signature changed (ADR-070 intact); a manager switching the account off genuinely stops the bot.

### ADR-P-WA-2 — The center's RAYAN on/off switch is enforced by the assistant

Context: `RayanSettings::enabled()` (docs/27 §5 "whether the assistant is on") was stored and
shown by the API but read by nothing at runtime.

Decision: `RayanAssistant::answer()` returns `AssistantReply::handOff('assistant_disabled')` first
thing when the setting is off — the same hand-off an unowned assistant gets; nothing is run or
metered. Conversations cannot import `Rayan\Application`, so the check lives in RAYAN.

Consequences: the Manager switch is real. `takeover_on_request` remains unenforced (no runtime
reader, no customer hand-off tool) and is therefore NOT exposed in the Manager UI; docs/27 §5
still lists it as a center choice — needs a decision (implement a hand-off tool, or drop the
setting).

### ADR-P-WA-3 — RAYAN settings changes are audited by the channel

Context: RAYAN may not use `Kernel\Audit` (ConversationsBoundaryTest), and no RAYAN settings
Action existed (the API controller writes `RayanSettings` directly, unaudited).

Decision: new `Rayan\Application\Actions\ConfigureAssistant` (entitlement `rayan_ai`,
`ai.manage`, approved model, tone cap) dispatches `Rayan\Domain\Events\AssistantSettingsChanged`;
`Conversations\Application\Listeners\AuditAssistantSettings` (registered in AppServiceProvider)
records `rayan.settings.updated` — on/off + model before/after + `tone_changed`, never the tone.

Consequences: Manager changes are audited. The API `RayanSettingsController` still bypasses the
Action (not in this area's ownership) — recommend switching it to `ConfigureAssistant`.

### ADR-P-WA-4 — The webhook handshake is recorded without a migration

Decision: a successful `challenge()` writes ONE `whatsapp_webhook_events` row per account
(`kind = handshake`, deterministic fingerprint, `received_at` refreshed on repeat, no token or
challenge stored). `WhatsAppReadiness` uses it (or any signed notification / `last_inbound_at`)
for "webhook verified".

Consequences: expand-only use of an existing string column; the table cannot grow from
repeated handshakes.

### ADR-P-WA-5 — PHP's query-key mangling (bug fix)

Context: PHP turns `hub.mode` into `hub_mode` before Laravel sees it; the adapter asked for the
dotted names only, so Meta's registration handshake could never succeed over real HTTP (the
existing tests called the Action with hand-built dotted arrays and never exercised HTTP).

Decision: `InboundEnvelope::queryValue()` falls back to the underscored spelling. The value
checks (`hash_equals` on the verify token) are unchanged. Covered by an HTTP test.

### ADR-P-WA-6 — Typed credentials never survive a Livewire request

Decision: `ConnectionForm::dehydrate()` empties `$credentials` at the end of EVERY request, so a
typed secret is never in a snapshot or rendered page, whether the save succeeded, was refused or
failed validation (the form then asks to retype). The browser sends deferred `wire:model` values
with the submit, so saving works; tests submit the same way (`Testable::update(calls, updates)`).

### ADR-P-WA-7 — The webhook address uses the center host's slug (bug fix)

Context: Phase 15 made `ResolvePublicTenant` accept a public route only on the center's own host
with that host's slug in the `{center}` segment. `WhatsAppConnections::webhookUrl()` still put
the PUBLIC KEY (`ctr_…`) there, so the address the API and the Manager showed could never
resolve (404) — the same defect Payments already fixed in `GatewayConnections::callbackUrl()`.

Decision: the same rule — the `center` URL default set by tenant resolution on the center host
(the slug) when present, otherwise the public key as before (keeps the existing
ConversationsIsolationTest contract for off-host calls). Covered by an HTTP handshake test and a
page assertion on the exact URL.

### Coordinator follow-ups (2026-09-24)
- `Api\RayanSettingsController::update()` now goes through `ConfigureAssistant` (entitlement, `ai.manage`, approved models, audit) — tested.
- `WhatsAppConnections::webhookUrl()` places the non-domain webhook route on the center's own host from its slug, also off-host (ADR-097).

Reversal cost: medium

---


## ADR-099: Standard Reports analytics are module facts beside the ReportResult

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager (area `reports-standard`)

### Context
The Standard Reports page was redesigned into an analytics workspace (KPIs with previous-period
comparison, trends with a previous overlay, distributions, rankings, heatmaps, sortable detail tables).
`StandardReports::run()` returns one `ReportResult` per report for the API, CSV and RAYAN; its shape is
a public contract and holds one period only. The page needs two periods, per-bucket series and several
dimensions, and must not rebuild reporting logic in Livewire.

### Decision
1. A separate module service, `Reports\Application\Analytics\StandardAnalytics`, builds per-report FACTS
   (numbers with unit and direction, series on the period's buckets, dimensions with previous values)
   from the same reader contracts, through the same `ReportRequestFactory` and the same authorization
   (`StandardReports::authorize()`, now public and shared with `run()`). Each reader is asked at most once
   per period. `ReportResult`, the API and the CSV are unchanged (two additive row columns).
2. Presentation (labels, formats, chart choice) lives in `App\View\Reports\*`, never in the module.
3. The report period (presets incl. last month and this year, and their like-for-like comparison) is a
   Reports-module value object (`ReportPeriod`) composed of Kernel `DateRange`s; the Kernel class is not
   widened.
4. Booking rates (completion / cancellation / no-show) share one denominator: bookings that reached an
   outcome, so they sum to 100 % and do not dip for future bookings in a month-to-date period.
5. Page figures are cached 60 s per tenant/viewer/scope/permissions/report/periods/branch/filters/locale;
   authorization runs on every read, cached or not.

### Consequences
+ The CSV/API contract is untouched; the page and export share authorization and filter narrowing.
+ Readers gained additive facts (heatmaps, per-line booking dimensions, branch billed, daily finance and
  loyalty series, top customers by name) that Advanced Reports may reuse.
- Two representations of a report exist (ReportResult for exports, facts for the page); definitions are
  kept aligned by sharing readers and the Facts helpers, and pinned by tests.

---

## ADR-100: Advanced Reports is a workspace of child sections over the reporting contracts

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager (area `reports-advanced`)

### Context
The Advanced Reports page was one Livewire component rendering one catalog report at a time with a
disabled comparison control. The product owner asked for a premium analytics workspace (executive
summary, trends, intelligence sections, entity comparisons, AI insights) that must not reload
unrelated sections, must keep the Phase 14 guarantees (Reporting target only, structured AI context,
separate allowance) and must stay usable when no AI provider is configured.

### Decision
1. The page is a shell (gates + one toolbar + view switch) with child components per section
   (Workspace, Compare, Library lazy; AiInsights eager) that receive the toolbar state as #[Reactive]
   props. Each child re-validates permission, entitlement and branch scope on the server.
2. All figures come from existing reporting CONTRACTS through two new module services:
   AdvancedWorkspace (each reader once per period, current + comparison) and AdvancedComparison
   (branch = a window the authorized request already holds; employee/service = the readers' own
   uuid filters). PeriodWindows derives every comparison request from the authorized one, so a
   comparison can never widen branch scope. No new SQL outside the owning modules.
3. AdvancedReports::run() accepts an optional comparison request (dates only). The comparison
   period follows DateRange semantics (month/quarter/year presets: same days of the previous one)
   or "same period last year"; the API default (equal preceding period) is unchanged.
4. AI insights reuse the Phase 14 context and allowance; a fixed instruction asks for a JSON object
   with five headings, which the analyst parses defensively and AnalysisAnswer allow-lists (unknown
   keys dropped, points trimmed, plain text only; a non-JSON reply becomes the summary).
   ReportAnalyst gains available(); an unavailable provider opens no run and the page shows one
   neutral "AI analysis is currently unavailable." state.
5. The chart library gains x-chart.multiline (trend overlay, 2–4 series) instead of hand-rolled SVG.

### Consequences
- Lazy sections paint skeletons on first load; later filter changes dim and re-render in place.
- Locally (array cache) nothing is cached; each section reads on its own request.
- Employee comparisons never show money (no employee-attributed revenue, docs/28 §4).
- ReportsSurfaceTest / ReportsUiContractTest / AdvancedReportRayanTest were updated for the new
  component boundaries (Advanced legs only).

### Amendment (independent review)
- Booking rates on the Advanced page use the Standard denominator (bookings with an
  outcome), so the same metric name never shows two values on the two pages and open
  bookings never read as a collapse ("today" at 10:00).
- `AdvancedWorkspace::build(..., array $focus)` adds an employee/service focus to the
  Insights view: the focus narrows through the readers' own uuid filters and limits the
  read to bookings, visits and queue (the facts those filters genuinely narrow). Money,
  customers, reviews and benefits are never attributed to one employee or service.
- Comparison values are matched by reader id (employee / service / booking line), never
  by display name.

---

## ADR-101: A translatable text reads in the request's language when the center publishes in it

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager (browser QA)

Context: `TranslatedText::get()` with no locale tried the center's DEFAULT language before the
request's. An English-speaking manager of an Arabic-first center that also publishes English saw
Arabic role, branch, service and tag names throughout the Manager — against docs/07 §5.1
("requested locale → tenant default → …"). The default-first order did protect one thing: a
translation left behind in a language the center switched off (disabling deletes nothing).

Decision: with no locale given, the application locale is the requested one ONLY when it is one
of the center's enabled content languages; otherwise the chain starts at the center default. An
explicit locale still wins. Outside a tenant nothing changes. Pinned by
`LocalizationTest` ("shows a name in the language being read …").

Reversal cost: low.


---

## ADR-102: No Livewire action is named after a reserved `$wire` alias

**Status:** Accepted · **Date:** 2026-09-24 · Phase 15 Manager (runtime bug, queue board)

Context: pressing Call on a queue ticket failed with `MethodNotFoundException:
Public method [<ticket uuid>] not found`. The board's action was named `call`
and the button said `wire:click="call('<uuid>')"`. In the browser Livewire
evaluates `wire:*` expressions against the `$wire` proxy, whose alias table
(`on, el, id, js, get, set, call, hook, commit, watch, entangle, dispatch,
dispatchTo, dispatchSelf, upload, uploadMultiple, removeUpload, cancelUpload`)
wins over component methods: `call` is `$call(method, …params)`, so the uuid
became the method name. Livewire's PHP test harness calls methods directly and
never passes through that proxy, which is why every feature test was green.

Decision: the action is `callTicket`. `tests/Architecture/LivewireActionNamesTest.php`
reads the alias table from the bundled Livewire and fails on any Livewire
component method, or any `wire:*` binding in a view, that uses one of those names.
`tests/Feature/Queue/QueueBoardActionBindingTest.php` reads the rendered Call and
Recall bindings back and invokes them exactly as written (method and argument),
and covers a malformed, an unknown and another center's uuid. The action's own
checks are unchanged: `QueueBoardQuery::find()` (permission, visit scope,
tenant, branch, own-only) and `CallTicket` (`queue.call` for the branch, the
locked row).

Amendment (wider audit, 2026-09-24) — three more ways a browser action went wrong,
each confirmed against Livewire 3.8.7 and fixed at the source:

- **A `wire:` event with no action.** 21 forms (12 Manager, 9 Super Admin filter
  bars and panels) carried a bare `wire:submit.prevent`. Pressing Enter made
  Livewire evaluate `$wire.` (a syntax error) and then freeze the form until the
  next request. They are `x-on:submit.prevent` now.
- **A confirmation Livewire never read.** Livewire asks `wire:confirm` only on
  the element that owns the action. On "Void sale" and "Close shift" it sat on
  the submit button inside a `wire:submit` form, so both ran without asking. It
  is on the form now, and theme.js arms the styled dialog on `submit` as well as
  `click` — Enter in a field never reaches the browser's native `confirm()`.
- **Escape and Tab behind a confirmation.** Every drawer/modal answered Escape on
  `window`, so one Escape closed a confirmation AND the layer beneath it, and a
  drawer's focus trap pulled Tab out of the dialog. Only the top visible layer
  answers Escape now (`window.msIsTopLayer`), and an open confirmation owns
  Escape and Tab.

`LivewireActionNamesTest` also fails on any `wire:` event without an action and
on any `wire:confirm` placed on a submit button.

Reversal cost: low.


---

## ADR-103: A guest's booking confirmation goes out on WhatsApp from the channel module

**Status:** Accepted · **Date:** 2026-09-25 · Phase 15 Manager (guest booking → WhatsApp)

Context: a customer with no `customer_accounts` row (a guest, ADR-041) has no
in-app inbox, so the `appointment_confirmed` notification never reaches them.
Notifications is in-app only and may never send on a channel (ADR-067), and
Booking must not learn about WhatsApp.

Decision:

- **Where.** `Conversations\Application\Listeners\ConfirmGuestBookingOnWhatsApp`
  listens to `Booking\Domain\Events\AppointmentConfirmed` and only schedules work
  with `Kernel\Database\AfterCommit`. `GuestBookingConfirmations` decides and sends
  through the existing seam: the purpose's approved template
  (`config/whatsapp.php` `templates.booking_confirmation`, env-driven, null by
  default) and `OutboundMessages` (persist first, send second, flood guard,
  metering, `ProviderSendFailed` → the existing staff alert). A registered
  customer keeps the in-app notice and gets no WhatsApp copy. A rolled-back
  confirmation sends nothing; a provider failure can never undo a booking.
- **Reading the booking.** Conversations reads it ONLY through
  `Booking\Contracts\BookingConfirmationFacts` (`AppointmentConfirmationFacts`)
  returning the readonly `BookingConfirmationData` / `BookingConfirmationLine`.
  Content comes from the record alone: customer name, center and branch name,
  branch-local date and start–end, every item's SNAPSHOT service name (+ variation
  and add-ons; the employee only when the customer chose that person), the public
  reference (ADR-068) and the branch phone. Never a uuid, an id, a price or the
  verification code. `ConversationsBoundaryTest` forbids Booking and Employees
  models in Conversations, and ratchets the `Customer` model to the three Phase 13
  sender-resolution files that already used it.
- **Once per booking, in the schema.** Tenant table `whatsapp_outbound_notices`
  (`unique(purpose, source_type, source_uuid)`, `insertOrIgnore` claim): every guest
  confirmation gets exactly one row — `sent`, `failed`, `unknown`, `pending`, or
  `skipped` with its reason (`channel_inactive`, `disabled`, `not_connected`,
  `account_off`, `provider_unavailable`, `no_template`, `template_language`,
  `opted_out`, `no_phone`, `no_reference`). Skips are recorded even for a center
  without `whatsapp_booking`, so gaining the channel or switching the setting on
  never replays old bookings. No phone and no rendered text on the row. Only
  `failed` is retried, by a conditional UPDATE (at most three attempts); `unknown`
  and a stale `pending` never are (ADR-070's rule: Meta takes no idempotency key).
- **Recovery.** `GuestConfirmationReconciler` (`metastyle:reconcile`, hourly) decides
  confirmed guest bookings from the last 24 hours that are still ahead and have no
  row, and retries `failed` ones while the center owns the channel and the booking
  is still confirmed and ahead. A confirmation is never sent a day late.
- **Consent and language.** `customers.allow_operational_messages` is honoured
  (`opted_out`). `config/whatsapp.php` `template_languages` maps an app locale to a
  Meta template language (en → en, ar → ar; `ckb` unmapped until Meta support is
  verified). The customer's preferred language when enabled and mapped, else the
  center primary, else any enabled mapped one; none → `skipped` /
  `template_language`. The text renders in that app locale; the mapped code is sent.
- **The switch.** `ConfigureWhatsAppNotifications` stores the center's ON/OFF
  (absent = ON, the Notifications rule), audited. The Manager card shows the real
  state, the fallback language and each enabled language as sendable or not; it is
  read-only while the center lacks `whatsapp_booking`, which only Super Admin grants
  (ADR-098).

Consequences: one small row per confirmed guest booking in every center. The send
runs in-process right after the commit (there is no tenant-aware queue worker
yet), so a staff member's "Confirm" waits up to the adapter's timeout when Meta is
slow; move it to a queued job when a worker exists. Template names are
platform-wide while Meta approval is per WABA, so every center must approve a
template with the same name and parameters. Reschedule and cancellation notices
for guests are not built. Like the rest of the channel, the send has not run
against live credentials (ADR-073).

Reversal cost: low (additive table; the listener can be unregistered).


---

## ADR-104: A queue display plays the center's own media and rotates its languages

**Status:** Accepted · **Date:** 2026-09-25 · Phase 15 Manager (queue display)

Context: centers want their waiting-room television to play their own images and
videos beside the queue, and to cycle its labels through the center's languages.
The display already exists (public page `/q/{key}`, polled allow-listed feed,
ADR-052). No signage product, no markup, iframes or SVG from a center (ADR-038),
the existing media kernel (ADR-083); the call must stay dominant and a TV stays on
all day.

Decision:

1. **Per-screen media on the existing kernel.** `MediaOwner::QueueDisplay` (owner
   id = the screen's row; 12 per screen) in the public `branding` collection, so
   the existing center media route serves it. Tenant table `queue_display_media`
   (sort order, enabled, translatable caption; cascade from `media_items`, restrict
   from `queue_displays`) says how the screen plays each file; alt text stays on
   `media_items.alt_text`. Per screen because screens are branch-scoped: a
   branch-limited manager must not change another branch's television. Videos
   need byte ranges (206) before Safari/WebKit play them: `MediaStore::response()`
   answers with a file response on a local disk and streams on any other driver —
   the driver is decided there and nowhere else (docs/09 §10).
2. **One Action**, `ManageDisplayMedia` (configure, add, update, move, step,
   remove): `queue_management` + `queue_display`, `queue.display.manage`, branch
   scope, the kernel's `media.upload`. Moves are single intents renumbered under
   the screen's row lock. No new entitlement.
3. **Language rotation is display configuration** (`rotation_enabled`,
   `rotation_locales`, `rotation_seconds` 5–60, default 10), validated by
   `SaveDisplay` against the center's enabled languages. `DisplayLanguages::cycle()`
   is the one definition of what a screen rotates through — the stored choice
   narrowed to the center's enabled languages, in center order — so switching a
   center language off drops it from every screen without deleting the screen's
   choice, and switching it on again restores it. The Manager form shows the
   rotation controls only with more than one language, and a hidden interval is
   neither validated nor saved.
4. **A versioned public presentation.** `DisplayPresentation` is an allow-list
   (labels per language from `queue_public.php`, direction, branch name, playlist
   as kind/url/mime/caption/alt; no uuids, paths or sizes). The page embeds it; the
   feed carries `presentation_version` and adds the full presentation only when the
   page's `pv` digest is stale, so a playlist change reaches the TV within one poll
   with no reload.
5. **No internal identifier on the wire.** `call_key` (for every screen) and every
   `announcement_id` (each line's and the speech payload's) are the same keyed
   digest (`Kernel\Privacy\Fingerprint`) of the current call event, scoped to the
   screen — never the event's uuid. The client acts once per key: speaks when an
   `announcement` is present (voice-gated by `DisplayFeed::mayAnnounce()`),
   otherwise chimes when sound is on. A language switch re-labels the last payload
   and never announces.
6. **A code-owned client**, `resources/js/queue-display/display-client.js` (ES5,
   inlined; the pure core tested with `node --test`). Videos are always muted; the
   media is its own grid cell, dimmed and held still for 12 s after a new call; the
   layout stays put while text direction flips per language. The poll awaits one
   request at a time and abandons one with no answer after 20 s, so a stalled
   connection never freezes a screen.
7. **Preview through the real page.** `center.queue.displays.preview(.feed)`
   (auth:web, never `public.tenant`) renders the same template and feed for a screen
   manager, never speaking or chiming (`announcement` and `call_key` null), with
   `lang=` pinning any center-enabled language and `sample=1` a labelled sample
   call. The frame is laid out at 1600×900 (or 900×1600) and scaled.

Consequences: four expand-only tenant migrations
(`2026_09_24_2301`/`2302`/`2303`, and ADR-103's `2901`) are applied deliberately
with `metastyle:tenant:migrate --all` before the code ships — never by a watcher,
and the code never tolerates a missing table. Videos are capped at 12 MB
(`site.media.video_max_kb`, Livewire's temporary-upload ceiling) and never play
sound. Media is not shared between screens. A screen whose language the center
switched off starts in the center default. Televisions should be reloaded once
after a deploy that changes the client, since it is inlined in the page. Streamed
media on a non-local disk (S3) is served without byte ranges.

Reversal cost: low–medium (additive schema; dropping the feature means ignoring the
columns and the table).


---

## ADR-105: A browser upload inside a center needs its resolver first and its framework cache directory

**Status:** Accepted · **Date:** 2026-09-25 · Phase 15 Manager (browser QA of queue display media)

Context: the first real browser upload in a center (a promotional image for a
queue screen, on DrBany) failed twice, in two different ways, while every PHP
test was green. `Livewire::test()` never calls the upload endpoint, and the
existing endpoint test posted anonymously.

1. `tempnam(): file created in the system's temporary directory` — a 500 from
   `/livewire/update`. Livewire's uploads use a real-time facade
   (`Facades\Livewire\…\GenerateSignedUploadUrl`); Laravel writes its stub to
   `storage_path('framework/cache')` on first use. Inside a center that path is
   `storage/tenants/{key}/…` (the filesystem bootstrapper suffixes it and creates
   nothing), so a center that had never stored a file had no such directory.
2. `TENANT.NOT_INITIALIZED` from `/livewire/upload-file`. That route adds
   `throttle:60,1`, which keys on `$request->user()` — the staff user, a
   tenant-database row. `ResolveLivewireTenant` was appended to the `web` group
   but absent from the priority list, so Laravel sorted it after the throttle:
   the user was looked up with no tenant bound. It fails closed, and every
   signed-in manager's upload failed.

Decision:

- `App\Kernel\Tenancy\Infrastructure\TenantStorageBootstrapper`, registered right
  after `FilesystemTenancyBootstrapper`, makes sure `storage_path('framework/cache')`
  exists on every tenancy bootstrap (a race-safe `mkdir`, and an exception if the
  directory still does not exist). Per bootstrap rather than at provisioning, so
  existing centers and new application servers are covered too.
- `ResolveLivewireTenant` joins the priority list with the other resolvers
  (before `SetLocale`, so before authentication and the throttle).
  `MiddlewareOrderGuard` now fails the boot if it is missing from the list or
  sorted after authentication or a throttle — the third silent ordering bug of
  the ADR-027 / ADR-045 family.

Tests: `TenantStorageAndCacheIsolationTest` generates a never-loaded real-time
facade inside a center whose storage root was emptied (reproduces the `tempnam`
error without the bootstrapper); `MiddlewareOrderTest` checks the resolved
pipeline of `livewire.upload-file` and the two new guard refusals;
`CenterMediaServingTest` uploads through the signed URL as a signed-in manager
from a real session.

Consequences: one `is_dir()` per center request; a `framework/cache` directory
per center under its storage root. Uploads by signed-in center users work in a
browser for the first time — every earlier upload check was a PHP test.

Reversal cost: low.


---

## ADR-106: A new center's database is named after its slug, then never renamed

**Status:** Accepted · **Date:** 2026-09-25 · Amends ADR-024

Context: ADR-024 named every tenant database `tenant_` + the zero-padded internal
sequence (`tenant_000003`). It is safe, but an operator reading `SHOW DATABASES`,
a backup file name or a slow-query log cannot tell which center a database
belongs to. The product owner asked for names that read as the center while
keeping ADR-024's guarantees: no raw input in an identifier, no collision, no
rename when the center changes.

ADR-024 rejected a "slugified center name" for three reasons: an injection
surface needing perfect sanitisation, collisions after slugging, and a rename
implied by a name change. Each is answered below rather than accepted as a risk.

Decision:

- **Format for a NEW center:** `{prefix}{label}_{sequence}`, e.g. `tenant_drbany_000003`,
  `tenant_barbershop_alpha_000004`, `tenant_qa_test_center_000005`.
  `TenantDatabaseName::generate(int $sequence, ?string $slug)` is the only
  producer.
- **Label, from the slug and never the display name.** Lowercase ASCII, and
  every run of anything outside `[a-z0-9]` (hyphens, spaces, quotes,
  dots, slashes, backslashes, wildcards, whitespace, non-ASCII bytes) becomes
  one underscore; leading and trailing underscores are removed. It works on
  bytes, so no identifier depends on how the server treats Unicode. A slug
  with nothing ASCII left becomes `center`. The slug is the center's own: the
  one its registration carries (requested, or derived by registration, which
  then becomes the center's address). `metastyle:tenant:provision` takes
  `--slug`, accepted only as typed in canonical form and valid for a center.
  **With no slug the label is `center`.** The display name never contributes,
  not even transliterated.
- **Length:** the label is cut to at most **24 characters** (`MAX_SLUG_LENGTH`),
  and further only when a long configured prefix (the test suite's, up to 33
  characters) needs the room: `min(24, 64 − prefix − 1 − suffix)`. A cut never
  leaves a trailing underscore. The whole name is at most **64 characters**, the
  MySQL/MariaDB identifier limit. The suffix is **never** cut.
- **Uniqueness comes from the suffix only:** the `AUTO_INCREMENT` `tenants.sequence`,
  zero-padded to six digits, widening to twelve; beyond twelve the generator
  refuses. Two centers whose slugs reduce to the same label (`dr-bany`,
  `Dr Bany`, two long slugs sharing their first 24 characters) still get two
  names. The existing sequence is the suffix; no random component is added.
  `tenants.tenancy_db_name` keeps its UNIQUE index.
- **Generated once, stored, never re-derived.** `TenantProvisioningService`
  assigns `tenants.tenancy_db_name` once, in the transaction that creates the
  control-plane row. Every later resolution, connection, migration and DDL path
  reads the stored value. A provisioning retry (`TenantProvisioningService::retry`)
  reuses it. Renaming a center
  (`UpdateOwnCenterProfile`) or moving it to another address
  (`UpdateCenterProfile::changeAddress`) changes neither the stored name nor
  the database.
- **Validation accepts both shapes.** `isValid()` accepts
  `{prefix}{label}_{6–12 digits}` with a label of `[a-z0-9]` runs joined by
  single underscores, at most 24 characters, total at most 64. It also accepts
  the pre-ADR-106 `{prefix}{6–12 digits}`, so existing databases keep working
  unchanged. `$` is anchored to the true end (the `D` modifier), so a name or
  prefix with a trailing newline is refused. Every path that creates,
  migrates or reports on a tenant database calls `assertValid()` first.
  Runtime connection switching binds the stored name through the existing
  tenancy bootstrapper **without** re-validating it, as it did before this
  decision. The protection there is that the stored name has exactly one
  writer, provisioning, enforced by an architecture test. Validating at
  bootstrap too would be defence in depth, but it changes the resolver's
  behaviour and is left for a separate decision. The prefix is still validated.
  Database creation and connection switching are unchanged: the same
  `MySQLDatabaseManager`, the same tenancy bootstrapper, the same fail-closed
  guard.

Enforced by:

- `tests/Feature/TenantDatabaseNameTest.php`: the format, normalisation of hostile
  and Unicode slugs, length bounds under the production and the longest test
  prefix, collisions, and old and new shapes in validation;
- `tests/TenantIsolation/TenantDatabaseNamingTest.php`:
  - registration, Unicode names, the console command and hostile names;
  - rename and change of address leaving the database, and its resolution, in place;
  - similar slugs in separate databases with separate data;
  - no operational table in the control plane;
- `tests/Architecture/TenantDatabaseNamingTest.php`: exactly one writer of
  `tenancy_db_name` and one caller of `generate()` in `app/`; nothing assembles a
  `tenant_` name by hand.

Existing databases: not renamed. `tenant_000001` … `tenant_000004` (the four
local centers) keep their names and stay valid. Renaming a live tenant database
is a separate, reviewed operation proposed to the product owner and not
performed without approval.

Known, pre-existing, not changed here: when a registration's provisioning
fails after the tenant row is committed but before the registration records
it (`ProvisionRegisteredTenant::tenantFor`), a retry provisions a second tenant
and database. The first one is left behind. Under ADR-106 both carry the same
label. The fix, recording the tenant id before the pipeline can fail, belongs
to onboarding and is proposed separately.

Consequences: a database name now reveals the center's slug to whoever can
list databases or read backups. That is the purpose; slugs are already public in
center hosts. The sequence still leaks signup volume, as under ADR-024. A center
whose slug later changes keeps a database named after its first slug; that is
the price of never renaming a live database.

Reversal cost: low for new centers (the generator is one method); renaming the
databases that exist is the expensive part, and is exactly what is not done.
