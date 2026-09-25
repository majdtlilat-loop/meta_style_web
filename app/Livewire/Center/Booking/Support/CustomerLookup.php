<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking\Support;

use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Customers\Domain\Models\Customer;

/**
 * Finding the customer a booking is for, the CRM's way.
 *
 * Search goes through `CustomerQuery` and results through `CustomerPresenter`,
 * so the desk obeys exactly the CRM's rules: phone matching only for somebody
 * who may see phones, masked numbers for somebody who may not, and a masked
 * field is never a filter (ADR-042). Nothing here widens what the CRM screen
 * itself would show the same person.
 */
final class CustomerLookup
{
    public function __construct(
        private readonly CustomerQuery $customers,
        private readonly CustomerPresenter $presenter,
    ) {}

    /**
     * @return list<array{uuid: string, name: string, contact_phone: string|null, tags: list<string>}>
     */
    public function search(string $search, User $viewer, int $limit = 6): array
    {
        $search = trim($search);

        if (mb_strlen($search) < 2 || ! $viewer->hasPermission(Permission::CustomerView)) {
            return [];
        }

        $results = [];

        foreach ($this->customers->paginate(['search' => $search], $viewer, $limit)->items() as $customer) {
            /** @var Customer $customer */
            $summary = $this->presenter->summary($customer, $viewer);

            /** @var list<array{name: string}> $tags */
            $tags = $summary['tags'];

            $results[] = [
                'uuid' => (string) $summary['uuid'],
                'name' => (string) $summary['name'],
                'contact_phone' => BookingFormat::phone(is_string($summary['phone'] ?? null) ? $summary['phone'] : null, ($summary['contact_masked'] ?? false) === true),
                'tags' => array_map(static fn (array $tag): string => (string) $tag['name'], $tags),
            ];
        }

        return $results;
    }

    /**
     * "This number already belongs to Sara" — said only to somebody who could
     * find Sara by that number in the CRM anyway. For anyone else the engine
     * still reuses her record, silently, and nothing is revealed (§10).
     */
    public function phoneOwner(string $country, string $number, User $viewer): ?string
    {
        if (! $viewer->hasPermission(Permission::CustomerView) || ! $viewer->hasPermission(Permission::CustomerContactView)) {
            return null;
        }

        $phone = PhoneNumber::fromParts($country, $number);

        if ($phone === null) {
            return null;
        }

        $name = Customer::query()->where('phone', $phone->e164)->whereNull('archived_at')->value('name');

        return is_string($name) ? $name : null;
    }
}
