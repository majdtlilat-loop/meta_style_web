<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking\Forms;

use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Contact\PhoneRule;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\CustomerRef;
use Livewire\Form;

/**
 * What reception typed into the new-booking drawer, and how it becomes the
 * engine's input — parsing only.
 *
 * Nothing here checks that a service is offered, that a person may perform
 * it or that a time is free: the engine does all of that when it is asked
 * (docs/15-BOOKING.md §1). These rules only make sure the request is well
 * formed and that a new customer has a name and a phone number that parses
 * as one — the two things the engine would otherwise refuse in English.
 */
final class BookingForm extends Form
{
    public const MAX_LINES = 6;

    /** `existing` or `new`. */
    public string $customerMode = 'existing';

    public string $customerSearch = '';

    public string $customerUuid = '';

    public string $customerLabel = '';

    public string $newName = '';

    public string $newPhone = '';

    public string $newCountry = 'IQ';

    /**
     * One row per service. Posted back by the browser, so read only through
     * the accessors below, which take strings and nothing else.
     *
     * @var list<array<string, mixed>>
     */
    public array $lines = [];

    public string $note = '';

    /** The chosen start, an ISO instant echoed from an offered slot. */
    public string $slot = '';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->lineRules() + [
            'note' => ['nullable', 'string', 'max:500'],
            'slot' => ['required', 'string', 'max:40'],
        ];

        if ($this->customerMode === 'existing') {
            return $rules + ['customerUuid' => ['required', 'string', 'max:64']];
        }

        return $rules + [
            'newName' => ['required', 'string', 'max:190'],
            'newCountry' => ['required', 'string', 'size:2'],
            'newPhone' => ['required', 'string', 'max:32', new PhoneRule($this->newCountry)],
        ];
    }

    /**
     * Enough to ask for availability.
     *
     * @return array<string, mixed>
     */
    public function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.service' => ['required', 'string', 'max:64'],
            'lines.*.variation' => ['nullable', 'string', 'max:64'],
            'lines.*.employee' => ['nullable', 'string', 'max:64'],
            'lines.*.addons' => ['array', 'max:10'],
            'lines.*.addons.*' => ['string', 'max:64'],
            'lines.*.resources' => ['array', 'max:10'],
            'lines.*.resources.*' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'lines.*.service' => __('manager_booking.composer.service'),
            'customerUuid' => __('manager_booking.composer.customer'),
            'newName' => __('manager_booking.composer.name'),
            'newPhone' => __('manager_booking.composer.phone'),
            'newCountry' => __('manager_booking.composer.phone'),
            'note' => __('manager_booking.composer.note'),
            'slot' => __('manager_booking.composer.time'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slot.required' => __('manager_booking.composer.pick_time'),
            'customerUuid.required' => __('manager_booking.composer.pick_customer'),
        ];
    }

    public function start(bool $maySearch): void
    {
        $this->lines = [self::blankLine()];
        $this->customerMode = $maySearch ? 'existing' : 'new';
    }

    public function addLine(): void
    {
        if (count($this->lines) < self::MAX_LINES) {
            $this->lines[] = self::blankLine();
        }
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) > 1 && isset($this->lines[$index])) {
            unset($this->lines[$index]);
            $this->lines = array_values($this->lines);
        }
    }

    /** A new service on a line: its option, extras, person and rooms go too. */
    public function serviceChanged(int $index): void
    {
        if (isset($this->lines[$index])) {
            $this->lines[$index] = ['service' => $this->serviceAt($index)] + self::blankLine();
        }
    }

    public function serviceAt(int $index): string
    {
        return self::text($this->lines[$index]['service'] ?? null);
    }

    public function ready(): bool
    {
        if ($this->lines === []) {
            return false;
        }

        foreach (array_keys($this->lines) as $index) {
            if ($this->serviceAt($index) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<BookingLine>
     */
    public function bookingLines(): array
    {
        return array_map(static fn (array $line): BookingLine => new BookingLine(
            serviceUuid: self::text($line['service'] ?? null),
            variationUuid: self::text($line['variation'] ?? null) === '' ? null : self::text($line['variation']),
            addonUuids: self::texts($line['addons'] ?? []),
            employeeUuid: self::text($line['employee'] ?? null) === '' ? null : self::text($line['employee']),
            resourceUuids: self::texts($line['resources'] ?? []),
        ), $this->lines);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return list<string>
     */
    private static function texts(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    public function customerRef(): CustomerRef
    {
        if ($this->customerMode === 'existing') {
            return CustomerRef::existing($this->customerUuid);
        }

        // E.164 from the phone field's two parts. The engine parses it again
        // and reuses whoever already has that number (§10). No preferred
        // language: the desk's interface language is the RECEPTIONIST's, not
        // the customer's, and the staff API records none either.
        $phone = PhoneNumber::fromParts($this->newCountry, $this->newPhone);

        return CustomerRef::details(trim($this->newName), $phone !== null ? $phone->e164 : $this->newPhone);
    }

    public function customerNote(): ?string
    {
        $note = trim($this->note);

        return $note === '' ? null : $note;
    }

    /**
     * The request as the idempotency hash sees it: a different booking under
     * the same token is a conflict, never a replay (§13).
     *
     * @return array<string, mixed>
     */
    public function payload(string $branchUuid): array
    {
        return [
            'branch' => $branchUuid,
            'lines' => $this->lines,
            'starts_at' => $this->slot,
            'customer' => $this->customerMode === 'existing'
                ? ['uuid' => $this->customerUuid]
                : ['name' => trim($this->newName), 'phone' => PhoneNumber::fromParts($this->newCountry, $this->newPhone)?->e164],
            'note' => trim($this->note),
        ];
    }

    public function clearCustomer(): void
    {
        $this->customerUuid = '';
        $this->customerLabel = '';
    }

    public function restart(bool $maySearch): void
    {
        $this->clearCustomer();
        $this->customerSearch = '';
        $this->newName = '';
        $this->newPhone = '';
        $this->note = '';
        $this->slot = '';
        $this->start($maySearch);
    }

    /**
     * @return array{service: string, variation: string, addons: list<string>, employee: string, resources: array<int, string>}
     */
    public static function blankLine(): array
    {
        return ['service' => '', 'variation' => '', 'addons' => [], 'employee' => '', 'resources' => []];
    }
}
