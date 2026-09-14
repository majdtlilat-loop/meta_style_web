<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Privacy\Fingerprint;
use App\Modules\Customers\Domain\Enums\CustomerSource;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer creates their own login.
 *
 * THE GUEST → REGISTERED UPGRADE, which is the whole reason this action is
 * careful. If a customer already exists — reception created them, they booked
 * as a guest, they walked in — signing up must attach an account to THAT record,
 * not make a second one. Everything later hangs off the customer id: bookings,
 * sales, reviews, loyalty, packages. A duplicate here splits a person's history
 * permanently, and nobody notices until they ask why their visits are missing
 * (docs/13-ROADMAP.md Phase 5 §9).
 *
 * So the phone is normalised first, and the existing customer is found by its
 * canonical form. `0750…` and `+964750…` are the same person.
 *
 * EXISTING CRM DATA IS NOT OVERWRITTEN. A customer signing up may type their
 * name differently from how reception recorded it; the stored name stays. Only
 * genuinely absent fields are filled in. The alternative — trusting the newer
 * input — lets a self-registration quietly rewrite a record staff curated (§7).
 *
 * Gated on the `customer_accounts` entitlement, enforced HERE rather than only
 * in middleware, because this action will later be reachable from the WhatsApp
 * bot and from RAYAN, neither of which passes through HTTP middleware
 * (docs/05-ENTITLEMENTS.md §6).
 */
final class RegisterCustomerAccount
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{customer: Customer, account: CustomerAccount, linked_existing: bool}
     */
    public function __invoke(
        string $phone,
        string $password,
        ?string $name = null,
        ?string $locale = null,
    ): array {
        $this->entitlements->ensure('customer_accounts');

        $parsed = PhoneNumber::parse($phone);

        if ($parsed === null) {
            throw ValidationException::withMessages([
                'phone' => 'That does not look like a phone number.',
            ]);
        }

        $existing = Customer::query()->where('phone', $parsed->e164)->first();

        if ($existing !== null && $existing->account()->exists()) {
            // Deliberately the same message a wrong password gets on the login
            // endpoint would not be right here — the caller needs to know to
            // sign in instead. It confirms only what they already asserted by
            // typing their own number.
            throw ValidationException::withMessages([
                'phone' => 'An account already exists for that number. Try signing in.',
            ]);
        }

        // A NAME IS REQUIRED FOR A NEW CUSTOMER, and this is a security rule
        // rather than a data-quality one. The obvious fallback — using the
        // phone number as the name — puts the number into a field that is
        // never masked, so a member of staff without `customer.contact.view`
        // would read it straight off the list. It would also land in the audit
        // trail, which deliberately carries the name as a label and
        // deliberately carries no phone number at all (Phase 5 §§15, 22).
        if ($existing === null && ($name === null || trim($name) === '')) {
            throw ValidationException::withMessages([
                'name' => 'Please tell us your name.',
            ]);
        }

        if ($existing !== null && $existing->isArchived()) {
            // An archived customer is one the center retired. Letting them
            // self-resurrect would undo a staff decision silently.
            throw ValidationException::withMessages([
                'phone' => 'We could not create an account for that number. Please contact the center.',
            ]);
        }

        /** @var array{customer: Customer, account: CustomerAccount, linked_existing: bool} $result */
        $result = DB::connection('tenant')->transaction(
            function () use ($existing, $parsed, $password, $name, $locale): array {
                $linked = $existing !== null;

                $customer = $existing ?? new Customer([
                    'source' => CustomerSource::SelfRegistration,
                ]);

                if (! $customer->exists) {
                    $customer->forceFill([
                        'name' => trim((string) $name),
                        'phone' => $parsed->e164,
                        'phone_display' => $parsed->display,
                        'preferred_locale' => $locale,
                        'source' => CustomerSource::SelfRegistration,
                    ]);
                } else {
                    // Conservative: fill gaps, overwrite nothing. A name typed
                    // at signup does not replace the one staff recorded.
                    if ($customer->preferred_locale === null && $locale !== null) {
                        $customer->preferred_locale = $locale;
                    }
                }

                $customer->save();

                /** @var CustomerAccount $account */
                $account = $customer->account()->create([
                    'password' => $password,
                    'is_active' => true,
                    'password_changed_at' => now(),
                    // NOT verified. There is no verification provider, and
                    // claiming otherwise would be a lie the rest of the system
                    // trusts (ADR-040).
                    'phone_verified_at' => null,
                ]);

                return ['customer' => $customer, 'account' => $account, 'linked_existing' => $linked];
            }
        );

        $this->audit->record(new AuditEvent(
            action: $result['linked_existing']
                ? 'crm.customer_account.linked'
                : 'crm.customer_account.created',
            category: AuditCategory::Security,
            // The customer acted on their own behalf; there is no staff actor.
            actor: new Actor(ActorType::Customer, AuditSource::Web, $result['account']->uuid, $result['customer']->name),
            targetType: Customer::class,
            targetId: $result['customer']->uuid,
            targetLabel: $result['customer']->name,
            meta: [
                // No password, no token, no phone number. The fingerprint is
                // enough to correlate a signup with a later login attempt.
                'phone_fingerprint' => Fingerprint::of($result['customer']->phone),
                'linked_existing_customer' => $result['linked_existing'],
                'phone_verified' => false,
            ],
        ));

        return $result;
    }
}
