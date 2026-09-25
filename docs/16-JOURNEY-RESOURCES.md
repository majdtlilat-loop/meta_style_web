# 16 — Service Journey & Operational Resources

> Phase 7. Read `15-BOOKING.md` first: this document assumes the Booking Engine
> and describes the operational half that sits beside it.

## 1. The distinction everything rests on

```
appointments / appointment_items    what was RESERVED   (Booking, Phase 6)
service_journeys / journey_stages   what HAPPENED       (ServiceJourney, Phase 7)
```

Booked with Ahmed at 10:00 for thirty minutes. Arrived 10:12, seen by Sara,
finished 10:51. **Both are true and neither may overwrite the other.**

One set of columns would force a choice between "what did we promise?" and "what
did we do?", and every later question — delay reports, duration variance,
employee attribution, device utilisation — needs both. It is also what makes the
customer's confirmation stay true: the item still says Ahmed, because that is
what they were told.

Three architecture tests hold the line: Journey never writes an appointment,
Booking never learns Journey exists, and no migration adds `arrived_at`,
`stage`, `queue` or `ticket` to the booking tables.

## 2. Modules

| Module | Layer | Owns |
|---|---|---|
| **Resources** | L1 Core Records | `ResourceType`, `OperationalResource`, `ServiceResourceRequirement` |
| **Employees** | L1 Core Records | `EmployeeAvailabilityBlock` (existing module, one new concern) |
| **Booking** | L2 Operations | resource-aware availability, reservations, layouts |
| **ServiceJourney** | L2 Operations | journeys, stages, handoffs, actual resource usage |

Resources is **not** part of ServiceJourney, though `04-MODULE-BOUNDARIES.md`
originally placed it there. Booking has to enforce capacity, and Booking must not
depend on Journey — so resources sit at L1 where both can read them (ADR-049).

Dependency direction, enforced:

```
ServiceJourney ──▶ Booking ──▶ Resources
       │                          ▲
       └──────────────────────────┘
```

## 3. Resources

**`ResourceType`** is a classification: "Laser Machine", "Treatment Room",
"Barber Chair". Tenant-defined, because a barbershop, a laser clinic and a
hammam share none of this vocabulary and an enum would need a release every time
a center bought a different machine.

**`OperationalResource`** is a physical thing of that type, standing at exactly
one branch. `branch_id` is NOT NULL: a nullable branch would make "is this chair
free?" ambiguous and would break the coordination lock (§7).

> The PHP class is `OperationalResource`, not `Resource`. `resource` is a native
> PHP type, and Pint rewrites the bare word to lower case inside generic
> docblocks, which PHPStan then reports as a wrong-case class reference — the two
> tools would fight on every run. The table is still `resources`.

**Capacity** is how many uses the resource supports at once. A private treatment
room is 1; a hammam that seats four is 4. Assuming exclusivity everywhere would
make a shared space bookable by one person.

**Archive, never delete.** Retiring a resource stops the NEXT booking and touches
nothing already made. `ResourceFinder::appointmentsOnInactiveResources()` lists
what is affected; who gets moved is the center's decision.

## 4. Service requirements

`service_resource_requirements` — one row per (service, type), with a quantity.
"A laser session needs one treatment room and one laser machine" is two rows.

Defined against the **type**, never a concrete resource: a service naming Laser
Machine 2 would be unbookable the day the center buys a third, and a center with
two machines would need two copies of the service.

Changing a requirement never touches an existing booking. The concrete resources
that booking holds are already in `resource_reservations`.

## 5. Reservations

`resource_reservations` — the concrete resources a booking holds, one row per
(appointment item, resource), with a quantity and a **snapshot** of the resource
and type names.

**No times of its own.** A reservation is held for exactly as long as its
appointment item, and the item already stores the window. A second copy would be
a second thing to keep in step through every reschedule.

## 6. Capacity is a peak, not a sum

The single most important piece of arithmetic in the phase, in
`Kernel\Time\Occupancy`.

```
Room capacity 2
existing   10:00–10:30  ×1
existing   10:30–11:00  ×1
candidate  10:00–11:00  ×1
```

The two existing bookings never coexist, so the peak load is 1 and the candidate
fits. **A sum says 2, adds the candidate, gets 3, and refuses a valid booking.**

So capacity is a sweep: `+q` when a window opens, `−q` when it closes, walk the
events in order, take the highest running total. Ends are processed before starts
at the same instant — the same half-open rule `TimeWindow` and `ConflictFinder`
use, or a resource would refuse the back-to-back bookings the calendar accepts.

Allocation for a requirement of N units: active resources of that type at this
branch, in `sort_order` then `id` order, taking `min(remaining, needed)` from
each. No scoring, no "best room", no balancing. **Deterministic** is the property
that matters — an idempotent retry must produce the room the confirmation named.

A **specific** resource is honoured or refused, never substituted.

## 7. Coordination: one lock, several writers

`Branches\Domain\BranchLock` — a pessimistic row lock on `branches`, taken inside
a transaction. Phase 6 used it in one place; Phase 7 gave it a name because it
now has several callers, and the whole guarantee depends on all of them taking
the same lock (ADR-044, ADR-047).

The race it closes:

```
Request A: books the last place in the hammam
Request B: reduces the hammam's capacity by one
```

Both read a consistent world, both decide they are fine, both commit — and the
center holds a reservation against capacity that no longer exists.

Every mutation that changes bookability takes it:

- resource capacity, activation, archival, branch move (both branches, ordered);
- a service's resource requirements (every branch);
- an employee availability block.

Locks are always taken in ascending branch id, because two callers that each need
branches 3 and 7 would deadlock if one took 7 first.

`acquire()` **throws outside a transaction**: a row lock there is released the
instant the statement finishes, so it protects nothing while looking exactly like
it does.

## 8. Employee availability blocks

`employee_availability_blocks` — break, unavailable, training, personal, admin.
Phase 6's engine knew about appointments and nothing else, so a stylist's lunch
hour was bookable.

**Not attendance.** No clock-in, no worked hours, no leave balance, no approval
workflow. When a real attendance module arrives it becomes a SECOND source
answering the same question, joining this one behind `BlockFinder` — the Booking
Engine does not change. **That is the documented seam.**

`branch_id` is required. Blocking somebody across three branches is three rows,
created together by one Action.

A block never cancels or moves an appointment. Creating one returns the bookings
already inside it so the person who made it can see what they have done.

### 8.1 Manager UI and scope (Phase 15)

The Manager edits blocks in two places with ONE component
(`Livewire\Center\Resources\AvailabilityBlocks`): the Resources page's "Time
off" tab (every upcoming block in the viewer's branches) and a staff profile's
"Time off" tab (that person only). Times are typed and shown in the BRANCH's
wall clock (`BranchClock::toLocal` on display, `BranchClock::toUtc` inside the
Action) — the old table printed UTC. The branch picker offers only live
branches in the viewer's scope where the person works; the person picker only
active staff. The affected-bookings list is shown only to `appointment.view`
holders, the same gate the API applies.

**The record's own branch is authorised, not only the target.**
`SaveAvailabilityBlock` update/delete and `SaveResource` update/archive/restore
now refuse when the EXISTING block or resource stands in a branch outside the
actor's scope. The API compensated in its controllers; the Livewire screens
called the Actions directly, so a scoped manager could re-point another
branch's block or machine into their own. `SaveResource::restore()` and
`SaveResourceType::restore()` undo an archive (under the branch lock; a
resource of a retired type cannot return). Editing a resource now round-trips
its active flag and sort order instead of forcing `is_active = true`.

## 9. Layouts: sequential, gapped, parallel

`Scheduler` lays a visit out. A line may declare `offsetMinutes` — where it
starts, measured from the beginning of the visit.

```
Colour        10:00–10:30   offset 0
(development)               ← nobody works; the customer waits
Haircut       11:00–11:30   offset 60

Service A     10:00–10:30   offset 0
Service B     10:00–10:45   offset 0   ← different employee and room
```

Null offset keeps Phase 6's behaviour exactly: start where the last line ended.
**Public booking never sets one** — a customer choosing their own gaps is a
product decision nobody has made.

### Opening hours are checked PER ITEM

Not against the span. With a gap the span can legitimately cross a closed
interval:

```
Branch open   09:00–13:00 and 16:00–21:00
Item A        12:00–13:00      inside the morning shift
Item B        16:00–17:00      inside the evening shift
Header span   12:00–17:00      crosses a closure, and is fine
```

The appointment header remains the **union** — earliest start to latest end —
because that is the visit's identity, when the customer arrives and when they
leave. It is not an opening-hours requirement.

A reschedule preserves each item's offset, so a development wait somebody booked
on purpose is not quietly deleted.

## 10. Journey lifecycle

```
(no row)  ──check in──▶  active  ──▶ completed
                            │
                            └──────▶ aborted
```

There is no `not_started`: **absence is the state.** A row for every future
booking would fill the operational tables with visits that have not happened, and
the board would have to filter them out again.

**Aborted** is the customer who arrived, began, and left. Without it such a
journey stays `active` for ever while its appointment is cancelled — dangling
state the board can never clear.

Aborting does **not** cancel the appointment. `CancelVisit` is the flow that does
both, in that order, by calling `AbortJourney` and then the Booking lifecycle
Action.

Completion calls `TransitionAppointment`. It creates no sale, no invoice, no
commission, no loyalty and no review request — those modules do not exist.

## 11. Stage lifecycle

```
waiting ──▶ in_service ──▶ completed
   │
   └──────▶ skipped   (reason required)
```

`waiting` means "this service has not started yet". **It is not a queue ticket**
— no number, no position, no priority, no display state. Phase 8 builds Queue on
top of these tables.

A stage cannot be skipped once it has started: something performed for ten
minutes and then abandoned is a completed stage with a short duration, and
calling it skipped would make service-time reporting lie.

Starting a stage **opens** its actual resource holds; finishing **closes** them.

## 12. Routing

Stages are derived from the booked items, in order, each taking the
**department** of its service — snapshotted at check-in, so re-organising the
center later does not rewrite what happened.

Never `service_category_id`. Department is how the business is organised;
Category groups services for a customer browsing a price list and routes nobody
(ADR-037). An architecture test scans for it.

There is no workflow designer. The route is the services the customer booked.

## 13. Handoff

`journey_handoffs` — append-only, one row per move, carrying from/to stage,
from/to employee, from/to department, a note and the actor.

A small explicit table rather than an event store: "who passed this customer to
whom, when, and why" is one indexed read, and an event-sourcing framework would
be infrastructure nothing else in the product uses.

## 14. Actual employee and actual resources

| Planned | Actual |
|---|---|
| `appointment_items.employee_id` | `journey_stages.employee_id` |
| `appointment_items.starts_at` / `ends_at` | `journey_stages.service_started_at` / `service_completed_at` |
| `resource_reservations` | `journey_stage_resources` |

**Reassignment** validates that the new employee is active, works at this branch,
and is qualified for the service. Phase 7 **rejects** rather than offering a
privileged override: no center has asked for one, so its rules and its reason
field would be guesswork. The seam is `ReassignStageEmployee::employee()`.

**Resource usage is intervals, not assignment.** A swap closes the open row
(`released_at = now`) and opens a new one:

```
Laser Machine 1   10:00 → 10:15   released, "device fault"
Laser Machine 2   10:15 → …       open
```

Overwriting `resource_id` in place would say the customer was on Machine 2 the
whole time, erasing the fifteen minutes Machine 1 was in use and the fact that it
failed. The booking's reservation is never rewritten either.

Capacity for an **actual** assignment is checked against other stages' actual
holds **and** against committed booking reservations, in one calculation. The
previous customer who overran is still in the room whatever the plan said, and a
room promised to somebody at 10:30 is not free at 10:10 merely because nobody is
standing in it. The rule, and what must not be counted twice, is §15.

> `journey_stage_resources.assigned_at` is DATETIME, not TIMESTAMP. MariaDB gives
> the first non-nullable TIMESTAMP column an implicit `ON UPDATE
> CURRENT_TIMESTAMP`, so closing a usage row silently rewrote when it had opened.
> Nothing errored; the numbers just moved. Same family as ADR-046.

## 15. Runtime resource capacity: actual use plus committed bookings

Two different things can stop a customer walking into a room, and a check that
looks at only one of them is wrong in a way nobody notices until a booked
customer is turned away at the door.

```
Resource capacity 1
committed  10:30 → 11:00   another customer's reservation
candidate  10:10 → 10:40   somebody arrived early, a host presses start
```

Phase 7 shipped counting only live usage rows, so at 10:10 the room looked empty
and the start was admitted. At 10:30 the customer whose booking it was arrived to
find it occupied. That was the phase's stated remaining risk, and this is it
closed (ADR-050).

**Runtime capacity = actual occupancy + committed external booking capacity.**
One combined peak over both sets, through the same `Kernel\Time\Occupancy`
sweep, the same half-open boundary rule, and never a sum.

### What goes into the calculation

| Set | Source | Meaning |
|---|---|---|
| Actual usages | `journey_stage_resources` | what is physically in use now |
| Committed reservations | `resource_reservations` via `ResourceFinder::committedLoadsFor()` | what is promised |
| The candidate | the window below, × the required quantity | what is being asked for |

Which reservations count is **Booking's** rule, not a second copy of it:
`committedLoadsFor()` filters on the booking conflict set, so a cancelled,
completed or no-show appointment stops consuming capacity the moment its status
changes, and the reservation rows it leaves behind are history rather than a
hold. Journey reads it through that seam; Booking still does not know Journey
exists.

### What must not be counted twice

The two sets describe the same world from two sides, so they overlap:

- **The stage's own booked reservation.** The visit reserved the room and is now
  walking into it. Counting both would have the customer competing with
  themselves, and nothing would ever start.
- **Any other item whose service has already started.** Once a stage leaves
  `waiting`, its reality is the usage row. Counting its reservation as well would
  refuse a second customer from a capacity-two room that genuinely has a place
  free — and would keep refusing after the first customer finished **early**,
  because the reservation runs to its planned end and the room does not.

Every other item's reservation is a real competitor and counts in full,
**including another item of the same visit**: two services booked back to back in
one exclusive room compete with each other exactly as two customers would.

### The candidate window

| Operation | Window |
|---|---|
| Starting a stage | `now` → `now + appointment_item.duration_minutes` |
| Swapping mid-service | `now` → `service_started_at + duration_minutes`, with a minimal window once that has passed |

> **It is an admission check, not a completion time.** Nothing writes it down.
> The actual record stays `assigned_at → released_at`, and a service that
> overruns produces a longer interval than the window it was admitted on. That
> is the truth of what happened, not a discrepancy to reconcile.

There is no scheduling policy here — no buffer, no turnaround, no maintenance
window. Those are real requests and they need a center to describe them (§21).

### Where it runs

Inside the transaction that writes the usage row, **under the same branch lock**
booking takes (§7, ADR-047). A capacity read that raced a booking is a capacity
read that passed for no reason.

A swap validates **before** anything is closed:

```
lock branch → prove the new resource fits → close the old usage → open the new
```

— or none of the four. Closing first and validating after would leave a customer
physically in a room the system believes is empty, which is worse than the
refusal it was trying to avoid.

### Never take the capacity away

If the room is committed, the answer is a different resource or a refusal. A
future reservation is never mutated, released or deleted to make an operational
request succeed.

## 16. Idempotency

`UNIQUE(service_journeys.appointment_id)` **is** the mechanism. A double-clicked
check-in is a duplicate-key violation the Action catches and turns into "here is
the journey that already exists".

Three layers: a read first for the ordinary repeat, the unique index as the
actual guarantee, and a catch that re-reads and returns the winner's journey so
the loser of a genuine race gets the canonical row rather than a 500.

No HTTP idempotency key on internal buttons — database invariants already make
these Actions idempotent, and a key would be ceremony with no guarantee behind
it. Stage transitions are idempotent by construction: the enum refuses a repeat.

## 17. Authorization

`Kernel\Authorization\AppointmentScope`, resolved by
`Booking\Application\AppointmentScopeResolver`:

| Grant | Sees |
|---|---|
| `appointment.view` | every appointment in the branches they may work in |
| `appointment.view_own` | rows their linked employee is assigned to |
| neither | nothing |

The broad grant **wins** when both are held. A user with the narrow grant and no
linked employee gets `none()`, never unrestricted — that is the escalation a
careless `?? null` would produce.

The journey board scopes on `journey.view` / `journey.view_own`, and "own" means
**planned or actual**: a stylist handed a customer mid-shift is not on the
booking any more, and a scope reading only items would hide the person they are
standing in front of.

Never a role-name check. `SystemRole::Employee` was narrowed from
`appointment.view` to `appointment.view_own` — see `12` §3.3 for the deploy
consequence.

## 18. Twelve permissions

```
appointment.view_own
resource.view              resource.manage
availability_block.manage
journey.view               journey.view_own        journey.manage
journey.stage.start        journey.stage.complete  journey.stage.reassign
journey.note.view          journey.note.manage
```

Skipping a stage rides `journey.stage.complete`, and a resource swap rides
`journey.stage.reassign`: the same operator doing the same job, and a code
nothing distinguishes is a promise rather than a permission.

**Entitlement:** the existing `booking`. Journey is the operational half of
booking, and a center that owns one owns the other. No new entitlement, no plan
names.

> **Deferred packaging decision.** `booking` is being reused as the commercial
> capability that enables the Journey workflow, walk-ins included — so a
> walk-in-only center could not be sold Journey without it. Nothing is blocked
> by this: every sellable plan includes `booking` today. The DOMAIN separation is
> untouched — `appointment_id` is nullable and no walk-in ever invents an
> appointment — and what is deferred is only which key sells the operational
> board. Audited and scoped in `docs/13-ROADMAP.md`, "Deferred
> packaging/entitlement decision"; revisit before offering a sellable
> walk-in-only configuration.

## 19. Audit

```
resources.type.{created,updated,archived}
resources.resource.{created,updated,archived}
resources.service_requirements.updated
employees.availability_block.{created,updated,deleted}
journey.checked_in
journey.stage.{started,completed,skipped,employee_reassigned,resource_swapped}
journey.handoff
journey.{completed,aborted}
journey.stage_note.{created,deleted}
```

`before`/`after` share one shape (`JourneySnapshot`) so a reassignment is
directly comparable, and a stage entry carries the booked employee as well as the
actual one — an entry showing only the new value would not show that anything had
changed.

No customer PII. **No note bodies** — the trail records that a note was written,
by whom, at what visibility, and how long it was. The audit log is not an
operational source of truth: resource-use history lives in
`journey_stage_resources`, which the product actually reads.

## 20. Performance

- Availability loads every resource reservation for the date range in **one**
  query, and every availability block in one more. Neither count grows with the
  number of bookings; regression tests hold both.
- The board loads appointments and journeys in two queries, with stages,
  their booked items (`stages.item.employee`), employees, departments and
  resources eager-loaded. The visit drawer loads handoffs with their stages and
  people, and notes with their authors, so no screen lazy-loads per row.

### The Manager board (Phase 15)

`{center}.…/manager/board` (`App\Livewire\Center\JourneyBoard`): five lanes —
Not arrived · Waiting · In service · Completed · Left — built from
`JourneyBoardView` cards (branch-local times, late / waiting minutes, the
service in progress with elapsed vs expected, the next service, progress). The
stage figures double as a lane filter. `wire:poll.15s.visible` for today.

- **Finished visits stay on their day.** `JourneyBoardQuery::forDay()` now
  includes the day's appointments that HAVE a journey whatever the appointment
  status (completed, cancelled), not only `booked`/`confirmed`; a booking
  cancelled before anybody arrived still leaves the board. The API board payload
  changes the same way.
- Every card is presenter data; walk-in rows (no appointment) render like any
  other — the old view crashed on them.
- One-press: check in, start the next service, finish the current one; the
  drawer (`Journey\VisitPanel`) adds skip (a reason is required), hand on
  (`journey.stage.reassign` + complete), reassign (only active, qualified people
  at the branch), swap a room (same kind, bookable at the branch), notes with
  visibility (team / managers only) and delete, hand-off history, give a queue
  number (`IssueTicket`, with `queue_management`), complete visit, customer left
  (`AbortJourney`), cancel booking (`CancelVisit`), and a LINK to the till.
- Choices come from `VisitOptions`; every uuid is re-resolved by
  `JourneyBoardQuery::find()` / `stage()` (branch + own scope).
- Walk-ins use the shared walk-in drawer: `CreateWalkInVisit` without the queue.
- **Locked:** without `booking` the board is the upgrade offer when the center
  has no visits or bookings; otherwise read-only with a compact notice.
- The Actions call `loadMissing()` rather than trusting the caller — an Action
  that assumes its caller eager-loaded is one query per row the first time
  somebody calls it from a loop.
- Starting a stage or swapping a resource adds **two bounded reads per resource**
  (§15): one for the competing reservations, one for which of those items have
  already started. Both are `WHERE IN` over a set bounded by how many bookings
  overlap a single service's duration on a single resource. A regression test
  asserts the count does not grow with the number of reservations.
- **No availability cache.** Correct beats fast until there is a measured problem
  and a proven invalidation story.

The availability collaborators are deliberately **not** scoped or singletons:
they cache per instance, and a scoped instance outliving an HTTP request offered
a room from a cache built before it was archived (ADR-048).

## 21. Not in Phase 7

Queue, tickets, displays, announcements, kiosk. POS, invoices, payments,
deposits, finance, commissions. Loyalty, memberships, packages, reviews.
Notifications, WhatsApp, RAYAN. Reports. Full attendance and payroll.

> Walk-in visits ARRIVED in Phase 8 and are documented in §22 above. The rest of
> this list still stands.

## 22. Walk-in visits (Phase 8)

Phase 7 shipped with `service_journeys.appointment_id` NOT NULL, and listed
walk-ins as the first thing Phase 8 would need. This is that, and it changed the
shape of a journey rather than adding a flag to it.

```
source = appointment   appointment_id set, customer_id and branch_id null
source = walk_in       appointment_id null, customer_id and branch_id set
```

**No fake appointments.** Inventing one to satisfy the old column would put
reservations into the booking tables for reservations nobody made, and every
availability query, calendar screen and later report would need a "but not the
fake ones" clause forever (ADR-051).

A walk-in **stage** has no `appointment_item_id` either, and carries its own
snapshot instead:

| Column | Why |
|---|---|
| `service_id` | which service, for eligibility and requirements |
| `service_name` | a rename must not rewrite what happened |
| `duration_minutes` | the window the resource admission check runs against |
| `price_minor`, `currency` | a reprice must not rewrite what happened |

The invariant is enforced in the Actions and by tests, never by a CHECK
constraint (ADR-033).

### The accessors are the point

`ServiceJourney::branchId()` / `customerId()` and `JourneyStage::serviceId()` /
`durationMinutes()` / `serviceName()` read the column when it is set and fall
back through the appointment. Every Action calls those, so none of them branches
on which kind of visit it holds — which is what kept the Phase 7 behaviour
byte-identical while the schema changed underneath it.

A booked journey deliberately does NOT copy the customer or the branch. The
accessor gives a uniform READ without a uniform WRITE, and a second copy is a
second place they can disagree.

### Everything else is unchanged

The same stages, the same statuses, the same handoffs, the same board. In
particular the same RESOURCE rules: a walk-in stage reserved nothing ahead of
time, so when it starts it allocates through `ResourceAllocator::allocate()` —
the same deterministic walk the Booking Engine uses — driven by the same combined
occupancy from §15. **A walk-in cannot take a room committed to a future
booking**, and there is no second allocation algorithm to drift.

### The customer

Resolved through `ResolveBookingCustomer`, which is where the center identity
rule lives: a phone that already belongs to somebody resolves to that person. A
walk-in must not fork a regular customer who came in without booking.

A walk-in with no phone gets a new record with a name and nothing else — the
honest representation of what the desk knows. Neither path ever creates a
`CustomerAccount`: walking in is not registering (ADR-041).

### What gates it

`booking`, not the queue. A center without the queue entitlement still takes
people who walk in; it simply hands nobody a number. The queue half lives in
`17-QUEUE.md`.
