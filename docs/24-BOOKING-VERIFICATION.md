# 24 — The booking reference and the verification code

Phase 13. Two identifiers on every appointment, doing two completely different
jobs. Keeping them apart is the whole design.

| | `reference` | `verification_code` |
|---|---|---|
| Shape | `B-000412` | 10 Crockford Base32 characters |
| Visibility | public, printed, quotable | secret, said once |
| Enumerable | **yes, by design** | no — ~50 bits of CSPRNG |
| Stored | in the clear | `HMAC-SHA256` under a versioned pepper |
| Authenticates | **nothing** | that booking, and only that booking |
| Backfilled onto old rows | yes | **never** |

---

## 1. The reference

`App\Modules\Booking\Domain\BookingReference` — `B-` plus the zero-padded
primary key.

It exists for one situation: a person reading it to another person. A uuid is
unusable there, which is why `appointments.uuid` stays the machine identifier
and this is the human one.

Deriving it from the primary key is safe precisely because it grants nothing.
It leaks the center's booking volume, which is also true of the invoice numbers
already printed on every receipt (`docs/18-SALES.md`), and the alternative — a
random reference — costs a uniqueness check, a collision retry and the ability
to backfill, to hide a number a competitor could estimate by walking past.

`normalise()` accepts what a person actually types: `b 412`, `412`, `#B-000412`.
Nobody dictating a reference says "capital B, hyphen, zero zero zero".

## 2. The code

`App\Modules\Booking\Domain\VerificationCode`. Ten characters, ~50 bits.

Short because it is READ ALOUD. A customer says it to a receptionist or types it
into a phone keyboard, so the 64 hex characters of an invoice share token are
not an option, and the whole design problem is the gap between "short enough to
dictate" and "not guessable".

Crockford Base32 drops `I`, `L`, `O` and `U`; `normalise()` maps the confusable
ones back, so a customer who reads `0` as `O` is not wrong about their booking.
Separators are dropped: `X4K7-9TB2M0` is how a person writes down ten
characters.

**Fifty bits is not cryptographic strength, and nothing here claims it is.**
Guessing one is ~10^15 attempts against a reference that must ALSO be right,
through a path that is rate limited per reference and per sender and that
answers identically for a wrong code and an unknown booking. The entropy is the
last line, not the only one.

## 3. Generated in the authoritative creation flow

`CreateAppointment` mints the code explicitly, BEFORE opening its transaction,
and writes the digest in the same `INSERT` as the appointment. The raw value
leaves through `BookingResult::$verificationCode` — the return of the call that
created it.

Deliberately **not** an Eloquent `created` hook. A one-time secret has to be
handed back through a return value, and a model event has nowhere to return
anything to; a transient property on the model would make the delivery of a
secret depend on nobody calling `->fresh()`.

So:

- a committed booking never exists without its digest — same statement;
- a rolled-back booking leaves no usable code — nothing was written or returned;
- drawing from the CSPRNG and reading the pepper happen first, so a deployment
  missing either refuses the booking instead of holding the branch lock while it
  finds out.

`BookingEngine::book()` therefore returns a `BookingResult` rather than an
`Appointment`. A channel may ignore the code; WhatsApp does (§12). What a
channel may not do is assume it can fetch it afterwards.

## 4. HMAC, not a plain hash (ADR-069)

A plain SHA-256 of a ten-character typeable code is brute-forceable offline from
a database copy — 10^15 hashes is a weekend on rented hardware. So the digest is

```
HMAC-SHA256( normalise(code), pepper[version] )
```

and the pepper is **not in any database**. A stolen copy is useless on its own.

The digest is taken over the NORMALISED form, so it is computed over exactly
what verification will derive from what a customer types.

## 5. Key versions

`security.keys.booking_verification` holds an `active` version and every version
still needed to verify existing digests. Each row records the version that wrote
it.

**Verification uses the version on the row, and no other.** Trying every
configured key until one matched would look like helpfulness and would be the
bug: a key kept only for old codes would silently start accepting new ones, a
rotation would become unobservable, and removing a retired key would change
behaviour nobody could predict.

A row naming a version this deployment does not have raises `MissingKeyVersion`
rather than answering false. "This code is wrong" and "this deployment cannot
check codes" are different facts; collapsing them turns a missing environment
variable into a silent refusal of every booking code with nothing saying why.

## 6. Rotation

1. add `v2` to `versions`, leaving `active` on `v1`
2. move `active` to `v2` — new and regenerated codes use it
3. keep `v1` while codes written under it may still be used
4. remove `v1` only when those codes are intentionally being retired

**There is no rehash.** The raw code does not exist anywhere to rehash from —
that is the point of §4, and the cost of it.

## 7. The production doctor

`metastyle:doctor --production` FAILS when the active version is missing, has no
key material, or when ANY configured version is shorter than
`security.minimum_key_bytes` (32). Every branch fails closed.

Checking every version, not only the active one, is deliberate: a retired key
that has been blanked rather than removed stops verifying the codes still
relying on it, and those rows then fail closed — the exact outage the check
exists to catch before it happens.

Outside production it is a WARNING. A developer machine has no pepper and does
not need one, and failing there makes `metastyle:doctor` something everybody
learns to ignore.

## 8. Who may issue a code

`IssueVerificationCode`, one Action, three entry points:

| Path | Authority |
|---|---|
| `forAccount()` | a signed-in CustomerAccount, for their own booking |
| `forStaff()` | `appointment.update` + branch scope, audited |
| `forVerifiedSender()` | a signature-verified WhatsApp sender who resolves to the owner |

**Deliberately not a way in:** a reference alone (it is public and enumerable —
if it could mint a code it would BE the code); a reference plus a typed phone
number (a phone number is printed on business cards); an unknown WhatsApp
sender; an identity the model supplied; and **any public "forgot my booking
code" endpoint**. There is none, anywhere.

A guest whose number does not match and who has no account is helped by STAFF. A
human confirming who they are talking to is the fallback, by design.

Issuing RETIRES the previous code, under the appointment's row lock. That is the
point: a customer asks for a new one precisely when they think somebody else has
seen the old one.

A terminal booking gets no code. The capability exists to let somebody ACT on a
booking, and none of those actions apply to one that is over.

## 9. Looking a booking up

`BookingLookup::byCode()` takes a reference and a code and returns the
appointment or **null**.

No such reference, a booking in another tenant, no code, a wrong code, a retired
key — all null. One answer, so the pair cannot be taken apart: if "no such
booking" and "wrong code" were distinguishable, the deliberately enumerable
reference space would become a map of the center's entire book.

It grants **that booking only**. Not the customer's other bookings, their
history, their invoices, loyalty, packages, memberships or account. A code proves
somebody holds one booking, not that they are the person who made it.

Attempts are counted against the REFERENCE being attacked and against whoever is
attacking it, and both are recorded on failure regardless of which part was
wrong (`config/limits.php`). A malformed guess counts against neither — otherwise
anybody could lock a customer out of their own booking by spraying nonsense at
its reference.

## 10. Legacy appointments

The migration adds nullable columns and backfills the REFERENCE for every
historical row in one `UPDATE`. It mints **no codes**.

Minting one for a booking nobody asked about would create a live secret that was
never delivered to anybody — a credential with no owner sitting in a database. A
legacy row keeps `NULL`, verification is simply unavailable, and staff issue one
on request.

A NULL digest never matches anything, including an empty string.

## 11. Where the raw code may appear

Exactly one place per issue: the return of the call that minted it, and the
response or render that call feeds.

Never in a column, an audit row, a log line, a notification payload, a
conversation message, a provider request, or a diagnostic. The audit entry for an
issue records the reference and the KEY VERSION — which is what makes a rotation
auditable — and never the code or the digest.

`BookingVerificationTest` proves this by dumping every value of every column of
every table in the center's database and asserting the code appears in none of
them.

The one deliberate exception: the public booking form's idempotency record
retains it for its 24-hour window, because a guest who double-taps must get the
same booking AND the same code back, and has no account to regenerate from.

## 12. WhatsApp: a digest exists ≠ the customer has the code

Every booking made through WhatsApp or RAYAN gets a code at the Booking-domain
level, like every other channel. **The WhatsApp channel deliberately does not
send or echo it.**

So nobody may later read "this booking has a digest" as "this customer was given
a code". They were not. A verified WhatsApp sender does not need one — their
phone number is the evidence — and if they later reach the center another way
they regenerate through §8.

There is deliberately no code-redaction pass over messages, because the code is
never put into one.
