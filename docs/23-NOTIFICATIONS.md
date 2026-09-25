# 23 — In-App Notifications

> Phase 12. Decisions: ADR-067 (notifications listen, and never hold anything
> up). Reviews are `docs/22-REVIEWS.md`; the two arrived together because the
> first thing worth telling a manager about is a one-star review.

A notification is a record that somebody was TOLD something. It is not the
thing they were told about — the appointment, the invoice, the activation and
the review are all the record, and all of them outlive this table.

Two consequences run through everything below. A notification can never change
what happened, and a notification that could not be written can never undo it.

## 1. What this is, and what it is not

| | |
|---|---|
| **Notification** | one fact worth telling somebody about |
| **NotificationRecipient** | one person's copy of it, and their read state |
| **NotificationPreference** | one person's answer for one optional switch |

It is not the audit log. Audit answers "who changed what, when", is written
inside the transaction it describes, and is kept for years
(`docs/08-AUDIT-SECURITY.md`). This answers "was this person told", is written
after the fact commits, and is swept after six months. An architecture test
keeps `Modules/Notifications` from importing `Kernel/Audit` at all.

It is not domain truth either. Nothing reads a notification to decide anything.

## 2. One channel: IN-APP

Creating the recipient row IS delivery. There is no queue, no provider, no
retry schedule and no delivery status, because for an inbox there is nothing
that could fail after the insert.

Explicitly **out of Phase 12**: WhatsApp, SMS, email infrastructure, APNs, FCM,
web push, and any third-party notification provider. Each of those is a phase
with its own entitlement, templates and consent rules, and each one starts by
somebody adding one innocuous call to this module — which is why an
architecture test scans it for `Mail::`, `Http::post`, `Twilio`, `Firebase` and
the rest.

A guest (no customer account) has no inbox, so a guest's booking confirmation
goes out on WhatsApp instead — from `Modules/Conversations`, which listens to
`AppointmentConfirmed` itself (docs/25 §22). This module still sends nothing and
learns nothing about it; a registered customer keeps `appointment_confirmed`
here and gets no WhatsApp copy.

There is no `notifications` entitlement. Operational notifications are part of
running a center, not a feature sold separately (`docs/05-ENTITLEMENTS.md`).

## 3. The types, and why there are only eleven

Every type had to answer the same question before it was added: **who receives
this, and what do they DO about it?** A notification nobody acts on is noise,
and an inbox full of noise is an inbox nobody reads — at which point the one
that mattered is lost too.

**To the customer**, when the center acted and they were not there to see it:

`appointment_confirmed` · `appointment_rescheduled` · `appointment_cancelled` ·
`appointment_reminder` · `invoice_issued` · `membership_activated` ·
`package_activated` · `membership_expiring` · `package_expiring` ·
`review_invitation_ready`

**To staff**, when somebody has to look at something:

`low_rating_received`

**From Meta Style itself** (Phase 15), to staff holding `platform_support.view`
(the owner always does): `platform_announcement`, and `platform_notice` for an
announcement the Super Admin marked important. The module listens for
`PlatformAnnouncementPublished`, raised inside the center's tenant context by
one queued delivery job per center; the title and body are read at display
time from the announcement in the requested language (`PlatformAnnouncementText`),
never copied into the notification. A notice cannot be switched off by a
preference. See `docs/29-PLATFORM-ADMIN.md` §5.

Deliberately absent: `appointment_created` (the person who booked it was
there), `payment_succeeded` (they just paid), `loyalty_points_earned` (every
bill would produce one) and `journey_completed` (they walked out).

The AUDIENCE belongs to the type, not to the call site. `NotificationType::audience()`
is what makes "a staff alert addressed to a customer" unreachable rather than
merely unlikely.

## 4. Two kinds of recipient, and no third

A **staff user** in `users`, or a **customer's login** in `customer_accounts`.
Different tables, different guards, unrelated id sequences — so a recipient row
carries `recipient_kind` AND `recipient_id`, there is no foreign key, and every
query filters on both. A query that filtered on the id alone would hand staff
user 7 the inbox of customer account 7, and would look correct in any fixture
where those two numbers happened to differ. A test pins them equal.

A **guest** — a customer with no account — has no inbox, and none is invented:
a pile of unread rows attached to somebody who cannot sign in is not a feature.
What a guest genuinely needs reaches them as a capability URL the desk hands
over: their invoice link, their review link.

## 5. Targeting staff by permission, never by role

"Tell the managers" is not expressible here, on purpose: a center may rename
its roles, split them, or give `review.manage` to one senior stylist (ADR-029).
The question is always **who may act on this** — who holds the PERMISSION, and
who may work in the BRANCH it happened in. `StaffTargets::withPermission()` is
the only answer, and a Branch A manager never sees Branch B's alert.

## 6. Within one center, and only one

Every recipient read here lives in the tenant's own database. There is no
cross-center audience and no place to ask for one. A platform-wide announcement
is Super Admin's, in its own phase.

## 7. Parameters, not sentences

`notifications.params` carries the small allow-listed VALUES a message needs —
a time, an invoice number, a count. The sentence is built at READ time from
`lang/<locale>/notifications_inbox.php`, so a customer who switches to Kurdish
sees their whole inbox in Kurdish, including last month's.

Never stored: rendered text, HTML, a serialised model, an internal id, money,
or anything customer-authored. A one-star alert carries the SCORE and not the
comment — the comment lives in the review, where staff read it with everything
else about that visit. An architecture test scans the module for `view(`,
`Blade::`, `<br`, `nl2br(` and friends.

## 8. Preferences: absent means ON

A row exists only where somebody turned something off, or back on again. A new
customer needs no rows, and a new key defaults to enabled everywhere without a
backfill.

Three switches, deliberately coarse: `appointment_reminders`,
`benefit_expiry`, `review_invitations`.

A preference affects DELIVERY, never truth, and it can only suppress a type
that names a key. A cancellation somebody else made, an issued invoice and a
one-star review have no key and are always sent: a setting that could hide them
would make the inbox an unreliable record of what the center did.

## 9. Severity: two levels

`normal` and `important`. The second exists because a one-star review a manager
has not seen is not a membership renewal confirmation — it changes presentation
and retention. It is NOT an escalation policy, a paging rule or a delivery
guarantee, and Phase 12 builds none of those.

## 10. The smallest correct schema

```
notifications              the FACT: type, severity, source, branch, params
notification_recipients    one row per person: read_at
notification_preferences   one row per person per switch they changed
```

There is no `notification_deliveries`. In-app is the only channel, and a
`sent` / `failed` column for something that cannot fail is a status that lies.

## 11. A notification never rolls anything back

Every listener schedules its work with `Kernel\Database\AfterCommit` — the same
rule benefits follow for money (ADR-061), for the same reason. The booking, the
payment, the activation and the review commit first, on their own; the inbox
row follows. A failure is reported as `AfterCommitFailed` and dropped.

So none of these is possible: a cancellation refused because an inbox row
failed; a review lost because an internal alert could not be written; an
activation rolled back by a notification. Tests inject a failure at the
CONNECTION for exactly this — the inbox is written with `insertOrIgnore`, so a
model hook would never fire.

## 12. Delivered twice is delivered once

Nothing asks "have I already sent this?" — that question races with itself.
Two unique indexes answer it instead:

```
notifications             unique(type, source_type, source_uuid)
notification_recipients   unique(notification_id, recipient_kind, recipient_id)
```

The same event heard twice, a sweep that overlaps the previous one, a
reconciliation pass and two workers all converge on one row and one inbox line.
That is what makes overlapping windows free and catch-up after an outage safe.

## 13. Appointment reminders

A bounded SWEEP on a clock, not a delayed job per booking. One job per
appointment would mean a job for every appointment a center ever takes, each
holding a time a reschedule silently invalidates, with no way to find the stale
ones.

- `notifications.reminders.lead_minutes` — one number, a day by default.
- Only `booked` and `confirmed`: the two statuses that occupy the calendar.
  Cancelled, completed and no-show are all past tense.
- Bounded by an indexed range on `appointments(status, starts_at)` and by
  `max_per_run`.
- The time is rendered in the BRANCH's own clock and carried with its offset —
  "14:30" means 14:30 where the customer is going.
- A **reschedule or a cancellation deletes the reminder that was already
  written**, and the sweep writes a fresh one for the new time. A customer must
  never be left holding a notice for an hour nobody expects them.

## 14. Expiry warnings

One threshold, one notification per membership or package, keyed on the benefit
itself. Not a drip sequence and not a second reminder a few days later: a
center that wants to chase expiring benefits properly is asking for marketing
campaigns, which is a later phase with an audience and an unsubscribe.

## 15. Retention

`notifications.retention.days` — six months by default. What is deleted is the
fact that somebody was told; the appointment, the invoice and the review all
remain.

**An UNREAD `important` notification is never swept**, whatever its age. In
Phase 12 that is the one-star review nobody has looked at, and a cleanup that
quietly removed it would be the one case where tidying up loses something a
center is answerable for. Once it has been read, it ages out like everything
else.

## 16. What a reader is allowed to see

An allow-list, named field by field: `id`, `type`, `severity`, `message`,
`source` (type and uuid), `created_at`, `read_at`. Never the recipient id, the
branch id, the raw parameters or any numeric key.

Somebody else's notification is **404, not 403** — a 403 still confirms it
exists. There is no endpoint anywhere that reads another person's inbox, for
staff or for anyone.

The recipient always comes from ONE named guard. `StaffNotificationController`
reads `sanctum`; `CustomerNotificationController` reads `customer-api`; both
share `Http\Concerns\ReadsAnInbox`. Asking several guards in turn until one
answers is how a staff request ends up reading a customer's inbox on the second
request of a test — and the tests refresh guard state rather than accommodate
it.

## 17. Query budgets

The inbox page and the unread count are one query each, against
`notification_recipients_inbox_index` (kind, id, read_at). Neither counts in
PHP; neither loads a history to answer "how many unread".

## 18. Bulk lookups

A reminder sweep walks many appointments across a handful of branches.
`BranchTimes` resolves each branch's zone once, because a query per appointment
for an answer that never changes is the shape that gets slower every month.

## 19. The scheduler

`metastyle:notifications:sweep`, hourly, through the existing scheduler:
reminders, expiry warnings and retention, in that order, per center.

- **Per-tenant fault isolation**: each center is attempted independently, a
  failure is reported and stepped over, and the command exits non-zero
  (`docs/02-TENANCY.md` §7).
- Every pass is bounded and idempotent; nothing retries and nothing loops. The
  next scheduled run IS the retry, and it finds exactly what this one left.
- Correctness never depends on it: a missed hour means a reminder arrives an
  hour later, not a lost appointment.

## 20. Surfaces

| Who | Where |
|---|---|
| Staff | `/manager/notifications` — inbox, unread filter, load more (cursor, 10 pages max), mark read, preferences |
| Staff (shell) | the topbar bell (`Center\Shell\NotificationBell`): polls every 20 s while visible, six newest, per-item and all mark-read, a chime only for a row newer than the session's high-water mark (`Inbox::latestUnreadId`, kept in the session, never in the page); sound mute is per browser |
| Staff API | `api/v1/tenant/notifications*` |
| Customer | their account page — inbox and pending review invitations |
| Customer API | `api/v1/customer/notifications*` |

Functional screens only. No final visual design in this phase.

## 21. What Phase 12 deliberately did not build

- Any channel but in-app (§2).
- A delivery-status model, an outbox, or a retry queue (§10).
- Escalation, paging or digest rules (§9).
- Campaigns, audiences, segments or unsubscribe links (§14).
- Cross-tenant broadcast (§6).
- A generic "send a notification" API for clients to call.
