<?php

declare(strict_types=1);

namespace App\Livewire\Center\Queue;

use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Contact\PhoneRule;
use App\Kernel\Entitlements\Entitlements;
use App\Livewire\Center\Queue\Concerns\RunsDeskActions;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\ServiceJourney\Application\Actions\CreateWalkInVisit;
use App\Modules\ServiceJourney\Application\VisitOptions;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Somebody walked in: phone or name → services → optional stylist → visit
 * (and, with the queue, a number). docs/17-QUEUE.md §22.
 *
 * ONE form for both desks. With `queue_management` and `queue.manage` the
 * reception flow is `CreateWalkInTicket` — one transaction, visit and number
 * together. Without the queue it is `CreateWalkInVisit`, gated on `booking`
 * only, so a center with no queue still takes walk-ins (§19).
 *
 * ## One press, one visit
 *
 * A token per opening: the database refuses a second visit on the same token,
 * so a double-click, a retried request or an impatient second press all return
 * the first visit — and the same number (§§11, 22).
 *
 * ## Identity
 *
 * An existing customer is chosen from a search of names the viewer may read
 * (masked contact, never a filter on it — ADR-042). A new one gives a name and,
 * optionally, a phone typed into the international field and normalised to
 * E.164 by `PhoneNumber::fromParts()`; the Booking Engine's resolver links it
 * to the customer who already owns that number rather than making a second.
 */
final class WalkInForm extends Component
{
    use RunsDeskActions;

    public bool $open = false;

    /** queue | visit — which desk opened the form. */
    public string $mode = 'queue';

    public string $branch = '';

    public string $search = '';

    public string $customer = '';

    public string $customerName = '';

    public string $name = '';

    public string $phone = '';

    public string $phoneCountry = 'IQ';

    /** @var list<string> */
    public array $services = [];

    public string $employee = '';

    public int $priority = 0;

    /** With the queue: hand them a number too. */
    public bool $ticket = true;

    public string $token = '';

    #[On('open-walk-in')]
    public function start(string $branch = '', string $mode = 'queue'): void
    {
        $this->resetExcept();
        $this->resetErrorBag();
        $this->mode = $mode === 'visit' ? 'visit' : 'queue';
        $this->token = (string) Str::uuid();

        $options = app(VisitOptions::class);
        $mine = $options->branches($this->viewer());
        $this->branch = $options->branch($this->viewer(), $branch) !== null ? $branch : ($mine[0]['uuid'] ?? '');
        $this->ticket = $this->queueAvailable();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function updatedBranch(): void
    {
        $this->reset(['services', 'employee']);
    }

    public function updatedServices(): void
    {
        // A stylist qualified for the old choice may not be for the new one.
        $this->employee = '';
    }

    public function pick(string $uuid, CustomerQuery $customers, CustomerPresenter $presenter): void
    {
        if (! $this->viewer()->hasPermission(Permission::CustomerView)) {
            return;
        }

        // Only a customer the same search would list: the uuid a browser sends
        // is never trusted on its own.
        $found = collect($customers->paginate(['search' => trim($this->search)], $this->viewer(), 8)->items())
            ->first(static fn (Customer $c): bool => $c->uuid === $uuid);

        if ($found instanceof Customer) {
            $this->customer = $found->uuid;
            $this->customerName = (string) $presenter->summary($found, $this->viewer())['name'];
            $this->search = '';
        }
    }

    public function clearCustomer(): void
    {
        $this->reset(['customer', 'customerName']);
    }

    public function save(CreateWalkInTicket $withTicket, CreateWalkInVisit $visitOnly): void
    {
        $this->validate([
            'branch' => ['required', 'string'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['string'],
            'name' => [$this->customer === '' ? 'required' : 'nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32', ...($this->phone === '' ? [] : [new PhoneRule($this->phoneCountry)])],
            'priority' => ['integer', 'in:0,10,20'],
        ], [], [
            'branch' => __('manager_queue.walk_in.branch'),
            'services' => __('manager_queue.walk_in.services'),
            'name' => __('manager_queue.walk_in.name'),
            'phone' => __('phone_field.label'),
        ]);

        $this->attempt(function () use ($withTicket, $visitOnly): void {
            $phone = $this->phone === '' ? null : PhoneNumber::fromParts($this->phoneCountry, $this->phone);

            $request = new WalkInRequest(
                branchUuid: $this->branch,
                serviceUuids: array_values(array_filter($this->services, 'is_string')),
                customerUuid: $this->customer === '' ? null : $this->customer,
                name: $this->customer === '' ? trim($this->name) : null,
                phone: $this->customer === '' && $phone !== null ? (string) $phone : null,
                employeeUuid: $this->employee === '' ? null : $this->employee,
                idempotencyToken: $this->token,
            );

            if ($this->ticket && $this->queueAvailable()) {
                $result = $withTicket($request, $this->viewer(), ['priority' => $this->priority]);

                $this->dispatch('walk-in-created', ticket: $result['ticket']->uuid, number: $result['ticket']->display_number, journey: $result['journey']->uuid);
            } else {
                $journey = $visitOnly($request, $this->viewer());

                $this->dispatch('walk-in-created', journey: $journey->uuid);
            }

            $this->open = false;
        });
    }

    public function render(VisitOptions $options, CustomerQuery $customers, CustomerPresenter $presenter): View
    {
        if (! $this->open) {
            return view('livewire.center.queue.walk-in-form', ['ready' => false]);
        }

        $viewer = $this->viewer();
        $branch = $options->branch($viewer, $this->branch);
        $matches = [];

        if ($this->customer === '' && mb_strlen(trim($this->search)) >= 2 && $viewer->hasPermission(Permission::CustomerView)) {
            $matches = collect($customers->paginate(['search' => trim($this->search)], $viewer, 8)->items())
                ->map(static function (Customer $c) use ($presenter, $viewer): array {
                    $summary = $presenter->summary($c, $viewer);

                    // Masked by the presenter; never a query value (ADR-042).
                    return ['uuid' => $summary['uuid'], 'name' => $summary['name'], 'contact_phone' => $summary['phone']];
                })
                ->values()
                ->all();
        }

        return view('livewire.center.queue.walk-in-form', [
            'ready' => true,
            'branches' => $options->branches($viewer),
            'serviceOptions' => $branch === null ? [] : $options->services($branch),
            'employeeOptions' => $branch === null || $this->services === [] ? [] : $options->employeesFor($branch, $this->services),
            'matches' => $matches,
            'canSearch' => $viewer->hasPermission(Permission::CustomerView),
            'queueAvailable' => $this->queueAvailable(),
            'canPrint' => $this->queueAvailable()
                && $viewer->hasPermission(Permission::QueueTicketPrint)
                && app(Entitlements::class)->enabled('printing'),
        ]);
    }

    /**
     * Whether this viewer at this center can hand out a number with the visit.
     */
    private function queueAvailable(): bool
    {
        return app(Entitlements::class)->enabled('queue_management')
            && $this->viewer()->hasPermission(Permission::QueueManage);
    }
}
