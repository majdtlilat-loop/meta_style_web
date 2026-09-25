# 21 — Loyalty, Memberships & Service Packages

> Phase 11. Decisions: ADR-061 (money first: after-commit benefits and
> reconciliation), ADR-062 (points ledger: unrecovered points, qualifying
> lifetime, expiry snapshots), ADR-063 (benefits at the till: Sales seams,
> synchronous give-back on void/discard), ADR-064 (a repair reproduces the
> event-time result), ADR-065 (a benefit on a draft is a hold that expires).

Three customer benefits, three modules, one rule above all others:

| Concept | Is | Is not |
| --- | --- | --- |
| **Invoice** | the amount billed | money |
| **Payment** | money collected | a benefit |
| **FinanceEntry** | money that moved | a points balance |
| **Loyalty** | points earned from collected money and visits, redeemed at checkout | accounting truth |
| **Membership** | a customer's entitlement over a period of time | a payment |
| **Service package** | prepaid sessions of named services | a counter |

Sales, Payments and Finance never import `Loyalty`, `Memberships` or `Packages`.
The benefit modules read Sales and Payments, hear their events, and plug into the
till through Sales' generic seams (§4).

---

## 1. Money first

**A benefit can never undo, delay or misreport real money.**

- `PaymentSucceeded`, `RefundSucceeded`, `SaleFinalized` and `JourneyCompleted`
  are raised inside the transaction that made them true. The benefit listeners do
  nothing there but **schedule** their work with `Kernel\Database\AfterCommit`,
  which runs it after the root transaction commits. A failure is reported
  (`AfterCommitFailed`, with a label) and never rethrown: the payment, its ledger
  entry and the invoice are already committed, and the desk is told the truth.
  This is the opposite of Finance's ledger, which *is* part of the money fact and
  commits with it (ADR-059).
- **Every after-commit reaction is idempotent and replayable from canonical facts**
  — payments, refunds, finalized sales, completed visits. Each has a
  `Kernel\Reconciliation\Contracts\Reconciler`:

  | Reconciler | Replays | Finds |
  | --- | --- | --- |
  | `LoyaltySync` | earning, refund reversals, visit rewards | succeeded payments / refunds / completed visits with no loyalty row |
  | `ActivatePackages` | package activation | finalized, settled sales with a package line and no package |
  | `ActivateMemberships` | membership activation | finalized, settled sales with a membership line and no membership |

  `metastyle:reconcile {--tenant=} {--days=3}` runs them per center, **hourly**
  (`routes/console.php`); run it with a wider `--days` after an outage. A second
  run writes nothing.
- **Reads are read-only.** `LoyaltyQuery`, `MembershipsQuery`, `PackagesQuery`,
  the presenters, the customer API and every panel never write — not even a
  repair. An architecture test forbids them from using the sync services.
- **Actions that change a balance sync the customer first** — `RedeemPoints`,
  `AdjustPoints`, `ApplyPackage`, `ApplyMembershipBenefit` call the module's
  `reconcileCustomer()` before they lock and decide, so a lost callback can never
  make them decide on a stale balance. Cancelling an existing package or
  membership does not: its state does not depend on anything a callback writes.
- **A repair reproduces the event-time result** (§6): reconciliation decides only
  WHEN a missing row is written — never what it is worth, how it is dated, or when
  it expires.
- No queue and no outbox: the work shares the tenant connection, and a
  transaction-scoped callback plus a reconciler covers a crash between commit and
  callback.

## 2. Entitlements

`loyalty`, `memberships`, `packages` — existing catalog keys, not sold by any
seeded plan, so every test grants what it uses. Entitlement = the center owns the
feature; permission = the person may do the operation.

| Operation | Needs |
| --- | --- |
| configure loyalty, tiers; define plans / packages | entitlement + manage permission |
| earn points (sync) | `loyalty` at sync time |
| redeem, adjust points | `loyalty` + permission |
| sell a membership / package (the offering) | entitlement (in the catalog's `offer()`) + `sale.create` |
| use a membership / package already paid for | permission only |
| read history, cancel, give back, refund reversal | permission only |

## 3. Module boundaries

`Loyalty`, `Memberships`, `Packages` sit above Sales, Payments and Journey. They
may read those modules' models and hear their events; nothing below may import
them (architecture tests). HTTP and Livewire compose them; the till and the
customer page embed their own components so those screens import none of them.
Booking never consumes a package; Journey never owns a balance.

## 4. The Sales seams

Sales never names a benefit module. It offers:

- **`SaleBenefits`** — `apply()` / `withdraw()` / `on()`. A benefit is a
  `benefit_discount` adjustment carrying an opaque `source_type` and
  `source_reference` (`unique`), optionally on ONE line. It locks the sale
  (`SaleMutation`), refuses anything but a draft, allows one benefit per line and
  re-prices. Ordinary discount Actions cannot add or remove one; a line carrying
  one cannot be changed or removed; the customer cannot be swapped under it.
- **Line-targeted discounts in `SalePricing`** — a targeted fixed discount is
  bounded by its own line; sale-wide percentages are taken from what the targeted
  discounts left payable; sale-wide discounts are allocated over what each line
  still owes. With no targeted discount every answer is exactly Phase 9's.
- **`OfferingCatalog`** (tag `sales.offering_catalogs`) and the `offering` line
  kind — a membership or package is SOLD as an ordinary line, priced by the
  catalog's `offer()`, never by the request. `Offerings::availableFor()` lists them.
- **`SaleFinalizationGuard`** (tag `sales.finalization_guards`) — a sale selling a
  membership or package needs a customer.
- **Events** — `SaleFinalized`, `SaleVoided`, `SaleDraftDiscarded`.

Lock order everywhere: **the sale first, then the benefit anchor** (loyalty
account, customer package, customer membership; activation: the customer row).

## 5. Visit rewards

A completed visit with at least one performed stage earns `visit_points` once:
the earn row is keyed on the journey uuid, `unique(source_type, source_uuid,
kind)` refuses a second, and a duplicate `JourneyCompleted` writes nothing.

## 6. Loyalty rules, and the rule that was in force

One `loyalty_programs` row holds the CURRENT rules. Zero switches a rule off.
Not a promotion engine.

- **spend** — `spend_points` per `spend_unit_minor` of money actually COLLECTED on
  an invoice, once its net collected reaches `min_spend_minor`;
- **visit** — `visit_points` per completed visit;
- **redeem** — a point is worth `point_value_minor`; at least `min_redeem_points`;
- **expiry** — `expiry_days`, snapshotted on each credit (§21).

### Earning reads history, never the current row

Changing the rules applies to what happens NEXT. That has to hold even when an
earning is written long after the event it belongs to — an after-commit failure
repaired hours later, by which time a manager may have doubled the rate and the
center may have gained or lost `loyalty`. So earning never reads the current
program row:

- **`loyalty_rule_versions`** — every earning rule the center has ever had,
  append-only, each with the instant it took effect. `ConfigureLoyalty` writes one
  on every change. The version effective at an instant is the latest one that had
  taken effect by then.
- **`loyalty_earning_observations`** — what Loyalty saw at an instant: whether the
  center owned `loyalty`, and which version was effective. Two kinds of row: one
  TIED TO A SOURCE, captured while that payment, refund or completed visit was
  still inside its own transaction (a READ — it can never fail the money) and
  written just after it commits; and free-standing points written by a
  configuration change or a reconciliation run.

An event's state is its own observation when it has one, and otherwise the last
observation at or before it. Entitlements live in the control plane and keep no
history of their own; this is Loyalty's record of what it saw, and nothing else
reads it.

### The observation is evidence; the rule history is the record

Recovery must not DEPEND on the observation, because the observation is written
by the same after-commit path that the earning was lost to. So the rule and its
expiry always come from `loyalty_rule_versions`, keyed by the event's own
instant — never from the observation, and never from the current program row.
The observation is consulted for one thing only: the entitlement.

And when no observation at or before the event survives at all, a rule version
that was in force IS the record that earning was running, so the event is
judged eligible. The money keeps its own date, its own rate and its own expiry.
Losing the evidence must never take a customer's points away.

### What that guarantees

- money collected under "1 point per 1,000" earns 1 per 1,000, even when the
  repair runs after the rule became 2 per 1,000;
- points expire by the rule in force when they were earned, counted from the
  event's own time — 90 days from the day the money was collected, whatever the
  program says later;
- an earning row is DATED by its payment or visit, not by the repair;
- money collected while the center owned `loyalty` stays recoverable after the
  entitlement is taken away;
- money collected during a gap never earns, not even when `loyalty` is granted
  again.

The one case the timeline cannot answer exactly is an event whose own
observation never happened — a process that died between the commit and the
callback — AND whose state changed since the last observation before it. It is
then judged by that earlier observation. Hourly reconciliation keeps the window
small.

### Per-invoice rule, and event order

An invoice earns under ONE version: the one effective when its first earning
payment succeeded, frozen in `loyalty_invoice_rules`. Its money is then replayed
in the order it happened — each payment earns the increase it caused in the
invoice's target, each refund of an earning payment reverses the decrease. So a
payment always earns what that payment earned, whatever order the syncs run in
and whichever of them failed. Money collected while nothing earned is left out of
the invoice entirely, and so are its refunds: they have nothing to take back.

Debits are never backdated. A reversal is dated when it is applied, because the
points it takes must be points the customer actually has.

## 7. Redemption

Explicit, at checkout, on a DRAFT: sync, lock the sale, lock the account, write
off aged-out points, check the balance, the minimum and what is left to pay,
write a `redeem` row, apply a sale-wide `benefit_discount`, audit. Once the
invoice is published Sales refuses. One redemption per sale; withdraw it to
change the amount. The redeem row carries the **restore expiry** — the latest
expiry among the credits it consumed (null if any never expires) — which a
give-back credit reuses.

### A benefit on a draft is a HOLD

Redeeming points, covering a line with a package session and using a membership
allowance all happen on a draft, before anything is published. Withdrawing the
benefit, discarding the draft or voiding the sale gives it back — but a draft
nobody ever finishes is none of those, and Sales has no draft expiry of its own.

So a benefit adjustment on a sale that is STILL A DRAFT, where neither the
benefit nor the sale has been touched for `ReleaseStaleBenefits::HOLD_HOURS`
(24), is RELEASED: the adjustment is removed, the sale is re-priced to what it
costs without it, and `SaleBenefitReleased` tells the granting module to give it
back — one transaction, under the sale lock, exactly like a void. The draft is
left open: the cashier can still finish it at full price, or apply the benefit
again if the customer still has it. It runs hourly with the other reconcilers.

An unpublished abandoned sale can therefore never hold a customer's points,
sessions or uses for more than a day. A PUBLISHED sale is not a hold: its
redemption stands until the sale is voided.

The query that finds expired holds runs unlocked, so nothing it decided is
trusted: under the sale lock the release checks AGAIN that the sale is still a
draft, that it is still untouched, and that the adjustment is still the same
hold — and abandons it otherwise. A cashier who came back to the draft, or
finished it, in that window keeps the benefit. A release and a finalization can
never both have it: whichever takes the lock first decides, and the other reads
the result and stands down.

## 8. Refunds, voids and what could not be taken back

- **Refund** — a `reversal` of exactly what the invoice no longer earns. The
  balance covers what it can and **never goes below zero**; the remainder is
  recorded as `unrecovered_points` on that reversal and on the account.
- **Recovery** — every later EARNING first writes a `recovery` row (keyed on the
  earning's uuid) of up to its own points, settling the shortfall before any of it
  is spendable. Append-only and auditable: nothing is hidden, nothing is a
  customer-visible debt.
- **Void / discard** — giving back redeemed points, used sessions and membership
  uses, and cancelling a membership or package a voided sale SOLD, runs
  **synchronously inside the Sales transaction** (`RedeemedPoints`,
  `RedeemedSessions`, `UsedBenefits`). It moves no money; a sale voided while its
  benefit stays spent would be a lie. If it cannot be made consistent, the void
  or discard is refused. Payment and refund reactions are never synchronous (§1).
- A sale voided before collection earned nothing to reverse.

## 9. Tiers

Name, threshold, optional benefit note, active/order. The tier is DERIVED from
**qualifying lifetime points** — Σ earned − Σ (refund reversal + its unrecovered
part). Redemptions, expiry and recoveries do not change it; a refunded earning
never keeps a tier. Never a manually edited customer field. No multiplier.

## 10. Membership plans and customer memberships

A **plan** (translated name, price, `duration_days`, benefits, archive) is what is
offered. A **customer membership** is a snapshot at activation — name, price,
currency, duration, benefits with their service names — so editing a plan changes
only what is sold next. Status stored: `active` · `cancelled`; `upcoming` and
`expired` are derived from `starts_at` / `expires_at`.

## 11. Buying a membership

Sold through the till as an `offering` line → invoice → payment. Activated when
the invoice is **settled** (successful payments cover it, or a zero total at
finalization) — after commit, under the sale lock and then the customer row,
idempotent by `unique(sale_item_id)`. The term is `duration_days` whole
branch-local days counting the start day, ending at the start of a local day.
A **renewal** of the same plan starts when the running term ends, never
overlapping — activation takes the CUSTOMER row lock after the sale's, so two
renewals settling at the same moment serialize into consecutive terms. A voided
selling sale cancels it.

## 12. Membership benefits

A discount on one service or on every service: `percent` (basis points of the
SERVICE price × quantity, rounded half up) or `fixed` (per unit, never more than
the service price); add-ons stay charged, as with packages. Optional
`uses_per_term`, counted from `membership_benefit_usages` (Σ use − Σ reversal),
one use per unit. Applied to a service line through `SaleBenefits`; withdrawn,
discarded or voided → one `reversal` of exactly that use.

## 13. Package definitions and customer packages

A **definition** (translated name, price, `validity_days`, items of a service or
service variation with a quantity, archive). A **customer package** is a snapshot
with its items. No branch scoping in Phase 11 (decision).

## 14. Package history

`package_transactions`: `allocation`, `redemption`, `reversal`, `cancellation`,
append-only. Sessions left = Σ allocation − Σ redemption + Σ reversal −
Σ cancellation, proven from the history every time — no counter. Every
redemption names its sale and, for a visit, the stage it covered;
`unique(source_type, source_uuid, kind)`.

## 15. Buying a package

Like a membership (§11): an `offering` line, activated when the invoice is
settled, after commit, idempotent by `unique(sale_item_id)`. An unpaid or
part-paid invoice activates nothing. A voided selling sale cancels it.

## 16. Using a package

Consumption follows PERFORMANCE, and the line has to PROVE it:

- **a visit line** carries the journey stage it came from, and that stage must be
  `completed` — the visit board is the proof;
- **a line typed at the till** proves nothing by itself, so a package may only
  cover it when the person at the till explicitly CONFIRMS the service was
  performed (`performed` on the API, a checkbox on the panel). Adding a service
  to a cart is not performing it.

The audit row records which of the two it was (`journey_stage` or
`staff_confirmed`). Booking never consumes a session.

Then: sync, lock the sale, lock the package, check it is the customer's, active
and in date, that an item covers the service (and variation), that enough
sessions are left; write one `redemption`; discount the line's unit price ×
sessions on that line only.

## 17. Partial coverage

One unit of a line consumes one session; covering 1 of a 2-unit line discounts
exactly one unit, and add-ons stay charged. Nothing silently zeroes a line.

## 18. Package expiry

Derived, never written: a package stops working at the start of the branch-local
day `validity_days` after activation. Expired packages cannot redeem and stay
readable. No scheduler.

## 19. What customers see

`GET /api/v1/customer/benefits` and the account page, through
`App\Http\Presenters\CustomerBenefits`: available points, tier and recent
activity (kind, direction, points, date); memberships in force or upcoming with
their benefits, uses left and first/last local day; active packages with sessions
left and last local day. Never a uuid, sale, reason, staff name, lifetime or
unrecovered figure. Strings come from the `customer_benefits` group (§28).

## 20. Permissions

`loyalty.view`, `loyalty.manage`, `loyalty.adjust`, `membership.view`,
`membership.manage`, `package.view`, `package.manage`. Manager: all. Host and
Cashier: the three `*.view`, which with the till's own `sale.create` lets them
redeem and apply what a customer already has. No owner bypass.

## 21. Expiry, adjustments and administrative changes

- **Points expiry is snapshotted** on each credit (`expires_at`). Changing the
  program changes new credits only. Which points remain is derived FIFO from the
  history (`PointsLots`): credits are lots with their own expiry, debits consume
  the oldest still-valid lots first, `expiry` rows record what aged out. Expiry is
  written LAZILY under the account lock before any movement reads the balance,
  and is idempotent. No scheduler.
- **Manual adjustments** — `loyalty.adjust`, a reason (3–190), the actor, an
  audit row, an append-only `adjustment`. Never an edit.
- **Cancelling** a package (forfeits what was left as `cancellation` rows) or a
  membership (stops it being usable) needs the manage permission and a reason,
  keeps every history row and moves no money — a refund is a separate Payments
  refund.

## 22. Downgrade

Losing an entitlement blocks NEW operations and erases nothing: no new earning or
redemption, no new plans/packages sold. History stays readable; refund reversals
and give-backs still run; **paid memberships and packages stay usable until their
own expiry**, and a purchase settled after the downgrade still activates — the
customer paid (decision).

## 23. Isolation

Everything is tenant-local: no `tenant_id` in any benefit table, no global
customer benefit. The same phone at two centers is two customers with two
balances; a package or membership from one center is unknown at another; with no
center bound every benefit query fails (`tests/TenantIsolation/BenefitsIsolationTest`).

## 24. Concurrency

Database locks, never Redis: the account row for points, the package row for
sessions, the membership row for uses, the sale row for activation (then the
customer row for renewals). Second-connection tests prove a second desk waits and
then decides on the committed state; balances never go negative.

## 25. Idempotency

Source uniqueness everywhere: earn per payment, reversal per refund, visit reward
per journey, recovery per earning, activation per sale line, redemption/use per
application uuid, give-back per redemption (a released hold gives back exactly
once), one observation per source event. Replays and duplicate events write
nothing.

## 26. Architecture tests

`tests/Architecture/BenefitsBoundaryTest.php`: nothing below depends on the
benefit modules; they do not touch HTTP, Livewire or Finance; enums are string
backed; histories are append-only at the model; reads never use the sync
services; every money/finalization handler starts with `AfterCommit::run`.
`DatabasePortabilityTest` also refuses a generated index or foreign-key name over
64 characters.

## 27. UI and API

Functional, not final design. Staff API under `/api/v1/tenant`: `loyalty/program`
(GET/PUT), `loyalty/tiers`, `customers/{uuid}/loyalty` (+ `adjustments`),
`sales/{uuid}/loyalty-redemption`, `membership-plans`, `customers/{uuid}/memberships`,
`customer-memberships/{uuid}/cancel`, `sales/{uuid}/items/{line}/membership-benefit`,
`package-definitions`, `customers/{uuid}/packages`, `customer-packages/{uuid}/cancel`,
`sales/{uuid}/items/{line}/package` (with `performed` for a till line),
`sales/offerings`. Pages: `center/loyalty`,
`center/memberships`, `center/packages`; the till's benefits panel
(`TillBenefits`) and the customer page's panel (`CustomerBenefitsPanel`).

**Manager pages (Phase 15).** `/manager/loyalty`: KPIs (members, points
outstanding, points earned and kept — `LoyaltyQuery::totals()`), what is
switched on (`LoyaltyPresenter::program()` flags), the rules form, the rule
history (`ruleVersions()`, append-only), tiers with add / edit / archive /
restore (`ManageLoyaltyTier::restore`) and the points holders
(`LoyaltyQuery::members()`, name only, largest balance first).
`/manager/memberships` and `/manager/packages`: on sale / archived views, edit
from the presenter's own figures (price back to a major string, percent from
`SalePricing::percentLabel`), display order, restore
(`ManageMembershipPlan::restore`, `ManagePackageDefinition::restore`), live
counts per plan/definition (`liveCountsByPlan` / `liveCountsByDefinition`,
one grouped query keyed by uuid), package items with a VARIATION, and the
holders lists (`members()` / `holders()`, sessions left from `PackageLedger`).
The customer panel reads each module separately (one failure never blanks the
others), labels kinds and states in the viewer's language, shows branch-local
days and the points' value, cancels on the card itself with a reason, and
offers an adjustment only when `loyalty` is owned. **Downgrade**: a page whose
feature is not owned and which has no history is the upgrade page; with
history it stays readable with the compact notice and no create/edit controls
— the Actions still refuse (`ensure`). The customer panel applies the same
rule per module: a module not owned shows the compact plan notice over its
history, or alone (and reads nothing) when the center never had any. Action
messages come from `lang/*/manager_benefits.php`.

## 28. Localization

Names are `Translatable` JSON, one field per enabled locale. Customer-facing
strings: `lang/{en,ar,ckb}/customer_benefits.php` (two words, never a group named
after a dotless literal). Staff labels stay inline.

## 29. Audit

Configuration, tiers, plans, definitions, manual adjustments, redemptions and
give-backs, activation, uses, cancellation — each in the change's transaction.
Audit is who did what; the module histories are the balance truth.

## 30. Out of scope

Reviews, ratings, review QR, notifications, reminders, promo codes, promotions,
gift cards / stored value, referrals, WhatsApp, RAYAN, advanced reports,
commissions, payroll, inventory, SaaS billing, branch-scoped benefits, tier
multipliers.
