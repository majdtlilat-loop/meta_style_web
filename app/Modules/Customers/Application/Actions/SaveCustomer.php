<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Kernel\Privacy\Fingerprint;
use App\Modules\Customers\Domain\Data\CustomerInput;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerTag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a customer.
 *
 * DUPLICATE RESOLUTION IS EXPLICIT, NEVER SILENT. If the phone belongs to an
 * existing customer, creation does not quietly make a second record and does not
 * quietly overwrite the first: it refuses, naming the existing customer, so the
 * person at the desk decides. Auto-merging on a phone match would be wrong the
 * one time a family shares a number; creating a duplicate would be wrong every
 * other time (docs/13-ROADMAP.md Phase 5 §§3, 19).
 *
 * AUDIT CARRIES NO PII. A customer's phone and email never enter an audit row —
 * changes are recorded as a flag plus a keyed fingerprint, which is enough to
 * answer "is this the same number as last week" and reveals nothing on its own
 * (§22, docs/08-AUDIT-SECURITY.md §17).
 */
final class SaveCustomer
{
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(CustomerInput $input, User $actingUser, ?Customer $customer = null): Customer
    {
        $this->authorize($actingUser, $customer);
        $this->validate($input);

        $existing = $customer;
        $phone = PhoneNumber::parse($input->phone);

        $this->assertPhoneIsFree($phone?->e164, $existing);

        $before = $existing === null ? null : $this->snapshot($existing);

        /** @var Customer $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($input, $existing, $phone): Customer {
            $customer = $existing ?? new Customer;

            $wasOptedIn = $customer->exists && $customer->marketing_opt_in;

            $customer->forceFill([
                'name' => $input->name,
                'phone' => $phone?->e164,
                'phone_display' => $phone?->display,
                'email' => $input->email === null ? null : mb_strtolower($input->email),
                'preferred_locale' => $input->preferredLocale,
                'date_of_birth' => $input->dateOfBirth,
                'allow_operational_messages' => $input->allowOperationalMessages,
                'marketing_opt_in' => $input->marketingOptIn,
            ]);

            // Stamped only when consent is newly given, and never cleared on
            // withdrawal — "they opted in on this date and out later" is the
            // record a marketing regulator asks for.
            if ($input->marketingOptIn && ! $wasOptedIn) {
                $customer->marketing_opt_in_at = Carbon::now();
            }

            if (! $customer->exists) {
                $customer->source = $input->source;
            }

            $customer->save();

            if ($input->touchesTags()) {
                $customer->tags()->sync($this->tagIds($input->tagUuids ?? []));
            }

            return $customer;
        });

        $this->audit->record(new AuditEvent(
            action: $existing === null ? 'crm.customer.created' : 'crm.customer.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Customer::class,
            targetId: $saved->uuid,
            // The name is the label a support conversation needs; it is the one
            // piece of customer PII deliberately kept, because an audit trail of
            // anonymous uuids answers nothing.
            targetLabel: $saved->name,
            before: $before,
            after: $this->snapshot($saved),
        ));

        return $saved;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actingUser, ?Customer $customer): void
    {
        $needed = $customer === null ? Permission::CustomerCreate : Permission::CustomerUpdate;

        if (! $actingUser->hasPermission($needed)) {
            throw new AuthorizationException(
                $customer === null ? 'You may not add customers.' : 'You may not change customers.'
            );
        }
    }

    /**
     * @throws ValidationException
     */
    private function validate(CustomerInput $input): void
    {
        if ($input->name === '') {
            throw ValidationException::withMessages(['name' => 'A customer needs a name.']);
        }

        // A number that cannot be normalised cannot be an identity, and storing
        // it would create exactly the duplicate this design prevents.
        if ($input->phone !== null && PhoneNumber::parse($input->phone) === null) {
            throw ValidationException::withMessages([
                'phone' => 'That does not look like a phone number.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertPhoneIsFree(?string $e164, ?Customer $current): void
    {
        if ($e164 === null) {
            return;
        }

        $owner = Customer::query()->where('phone', $e164)->first();

        if ($owner === null || ($current !== null && $owner->is($current))) {
            return;
        }

        // Named, so the person at the desk can open the existing record rather
        // than guessing. Safe: they already hold customer.create or
        // customer.update, so they may see customers.
        throw ValidationException::withMessages([
            'phone' => "That number already belongs to {$owner->name}.",
        ]);
    }

    /**
     * @param  list<string>  $uuids
     * @return list<int>
     */
    private function tagIds(array $uuids): array
    {
        /** @var list<int> $ids */
        $ids = CustomerTag::query()->whereIn('uuid', $uuids)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();

        return $ids;
    }

    /**
     * The audit shape. Contact VALUES never appear — only whether they are set,
     * and a fingerprint that correlates without revealing.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'has_phone' => $customer->phone !== null,
            'phone_fingerprint' => Fingerprint::of($customer->phone),
            'has_email' => $customer->email !== null,
            'email_fingerprint' => Fingerprint::of($customer->email),
            'preferred_locale' => $customer->preferred_locale,
            'source' => $customer->source->value,
            'allow_operational_messages' => $customer->allow_operational_messages,
            'marketing_opt_in' => $customer->marketing_opt_in,
        ];
    }
}
