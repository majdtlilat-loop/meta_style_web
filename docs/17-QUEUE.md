# 17 — Walk-ins, Queue, Display & Voice

Phase 8. Read `16-JOURNEY-RESOURCES.md` first: everything here sits on top of it.

## 1. Four concepts, permanently separate

```
Appointment      what was RESERVED          a booking, in the future
ServiceJourney   the operational VISIT      arrived 10:12, left 10:51
JourneyStage     what was actually PERFORMED  Sara did the haircut, 10:15–10:44
QueueTicket      the WAITING and CALLING around that stage   A012 → Room 3
```

A ticket is a mechanism for getting a customer to the right place at the right
moment. **It is never the source of truth for service execution.** Its
`serving_started_at` records when the queue LEARNED the stage had started; the
service itself began at `journey_stages.service_started_at`, and every duration
figure reads that one.

Nothing in Phase 8 added queue state to `appointments`, `appointment_items` or
`journey_stages`. Three architecture tests scan for it.

## 2. Walk-ins come first

A queue is mostly about people who did not book. Phase 7 tied a journey to an
appointment with a NOT NULL foreign key, so Phase 8 opened with that:

```
source = appointment   appointment_id set, customer_id and branch_id null
source = walk_in       appointment_id null, customer_id and branch_id set
```

**No fake appointments.** Inventing one to satisfy the old column would have put
reservations into the booking tables for reservations nobody made, and every
availability query, calendar screen and later report would spend the rest of the
product's life filtering them out (ADR-051).

A walk-in **stage** carries its own service snapshot — name, duration, price,
currency — for the same reason `appointment_items` snapshots: renaming or
repricing a service must never rewrite a visit that already happened. And the
duration is not decoration: it is the window the resource admission check runs
against (ADR-050).

`ServiceJourney::branchId()` / `customerId()` and `JourneyStage::serviceId()` /
`durationMinutes()` / `serviceName()` hide the difference, so no Action branches
on which kind of visit it holds.

**The customer is resolved, never duplicated.** A phone goes through
`ResolveBookingCustomer` — the center's identity rule, unchanged — so a walk-in
attaches to the regular customer who typed their own number. A walk-in with no
phone gets a new record with a name and nothing else, which is what the desk
actually knows. Neither ever creates a `CustomerAccount`: walking in is not
registering (ADR-041).

Walk-ins are gated on **`booking`**, not on the queue. A center without the queue
entitlement still takes people who walk in; it simply hands nobody a number.

## 3. Queue schema

| Table | What it is |
|---|---|
| `queue_service_points` | destinations: "Reception Desk 1", "Laser Room 2" |
| `queue_displays` | the screens, their scope, language and voice settings |
| `queue_sequences` | one locked counter per branch per local day per prefix |
| `queue_tickets` | the numbers |
| `queue_ticket_events` | append-only history |

**Every instant is DATETIME.** MariaDB gives the first non-nullable TIMESTAMP
column an implicit `ON UPDATE CURRENT_TIMESTAMP`; `issued_at` and `occurred_at`
are those columns, and every later mutation would have silently rewritten them.
It cost Phase 7 an afternoon on `journey_stage_resources` (ADR-046, ADR-050).

## 4. A ticket carries almost no data

The number, where to go, and the times of queue events. Everything about the
person is one relation away through the journey, and a copy on the ticket would
be a copy on the read model a **public screen** is built from.

Three deliberate exceptions:

- `display_number` — snapshotted, because it is printed on paper in somebody's
  hand and a later change to the padding must not renumber it;
- `branch_id` — denormalised, because it is the numbering scope and in every
  board query, and a walk-in has no appointment to join through;
- `last_announcement_uuid` — the call a screen is currently speaking (§13).

## 5. One writer, seven steps

`Queue\Domain\TicketMutation` is the only thing that writes a ticket:

1. open a transaction
2. re-read the ticket `FOR UPDATE`
3. validate against the **locked** row, never the one the caller was holding
4. allocate the next history sequence under that same lock
5. append exactly one history row
6. write the ticket
7. commit

Two desks pressing "call" on A012 at the same instant is not a hypothetical, it
is a Saturday. Without the lock both read `waiting`, both decide the move is
legal, both write `called`, and both compute history sequence 2 — one of which
the unique index rejects, taking an otherwise-successful call down with it.

`MAX(sequence) + 1` is safe **there and nowhere else**, because every writer
locks the ticket first. The unique index on `(queue_ticket_id, sequence)` is what
says so even if a future path forgets.

## 6. Numbers

`A001`, `L015`, `R1-007`. Never a uuid — a customer has to read it back to a
receptionist.

```
INSERT IGNORE the sequence row  →  SELECT ... FOR UPDATE  →  +1  →  write
```

Every simpler implementation hands two simultaneous walk-ins the same number:
`SELECT MAX(number)+1` (both read the same maximum), a counter in PHP (two
workers, two counters, undetectable), Redis alone (correct until the key is gone,
and the queue is not allowed to be wrong when the cache is empty).

Backstopped by `unique(branch_id, business_date, display_number)`: even a bug
produces an error rather than a duplicate on paper. On the COMPOSED number
rather than on `(prefix, number)` — it is the same guarantee in three columns
instead of four, and it additionally refuses two prefixes that compose to one
string (`A` #1001 and `A1` #001), which is the pair a customer could not tell
apart anyway.

**Scope**: branch + **branch-local** date + prefix. The day resets at the
center's midnight, not the server's — a server clock restarts the numbering
mid-evening in Baghdad while customers are still holding tickets.

**Prefix**: the service point's, then the department's `queue_prefix`, then `A`.
Validated at the boundary — uppercase ASCII, one to four characters, no
whitespace — because it is printed, displayed across a room and read aloud. `l`
and `L` must not become two sequences, which is two customers holding `L001`.

## 7. The state machine

```
waiting ──call──▶ called ──(recall: stays called, appends history)
   │                 │
   │                 ├──▶ serving ──▶ completed
   │                 │
   ├──hold/skip──▶ held ──resume──▶ waiting
   │
   └──cancel──▶ cancelled
```

| From | Operator may move it to | Journey may move it to |
|---|---|---|
| `waiting` | `called`, `held`, `cancelled` | `serving`, `completed` |
| `called` | `called` (recall), `waiting` (transfer), `held`, `cancelled` | `serving`, `completed` |
| `serving` | — | `completed`, `cancelled` |
| `held` | `waiting`, `cancelled` | `serving`, `completed` |

**`serving` and `completed` are Journey's to give.** No queue Action produces
either; the only writer is the synchronizer, reacting to a fact. An architecture
test scans the module for `state: TicketState::Serving|Completed`.

**Nothing operator-driven leaves `serving`.** A host cannot hold, skip, transfer
or cancel a ticket whose service has begun — that would make the ticket and the
stage disagree about a customer sitting in a chair, and the ticket, being the
thing on the television, is the one people would believe. Somebody who walks out
mid-service is an **abandoned visit** (§19).

## 8. Destinations

`queue_service_points`: a branch, an optional department, a translated name, a
short `display_code`, an optional `ticket_prefix`, and an **optional** link to an
`OperationalResource`.

A destination is not a resource. Sometimes it happens to be one; often it is not,
because nothing reserves a reception counter. **Calling a ticket to a
resource-linked point assigns nothing** — actual resource capacity is taken only
by the Phase 7 Journey Actions, under the branch lock, against the combined
occupancy check.

`display_code` is unique per branch: two "R1"s make a call ambiguous at the exact
moment it has to be obvious.

A polymorphic "anything can be a destination" was rejected. Every consumer would
have to know which kind it held, and a television needs one thing — a short code
and a name.

## 9. Displays

A first-class record, because the public URL has to name something real:

```
/q/{center public key}/{display public key}
```

Two opaque, rotatable keys. A stolen screen or a leaked link is fixed by
`rotate()` without disturbing anything that references the row (ADR-027,
ADR-036).

**Three scopes, and only three**: branch-wide, one department, or one service
point. A screen at the door, a screen on the laser floor, a screen above a
counter. Validated against the branch, so a screen can never show another
branch's calls.

Configurable: language, recent-call count (clamped 1–20), sound, voice, and the
ordered list of voice locales. **Never markup** — a display is a guest page, and
center-authored script on one is stored XSS against that center's own customers
(ADR-038).

**Fails closed**: unknown key, inactive, archived, or entitlement withdrawn all
answer 404, so nobody can enumerate a center's screens.

## 10. Priority and ordering

`priority` is a number — 0 normal, 10 high, 20 urgent — so a center that wants
something between them sets 15 rather than waiting for a release. Set explicitly
by a person, audited, and **never inferred** from anything about the customer.

The ordering, everywhere, with no exceptions:

```sql
WHERE branch_id = ? AND business_date = ? AND state = 'waiting'
ORDER BY priority DESC, issued_at ASC, id ASC
```

`issued_at` is untouched by a hold, so a customer who stepped out returns to
their place rather than the back of a Saturday queue. `id ASC` settles the tie —
DATETIME has one-second resolution and two walk-ins taken in the same second
share an `issued_at`.

## 11. Idempotency

Two invariants, both in the database:

- `unique(queue_tickets.active_journey_stage_id)` — one OPEN ticket per stage.
  Equal to `journey_stage_id` while open, NULL once closed, so history piles up
  freely. A partial index would be the obvious tool and neither engine has one; a
  generated column would be MySQL-8-only (ADR-033).
- `unique(service_journeys.idempotency_token)` — one walk-in per submission. The
  reception screen sends a token; a double-click collides and the Action returns
  the visit that already exists.

Both use the three-layer pattern check-in proved: a read for the ordinary repeat,
the index for the guarantee, a catch that re-reads and returns the winner.

## 12. Queue ↔ Journey

```
Booking ◀── Journey ◀── Queue          Queue ─▶ Resources, Departments
              │
              └── domain events ──▶ Queue listens
```

Journey states facts — `JourneyStageStarted`, `JourneyStageSettled`,
`JourneyAborted` — and does not know who listens.
`Queue\Application\SyncTicketsWithJourney` listens and keeps tickets in step.

**Synchronous, and inside the Journey transaction.** No `ShouldQueue`, no
broadcast. The events are dispatched inside the Action's transaction, so the
ticket and the stage commit together or not at all. A queued listener would leave
a stage `in_service` beside a ticket still `called` for as long as a worker took
— and forever on a center running no worker. A failure here takes the Journey
mutation down with it, which is the intended trade: refusing to start a service
is recoverable, and a ticket that quietly disagrees with the floor is not
(ADR-053).

Idempotent: replaying a fact finds the ticket already in the target state and
does nothing.

**The result: both boards behave identically.** "Start" on the visit board and
"start" on the queue board are the same Action producing the same event, and a
test asserts it.

## 13. Call, recall, and the announcement identifier

A recall **appends**. `queue_ticket_events` gets a new row with its own uuid, and
the ticket's `last_announcement_uuid` points at it.

That uuid is what the public feed exposes as `announcement_id`. A television
polls every three seconds and remembers which announcements it has spoken:

- an unchanged poll sees the same id and says nothing;
- a recall is a new event with a new id, so it speaks again;
- a reconnect re-reads the canonical feed and carries on.

Without it the screen would either repeat the same number forever or have to
guess from timestamps. With `called_at` overwritten in place, "called three times
then skipped" would be unanswerable as well.

## 14. Hold, skip, transfer

**Hold** — the host knows why. **Skip** — called, no answer. Both produce `held`
and are recorded as different history rows, because they mean different things
when somebody reads the day back. A skip also increments `skip_count`.

**A queue skip is not a stage skip.** `StageStatus::Skipped` means the customer
DECLINED the service; missing a call means nothing of the kind, and turning one
into the other would put "customer declined" in the record of somebody who was in
the toilet.

**Transfer** returns the ticket to `waiting` with a new destination — a
transferred ticket still showing `called` would be a customer standing at a
counter nobody expects them at. Earlier calls survive; the transfer appends both
the old and the new destination. Same branch only.

## 15. Real-time: the decision

Phase 8 is the first phase with a genuine real-time requirement, so the options
were compared before anything was installed.

| | Polling (chosen) | Laravel Reverb | SSE |
|---|---|---|---|
| New dependency | none | reverb + echo + pusher-js | none |
| Deployment | none | a daemon under supervisor, a second port | none |
| Reverse proxy | none | WebSocket upgrade, TLS for `wss://` | buffering must be off |
| Redis | no | yes, past one app server | no |
| Cost per screen | one short request per poll | one persistent connection | **one PHP-FPM worker, all day** |
| Tenant isolation | inherent | new: per-tenant channels, and the display is unauthenticated | inherent |
| Reconnect | trivial — the next poll is a full read | Echo reconnect plus resync | resync |
| `doctor --production` | unchanged | a new hard requirement | unchanged |

**Polling, and no new dependency.** A television showing a number three seconds
late is not a defect; a deployment that needs a supervisor-managed daemon and
Redis before a barbershop can show a queue is. SSE looks cheaper than Reverb and
is worse than polling here, because a display left on all day pins an FPM worker
for the whole day and this product has no async worker pool to absorb that
(ADR-052).

Staff board: `wire:poll.5s`. Display: a static page polling a bounded JSON feed
every 3s.

**Correctness never depends on delivery.** The database is the source of truth,
the feed is a complete read of it, and a screen that lost its network for an hour
recovers on its next successful poll. The domain events ship regardless, so a
realtime adapter can listen outward later without touching an Action.

## 16. Voice

A **semantic payload**, never a sentence built in PHP:

```json
{ "announcement_id": "…", "number": "A012",
  "lines": { "ar": "…", "en": "Ticket A012, please proceed to Room 3." } }
```

Templates live in `lang/{en,ar,ckb}/queue_public.php`. Building the sentence in
PHP would hardcode English word order into the backend and make Arabic and
Kurdish impossible to phrase properly.

> **The file is not called `queue.php`, and that is not arbitrary.** Laravel
> parses a dotless key as a GROUP, so `__('Queue')` — which the navigation uses
> — looks for `lang/en/Queue.php`. On a case-insensitive filesystem that FINDS
> `queue.php`, returns the whole array, and fatals in `htmlspecialchars`. It
> would have passed CI on Linux and broken every developer's machine. Two words,
> so nothing bare can match it.

Playback belongs to the display client, through `speechSynthesis`. No TTS
service, no stored audio, nothing to keep in step with a renamed counter. A small
client-side announcement queue serialises utterances so a rapid recall cannot
overlap one in flight.

**Kurdish Sorani, stated honestly: `ckb` speech synthesis is effectively
unavailable in mainstream browsers.** Arabic and English are widely present;
`ckb` will find no voice on essentially every TV browser. So the screen speaks
the locales it can, **always renders the text including `ckb`**, and plays a
chime when nothing can be spoken. It does not substitute an Arabic voice for
Kurdish text. A center that needs spoken Sorani needs recorded audio or a server
TTS service, which is a real cost and belongs in a later phase with a stated
requirement.

## 17. Printing

A **render seam**, not a print platform. `TicketPrinter` builds a payload; a
Blade view turns it into an 80mm page; the browser prints it. No ESC/POS, no
printer worker, no device credential, no spooler — none of which this product
needs yet and all of which would be infrastructure to operate from day one.

Content: center and branch, the number very large, destination, branch-local
time, optionally the service. **No customer PII by default** — a queue ticket is
left on tables and handed to whoever is next. Direction comes from the language
registry, so the same markup prints RTL.

Auto-print calls `window.print()` and a button is always there: browsers outside
kiosk mode may refuse without a gesture. Documented rather than worked around;
the workarounds are an extension or a local agent.

## 18. Permissions

Catalog **56 → 62**.

| Code | Covers |
|---|---|
| `queue.view` | board and feeds |
| `queue.manage` | issue, hold, resume, transfer, cancel, priority |
| `queue.call` | call, recall, skip |
| `queue.ticket.print` | render and print |
| `queue.display.manage` | service points **and** displays |
| `journey.walk_in.create` | start a visit nobody booked |

Transfer rides `queue.manage` and skip rides `queue.call` — the same operator at
the same desk, and a code nothing distinguishes is a promise rather than a
permission.

Roles: **Manager** all six · **Host** all but `queue.display.manage` ·
**Employee** `queue.view` only, board narrowed by the existing
`journey.view` / `journey.view_own` split · **Cashier** none. No Owner bypass, no
role-name conditionals. `metastyle:roles:sync --all` on deploy.

## 19. Entitlements

The catalog already declared these in Phase 3; Phase 8 is where they start being
enforced.

| Key | Gates |
|---|---|
| `queue_management` | every queue Action, walk-in **ticket** issuance |
| `queue_display` | the public feed and the display page |
| `queue_voice` | whether the display announces |

- **Walk-in visits are gated on `booking`**, so Booking and Journey keep working
  with no queue entitlement at all.
- Withdrawal blocks new queue operations; historical queue data stays readable to
  authorised management, matching the existing downgrade policy.
- No plan-name comparisons, ever.

### No package sells the queue yet

Phase 8 defined a CAPABILITY. Which commercial package receives it is a
different decision, made by different people, on a different schedule — and a
phase that quietly answers it has priced the product as a side effect of
shipping a feature.

So the three keys are in the catalog and in **none** of the seeded plans. A
center that wants the queue gets a per-tenant override, which is exactly how an
add-on is sold, and the assignment question stays open for the SaaS/package work
to answer on purpose.

This is also what keeps the entitlement tests honest: `QueueEntitlementTest`
proves each key blocks something real, and `TrialAndEntitlementTest` pins the
whole plan matrix as an EXACT set, so the next phase to slip a capability into a
package fails loudly instead of silently making other tests vacuous.

## 20. Branch scope

A ticket belongs to one branch, and nothing crosses:

- a branch's tickets never appear on another branch's display;
- a service point must stand at the ticket's branch to be called or transferred
  to;
- each branch has its own daily sequence, so two branches both start at `A001`;
- cross-branch transfer is refused, not merely discouraged.

`QueueAccess` checks entitlement, permission and branch scope in one place,
because the failure mode of repeating it is an Action that checks two of the
three.

## 21. Surfaces

**Staff API** — board, ticket detail, issue, walk-in, call, hold, resume,
transfer, priority, cancel, start, complete, abandon, print, service points,
displays.

There is deliberately **no** "mark this ticket completed" and no "set serving":
both follow the Journey fact.

**Staff screen** `/center/queue` — Waiting · Called · Serving · Held · Recently
completed, with filters and the walk-in form. Every button calls the Action the
API calls.

**Public display** `/q/{center}/{display}` — full-screen, read-only, no login, no
controls, polling the feed.

**Printable ticket** `/center/queue/tickets/{uuid}/print`.

## 22. Reception flow

```
phone or name  →  services  →  optional employee  →  create visit  →  ticket  →  print
```

One orchestration, one transaction: a failure part-way leaves nothing behind. Not
a booking form — reception is standing in front of a customer.

## 23. Audit

`queue.service_point.{created,updated,archived}` ·
`queue.display.{created,updated,key_rotated}` ·
`queue.ticket.{issued,called,recalled,skipped,held,resumed,transferred,cancelled,priority_changed}` ·
`journey.walk_in_created`.

No customer PII — the walk-in entry fingerprints the customer rather than naming
them. The display's public key is never audited: an audit row is readable by more
people than a display URL should be.

**Queue history is domain data, kept separately.** Audit answers "who changed
what, and were they allowed to"; `queue_ticket_events` answers "what happened to
this customer". A report must never be built on a security record.

## 24. Metrics readiness

Nothing is precomputed and no report ships. Everything a later phase needs is
derivable: wait time (`issued_at` → `first_called_at`), call-to-service
(`first_called_at` → `serving_started_at`), hold intervals, recall count,
transfers, abandonment, throughput by department.

## 25. Not in Phase 8

POS, invoices, payments, deposits, finance, commissions. Loyalty, memberships,
packages, reviews. Notifications, WhatsApp, RAYAN. Reports. Attendance and
payroll.

And, inside the queue itself: cross-branch transfer, ticket pre-booking, kiosk
self-service check-in, recorded-audio announcements, resource turnaround buffers,
and real-time push.
