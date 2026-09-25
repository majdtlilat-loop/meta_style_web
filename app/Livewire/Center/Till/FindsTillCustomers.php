<?php

declare(strict_types=1);

namespace App\Livewire\Center\Till;

use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Application\Actions\SaveCustomer;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Customers\Domain\Data\CustomerInput;
use App\Modules\Customers\Domain\Enums\CustomerSource;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\SalesQuery;
use Illuminate\Validation\ValidationException;

/**
 * Who the sale is for: find a customer by name — or by phone for staff allowed
 * to see numbers — or add a walk-in on the spot, then attach them.
 *
 * The search is the CRM's own (`CustomerQuery`), so a person who only sees
 * masked numbers cannot use the till to test full numbers either. Contact
 * details reach this screen masked by `CustomerPresenter`, never here.
 */
trait FindsTillCustomers
{
    public string $customerSearch = '';

    public bool $addingCustomer = false;

    public string $newCustomerName = '';

    public string $newCustomerPhone = '';

    public string $newCustomerCountry = 'IQ';

    public function attachCustomer(string $customerUuid, AdjustSale $adjust, SalesQuery $query): void
    {
        $this->attempt(function () use ($customerUuid, $adjust, $query): void {
            $adjust->customer($query->find($this->sale, $this->user()), $this->user(), $customerUuid === '' ? null : $customerUuid);

            $this->reset(['customerSearch']);
        });
    }

    public function startNewCustomer(): void
    {
        $this->addingCustomer = true;
        $this->newCustomerName = trim($this->customerSearch);
        $this->resetValidation();
    }

    public function cancelNewCustomer(): void
    {
        $this->reset(['addingCustomer', 'newCustomerName', 'newCustomerPhone']);
        $this->resetValidation();
    }

    /**
     * Adds the walk-in to the CRM — through the CRM's own Action, so a number
     * that already belongs to someone is refused rather than duplicated — and
     * puts them on this sale.
     */
    public function createCustomer(SaveCustomer $save, AdjustSale $adjust, SalesQuery $query): void
    {
        $phone = trim($this->newCustomerPhone);
        $parsed = $phone === '' ? null : PhoneNumber::fromParts($this->newCustomerCountry, $phone);

        if ($phone !== '' && $parsed === null) {
            $this->addError('newCustomerPhone', (string) __('manager_pos.customer.phone_invalid'));

            return;
        }

        $this->attempt(function () use ($save, $adjust, $query, $parsed): void {
            $customer = $save(new CustomerInput(
                name: trim($this->newCustomerName),
                phone: $parsed?->e164,
                source: CustomerSource::Staff,
            ), $this->user());

            $adjust->customer($query->find($this->sale, $this->user()), $this->user(), $customer->uuid);

            $this->reset(['addingCustomer', 'newCustomerName', 'newCustomerPhone', 'customerSearch']);
            $this->saved = (string) __('manager_pos.customer.added', ['name' => $customer->name]);
        });
    }

    /**
     * @return list<array{uuid: string, name: string, contact: string|null}>
     */
    protected function customerMatches(CustomerQuery $customers, CustomerPresenter $presenter, User $user): array
    {
        $term = trim($this->customerSearch);

        if (mb_strlen($term) < 2 || ! $user->hasPermission(Permission::CustomerView)) {
            return [];
        }

        try {
            $page = $customers->paginate(['search' => $term], $user, 8);
        } catch (ValidationException) {
            return [];
        }

        $matches = [];

        foreach ($page->items() as $customer) {
            /** @var Customer $customer */
            $summary = $presenter->summary($customer, $user);
            $phone = is_string($summary['phone'] ?? null) ? $summary['phone'] : null;

            $matches[] = [
                'uuid' => (string) $summary['uuid'],
                'name' => (string) $summary['name'],
                // Already masked for a viewer without the contact permission;
                // a full number is shown the way people read it.
                'contact' => $phone !== null && ! $summary['contact_masked'] ? (PhoneNumber::parse($phone)?->international() ?? $phone) : $phone,
            ];
        }

        return $matches;
    }
}
