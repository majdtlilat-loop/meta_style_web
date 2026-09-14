# 10 — API Foundation

> Status: **Phase 0 (design only)**. Implemented in Phase 3.

One API serves Meta Style Web, the staff mobile app, SADMIN, white-label apps,
the customer web experience, the WhatsApp bot, and RAYAN. There is no second
backend and no per-client API.

## 1. Three surfaces

| Surface | Prefix | Principal | Tenant resolved by |
|---|---|---|---|
| **Platform** | `/api/v1/platform/*` | Platform user | none — control plane only |
| **Tenant** | `/api/v1/tenant/*` | Staff user | host or tenant-bound token |
| **Public** | `/api/v1/public/*` | Customer or guest | host, or tenant public key |

Each has its own route file, middleware stack, rate limits, and error surface.
A tenant endpoint is never reachable under `/public`, and the public surface
never exposes staff-only fields.

The white-label app and the customer web experience use the **same** `/public`
surface. Branding is data, not a different API.

## 2. Versioning

- URL versioning: `/api/v1/`.
- A new major version only for breaking changes. Additive changes ship in `v1`.
- Two versions supported concurrently, maximum; the old one gets a
  `Deprecation` header and a sunset date.
- Mobile apps pin a version. **The API must assume old app versions exist in
  the wild indefinitely** — an Iraqi user on a two-year-old build is normal.
  This is a stronger constraint than it appears and is why response fields are
  never removed within a major version.

## 3. Request conventions

| Header | Purpose |
|---|---|
| `Authorization: Bearer <token>` | Sanctum token |
| `Accept-Language: ar, en;q=0.8` | Locale negotiation (`07` §5) |
| `X-Request-Id` | Client-supplied correlation id; generated if absent, echoed always |
| `Idempotency-Key` | Required on mutating POSTs (§6) |
| `X-App-Version`, `X-Platform` | Client build info, for support and deprecation telemetry |

There is **no** tenant header. Ever. (`02` §2.1.)

Bodies are JSON. Field names are `snake_case`. Identifiers in requests and
responses are **uuids**, never auto-increment ids (`03` §7).

## 4. Response shape

Success:

```json
{
  "data": { "uuid": "...", "name": "Haircut" },
  "meta": { "request_id": "..." }
}
```

Collection:

```json
{
  "data": [ ... ],
  "meta": {
    "request_id": "...",
    "pagination": {
      "per_page": 25,
      "next_cursor": "eyJpZCI6MTIzfQ",
      "prev_cursor": null,
      "total": 1284
    }
  }
}
```

`total` is present only for page-based endpoints; cursor endpoints omit it
(counting is the expensive part).

Error:

```json
{
  "error": {
    "code": "BOOKING.SLOT_UNAVAILABLE",
    "message": "That time is no longer available.",
    "details": { "requested_at": "2026-09-04T17:00:00+03:00" }
  },
  "meta": { "request_id": "..." }
}
```

- `code` is **stable, machine-readable, and never localised.** Clients branch
  on it.
- `message` is localised for display.
- Validation errors add `details.fields` as `{ "field": ["message"] }`.
- Every error carries `request_id` so a user can quote it to support.

### 4.1 Error code catalog

`{DOMAIN}.{CONDITION}`, defined in one enum:

```
AUTH.INVALID_CREDENTIALS       AUTH.TOKEN_EXPIRED        AUTH.TENANT_MISMATCH
TENANT.NOT_RESOLVED            TENANT.SUSPENDED          TENANT.READ_ONLY
ENTITLEMENT.NOT_AVAILABLE      ENTITLEMENT.LIMIT_REACHED ENTITLEMENT.QUOTA_EXHAUSTED
PERMISSION.DENIED              PERMISSION.BRANCH_SCOPE
VALIDATION.FAILED              IDEMPOTENCY.CONFLICT      RATE_LIMIT.EXCEEDED
BOOKING.SLOT_UNAVAILABLE       BOOKING.OUTSIDE_HOURS     BOOKING.POLICY_VIOLATION
BOOKING.EMPLOYEE_UNAVAILABLE   BOOKING.RESOURCE_CONFLICT BOOKING.DEPOSIT_REQUIRED
POS.INSUFFICIENT_PAYMENT       PAYMENT.PROVIDER_ERROR    PACKAGE.NO_SESSIONS_LEFT
```

`ENTITLEMENT.NOT_AVAILABLE` includes the missing key in `details` so clients can
render a specific upgrade prompt rather than a generic error.

### 4.2 Status codes

`200` ok · `201` created · `202` accepted (queued) · `204` no content ·
`400` malformed · `401` unauthenticated · `403` unauthorized / entitlement /
tenant-suspended · `404` not found · `409` conflict (idempotency, version) ·
`422` validation · `429` rate limited (+ `Retry-After`) · `503` maintenance.

A resource in another tenant returns `404`, not `403` — existence is not
disclosed across tenants.

## 5. Pagination

- **Cursor** for anything large, append-heavy, or infinite-scrolled: bookings,
  audit logs, customers, sales, notifications, queue history. Stable under
  concurrent inserts.
- **Page-based** for bounded admin tables where a page count is genuinely
  useful: branches, services, employees, roles.
- Default 25, maximum 100.
- Sorting is an allow-list per endpoint. Never accept an arbitrary column name.

## 6. Idempotency

Mandatory `Idempotency-Key` on every POST that creates money or commitments:
bookings, sales, payments, refunds, invoices, package redemptions, message
sends.

```
idempotency_keys (tenant DB)
  key, endpoint, request_hash, status, response_code, response_body,
  locked_at, created_at, expires_at (24h)
```

- Same key + same request hash → the stored response is replayed.
- Same key + **different** request hash → `409 IDEMPOTENCY.CONFLICT`.
- In-flight → `409` with `Retry-After`.

**Implemented in Phase 6** as `Kernel\Http\Idempotency` plus the
`EnsureIdempotency` middleware, applied to booking creation and rescheduling on
every surface. The unique index on `(endpoint, key)` IS the concurrency control:
two simultaneous retries both try to insert and the database lets one win.

Only 2xx responses are stored. A failed request releases its key, so a client
that fixes the problem can retry with the same one — storing the failure would
answer that corrected retry with `IDEMPOTENCY.CONFLICT`, which is the failure
mode where the safety mechanism becomes the outage.

A browser cannot send a header, so the public booking form carries the key in a
hidden field and calls the same service. One implementation, two entry points.

This is not optional polish. Mobile clients on unreliable connections retry;
WhatsApp webhooks are delivered at-least-once; RAYAN retries on timeout.
Without idempotency, every one of those becomes a double booking or a double
charge. It ships in Phase 3, before any of those channels exist.

## 7. Concurrency

- `updated_at` (or a `version` integer) is returned on mutable resources and
  may be sent back as `If-Unmodified-Since` / a `version` field; a mismatch is
  `409`. Prevents two hosts silently overwriting each other's edits to the same
  appointment.
- Booking slot allocation uses a Redis lock plus a database uniqueness
  constraint. The lock is an optimisation; **the constraint is the guarantee.**
- Invoice numbering uses a per-branch locked sequence — gapless and never
  duplicated, even under concurrent checkout.

## 8. Time

| Rule | |
|---|---|
| Storage | UTC, always. |
| Transport | ISO-8601 **with offset**: `2026-09-04T17:00:00+03:00`. |
| Business logic | Computed in the **branch** timezone, not the tenant's, not the server's, not the user's. |
| Timezone source | `branches.timezone` (IANA). |
| Date-only values | Plain `YYYY-MM-DD`, never a midnight timestamp. |

A tenant with branches in two timezones is a real case and must work on day
one. "Today's bookings" is a per-branch question. This is the single most
common source of subtle bugs in booking systems, which is why branch timezone
is in the schema from Phase 4 rather than added later.

**Implemented in Phase 6** as `Kernel\Time\BranchClock` and
`Kernel\Time\TimeWindow`.

- A local time that does NOT EXIST — 02:30 on a spring-forward morning — returns
  null rather than being silently shifted forward, so no slot is ever offered at
  a time that did not happen.
- An AMBIGUOUS local time — 01:30 on a fall-back morning, which occurs twice —
  resolves to the first occurrence, consistently and by decision.
- Scheduled instants are stored as `DATETIME`, not `TIMESTAMP`: MySQL converts a
  TIMESTAMP through the session timezone, which is a second opinion about a
  question already answered, and it changes its mind if the server's timezone
  does (ADR-046). Nullable lifecycle stamps stay TIMESTAMP.

## 9. Money

```json
{ "amount": 25000, "currency": "IQD", "formatted": "25,000 د.ع" }
```

- `amount` is an **integer in minor units**. IQD exponent is **0**, so 25000
  IQD is `25000`, not `2500000`. USD exponent is 2.
- The exponent comes from `Kernel/Money`. Never assume 2.
- `formatted` is a display convenience; clients must never parse it back.
- Never a float, in JSON or in the database.

**Implemented in Phase 4** as `Kernel\Money\Money` and `Kernel\Money\Currency`.

- `Money::fromMinor(25000, Currency::IQD)` is the primary constructor.
- `Money::fromMajorString('25.10', Currency::USD)` parses form input **as a
  string**. Never through a float: `25.10 * 100` is 2509.9999999999995, and the
  cast to int truncates it to one cent less than the customer typed.
- More decimal places than the currency has is an error, not a rounding. IQD
  rejects `25000.50` outright.
- Adding two currencies throws. Conversion needs a rate, and a rate is a
  decision somebody has to make.

**Currency is a center-level setting**, not a column on each priced row. One
center has one price list in one currency; a per-row currency would permit a
menu silently mixing IQD and USD.

## 10. Middleware stacks

```
platform:  request-id → auth:platform → 2fa → rate-limit → audit-context
tenant:    request-id → resolve-tenant → bind-tenant → access-level
           → auth:tenant → locale → rate-limit → entitlement → audit-context
public:    request-id → resolve-tenant → bind-tenant → access-level
           → optional-auth:customer → locale → strict-rate-limit → audit-context
```

`access-level` converts a suspended or cancelled subscription into a
`403 TENANT.SUSPENDED` or a read-only mode that rejects all writes (`05` §5.1).

## 11. Webhooks

**Inbound** (payment providers, WhatsApp): signature verified **before** any
parsing, tenant resolved from the endpoint path or provider account mapping,
persisted raw, then processed asynchronously. Always `200` quickly; never do
business work inside the webhook request. Replay-protected by provider event id.

**Outbound** (tenant integrations, deferred to post-Phase 13): reserved at
`/api/v1/tenant/webhooks`. Design constraints recorded now: signed payloads,
exponential retry with a dead-letter, per-tenant secret, and a delivery log.

## 12. OpenAPI

An OpenAPI 3.1 document is maintained per surface and is the contract Flutter
clients generate from. Contract tests assert that responses match the schema,
so the spec cannot drift from the implementation. The spec is written alongside
each endpoint, not reverse-engineered at the end.

## 13. Realtime (Phase 8+)

The queue display, host dashboard and POS need live updates. Laravel
broadcasting over Redis, with:

- Channels namespaced `tenant.{uuid}.branch.{id}.queue`.
- Channel authorisation re-checks the bound tenant, the permission, **and** the
  branch scope — a channel name is client-supplied and must never be trusted.
- The queue display authenticates with a **device token** (a long-lived,
  revocable, display-only credential), not a staff account. A TV in a waiting
  room is not a trusted device.
- Polling fallback for TVs and Android boxes with unreliable WebSocket support.

## 14. Anti-patterns

| Anti-pattern | Why |
|---|---|
| A tenant id header or body field | Forgeable; see `02` §2.1. |
| Exposing auto-increment ids | Enumeration and business-volume disclosure. |
| Localised error `code` values | Clients cannot branch on them. |
| Removing a response field within a major version | Breaks old mobile builds that will never update. |
| Naive timestamps without an offset | Wrong-day bookings across timezones. |
| Float money, or assuming 2 decimals | 100× errors on IQD. |
| Skipping idempotency until the mobile app ships | Retrofitting it after double bookings is far harder. |
| Business work inside a webhook request | Provider timeouts and duplicate processing. |
| `403` for a record in another tenant | Confirms the record exists. |
| Arbitrary `sort` / `filter` column names from the client | Injection and index-less scans. |
| A separate API for the white-label app | The thing the product exists to avoid. |
| Trusting a broadcast channel name | Cross-tenant realtime leak. |
