<?php

declare(strict_types=1);

namespace App\Livewire\Center\Customers\Concerns;

use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneCountries;
use App\Kernel\Contact\PhoneRule;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Modules\Customers\Application\Actions\SaveCustomer;
use App\Modules\Customers\Domain\Data\CustomerInput;
use App\Modules\Customers\Domain\Exceptions\DuplicateCustomerPhone;
use App\Modules\Customers\Domain\Models\Customer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The customer create/edit form, shared by the CRM list and the profile page.
 *
 * The phone is the shared picker — a country (Iraq by default) and a national
 * number — and becomes ONE E.164 identity in `SaveCustomer`
 * (`PhoneNumber::fromParts`). A number that already belongs to somebody is
 * refused by the Action, which names the owner; the form then offers to open
 * that customer instead of creating a second one (ADR-041).
 *
 * Contact fields are prefilled, and sent, only for staff holding
 * `customer.contact.view`: an edit form that handed back the masked value
 * would be the way round the masking, and a blank field submitted by someone
 * who never saw it must not erase the number.
 */
trait EditsCustomer
{
    /** The record being edited, by uuid. Null while adding. */
    public ?string $editing = null;

    public bool $showForm = false;

    public string $name = '';

    public string $phone = '';

    public string $phoneCountry = PhoneCountries::DEFAULT;

    public string $email = '';

    public string $preferredLocale = '';

    public string $dateOfBirth = '';

    public bool $allowOperational = true;

    public bool $marketingOptIn = false;

    /** @var list<string> */
    public array $tagUuids = [];

    /**
     * The customer a typed phone already belongs to: offered to open instead.
     *
     * @var array{uuid: string, name: string}|null
     */
    public ?array $duplicate = null;

    public function create(): void
    {
        $this->resetForm();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(string $uuid): void
    {
        $customer = $this->customerForForm($uuid);

        $this->resetValidation();
        $this->resetForm();
        $this->editing = $uuid;
        $this->name = $customer->name;

        // Only prefilled for someone allowed to see it. Otherwise the edit form
        // would hand back the very value the list masked.
        if ($this->formActor()->hasPermission(Permission::CustomerContactView)) {
            $phone = $customer->phoneNumber();
            $this->phoneCountry = $phone?->country() ?? PhoneCountries::DEFAULT;
            $this->phone = $phone === null ? '' : $phone->national();
            $this->email = (string) $customer->email;
        }

        $this->preferredLocale = (string) $customer->preferred_locale;
        $this->dateOfBirth = $customer->date_of_birth?->toDateString() ?? '';
        $this->allowOperational = $customer->allow_operational_messages;
        $this->marketingOptIn = $customer->marketing_opt_in;
        $this->tagUuids = $customer->tags->pluck('uuid')->values()->all();

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->resetValidation();
        $this->showForm = false;
    }

    public function save(SaveCustomer $save): void
    {
        $actor = $this->formActor();
        $mayEditContact = $actor->hasPermission(Permission::CustomerContactView);

        $rules = [
            'name' => ['required', 'string', 'max:190'],
            'preferredLocale' => ['nullable', 'string', Rule::in(app(LanguageRegistry::class)->supported())],
            'dateOfBirth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];

        if ($mayEditContact) {
            $rules['email'] = ['nullable', 'email:rfc', 'max:190'];
            $rules['phoneCountry'] = ['required', 'string', 'size:2'];
            $rules['phone'] = ['nullable', 'string', 'max:32', ...(trim($this->phone) !== '' ? [new PhoneRule($this->phoneCountry)] : [])];
        }

        $this->validate($rules, [], [
            'name' => __('manager_customers.fields.name'),
            'email' => __('manager_customers.fields.email'),
            'phone' => __('manager_customers.fields.phone'),
            'phoneCountry' => __('phone_field.country'),
            'preferredLocale' => __('manager_customers.fields.language'),
            'dateOfBirth' => __('manager_customers.fields.date_of_birth'),
        ]);

        $this->duplicate = null;
        $existing = $this->editing === null ? null : $this->customerForForm($this->editing);

        try {
            $customer = $save(CustomerInput::fromArray([
                'name' => $this->name,
                // A viewer who cannot see contact details cannot change them
                // either: submitting a blank form would otherwise erase a phone
                // number they were never shown.
                'phone' => $mayEditContact ? $this->phone : ($existing === null ? null : ($existing->phone_display ?? $existing->phone)),
                'phone_country' => $mayEditContact ? $this->phoneCountry : null,
                'email' => $mayEditContact ? $this->email : $existing?->email,
                'preferred_locale' => $this->preferredLocale,
                'date_of_birth' => $this->dateOfBirth === '' ? null : $this->dateOfBirth,
                'allow_operational_messages' => $this->allowOperational,
                'marketing_opt_in' => $this->marketingOptIn,
                'tags' => $this->tagUuids,
            ]), $actor, $existing);
        } catch (DuplicateCustomerPhone $duplicate) {
            $this->duplicate = ['uuid' => $duplicate->customerUuid, 'name' => $duplicate->customerName];
            $this->addError('phone', (string) ($duplicate->errors()['phone'][0] ?? $duplicate->getMessage()));

            return;
        } catch (ValidationException $invalid) {
            foreach ($invalid->errors() as $key => $messages) {
                $this->addError(self::FORM_FIELDS[$key] ?? 'name', (string) ($messages[0] ?? ''));
            }

            return;
        } catch (AuthorizationException $refused) {
            $this->addError('name', $refused->getMessage());

            return;
        }

        $created = $existing === null;

        $this->resetForm();
        $this->showForm = false;
        $this->customerSaved($customer, $created);
    }

    /** Action error keys → the form property they belong under. */
    private const FORM_FIELDS = [
        'name' => 'name',
        'phone' => 'phone',
        'email' => 'email',
        'preferred_locale' => 'preferredLocale',
        'date_of_birth' => 'dateOfBirth',
    ];

    /** What the page does once a customer is saved. */
    abstract protected function customerSaved(Customer $customer, bool $created): void;

    abstract protected function formActor(): User;

    private function customerForForm(string $uuid): Customer
    {
        /** @var Customer $customer */
        $customer = Customer::query()->with('tags')->where('uuid', $uuid)->firstOrFail();

        return $customer;
    }

    private function resetForm(): void
    {
        $this->editing = null;
        $this->name = '';
        $this->phone = '';
        $this->phoneCountry = PhoneCountries::DEFAULT;
        $this->email = '';
        $this->preferredLocale = '';
        $this->dateOfBirth = '';
        $this->allowOperational = true;
        $this->marketingOptIn = false;
        $this->tagUuids = [];
        $this->duplicate = null;
    }
}
