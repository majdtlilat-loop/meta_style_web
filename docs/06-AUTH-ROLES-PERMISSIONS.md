# 06 — Authentication, Roles & Permissions

> Status: **Implemented (Phase 3)** for staff authentication and RBAC.
> Platform and customer authentication are not built — see §1.

## 1. Three audiences, three identities

| Audience | Lives in | Guard | Token | Example |
|---|---|---|---|---|
| **Staff User** | tenant DB `users` | `web` (session) / `sanctum` (API) | Tenant-bound token | Owner, manager, host, cashier, employee | **Built (Phase 3)** |
| **Platform User** | control plane `platform_users` | — | — | Super Admin, support agent | Not built |
| **Customer** | tenant DB `customers` | — | — | A person booking a haircut | Phase 5 |

Only the staff audience exists today. Phase 3 has no SADMIN surface and no
customers, and standing up authentication systems that nothing authenticates
against is how a codebase acquires guards nobody understands. `config/auth.php`
declares one provider and two guards, and says so.

These are genuinely different principals with different tables, different
password policies, and different permission systems. They are **not** one
`users` table with a `type` column.

A `Principal` value object in `Kernel/Identity` normalises them for audit and
authorization:

```php
final readonly class Principal
{
    public PrincipalType $type;   // platform | staff | customer | guest | system | ai
    public ?int $id;
    public ?int $tenantId;
    public ?int $impersonatorId;  // set during support impersonation
    public string $source;        // web | api | pos | whatsapp | ai | job | system
}
```

Every audited action and every `Actor` passed into a domain engine carries a
`Principal`. This is what makes a booking made by RAYAN, by a host, and by a
customer equally attributable.

## 2. Authentication flows

### 2.1 Staff on Meta Style Web  *(built)*

The web session has no tenant of its own, so sign-in takes the center's
**public key** alongside the credentials. The key is opaque, revocable, resolved
server-side, and authorises nothing by itself.

On success the key is written to the **session**, which becomes a trusted
resolution source for every later request (ADR-030). A center with its own
hostname resolves by host instead and never sees the field.

The internal tenant id and sequence are never accepted here: neither is
revocable, and the sequence leaks how many centers exist.

### 2.2 Staff on the API  *(built)*

`POST /api/v1/public/auth/token` takes `center_key`, `identifier`, `password`
and returns a tenant-bound token:

```
ctr_a1b2c3...|17|k9Xs...
└ center key ┘└ Sanctum ┘
```

The token row is written to **that center's database**, which is the binding
that actually matters — presenting it elsewhere finds nothing to authenticate
against. The prefix exists so a host/token mismatch can be *detected* and
audited rather than surfacing as a bare 401 (ADR-027).

Unknown center key, unknown user, wrong password and never-activated account all
return the same answer, in the same shape: anything more specific turns the
endpoint into a way to discover who works where.

### 2.2.1 The login directory  *(not built)*

The control-plane directory described below would let the shared mobile app
resolve a center from an identifier alone, with no center key. It is deferred:
"remember the center key" is a smaller problem than a hashed global index of
every staff identifier on the platform, and nothing needs it yet. Build it when
a concrete UX requirement appears.

### 2.3 Customers

- **Guest** — no authentication. Identified per booking by a signed, expiring
  link. Guest bookings create a `Customer` record with `is_guest = true` and no
  credentials.
- **Registered** — phone (primary in Iraq) or email, with OTP or password.
  A guest record is upgraded in place when the same verified phone registers,
  preserving booking history.

Customer identity is **per tenant**. The same human at two centers is two
records. Cross-tenant customer identity is explicitly out of scope (`00` §9).

### 2.4 Platform users

Control-plane only. Mandatory 2FA. IP allow-listing available. Never able to
authenticate into a tenant guard directly — access to tenant data is only via
**impersonation** (§8).

### 2.5 Middleware ordering  *(enforced at boot)*

Both surfaces load the credential from the **tenant** database — Sanctum tokens
on the API, the staff account on the web. So the pipeline has exactly one valid
order, and it is not negotiable:

```
resolve tenant  →  initialise tenant  →  look up credential  →  authorize
```

Listing `['tenant', 'auth:sanctum']` in the right order on the route is **not
enough**. Laravel sorts middleware by `$middlewarePriority`, and priority wins
over the route array. `bootstrap/app.php` therefore inserts `ResolveTenant`
ahead of authentication:

```php
$middleware->prependToPriorityList(
    before: AuthenticatesRequests::class,   // the INTERFACE, not Authenticate::class
    prepend: ResolveTenant::class,
);
```

**The anchor is the interface on purpose.** The priority list contains
`Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests`, not the concrete
`Authenticate` class. Anchoring on the concrete class matches nothing, and
`prependToPriorityList` then silently appends to the *end* of the list — which is
exactly what happened during Phase 3. The routes looked right, the source looked
right, and the ordering was wrong.

It did not present as a broken page, which is why it survived review: Sanctum
looked for the token, found no tenant connection, and failed closed on
`TenantConnectionGuard`. Correct by accident of a second defence rather than by
design, and any future middleware that reads tenant data during authentication
would have had no such backstop.

`MiddlewareOrderGuard` therefore asserts the **resolved** priority list at boot
and **throws** if the order is wrong, if `ResolveTenant` is absent from the list,
or if the anchor interface has disappeared from it. A misordered pipeline is not
a degraded mode, so this is the one readiness condition that refuses to start
rather than logging (contrast `12` §11).

Source inspection cannot catch this class of bug, because the source looked
correct. `tests/TenantIsolation/MiddlewareOrderTest.php` observes the running
pipeline instead: which connection the credential lookup ran on, which tenant
was bound at that moment, that a route without `tenant` cannot authenticate at
all, that a host/token or host/session conflict is refused *before* any
authentication attempt, and that the context is cleared afterwards — including
when the request failed inside the pipeline.

## 3. Permission catalog lives in code

`App\Kernel\Authorization\Permission` is a backed enum. Codes are stable
forever — a stored grant references the string, so renaming one silently revokes
access in every tenant database.

Phase 3 defines only what it can enforce:

```
staff.view            role.view                 branch.view
staff.create          role.create               settings.view
staff.update          role.update               audit.view
staff.deactivate      role.delete
staff.access.manage   role.permissions.manage
```

Later phases added `appointment.*`, `journey.*`, `queue.*` and — in Phase 9 —
`sale.*`, `invoice.print`, `cashier_shift.*` and `product.manage` (catalog 71,
docs/18-SALES.md §35). `finance.*`, `payment.*` and `reports.*` arrive with the
features that check them. A catalog full of unenforceable codes is a list of
promises — and it makes the Owner role look complete when it is not.

Naming: `{area}.{resource}.{action}` or `{area}.{action}`, lowercase, dotted.

**Why code and not seeded rows:** if permissions were rows in each tenant
database, adding one would be a data migration across every database, and any
tenant that missed it would silently deny access. In code the catalog is
identical everywhere by construction; only the **assignments** are per-tenant
data.

## 4. Roles

```
roles                 id, uuid, key, name(json), is_system, is_default,
                      description(json)
role_permissions      role_id, permission_key        ← catalog key, code-defined
user_roles            user_id, role_id
user_branches         user_id, branch_id             ← branch scoping
users                 ..., all_branches (bool), is_owner (bool)
```

System roles seeded at provisioning (`is_system = true`, not deletable, but
their permission sets are editable by the owner):

| Role | Intent |
|---|---|
| `owner` | Full control including billing and role management. |
| `manager` | Operations, staff, reports. No billing. |
| `host` | Reception: bookings, queue, check-in, customer basics. |
| `cashier` | POS, invoices, shift close. No revenue reports. |
| `employee` | Own schedule, own stages, own customers' service notes. |

Tenants may create custom roles. **Nobody may grant a permission they do not
themselves hold** — enforced in `UpdateRolePermissions` and `AssignRolesToUser`,
because without it anyone able to edit roles could write every permission into a
role and assign it to themselves in two steps.

**Owner is not a bypass.** The Owner role holds an explicit stored grant for
every permission in the catalog. `is_owner` on the user is *protection* — the
account cannot be deactivated or have its roles changed by others — and
authorises nothing.

A `Gate::before` shortcut would be one line and would never go stale, but it
cannot be audited, cannot be narrowed by a center that wants a restricted owner,
and would silently swallow every future security boundary the moment it is added
(ADR-029).

Owner does **not** bypass entitlement checks either: an owner on a plan without
`pos` still has no POS.

Custom roles are unlimited in Phase 3; the `custom_roles` limit entitlement
arrives when limit-type entitlements do.

### 4.1 Keeping system roles current  *(deploy step)*

Explicit grants have one consequence that is easy to miss: they are rows, written
once at provisioning. A release that adds a permission to the catalog leaves
every center provisioned before it with an Owner who does not hold it — no
error, no log, just a feature that does nothing for the person paying for it.

```bash
php artisan metastyle:roles:sync --all          # deploy step, after tenant migrations
php artisan metastyle:roles:sync --tenant=<id>  # one center
php artisan metastyle:roles:sync --all --dry-run
```

What it will and will not do:

| | |
|---|---|
| Creates missing system roles | Yes — a role added to `SystemRole` reaches existing tenants |
| Adds new catalog permissions to **Owner** | Yes — Owner means "everything" by definition |
| Widens other system roles | **No** — a center that narrowed Manager meant it |
| Touches custom roles | **No** — only `is_system` roles are considered |
| Touches who holds which role | **No** — assignments are the center's business |
| Creates duplicate roles or grants | **No** — idempotent; repeat runs change nothing |

Operationally it behaves like the tenant migrator: one independent attempt per
tenant inside its own context, failures returned rather than thrown so the run
continues, the context restored by a `finally` even when a tenant dies mid-sync,
unprovisioned tenants skipped rather than failed, and a non-zero exit if anything
failed. Audit entries are written only when something changed or a tenant failed
(ADR-032).

## 5. Authorization = permission ∩ branch scope

Both gates must pass:

```php
$user->hasPermission('booking.appointment.cancel')
  && $user->canAccessBranch($appointment->branch_id)
```

Branch scope:

- `all_branches = true` → every branch of the tenant.
- otherwise → the branches in `user_branches`.

Every policy that touches a branch-owned record checks scope. A missing scope
check is the second most likely data-leak bug in this system, after tenant
resolution — inside a tenant, but still a real breach between a center's
locations.

Phase 3 expresses scope as a `BranchScope` value object — either unrestricted or
an explicit list — which Actions consult directly and queries apply with
`applyTo()`. Filtering happens **in the query**, not after: a manager scoped to
one branch must not receive another branch's rows at all, not merely have them
hidden.

The base policy class that makes forgetting the check a type error arrives with
the first branch-owned business records in Phase 4.

## 6. Field-level permissions and masking

Some permissions gate **fields**, not endpoints:

| Permission | Without it |
|---|---|
| `appointment.view_own` | Only appointments the viewer's linked employee is assigned to. Resolved through `AppointmentScope`, never a role name (Phase 7 §30) |
| `queue.view` | The queue board and its feeds are unreachable |
| `queue.call` | Cannot call, recall or skip — the host's core gesture |
| `queue.manage` | Cannot issue, hold, resume, transfer, cancel or reprioritise |
| `queue.display.manage` | Cannot configure destinations or screens (a manager's job) |
| `queue.ticket.print` | No printable ticket |
| `journey.walk_in.create` | Cannot start a visit nobody booked |
| `sale.view` | Sales and their invoices are unreachable — there is no separate `invoice.view` |
| `sale.create` | Cannot build a cart, open a visit checkout, set the customer or discard a draft |
| `sale.finalize` | Cannot publish an invoice; the customer share link is not shown |
| `sale.adjust` | No discounts, surcharges, price overrides or custom lines (a manager's call) |
| `sale.void` | Cannot void a published sale |
| `invoice.print` | No 80mm or A4 print — also needs the `printing` entitlement |
| `cashier_shift.manage` / `.supervise` | Cannot open/close your own till / anybody's |
| `product.manage` | Cannot edit the product catalog |
| `journey.note.view` | Operational stage notes not serialised at all |
| `appointment.note.view` | Staff booking notes not serialised at all |
| `appointment.note.manage` | Manager-only booking notes filtered out |
| `customer.contact.view` | `+9647501234567` → `+964 ••••••••67`, `s•••@•••.com` |
| `customer.note.view` | Internal notes not serialised at all |
| `customer.note.manage` | Manager-only notes filtered out of the list |
| `finance.revenue.view` | Revenue columns omitted entirely *(Phase 10)* |

**Implemented in Phase 5** as `Kernel\Privacy\ContactMasker` plus
`Modules\Customers\Application\CustomerPresenter`. The presenter is what the
API controller AND the Livewire screens both call — one implementation, so the
rule cannot drift between surfaces. An architecture test fails the build if a
Blade template reaches for `->phone` or `->email` outside three documented
exemptions (the customer's own account page, and branch contact details, which
are business information rather than personal data).

The codes above are the ones that exist. Earlier drafts of this section sketched
`customer.phone.view_full` and `customer.note.view_internal`; the shipped
catalog follows the singular `{area}.{resource}.{action}` convention used
everywhere else.

Rules:

1. Masking happens in **API Resources**, in one shared `MaskedAttribute` helper.
2. The **same policy applies to exports and reports.** An export that bypasses
   masking is the classic way this control is defeated — export builders call
   the same helper.
3. Omit rather than null where possible: returning `"revenue": null` tells the
   client the field exists and is hidden, which is fine; returning a masked
   value that looks real is not.
4. Masked fields are never used as query filters (searching by full phone while
   only seeing a masked one is an oracle). **Enforced:** `CustomerQuery` matches
   a phone or email only for a viewer holding `customer.contact.view`.
5. A field that is never masked must never be given a masked field's value. A
   self-registration that defaulted a customer's NAME to their phone number put
   the number in front of every viewer the masking was meant to stop — so
   registration requires a name (ADR-042).

## 7. Tokens

- Laravel Sanctum personal access tokens, stored **in the tenant database**.
- Tokens are tenant-bound (§2.2, ADR-027); a token presented against a
  different resolved tenant is rejected, and a host/token conflict is audited.
- A token stops working the moment its owner is deactivated — checked on every
  request, not only at issue.
- Token abilities mirroring permission keys is deferred: abilities that are
  never narrowed are decoration, and Phase 3 issues one token type.
- Expiry: staff 30 days sliding, customer 90 days, platform 12 hours.
- Revocation: on password change, on role change, on staff deactivation, and on
  demand from SADMIN.

## 8. Impersonation (support access)

Support cannot browse tenant data casually. Impersonation is explicit and
expensive by design.

Requirements, all mandatory:

1. A **reason** and, where applicable, a **support ticket reference**.
2. **Time-boxed** session (default 60 minutes, hard maximum 4 hours).
3. A persistent, unmissable banner in the UI for the whole session.
4. Recorded in `impersonation_sessions` (control plane) with real platform
   user, target tenant, target user, reason, ticket, IP, correlation id.
5. **Every action dual-audited** — written to the tenant's `audit_logs` (so the
   center can see what support did in their account) *and* to
   `platform_audit_logs`, linked by correlation id.
6. Actions carry `Principal{type: staff, id: target, impersonatorId: platform}`
   so the tenant's own audit view shows "Manager Ali (via Meta Style Support)".
7. Configurable prohibitions — by default an impersonating session may not
   export customer PII, change billing, or delete data.
8. The tenant owner is notified that a support session occurred.

Impersonation is a privileged capability held by a subset of platform users,
not by all of them.

## 9. Implementation decision — build, don't adopt

Custom minimal RBAC in `Kernel/Authorization` rather than `spatie/laravel-permission`.
Full rationale in `DECISIONS.md` ADR-007. Summary:

- The permission **catalog is code-defined**, which removes most of what a
  permission package provides (models, sync commands, seeding).
- The package's permission cache uses a single global cache key. In a
  database-per-tenant system that must be namespaced per tenant, and getting it
  wrong means **one tenant's permissions applied to another** — the exact class
  of bug we cannot risk.
- We need branch scoping and field-level masking regardless, which the package
  does not provide.
- What remains is roughly 150 lines: a `HasRoles` trait, a permission resolver
  with a tenant-scoped cache key, a base policy, and a Gate registrar.

Cost of being wrong: adopting the package later is a data migration of two
pivot tables. Cheap. Recorded as reversible.

## 10. Password and account policy

| Control | Value |
|---|---|
| Hashing | bcrypt (Laravel default), cost tuned per environment |
| Minimum length | 10 for staff, 8 for customers, 14 for platform users |
| Breach check | Laravel `Password::uncompromised()` for staff and platform |
| Throttling | Per identifier + per IP, exponential backoff |
| Lockout | 10 failures → 15 minute lock, audited |
| 2FA | Mandatory for platform users; available for staff; opt-in for owners initially, required from Phase 10 for `finance.*` holders |
| Password reset | Signed, single-use, 60-minute tokens; invalidates all sessions |
| Deactivation | Soft — user retained for audit attribution, all tokens revoked, directory entry disabled |

Staff users are **never hard-deleted**. Historical bookings, invoices and audit
entries must keep resolving to a name.

## 11. Anti-patterns

| Anti-pattern | Why |
|---|---|
| `if ($user->role === 'manager')` | The role set is customisable; check permissions. |
| Permissions seeded as rows per tenant | Guarantees drift; a missed tenant silently denies access. |
| One `users` table for staff, customers and platform users | Three different security models in one place. |
| Checking permission but not branch scope | Cross-branch leak inside a tenant. |
| Masking in the UI only | Defeated by the API, exports, and reports. |
| Trusting a tenant id inside a token claim | Client-controllable; bind server-side. |
| Support browsing tenant data without impersonation records | Unauditable, and legally indefensible. |
| Hard-deleting staff | Orphans historical attribution. |
| Owner bypass that also skips entitlement checks | Sells nothing; owners get unpaid features. |
