# 18 — Sales, POS, Invoices & Cashier Shifts

> Status: **Implemented (Phase 9).** Section numbers follow the Phase 9
> specification, so a `§N` in code resolves here.
>
> Decisions: ADR-054 (sale ≠ visit, invoice = immutable snapshot), ADR-055
> (numbering), ADR-056 (browser printing, no PDF/QR dependency), ADR-057
> (financial-consistency corrections: visit completion, hashed links, downgrade,
> in-transaction audit).
>
> Phase 10 added Payments and Finance beside Sales without giving Sales a payment
> concept: see `docs/19-PAYMENTS.md` and `docs/20-FINANCE.md`. The Phase 10 touches
> here are the void guard (§20), opening cash (§§13–14) and the money routes (§§42–46).

## 1. Seven concepts, permanently separate

| Concept | Answers | Lives in |
|---|---|---|
| Appointment | what was **reserved** | `appointments`, `appointment_items` |
| ServiceJourney | the operational **visit** | `service_journeys` |
| JourneyStage | what was actually **performed** | `journey_stages` |
| QueueTicket | the **waiting and calling** around a stage | `queue_tickets` |
| **Sale** | what was **charged** | `sales`, `sale_items`, `sale_item_addons`, `sale_adjustments` |
| **Invoice** | what was **published** — immutable | `invoices`, `invoice_items` |
| Payment | money **settlement** — Payments, Phase 10 (`docs/19-PAYMENTS.md`) | `payments`, `refunds` |

A sale may point at a visit. It never becomes one, and **no financial state is
ever written to the appointment, journey or queue tables** — a TenantIsolation
test scans them for `sale_id`, `grand_total_minor`, `payment_status` and friends.

## 2. Module boundaries

`app/Modules/Sales` reads Booking (appointment-item price snapshots), Journey,
Catalog, Customers and Branches. **Nothing depends on Sales**: architecture tests
forbid Booking, Journey, Queue, Resources, Customers, Catalog and Branches from
importing it. A center without POS completes visits exactly as before.

`Product` lives in **Catalog** (§6). The pricing service (`Domain/Pricing`) is
framework-free and imports nothing from Illuminate.

## 3. Sale

`uuid · branch_id · customer_id? · service_journey_id? · active_journey_id? ·
source · status · currency · subtotal/discount_total/surcharge_total/tax_total/grand_total (minor) ·
cashier_shift_id? · idempotency_token? · created_by/finalized_by/voided_by · void_reason ·
finalized_at · voided_at`

Adjustment total is derived (`surcharge_total − discount_total`), not stored twice.

## 4–5. Lifecycle

```
draft ──finalize──▶ finalized ──void──▶ voided
  │
  └─discard──▶ (deleted; the audit log keeps the fact)
```

A **draft is the cart**: mutable, unnumbered, not yet financial. **Discard deletes
it**, because it never became financial history and keeping abandoned carts
forever puts noise in every sales query. A **finalized** sale is frozen (§19). A
**voided** sale keeps its invoice.

No payment states. "Unpaid", "partial" and "paid" belong to Payments
(docs/19 §9), which reads the invoice and never writes to `sales` or `invoices`.
Sales never imports Payments or Finance (architecture test).

## 5. Sale lines

`kind`: `service` (with variation and add-ons) · `product` · `custom`.

Every line is a **snapshot** taken when it is added: name, variation name, add-ons
with their prices, quantity, `original_unit_price_minor`, `price_source`
(`catalog` · `journey` · `manual`), `unit_price_minor`, line subtotal, discount
allocation, line total, currency. References (`service_id`, `product_id`,
`journey_stage_id`, `employee_id`) are nullable and never read to price.

A `custom` line needs `sale.adjust` **and** a reason: it is a price nobody's catalog
agreed to. Tax fields beyond a zero `tax_total` do not exist (§41).

## 6. Products

`products`: `uuid`, translated `name`, `sku?` and `barcode?` (unique), `price_minor`,
`is_active`, `archived_at`, `sort_order`. Sellable at every branch.

**Not inventory.** No stock, cost, supplier, batch, expiry or warehouse — a test
asserts none of those columns or tables exist. Inventory will be its own module
that reads this row. Per-branch availability arrives with a stated need.

## 7. Journey → Sale

`CheckoutJourney` **prepares** a draft from an **active or completed** journey,
never an aborted one. `FinalizeSale` **publishes** it only once the journey is
**completed**:

| Journey | Checkout (draft) | Finalization |
|---|---|---|
| `active` | created or reused — the desk previews Booked · Performed · Charged and the unfinished stages | **refused**: "still in progress" |
| `completed` | created or reused | allowed |
| `aborted` | refused | **refused**: discard the draft |

An invoice issued mid-visit would miss every service performed after it, and the
one-live-sale rule (§8) would leave nowhere honest to charge them. There is no
partial checkout, split invoice, progress billing or deposit invoice. A direct
POS sale has no journey and is unaffected.

| Stage status | Becomes |
|---|---|
| `completed` | a candidate line, priced from the **visit's own snapshot** |
| `skipped` | nothing — never charged automatically |
| `waiting`, `in_service` | nothing — shown as not yet performed |

**Priced from the visit, never today's catalog.** A booked visit carries the price
quoted at booking; a walk-in stage carries the price at arrival.

**The appointment price includes its add-ons.** Booking stores
`appointment_items.price_minor` = base-or-variation **plus** every add-on, and each
add-on again on its own row. The line's unit price is the item price minus its
add-ons; the add-ons are carried separately. An item cheaper than its own add-ons
is refused rather than guessed at.

**No performed service is lost** (`VisitLines`). Every completed stage without a
line gets one — when checkout is **reopened** (audited `sale.visit_lines_added`)
and, authoritatively, **inside finalization** once the visit is completed and its
stages can no longer change (counted in `sale.finalized` meta). A stage can also
be charged explicitly (`AddSaleLine`, `kind = journey_stage`). Once each —
`unique(sale_id, journey_stage_id)`.

That guarantee needs one rule: **a visit line is never removed.** Removing one
was a 100% discount without `sale.adjust`, and finalization would put it back.
Charging less for a performed service is an explicit price override with a
reason, or a discount — visible on the bill and in the audit trail. Quantity on a
visit line stays 1.

## 8. One live sale per visit

`sales.active_journey_id` equals `service_journey_id` while the sale is a draft or
finalized, NULL once voided, with a unique index. Checkout is:

```
read    WHERE active_journey_id = ?          → the usual repeat returns it
lock    service_journeys row FOR UPDATE
re-check, create
backstop unique(active_journey_id)           → a race reads the winner
```

A double-clicked checkout returns the same draft. **Voiding releases the visit**
so it can be charged correctly on a new sale; `service_journey_id` still records
which visit the voided sale charged. Direct POS sales carry no journey at all.

## 9. The cart and server-side pricing

All cart edits go through `SaleMutation`:

```
BEGIN
  SELECT … FROM sales WHERE id = ? FOR UPDATE
  refuse unless the LOCKED row is still a draft
  apply the change
  recalculate every total from stored lines   ← SalePricing, nothing else
COMMIT
```

**Totals from the browser are never read.** The only request carrying a price is a
custom line or an override, and both need `sale.adjust` plus a reason.

## 10. Money and rounding

Integer minor units only; IQD exponent 0. One rounding rule:

```
percent amount = intdiv(base × basis_points + 5000, 10000)   // half up, one minor unit
```

Percentages are stored as basis points (1–10000) and parsed from what a person
typed **as a string** (`SalePricing::basisPoints("12.5") = 1250`), never through a
float.

**Allocation** of sale-level discounts to lines uses the **largest remainder**
method (earlier line wins a tie), so the shares always sum exactly.

**A sale is capped at 3 000 000 000 minor units** so the allocation's largest
product is ≤ 9 × 10¹⁸ and fits a signed 64-bit integer — exact arithmetic with no
floats and no big-number dependency.

Rounding a cash total to the smallest note in circulation is a settlement concern.
Phase 10 did not add it: the desk records the exact amount it takes.

## 11. Pricing seam

`LinePriceResolver` turns a catalog pick into a snapshot **once**, validating:
active, not archived, offered at the sale's branch, variation belongs to the
service, each add-on attached to it. `JourneyChargeCandidates` does the same from
a visit. `SalePricing` computes totals from snapshots. **Finalization re-prices
from the snapshots and never reads the catalog** — a query-count test asserts an
invoice render touches no catalog table.

```
line subtotal   = (unit price + add-ons per unit) × quantity
subtotal        = Σ line subtotals
discount total  = Σ discounts            (never above subtotal)
surcharge total = Σ surcharges
grand total     = subtotal − discounts + surcharges + tax (0)
```

## 12. Discounts and surcharges

`discount_fixed` · `discount_percent` (of the SUBTOTAL, never compounding) ·
`surcharge_fixed`. Each needs `sale.adjust`, a reason, and is audited. At most 10
per sale. A discount may take a sale to zero and never below; surcharges do not
create discountable value. Removing a line that would leave a discount bigger than
the sale is refused as a whole.

No promo codes, loyalty or membership pricing.

## 13. Price override

`ChangeSaleLine::overridePrice` sets `unit_price_minor` with the actor and a reason
and requires `sale.adjust`. `original_unit_price_minor` is never touched; a null
price restores it. Audited at `notice` with before, after and original.

## 13–14. Cashier shift

`cashier_shifts`: `uuid · branch_id · user_id · active_user_id? · status · opened_at ·
closed_at? · opening_note? · closing_note? · closed_by`.

**Invariant: one open shift per user per branch.** `active_user_id = user_id` while
open, NULL once closed; `unique(active_user_id, branch_id)`; opening locks the
user's row first. A second tap returns the open shift. One person may hold shifts
at two branches.

**Finalizing a sale requires your open shift at that branch**, and stamps it on
the sale — the attribution reconciliation needs. Closing your own shift
needs `cashier_shift.manage`; closing someone else's needs
`cashier_shift.supervise`.

**Phase 10:** a shift may record `opening_cash_minor` when it opens. With the
`finance` entitlement the till closes through Finance's `CloseShiftWithCount`,
which snapshots expected cash, the count and the variance and calls this close
in the same transaction (docs/20 §§31–33); without it, closing is unchanged.
Cash payments and cash refunds lock the shift, so they serialise with a close.

## 14–16. Invoice

Created **only** by `FinalizeSale`. Stores its own snapshots: number, prefix,
`sequence_year`, sequence, `issued_at` (DATETIME UTC) and `issued_timezone`, center
name, branch name/address/phone, customer **name**, currency, all totals,
adjustments (JSON), and `invoice_items` with names, variations, add-ons (JSON),
quantities and amounts.

**Immutable**, enforced four ways:

1. `ImmutableDocument` throws on update and delete of `Invoice` and `InvoiceItem`.
2. No Action edits an invoice.
3. An architecture scan bans query-builder writes to `invoices` / `invoice_items`.
4. Tests: renaming and repricing the service, product and branch after
   finalization leaves the invoice, its render, its public page and the sale's
   totals identical.

Corrections are voids today and credit notes later — never rewrites.

## 17. Numbering

Per **branch** per **branch-local calendar year**, from `invoice_sequences` under
`FOR UPDATE`. Format `{PREFIX}-{YYYY}-{NNNNNN}`. Prefix from
`branches.invoice_prefix`; the main branch falls back to `INV`, which is reserved
for it; any other branch must set one before issuing. Backstops:
`unique(branch_id, sequence_year, sequence_number)`, `unique(number)`,
`unique(sale_id)`.

The column is `sequence_year`, named for what it is: the branch-local calendar
year the numbering resets on. It makes no claim about the center's fiscal or
accounting year, which nothing in the product has verified.

**Exact guarantee:** unique and gapless per branch-year for as long as invoices
are never deleted and nobody edits the sequence by hand. A failed finalization
consumes nothing (ADR-055).

## 18. Finalization

```
BEGIN
  lock the sale FOR UPDATE
  finalized?  → return THAT invoice (replayed)      voided? → refuse
  visit's sale? → journey must be completed; add missing performed services (§7)
  lock your open shift FOR UPDATE        (none → refuse)
  ≥ 1 line
  recalculate from snapshots
  allocate the number                    (locked sequence row)
  write Invoice + items + share-link DIGEST
  mark finalized, stamp the shift
  audit sale.finalized, invoice.issued   ← same transaction
COMMIT
```

Two desks on one sale serialise on the sale lock; the second gets the same
invoice with `replayed: true` and no link secret (§20). A unique violation on
`invoices.sale_id` resolves to the published invoice.

**The audit commits with the money.** `sale.finalized` and `invoice.issued` are
written inside the finalization transaction, to the tenant's own `audit_logs` on
the same connection — the synchronous, in-transaction write docs/08 §7 already
prescribes. If either insert fails, the invoice, its items, its link, the
sequence increment and the sale's state all roll back, and the caller sees the
error: never an invoice reported as issued with no accountable record. `sale.voided`
and `sale.discarded` (the only trace a discarded draft leaves) follow the same rule.
Audit still records who and when; the sale and invoice rows remain the commercial
record, and no line or total history is copied into audit. Tested by failing the
audit insert mid-finalization (`SalesAuditAtomicityTest`).

## 19. Finalized sales are frozen

Adding, removing or changing lines, overriding prices, adjusting, changing the
customer and discarding are all refused by `SaleMutation` against the LOCKED row.
A finalized sale never returns to draft.

## 20. Void

`sale.void` + a reason (3–190 chars), finalized sales only. Records who, when, why
on the sale; clears `active_journey_id`; leaves the invoice row byte-identical.
The digital and printed invoice show **VOID** and the void date, never the reason.
A repeated void returns the same outcome. **A void never refunds.** Since
Phase 10, `CloseSale::void` runs every `Sales\Contracts\SaleVoidGuard` (container
tag `sales.void_guards`) inside its transaction with the sale locked; Payments'
guard refuses while an online payment or a refund is pending, or while money is
collected — refund it explicitly first (docs/19 §27). Sales imports nothing from
Payments to do this.

## 20, 28. Digital invoice

`invoice_share_links`: `token_hash` (`CHAR(64)`, unique), `active_invoice_id`
(unique — one live link), `revoked_at`.

**The secret is never at rest** — the rule registration and staff activation
tokens already follow (ADR-035). `InvoiceShareToken` generates 256 bits
(`random_bytes(32)`, 64 hex); the row stores **SHA-256 of it only**; the public
lookup hashes what was presented and queries by the digest. The plaintext exists
in exactly one place: the URL returned by the call that minted it —
`FinalizeSale` (`IssuedInvoice::$shareToken`) or `RotateInvoiceLink`. So:

- `share_url` appears only in the finalize and share-link responses. A repeated
  finalize, `GET invoices/{uuid}` and every list say `share_link_active` instead.
- A desk that needs the link again **issues a new one**; the old one stops working.
- The till and the sales screen hold a freshly minted URL in a `#[Locked]`
  Livewire property for that screen only — never the session, which may be
  database-backed.
- Neither the secret nor its digest is audited, logged or serialised
  (`$hidden`). The public-tenant conflict audit records the route **template**,
  not the literal path, because this path carries the secret.

Tested: every row of every tenant table, `platform_audit_logs`, `jobs`,
`failed_jobs` and every log line are searched for both the revoked and the live
plaintext after a finalize, a rotation and public reads.

Routes: `GET /i/{center}/{token}` (page) and `GET /api/v1/invoices/{center}/{token}`
(JSON), both `public.tenant` + `locale` + `throttle:public-invoice`, **no auth
middleware, ever**. Unknown, malformed and revoked tokens are the same 404; an
invoice number is not a key. Another center's token under this center's key: 404.
The page sets `noindex, nofollow` and `referrer: no-referrer`.

**Not gated on `pos`** — see §15a.

## 15a. Losing POS stops new sales, not history

The downgrade rule Booking set in Phase 6 (docs/05 §6.2): mutations require the
entitlement, reading what exists does not. `SalesAccess` has two gates:

| Gate | Checks | Used by |
|---|---|---|
| `ensure()` | `pos` + permission + branch | new draft, checkout, every draft mutation, finalize, void, discard, shifts, products, invoice prefix |
| `authorize()` | permission + branch | `SalesQuery` (find, day list, invoice), link rotation |
| `ensurePrinting()` | `printing` + `invoice.print` + branch | 80mm / A4 page, printable payload |

After POS is withdrawn: staff still read finalized sales and invoices (API and
the Sales screen, which says POS is not included and hides void, products and
prefix); the customer's live link still resolves; the invoice stays immutable;
printing follows `printing` alone; the till (`/center/pos`) says POS is not
included and shows no cart. **Link rotation stays available**: revoking a leaked
credential to a document already published is a security control over history,
not a new commercial operation. Platform lifecycle controls are unchanged — a
center `ResolvePublicTenant` will not resolve answers 404 everywhere. Drafts left
open are frozen until POS returns. Tested in `SalesDowngradeTest`.

## 21–22. Receipt vs invoice

The invoice is the financial document. The 80mm receipt, the A4 page and the
digital invoice are **three layouts of one view model** (`InvoiceRenderer`), built
only from the invoice's snapshots. There is no second, mutable "receipt" record.

## 23–24. Printing

`pos` owns the invoice; **`printing` owns the paper**. The print page
(`/center/sales/invoices/{uuid}/print/{80mm|a4}`) and the printable API payload
need `printing` **and** `invoice.print` — not `pos`: the paper is a surface over
history (§15a). A center with POS but without Printing sees every invoice
digitally and gets no print buttons.

Browser print, `@page` sizes, logical CSS properties, `dir` from the language
registry. No ESC/POS, no print agent, no device management.

## 25. PDF and QR

Not in Phase 9 (ADR-056). The browser's "Save as PDF" covers the A4 case and
shapes Arabic and Kurdish correctly. No QR is printed; adding one needs a package
decision (`chillerlan/php-qrcode` or a JS library).

## 26. Templates

`Kernel/Templates` does not exist and was not built. Fixed Blade templates, no
center-authored HTML, CSS or JavaScript; an architecture scan refuses `{!! !!}` and
`@php` in the invoice and till templates. **Branding:** center name and branch
details. No logo or footer setting exists yet, so none is rendered.

## 27, 54. Customer-safe allow-list

The public payload is exactly: `number, issued_date, issued_time, center_name,
branch_name, branch_address, branch_phone, customer_name, currency, lines,
adjustments, subtotal, discount_total, surcharge_total, tax_total, grand_total,
voided, voided_date, locale, direction` — and each line `name, variation, addons,
quantity, unit_price, line_subtotal`. A test asserts the key list and that no
note, reason, staff identity, uuid, original price, customer phone/email or the
token itself appears.

## 30. Sale source

`pos` · `journey_checkout` · `walk_in_checkout` — stored, never inferred.

## 31. Direct POS sale

`CreateDraftSale` — no journey, no appointment, idempotent on a client token.

## 32–33. Journey independence

`CompleteJourney` creates no sale and works without `pos` (tested). The visit board
shows a **Checkout** link to `/center/pos?journey={uuid}`; the till calls
`CheckoutJourney` on mount. The board imports nothing from Sales.

## 35–36. Permissions

| Code | Allows | Manager | Cashier | Host | Employee |
|---|---|:-:|:-:|:-:|:-:|
| `sale.view` | read sales and their invoices | ✓ | ✓ | ✓ | |
| `sale.create` | carts, checkout, customer, discard | ✓ | ✓ | ✓ | |
| `sale.finalize` | publish the invoice, rotate its link | ✓ | ✓ | | |
| `sale.adjust` | discounts, surcharges, overrides, custom lines | ✓ | | | |
| `sale.void` | void a finalized sale | ✓ | | | |
| `invoice.print` | paper surfaces (with `printing`) | ✓ | ✓ | | |
| `cashier_shift.manage` | open/close your own shift | ✓ | ✓ | | |
| `cashier_shift.supervise` | close anyone's shift | ✓ | | | |
| `product.manage` | edit the product catalog | ✓ | | | |

Owner holds everything as explicit grants. There is no `invoice.view` — viewing an
invoice is viewing a sale. The branch invoice prefix uses the existing
`branch.manage`. Catalog: 62 → 71. **Run `metastyle:roles:sync --all` on deploy.**

## 38–39. Visit snapshot vs sale snapshot

The journey says what execution represented; the sale says what was charged. They
may differ (discount, override, complimentary service). Both are kept. The sale's
customer defaults from the visit; changing it never rewrites the visit.

## 40. Currency

One currency per sale, snapshotted from `Currency::default()` at creation. Every
line must match; a visit priced in another currency is refused. No FX.

## 41. Tax

No tax requirement exists anywhere in the product. `tax_total_minor` is a zero
seam on sales and invoices; no VAT is guessed. Tax labels and registration numbers
are a product/legal decision.

## 42–46. Surfaces

**API** (`/api/v1/tenant`): `sales` list/create/show/discard; `sales/{uuid}/items`
add/patch/delete; `…/price-override` set/clear; `sales/{uuid}/customer`;
`sales/{uuid}/adjustments` add/delete; `finalize`; `void`;
`journeys/{uuid}/checkout`; `invoices/{uuid}`, `…/printable`, `…/share-link`;
`branches/{uuid}/invoice-prefix`; `cashier-shifts` open/current/close; `products`.
**No payment endpoint on Sales.** Since Phase 10 every money route is served by a
Payments surface (docs/19 §57); `SalesSurfaceTest` checks the route table for
exactly that, and that `SalesController` and the till answer none. The till and
the sales list embed Payments' invoice money panel without importing Payments.

**UI:** `/manager/pos` (the till — branch, shift, search and barcode, cart, variation
and add-ons, adjustments, customer, finalize, the freshly minted invoice link,
print) with a **Booked · Performed · Charged** table for visit checkouts, a
"visit still in progress" notice that disables finalize, and no Remove on visit
lines; `/manager/sales` (sales history, detail, void, issue a new customer link,
print — readable without `pos`); the public invoice page.

**Phase 15 Manager.** The till reads its catalog through `TillCatalog`
(services by category, products, a service's options and extras — display
only; `LinePriceResolver` prices the line), adds an exact barcode or SKU on
Enter, steps quantities through `ChangeSaleLine`, labels a benefit discount as
a discount (`is_discount` from `AdjustmentType::isDiscount()`; it used to read
"Surcharge"), finds customers through the CRM's own `CustomerQuery` (a phone
is a search key only with `customer.contact.view`; contact is masked by the
presenter) and adds a walk-in through `SaveCustomer`. A service rung up at the
till may name who performed it (`AddSaleLine` / `ChangeSaleLine::update` key
`employee`, stored in the existing `sale_items.employee_id`): the employee must
be active, assigned to the sale's branch and eligible for that service — the
eligibility Booking applies (`LineEmployees`). A visit line's performer comes
from its stage and is never re-typed; the staff presenter shows the name, the
public invoice never does, and nothing computes a commission from it. Without
`pos` it is the upgrade state. **Sales history** (`SalesQuery::history`) covers whole
branch-local days, searches an invoice number or customer name, filters by
state and by who issued the sale, paginates, and shows issued/voided counts and
totals per currency (`historyTotals`); a malformed `?date=` is refused with a
message instead of reaching Carbon. The detail drawer shows the adjustments,
cashier, local issue time and source; it opens from `?sale=`. **POS settings**
(`/manager/pos/settings`, `pos`): products named once per enabled content
language and priced by parsing what was typed (`SaveProduct` create, update,
off-sale, archive), and each branch's invoice prefix (`SetBranchInvoicePrefix`)
— the page the prefix refusal already pointed to. The customer link is now
published under the center's own host (`InvoiceLinks::url` takes the request's
center slug; only a hostless API call still falls back to the public key).

## 47–49. Concurrency

Tested against a lock held by a genuinely separate MySQL connection, with the real
Action running under a one-second lock timeout: finalizing a sale another desk is
finalizing waits and consumes nothing; finalizing a different sale while another
desk holds the branch sequence waits and then receives the next number; a second
"open shift" waits and returns the first; closing a shift waits behind a
finalization using it.

## 50–51. Isolation

All sales tables are in the tenant database with no `tenant_id`. Tested: sales and
invoices apart when ids and numbers collide; tokens do not resolve across centers;
numbering independent; shifts and products isolated; fail closed with no tenant.
Branch: a visit is checked out only at its branch; a service must be offered at
the sale's branch; shifts and sequences are per branch.

## 53. Query budgets

Asserted as "does not grow": presenting a cart (3 vs 12 lines), rendering an invoice
(3 vs 12 items, ≤ 3 queries, no catalog table), and a day's sales list (3 vs 12
sales). Lists are capped at 100.

## 56. Schema notes

Every business instant is DATETIME. Every invariant is a nullable unique column,
never a partial index, CHECK or generated column. No index is wider than three
columns. Indexes and their queries are documented in the migrations.

## 58. Not in Phase 9

Payment gateways, payment intents, webhooks, refunds, settlements, tips, deposits,
expense/revenue accounting, financial reports, cash reconciliation, commissions,
loyalty, memberships, promo codes, gift cards, reviews, notifications, WhatsApp,
RAYAN, advanced reports, PDF, QR, inventory.

## 59. Phase 11 additions — benefit seams

Loyalty, Memberships and Packages change what a sale charges without Sales
naming any of them (docs/21 §4, ADR-063):

- **`SaleBenefits`** — a `benefit_discount` adjustment with an opaque
  `source_type` / `source_reference` (unique), optionally on one line (one
  benefit per line), applied and withdrawn under `SaleMutation`, drafts only.
  `AdjustSale` cannot add or remove one; `ChangeSaleLine` refuses a line carrying
  one; the customer cannot change under one.
- **Line-targeted discounts in `SalePricing`** (§12): a targeted fixed discount is
  bounded by its line; percentages are taken from what targeted discounts left;
  sale-wide discounts are allocated over what each line still owes. With none,
  every result is exactly Phase 9's.
- **`offering` lines** priced by an `OfferingCatalog` (tag
  `sales.offering_catalogs`); quantity is always 1. `GET sales/offerings` lists
  what may be sold.
- **`SaleFinalizationGuard`** (tag `sales.finalization_guards`) — asked before
  finalizing.
- **Events**, dispatched inside the Sales transaction: `SaleFinalized`,
  `SaleVoided`, `SaleDraftDiscarded`.

