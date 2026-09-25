<?php

declare(strict_types=1);

namespace App\Livewire\Center\Customers;

use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Customers\Concerns\FormatsLocalDates;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\ServiceJourney\Application\CustomerProfileVisits;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use App\View\Label;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The customer page's visits: what actually HAPPENED, walk-in or booked —
 * who performed each service and what was skipped (docs/16 §22).
 *
 * The Journey module's own read (`journey.view` + branch scope). Times are on
 * the visit's branch clock. Read-only — the floor board is where visits move.
 */
final class VisitsPanel extends Component
{
    use FormatsLocalDates;

    #[Locked]
    public string $customer = '';

    public function render(CustomerQuery $customers, CustomerProfileVisits $visits): View
    {
        $user = $this->user();

        try {
            $customer = $customers->find($this->customer, $user);
            $found = $visits->forCustomer($user, (int) $customer->getKey());
        } catch (AuthorizationException) {
            return view('livewire.center.customers.panel-denied');
        }

        return view('livewire.center.customers.visits-panel', [
            'visits' => array_map(fn (ServiceJourney $visit): array => $this->row($visit), $found),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ServiceJourney $visit): array
    {
        $appointment = $visit->relationLoaded('appointment') ? $visit->appointment : null;
        $branch = $visit->branch ?? $appointment?->branch;
        $timezone = $branch instanceof Branch ? $branch->timezone : null;
        $locale = app()->getLocale();

        return [
            'uuid' => $visit->uuid,
            'status' => $visit->status->value,
            'status_label' => Label::for('journey_status', $visit->status->value),
            'tone' => match ($visit->status->value) {
                'completed' => 'success',
                'aborted' => 'danger',
                default => 'primary',
            },
            'walk_in' => $visit->isWalkIn(),
            'arrived' => $this->localDateTime($visit->arrived_at, $timezone),
            'finished' => $this->localDateTime($visit->completed_at ?? $visit->aborted_at, $timezone),
            'reference' => $appointment?->reference,
            'branch' => $branch instanceof Branch ? $branch->name->get() : null,
            'reason' => $visit->abort_reason,
            'stages' => array_values($visit->stages->map(fn (JourneyStage $stage): array => [
                'service' => $stage->serviceName()?->get($locale) ?? '—',
                'employee' => $stage->relationLoaded('employee') ? $stage->employee?->name->get($locale) : null,
                'status' => $stage->status->value,
                'status_label' => Label::for('stage_status', $stage->status->value),
            ])->all()),
        ];
    }

    private function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
