# 22 — Reviews & Ratings

> Phase 12. Decisions: ADR-066 (a review is a capability over a completed
> visit). Notifications are `docs/23-NOTIFICATIONS.md`.
>
> Section numbers follow the Phase 12 specification, so a `§` in the code lands
> on the rule it means. The gaps are sections of that specification that belong
> to notifications rather than here.

A review is one customer's account of one visit that actually happened. It is
not a rating board, not a testimonial feed, and not a financial record.

## 1. The shape of it

| | |
|---|---|
| **ReviewInvitation** | the right to review one visit, once — a capability |
| **Review** | what the customer said: an overall score, and their words |
| **ReviewRating** | the optional detail: this service, this person |

## 2. Where it sits

Reviews is a TOP-layer module. It reads the visit, the catalog, the team and —
for traceability only — the invoice. Nothing below imports it, and an
architecture test names every module that must not.

Reviews does not import Notifications either. It emits `ReviewInvitationIssued`
and `ReviewSubmitted`; Notifications listens. Eligibility, the capability
check, the rating targets and the moderation trail all live in the Actions, and
a scan refuses any of them in a controller or a Livewire component.

## 3. Eligibility: something has to have happened

A **completed `ServiceJourney` with at least one COMPLETED `JourneyStage`**.
The customer arrived, and something was actually performed.

- A visit that was abandoned produces no invitation.
- A visit where every stage was skipped produces none either: the customer
  declined the service, and there is nothing to rate.
- A visit still running is not over.

**Payment is deliberately not required.** A complimentary visit, a service
covered entirely by a package and a bill settled next week are all real visits,
and tying feedback to money would quietly silence exactly the customers a
center most wants to hear from. `invoice_id` is a nullable traceability link
and nothing reads it to decide anything.

**A customer account is not required either.** Most of a center's customers
will never have a login; the link is the identity (§5).

## 4. One visit, one invitation

`review_invitations.service_journey_id` is **UNIQUE**. A repeated
`JourneyCompleted` — a retry, a second worker, an hourly reconciliation — hits
the index and the second attempt quietly does nothing. The invariant belongs to
the database, not to a check somebody has to remember.

`ReviewSync` is the other half: `JourneyCompleted` schedules the minting for
after the visit's transaction commits (ADR-061), and an hourly pass finds
completed visits with no invitation. A center whose review table was
unavailable for a minute still closed its visits — the alternative, a visit
that cannot be finished because feedback could not be arranged, is absurd on a
salon floor.

## 5. The token

The same bearer capability as an invoice share link, treated the same way
(ADR-035, ADR-058):

```
generated   256 bits of CSPRNG output, 64 hex characters
stored      SHA-256 only — review_invitations.token_hash
plaintext   exists in one place: the URL the minting call returned
```

Never a column, never a log line, never an audit row, never a notification
payload. A copy of the database opens no review form.

**A later read cannot show the link again.** "Send it to them again" mints a
NEW one and retires the old — exactly how `RotateInvoiceLink` behaves, and also
what a desk wants when a link went to the wrong number. `ManageReviewInvitation::reissue()`
returns the plaintext once; `revoke()` kills a link without issuing one.

SHA-256 rather than a slow hash, deliberately: there is no dictionary to slow
down against 256 random bits, and the public lookup has to be an indexed
equality match.

A signed-in customer needs none of this — see §40.

## 6. Lifetime

`reviews.invitation_valid_days`, thirty by default, read at MINT time and
stored on the row. Changing the setting never moves an expiry a customer was
already given.

**Expiry is not a status.** `expires_at` compared to now is the whole truth, so
nothing can be expired in fact and `issued` in the table because a sweep did
not run. The statuses are `issued`, `used` and `revoked`; `used` is terminal.

## 7. The review itself

```
reviews
  review_invitation_id   UNIQUE
  service_journey_id     UNIQUE
  branch_id              snapshotted from the visit
  customer_id            nullable — a guest has no account, and may have no record
  invoice_id             nullable — traceability only
  status                 submitted | hidden | flagged
  overall_rating         1..5, REQUIRED
  public_comment         nullable, bounded, customer-authored
  submitted_at
  moderated_at / moderated_by_id / moderated_by_label / moderation_reason
```

The overall score is a COLUMN rather than a row in `review_ratings`, so the
visit's score can never be missing, duplicated or disagreed with.

## 8. Three statuses, and no workflow

```
submitted ──hide──▶ hidden ──unhide──▶ submitted
    └────flag─────▶ flagged
```

There is no queue, no approval step and no "pending": a review is published to
the center the moment it is submitted. Hiding is the exception, taken by a
person who has to give a reason.

`flagged` marks something for internal attention and **still counts** —
flagging asks colleagues to look, and an average that moved the moment somebody
clicked "look at this" would measure attention rather than service. `hidden`
does not count.

Nothing deletes a submitted review. A privacy erasure is a separate decision
with its own rules, not a button on a moderation screen.

## 9. Dimensions

An **overall** score is required. **Service** and **employee** ratings are
optional, and there is one public comment for the whole visit rather than a
comment box beside every line.

Integers, 1–5, validated strictly. Averages are decimal READ-MODEL outputs and
are never stored (§25).

## 10. Rating rows, not JSON

`review_ratings` is structured because these are the columns the averages are
grouped by, and an aggregate over a JSON document is a scan that gets slower
with every review a center collects.

`journey_stage_id` is NOT NULL and is the proof. `unique(review_id, dimension,
journey_stage_id)`: one score per dimension per performed service, so a double
submit cannot double-weight a stylist.

**A rating names a STAGE, and everything about it is read from that stage.**
The request never says what it is rating, which is why the bad shapes are not
reachable rather than merely refused.

## 11. Employee ratings follow execution, not booking

`journey_stages.employee_id` is who ACTUALLY performed the work;
`appointment_items.employee_id` is who was booked. A customer rates the person
who was there.

If no employee was ever recorded against the stage, there is no target and the
rating is refused — inventing one would credit or blame somebody the record
does not name.

## 12. Service ratings follow performance

Only a COMPLETED stage. A skipped stage is a service the customer declined; a
waiting one never ran; an appointment-only service that was never performed has
no stage at all.

## 13. The center's rating

The overall score belongs to the visit, and through it to the center. There is
no `average_rating` column on a branch, a service or an employee: a stored
average is a second source of truth that must be recomputed on every
submission, every hide and every unhide, and is wrong in between. Caching is a
later, deliberate decision with its own invalidation rules.

## 14. Submission

```
BEGIN
  lock the invitation                  ← the capability, FOR UPDATE
  issued, not revoked, not expired, not already used
  lock the visit; completed, and something was performed
  the overall score is 1–5
  every detail rating names a COMPLETED stage of THIS visit
  write the review and its ratings
  mark the invitation used
  announce it
COMMIT
```

Two tabs, two phones, one link pressed twice: both take the invitation's row
lock and the second reads `used`. And if that check were removed tomorrow, the
two unique columns are the backstop. A concurrency test proves it with a real
second connection.

A double submit gets `REVIEW.ALREADY_SUBMITTED` (409) and the page shows the
thank-you — never a second review.

## 15. The customer's words are immutable

`overall_rating`, `public_comment`, `submitted_at`, `service_journey_id` and
`customer_id` are refused at the MODEL once written. A center that could edit a
review would be publishing its own opinion under a customer's name.

Moderation changes `status` and records who, when and why beside it. The
content stays exactly as it arrived.

## 16. Staff follow-up

No public reply threads in Phase 12. A low rating raises an internal
notification (§17); anything more belongs to the existing Notes architecture,
and a support-ticket subsystem is not this phase.

## 17. Low-rating follow-up

`reviews.low_rating_threshold`, two by default, read at the moment a review
arrives — so changing it never rewrites which past reviews did or did not raise
an alert.

At or below it, an `important` notification goes to the staff holding
`review.manage` in THAT branch. After the review commits, always: the review is
the customer's truth and is never lost, never refused, because an internal
alert could not be written (`docs/23-NOTIFICATIONS.md` §11).

Public error codes deliberately say less than internal ones: the
unauthenticated page maps unknown, revoked and expired to ONE generic 404
(§46).

## 18. Entitlement: `reviews`

ONE key, not three. The QR image and the moderation screen are presentations of
the same capability; selling them apart would be three switches a center has to
understand to get one feature. No seeded plan sells it yet — like the queue
keys, a phase defines a capability and the SaaS work decides which package gets
it.

**It depends on nothing.** A review is about a completed `ServiceJourney` with a
performed stage, and a journey is a WALK-IN as readily as a booked visit:
`service_journeys.appointment_id` is nullable exactly so a walk-in never has to
invent an appointment (ADR-051). Declaring `requires => ['booking']` would have
had the dependency closure silently drop `reviews` from a walk-in-only center —
the entitlement would simply not be in the effective set, with no error anywhere
— and would have tied a feature about visits that ALREADY HAPPENED to a
capability about arranging future ones.

Runtime eligibility proves itself independently (§3): a completed journey, and
at least one completed stage. Nothing in `Modules\Reviews` asks about `booking`,
and an architecture test keeps Reviews out of Booking altogether.

> Separately, and not a Reviews rule: every `ServiceJourney` ACTION — creating a
> walk-in, moving a stage, completing a visit — gates on `booking` today, which
> is the Phase 7/8 decision that walk-ins are sold with booking. That governs
> PERFORMING a visit. It does not govern reviewing one that is already finished:
> a center that loses `booking` keeps its history, and keeps collecting,
> reading and moderating reviews on it.

**A downgrade stops issuing, and nothing else.** Losing `reviews`:

- blocks new invitations, from the listener, from reconciliation and from the
  desk;
- does NOT block a customer submitting through a link the center already gave
  them. That capability was granted while the center owned the feature, and
  taking it back punishes the customer for a decision they had no part in;
- does NOT block staff reading their reviews, or HIDING one. A center that
  cannot moderate what is already published is worse off than one with no
  reviews at all.

## 19. Permissions

`review.view` reads reviews, ratings and the summaries within branch scope.
`review.manage` hides, unhides, flags, and issues or revokes a link.

Two codes, and deliberately not six: a rating with its review hidden is a
number nobody can act on, and "may read service ratings but not employee
ratings" is not a job anybody has. No Owner bypass — the Owner role holds both
as explicit grants (ADR-029), so `metastyle:roles:sync --all` is a deploy step.

## 20. QR

**No QR library is installed.** The repository has none, and Phase 12 does not
add a dependency to get one: the review URL itself always works, and a text
link is a complete feature.

If a QR image is wanted later, `endroid/qr-code` or `bacon/bacon-qr-code` is
the candidate, needs a `docs/DECISIONS.md` entry before installation, and would
encode the capability URL and nothing else — never a name, a phone, an employee
id or a service id.

## 21. The public page

`{slug}.{base}/r/{token}` since Phase 15 — the center's OWN host and the
capability, resolved by `ResolvePublicTenant` (the host must be the center's
registered domain and the `{center}` segment its slug). `ReviewInvitations::url()`
therefore takes the slug of the host the request resolved on (the Manager, or an
API call made on the center's host), exactly like `InvoiceLinks::url()`; the
public key only when there is no center host at all. NO authentication
middleware, ever: most of the people who open it have no account and never will.

It shows the center, the branch, the date, what was performed and by whom, and
the form. The only identifier on it is the STAGE uuid, because a rating has to
name what it is rating; a stage uuid opens nothing, and submission accepts one
only for the visit the presented link already unlocked. The token appears once
more, as the form's action — exactly as the invoice page posts back to its
share link.

Not on the page: the journey uuid, the sale, the invoice, prices, notes,
payment, audit, customer details or employee ids. `noindex`, `no-referrer`.

AR / EN / CKB, direction from the language registry. The center authors no part
of it (ADR-038).

## 22. After submission

A thank-you page. No token, no resubmission, no promotional upsell. A link that
has already been used gets the same page: whoever holds it has already proved
they had it, and being told "thank you, we have your review" is better than
being told nothing.

## 23. The staff screen

`/manager/reviews` (Phase 15 Manager): a summary for the chosen BRANCH and DAYS
(`Reviews\Summary`: average, 1–5 spread, per-service and per-employee averages,
all from `RatingSummary`), filters (branch within the viewer's scope, from/to,
status, rating, service, employee), the list in keyset pages by review uuid
(`ReviewsQuery::pageBefore` — no numeric id in component state), and hide /
flag / unhide with a reason. A plain `from`/`to` date is a branch-local day and
`to` INCLUDES that day (`ReviewDays`: one half-open UTC window per branch
timezone, one query) — for the API too; a full timestamp is compared as given.
The customer page shows that customer's reviews (`customer` filter). Losing
`reviews` with reviews collected keeps the page readable and moderation working
(compact notice); with none collected it is the upgrade page.

The presenter is an allow-list — the review's uuid, its status, its scores, the
comment, the moderation trail and the NAMES of the branch and of what was
rated. Never the journey uuid, the invitation, the token digest, the sale, the
invoice, the customer, or any numeric key.

## 24. The rating summary read model

`RatingSummary` is the ONE place an average is computed: count, average, the
1–5 distribution, per-service and per-employee averages. Two screens each doing
their own `avg()` is how a center ends up with a dashboard and a report that
disagree about its own score.

What counts: `submitted` and `flagged`. Not `hidden`.

## 25. Precision

Individual ratings are integers. The display precision is decided once —
`RatingSummary::PRECISION`, one decimal. A center with no reviews has `null`,
not `0.0` and not `5`.

## 40. Telling a customer their review is wanted

When a visit completes and is eligible, the invitation is minted and
`ReviewInvitationIssued` is announced. For a signed-in customer, Notifications
puts `review_invitation_ready` in their inbox carrying the invitation's UUID —
never a secret. Their own account resolves it, so nothing has to copy a
capability into a second table to reach them.

For a guest the invitation exists and the desk can hand over its link. Phase 12
builds no messaging provider to send it for them.

## 41. Idempotency

One invitation per visit (§4), one review per invitation and per visit (§14),
one inbox row per notification per person (`docs/23` §12). Repeated events
produce nothing new at any layer.

## 45. Audit

Written: invitation issued, reissued and revoked; review submitted; hidden,
unhidden and flagged, with the moderation reason. Category `customer`.

Never written: the plaintext token, its digest, the customer's comment, the
customer's name or phone. An audit row is readable by more people than a
customer's review should be. Notification reads are not audited at all.

## 46. Privacy on the public surface

Unknown, revoked, expired and not-eligible are ONE generic 404. Distinguishing
them tells a stranger that a particular link existed, which is a visit, which
is a customer. The single exception is a link that has already been USED (§22).

A customer-facing surface never exposes employee ids, phone numbers, notes,
payments, finance, loyalty internals, audit rows or exception messages.

## 47. Moderation

`review.manage`, within branch scope. Every action records the actor, the time
and — for hiding — the reason, and is audited. Hiding without a stated reason
is refused: a review taken out of a center's ratings with no reason given is
indistinguishable from one taken down because somebody disliked it.

Staff may never rewrite a rating or a comment (§15).

## 49. Tenant isolation

`review_invitations`, `reviews`, `review_ratings`, `notifications`,
`notification_recipients` and `notification_preferences` all live in the tenant
database, with no `tenant_id` column. A capability minted at one center resolves
nothing at another — including the same secret presented at both — and with no
center bound every query fails closed.

## 50. Branch isolation

A review's branch is snapshotted from the visit. `ReviewsQuery` applies
`BranchScope` to every read, so a manager of one branch cannot reach another's
reviews by changing a filter, editing a URL or calling the API directly. A
review outside scope is 404, never 403. Staff targeting for the low-rating
alert is branch-scoped the same way.

## 51. Concurrency

Real second-connection tests: two simultaneous submissions of the same link
produce exactly one review; the loser reads the committed state and is refused.
Database constraints are the backstop underneath the locks, not the other way
round. No Redis lock is used for correctness anywhere.

## 52. Query budgets

- The staff page is one query for the reviews and ONE for all of their ratings,
  grouped in PHP; branch, service and employee names resolve in three more.
- `RatingSummary` is three grouped aggregates, whether there are two reviews or
  five thousand — asserted as "does not grow", not as a magic number.
- No per-review aggregate, and no per-employee average computed in a loop.

## 56. Comment safety

A comment is untrusted text. It is trimmed, bounded to
`Review::MAX_COMMENT_LENGTH` (1 000), stored EXACTLY as written, and escaped by
every surface that renders it. Nothing sanitises it on the customer's behalf: a
sanitiser that quietly edited their words would be publishing something they
did not say. No HTML is accepted or rendered anywhere.

## 58. Routes

```
GET  {center}.{base}/r/{token}   public.tenant · locale · throttle:public-review
POST {center}.{base}/r/{token}   public.tenant · locale · throttle:public-review
```

The POST is the sixth entry in `PublicRouteBoundaryTest`'s exact allow-list of
public writes, with its own limiter — six a minute and thirty an hour per
center and address, below what reading the menu gets. CSRF-protected like any
form.

## 75. Downgrade, proven

A test issues an invitation while `reviews` is owned, revokes the entitlement,
and then requires: the issued link still submits exactly once; a new visit
issues nothing, from the listener or from reconciliation; and the desk cannot
mint one either.

## 80. What the tests must cover

One invitation per completed visit · none for a skipped-only or unfinished
visit · a repeat event creates no duplicate · the token hashed at rest and
absent from the database, logs and audit · a valid submission · expired,
revoked, reused and unknown tokens · simultaneous submissions · ratings outside
1–5 · an unrelated service · a skipped service · the performing employee · a
stage with no recorded employee · comment escaping · hiding preserving content
· hidden excluded from the averages · downgrade behaviour · tenant isolation ·
branch scope.

And, for §18 specifically (`ReviewEntitlementTest`): `reviews` declares no
prerequisite and survives the dependency closure on its own, while a key that
really does depend on something does not — so the closure is proven to be
running rather than passing everything through · a WALK-IN journey with
`appointment_id` null is invited and submitted · `reviews` off issues nothing,
from the listener, from reconciliation or from the desk · a finished walk-in
stays reviewable, and a fresh link stays issuable, after `booking` is revoked ·
the booked flow is unchanged.
