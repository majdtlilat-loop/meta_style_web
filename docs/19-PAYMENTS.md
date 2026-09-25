# 19 — Payments, Payment Gateways & Refunds

> Status: **Implemented (Phase 10).** Section numbers follow the Phase 10
> specification — one numbering shared with `docs/20-FINANCE.md` — so a `§N` in
> code resolves in one of the two.
>
> Decisions: ADR-058 (a payment is one attempt; reservation; refunds never
> reopen), ADR-059 (the ledger reacts to money in the same transaction),
> ADR-060 (provider honesty: FIB from its documentation, callbacks as pointers,
> no raw webhook bodies).

## 1. What Payments is — and is not

Payments records **money collected against an issued invoice**, and money
returned against a payment. The **center is the merchant**: every online payment
goes to the center's own merchant account at the provider, configured with the
center's own credentials. Meta Style never receives, pools or holds customer
funds, keeps no center balance, is never merchant of record, has no platform
wallet, pays nobody out and takes no commission. An architecture test scans for
those concepts.

| Concept | Answers | Lives in |
|---|---|---|
| Sale / Invoice | what was charged / published | Sales — `sales`, `invoices` |
| **Payment** | one attempt to settle one invoice | `payments` |
| **Refund** | money returned against one payment | `refunds` |
| **Gateway account** | the center's merchant account at a provider, per branch | `payment_gateway_accounts` |
| **Webhook event** | that a callback arrived, and what it did — never its body | `payment_webhook_events` |
| Ledger entry | money that actually moved | Finance — `finance_entries` |

## 2. Entitlements (existing keys, unchanged package assignment)

| Key | Gates |
|---|---|
| `pos` | cash and manual-electronic collection at the desk; cash/manual refunds |
| `payments` | online (gateway) payments from the desk or the invoice link; gateway configuration; provider refunds |
| `finance` | see `docs/20-FINANCE.md` |

**Cash never needs `payments`.** The seeded trial plan owns `pos` and not
`payments`, so the cash tests run with no override — that is what makes the rule
a real assertion. Package assignment is not changed by Phase 10.

## 3. Downgrade

Losing an entitlement blocks the **next** operation and never erases or hides
history (the Booking rule, docs/05 §6.2):

- Reading payments, refunds and settlement needs `payment.view` only — no key.
- Resolving a payment already moving (refresh, cancel) needs no key.
- **A webhook is never refused because the center downgraded** after the payment
  started (§23). Disabling a gateway account is allowed without `payments` — it
  is a safety action.

## 4. Module boundaries

`app/Modules/Payments` reads Sales (invoice, sale, cashier shift). **Sales never
imports Payments** (architecture test), nor does Booking, Journey, Queue,
Resources, Customers, Catalog, Branches or the Kernel. **Payments never imports
Finance**: the ledger hears about money through `PaymentSucceeded` and
`RefundSucceeded` (docs/20 §36).

The till (`PointOfSale`) and the sales list EMBED the `InvoicePayments` component
and import nothing from Payments themselves; `SalesController` answers no money
route. Both are tested.

### 4.1 Voiding a sale with money on it

Sales publishes a neutral contract, `Sales\Contracts\SaleVoidGuard`, and runs
every implementation tagged `sales.void_guards` inside `CloseSale::void`'s
transaction, with the sale already locked. `PaymentsVoidGuard` refuses while a
gateway payment is pending, while a refund is pending, or while money is
collected net of refunds (§27). It never refunds or cancels by itself. No model
observer is involved (§25).

## 5–7. Payment

`uuid · invoice_id · branch_id · amount_minor · currency · method · status ·
source · manual_method_label? · manual_reference? · gateway_account_id? ·
provider? · provider_payment_reference? · provider_display_code? · checkout_url? ·
expires_at? · cashier_shift_id? · idempotency_token? · collected_by id/label ·
initiated_at · succeeded_at? · failed_at? · cancelled_at? · failure_code?`

One payment = **one attempt** against **one invoice**. A split bill is several
payments. `unique(gateway_account_id, provider_payment_reference)`.

```
pending ──▶ succeeded
   ├─────▶ failed
   └─────▶ cancelled
```

Desk payments are created `succeeded`. Only gateway payments are ever `pending`.
Final states never move again (`PaymentStatus::allowedTransitions`).

## 8. Methods

| Method | Moves the drawer | Needs |
|---|:-:|---|
| `cash` | ✓ | `pos`, the collector's open shift at the branch |
| `manual_electronic` | | `pos`, a label ("FIB transfer") — staff-confirmed (§14) |
| `gateway` | | `payments`, an enabled configured account at the invoice's branch |

## 9. Settlement state, and why refunds never reopen

`unpaid | partial | paid` comes from **gross succeeded** payments. A refund is
money leaving the center, not the customer owing again: a paid invoice with a
refund stays `paid`, and its **net collected** goes down. Nothing reopens.

## 10–11. Reservation and locks

```
available = invoice total − succeeded − pending      (0 for a voided sale)
```

A **pending gateway payment reserves its amount**. With 20,000 left, a pending
online payment of 20,000 leaves 0 for the cashier, so the provider's later "paid"
can never collect twice.

**Lock order, everywhere:** sale `FOR UPDATE`, then invoice `FOR UPDATE`
(`InvoicePaymentLock`). The void path locks the sale, then the guard locks the
invoice. A refund locks the payment row. Cash additionally locks the collector's
shift — the same lock a counted close takes, so no cash moves between computing
what the drawer should hold and closing it (docs/20 §33).

## 12–14. Taking money at the desk

`CollectDeskPayment`:

```
BEGIN
  lock sale → invoice;  voided? refuse
  available (InvoiceSettlement, under the lock);  amount ≤ available
  cash → lock the collector's open shift
  write the payment, succeeded
  PaymentSucceeded → Finance writes the ledger collection   (same transaction)
  audit payment.cash_recorded | payment.manual_recorded
COMMIT
```

Idempotent: a repeated token returns the payment it created; the same token for
different money is refused. **Manual electronic is staff-confirmed** — it records
what the desk checked and claims no provider verification (§14).

## 15–18. Providers

`Payments\Contracts\PaymentProvider`: `code · displayName · capabilities ·
credentialFields · createPayment · readNotification · queryPayment ·
cancelPayment · refund`. `ProviderCapabilities` says, explicitly: available,
cancellation, refunds, whether a callback needs a status query, environments,
currencies. The UI offers only what the adapter declares.

| Provider | Status |
|---|---|
| **FIB** (First Iraqi Bank) | Real adapter, `Infrastructure/Providers/FibProvider` |
| ZainCash · Qi · FastPay | `UnsupportedProvider` — registered, `available: false`, every operation refuses |

### 16. FIB

Built from FIB's official web-payments documentation and FIB's own PHP SDK, both
read 2026-09-14 and in agreement on everything used:

- token: `POST {base}/auth/realms/fib-online-shop/protocol/openid-connect/token`
  (form: `grant_type=client_credentials`, `client_id`, `client_secret`)
- create: `POST {base}/protected/v1/payments` with `monetaryValue{amount, currency}`,
  `statusCallbackUrl`, `description` (≤ 50) → `paymentId`, `readableCode`,
  `personalAppLink`, `validUntil`
- status: `GET …/payments/{id}/status` → `PAID | UNPAID | DECLINED` (+ `decliningReason`)
- cancel: `POST …/payments/{id}/cancel` → 204

**Refund: unsupported.** The public documentation has no refund operation; an
endpoint only an SDK calls is not a contract. **Callbacks are pointers**: FIB's
callback carries nothing that proves FIB sent it, so the status is always read
back with the center's credentials and only that answer can settle (§22).
IQD only. Sandbox host `https://fib.stage.fib.iq`; the live host is an operator
setting, `FIB_LIVE_BASE_URL`, and a live account cannot be configured until it is
set.

**Not yet proven: no sandbox transaction has been run.** Every request and
response is pinned by contract tests (`FibProviderTest`, `Http::fake`) built from
the documented examples.

### 17–18. No invented integrations

ZainCash, Qi and FastPay stay unsupported until their documentation and sandbox
access are verified (pending decision P-09). Adding one means an adapter, its
contract tests, and — if it needs a package — a `docs/DECISIONS.md` entry BEFORE
installation.

## 19. Credentials

Per branch, per provider (`unique(branch_id, provider)`). Stored in the **tenant**
database, cast `encrypted:array` (APP_KEY), `$hidden`, never returned by any API,
Livewire state, presenter, log or audit row. `ManageGatewayAccount` keeps only the
fields the adapter declares; the only thing shown back is a masked
`safe_identifier` (`••••` + last 4 of `client_id`/`merchant_id`). Updating means
typing new values — there is no "show secret". The settings screen clears typed
secrets in a `finally`, success or failure. Credentials are never hashed: they
must be sent to the provider.

## 20. No tenant-chosen destinations

Provider hosts come from `config/payments.php`, https only. No credential field
may be a URL, host or endpoint (runtime test over the registry), and no adapter
reads one from credentials (architecture scan). A center cannot point the
platform's outbound requests — carrying its secrets — anywhere.

HTTP to providers: 10 s timeout, 5 s connect (`payments.http`).

## 21–24. Provider callbacks

`POST /api/v1/payments/{center}/gateways/{account}/webhook` —
`public.tenant` + `locale` + `throttle:payment-webhook` (120/min per center +
account — per ACCOUNT, not per IP, so rotating addresses buys an attacker
nothing). No staff auth, no entitlement. It is one of exactly five public-tenant
writes, each pinned with its own limiter in `PublicRouteBoundaryTest`.

```
center from its public key; account by its public uuid (enabled or not)
adapter verifies the callback          → bad signature: 400, nothing written
payment by (account, provider reference) → none: 202, nothing written
status: the verified callback, or an authenticated status query (FIB)
GatewaySettlement::apply:
  BEGIN
    claim fingerprint  sha256(provider|account|reference|state|event id)
                       insertOrIgnore on unique(gateway_account_id, fingerprint)
                       → duplicate: return, nothing else
    lock the payment;  already final → ignored
    PAID: reference, amount AND currency match? → succeeded + PaymentSucceeded
                                                → else: stays pending (§82)
    DECLINED → failed · CANCELLED → cancelled (reservation released)
    audit; mark the event processed
  COMMIT
```

- **§21 Tenant resolution** by the center's public key and the account's uuid —
  both opaque, neither enough to settle anything. An account uuid from another
  center is unknown there (400).
- **§22 Idempotent**: fingerprint claim, payment already final, and the ledger's
  unique source — three independent reasons a repeated "paid" moves nothing.
- **§23 No entitlement**: money already moving does not depend on today's plan.
- **§24 No raw body** is stored. `payment_webhook_events` holds account, provider,
  provider event id, fingerprint, event type, whether the signature was verified,
  the payment, result and a safe error code. No body, headers or signature column
  exists (architecture test).

Answers: `202 {"received": true}` whether or not the reference exists (so
references cannot be probed), `400 {"received": false}` for a rejected callback,
`503` when a status query could not reach the provider (the provider retries).

**Deviation from docs/10 §11** ("persist raw, process asynchronously"): settlement
runs synchronously inside the callback request. Rationale and limits in ADR-060.

## 25–26. Refunds

Separate rows: `uuid · payment_id · branch_id · amount_minor · currency · method ·
provider? · status (pending|succeeded|failed) · reason · provider_refund_reference? ·
cashier_shift_id? · idempotency_token? · requested_by · requested_at · succeeded_at? ·
failed_at? · failure_code?`.

```
refundable = payment − succeeded refunds − pending refunds      (payment row locked)
```

- Only succeeded payments; a reason is required (3–190).
- `cash` is paid from the **refunder's** open drawer; `manual_electronic` is a
  confirmed transfer; `gateway` uses the provider's refund API **only if the
  adapter declares one** — otherwise refused as unsupported, never recorded as if
  it happened. A cash or manual refund against an online payment is allowed.
- Provider refunds reserve (pending), call the provider outside any transaction,
  then record succeeded (with its ledger debit) or failed (none).
- A refund never touches the payment or the invoice, and never reopens it (§9).

## 27. Void coordination

See §4.1.

## 28. The settlement formula

`InvoiceSettlement` is the only implementation: `forInvoice`, `forInvoices`
(batched, three queries for any number of invoices) and
`outstandingIssuedBetween` (one aggregate query for the finance dashboard). No
other class adds payments up.

## 29–30. Paying from the invoice link

The customer's invoice page (`/i/{center}/{token}`) shows paid, pending and
remaining, and — when `payments` is on, the sale is not voided, something is left
and the invoice's branch has an enabled configured account at an available
provider — a pay button per gateway.

- The **share secret is the authority**; the **server decides the amount**: the
  full remaining balance. The customer chooses only the gateway.
- `POST /i/{center}/{token}/pay` (web, CSRF) and
  `POST /api/v1/invoices/{center}/{token}/payments` — both `throttle:public-payment`
  (6/min per center + IP), idempotency token required.
- Every refusal gets one generic answer (`PAYMENTS.POLICY_VIOLATION`,
  `invoice_public.payment_unavailable`).
- A pending online payment shows its code, the provider's link and its expiry.
- **§30 Returning from the provider proves nothing.** The page only reflects what
  a verified callback or status query already settled.

## 48. Presenting gateway accounts

`PaymentsPresenter::gatewayAccount`: uuid, provider, provider name, display name,
environment, enabled, configured, safe identifier, configured at/by, supports
refunds/cancellation. `providers()` lists credential field NAMES only.

## 49–55. Audit

Category `finance` (mismatches: `security`, `critical`). Written inside the
money's transaction.

`payment.cash_recorded` · `payment.manual_recorded` · `payment.gateway_initiated` ·
`payment.gateway_succeeded` · `payment.gateway_failed` · `payment.gateway_cancelled` ·
`payment.gateway_mismatch` · `refund.recorded` · `refund.requested` ·
`refund.succeeded` · `refund.failed` · `payment_gateway.configured` ·
`payment_gateway.enabled` · `payment_gateway.disabled`.

**Never** in a payload: credentials, signatures, raw provider bodies, authorization
headers, invoice share secrets, payer names, account or card data. The audit log is
who-did-what; amounts are read from payments and refunds, never from audit.

## 57. API (`/api/v1/tenant`)

| Method | Path | |
|---|---|---|
| GET | `invoices/{uuid}/payments` | settlement + payments |
| POST | `invoices/{uuid}/payments` | `method`, `amount_minor`, `manual_method_label?`, `manual_reference?`, `gateway_account?`, `idempotency_token?` |
| GET | `payments/{uuid}` | with refunds |
| POST | `payments/{uuid}/refresh` · `payments/{uuid}/cancel` | resolve a pending gateway payment |
| POST | `payments/{uuid}/refunds` | `method`, `amount_minor`, `reason`, `idempotency_token?` |
| GET | `refunds/{uuid}` | |
| GET | `branches/{uuid}/payment-gateways` | accounts + providers |
| PUT | `branches/{uuid}/payment-gateways/{provider}` | `environment`, `display_name`, `credentials{}` |
| POST | `payment-gateways/{uuid}/enable` · `…/disable` | |

Error codes: `PAYMENTS.INVALID_TRANSITION` (422), `PAYMENTS.POLICY_VIOLATION`
(422), `PAYMENTS.PROVIDER_UNSUPPORTED` (422), `PAYMENTS.PROVIDER_UNAVAILABLE` (503),
`ENTITLEMENT.NOT_AVAILABLE` (403). Integer minor units only; a fraction fails
validation. No numeric id is ever presented.

## 58. Public API

`GET /api/v1/invoices/{center}/{token}/payment` — allow-list: `state`, `voided`,
`paid`, `pending`, `remaining`, `online_options[{gateway, name}]`,
`pending_online{code, link, expires_at}`. Never a payment uuid, a cashier, an id,
a failure message, a manual transfer label or reference, or a refund reason.

## 59–61. Screens

- **Invoice money panel** (`Center\InvoicePayments`, embedded in the till and the
  sales list): total, paid, pending, remaining; cash, transfer and online buttons;
  refresh/cancel for pending online payments; refunds. Amounts typed by a person are
  parsed with `Money::fromMajorString`; the panel does no arithmetic. It acts only
  on payments of its own invoice.
- **Online payments** (`/manager/payments/gateways`): configure, enable, disable.
- **Public invoice page**: the payment section in `sales/invoice-public`,
  translated in AR, EN and CKB (`invoice_public.*`) and RTL-safe.

**Phase 15 Manager.** The panel shows the settlement state (unpaid · partly
paid · paid · voided), a pending online payment's code, checkout link and
branch-local expiry, and "use what is left", which writes the server's
remaining figure back as text (`Money::toMajorString`, a parse in reverse).
Choosing a payment to refund prefills what it can still give back —
`InvoiceSettlement::refundableOf()`, from its loaded refunds; `RequestRefund`
still decides under the payment lock — and offers only the ways back the
entitlements and the provider allow. The desk and the public page share ONE
gateway filter, `UsableGateways::forInvoice()` (enabled, configured, available,
the account's environment, the invoice currency); the desk used to skip the
last two. **Payments and refunds** (`/manager/finance/receipts`, route
`center.payments`, `Center\Receipts`,
`payment.view`, history readable after a downgrade; only a center with neither
`pos` nor `payments` that never took a payment sees the upgrade state —
`PaymentsQuery::hasHistory()`): payments started and refunds
requested at a branch by branch-local day, filtered by method, state and
invoice number, paginated (`PaymentsQuery::receipts` / `refundsIn`, indexes
`payments(branch_id, initiated_at)` and the new `refunds(branch_id,
requested_at)`); the totals are what SUCCEEDED in the window, summed per
currency by `PaymentsQuery::receiptTotals` — a pending online payment is shown
apart and is not money received. **Gateways** gains an "update credentials"
flow (provider, environment and name prefilled; secrets never), clears typed
secrets whenever the provider, environment or branch changes, shows the
upgrade state when `payments` is missing and nothing was configured, and keeps
existing accounts readable (and switchable off) after a downgrade. Staff copy
is translated in `lang/{en,ar,ckb}/manager_pos.php`; an Action's English
refusal is translated at the Livewire boundary (`PosFinance\Refusals`) and
stays exactly the Action's sentence in English. **Callback address:** since
Phase 15 `public.tenant` resolves a center only on its own host with that
host's slug in the path, so `GatewayConnections::callbackUrl()` publishes
`{slug}.<base>/api/v1/payments/{slug}/gateways/{account}/webhook` when the
payment starts on the center's host (the till or the invoice page); an
off-host API client still gets the public-key form, which no longer resolves
— a Kernel slug read on `TenantContext` would close that (proposed).

## 63. Query budgets

Asserted as "does not grow": settling, listing and presenting an invoice's
payments (2 vs 8 payments with refunds), settling a page of invoices (1 vs 6), and
rendering the desk panel (2 vs 8).

## 64. Isolation

All payments tables are in the tenant database with no `tenant_id`, and none
exists in the control plane. Tested: two centers with the same provider reference
and colliding ids — a callback settles only the named center's payment; one
center's account uuid under another center's key is refused and changes neither;
an invoice token does not resolve for payment in another center; queries fail
closed with no center bound.

## 65–68. Concurrency and callback security tests

Against a lock held by a genuinely separate MySQL connection, with the real Action
under a one-second lock timeout: a second desk collecting the last balance waits,
then is refused; a second refund on one payment waits and cannot exceed it; a cash
collection waits behind a shift close. Callback tests: wrong or missing signature,
unknown account, unknown reference answered like a known one, completion after a
downgrade and a disabled account, no secret or body in any table or log, fail
closed on an unknown center.

## 73–74. Public privacy tests

The public JSON key list is compared exactly; staff names, ids, manual transfer
details and failure messages are asserted absent from the page and the JSON.

## 81–82. Amounts from providers

`ProviderAmount::toMinor` accepts an integer or an exact decimal string in the
currency's exponent (excess zeros allowed) and **refuses floats** and anything
inexact. A "paid" whose amount, currency or reference does not match the payment
**does not settle**: the payment stays pending with its reservation held,
`payment.gateway_mismatch` is written as a critical security event, and a person
resolves it (refresh or cancel).

## 83–84. Prohibited data and generic answers

No card number, CVV, PIN, OTP or track data column exists in any migration
(architecture test over every control and tenant migration). Public and callback
endpoints answer as little as possible: one generic payment refusal, one
`received` flag.

## Operations

- Deploy runs `metastyle:tenant:migrate --all` (nine `2026_09_14_*` tenant
  migrations) and `metastyle:roles:sync --all` (six new permissions).
- `FIB_LIVE_BASE_URL` must be set before any center can configure a live FIB account.
- **APP_KEY rotation.** Rotate with the old key in `APP_PREVIOUS_KEYS`
  (`config/app.php`), which Laravel's encrypter falls back to, so stored gateway
  credentials stay readable. The re-encryption command docs/08 §10 requires does
  **not** exist yet, so a previous key cannot be retired. If credentials ever stop
  decrypting, the account **fails closed**: `isConfigured()` is false, the invoice
  page and settings still render without a pay option, callbacks for it answer
  400 and staff actions refuse with "not configured" — never a 500. A manager
  re-entering the credentials recovers it (`GatewayAccountTest`).

## Known limits

- A provider refund whose process dies between the provider call and recording
  its answer stays `pending` (holding its amount); there is no automated recovery
  job yet.
- A provider create that times out after FIB actually created the payment is
  recorded `failed` locally and its reservation released; the customer never saw
  a code, but the provider-side payment exists until it expires.
- The public invoice URL carries its secret in the path, so web-server access
  logs must be treated as sensitive (or configured not to record `/i/` paths).

## Not in Phase 10

Cards/terminals integrations, deposits and prepayment before an invoice, tips,
installments, currency conversion, provider fee accounting, payouts, settlement
statement import, loyalty, memberships, promotions, gift cards, SaaS billing.
