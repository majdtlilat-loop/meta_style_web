# 00 — Product Overview

> Status: **Phase 0 (design only)**. Nothing in this document is implemented.

## 1. What Meta Style is

Meta Style is a **multi-tenant SaaS operating platform** for appointment- and
service-based personal-care businesses:

barbershops · hair salons · beauty centers · laser centers · spas · hammams ·
grooming centers · and similar service businesses.

A single Meta Style deployment serves many independent businesses. Each business
("center") gets an isolated operational database, its own branding, its own
staff, its own customers, and a feature set determined by what it pays for.

Meta Style is **not** a booking widget. It is the system a center runs its day
on: front desk, queue, service delivery, checkout, money, customers, reporting.

## 2. Vocabulary (canonical — use these words in code)

| Term | Meaning |
|---|---|
| **Platform** | Meta Style itself: the vendor, the control plane, the SADMIN app. |
| **Tenant** | One paying business account. Owns exactly one operational database. |
| **Center** | The customer-facing word for a Tenant. In code, always say `Tenant`. |
| **Branch** | A physical location belonging to a Tenant. A Tenant has 1..N branches. |
| **Staff / User** | A person who works for a Tenant (owner, manager, host, cashier, employee). Lives in the tenant database. |
| **Platform User** | A Meta Style employee (super admin, support, billing). Lives in the control plane. |
| **Customer** | An end consumer of a Center. Tenant-scoped. May be a guest (no account). |
| **Service** | A sellable unit of work (haircut, laser session, hammam). |
| **Appointment** | A scheduled intent to receive one or more services at a time. |
| **Visit** | The realised execution of an appointment or a walk-in. What actually happened. |
| **Service Journey** | The ordered set of stages a Visit passes through. |
| **Stage** | One step of a Service Journey (a service, by an employee, in a room, with a status). |
| **Ticket** | A queue position. May or may not be linked to an Appointment. |
| **Destination** | Generic name for where a ticket is sent (Laser Room 2, Chair 4, Cashier, Gate A). |
| **Resource** | A bookable non-human asset: room, chair, device, equipment. |
| **Sale / Order** | A commercial transaction at POS. Produces an Invoice. |
| **Entitlement** | A named capability a tenant may or may not have (`pos`, `queue_voice`). |
| **Plan** | A named bundle of entitlements sold as a subscription. |
| **Add-on** | An entitlement sold on top of a Plan. |
| **Override** | A per-tenant entitlement grant or revocation set by Super Admin. |
| **Control Plane** | The platform database and the code that manages tenants. |
| **Tenant Plane** | A tenant's own database and the code that runs a tenant's business. |
| **White Label** | A branded customer mobile app for one tenant, on the shared backend. |
| **RAYAN** | The Meta Style AI assistant. |

**Naming rule:** the product says "center", the code says `Tenant`. Do not
introduce a `Center` class, table, or namespace.

## 3. Product surfaces

| # | Surface | Audience | Auth | Tenant resolved by |
|---|---|---|---|---|
| 1 | **Meta Style Web** | Owner, Manager, Host/Reception, Cashier, Employee, POS | Staff session / token | Subdomain or custom domain |
| 2 | **Meta Style App** (Flutter) | Staff of any center | Staff token | Login directory lookup, then center picker |
| 3 | **Meta Style SADMIN** | Platform super admins and support | Platform token | N/A — control plane only |
| 4 | **White Label Customer App** (Flutter) | Customers of one center | Customer token or guest | Compiled-in tenant key |
| 5 | **Customer Web Experience** | Customers, no install required | Guest or customer token | Public domain / menu slug |

All five surfaces are served by **one Laravel application** and **one API**.
A branded white-label app never gets its own backend.

## 4. Customer accounts are optional

Guest booking must always work. An account is an upsell, never a gate.

Creating an account unlocks: booking history, loyalty, discounts, memberships,
packages, remaining sessions, favourite employees, saved preferences, faster
rebooking, personalised offers.

**Design consequence:** every customer-facing flow must have a guest path.
A `Customer` record may exist without credentials. Identity is not authentication.

## 5. Business model

Meta Style earns from tenant subscriptions. It does **not** take a cut of a
center's customer payments — customer money settles directly into the center's
own merchant account.

This means two entirely separate financial domains, which must never share
tables, ledgers, or reports:

- **SaaS Billing** — Center pays Meta Style. Lives in the **control plane**.
- **Center Finance** — Customer pays Center. Lives in the **tenant plane**.

Monetisation is by **entitlement**, not by plan name. Plans are marketing
packaging over a capability matrix (see `05-ENTITLEMENTS.md`).

## 6. Subscription lifecycle

```
        self-registration
               |
               v
          [ trialing ] ---- trial ends, no payment ----> [ expired ]
               |
          payment ok
               |
               v
          [ active ] <-------- payment recovered --------+
               |                                          |
        payment fails                                     |
               |                                          |
               v                                          |
        [ past_due ] ---- grace elapsed ----> [ suspended ]
               |
             cancel
               |
               v
         [ cancelled ]
```

Access level is derived from subscription status and is **separate from**
entitlements:

| Status | Access |
|---|---|
| `trialing` | Full access to the trial entitlement set. |
| `active` | Full access to plan + add-ons + overrides. |
| `past_due` | Full access, persistent billing warning. |
| `suspended` | Read-only. Billing screens and data export only. |
| `cancelled` | Login + export only, for a defined retention window. |
| `expired` | Same as cancelled. |

## 7. Functional domains (future scope — not built in Phase 0)

**Core operations:** Branches · Employees · Customers · Service Catalog ·
Electronic Menu · Booking Engine · Service Journey · Queue Management.

**Commerce:** POS · Invoices · Payments · Finance · Packages · Memberships ·
Gift Cards · Loyalty.

**Engagement:** CRM · Reviews · Notifications · WhatsApp · Marketing · Automation.

**Insight:** Standard Reports · Advanced Reports · AI Insights.

**Platform:** SaaS Billing · Entitlements · Support Tickets · Landing CMS ·
White Label · Audit.

Each is defined as a module boundary in `04-MODULE-BOUNDARIES.md`.

## 8. Product principles

1. **One engine per business rule.** One Booking Engine serves web, mobile,
   white label, host, POS, WhatsApp, and AI. No channel re-implements rules.
2. **Capability, not package.** Code asks "can this tenant do X", never
   "is this tenant on the Pro plan".
3. **Guest-first.** Every customer flow works without an account.
4. **Same schema everywhere.** Every tenant database has an identical schema
   regardless of subscription. Features are gated at runtime, not by DDL.
5. **Attributable by default.** Every meaningful mutation records who, what,
   when, from where, and why.
6. **Brandable, not blank.** Customisation starts from templates, never an
   empty canvas.
7. **Localised, not translated later.** Arabic, English and Kurdish Sorani are
   first-class from day one; more languages must be addable without migrations.

## 9. Explicitly out of scope (for now)

- Microservices, service mesh, distributed transactions.
- Cross-tenant customer identity (a customer at Center A is a distinct record
  from the same human at Center B).
- Meta Style taking custody of customer funds.
- Card data of any kind (see `08-AUDIT-SECURITY.md`).
- A native print agent.
- A blank-canvas visual design tool.

## 10. Where to read next

| Question | Document |
|---|---|
| How is the system shaped? | `01-ARCHITECTURE.md` |
| How are tenants isolated? | `02-TENANCY.md` |
| How do migrations work across N databases? | `03-DATABASE-MIGRATIONS.md` |
| Which module owns what? | `04-MODULE-BOUNDARIES.md` |
| How are features sold and gated? | `05-ENTITLEMENTS.md` |
| Who can do what? | `06-AUTH-ROLES-PERMISSIONS.md` |
| How is multilingual content stored? | `07-LOCALIZATION.md` |
| What is logged and how is it secured? | `08-AUDIT-SECURITY.md` |
| Where do files go? | `09-STORAGE.md` |
| What do the APIs look like? | `10-API-FOUNDATION.md` |
| How do we test all this? | `11-TESTING-STRATEGY.md` |
| How do we deploy and migrate safely? | `12-DEPLOYMENT-MIGRATION-STRATEGY.md` |
| What is built when? | `13-ROADMAP.md` |
| What connects later? | `14-FUTURE-INTEGRATIONS.md` |
| Why was it decided this way? | `DECISIONS.md` |
