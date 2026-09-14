# Booking

> **Phase 7 extended this engine.** Availability now also enforces resource
> capacity and employee availability blocks, item layouts may contain gaps and
> parallel work, and opening hours are validated per ITEM rather than against the
> visit's span. See `16-JOURNEY-RESOURCES.md` for all of it; the rules below are
> unchanged except where that document says otherwise.

The single engine every channel calls. Web, mobile, white label, host, POS,
WhatsApp and RAYAN are adapters to it (`04-MODULE-BOUNDARIES.md` §4.1).

Implemented in Phase 6. This document is the rules; the code is
`app/Modules/Booking`.

---

## 1. The boundary

```
  Public web   Customer app   Host UI   White label   WhatsApp   RAYAN
       └────────────┴────────────┴──────────┴────────────┴─────────┘
                                 │
                   Booking\Contracts\BookingEngine
                                 │
        availability · book · reschedule · cancel · transition
```

A channel **may** parse its own input, resolve its own customer, present
availability, and call those five methods.

A channel **may not** compute a slot, decide whether a booking is allowed, apply
a policy, or write to `appointments`.

Three architecture tests hold this: no controller or Livewire component creates
or updates an appointment; no code outside `Modules/Booking` reads branch
schedules; the Booking module imports no HTTP, Livewire, `Auth`, `Request` or
`Session`.

---

## 2. The domain

**Appointment** — one customer's reservation at one branch. The VISIT.
**AppointmentItem** — one booked service inside it. Sequential, ordered.

Two tables, not one row with a service column: a customer books a haircut, a
beard trim and a facial as one arrival, and that fact is what reception, the
queue, the invoice and the reminder all need.

No `tenant_id` (the row is in the center's database) and no `local_date` (it is
derivable from `starts_at` and the branch timezone, and a stored copy could
disagree).

### The Phase 7 seam

An appointment item says **what was reserved**. A Service Journey stage will say
**what actually happened** — started when, by whom, in which room, handed off to
whom — in its own table, referencing this one.

No stage status, no queue state, no room, no "in progress" flag belongs on an
appointment item. An architecture test scans the migrations for those words in
column definitions.

---

## 3. Snapshots

A manager raises a price tomorrow. Every appointment already in the book keeps
what the customer agreed to.

Copied at booking time, per item: `price_minor`, `currency`, `duration_minutes`,
`service_name` (translatable), `variation_name`. Per add-on: the same four.

**Not** a JSON copy of the service row — descriptions, flags and images nothing
reads, going stale in a more confusing way. Only what preserves the MEANING of
the booking.

The canonical `service_id` stays linked when possible: nullable, `nullOnDelete`.
Reports group by it; the item does not depend on it.

A variation with a null price inherits from its service (ADR-037) — and that
inheritance is **resolved** at booking time. From then on the appointment
carries the number, not the rule.

---

## 4. Availability

One engine, `Domain\Availability\AvailabilityEngine`, assembled from named
collaborators:

| Component | Answers |
|---|---|
| `BranchCalendar` | When is this branch open, as UTC windows |
| `LineResolver` | Is this service/variation/add-on/employee valid, and what does it cost |
| `Scheduler` | Where does each service sit in the visit |
| `EmployeeAssigner` | Who may perform it, and who is free |
| `ConflictFinder` | What already occupies this employee's time |

### What it considers

Branch open (weekly hours, date exceptions, split shifts, overnight) · service
active, bookable and offered at this branch · variation and add-on durations ·
employee active, eligible and assigned to this branch · no conflicting
appointment · minimum lead time · booking horizon.

### What it does not

Rooms, chairs, devices, capacity, queue state, staff breaks, attendance, buffers
between appointments, deposits. Phase 7 adds resource constraints as one more
collaborator beside `ConflictFinder` — not as extra branches inside it.

### Slot generation

Candidate starts step by the center's `slot_interval_minutes`, aligned to the
opening interval's own start (a branch opening at 09:20 offers 09:20, not 09:30).
The loop is bounded by the window, so slot count is a function of opening hours
and granularity — never of the requested range.

### Cost

A day's availability is a FIXED number of queries regardless of how many slots
it produces: the schedule, the eligibility sets, and one bulk load of every
conflicting appointment in the range. A query-count test holds it.

**Availability is not cached.** A stale "yes" becomes a double booking the
customer was told was fine; a stale "no" is a lost sale nobody can explain.
Correct beats fast until there is a measured problem and a proven invalidation
story.

---

## 5. Time

Store UTC. Compute in the **branch** timezone (`10-API-FOUNDATION.md` §8).
`Kernel\Time\BranchClock` is the only place local wall clock becomes absolute
time.

Two local times are not what they appear to be, and PHP resolves both silently:

- **Non-existent** — on a spring-forward day 02:30 never happens. PHP moves it
  to 03:30 and says nothing. `BranchClock::toUtc()` returns null instead, so no
  slot is offered at a time that does not exist.
- **Ambiguous** — on a fall-back day 01:30 happens twice. The first occurrence
  is chosen, consistently, and that is a decision rather than an accident.

The conversion builds the intended wall clock as a string and asks the timezone
to resolve it. Taking local midnight and adding minutes cannot detect a gap at
all — `addMinutes()` moves the absolute instant, so midnight plus 150 minutes is
a perfectly real 03:30 rather than the 02:30 that was asked for, and the bug
looks exactly like success.

Iraq does not observe DST. That is the reason to handle it: the bug would first
appear in a market nobody was testing.

Scheduled instants are `DATETIME`, not `TIMESTAMP` (ADR-046).

---

## 6. The overlap rule

```
existing.starts_at < candidate.ends_at  AND  existing.ends_at > candidate.starts_at
```

Strict on both sides:

```
existing 10:00–10:30  vs  10:15–10:45  →  CONFLICT
existing 10:00–10:30  vs  10:30–11:00  →  no conflict     ← salons depend on this
existing 10:00–10:30  vs  09:00–12:00  →  CONFLICT        ← the enclosing case
```

Deliberately not built from `whereBetween`. The obvious
`whereBetween(start, [a, b])` formulation misses the third row — an existing
appointment that starts before the candidate and runs through the whole of it —
which double-books the longest treatments.

The rule lives in two places, `Kernel\Time\TimeWindow::overlaps()` and
`ConflictFinder`'s SQL. If one changes, both must.

---

## 7. Concurrency

`BEGIN` → `SELECT ... FROM branches WHERE id = ? FOR UPDATE` → authoritative
re-check → `INSERT`. See ADR-044 for why the branch row, why not Redis, and what
it costs.

Rescheduling uses the same lock. Availability returned a moment ago is advisory;
a slot it offered can still be refused, and that is correct behaviour rather
than a race.

---

## 8. Employee assignment

A line names an employee, or says "any available" — a real customer choice, not
a missing value.

**Named:** eligibility, branch assignment, active status and conflict are all
validated. Kept through a reschedule: silently moving a customer off the person
they asked for is the wrong kind of helpful.

**Any:** the lowest employee id among those eligible, at this branch, active and
free. Simple and deterministic on purpose — an idempotent retry must not book a
different person than the confirmation the customer already saw.

Explicitly not: load balancing, rotation, revenue fairness, skill ranking,
preference history, AI recommendation. Every one is a policy a center would want
to configure, and none has been asked for. `EmployeeAssigner` is the seam.

`employee_selection` records which of the two happened, because a reassignment
is a scheduling detail in one case and a phone call in the other.

---

## 9. Lifecycle

```
booked ──▶ confirmed ──▶ completed
   │   ╲        │  ╲
   │    ╲       │   ╲──▶ no_show
   │     ╲──────┴──────▶ cancelled
   └──────────────────▶ completed | no_show
```

Five statuses. `booked → completed` and `booked → no_show` are allowed: a center
that never uses the confirm step must still be able to close an appointment out,
and forcing one would be a workflow the software invented.

`pending` is absent — Phase 6 has no deposits and no approval step, so nothing
could move an appointment out of it.

Only `booked` and `confirmed` occupy the calendar. Cancelling releases the slot
immediately.

**Completion creates nothing financial.** No sale, no invoice, no commission, no
loyalty, no review request. Those modules do not exist, and faking their side
effects would leave a center's books full of records nothing can reconcile.
Later phases react to the audit event.

**No-show requires the appointment to have started.** A customer who calls ahead
has cancelled; a no-show is a customer who did not appear, and that can only be
known afterwards. Without the guard, "no-show" becomes a second, worse cancel
button and the history a later risk score depends on becomes noise.

---

## 10. Customers

Booking does not own customer rules; Customers does. Booking adds one: a guest
booking attaches to the person who already exists, never a second copy.

| Actor | Reference | Behaviour |
|---|---|---|
| Staff | `existing(uuid)` or `details(name, phone)` | Needs `customer.view` / `customer.create` |
| Guest | `details(name, phone)` | Resolves by normalised phone, or creates |
| Customer | `self()` | From the guard; a supplied uuid is never read |

`ResolveBookingCustomer` **reuses** on a phone match rather than refusing the way
`SaveCustomer` does. That refusal is right at a desk and wrong on a public form:
a guest typing their own number would be blocked by their own history, and the
refusal would tell an anonymous visitor that the number is a customer here.

A name is **required** for a new customer. The obvious fallback — the phone
number as the name — puts it in the one field that is never masked (ADR-042).

Nothing about a matched customer is returned to a guest.

---

## 11. Notes

Three kinds, and they are not the same thing:

| | Written by | Visible to customer |
|---|---|---|
| `appointments.customer_note` | the customer | yes |
| `appointment_items.customer_note` | the customer, per service | yes |
| `NoteOwner::Appointment` internal notes | staff | **never** |

The customer notes are columns, deliberately not rows in `internal_notes`, so no
surface can reach them by loading "the notes". `AppointmentPresenter::forCustomer()`
is a separate method rather than the staff shape with fields removed — what
would leak is a staff note about the person reading it.

`NoteVisibility::requiredPermission()` takes the owner: reading notes on a
customer and on an appointment are different grants.

Every note-writing surface carries `Kernel\Notes\NoteAdvisory` — one sentence
saying these are operational notes and not a medical record. It is a warning in
front of the person about to type, not a classifier guessing afterwards.

---

## 12. Booking settings

Per center, in the tenant `settings` table, via `Domain\BookingSettings`:

| Setting | Default | Purpose |
|---|---|---|
| `slot_interval_minutes` | 15 | The grid public booking offers |
| `max_advance_days` | 60 | Booking horizon; bounds the search too |
| `min_lead_minutes` | 0 | Shortest notice |
| `customer_cancel_notice_minutes` | 0 | Customer self-cancellation window |
| `max_calendar_days` | 31 | Widest calendar range a query may ask for |

Five values. Buffers, per-service lead times, deposits, cancellation fees,
no-show penalties and per-branch horizons are all real future requests, and not
one is needed to make the engine correct.

Every getter clamps: a stored `0` interval would loop forever and a stored `1`
would offer 1,440 slots a day per employee.

---

## 13. Idempotency

`idempotency_keys` in the tenant database, exactly the shape
`10-API-FOUNDATION.md` §6 specifies. `Kernel\Http\Idempotency` is the only thing
that reads or writes it; the API middleware and the public booking form both go
through it.

| Situation | Answer |
|---|---|
| New key | Runs, stores the response |
| Same key, same payload | Replays — no second write |
| Same key, different payload | `409 IDEMPOTENCY.CONFLICT` |
| In flight | `409` with `Retry-After` |
| Previous attempt FAILED | Key released; a corrected retry works |

The unique index on `(endpoint, key)` **is** the concurrency control: two
simultaneous retries both try to insert and the database lets one win.

Only 2xx responses are stored. Storing a failure would answer a corrected retry
with `IDEMPOTENCY.CONFLICT` — the failure mode where the safety mechanism becomes
the outage.

`metastyle:idempotency:sweep` prunes expired rows.

---

## 14. Permissions and entitlement

Nine codes, singular convention: `appointment.view`, `.create`, `.update`,
`.cancel`, `.confirm`, `.complete`, `.no_show`, `appointment.note.view`,
`appointment.note.manage`.

Split by action rather than by role because a center routinely wants reception
to book and cancel while only a manager marks a no-show.

| Role | Holds |
|---|---|
| Owner | All, as explicit grants (ADR-029) |
| Manager | All |
| Host | All except `note.manage` |
| Cashier | `view` |
| Employee | `view` |

**Permission and branch scope.** `CalendarQuery` applies the scope in the WHERE
clause, and every mutation checks `canAccessBranch()`.

"Own appointments only" is **deferred**: the current RBAC expresses branch scope
cleanly, and the roadmap asked not to overbuild contextual authorization. The
seam is `Employee.user_id` plus a future `AppointmentScope`.

### Entitlement

`booking`, enforced inside the Actions — the WhatsApp bot, RAYAN and queued jobs
never pass through HTTP middleware.

Mutations and availability require it. **Reading existing appointments does
not**, so a center that downgrades keeps its own history.

The public MENU stays viewable without it — it is the center's shop window, and
switching it off would punish their customers for a billing decision. The Book
action is not rendered, and `/m/{center}/book` is 404 (not 403: a guest has no
billing relationship with the center).

---

## 15. Audit

| Event | Action |
|---|---|
| Created | `booking.appointment.created` |
| Confirmed / completed / cancelled / no-show | `booking.appointment.{verb}` |
| Rescheduled | `booking.appointment.rescheduled` (before/after) |
| Note written / deleted | `booking.appointment_note.{verb}` |

`AppointmentSnapshot` is the one shape, so a reschedule's before and after are
directly comparable — including item windows, employees and price snapshots,
because a move that also changed the stylist is only half told by the header
times.

**No customer phone, email or note body.** The phone appears as a keyed
`Fingerprint`; the NAME is the deliberate exception, as the target label, because
a trail of anonymous uuids answers nothing during an investigation (ADR-042).

Actor and source are always recorded. A future WhatsApp or RAYAN booking changes
the two values, not the shape.

---

## 16. Surfaces

**Staff API** (`/api/v1/tenant`): `availability`, `calendar`,
`appointments/affected`, and create / show / reschedule / confirm / complete /
no-show / cancel / notes.

**Customer API** (`/api/v1/customer`, `auth:customer-api`): `availability`, own
appointment list and detail, create, reschedule, cancel.

**Public** (`/api/v1/menu/{center}`): `availability`, `bookings`.

**Staff calendar** — Livewire, day and week, rendered as tables. No calendar
library: it would need its own tenant-aware endpoint, its own RTL handling and
its own build step to draw what Blade already draws.

**Public booking** — plain server-rendered forms, three GETs and one POST, each a
real URL. The menu is deliberately a no-JavaScript page opened from a QR code on
a slow connection, and the booking flow keeps that promise.

Creation and rescheduling require an `Idempotency-Key`. Status changes do not:
the lifecycle refuses a second attempt, so a retry cannot double-apply one.

---

## 17. Operational

**Employee deactivation.** Existing future appointments are never deleted or
reassigned automatically. `GET /api/v1/tenant/appointments/affected` and the
calendar's "Needs a new team member" filter list them; reassignment is a decision
the center makes.

**Eligibility withdrawal.** Existing appointments keep their assignment; new
bookings validate against current eligibility.

**Timezone correction.** `booked_timezone` is snapshotted, so an appointment
still reports the wall clock it was agreed at. Correcting a branch's timezone
does not move stored instants — that is a data correction needing operator
attention, and the snapshot makes it visible rather than silent.
