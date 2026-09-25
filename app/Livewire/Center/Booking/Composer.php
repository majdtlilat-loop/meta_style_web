<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking;

use App\Kernel\Authorization\Permission;
use App\Kernel\Http\Idempotency;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Booking\Concerns\RunsBookingActions;
use App\Livewire\Center\Booking\Forms\BookingForm;
use App\Livewire\Center\Booking\Support\BookingFormat;
use App\Livewire\Center\Booking\Support\CustomerLookup;
use App\Livewire\Center\Booking\Support\SlotList;
use App\Modules\Booking\Application\BookingOptions;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\VerificationCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The new-booking drawer: customer, services, a time the engine offers, book.
 *
 * An ADAPTER in the docs/15 §1 sense. It parses what reception typed
 * ({@see BookingForm}), presents the engine's availability and calls
 * `BookingEngine::book()` as `BookingActor::staff()` — the source is the
 * adapter's claim, never the form's (§§1, 10). It decides nothing.
 *
 * ## Double clicks
 *
 * The booking runs inside `Kernel\Http\Idempotency` under a per-form token,
 * the public booking page's pattern: a second click while the first is in
 * flight is refused, and a replay returns the first answer instead of booking
 * a second stylist (§13). The token rotates only once a booking lands.
 *
 * ## The verification code stays here
 *
 * The one-time code comes back from the engine and is shown in THIS drawer's
 * done state, and nowhere else — not in an event, not in the URL, not in the
 * stored idempotent response. The next thing the desk does clears it
 * (docs/24-BOOKING-VERIFICATION.md §11).
 */
final class Composer extends Component
{
    use RunsBookingActions;

    public BookingForm $form;

    public string $branchUuid = '';

    public string $date = '';

    /** `form` or `done`. */
    public string $step = 'form';

    /** @var list<array{starts_at: string, time: string, staff: string, current: bool}> */
    public array $slots = [];

    public bool $searched = false;

    #[Locked]
    public string $token = '';

    /** @var array{uuid: string, reference: string, when: string}|null */
    public ?array $booked = null;

    /** Shown once, in the done state; cleared by the next action (§11). */
    public string $issuedCode = '';

    /** @var list<array{uuid: string, name: string, timezone: string}>|null per request, never serialised */
    private ?array $branchList = null;

    /** The raw code on its way out of the engine; private, so never serialised. */
    private ?string $minted = null;

    public function mount(string $branch = '', string $date = ''): void
    {
        $viewer = $this->viewer();

        abort_unless($viewer->hasPermission(Permission::AppointmentCreate), 403);

        $uuids = array_column($this->branches(), 'uuid');
        $this->branchUuid = in_array($branch, $uuids, true) ? $branch : ($uuids[0] ?? '');
        $this->date = $date !== '' && $date >= $this->today() ? $date : $this->today();
        $this->token = (string) Str::uuid();
        $this->form->start($viewer->hasPermission(Permission::CustomerView));
    }

    // ------------------------------------------------------------ the form

    public function updated(string $property): void
    {
        if ($property === 'branchUuid') {
            $uuids = array_column($this->branches(), 'uuid');
            $this->branchUuid = in_array($this->branchUuid, $uuids, true) ? $this->branchUuid : ($uuids[0] ?? '');
            // Another branch has another menu and another team.
            $this->form->lines = [BookingForm::blankLine()];
        }

        if (preg_match('/^form\.lines\.(\d+)\.service$/', $property, $match) === 1) {
            $this->form->serviceChanged((int) $match[1]);
        }

        if ($property === 'date' && ($this->date < $this->today() || CarbonImmutable::hasFormat($this->date, 'Y-m-d') === false)) {
            $this->date = $this->today();
        }

        if ($property === 'branchUuid' || $property === 'date' || str_starts_with($property, 'form.lines')) {
            $this->clearSlots();
        }

        if ($property === 'date' && $this->searched) {
            $this->findSlots(app(BookingEngine::class), app(SlotList::class));
        }
    }

    public function addLine(): void
    {
        $this->form->addLine();
        $this->clearSlots();
    }

    public function removeLine(int $index): void
    {
        $this->form->removeLine($index);
        $this->clearSlots();
    }

    public function useCustomerMode(string $mode): void
    {
        $maySearch = $this->viewer()->hasPermission(Permission::CustomerView);
        $this->form->customerMode = $mode === 'existing' && $maySearch ? 'existing' : 'new';
        $this->resetErrorBag();
    }

    /** Only a customer the viewer's own search returned may be chosen. */
    public function chooseCustomer(string $uuid, CustomerLookup $lookup): void
    {
        foreach ($lookup->search($this->form->customerSearch, $this->viewer()) as $candidate) {
            if ($candidate['uuid'] === $uuid) {
                $this->form->customerUuid = $uuid;
                $this->form->customerLabel = $candidate['name'];
                $this->form->customerSearch = '';
                $this->resetErrorBag('form.customerUuid');

                return;
            }
        }
    }

    public function clearCustomer(): void
    {
        $this->form->clearCustomer();
    }

    public function shiftDate(int $days, BookingEngine $engine, SlotList $list): void
    {
        $date = CarbonImmutable::parse($this->date)->addDays($days)->format('Y-m-d');
        $this->date = $date < $this->today() ? $this->today() : $date;

        $this->findSlots($engine, $list);
    }

    /**
     * The engine's times for these services on this day, with the person it
     * would book beside each one.
     */
    public function findSlots(BookingEngine $engine, SlotList $list): void
    {
        $this->clearSlots();

        $this->form->validate($this->form->lineRules());

        $found = $this->attempt(fn (): array => $engine->availability(
            AvailabilityQuery::forDay($this->branchUuid, $this->form->bookingLines(), $this->date),
        ));

        $this->searched = true;

        if (is_array($found)) {
            $this->slots = $list->present($found);
        }
    }

    public function pickSlot(string $startsAt): void
    {
        if (in_array($startsAt, array_column($this->slots, 'starts_at'), true)) {
            $this->form->slot = $startsAt;
            $this->resetErrorBag('form.slot');
        }
    }

    // ---------------------------------------------------------------- book

    public function book(BookingEngine $engine, Idempotency $idempotency): void
    {
        // A click queued behind the one that booked arrives in the done state;
        // it must not book again under the fresh token.
        if ($this->step !== 'form') {
            return;
        }

        $this->form->validate();

        if (! in_array($this->form->slot, array_column($this->slots, 'starts_at'), true)) {
            $this->addError('form.slot', __('manager_booking.composer.pick_time'));

            return;
        }

        $request = new BookingRequest(
            branchUuid: $this->branchUuid,
            lines: $this->form->bookingLines(),
            startsAt: CarbonImmutable::parse($this->form->slot)->utc(),
            customer: $this->form->customerRef(),
            customerNote: $this->form->customerNote(),
        );

        $actor = BookingActor::staff($this->viewer());
        // The one-time code leaves the operation through this holder, never
        // through the stored (replayable) response.
        $this->minted = null;

        $response = $this->attempt(fn (): JsonResponse => $idempotency->run(
            $this->token,
            'manager.calendar.book',
            $this->form->payload($this->branchUuid),
            function () use ($engine, $request, $actor): JsonResponse {
                $result = $engine->book($request, $actor);
                $this->minted = $result->verificationCode;

                // The stored, replayable answer carries no code: staff can
                // always issue a new one, and a secret has no business in a
                // 24-hour replay table (docs/24 §11).
                return new JsonResponse([
                    'uuid' => $result->appointment->uuid,
                    'reference' => (string) $result->appointment->reference,
                    'date' => $result->appointment->localDate(),
                    'time' => $result->appointment->localStart()->format('H:i'),
                ]);
            },
        ));

        if (! $response instanceof JsonResponse) {
            // Refused — typically a slot taken a second ago. What is left
            // stays on screen to choose from (§7).
            $this->form->slot = '';

            return;
        }

        if ($response->getStatusCode() >= 400) {
            $this->error = __('manager_booking.errors.in_flight');

            return;
        }

        /** @var array{uuid: string, reference: string, date: string, time: string} $body */
        $body = json_decode((string) $response->getContent(), true);

        $this->booked = [
            'uuid' => $body['uuid'],
            'reference' => $body['reference'],
            'when' => BookingFormat::dateLong($body['date']).' · '.$body['time'],
        ];
        $this->issuedCode = $this->minted !== null ? VerificationCode::format($this->minted) : '';
        $this->minted = null;
        $this->step = 'done';
        $this->token = (string) Str::uuid();

        $this->dispatch('booking-created', uuid: $body['uuid']);
    }

    public function bookAnother(): void
    {
        $this->issuedCode = '';
        $this->booked = null;
        $this->step = 'form';
        $this->searched = false;
        $this->clearSlots();
        $this->form->restart($this->viewer()->hasPermission(Permission::CustomerView));
        $this->resetErrorBag();
    }

    public function openBooking(): void
    {
        $uuid = $this->booked['uuid'] ?? null;
        $this->issuedCode = '';

        if (is_string($uuid)) {
            $this->dispatch('open-appointment', uuid: $uuid);
        }
    }

    public function close(): void
    {
        $this->issuedCode = '';
        $this->dispatch('booking-composer-closed');
    }

    // --------------------------------------------------------------- render

    public function render(BookingOptions $options, CustomerLookup $lookup): View
    {
        $viewer = $this->viewer();
        $services = $options->services($this->branchUuid, $viewer);
        $byUuid = array_column($services, null, 'uuid');

        $lines = [];

        foreach (array_keys($this->form->lines) as $index) {
            $service = $byUuid[$this->form->serviceAt($index)] ?? null;

            $lines[] = [
                'index' => $index,
                'service' => $service,
                'employees' => $service !== null ? $options->employeesFor($service['uuid'], $this->branchUuid, $viewer) : [],
                'resources' => $service !== null ? $options->resourcesFor($service['uuid'], $this->branchUuid, $viewer) : [],
            ];
        }

        $ready = $this->form->ready();
        $estimate = $ready ? $options->estimate($this->form->bookingLines(), $this->branchUuid, $viewer) : null;
        $existing = $this->form->customerMode === 'existing';

        return view('livewire.center.booking.composer', [
            'branches' => $this->branches(),
            'services' => $services,
            'lineOptions' => $lines,
            'estimate' => $estimate,
            'estimateDuration' => $estimate !== null ? BookingFormat::duration($estimate['duration_minutes']) : null,
            'customers' => $existing ? $lookup->search($this->form->customerSearch, $viewer) : [],
            'lookedUp' => $existing && mb_strlen(trim($this->form->customerSearch)) >= 2,
            'phoneMatch' => $existing ? null : $lookup->phoneOwner($this->form->newCountry, $this->form->newPhone, $viewer),
            'canSearchCustomers' => $viewer->hasPermission(Permission::CustomerView),
            'canCreateCustomers' => $viewer->hasPermission(Permission::CustomerCreate),
            'periods' => BookingFormat::periods($this->slots),
            'dateLabel' => BookingFormat::dateLong($this->date),
            'minDate' => $this->today(),
            'isFirstDay' => $this->date <= $this->today(),
            'maxLines' => BookingForm::MAX_LINES,
            'ready' => $ready,
        ]);
    }

    // ------------------------------------------------------------ internals

    private function clearSlots(): void
    {
        $this->slots = [];
        $this->form->slot = '';
    }

    /**
     * @return list<array{uuid: string, name: string, timezone: string}>
     */
    private function branches(): array
    {
        return $this->branchList ??= app(BookingOptions::class)->branches($this->viewer());
    }

    /** Today where the chosen branch is — never the server's day (§5). */
    private function today(): string
    {
        $timezone = 'UTC';

        foreach ($this->branches() as $branch) {
            if ($branch['uuid'] === $this->branchUuid || $this->branchUuid === '') {
                $timezone = $branch['timezone'];

                break;
            }
        }

        return BranchClock::localDate(CarbonImmutable::now()->utc(), $timezone);
    }
}
