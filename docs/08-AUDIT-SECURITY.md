# 08 — Audit & Security

> Status: **Phase 0 (design only)**. Audit kernel implemented in Phase 2–3.

## Part A — Audit

## 1. Principle

> Every meaningful mutation is attributable to a principal, a moment, a source,
> and where relevant a reason.

"Meaningful" is a deliberate filter. Auditing every `updated_at` produces a log
nobody reads and a table nobody can query. Auditing the wrong things is how
audit systems die.

## 2. Where audit lives

| Log | Database | Contains |
|---|---|---|
| `audit_logs` | **Tenant** | Everything done inside a center, by anyone — including Meta Style support acting through impersonation. |
| `platform_audit_logs` | **Control** | Everything platform users do: tenant management, plan changes, entitlement overrides, impersonation, refunds, config. |

An impersonated action writes to **both**, joined by `correlation_id`. The
center must be able to see what support did in their account without access to
the platform log, and the platform must retain its own record independently.

## 3. Schema

```
audit_logs                     (tenant DB; identical shape in platform_audit_logs)
  id              bigint
  uuid            char(36)
  occurred_at     timestamp(3)      UTC, millisecond precision
  correlation_id  char(36)          request/job id — ties related entries together

  actor_type      varchar           platform|staff|customer|guest|system|ai|integration
  actor_id        bigint  null
  actor_label     varchar           denormalised name at time of action
  impersonator_id bigint  null      platform user acting as actor
  role_label      varchar null      role at time of action
  source          varchar           web|api|pos|whatsapp|ai|job|system|sadmin

  branch_id       bigint  null
  action          varchar           booking.appointment.cancelled
  category        varchar           booking|finance|security|customer|config|system
  severity        varchar           info|notice|warning|critical

  target_type     varchar null      morph class
  target_id       bigint  null
  target_label    varchar null      human-readable at time of action

  before          json    null      changed attributes only, redacted
  after           json    null      changed attributes only, redacted
  meta            json    null      contextual extras
  reason          text    null      required for a defined action list

  ip              varbinary(16) null
  user_agent      varchar null
  device_id       varchar null
  session_id      varchar null
```

Notes on specific columns:

- **`actor_label` / `target_label` / `role_label` are denormalised on purpose.**
  An audit entry must remain readable after the employee is deactivated, the
  service renamed, or the role deleted. Joining to live data at read time
  produces a log that rewrites history.
- **`before`/`after` hold changed attributes only**, never full rows. Full rows
  bloat the table and increase the blast radius of a log leak.
- **`occurred_at`, not `created_at`** — the action's time, not the row's.
- `correlation_id` is set by middleware from `X-Request-Id` and propagated into
  every queued job the request spawns.

## 4. What is audited

**Always:**

- Money: sale, refund, discount, price override, payment, shift close, expense.
- Bookings: create, reschedule, cancel, no-show, rule override.
- **Phase 7 operations**: `resources.*` (type and resource lifecycle, capacity
  changes, service requirement changes), `employees.availability_block.*`, and
  `journey.*` — check-in, stage started/completed/skipped, employee reassigned,
  resource swapped, handoff, journey completed/aborted, stage notes. A stage
  entry carries the BOOKED employee alongside the actual one, because an entry
  showing only the new value would not show that anything had changed.
  The audit log is never the operational source of truth: resource-use history
  lives in `journey_stage_resources`, which the product reads.
- **Phase 8 queue**: `queue.service_point.*`, `queue.display.*` (including
  `key_rotated`), `queue.ticket.*` — issued, called, recalled, skipped, held,
  resumed, transferred, cancelled, priority changed — and
  `journey.walk_in_created`, which FINGERPRINTS the customer rather than naming
  them. A display public key is never audited: an audit row is readable by more
  people than a display URL should be.
  Queue history is separate domain data: `queue_ticket_events` answers "what
  happened to this customer", and a report must never be built on a security
  record.
- **Phase 9 sales** (category `finance`): `sale.created`, `sale.discarded`,
  `sale.line_added`, `sale.line_updated`, `sale.line_removed`,
  `sale.customer_changed` (whether there is a customer — never who),
  `sale.adjustment_added` / `_removed` and `sale.price_overridden` /
  `_override_cleared` (`notice`, with before, after and the original price),
  `sale.visit_lines_added` (services a reopened checkout picked up),
  `sale.finalized`, `invoice.issued`, `invoice.link_rotated` (neither the link
  secret nor its digest is ever audited), `sale.voided` (`warning`, with the
  reason), `cashier_shift.opened` / `closed`, `catalog.product.*` and
  `sales.invoice_prefix_set`.
  `sale.finalized`, `invoice.issued`, `sale.voided`, `sale.discarded` and
  `invoice.link_rotated` are written **inside** the transaction that makes the
  change (§7), so a failed audit insert rolls the financial change back rather
  than leaving an unaudited invoice reported as issued (ADR-057).
  The sale, its lines and its immutable invoice are the financial record; the
  audit log answers "who changed what" and is never where a total is read from.
- **Phase 10 payments** (category `finance`, written inside the money's
  transaction): `payment.cash_recorded`, `payment.manual_recorded`,
  `payment.gateway_initiated` / `_succeeded` / `_failed` / `_cancelled`,
  `payment.gateway_mismatch` (category `security`, `critical` — a provider
  reported an amount, currency or reference that does not match; the payment is
  not settled), `refund.recorded` / `requested` / `succeeded` / `failed` (with the
  reason), and `payment_gateway.configured` / `enabled` / `disabled` (category
  `config` — provider and environment, never a credential value).
  **Phase 10 finance**: `finance.expense.posted`, `finance.expense.voided`
  (`warning`, with the reason), `finance.expense_category.created` / `updated` /
  `archived`, `cashier_shift.reconciled` (expected, counted, variance; `warning`
  when they differ). Never in any payload: credentials, signatures, provider
  bodies, authorization headers, invoice link secrets, payer names or account
  data. Amounts are read from payments, refunds and the ledger — never from audit
  (docs/19 §§49–55, docs/20 §§49–50).
  **Implemented in Phase 6** as `booking.appointment.{created, confirmed,
  rescheduled, cancelled, completed, no_show}` and
  `booking.appointment_note.{created, deleted}`. `before`/`after` share one
  shape (`AppointmentSnapshot`) so a reschedule is directly comparable —
  including the per-item windows, employees and price snapshots, because a move
  that also changed the stylist is only half told by the header times.
  No customer phone, email or note body reaches a row; the phone is a keyed
  fingerprint and the name is the deliberate exception, as the target label
  (ADR-042).
- Customer data: create, merge, delete, export, PII field reveal.
- Notes in `medical`, `allergy`, `complaint` categories: create, edit, delete.
- Security: login success/failure, password change, 2FA change, token issue and
  revoke, permission or role change, impersonation start/end.
- Configuration: settings, roles, templates publish, entitlement-affecting changes.
- Platform: tenant lifecycle, plan and subscription changes, overrides, support access.
- AI and automations: every mutating action requested by RAYAN or a rule.

**Never:**

- Read operations, except PII reveals and exports.
- Passwords, hashes, tokens, API keys, payment credentials — in any field.
- Free-text note BODIES. The trail records that a note was written, by whom, at
  what visibility, and how long it was. Copying the text would duplicate
  whatever sensitive thing it says into a second table with different readers
  and different retention.
- Bulk system writes with no human actor (backfills log one summary entry).

**Explicitly required reasons** (`reason` non-null, enforced in the Action):
refund, price override, booking rule override, data export, impersonation
start, entitlement override, tenant suspension, invoice void.

## 5. Append-only enforcement

Three layers, because one is not enough:

1. **Application** — `AuditLog` has no `update()`/`delete()` path; the model
   throws. Written only through `Kernel/Audit::write()`.
2. **Database privileges (the real control)** — in production, the application
   MySQL user has `INSERT, SELECT` on `audit_logs` and `platform_audit_logs`,
   and **no `UPDATE` or `DELETE`**. Retention pruning runs as a separate
   maintenance user, on a schedule, never in a web request.
3. **Hash chain, critical subset only** — entries with `severity = critical`
   (financial and security) carry `previous_hash` and `row_hash` over a
   canonical serialisation, forming a per-tenant chain. A nightly verifier
   detects tampering. Restricted to the critical subset because a full chain
   serialises all writes.

## 6. Retention

| Category | Hot (queryable) | Archive | Total |
|---|---|---|---|
| `finance` | 24 months | cold storage | 7 years (configurable per jurisdiction) |
| `security` | 24 months | cold storage | 7 years |
| `booking`, `customer`, `config` | 12 months | cold storage | 36 months |
| `system` | 3 months | — | 3 months |

Archiving moves rows to a compressed export in tenant storage and records the
archive in `tenant_operations`. Deletion of an archive is a manual, dual-approval
operation.

`audit_logs` is indexed on `(occurred_at)`, `(actor_type, actor_id, occurred_at)`,
`(target_type, target_id, occurred_at)`, `(category, occurred_at)`,
`(correlation_id)`. It will be one of the largest tables per tenant; monthly
partitioning is planned from Phase 13.

### Capability links (Phase 9, 10, 12)

Three surfaces are now reached by a bearer secret rather than a session: the
customer's invoice, paying it online, and leaving a review. All three follow the
same rule — 256 bits of CSPRNG output, SHA-256 at rest, the plaintext returned
once by the call that minted it and stored nowhere (ADR-035, ADR-058, ADR-066).

A stored secret therefore cannot be read back. "Send it again" MINTS A NEW ONE
and retires the old, which is also what a desk wants when a link went to the
wrong person. Neither the plaintext nor its digest ever reaches a log line, an
audit row, a notification payload or a presenter.

Unknown, revoked and expired collapse into ONE generic 404. Distinguishing them
confirms that a particular link existed — which, for a review link, is a visit,
which is a customer.

## 7. How audit is written

Explicitly, inside Actions:

```php
$this->audit->write(
    action:   'pos.sale.refunded',
    category: AuditCategory::Finance,
    severity: AuditSeverity::Critical,
    target:   $sale,
    before:   ['status' => 'completed'],
    after:    ['status' => 'refunded'],
    reason:   $request->reason,
);
```

**Not** via a global model observer that audits every save. Model observers
produce noise, cannot supply a reason, cannot distinguish a user cancelling a
booking from a job expiring it, and silently miss anything written with a query
builder.

A `RecordsAudit` trait may reduce boilerplate, but the call site stays in the
Action where the business meaning is known.

Audit writes are **synchronous and inside the same transaction** as the change
for `critical` severity — an audit entry that can be lost is not an audit
entry. Non-critical entries may be queued.

---

## Part B — Security

## 8. Tenant isolation is a security control

The whole of `02-TENANCY.md` is a security document. The controls that matter
most:

- Tenant is never client-supplied.
- Conflicting resolution sources → `403` + security audit event.
- Fail-closed connection guard: an unbound tenant query throws.
- The mandatory `tests/TenantIsolation` suite is a release gate.

## 9. Payment data — hard prohibitions

Meta Style **must never store**:

- card numbers (PAN), even partially beyond a provider-supplied last-4 token
- CVV / CVC
- PINs
- OTPs
- full magnetic stripe or chip data

Enforcement:

1. A CI check fails any migration or model introducing a column matching
   `card_number|pan|cvv|cvc|card_cvc|pin_code|otp_code` outside an explicit
   allow-list.
2. Payment provider adapters return **tokens and references only**.
3. Redirect/hosted-field flows are preferred so card data never touches our
   servers or our JavaScript origin.

Customer funds settle to the **center's** merchant account. Meta Style never
takes custody. This is a product decision with a large regulatory consequence
and must not be quietly changed by an integration.

**As implemented in Phase 10** (docs/19 §§19–24, 83): `PaymentsBoundaryTest`
scans every control and tenant migration for card, CVV, PIN, OTP and track-data
column names, and refuses a body, headers or signature column on
`payment_webhook_events`. No provider flow collects card data: FIB's customer
pays in the FIB app by code or link. A provider callback is believed only after
its signature is verified or its status is read back with the center's own
credentials; an amount mismatch never settles and raises a `critical` security
event.

## 10. Secrets and encryption

| Secret | Storage |
|---|---|
| App key, DB credentials, Redis, mail | Environment / secret manager. Never in the database, never in git. |
| Tenant DB passwords | Control plane, encrypted with `APP_KEY` (`db_password_encrypted`). |
| Tenant payment provider credentials | **Tenant database**, encrypted at rest, decrypted only inside the payment adapter. They are the center's property. |
| Tenant WhatsApp credentials | Same as above. |
| Signing keys for review/invoice links | Environment. Rotatable, with a grace window for in-flight links. |

Encrypted credential columns are excluded from every API resource, every export,
every audit payload, and every log by a central redaction list — not by
remembering to omit them at each call site.

Key rotation: `APP_KEY` rotation requires a re-encryption command that walks
every tenant database. It must exist before the first paying customer, not
after — it is far harder to add later.

> **Status after Phase 10: the command does not exist.** Gateway credentials
> (`payment_gateway_accounts.credentials`, `encrypted:array`) are the first
> tenant data that needs it. Until it ships, rotate only with the old key in
> `APP_PREVIOUS_KEYS`; credentials that stop decrypting fail closed rather than
> erroring (docs/19 §Operations). Gateway credentials are excluded from
> serialisation by `$hidden` and by allow-listed presenters, and never passed to
> audit at all; the central `Kernel\Audit\Redactor` is the backstop.

## 11. Transport, headers, sessions

- HTTPS only, HSTS with preload, TLS 1.2+.
- Secure, `HttpOnly`, `SameSite=Lax` session cookies; `Strict` for SADMIN.
- CSP without `unsafe-inline` for the customer-facing menu (it renders
  tenant-supplied content).
- `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `X-Frame-Options: DENY` except explicitly embeddable surfaces (queue display,
  embedded menu) which use a per-tenant `frame-ancestors` allow-list.
- CORS: allow-list per tenant domain, never `*` on authenticated endpoints.
- Session fixation prevented on privilege change; all sessions invalidated on
  password change.

## 12. Input, output, uploads

- Validation in Form Requests, always allow-listed. Never `$request->all()`
  into `fill()`; every model declares `$fillable`.
- Eloquent/query bindings only. Raw SQL requires a review comment and bound
  parameters.
- Tenant-supplied content (service descriptions, menu copy, notes) is escaped
  on output and sanitised on input where HTML is permitted (a strict allow-list
  — the electronic menu is a public page rendering tenant content, which makes
  it the primary stored-XSS target in the product).
- Uploads: MIME verified by content not extension, size-capped, stored with
  generated names outside the web root, images re-encoded to strip metadata and
  embedded payloads. Executable and SVG uploads rejected by default (SVG is a
  script vector).

## 13. Rate limiting and abuse

| Surface | Bucket |
|---|---|
| Authentication | identifier + IP, exponential backoff, lockout |
| Public booking / menu | tenant + IP, tighter limits, plus a bot check on booking submit |
| Tenant API | tenant + user + endpoint class |
| Platform API | platform user |
| Review links | token, single-use |
| WhatsApp webhook | signature verification first, then per-tenant limit |
| Payment provider callbacks | center + gateway account, 120/min; verification before any write |
| Public invoice payment | center + IP, 6/min — every attempt reaches a provider |
| Exports | per user per hour, and audited |

Public booking is the most abusable surface: it is unauthenticated, it writes,
and it can send messages. It needs its own limits from Phase 6, not Phase 14.

### 13.1 A limit is only a limit if every worker shares the bucket

Laravel counts rate limits in the cache store. Locally that is `array`, which is
per **process**; `file` is per **server**. In production with N workers, either
one gives every worker a private counter, so a published limit of "5 login
attempts per minute" actually admits 5 × N.

Nothing about this fails. The limiter still returns 200s and 429s, still passes
every test, and never logs the fact that the brute-force protection on the login
endpoint is not what it claims to be. It is the most dangerous shape a bug can
take here.

**Production must use a rate-limit backend shared across every process and
server; Redis is the recommended one** (ADR-034). Local and test runs still need
no Redis — that split is deliberate (ADR-025), and these checks are what protect
it from quietly reaching production:

- `metastyle:doctor --production` fails the deploy on a per-process or
  per-server limiter store (`12` §11);
- in production the same checks run at boot and log each failure at `critical`.

Combined with ADR-025's requirement that the cache store be taggable, Redis is
in practice the only store that satisfies both: `database` is shared but cannot
do tags, `array` can do tags but is per-process.

Meta Style's own limiters are defined in `AppServiceProvider::rateLimiters()`.
Anything inside tenant context is keyed by **tenant** as well as by user, so one
busy center cannot exhaust another's allowance.

### 13.2 Credential limits live in the Action, not on the route

`Kernel\Identity\LoginThrottle`, called by **both** `AuthenticateCustomer` and
`AuthenticateStaff`. One implementation; the customer form, the staff form and
the two API token endpoints all fill the same buckets.

A route throttle was tried first and was wrong twice over:

1. **It never ran.** A Livewire action posts to `/livewire/update`, not to the
   route that rendered the component, so `throttle:login` on `GET /login` never
   saw a single staff sign-in attempt and `throttle:login` on
   `/customer/sign-in` never saw a customer's. The route limits are still there
   as a coarse outer guard on the API endpoints; they are not the protection.
2. **It bounded the attacker, not the account.** A thousand addresses trying one
   identifier each get a full allowance apiece and never trip an IP bucket.

| Bucket | Limit |
|---|---|
| `login:{tenant}:id:min:{fingerprint}` | 5 / minute |
| `login:{tenant}:id:hour:{fingerprint}` | 20 / hour |
| `login:{tenant}:ip:min:{address}` | 10 / minute |

- **Keyed by tenant**, so one center's failed logins can neither exhaust nor be
  observed through another's allowance — even for a person who works at two
  centers under the same email.
- **The identifier is fingerprinted** (ADR-042), never stored raw: a cache key
  lands in Redis, in `KEYS *` output, in a slow log. The password is not a
  parameter of any of it.
- **Canonicalised first** — the customer's number to E.164, the staff
  identifier to lower case — so ten spellings are not ten allowances.
- **A success clears the identifier's buckets, never the address bucket.** Many
  people share one address behind a carrier NAT, and one correct guess must not
  hand an attacker a free reset.
- **The 429 is deliberately distinct from a credentials failure**, and reveals
  nothing: the bucket counts what the CALLER typed, so an identifier nobody has
  trips it at exactly the same attempt as a real one. An unknown **center key**
  is counted too, in the tenant-less scope, for the same reason — otherwise the
  limit itself would announce which center keys are real.

## 14. Signed links and QR codes

Used for review requests, invoice links, menu previews, guest booking
management, and password resets.

Rules:

1. **No PII in the URL or the QR payload.** A QR contains an opaque token or a
   signed URL, nothing else — no name, no phone, no invoice number, no
   customer id.
2. Server-side resolution: the token maps to customer, visit, invoice, services
   and employees on our side.
3. Single-purpose (a review token cannot open an invoice), expiring,
   single-use where the action is a mutation, and individually revocable.
4. Tokens are random (≥128 bits), not derived from record ids.
5. Enumeration-resistant: an invalid or expired token returns the same
   response as a valid-but-unknown one.

## 15. Exports and data protection

Every export path:

```
permission check  →  masking policy applied  →  build (queued)
   →  store in tenant storage  →  signed short-lived URL  →  audit entry
```

- Exports are tenant-isolated, permission-controlled, and always audited with
  row counts and column list.
- Field masking (`06` §6) applies identically to exports. This is the most
  commonly missed control in products like this.
- Bulk customer PII export is a distinct, higher permission than viewing
  customers.
- Export artefacts expire and are purged.

## 16. Impersonation

See `06-AUTH-ROLES-PERMISSIONS.md` §8. Security-relevant summary: reason
required, time-boxed, dual-audited, banner shown, owner notified, restricted
capability set.

## 17. Logging hygiene

Structured JSON logs carrying `request_id`, `tenant_id`, `principal`, `route`,
`status`, `duration`. Never carrying: passwords, tokens, full phone numbers,
payment credentials, request bodies of authentication endpoints, or decrypted
tenant secrets. A central redaction processor is applied to the logger, not
left to each call site.

## 18. Incident readiness (minimum, before first paying customer)

1. Per-tenant backup and a **rehearsed** single-tenant restore.
2. Token/session mass-revocation command.
3. `APP_KEY` rotation and credential re-encryption command.
4. A query that answers "what did principal X touch between T1 and T2" across
   both audit logs.
5. A documented tenant data-export-and-delete procedure.
6. Alerting on: repeated tenant-resolution conflicts, audit hash-chain
   verification failure, authentication brute force, entitlement bypass
   attempts, and migration failures.

## 19. Anti-patterns

| Anti-pattern | Why |
|---|---|
| Auditing via a global model observer | Noise, no reason, misses query-builder writes. |
| Audit rows the app user can UPDATE/DELETE | It is not an audit log. |
| Joining audit to live data for labels | Rewrites history when records change. |
| Storing full before/after rows | Bloat and a bigger leak radius. |
| PII inside a QR code | Anyone photographing the receipt gets it. |
| Sequential ids in public URLs | Enumeration of every invoice and customer. |
| Masking only in the UI | The API and exports leak it. |
| `$request->all()` into `fill()` | Mass assignment. |
| Allowing SVG uploads for logos | Stored XSS on the public menu. |
| `*` CORS on authenticated endpoints | Cross-origin token theft. |
| Deferring key rotation tooling | Unbuildable under incident pressure. |
| Trusting a webhook without signature verification | Anyone can create bookings and messages. |
