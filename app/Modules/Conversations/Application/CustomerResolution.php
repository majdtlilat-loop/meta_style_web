<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Contact\PhoneNumber;
use App\Modules\Customers\Domain\Models\Customer;

/**
 * Turns a signature-verified WhatsApp sender number into a customer of THIS
 * center — or into nobody, which is an ordinary answer.
 *
 * ## What the verified number proves, exactly (ADR-070)
 *
 * That whoever sent this message controls that phone number, for this
 * interaction. Meta signed the notification with the center's own app secret,
 * and the number came out of that signed body.
 *
 * It does NOT prove:
 *
 *   - that the person is the customer whose record holds that number. A phone
 *     changes hands; a family shares one. It is the same evidence a receptionist
 *     has when somebody phones the shop, and it is treated the same way — good
 *     enough to discuss that person's bookings, not to change their password.
 *   - anything at all about any FUTURE message. Trust is per-message and
 *     re-established from the signature every time. This is why nothing here
 *     writes a `whatsapp_verified` flag and why `phone_verified_at` is left
 *     alone: a stored flag would outlive the evidence that justified it
 *     (ADR-040, docs/25-WHATSAPP.md §6).
 *
 * ## Inside this tenant, only
 *
 * The lookup runs on the tenant connection, so it cannot see another center by
 * construction. The same number in two centers is two unrelated people, and
 * there is deliberately no global directory to consult — building one would
 * make Meta Style a cross-center identity broker, which is precisely what the
 * database-per-tenant design exists to prevent (§7).
 *
 * ## It never creates anybody
 *
 * A wrong number, a supplier, somebody's cousin asking a question — none of
 * them is a customer, and filling a center's CRM with every number that ever
 * messaged them would make the CRM useless. A customer record is created later,
 * by the Booking Engine's own resolver, at the moment somebody actually books
 * (§7). No CustomerAccount is ever created here either: an account is a login,
 * and a WhatsApp message is not a request for one.
 */
final class CustomerResolution
{
    /**
     * The customer this verified number belongs to, or null.
     *
     * @param  string  $verifiedPhone  E.164, from the verified provider envelope.
     *                                 NEVER from message text, an AI tool
     *                                 argument or a request field (§6).
     */
    public function resolve(string $verifiedPhone): ?Customer
    {
        $phone = PhoneNumber::parse($verifiedPhone);

        if ($phone === null) {
            return null;
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()
            // Normalised on both sides: the stored column is E.164 and so is
            // this, which is the whole point of ADR-039. Comparing raw
            // formatting would make `0750…` and `+964750…` different people.
            ->where('phone', $phone->e164)
            ->first();

        if (! $customer instanceof Customer || $customer->isArchived()) {
            /*
             * An archived customer is a decision the center made about that
             * person. Resolving to them would quietly bring them back into
             * service through a side door — so the conversation stays
             * unidentified, and a member of staff decides.
             */
            return null;
        }

        return $customer;
    }
}
