# 20 — Center Finance: Ledger, Expenses, Drawer Counts & Dashboard

> Status: **Implemented (Phase 10).** Section numbers follow the Phase 10
> specification — one numbering shared with `docs/19-PAYMENTS.md` — so a `§N` in
> code resolves in one of the two.
>
> Decisions: ADR-059 (the ledger is written by Finance, in the transaction that
> moved the money), ADR-058 (payments, reservation, refunds).

## Center finance, not platform billing

Finance is the **center's** record of money that moved through it: customers
paying, refunds going back, expenses going out, and what each cashier's drawer
should hold. It lives in the tenant database. It never reads or writes the
control plane, SaaS subscriptions or Meta Style's own invoices, and it holds no
funds (docs/04 §SaaS).

## 3. Downgrade (shared rule)

`finance` gates **new** work: posting and voiding expenses, managing categories,
counted closes and the dashboard. History stays readable without it: the ledger
(`finance.view`), expenses (`expense.manage`) and a shift's expected cash. Nothing
is deleted or hidden by a downgrade (`FinanceSurfaceTest`, `ExpenseTest`).

A center without `finance` still takes payments and closes shifts the Phase 9 way
— the ledger is still written, because it records facts about money, not a
feature being sold (ADR-059).

## 31–33. Counting the drawer

### Opening cash
`cashier_shifts.opening_cash_minor` (nullable, Sales-owned) is set when a shift
opens (`ManageCashierShift::open`, max 3,000,000,000).

### 32. Expected cash
```
expected = opening cash
         + CASH collections recorded against this shift
         − CASH refunds recorded against this shift
         − CASH expenses paid from this drawer
         + reversals of those expenses, while the shift was still open
```
Only `method = cash`. A transfer, a card terminal or an online payment never
passed through the drawer. Computed by `ExpectedCash::forShift` in one query on
the ledger's `cashier_shift_id`.

### 33. The counted close
`CloseShiftWithCount`:
```
BEGIN
  lock the shift                      → must be open
  expected = ExpectedCash              (under that lock)
  write the reconciliation: opening, collected, refunded, expenses, reversals,
                            expected, counted, variance = counted − expected
  close the shift                      (Sales' own close, nested — same transaction)
  audit cashier_shift.reconciled       (warning when variance ≠ 0)
COMMIT
```
The shift lock is the one every cash collection, cash refund and drawer expense
takes, so no cash moves between computing what the drawer should hold and closing
it. **A variance never blocks the close** — it is the fact being recorded, and no
note is demanded. A reconciliation is append-only and snapshotted: it is never
re-derived later.

`cashier_shift_reconciliations`: `uuid · cashier_shift_id (unique) · branch_id ·
currency · opening/collected/refunded/expenses/expense_reversals (minor) ·
expected_cash_minor (signed) · counted_cash_minor · variance_minor (signed) ·
note? · reconciled_by · reconciled_at`.

Your own shift needs `cashier_shift.manage`; someone else's, `cashier_shift.supervise`.
**At the till**: opening a shift offers an optional opening-cash field; with
`finance`, closing requires a blind count and records the reconciliation; without
it, the till closes exactly as in Phase 9.

## 34–37. The ledger

`finance_entries`: `uuid · branch_id · direction (in|out) · kind · amount_minor
(always positive) · currency · method · provider? · source_type · source_uuid ·
cashier_shift_id? · label · occurred_at · created_at` — no `updated_at`.
`unique(source_type, source_uuid, kind)`, `index(branch_id, occurred_at)`.

### 35. Kinds
| Kind | Direction | Written when |
|---|---|---|
| `collection` | in | a payment **succeeds** (desk, or verified gateway) |
| `refund` | out | a refund **succeeds** |
| `expense` | out | an expense is posted |
| `expense_reversal` | in | an expense is voided |

**Never** for an invoice being issued (billed is not received), a pending payment,
a failed or cancelled payment, or a pending or failed refund.

### 36. How entries are written
Payments raises `PaymentSucceeded` / `RefundSucceeded` (identifiers only)
**synchronously, inside its own transaction**; `RecordMoneyMovements` appends the
entry in that same transaction. Payments never imports Finance. If the ledger
write fails, the payment rolls back with it — no money is reported without its
entry (`FinanceLedgerTest`). No model observer is involved.

### 37. One writer, append-only
`Ledger::append` is the only writer (architecture scan): it throws outside a
transaction, refuses a non-positive amount, and is idempotent per source — a
second append for the same source and kind returns the first, and the unique
index backs that against a race. `FinanceEntry` and `ShiftReconciliation` use
`AppendOnlyRecord`: update and delete throw. A mistake is corrected by a new
entry, never by editing one; no builder write to either table exists anywhere.

## 38. Expense categories

`expense_categories`: `uuid · name (JSON, Translatable) · sort_order · archived_at?`.
Center-wide, defined by the center, **archived, never deleted** — posted expenses
keep their category. Listing, creating, renaming and archiving need
`expense.manage`; creating and changing also need `finance`.

## 39–41. Expenses

`expenses`: `uuid · branch_id · expense_category_id · amount_minor · currency ·
occurred_at · method (cash|manual_electronic) · reference? · payee_label? ·
description (3–500) · cashier_shift_id? · status (posted|voided) ·
idempotency_token? · created_by · voided_at? · voided_by · void_reason?`.

- **Posted** with its `expense` ledger entry in one transaction. Never in the
  future (5-minute tolerance); never through a gateway.
- **§40 Voided, never edited or deleted**: who, when and a reason (3–190), and an
  `expense_reversal` entry. The reversal carries the drawer only while that shift
  is still open — a closed shift's count is a snapshot and is not rewritten. A
  repeated void returns the same outcome.
- **§41 "Paid from the drawer" is said, not inferred.** `from_drawer: true` links
  a CASH expense to the poster's OWN open shift at that branch (refused otherwise).
  A rent transfer made while a cashier happens to have a shift open must not make
  that drawer look short.
- Branch-scoped: posting at a branch you may not work in is refused.

## 42–44. The dashboard

`FinanceDashboard::summary(viewer, from, until, branch?)` over at most **92
branch-local days**, across the branches the viewer may see (or one):

| Figure | Meaning | Source |
|---|---|---|
| `invoiced_minor`, `invoice_count` | invoices issued in the window, sale not voided | invoices |
| `voided_minor` | invoices whose sale was voided — shown apart, never billed | invoices |
| `collected_minor` (+ by method) | payments that succeeded | ledger |
| `refunded_minor` (+ by method) | refunds that succeeded | ledger |
| `expenses_minor`, `expense_reversals_minor`, `net_expenses_minor` | expenses posted, net of voids | ledger |
| `net_movement_minor` | collected − refunded − net expenses — **money, not revenue** | derived |
| `outstanding_minor` | still owed on the live invoices billed in the window | `InvoiceSettlement` |
| `by_branch`, `variances`, `variance_total_minor` | per-branch figures; the 50 latest drawer counts | ledger, reconciliations |

- **§43** Five separate numbers, never one. Nothing is called revenue or profit
  (architecture scan): there is no cost of goods, no tax engine and no fee
  accounting. The audit log is never read for money.
- **§44** Bounded: four aggregate queries whatever the volume (a test pins "does
  not grow"). Days are branch-local; across branches, the main branch's timezone.
- Needs `finance` and `finance.view`.

## Permissions (Phase 10, both documents)

| Code | Allows | Manager | Cashier | Host | Employee |
|---|---|:-:|:-:|:-:|:-:|
| `payment.view` | an invoice's payments, refunds, settlement | ✓ | ✓ | | |
| `payment.collect` | take cash/transfer/online payment; refresh or cancel a pending one | ✓ | ✓ | | |
| `payment.refund` | refund a payment | ✓ | | | |
| `payment.gateway.manage` | configure, enable, disable a branch's gateway accounts | ✓ | | | |
| `finance.view` | the dashboard and the ledger | ✓ | | | |
| `expense.manage` | categories, post and void expenses | ✓ | | | |

Owner holds everything as explicit grants; there is no Owner bypass. Counting a
drawer reuses `cashier_shift.manage` / `cashier_shift.supervise` — no new code was
needed. Catalog: 71 → 77. **Run `metastyle:roles:sync --all` on deploy.**

## 49–50. Audit

Category `finance`, written inside the transaction that makes the change:
`finance.expense.posted` · `finance.expense.voided` (`warning`, with the reason) ·
`finance.expense_category.created` / `updated` / `archived` ·
`cashier_shift.reconciled` (expected, counted and variance; `warning` when they
differ). Amounts live in the records; the audit log answers who and when.

## 56. Never customer-facing

No finance figure, entry, expense or count appears on any public page or public
API. Presenters are staff allow-lists with no numeric ids.

## 57. API (`/api/v1/tenant`)

| Method | Path | |
|---|---|---|
| GET | `finance/dashboard` | `from`, `until`, `branch?` |
| GET | `finance/ledger` | `branch`, `from`, `until` (≤ 100 entries) |
| GET · POST | `finance/expense-categories` | `name{locale: text}`, `sort_order?` |
| PUT · DELETE | `finance/expense-categories/{uuid}` | rename · archive |
| GET · POST | `finance/expenses` | list (`branch`, `from`, `until`, ≤ 100) · post |
| POST | `finance/expenses/{uuid}/void` | `reason` |
| GET | `cashier-shifts/{uuid}/expected-cash` | |
| POST | `cashier-shifts/{uuid}/close-with-count` | `counted_cash_minor`, `note?` |

Error codes: `FINANCE.POLICY_VIOLATION` (422), `FINANCE.INVALID_TRANSITION` (422),
`ENTITLEMENT.NOT_AVAILABLE` (403).

## 62. Screens

`/manager/finance` (the dashboard, branch and date filters, the ledger for one
branch) and `/manager/finance/expenses` (categories, post, void). The till gains
an optional opening-cash field, and a counted close when `finance` is on.

**Phase 15 Manager.** One money workspace — Sales · Payments · Shifts ·
Overview · Expenses — with shared tabs (shown per permission) and one date
window: today, yesterday, last 7 days, this month, last month or custom, in
whole BRANCH-LOCAL days (all branches: the main branch's, and the page says
so), refused beyond 92 days as a message, never an exception from a typed
`?from=`. The **overview** adds voided invoices, refunds by method, expense
reversals and the drawer difference total, links to the Standard Reports that
carry the figures further, and reads the ledger in the viewer's words: a
translated kind plus a reference resolved from the entry's source
(`FinanceQuery::references` — the invoice number of a payment or refund, the
category and description of an expense) instead of the English label stored at
the time. **Expenses** are paginated and filtered by category, method and state,
totalled per currency by `FinanceQuery::expenseTotals` (voided ones counted
apart, never in the total), and may be dated to an earlier branch-local day
(local noon of that day; a future day is refused by the Action). Categories are
renamed one field per ENABLED content language — a switched-off language keeps
its text — and an archived one can be brought back
(`ManageExpenseCategory::unarchive`). **Cashier shifts**
(`/manager/finance/shifts`): every open shift and those opened in the window,
all of them for `cashier_shift.supervise`, only your own otherwise; a
supervisor closes a colleague's shift from here, and the count stays BLIND —
the expected figure is never on screen before the drawer is counted. After a
downgrade the ledger, expenses and counts stay readable under the shared notice;
a center that never had Finance sees the upgrade state. Staff copy is
translated in `lang/{en,ar,ckb}/manager_finance.php`.

## 64. Isolation

All finance tables are in the tenant database with no `tenant_id`; none exists in
the control plane. Tested: two centers with colliding ids keep separate ledgers,
expenses, counts and dashboards; a new center starts empty; queries fail closed
with no center bound.

## 65. Boundaries

Finance reads Sales and Payments and listens to Payments' events. Sales,
Payments, Booking, Journey, Queue, Resources, Customers, Catalog, Branches and the
Kernel never import Finance. The module uses no HTTP, Livewire, Request, Auth or
Session facade. Enums are string backed.

## 69–72. Tests

`ShiftReconciliationTest` (the formula with every kind of movement, variance,
supervisor-only counts of others' drawers, the till with and without `finance`),
`ExpenseTest`, `FinanceLedgerTest` (nothing for billing/pending/failed, one entry
per source, append-only, ledger failure rolls the payment back),
`FinanceDashboardTest`, `FinanceSurfaceTest`, `FinanceIsolationTest`,
`FinanceBoundaryTest`. Date windows in tests are branch-local
(`SeedsPayments::branchToday`), never the UTC date.

## Not in Phase 10

Tax/VAT, cost of goods, profit, provider fee accounting, commissions, payroll,
bank statement import, budgets, multi-currency, financial exports and advanced
reports (a later phase), loyalty, memberships, promotions, SaaS billing.
