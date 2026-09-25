# 14 — Future Integrations

> Status: **Phase 0 (design only)**. Nothing here is implemented. This document
> fixes the *shape* of each integration so that later phases cannot casually
> violate the architecture.

## 1. Universal integration rules

1. **Adapters live in `Infrastructure/`** of the owning module. Business code
   depends on the module's `Contracts/` interface, never on a vendor SDK.
2. **Credentials are the tenant's property.** Provider credentials live
   encrypted in the **tenant** database, not the control plane, and are
   decrypted only inside the adapter.
3. **Every external call is timed, retried with backoff, circuit-broken, and
   logged with a correlation id.** A slow provider must not hold an HTTP worker.
4. **All outbound work is queued.** No synchronous provider call inside a
   customer-facing request unless the flow genuinely requires it (a payment
   redirect does; a WhatsApp message does not).
5. **Inbound webhooks:** verify signature → persist raw → return `200` → process
   asynchronously → idempotent by provider event id.
   *Payment callbacks (Phase 10) deliberately keep no raw body and settle
   synchronously after verification — ADR-060, docs/19 §§21–24.*
6. **Every integration is entitlement-gated and quota-metered.**
7. **No integration contains business rules.** They translate; the domain
   decides.
8. A provider outage degrades one capability. It never blocks booking, checkout,
   or the queue.

## 2. Payments (Phase 10)

> **Implemented in Phase 10 — `docs/19-PAYMENTS.md` is authoritative.** What
> follows is the Phase 0 design. Where they differ, docs/19 wins: the contract in
> §2.3 became `Payments\Contracts\PaymentProvider` (create, read notification,
> query, cancel, refund, explicit capabilities); the state machine in §2.5 became
> `pending → succeeded | failed | cancelled` with refunds as separate rows; no
> provider statement import exists; raw callbacks are not persisted (ADR-060).

### 2.1 Providers

ZainCash · FIB · Qi · FastPay — subject to account access and documentation.
Iraqi gateway documentation quality varies; treat "we can integrate provider X"
as unproven until a sandbox transaction has succeeded.

*Phase 10:* FIB has an adapter built from its published documentation, **not yet
run against its sandbox**; ZainCash, Qi and FastPay are registered as explicitly
unavailable.

### 2.2 The money rule

Customer funds settle to the **center's** merchant account. Meta Style never
takes custody, never aggregates, never holds a float. This is a product and
regulatory position, not an implementation detail, and an integration must not
quietly change it.

### 2.3 Adapter contract (illustrative)

```php
interface PaymentProvider
{
    public function code(): string;
    public function capabilities(): ProviderCapabilities;   // refund, partial,
                                                            // deposit, webhook...
    public function createCharge(ChargeRequest $r): ChargeResult;   // → redirect
                                                                    //   or token
    public function verify(string $reference): ChargeStatus;
    public function refund(RefundRequest $r): RefundResult;
    public function parseWebhook(Request $r, TenantCredentials $c): WebhookEvent;
}
```

Capability negotiation matters: not every Iraqi provider supports partial
refunds or deposits. `Payments` asks `capabilities()` and the UI hides what the
provider cannot do — rather than failing at the point of refund.

### 2.4 Hard prohibitions

Never stored, in any column, log, cache, or audit payload: PAN, CVV, PIN, OTP,
track data. Hosted fields or redirect flows are preferred so card data never
reaches our origin. CI-enforced (`08` §9).

### 2.5 States

```
initiated → pending → authorized → captured → settled
                   ↘ failed
                   ↘ expired
captured → refunded | partially_refunded
```

Reconciliation runs daily against provider statements; unmatched transactions
raise an operational alert rather than being auto-resolved.

## 3. WhatsApp (Phase 14)

### 3.1 Route decision (open)

| Option | Pros | Cons |
|---|---|---|
| **Meta Cloud API direct** | No middleman, lower cost, full control | Each tenant needs its own Meta Business verification and number — heavy onboarding |
| **BSP / provider** | Simpler tenant onboarding, managed templates | Cost per message, vendor lock-in, less control |

Recommendation: design the adapter interface so both are possible, pilot with
one BSP for onboarding simplicity, and keep the direct path open. Locked before
Phase 14, not now.

### 3.2 Architecture

```
WhatsApp webhook
  → verify signature
  → resolve tenant (by receiving number → tenant mapping)
  → persist raw message
  → 200 OK
  → queue: ProcessInboundMessage
       ├─ conversation state machine (per customer, per tenant)
       ├─ intent → BookingEngine / Packages / Invoices contracts
       └─ reply via template renderer, in the recipient's locale
```

**The rule:** a code search for availability, slot, or booking-rule logic inside
`Modules/WhatsApp` must return nothing. The bot builds an `Actor` and calls
`BookingEngine`. Same validation, same policies, same audit, same entitlements.

### 3.3 Templates and handoff

Message templates are tenant-customisable, localised per recipient (`07` §9),
and rendered by `Kernel/Templates`. Meta template approval lead times must be
part of tenant onboarding expectations.

Handoff: the bot can escalate to a human host, transferring conversation
context. While a human holds the conversation, the bot does not reply.

## 4. RAYAN AI (Phase 15)

### 4.1 Non-negotiable architecture

```
  user message
       │
       ▼
  ┌─────────────────────────────────────┐
  │ RAYAN — interprets intent           │   ← may read, may propose
  │ (Claude, with tool definitions)     │
  └──────────────┬──────────────────────┘
                 │  tool call (a REQUEST, not an action)
                 ▼
  ┌─────────────────────────────────────┐
  │ Tool handler                        │
  │  • builds Principal{type: ai}       │
  │  • entitlement check                │
  │  • permission check                 │
  │  • branch scope check               │
  │  • confirmation gate if destructive │
  └──────────────┬──────────────────────┘
                 │
                 ▼
  ┌─────────────────────────────────────┐
  │ Domain engine (BookingEngine, POS…) │   ← validates and executes
  │ full rules, full audit              │
  └─────────────────────────────────────┘
```

**RAYAN never touches the database.** It calls the same Actions a human's HTTP
request would, through the same gates. If a manager lacks
`finance.revenue.view`, RAYAN cannot answer their revenue question — the tool
returns the same `403`.

### 4.2 Roles and scope

| Role | Can | Cannot |
|---|---|---|
| Customer AI | Book, reschedule, cancel within policy, check balances | See other customers, see staff data, see prices not offered to them |
| Host AI | Availability, queue, check-in, customer lookup | Financial reports, settings |
| Employee AI | Own schedule, own stages, own customers | Other employees' data, revenue |
| Manager AI | Operations and reports within permissions | Anything outside their permission set |
| Support AI | Ticket triage and summarisation | Tenant business data without impersonation |

The role does not grant permissions. The **acting user's** permissions apply;
the role only shapes the tool surface and the prompt.

### 4.3 Model and SDK

- Anthropic Claude via the official PHP SDK (`composer require anthropic/…`),
  called from `Modules/RayanAI/Infrastructure`.
- Default model **`claude-opus-5`** for reasoning-heavy roles (Manager, Support
  triage, "why did revenue fall this month").
- **`claude-haiku-4-5`** for cheap, high-volume, narrow tasks (intent
  classification, message routing, short customer replies) where it measurably
  holds quality.
- Adaptive thinking (`thinking: {type: "adaptive"}`); tune `output_config.effort`
  per role rather than defaulting everything to maximum.
- Streaming for anything user-facing.
- Model IDs and parameters are **verified at implementation time**, not
  copied from this document — the API surface moves faster than these docs.
- The model choice is configuration, not code. Swapping models must not require
  touching tool handlers.

### 4.4 Safety and cost

- Every mutating tool call is audited with `Principal{type: ai}`, the
  conversation id, and the prompt reference (`08` §4).
- Destructive or financial actions require explicit user confirmation in the
  conversation before execution.
- Tenant data is never used to train a model.
- `ai_requests` quota metered per tenant (`05` §8); cost per tenant is a
  dashboard metric from day one — AI is the one integration whose cost scales
  with usage in a way that can quietly destroy margin.
- Prompt injection: customer-authored text (notes, names, review text) reaching
  a prompt is data, never instruction. Tool handlers re-validate every argument
  server-side; a tool call is never trusted because the model produced it.

## 5. Queue voice (Phase 8)

Two options, decided in Phase 8:

| Option | Notes |
|---|---|
| **Pre-recorded fragments** | Number + destination fragments concatenated per language. Predictable, offline-capable, no per-announcement cost. Requires a recording session per language, including Sorani. |
| **TTS** | Flexible for arbitrary destination names. Per-call cost and latency; Sorani voice availability is the open question. |

Recommendation: pre-recorded fragments for numbers and a TTS fallback for
custom destination names, cached as audio assets in tenant storage
(`09` §2). Announcement language order is a tenant setting (`07` §9).

## 6. Printing (Phase 8–9)

Staged deliberately. **Do not build a print agent early.**

| Stage | Method | Covers |
|---|---|---|
| 1 | Browser printing with CSS `@page` (58mm, 80mm, A4) | Most tenants, zero installation |
| 2 | Network printers via ESC/POS over TCP | Fixed POS stations |
| 3 | Bluetooth via the mobile app | Mobile POS |
| 4 | Native print agent | **Only if** stages 1–3 demonstrably fail for real tenants |

All formats render through `Kernel/Templates`. RTL correctness on thermal
output is verified separately from the browser (`07` §6).

## 7. Search (post Phase 13, if needed)

Not in the plan. If multilingual full-text search across services and customers
becomes a real requirement, the constraint is recorded now: **one index per
tenant, or a mandatory tenant filter enforced at the driver level** — never an
application-level filter that a developer can forget (`02` §5).

Until then, MySQL full-text and generated columns (`07` §3.3) are sufficient.

## 8. Push notifications (Phase 14/17)

FCM (Android) and APNs (iOS). White-label apps need **per-app credentials** —
each branded app is a distinct bundle id with its own push configuration, stored
per `white_label_apps` row. This is a concrete reason the white-label metadata
table exists in the control plane from the start.

## 9. Calendar sync (unplanned)

Google/Outlook calendar sync for employees is a frequent request in this
product category. Not planned. If it arrives, it is a read-projection out of
`Booking`, one-way first. Two-way sync is a distributed-consistency problem that
should not be entered casually.

## 10. Integration risk register

| Integration | Main risk | Mitigation |
|---|---|---|
| Iraqi payment gateways | Documentation and sandbox quality; capability gaps | Capability negotiation; prove one provider end-to-end before promising four |
| WhatsApp | Per-tenant Meta verification is slow; template approval delays | BSP option; set onboarding expectations; queue and retry |
| RAYAN AI | Cost scaling; prompt injection; hallucinated actions | Quotas + per-tenant cost metrics; tools as the only action path; confirmation gates |
| Queue voice | Sorani TTS availability | Pre-recorded fragments as the primary path |
| Printing | Hardware fragmentation | Browser-first; escalate only on evidence |
| Push (white label) | Per-app credential sprawl | Modelled in `white_label_apps` from Phase 2 |
