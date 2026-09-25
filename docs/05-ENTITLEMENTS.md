# 05 — Feature Entitlements

> **Phase 8 began enforcing the queue keys.** `queue_management` gates every
> queue Action and ticket issuance, `queue_display` the public feed and display
> page, `queue_voice` whether a screen announces. Walk-in VISITS are gated on
> `booking`, so Booking and Journey keep working with no queue entitlement at
> all — a center without it simply hands nobody a number
> (`docs/17-QUEUE.md` §19).
>
> **Phase 9 locked the commerce keys.** `pos` owns sales, checkout, invoice
> issuing, products and cashier shifts — new operations; invoices already issued
> stay readable without it (§6.2); `printing` owns only the
> 80mm/A4 paper surfaces. **There is no `invoices` key** — issuing an invoice is
> a consequence of a POS sale, not a product of its own. Phase 9 changed no
> package (docs/18-SALES.md §57).
>
> **Phase 10 used those keys, unchanged.** `pos` also covers taking cash and
> staff-confirmed transfers against an invoice (cash never needs `payments`);
> `payments` covers online gateway payments, gateway configuration and provider
> refunds; `finance` covers expenses, counted shift closes and the dashboard. The
> ledger is written regardless — it records money, it is not the product. A
> provider callback is never refused because a center downgraded after the
> payment started. Phase 10 changed no package (docs/19 §§2–3, docs/20 §3).
>
> **No seeded plan sells the queue keys.** Defining a capability and pricing it are two
> decisions; a phase owns the first and never the second. Until the package work
> says otherwise, the queue is bought as a per-tenant add-on — see §9.

> Status: **Engine implemented (Phase 3).** Catalog, plan grants, tenant
> overrides, dependency closure, cycle detection, versioned cache and the
> `ensure()` gate are live. Only the `boolean` type is built — limits and
> quotas (§2) arrive with the features that enforce them, because a limit
> nothing checks is a number in a table.

## 1. The rule

> **Business code asks what a tenant *can do*, never what they *bought*.**

```php
// FORBIDDEN — everywhere, always
if ($tenant->plan === 'pro') { ... }
if (in_array($plan->code, ['business', 'enterprise'])) { ... }
if ($subscription->plan_id >= 3) { ... }

// CORRECT
if ($entitlements->enabled('queue_voice')) { ... }
$entitlements->ensure('pos');            // throws EntitlementRequired
$entitlements->owns('pos');              // ownership, ignoring subscription state
```

An architecture test fails any change introducing a plan-name comparison outside
the SaaS and Entitlements layers (`11-TESTING-STRATEGY.md` §5).

**Why:** plans get renamed, split, merged, discounted, and grandfathered. Every
one of those becomes a code change if plan names are in business logic. Adding
a new plan must require **zero** changes outside a database row.

## 2. Three entitlement types

| Type | Question answered | Example | Storage of value |
|---|---|---|---|
| `boolean` | Is the feature on? | `pos`, `queue_voice` | `true` / `false` |
| `limit` | What is the ceiling? | `branches`, `employees`, `storage_mb` | integer, `-1` = unlimited |
| `quota` | How much is left this period? | `whatsapp_messages`, `ai_requests`, `sms_credits` | integer per period + counter |

**Only `boolean` is built.** Limits and quotas are documented here because the
shape of the eventual API matters for the catalog's design, but neither is
implemented: a limit nothing enforces is a number in a table, and a quota with
no consumer is a counter that never moves. They arrive with the features that
need them.

Three different APIs, three different failure modes:

```php
$e->allows('pos');                  // bool
$e->limit('branches');              // int  (-1 unlimited)
$e->withinLimit('branches', $count) // bool — checked BEFORE create
$e->quota('whatsapp_messages');     // Quota { allowed, used, remaining, resets_at }
$e->consume('whatsapp_messages', 1) // bool — atomic, false when exhausted
```

Limits are enforced **at creation time** ("you may not add a 4th branch").
Quotas are enforced **at consumption time** and reset each billing period.

## 3. Catalog

The entitlement **catalog** (keys, types, categories, dependencies) is defined
in **code**, in `config/entitlements.php`, and synced into the control-plane
`entitlements` table by a deploy-time seeder.

**Why code, not database:** a key referenced by code must exist. If the catalog
lived only in the database, a deploy could reference `queue_voice` before the
row existed, and every environment would drift. Code is the source of truth;
the table exists so the SADMIN UI and plan builder can join against it.

### 3.1 Initial catalog

```php
// config/entitlements.php  (illustrative)
'booking'                => ['type' => 'boolean', 'category' => 'core'],
'customer_accounts'      => ['type' => 'boolean', 'category' => 'core'],
'electronic_menu'        => ['type' => 'boolean', 'category' => 'core'],
'service_journey'        => ['type' => 'boolean', 'category' => 'operations'],

'queue_management'       => ['type' => 'boolean', 'category' => 'queue'],
'queue_display'          => ['type' => 'boolean', 'category' => 'queue',
                             'requires' => ['queue_management']],
'queue_voice'            => ['type' => 'boolean', 'category' => 'queue',
                             'requires' => ['queue_management']],

'pos'                    => ['type' => 'boolean', 'category' => 'commerce'],
'printing'               => ['type' => 'boolean', 'category' => 'commerce'],
'payments'               => ['type' => 'boolean', 'category' => 'commerce',
                             'requires' => ['pos']],
'finance'                => ['type' => 'boolean', 'category' => 'commerce',
                             'requires' => ['pos']],
'inventory'              => ['type' => 'boolean', 'category' => 'commerce'],

'crm'                    => ['type' => 'boolean', 'category' => 'engagement'],
'loyalty'                => ['type' => 'boolean', 'category' => 'engagement',
                             'requires' => ['customer_accounts']],
'memberships'            => ['type' => 'boolean', 'category' => 'engagement',
                             'requires' => ['pos']],
'packages'               => ['type' => 'boolean', 'category' => 'engagement',
                             'requires' => ['pos']],
'reviews'                => ['type' => 'boolean', 'category' => 'engagement'],
'marketing'              => ['type' => 'boolean', 'category' => 'engagement',
                             'requires' => ['crm']],

'reports_standard'       => ['type' => 'boolean', 'category' => 'insight'],
'reports_advanced'       => ['type' => 'boolean', 'category' => 'insight',
                             'requires' => ['reports_standard']],
'reports_ai_insights'    => ['type' => 'boolean', 'category' => 'insight',
                             'requires' => ['reports_advanced']],

'whatsapp_integration'   => ['type' => 'boolean', 'category' => 'channels'],
'whatsapp_booking'       => ['type' => 'boolean', 'category' => 'channels',
                             'requires' => ['whatsapp_integration', 'booking']],
'whatsapp_ai'            => ['type' => 'boolean', 'category' => 'channels',
                             'requires' => ['whatsapp_booking', 'rayan_ai']],
'rayan_ai'               => ['type' => 'boolean', 'category' => 'channels'],
'white_label_app'        => ['type' => 'boolean', 'category' => 'channels'],

'branches'               => ['type' => 'limit', 'default' => 1],
'employees'              => ['type' => 'limit', 'default' => 5],
'staff_accounts'         => ['type' => 'limit', 'default' => 5],
'storage_mb'             => ['type' => 'limit', 'default' => 1024],
'custom_roles'           => ['type' => 'limit', 'default' => 0],

'whatsapp_messages'      => ['type' => 'quota', 'period' => 'billing_cycle'],
'sms_credits'            => ['type' => 'quota', 'period' => 'billing_cycle'],
'ai_requests'            => ['type' => 'quota', 'period' => 'billing_cycle'],
```

Keys are `snake_case`, stable forever, and **never reused for a different
meaning**. Deprecating a key means marking it deprecated, not deleting it —
historical subscriptions still reference it.

## 4. Dependencies

`queue_voice` requires `queue_management`. `whatsapp_ai` requires
`whatsapp_booking` **and** `rayan_ai`.

Dependencies are validated in **two** places:

1. **At plan/add-on definition time** — SADMIN refuses to save a plan granting
   `queue_voice` without `queue_management`. Loud, early, fixable.
2. **At resolution time** — if a granted entitlement's dependency is missing
   (through an override, a partial refund, a botched migration), the dependent
   entitlement is **dropped from the effective set** and a warning is logged.

Resolution-time enforcement is a closure over the dependency graph, applied
repeatedly until stable, so transitive chains resolve correctly. The graph is
validated as acyclic at boot; a cycle is a fatal configuration error.

Never assume dependency-satisfaction and skip a check: code that needs
`queue_management` checks `queue_management`, even inside a `queue_voice`
branch.

## 5. Resolution

```
     plan_entitlements (from the tenant's subscription plan)
              │
              ▼  merge
     tenant_entitlement_overrides (active window only, may grant OR revoke;
                                   revoke always beats a plan grant)
              │
              ▼  apply
     trial modifiers (if status = trialing → trial entitlement set)
              │
              ▼  filter
     dependency closure (drop entitlements with unmet dependencies)
              │
              ▼  clamp
     access level (from subscription status)
              │
              ▼
     EffectiveEntitlements  (immutable, cached)
```

Later stages win over earlier ones, and a revocation beats a grant for the same
key regardless of insertion order — otherwise removing a capability from one
abusive center would mean editing the plan every other center is on.

A separate `subscription_addons` table is not built: an add-on is expressed as a
tenant override with `source = 'addon'`, which is the same row shape and one
fewer table to keep consistent. Split it out if add-ons ever need their own
billing lifecycle.

### 5.1 Access level clamp

Subscription status is not an entitlement, and it is applied last:

| Status | Effect on the resolved set |
|---|---|
| `trialing` | Replace with the configured trial set (see §7). |
| `active` | No change. |
| `past_due` | No change (grace period). |
| `suspended` | All entitlements → read-only. Writes rejected platform-wide except billing and export. |
| `cancelled` / `expired` | Export and billing only. |

This is deliberately **separate from** entitlements so that "what did they buy"
and "are they allowed to use it right now" never get tangled. A suspended
tenant on the Enterprise plan still *has* `pos`; they just can't write.

### 5.2 Caching

- Cached in Redis at `p:tenant:{id}:entitlements:v{entitlements_version}`.
- `tenants.entitlements_version` is incremented on **any** change to the
  tenant's plan, subscription, add-ons, or overrides.
- Version-stamped keys mean invalidation is a single integer increment; stale
  keys expire on their own. No cache-tag sweeping, no fan-out.
- TTL is a safety net (1 hour), not the invalidation mechanism.
- Quota **counters** are not in this cache — they are separate atomic Redis
  counters reconciled to `tenant_usage_counters` (see §8).

## 6. Enforcement points

Defence in depth. A feature is gated in **two** places, minimum.

### 6.1 Route middleware — coarse

```php
Route::middleware(['entitlement:pos'])->group(...);
Route::middleware(['entitlement:queue_voice'])->group(...);
```

Returns `403` with error code `ENTITLEMENT.NOT_AVAILABLE` and the missing key,
so the UI can show a targeted upgrade prompt.

### 6.2 Inside the Action — authoritative

```php
final class CallNextTicket
{
    public function __invoke(CallTicketRequest $r, Actor $actor): Ticket
    {
        $this->entitlements->ensure('queue_management');
        // ...
    }
}
```

**This is the one that matters.** Route middleware protects HTTP. It does
nothing for a WhatsApp webhook, a RAYAN tool call, a queued job, an artisan
command, or an internal module call — and those are exactly the paths that will
exist by Phase 14.

`ensure()` throws `EntitlementRequired`, mapped to `403` by the exception
handler.

**Phase 6 worked example — `booking`.** Enforced inside `CreateAppointment`,
`RescheduleAppointment` and `TransitionAppointment`, so a future WhatsApp bot
and RAYAN inherit the gate without touching booking code.

Two decisions worth stating, because "gate the feature" is not specific enough
to implement:

- **Mutations and availability require it; READING existing appointments does
  not.** A center that downgrades keeps its own history. Locking a center out of
  the bookings it already took would be a billing decision punishing their
  customers.
- **Phase 9 applies the same rule to `pos`.** New drafts, checkout, every draft
  change, finalization, void, shifts and products require it; READING finalized
  sales and invoices does not, the customer's existing invoice link keeps
  working, and printing needs only `printing` (docs/18-SALES.md §15a).
- **The public MENU stays viewable without it**; only the Book action
  disappears, and `/m/{center}/book` answers `404`. Not `403`: a guest has no
  billing relationship with the center, and telling them which features it has
  not bought is neither useful nor the center's wish
  (docs/13-ROADMAP.md Phase 6 §31).

### 6.3 Limits — at creation

```php
$this->entitlements->ensureWithinLimit('branches', $currentCount);
```

Checked inside `CreateBranch`, not in the controller, and not in the UI.

### 6.4 Quotas — at consumption

```php
if (! $this->entitlements->consume('whatsapp_messages', 1)) {
    throw new QuotaExhausted('whatsapp_messages');
}
```

`consume()` is atomic (Redis `INCRBY` with a ceiling check). It must be called
**before** the external side effect, never after.

### 6.5 UI and API discoverability

`GET /api/v1/tenant/me/entitlements` returns the effective set so clients can
hide unavailable features. This is **presentation only** — it is never the
enforcement mechanism. Every gated endpoint enforces server-side regardless of
what the client renders.

#### The Manager shell (upsell, plan page, banner)

`App\View\Manager\ManagerNavigation` builds the sidebar in PHP (composed into
the layout by `ManagerShellComposer`; no logic in Blade). Two questions, kept
apart:

- **Permission** missing → the item is not rendered. Nobody is advertised a
  module they could never use.
- **Entitlement** not owned (and the subscription in good standing) → the item
  is rendered LOCKED: lock icon, a label from `FeatureOffer::lockLabel()` read
  from the real catalog (`PlanOffers::lowestIncluding`, dependency closure
  applied) — "Available from Business", or "Contact us" when no public plan
  sells it. It is a plain link to `center.plan?feature=KEY` without
  `wire:navigate`; `resources/js/manager/shell.js` opens the
  `Center\Shell\UpgradePrompt` dialog instead (`[data-upgrade-feature]`). The
  prompt re-validates the key against the catalog, the navigation map and the
  viewer's permissions. Nothing is unlocked by it. It links only where the
  viewer may go: "View plans" for `settings.view`, "Contact Meta Style" for
  `platform_support.view`; otherwise it says to ask whoever manages the
  center's subscription.
- Suspended / cancelled / expired → **no locks**; one `SubscriptionBanner`
  line instead (trial days left and past-due grace for `settings.view`
  holders; suspension and expiry for everyone).

An `EntitlementRequired` that escapes on a WEB request renders
`errors.feature-locked` with status 403 (`FeatureLockedPage`, registered in
`bootstrap/app.php` after the API renderer, which keeps the JSON envelope).

`center.plan` (`App\Livewire\Center\Plan`, `settings.view`) is read-only: the
subscription as sold (snapshot name, price, currency, cycle), trial / renewal /
grace dates, a scheduled change, owned features by category ("added" = an
override beyond the plan), enforced allowances, and a comparison with the
public plans (`PlanOffers::effectiveCodes`), prices in each plan's own
currency, "Recommended" only for `is_featured`. The only action is to ask Meta
Style through platform support (prefilled subject).

## 7. Trials

Super Admin controls:

- **Default trial duration** — platform setting, applies to self-registration.
- **Default trial entitlement set** — a designated plan, or an explicit key list.
- **Per-tenant trial override** — extend duration, or grant/revoke specific
  entitlements for one tenant.

Trial expiry is evaluated by a scheduled job, not by a real-time comparison
scattered through the code. On expiry: subscription → `expired`, entitlements
version bumped, owner notified. Data is retained through the retention window.

## 8. Quota accounting

```
consume(key, n)
  ├─ Redis INCRBY  q:{tenant}:{key}:{period}   (atomic, with ceiling check)
  ├─ over ceiling? → decrement back, return false
  └─ return true

every 5 minutes  → reconcile Redis counters into tenant_usage_counters
period rollover  → snapshot final value, start a new period key
```

Redis is the hot path; MySQL is the durable record for billing and reporting.
A Redis flush loses at most five minutes of counters — acceptable for
soft-limited quotas, and the reason quotas are never used for anything with
financial consequence.

## 9. Plans are packaging, not logic

A plan is a row plus a set of `plan_entitlements` rows. Adding "Business Plan +
Queue Add-on + Printing Add-on" requires:

- one `plans` row (or an existing plan),
- N `plan_entitlements` rows,
- two `subscription_addons` rows.

**Zero lines of application code.** If a new plan requires a code change, the
entitlement model has been violated — treat it as a bug.

### A phase defines capabilities; it does not price them

The corollary, learned the hard way in Phase 8. Shipping a feature means adding
its key to the CATALOG and enforcing it. Putting that key into a PACKAGE is a
commercial decision owned by the SaaS work, and a phase that makes it on the way
past has priced the product as a side effect of writing code.

The seeded matrix is therefore pinned as an exact set in
`TrialAndEntitlementTest`. It is not there to describe the packages — it is
there so that adding to one fails LOUDLY. Phase 8 put the three queue keys into
trial and business, which broke four tests honestly and, far worse, made a fifth
pass while proving nothing: an override test that grants a capability the plan
already grants asserts nothing at all.

> A capability nobody sells yet is normal, and it is not a gap. The queue lives
> exactly there: in the catalog, enforced everywhere, in no package, bought as a
> per-tenant override until somebody decides otherwise on purpose.

## 10. Anti-patterns

| Anti-pattern | Why it breaks |
|---|---|
| `if ($plan === 'pro')` | The thing this entire document exists to prevent. |
| Checking entitlements only in route middleware | WhatsApp, AI, jobs, and commands bypass HTTP. |
| Checking entitlements only in the frontend | Trivially bypassed. |
| Creating a `pro_features` database table | A plan-name check with extra steps. |
| Different tenant schemas per plan | Makes migrations combinatorial (`03` §2). |
| Deleting a deprecated entitlement key | Breaks historical subscriptions and reports. |
| Reusing a key with new meaning | Silently changes what existing tenants own. |
| Caching the resolved set without a version stamp | Tenants keep paid features after downgrade, or lose them after upgrade. |
| Treating `suspended` as "no entitlements" | Conflates access level with ownership; breaks reinstatement. |
| Quota checked after the external call | Overage that cannot be recovered. |
