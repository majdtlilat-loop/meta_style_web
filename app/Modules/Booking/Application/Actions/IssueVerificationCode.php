<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Security\Exceptions\MissingKeyVersion;
use App\Modules\Booking\Application\BookingVerification;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Issues a booking a NEW verification code, replacing any it had.
 *
 * The single authoritative path, for all three ways somebody may legitimately
 * ask (docs/24-BOOKING-VERIFICATION.md §8):
 *
 *   A  {@see forAccount()}        a signed-in customer, for their own booking
 *   B  {@see forStaff()}          a member of staff with the permission and the
 *                                 branch, audited
 *   C  {@see forVerifiedSender()} a signature-verified WhatsApp sender who
 *                                 resolves to the customer who owns it
 *
 * ## What is deliberately NOT a way in
 *
 * - a booking reference on its own. It is public and enumerable; if it could
 *   mint a code it would BE the code.
 * - a reference plus a typed phone number. A phone number is not a secret — it
 *   is printed on business cards — and "enter the number you booked with" is a
 *   guessing game with a very small search space.
 * - a WhatsApp sender who does not resolve to the owner.
 * - an identity the AI supplied. RAYAN passes no customer identity anywhere;
 *   it comes from the verified envelope (§§8, 12).
 * - a public "forgot my booking code" endpoint. There is none, and adding one
 *   would defeat every line above. A guest with no account whose phone does not
 *   match is helped by STAFF, deliberately: a human confirming who they are
 *   talking to is the fallback, not a form.
 *
 * ## Regeneration invalidates
 *
 * The old digest is overwritten, so the previous code stops working the instant
 * this returns — the same semantics as rotating an invoice share link. That is
 * the point: a customer asks for a new code precisely when they think somebody
 * else may have seen the old one.
 */
final class IssueVerificationCode
{
    public function __construct(
        private readonly BookingVerification $verification,
        private readonly Audit $audit,
    ) {}

    /**
     * Path B — a member of staff, at the counter or on the phone.
     *
     * `appointment.update` rather than a new permission: re-issuing a code is
     * changing the booking's access, which is squarely what that grant already
     * covers, and a `booking.code.issue` code nothing else ever checks would be
     * a micro-permission (Phase 13 §61).
     *
     * @throws AuthorizationException
     * @throws BookingFailed
     * @throws MissingKeyVersion
     */
    public function forStaff(Appointment $appointment, User $user): string
    {
        if (! $user->hasPermission(Permission::AppointmentUpdate)) {
            throw new AuthorizationException('You may not change appointments.');
        }

        // Permission and branch scope, independently (ADR-029).
        if (! $user->canAccessBranch((int) $appointment->branch_id)) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        return $this->issue($appointment, new Actor(
            ActorType::Staff,
            AuditSource::Web,
            $user->uuid,
            $user->name,
        ));
    }

    /**
     * Path A — a signed-in customer, for a booking that is theirs.
     *
     * @throws AuthorizationException
     * @throws BookingFailed
     * @throws MissingKeyVersion
     */
    public function forAccount(Appointment $appointment, CustomerAccount $account): string
    {
        if (! $account->canAuthenticate()) {
            throw new AuthorizationException('This account cannot be used.');
        }

        $this->assertOwnedBy($appointment, (int) $account->customer_id);

        return $this->issue($appointment, new Actor(
            ActorType::Customer,
            AuditSource::Api,
            $account->uuid,
            'customer',
        ));
    }

    /**
     * Path C — a WhatsApp sender whose number Meta's signature vouched for, and
     * who resolved to the customer this booking belongs to.
     *
     * The CALLER has already done the two things that make this safe: verified
     * the webhook signature, and resolved the sender's E.164 number to a
     * customer inside this tenant. This Action re-checks the only part it can —
     * that the resolved customer owns the booking — because ownership is the
     * claim being made here (§8).
     *
     * @throws AuthorizationException
     * @throws BookingFailed
     * @throws MissingKeyVersion
     */
    public function forVerifiedSender(Appointment $appointment, Customer $customer): string
    {
        $this->assertOwnedBy($appointment, (int) $customer->getKey());

        return $this->issue($appointment, new Actor(
            ActorType::Customer,
            AuditSource::WhatsApp,
            $customer->uuid,
            'whatsapp',
        ));
    }

    /**
     * Path B for a transport that holds only a uuid — the staff API.
     *
     * The lookup lives here so the HTTP adapter never handles the Appointment
     * model (BookingBoundaryTest). Null when there is no such booking; the
     * permission and branch checks are {@see forStaff()}'s, unchanged.
     *
     * @return array{reference: string|null, code: string}|null
     *
     * @throws AuthorizationException
     * @throws BookingFailed
     * @throws MissingKeyVersion
     */
    public function forStaffByUuid(string $uuid, User $user): ?array
    {
        $appointment = Appointment::query()->where('uuid', $uuid)->first();

        if (! $appointment instanceof Appointment) {
            return null;
        }

        return ['reference' => $appointment->reference, 'code' => $this->forStaff($appointment, $user)];
    }

    /**
     * Path A for a transport that holds only a uuid — the customer API.
     *
     * Scoped to the account's own customer IN THE QUERY, so somebody else's
     * booking is NOT FOUND (null) rather than forbidden, and no branch of this
     * method can hand back a wrong appointment.
     *
     * @return array{reference: string|null, code: string}|null
     *
     * @throws AuthorizationException
     * @throws BookingFailed
     * @throws MissingKeyVersion
     */
    public function forAccountByUuid(string $uuid, CustomerAccount $account): ?array
    {
        $appointment = Appointment::query()
            ->where('uuid', $uuid)
            ->where('customer_id', $account->customer_id)
            ->first();

        if (! $appointment instanceof Appointment) {
            return null;
        }

        return ['reference' => $appointment->reference, 'code' => $this->forAccount($appointment, $account)];
    }

    /**
     * @throws AuthorizationException
     */
    private function assertOwnedBy(Appointment $appointment, int $customerId): void
    {
        if ((int) $appointment->customer_id !== $customerId) {
            // The same refusal whether the booking belongs to somebody else or
            // the caller simply got the reference wrong. Distinguishing them
            // would turn this into a way to ask "does this reference exist".
            throw new AuthorizationException('That booking is not available.');
        }
    }

    /**
     * Mints, writes and returns — once.
     *
     * @throws BookingFailed
     * @throws MissingKeyVersion
     */
    private function issue(Appointment $appointment, Actor $actor): string
    {
        if ($appointment->isTerminal()) {
            /*
             * A cancelled or completed booking gets no new code. The capability
             * exists to let somebody ACT on a booking — reschedule it, cancel
             * it, read its details before arriving — and none of those apply to
             * one that is over. Issuing anyway would create a live secret whose
             * only remaining use is reading history (§8).
             */
            throw BookingFailed::policy('That booking is closed.');
        }

        $code = $this->verification->mint();

        DB::connection('tenant')->transaction(function () use ($appointment, $code): void {
            /** @var Appointment|null $locked */
            $locked = Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Appointment) {
                throw BookingFailed::policy('That booking is not available.');
            }

            /*
             * Under the lock, so two people pressing "regenerate" at the same
             * moment cannot end with the digest of one code and the other
             * person holding the other — exactly one of them wins, and the
             * loser's code was never valid (§8).
             */
            $locked->forceFill([
                ...$code->columns(),
                'verification_code_issued_at' => CarbonImmutable::now()->utc(),
            ])->saveQuietly();

            $appointment->setRawAttributes($locked->getAttributes(), true);
        });

        $this->record($appointment, $actor, $code->keyVersion);

        return $code->raw;
    }

    /**
     * The audit entry.
     *
     * NO RAW CODE, and no digest either. What is recorded is that a code was
     * issued, to which booking, by whom, and under which KEY VERSION — the last
     * being the one fact that makes a rotation auditable. The secret itself
     * never enters the audit log, a notification payload, a log line or a
     * conversation message (§11).
     *
     * `Security`, not `Booking`: this changes who can reach a booking, and the
     * question it answers later is a security one.
     */
    private function record(Appointment $appointment, Actor $actor, string $keyVersion): void
    {
        $this->audit->record(new AuditEvent(
            action: 'booking.verification_code.issued',
            category: AuditCategory::Security,
            actor: $actor,
            severity: AuditSeverity::Notice,
            targetType: Appointment::class,
            targetId: $appointment->uuid,
            targetLabel: $appointment->reference,
            meta: [
                'reference' => $appointment->reference,
                'key_version' => $keyVersion,
            ],
        ));
    }
}
