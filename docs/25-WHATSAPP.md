# 25 — Conversations and the WhatsApp channel

Phase 13. A center talks to its customers on WhatsApp, from the center's own
number, and a person can take over at any moment.

---

## 1. What this is not

Not a support-ticket system. No priority, no SLA, no queue, no assignment rules
engine, no tags, no macros, no satisfaction survey. A conversation here is: this
phone number, this center, what was said, and whether a human or the assistant
is answering.

Not a marketing channel. There is no broadcast, no campaign, no list.

## 2. The four tables

| Table | Job |
|---|---|
| `whatsapp_accounts` | the center's own provider account and credentials |
| `conversations` | one thread with one phone number |
| `messages` | what was said, in one direction, by one kind of author |
| `whatsapp_webhook_events` | which provider notifications were accepted, once |

## 3. Direction and author are separate

`direction` is about the wire and decides whether a delivery state means
anything. `author_type` is about accountability.

They are not interchangeable: a `system` note and a `staff` reply are both
outbound, and only one was written by a person. "The bot said it" and "one of my
staff said it" are completely different conversations for a center to have, and
a thread that cannot tell them apart is useless for exactly the case it most
needs to serve.

## 4. The center owns the number

Messages are sent from the CENTER's WhatsApp Business number using the CENTER's
access token. Meta Style never becomes the sender of record and never pools
centers behind one number — which would put one center's customers into
another's inbox the moment a number was reused.

Credentials are `encrypted:array`, `$hidden`, read through one method that fails
closed after an application key change, and never presented, logged or audited.
Replacing them is wholesale, never a merge: a half-filled form would leave an
account that looks configured and cannot verify a signature, which is the
hardest failure to diagnose from outside.

**There is no URL column, anywhere.** Where Meta lives is platform
configuration. A tenant-supplied destination would be server-side request
forgery by configuration — with the center's own token attached (ADR-071).

## 5. The provider seam

`Conversations\Contracts\MessagingProvider`. One real adapter
(`MetaWhatsAppCloudProvider`) plus `UnsupportedMessagingProvider` for anything
listed and not implemented — the same shape as the payment gateway seam, and for
the same reason: a fabricated integration is worse than an honest refusal.

`MessagingCapabilities` states what the adapter can actually do, so generic code
above it never assumes a feature a provider lacks.

## 6. Identity comes from the envelope (ADR-070)

The sender's number comes from
`entry[].changes[].value.messages[].from`, in a notification whose signature
verified against the center's own app secret. It is normalised through
`Kernel\Contact\PhoneNumber` and resolved against customers **in this tenant
only**.

That proves control of the number FOR THAT INTERACTION. It does not prove the
person is the customer whose record holds it — a phone changes hands, a family
shares one — which is the same evidence a receptionist has when somebody phones
the shop, and it is treated the same way.

**It is never a stored flag.** No `whatsapp_verified`, no `verified_phone`, and
nothing writes `phone_verified_at` (ADR-040 still holds). A conversation may
retain a resolved `customer_id` for context; every future inbound message is
still verified from its own signature. Trust is per-message.

The same number in two centers is two unrelated people. There is no global
directory, and building one would make Meta Style a cross-center identity
broker.

Nothing else may ever establish who is speaking: not message text, not an AI
tool argument, not a query parameter, not a field somebody added to the JSON.

## 7. First-time senders

A sender nobody recognises gets a conversation with `customer_id` NULL. That is
an ordinary state.

No customer record is created. A wrong number, a supplier, somebody's cousin —
none of them is a customer, and filling a center's CRM with every number that
ever messaged them makes the CRM useless. A record is created later, by the
Booking Engine's own resolver, at the moment somebody actually books, from the
VERIFIED phone number.

No `CustomerAccount` is ever created. An account is a login, and messaging a
salon is not a request for one.

## 8. Webhook security — the order is mandatory

```
resolve tenant (public.tenant, from the center's public key)
  → find the account by its public uuid, enabled or not
  → load the adapter, decrypt the center's credentials
  → VERIFY THE SIGNATURE
  → only now: parse the body
  → replay check, per item, by fingerprint
  → rate limit, per account and per sender
  → process
```

Nothing before the signature check touches a customer, a conversation or the
assistant. An unsigned request cannot create a conversation, resolve a phone
number, reach a tool, or spend a center's AI allowance.

`X-Hub-Signature-256` is `sha256=` plus the hex HMAC-SHA256 of the **raw bytes**
with the app secret. Decoding and re-encoding the JSON first reorders keys and
renormalises escapes, and the signature over the result never matches — that is
every "signature verification is broken" bug in this shape of integration.

An account with no app secret verifies nothing, rather than comparing against an
empty key — which is the one way the check can accidentally succeed.

Rejected notifications are RECORDED, with `signature_verified = false` and a
reason code. A burst of failed signatures is the most useful thing an operator
can see, and discarding them silently makes an attack indistinguishable from a
quiet afternoon.

The endpoint answers an empty `403` for every refusal — unknown account,
unusable credentials, bad signature, malformed body — deliberately
indistinguishable. The reason lives on the event row.

The registration handshake (`hub.mode`, `hub.verify_token`, `hub.challenge`)
echoes the challenge as plain text, and only when the token matches under
`hash_equals`. PHP rewrites dots in query keys to underscores (`hub_mode`), so
`InboundEnvelope::queryValue()` accepts both spellings — before that fix the
handshake could not succeed over real HTTP. A successful handshake is recorded
as ONE `kind = handshake` event row per account (fixed fingerprint, refreshed
`received_at`; no token, no challenge), which the Manager shows as "webhook
verified". Without `whatsapp_booking` the handshake is refused like a wrong
token: no provider connection can be activated.

The callback address is `{slug host}/api/v1/whatsapp/{slug}/accounts/{uuid}/webhook`.
Since Phase 15 `ResolvePublicTenant` accepts a public route only on the center's
own host with that host's slug in the path, so `WhatsAppConnections::webhookUrl()`
uses the `center` URL default the center host set (the Payments `callbackUrl`
rule); off-host it still falls back to the public key, which does not resolve.

## 9. Delivery states, and `unknown`

`pending · sent · failed · unknown`.

The fourth exists because of a failure mode every outbound integration has and
most pretend not to: the request was sent and the application never learned the
outcome.

- calling it `failed` invites a retry, and the retry sends the customer a SECOND
  copy of a message they already received;
- calling it `sent` claims a delivery nobody observed.

So it stays `unknown`, generic code **never** blindly retries it, and staff see
it. Meta's send endpoint takes no caller-supplied idempotency key (verified), so
there is no way to make a retry provably safe — which is exactly why the state
exists. Meta also publishes no endpoint to read one message's status on demand,
so an `unknown` is resolved by a later status callback or by a person.

Only a `failed` raises `ProviderSendFailed`. Alerting on every transient
`unknown` trains staff to ignore the alert that means the integration is really
broken.

## 10. Persist first, send second

```
1. write the message row as `pending`, and COMMIT
2. call the provider — outside any transaction
3. update the row with what was observed
```

Sending inside a transaction has two failure modes that are both worse: a
rollback after the provider accepted leaves a message the customer has received
and the center has no record of, and a slow provider holds a transaction open
for its timeout.

Inbound is the same rule from the other side: **the customer's message is
committed before anything else can fail**. Whatever happens next — the assistant
is off, the quota is spent, the provider is down, a tool refuses — the message is
in the center's database and visible to staff. A design that stored it only after
a successful reply would lose exactly the messages that most needed a human.

## 11. Idempotency

Meta delivers at least once and retries anything not answered 2xx.
`unique(whatsapp_account_id, fingerprint)` plus `insertOrIgnore` is the whole
mechanism — nothing anywhere asks "have I seen this".

One redelivered notification produces one inbound message, one conversation
effect and at most one AI run.

## 12. Human takeover

```
ai_active ──► human_requested ──► human_active ──► closed
    ▲                                  │
    └──────────────────────────────────┘
```

`human_requested` is a real state, not a flag on `human_active`: it is the one a
staff notification is about — somebody has asked for a person and nobody has
picked it up.

The assistant answers only in `ai_active`. A bot reply arriving while a human is
typing is the clearest possible signal that nobody is really there.

Replying implies taking over, through the same Action a deliberate takeover uses,
so the lock, the authorization and the audit entry are the same.

Triggers: the customer asks, the AI allowance is spent, the model keeps failing,
the request is unsupported, or a provider ambiguity.

Every move locks the row and validates the LOCKED state. Two staff members
pressing "take over" at the same instant: one wins, the other is told who has it.

### The turn is re-checked after the model answers, not only before

`takeOver()` is allowed from `ai_active` deliberately, so that somebody watching
the bot get it wrong can step in without waiting for the customer to ask. That is
also a race: routing checks the status, then calls the model provider, and a
whole turn takes seconds. By the time it returns, the `Conversation` the router
holds is a stale copy of a row a colleague may have claimed.

So `ConversationRouter` re-reads the status column the moment the assistant
returns, and abandons the turn if a person now holds the thread. Without it two
things go wrong, and the second is worse than the first:

1. the assistant's reply is sent underneath the colleague's;
2. a hand-off pushes `human_active` back to `human_requested`, clears
   `assigned_user_id`, and pages the desk about a thread somebody is already
   reading.

The customer's message is stored either way, and the run has already been metered
by the assistant — nothing is lost but a reply nobody should send.
`tests/Feature/Conversations/ConversationConcurrencyTest.php` commits the
takeover from a genuinely separate connection mid-turn, and both halves fail
without the re-read.

## 13. Notifications are downstream

Conversations emits `TakeoverRequested` and `ProviderSendFailed`; the
Notifications module listens (ADR-067). Conversations does not import
Notifications, and Notifications may import only
`Conversations\Domain\Events\*` — an architecture test enforces both.

The status change is committed before the event is dispatched, so a notification
failure can never leave a conversation nobody is answering and nobody has been
told about.

## 14. Templates

Meta requires a pre-approved template outside the customer service window their
own message opens.

**Meta Style does not model the window.** Its rules are Meta's, they have changed
more than once, and a local reimplementation would be a second opinion that goes
stale without anybody noticing. Phase 13 replies to customers who have just
messaged, which is inside the window by construction; Meta refuses anything it
should.

`config/whatsapp.php` maps a PURPOSE to the template name a center had approved,
and ships **empty**. A name configured for a template that does not exist, or
exists unapproved, fails at the provider — so plausible-looking defaults would be
shipping an outage.

A template is addressed by name AND Meta's language code, which is not an app
locale: `template_languages` in the same file maps one to the other (`en`, `ar`
by default; `ckb` unmapped until Meta support is verified). A language with no
mapping is never sent in (§22).

## 15. Rate limits are Meta Style's own

`config/limits.php`, per account, per sender, per conversation, and per
conversation for outbound. These are not Meta's limits: theirs are theirs, are
not published as a number this code could rely on, and are not ours to depend on.

A flood is DROPPED QUIETLY and recorded. Throwing would produce a non-2xx, and
Meta would retry the same flood forever — turning a rate limit into a permanent
load generator.

Security limits are not commercial quotas. See `docs/26-USAGE-QUOTAS.md`.

## 16. Provider status: implemented, not live-verified

`MetaWhatsAppCloudProvider` is built against Meta's published Cloud API and
Graph Webhooks documentation, read **2026-09-20**. The facts relied on:

| | |
|---|---|
| send | `POST https://graph.facebook.com/v25.0/{phone-number-id}/messages`, bearer token |
| text body | `{messaging_product, recipient_type, to, type:"text", text:{preview_url, body}}` |
| template body | `type:"template", template:{name, language:{code}, components}` |
| success | `messages[0].id` (a `wamid.…`), `message_status:"accepted"` |
| webhook GET | `hub.mode=subscribe`, `hub.verify_token`, `hub.challenge` → echo the challenge |
| webhook POST | `X-Hub-Signature-256: sha256=<hex hmac-sha256(raw body, app secret)>` |
| inbound | `entry[].changes[].value.{metadata.phone_number_id, contacts[], messages[]}` |
| statuses | `value.statuses[].{id, status, timestamp, recipient_id, conversation, pricing, errors}` |

`status` is one of `sent`, `delivered`, `read`, `failed`. `sent`, `delivered` and
`read` are all recorded as `sent`: the product question is "did it get there",
and a read receipt is the customer's business.

**No Meta credentials exist in this environment, so the adapter has not
completed a real round trip.** It is exercised by contract tests against these
recorded shapes. The Graph version is pinned rather than floating, so the day an
adapter breaks is decided by a deploy rather than by Meta's release calendar.

## 17. The staff surface

`{center}.…/manager/conversations` — a filtered list, a timeline, a reply box, take over,
hand back, close. `GET/POST /api/v1/tenant/conversations…` for the same.

Phase 15 Manager inbox: status filter with per-status counts
(`ConversationsQuery::counts`), two panes (list and thread; one at a time on a
phone), chat bubbles by direction and author, delivery shown exactly as recorded
(`unknown` never as "failed"), `wire:poll.10s.visible`, EN/AR/KU. Locked when
the center owns neither `whatsapp_booking` nor `rayan_ai` and has no threads;
with threads but no channel it stays readable and closable and the composer says
replies need WhatsApp. The adapters are still implemented, not live-verified
(§16) — the screen claims nothing beyond the recorded state.

The customer's phone number goes through `CustomerPresenter`. A conversation IS a
phone number, so rendering it raw would hand every holder of `conversation.view`
a contact list, bypassing `customer.contact.view` (ADR-042). An unresolved
thread's number is masked to the same shape.

The inbox is O(1) in threads. `ConversationsQuery::inbox()` eager loads
`customer.account` — not `customer` — because `Customer::isRegistered()` falls
back to `account()->exists()` whenever that relation is unloaded, which is one
`SELECT` per row from inside the presenter: invisible on a demo inbox, thirty
queries on a real one. `tests/Feature/Conversations/ConversationQueryCountTest.php`
asserts the cost of listing twelve threads equals the cost of listing three,
rather than asserting a magic number.

## 18. What is not retained

No raw provider body. A payload carries a display name, a profile, a `wa_id` and
whatever Meta adds next, none of it needed once the message has been read — the
same decision Payments made for gateway callbacks.

No provider secret in any presenter, response, log or audit row.

The WhatsApp profile name is deliberately ignored: it is whatever the sender
typed into their own phone, not a fact about a customer.

## 19. Entitlements and permissions

`whatsapp_booking` (requires `booking`) gates the channel; `rayan_ai` gates the
assistant. They are checked **separately** — a center can own the channel and not
the assistant, which is a real configuration: messages arrive and staff answer
them by hand, and it is the default.

Losing `whatsapp_booking` stops receiving and sending. It does **not** stop staff
reading threads they already have, or closing them: a center that can no longer
see what was said to their customers in their name is worse off than one that
never had the feature (the Booking downgrade rule).

Receiving is enforced in `ReceiveWhatsAppWebhook`, AFTER the signature
verifies: a customer message to a center without `whatsapp_booking`, or to an
account a manager switched off, is recorded `ignored` (`channel_inactive` /
`account_disabled`) and answered 200 — no thread, no customer lookup, no
assistant run, even when `rayan_ai` is still owned. Delivery statuses for
messages already sent keep being applied.

Permissions: `conversation.view`, `conversation.reply`, `conversation.takeover`,
`whatsapp.manage`. No Owner bypass.

## 20. Audit

Audited: the account configuration and credential replacement (at `Warning` when
secrets change, because it changes who can message that center's customers in
their name), takeover, return to the assistant, and close.

Never audited: a credential value or a digest of one, a raw Authorization
header, a message body, a customer's phone number, or a routine read.

Also audited: `whatsapp.account.enabled` / `whatsapp.account.disabled`
(`ManageWhatsAppAccount::setEnabled`), and `rayan.settings.updated` — RAYAN may
not reach the audit trail, so `ConfigureAssistant` dispatches
`AssistantSettingsChanged` and `Conversations\Application\Listeners\AuditAssistantSettings`
records it (on/off and model before/after, `tone_changed` — never the tone's
text). `whatsapp.account.configured` now carries `before` (enabled, phone
number id). Note: the audit `Redactor` masks any key containing
`credentials`, so `after.credentials_replaced` is stored as `[redacted]`;
`Warning` severity is what marks a replacement.

## 21. The Manager surface — Settings → WhatsApp

`center.integrations.whatsapp` (`/manager/settings/whatsapp`),
`App\Livewire\Center\Integrations\*`. Linked from the Settings navigation
(`SettingsLink`: "Not in plan" / "Needs attention") and the sidebar SETTINGS
group (`whatsapp_booking`; `settings.view` or `whatsapp.manage`).

| | |
|---|---|
| view | `settings.view` or `whatsapp.manage`: status, setup check, flow, languages — read-only |
| manage | `whatsapp.manage` (+ `whatsapp_booking`): connect, replace credentials, turn on/off, webhook URL + copy |
| assistant | `ai.manage` (+ `rayan_ai`): RAYAN on/off, approved model (select only when the platform approves more than one), tone ≤ 500 |
| locked | no `whatsapp_booking`: the feature offer; an account from before stays visible read-only; every Action refuses |

- **Credentials are write-only.** `ConnectionForm` never loads them and clears
  them in `dehydrate()`, so typed values never return in a snapshot or page,
  whatever the outcome. Each field shows "Configured / Not configured" only.
  All three or none (empty keeps what is stored), exactly like the Action.
- **"Check setup" is not a connection test.** `WhatsAppReadiness` reads what was
  recorded — entitlement, provider availability, phone number id shape,
  credentials present and decryptable, account on, handshake or a signed
  notification, signature failures newer than the last good notification, the
  last send's recorded state, `last_error_code` — and answers `ready`,
  `waiting` (webhook not verified yet), `problem` (first failing item) or
  `not_connected`. It calls no provider (§16).
- **RAYAN off is real.** `RayanAssistant::answer()` hands off with
  `assistant_disabled` when `RayanSettings::enabled()` is false — nothing is
  run or metered. `takeover_on_request` is NOT offered: no runtime path reads it
  and there is no customer-initiated hand-off tool.
- **The booking-flow list is derived**, not written: a RAYAN line appears only
  when its tool is registered in `ToolRegistry`; employee choice is not listed
  because `create_booking` takes none.
- Languages: the center's enabled content locales (`TenantLocales`, KU label);
  a thread replies in the customer's saved locale when enabled, else the
  primary one. No separate WhatsApp language storage.
- The adapter is still implemented, NOT live-verified; the screen never says
  otherwise.

## 22. Guest booking confirmation

A GUEST (a customer with no `customer_accounts` row, ADR-041) whose booking is
CONFIRMED is sent the center's approved confirmation template on WhatsApp.
A registered customer keeps the in-app `appointment_confirmed` notification
(docs/23) and gets no WhatsApp message, so nobody hears twice. A CRM customer
is not an account: nothing here creates a customer or an account (ADR-103).

| | |
|---|---|
| trigger | `Booking\Domain\Events\AppointmentConfirmed`, heard by `Conversations\Application\Listeners\ConfirmGuestBookingOnWhatsApp` — Booking knows nothing about WhatsApp, Notifications stays in-app |
| reads | the booking ONLY through `Booking\Contracts\BookingConfirmationFacts` → the readonly `Booking\Data\BookingConfirmationData` (docs/15 §1). Conversations imports no Booking or Employees model — `ConversationsBoundaryTest` fails if it does, and no Customer model outside the three Phase 13 sender-resolution files |
| when | `AfterCommit`: only after the transition commits. A rolled-back confirmation sends nothing; a provider failure can never undo the booking. Only `TransitionAppointment` confirms (no path creates a booking already `confirmed`) |
| once | `whatsapp_outbound_notices` `unique(purpose, source_type, source_uuid)` + `insertOrIgnore` — one decision per booking, whatever repeats |
| send | the `booking_confirmation` template (`config/whatsapp.php`, env `WHATSAPP_TEMPLATE_BOOKING_CONFIRMATION`, unset by default) through `OutboundMessages` — persist first, send second, flood guard, metering, `ProviderSendFailed` → the existing staff alert |
| thread | the open thread with that number on the center's account, else a new `ai_active` one carrying the booking's customer and branch, so a reply is routed like any message |
| language | Meta addresses a template by name AND a language code of its own, so the platform maps app locale → Meta code in `config/whatsapp.php` `template_languages` (`en` → `en`, `ar` → `ar` by default, env `WHATSAPP_TEMPLATE_LANGUAGE_EN/AR/CKB`; **`ckb` is NOT mapped by default** — Meta template support for Kurdish Sorani is unverified). The notice is written in the customer's `preferred_locale` when the center has it enabled AND it is mapped, else the center's primary language when mapped, else any enabled mapped language (`GuestConfirmationReadiness::templateLanguage()`); the parameters and the stored body are rendered in that language and the MAPPED code is sent. No mapped language → `skipped` / `template_language`, nothing sent |
| setting | Settings → WhatsApp → "Send booking confirmation to guest customers via WhatsApp"; tenant `settings` key `whatsapp_notifications`, absent = ON; `ConfigureWhatsAppNotifications` (`whatsapp_booking` + `whatsapp.manage`), audited `settings.whatsapp_notifications.updated` |

**The template contract.** Business-initiated messages are never free-form
(§14). The approved template must take eight body variables, in order:
customer name, center name, branch name, date, time (start–end, branch clock),
every booked service (snapshot names, variation, add-ons; the employee only
when the customer asked for that person), booking number (`B-000412`, the
public reference, ADR-068), branch contact number. A missing value is sent as
a dash — Meta refuses an empty parameter — and nothing is invented. Never a
uuid, an id, a price or the verification code. The message row stores the same
content rendered locally from `conversations.booking_confirmation.*`.

**Decisions are recorded.** `skipped` with a reason when nothing may be sent:
`channel_inactive`, `disabled`, `not_connected`, `account_off`,
`provider_unavailable`, `no_template`, `template_language` (none of the
center's enabled languages is mapped), then per customer `opted_out`
(`allow_operational_messages` is the stored consent), `no_phone`,
`no_reference`. A skip is final — turning the switch on, connecting WhatsApp or
regaining the entitlement never replays old bookings as news.

**Failure and retry.** A refusal is `failed` (the provider's safe code in
`reason`), the booking stays confirmed, and `ProviderSendFailed` pages the
people with `whatsapp.manage` (once per thread). `GuestConfirmationReconciler`
(tagged, `metastyle:reconcile` hourly) takes its candidates from Booking —
`BookingConfirmationFacts::confirmedGuestBookings()`: guest bookings confirmed
in the last 24 hours and still ahead, paged by id — and matches them against
its own notice rows. A candidate with no row is decided now (the callback died
after the commit); one whose notice is `failed` with attempts left is retried,
at most three attempts in all, claimed by a conditional UPDATE so two passes
cannot both send. `unknown` and a stale `pending` are never retried (§9). The send
runs in-process after the commit, like every after-commit reaction here; there
is no tenant-aware queue worker yet.

**The Manager card** (`Integrations\BookingConfirmations`) shows the switch
and the truth from `GuestConfirmationReadiness` — the same check the sender
makes: "Sending", or "Not sending" with the reason, the mapped template name
(or "Not set up"), the language rule with its real fallback ("Customer's
language, else EN") and each enabled language as EN / AR / KU — sendable, or
struck through as "Not available on WhatsApp" when unmapped ("None available"
when none is), the last 30 days (sent · failed · unconfirmed · not sent, by the
message's observed state: `COALESCE(messages.delivery_state, notice.status)`,
so a refusal Meta reported later by status callback counts as the refusal it
is) and the last issue. Viewers with `settings.view` read it; only `whatsapp.manage`
changes it, and the Action refuses otherwise.

Not built: a reschedule or cancellation notice on WhatsApp, a per-center
template name or language mapping (both are platform-wide while approval is
per WABA), a verified Kurdish template language, and a queued worker for the
send.
