# 26 — Usage metering and commercial quotas

Phase 13. `app/Kernel/Usage`. What a center has used of what it bought.

---

## 1. The whole public surface

```php
$usage->consume('ai_runs', 'ai_run', $run->uuid);   // spends, or throws
$usage->meter('wa_outbound', 'wa_message', $uuid);  // records, never refuses
$usage->summary('ai_runs');                         // what to show a manager
```

Everything below it — periods, allowance resolution, snapshots, atomic
arithmetic — is an implementation detail no caller holds in their head.

## 2. Generic, permanently (Phase 13 correction 3)

`Kernel\Usage` takes a resource CODE and a source identity. It has no idea what
an AI run or a WhatsApp message is, when RAYAN should run, when WhatsApp should
send, or which conversation should hand off.

The catalog lives in `config/usage.php`, not in a Kernel enum, precisely so the
Kernel can validate names it is not allowed to know. The consuming modules own
the meaning: `Rayan\Domain\Enums\AiUsage` and
`Conversations\Domain\Enums\WhatsAppUsage`.

An architecture test scans `app/Kernel/Usage/` for `Rayan`, `WhatsApp`,
`Conversation`, `ai_runs`, `wa_inbound` and friends, and fails on any of them.

## 3. Hard versus metered

| | Enforced | Why |
|---|---|---|
| `ai_runs` | **yes** | customer-facing RAYAN run allowance |
| `advanced_report_ai_runs` | **yes** | commercially separate Advanced Report analysis run allowance |
| tokens, tool calls, failures | no | only known AFTER the provider answers |
| every WhatsApp resource | no | recorded and reported; nothing refuses |

A hard monthly TOKEN cap would need reserve-then-settle semantics — an estimate
held before the call and trued up after it — and guessing at that produces a
limit that both overcounts and leaks. **Nothing in this product claims one.**

`consume()` on a metered resource behaves exactly like `meter()`, so a caller
cannot accidentally create a limit the product does not have.

`used` may legitimately exceed a stated allowance on a metered resource. That is
the honest record; `remaining()` clamps at zero so no dashboard shows a negative.

## 4. NULL means unlimited

Everywhere, with no exception and no sentinel. A plan that sells "unlimited"
stores NULL, never `999999999`, because a fake number is a number somebody
eventually hits and then asks about.

Resolution, first match wins — and NULL is a match, not a gap:

```
tenant override  →  plan limit  →  system default
```

A plan row that says NULL means the plan grants unlimited and stops the chain.
The ABSENCE of a row means the plan says nothing and the default applies.

## 5. Counted once, spent atomically

Two database guarantees carry everything, and neither is a timing hope.

**Counted once** — `unique(resource, source_type, source_uuid)` with
`insertOrIgnore`. A replayed webhook, a retried listener and two reconciler
passes over the same fact produce one row. Nothing asks "have I already counted
this"; a check-then-insert has a race in exactly the case that matters.

**Spent atomically** — one conditional UPDATE:

```sql
UPDATE usage_counters
   SET used = used + ?
 WHERE resource = ? AND period_start = ?
   AND (allowance_snapshot IS NULL OR used + ? <= allowance_snapshot)
```

The affected-row count IS the answer. No read, no comparison in PHP, no window.
At 99 of 100 with two racers, InnoDB serialises them: one writes 100, the other
matches nothing and is refused. **101 is unreachable.**

The event row and the counter move in ONE transaction, event first — so a
refusal rolls the evidence back with it, and the evidence never claims usage
that was denied.

**First row of a period**: `insertOrIgnore` then re-read. Two simultaneous first
requests both try to create it; the unique index makes exactly one succeed and
the other reads whichever won. Neither sees a duplicate-key error, which a
check-then-insert would surface to a customer as a 500 on the first message of
the month.

## 6. Mid-period allowance changes

| Change | When it applies |
|---|---|
| increase | **immediately** |
| finite → unlimited | immediately (also an increase) |
| decrease | **next period only** |
| unlimited → finite | next period only (also a decrease) |
| `enforce_immediately` | now, audited at `Warning` |

A center that has just paid for more must not wait for a billing boundary. A
center part-way through a month they have paid for must not be cut off by a
pricing change. The escape hatch exists for abuse and compromised accounts, and
is flagged on the row as well as in the audit trail because it can stop a
feature a center is using right now, in front of their own customers.

## 7. Allowances snapshot into the tenant

A quota decision must be one atomic statement against one database. Reaching
across to the control plane on every run would be a cross-database
check-then-increment — two round trips with a race between them, on the path
that most needs not to have one.

So the allowance is resolved ONCE, when a period's counter row is created, and
snapshotted onto it. Every later check reads the snapshot.

That also means a mid-period plan change cannot retroactively make
already-allowed usage disallowed.

## 8. Keeping the snapshot honest

The cost of a copy is that it can be stale. `AllowanceSync` is the repair —
tagged into `metastyle:reconcile`, hourly, the same after-commit shape as the
benefit reconcilers (ADR-061).

`allowance_version` is what makes it safe. An override carries a version; the
counter records the version its snapshot came from. Matching versions mean
"already applied". Comparing ALLOWANCES could never tell a stale copy from a
decrease that is correctly waiting for next period.

A deferred decrease records **nothing** — marking it applied would cancel it
rather than defer it. The next period's row is created from a fresh resolution
and simply starts with the new number.

Running it twice changes nothing. The one direction it never takes on its own is
DOWN.

## 9. The Super Admin projection

`tenant_usage_projections`, refreshed hourly by `metastyle:usage:project`, so
the platform can see a hundred centers without opening a hundred databases.

**Derived, lagging, non-authoritative.** Nothing consumes quota from it. A quota
check that read this table would be deciding from a snapshot minutes old,
reintroducing the race the tenant-side counter removes — across a database
boundary where no lock can help.

Per-tenant fault isolation: one unreachable center is reported and stepped over,
and the command exits non-zero.

A center with no usage gets no row. "No row" already means "no usage", and a row
per resource per center per month whether or not the feature was touched is
mostly noise.

## 10. Percentages and states

`normal · warning · high · exhausted`, from `config/usage.thresholds`
(70 / 85 / 100).

Computed in ONE place (`UsageSummary`) so the dashboard, the alert and the
projection cannot disagree.

**Unlimited has no percentage.** `allowance`, `percent` and `remaining` are all
NULL together. A percentage of unlimited is not 0 and not 100 — the fraction has
no denominator — and rendering either would state a limit the center does not
have.

## 11. Periods

The subscription's own `current_period_start` / `current_period_end` when the
control plane has them, so usage resets when the center is BILLED rather than on
a day that means nothing to them. A trial, or a plan with no billing dates, falls
back to a deterministic UTC calendar month.

A stale window — one that ended weeks ago and was never rolled forward — is
treated as no window, or today's usage would be counted into a closed period.

**UTC, explicitly, always.** `startOfMonth()` on a host set to `Asia/Baghdad` and
on one set to `UTC` are three hours apart, and the counter's unique key is the
period start — so the two would be different ROWS.

Branch-local time is deliberately irrelevant: a billing period is a commercial
fact about the center, not an operational one about a shop floor.

## 12. Exhaustion

`QuotaExceeded` is **not an error**. It means a center is using the product and
has reached the end of a number they agreed to.

At AI exhaustion: the inbound message is preserved, the provider is not called,
the conversation moves to `human_requested`, staff are notified, and one fixed
acknowledgement may be sent.

**The customer is never told the center has run out of a paid allowance.** A
person messaging a salon about their haircut is not a party to the salon's
billing, and saying so would embarrass the center to their own customer.

Human takeover always remains available.

## 13. Threshold alerts

One per tenant + resource + period + threshold, enforced by
`unique(resource, period_start, threshold)` and `insertOrIgnore` — so fifty
simultaneous messages crossing 85% produce one inserted row and one event.

Raised AFTER the usage transaction. An alert is a reaction to usage that already
happened, and failing to announce one must never undo it.

`Kernel\Usage` dispatches `UsageThresholdReached`; the Notifications module
listens. The Kernel may not import a business module, so this is the only shape
available — and it is the same one every other module uses.

Only enforced AI-run resources are announced. WhatsApp volume has no enforced allowance to
run out of, so a "threshold" there would be a warning about nothing.

## 14. The manager dashboard

`/center/usage`, behind `settings.view`.

Three groups, never one number: customer-facing AI, Advanced Report AI and
WhatsApp. The two AI products have separate hard run allowances while sharing
provider token/failure metering.

Shown: used, allowance, percentage, remaining, status, period end, and whether
the resource is enforced at all.

**Not shown:** any unit cost, any provider price, any Meta Style margin, any
provider account identifier. A center sees what THEY used against what THEY
bought; the platform's economics are not theirs to read.

`whatsapp.manage` and `ai.manage` are deliberately NOT what is checked here — a
manager may read the numbers without being trusted with the credentials. And
there is no `usage.view`: it would be a micro-permission nothing else
distinguishes.
