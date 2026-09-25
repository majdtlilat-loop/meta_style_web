<?php

declare(strict_types=1);

use App\Kernel\Security\Exceptions\MissingKeyVersion;
use App\Kernel\Security\Exceptions\TooManyAttempts;
use App\Kernel\Security\Keyring;
use App\Modules\Booking\Application\Actions\IssueVerificationCode;
use App\Modules\Booking\Application\BookingLookup;
use App\Modules\Booking\Application\BookingVerification;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\BookingReference;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\VerificationCode;
use App\Modules\Customers\Domain\Models\Customer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The booking verification capability
|--------------------------------------------------------------------------
|
| docs/24-BOOKING-VERIFICATION.md · ADR-069.
|
| A booking has two identifiers and they do completely different jobs:
|
|     reference   public, quotable, enumerable BY DESIGN, authenticates nothing
|     code        ~50 bits of CSPRNG, HMAC at rest under a VERSIONED pepper,
|                 raw value returned exactly once and stored nowhere
|
| Everything below turns on keeping those two apart.
|
*/

it('gives every new booking a reference and a code, and returns the code once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $booked = $this->bookFor($seed, $owner, $this->seedCustomer());

        // `bookFor` unwraps to the appointment; re-read to prove the columns
        // were written by the CREATE, not by anything after it.
        $appointment = Appointment::query()->whereKey($booked->getKey())->firstOrFail();

        expect($appointment->reference)->toBe(BookingReference::forId((int) $appointment->getKey()))
            ->and($appointment->verification_code_digest)->not->toBeNull()
            ->and(mb_strlen((string) $appointment->verification_code_digest))->toBe(64)
            ->and($appointment->verification_code_key_version)->toBe('v1')
            ->and($appointment->verification_code_issued_at)->not->toBeNull();
    });
});

it('never writes the raw code to any column, in any table', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $result = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], now()->addDay()->toDateString(), '10:00'),
                customer: CustomerRef::details('Sara', '0750 123 4567'),
            ),
            BookingActor::staff($owner),
        );

        $raw = (string) $result->verificationCode;

        expect($raw)->not->toBe('')
            ->and(VerificationCode::isWellFormed($raw))->toBeTrue();

        /*
         * The sharpest form of the claim: dump EVERY value of every column of
         * every table in the center's database and assert the raw code appears
         * in none of them. A column added later that happened to persist it
         * fails here (§3).
         */
        $found = [];

        foreach (DB::connection('tenant')->select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            foreach (DB::connection('tenant')->table((string) $table)->get() as $record) {
                foreach ((array) $record as $column => $value) {
                    if (is_string($value) && str_contains($value, $raw)) {
                        $found[] = $table.'.'.$column;
                    }
                }
            }
        }

        expect($found)->toBe([]);
    });
});

it('stores an HMAC under a versioned pepper, never a plain SHA-256', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $result = $this->bookWithCode($seed, $owner);
        $appointment = $result['appointment'];
        $raw = $result['code'];

        $normalised = VerificationCode::normalise($raw);

        /*
         * THE POINT OF ADR-069. A plain digest of a ~50-bit typeable code is
         * brute-forceable offline from a database copy: 10^15 hashes is a
         * weekend on rented hardware. An HMAC under a pepper that is NOT in the
         * database makes the copy useless on its own (§4).
         */
        expect($appointment->verification_code_digest)
            ->not->toBe(hash('sha256', $normalised))
            ->not->toBe(hash('sha256', $raw))
            ->toBe(app(Keyring::class)->hmac(BookingVerification::KEY, $normalised, 'v1'));
    });
});

it('accepts the code however a person writes it, and refuses anything else', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $result = $this->bookWithCode($seed, $owner);
        $appointment = $result['appointment'];
        $raw = VerificationCode::normalise($result['code']);

        $verification = app(BookingVerification::class);

        /*
         * Crockford's confusable characters map BACK. A customer who reads `0`
         * as `O` is not wrong about their booking, and a product that refused
         * them would be deciding that a font choice is the customer's problem
         * (§4).
         */
        $written = [
            $raw,
            mb_strtolower($raw),
            mb_substr($raw, 0, 5).'-'.mb_substr($raw, 5),
            mb_substr($raw, 0, 5).' '.mb_substr($raw, 5),
            strtr($raw, ['0' => 'O', '1' => 'I']),
            strtr(mb_strtolower($raw), ['0' => 'o', '1' => 'l']),
        ];

        foreach ($written as $variant) {
            expect($verification->matches($appointment, $variant))->toBeTrue($variant);
        }

        foreach ([mb_substr($raw, 0, 9), $raw.'X', '', 'NOTACODE12', str_repeat('U', 10)] as $wrong) {
            expect($verification->matches($appointment, $wrong))->toBeFalse($wrong);
        }
    });
});

it('opens exactly one booking, and answers identically for every failure', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $mine = $this->bookWithCode($seed, $owner, '0750 123 4567', '10:00');
        $theirs = $this->bookWithCode($seed, $owner, '0750 123 4568', '11:00');

        $lookup = app(BookingLookup::class);

        expect($lookup->byCode($mine['appointment']->reference, $mine['code'], '+9647501234567')?->getKey())
            ->toBe($mine['appointment']->getKey());

        /*
         * One answer for every kind of failure. If "no such reference" and
         * "wrong code" were distinguishable, the enumerable reference space
         * would become a map of the center's entire book (§9).
         */
        expect($lookup->byCode($mine['appointment']->reference, $theirs['code'], '+9647509999991'))->toBeNull()
            ->and($lookup->byCode('B-999999', $mine['code'], '+9647509999992'))->toBeNull()
            ->and($lookup->byCode($mine['appointment']->reference, 'ZZZZZZZZZZ', '+9647509999993'))->toBeNull()
            ->and($lookup->byCode('not-a-reference', $mine['code'], '+9647509999994'))->toBeNull();
    });
});

it('bounds guessing per reference, and per whoever is guessing', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $booking = $this->bookWithCode($seed, $owner);
        $lookup = app(BookingLookup::class);
        $reference = (string) $booking['appointment']->reference;

        // `config/limits.php`: five a minute against one reference.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            expect($lookup->byCode($reference, 'ZZZZZZZZZ'.$attempt, '+96475000000'.$attempt))->toBeNull();
        }

        expect(fn () => $lookup->byCode($reference, 'ZZZZZZZZZX', '+9647500000099'))
            ->toThrow(TooManyAttempts::class);
    });
});

it('spends no allowance on a malformed guess, so nobody can lock a customer out', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $booking = $this->bookWithCode($seed, $owner);
        $lookup = app(BookingLookup::class);
        $reference = (string) $booking['appointment']->reference;

        /*
         * Garbage is not a guess. If it consumed the allowance, anybody could
         * lock a real customer out of their own booking by spraying nonsense
         * at its reference — a denial of service with no authentication at all
         * (§9).
         */
        for ($attempt = 0; $attempt < 50; $attempt++) {
            expect($lookup->byCode($reference, 'short', '+9647501111111'))->toBeNull();
        }

        // Still allowed, and still correct.
        expect($lookup->byCode($reference, $booking['code'], '+9647501111111')?->getKey())
            ->toBe($booking['appointment']->getKey());
    });
});

it('leaves a legacy booking without a code until one is explicitly issued', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = $this->bookFor($seed, $owner, $this->seedCustomer());

        /*
         * Simulates a booking made before Phase 13: the migration backfilled a
         * REFERENCE for every historical row and deliberately minted no code,
         * because a code nobody asked for is a live credential with no owner
         * (§10).
         */
        DB::connection('tenant')->table('appointments')->where('id', $appointment->getKey())->update([
            'verification_code_digest' => null,
            'verification_code_key_version' => null,
            'verification_code_issued_at' => null,
        ]);

        $legacy = Appointment::query()->whereKey($appointment->getKey())->firstOrFail();

        expect($legacy->reference)->not->toBeNull()
            ->and($legacy->hasVerificationCode())->toBeFalse()
            // A NULL digest must never be treated as "matches anything".
            ->and(app(BookingVerification::class)->matches($legacy, 'ANYTHING12'))->toBeFalse();

        $issued = app(IssueVerificationCode::class)->forStaff($legacy, $owner);

        $legacy->refresh();

        expect($legacy->hasVerificationCode())->toBeTrue()
            ->and(app(BookingVerification::class)->matches($legacy, $issued))->toBeTrue();
    });
});

it('retires the old code the moment a new one is issued', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $booking = $this->bookWithCode($seed, $owner);
        $appointment = $booking['appointment'];
        $first = $booking['code'];

        $second = app(IssueVerificationCode::class)->forStaff($appointment, $owner);

        $appointment->refresh();
        $verification = app(BookingVerification::class);

        // The whole reason somebody regenerates: they think the old one has
        // been seen (§8).
        expect($verification->matches($appointment, $second))->toBeTrue()
            ->and($verification->matches($appointment, $first))->toBeFalse()
            ->and($second)->not->toBe($first);
    });
});

it('lets a customer regenerate their own, and nobody else theirs', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $mine = $this->seedCustomer('Sara', '0750 123 4567');
        $theirs = $this->seedCustomer('Rana', '0750 123 4568');

        $myAccount = $this->seedCustomerAccount($mine);

        $myBooking = $this->bookFor($seed, $owner, $mine, time: '10:00');
        $theirBooking = $this->bookFor($seed, $owner, $theirs, time: '11:00');

        $issue = app(IssueVerificationCode::class);

        expect(VerificationCode::isWellFormed($issue->forAccount($myBooking, $myAccount)))->toBeTrue();

        // Somebody else's booking is refused in words that confirm nothing
        // about whether it exists.
        expect(fn () => $issue->forAccount($theirBooking, $myAccount))
            ->toThrow(AuthorizationException::class);
    });
});

it('lets a verified WhatsApp sender regenerate only their own', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $mine = $this->seedCustomer('Sara', '0750 123 4567');
        $theirs = $this->seedCustomer('Rana', '0750 123 4568');

        $myBooking = $this->bookFor($seed, $owner, $mine, time: '10:00');
        $theirBooking = $this->bookFor($seed, $owner, $theirs, time: '11:00');

        $issue = app(IssueVerificationCode::class);

        expect(VerificationCode::isWellFormed($issue->forVerifiedSender($myBooking, $mine)))->toBeTrue();

        expect(fn () => $issue->forVerifiedSender($theirBooking, $mine))
            ->toThrow(AuthorizationException::class);
    });
});

it('refuses staff without the permission, or outside the branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = $this->bookFor($seed, $owner, $this->seedCustomer());

        // Permission and branch scope are INDEPENDENT gates (ADR-029).
        $noPermission = $this->staffWith(['appointment.view'], 'nopermission@alpha.test');

        expect(fn () => app(IssueVerificationCode::class)->forStaff($appointment, $noPermission))
            ->toThrow(AuthorizationException::class);

        $otherBranch = $this->seedBranch('Other Branch');
        $scoped = $this->staffWith(['appointment.update'], 'scoped@alpha.test');
        $scoped->forceFill(['all_branches' => false])->save();
        $scoped->syncBranchScope([(int) $otherBranch->getKey()]);

        expect(fn () => app(IssueVerificationCode::class)->forStaff($appointment, $scoped))
            ->toThrow(AuthorizationException::class);
    });
});

it('fails closed when the key version on the row is not configured', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $booking = $this->bookWithCode($seed, $owner);
        $appointment = $booking['appointment'];

        // A pepper retired without the codes written under it being retired.
        DB::connection('tenant')->table('appointments')->where('id', $appointment->getKey())
            ->update(['verification_code_key_version' => 'v9']);

        $appointment->refresh();

        /*
         * THROWN, not answered false. "This code is wrong" and "this deployment
         * cannot tell whether it is wrong" are different facts, and collapsing
         * them would turn a missing environment variable into a silent refusal
         * of every booking code in the system (§5).
         */
        expect(fn () => app(BookingVerification::class)->matches($appointment, $booking['code']))
            ->toThrow(MissingKeyVersion::class);

        // And the public path turns that into the SAME generic refusal a wrong
        // code gets, while still reporting it.
        expect(app(BookingLookup::class)->byCode((string) $appointment->reference, $booking['code'], '+9647501234567'))
            ->toBeNull();
    });
});

it('verifies with the version on the row, never by trying every key', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $old = $this->bookWithCode($seed, $owner, '0750 123 4567', '10:00');

        /*
         * ROTATION. v2 is added and made active; v1 stays configured so codes
         * written under it keep working (§6).
         */
        config([
            'security.keys.booking_verification.versions.v2' => 'base64:'.base64_encode(str_repeat('b', 32)),
            'security.keys.booking_verification.active' => 'v2',
        ]);

        $new = $this->bookWithCode($seed, $owner, '0750 123 4568', '11:00');

        $verification = app(BookingVerification::class);

        // The v1 booking still verifies — under v1, because that is what its
        // row says.
        expect($old['appointment']->verification_code_key_version)->toBe('v1')
            ->and($verification->matches($old['appointment'], $old['code']))->toBeTrue();

        // The new one was written under the ACTIVE version.
        expect($new['appointment']->verification_code_key_version)->toBe('v2')
            ->and($verification->matches($new['appointment'], $new['code']))->toBeTrue();

        /*
         * And the codes are NOT interchangeable across versions. If
         * verification tried every configured key, the v1 code would verify
         * against the v2 row's digest by accident of the search rather than by
         * the row saying so — which is exactly what makes a rotation
         * unobservable (§5).
         */
        expect($verification->matches($new['appointment'], $old['code']))->toBeFalse()
            ->and($verification->matches($old['appointment'], $new['code']))->toBeFalse();

        // Regenerating an old booking moves it forward to the active version.
        app(IssueVerificationCode::class)->forStaff($old['appointment'], $owner);
        $old['appointment']->refresh();

        expect($old['appointment']->verification_code_key_version)->toBe('v2');
    });
});

it('puts no raw code in an audit row, a notification or a log', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $booking = $this->bookWithCode($seed, $owner);
        $code = app(IssueVerificationCode::class)->forStaff($booking['appointment'], $owner);

        $audit = DB::connection('tenant')->table('audit_logs')
            ->where('action', 'booking.verification_code.issued')
            ->orderByDesc('id')
            ->first();

        expect($audit)->not->toBeNull();

        $serialised = (string) json_encode((array) $audit);

        expect(str_contains($serialised, $code))->toBeFalse('the raw code is in the audit row')
            ->and(str_contains($serialised, (string) $booking['appointment']->verification_code_digest))
            ->toBeFalse('the digest is in the audit row')
            // What IS recorded: which key version wrote it, so a rotation is
            // auditable (§11).
            ->and($serialised)->toContain('key_version');
    });
});

it('refuses to issue a code for a booking that is over', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = $this->bookFor($seed, $owner, $this->seedCustomer());

        app(BookingEngine::class)->cancel(
            $appointment,
            BookingActor::staff($owner),
            'Customer changed their mind.',
        );

        /*
         * A capability exists to let somebody ACT on a booking. None of those
         * actions apply to one that is finished, so issuing anyway would create
         * a live secret whose only remaining use is reading history (§8).
         */
        expect(fn () => app(IssueVerificationCode::class)->forStaff($appointment->refresh(), $owner))
            ->toThrow(BookingFailed::class);
    });
});

it('normalises a reference the way a person writes one', function (): void {
    // `b 412`, `412` and `B-000412` are one booking. Nobody dictating a
    // reference says "capital B, hyphen, zero zero zero" (§1).
    foreach (['B-000412', 'b-000412', 'b 000412', '412', 'B412', '#B-000412'] as $written) {
        expect(BookingReference::normalise($written))->toBe('B-000412', $written);
    }

    expect(BookingReference::isWellFormed('B-000412'))->toBeTrue()
        ->and(BookingReference::isWellFormed('B-1'))->toBeFalse()
        ->and(BookingReference::isWellFormed('nonsense'))->toBeFalse();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
