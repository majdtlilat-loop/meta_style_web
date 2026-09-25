<?php

declare(strict_types=1);

namespace App\Livewire\Center\Benefits;

use App\Kernel\Identity\Models\User;
use App\Modules\Memberships\Application\MembershipsPresenter;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Who holds a membership in force (or starting soon), soonest ending first —
 * on the plans page. NAME only, linked to the customer's page. Read-only: a
 * membership is sold at the till and cancelled from the customer's page.
 */
final class MembershipMembers extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(MembershipsQuery $query, MembershipsPresenter $presenter): View
    {
        $user = auth('web')->user();

        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $page = $query->members($user, null, $this->search, 15);
        } catch (AuthorizationException) {
            return view('livewire.center.customers.panel-denied');
        }

        /** @var list<CustomerMembership> $items */
        $items = $page->items();
        $locale = app()->getLocale();

        return view('livewire.center.benefits.membership-members', [
            'page' => $page,
            'members' => array_map(static fn (array $row): array => $row + [
                'state_label' => __('manager_benefits.states.'.$row['state']),
                'last_day_label' => CarbonImmutable::parse((string) $row['last_day'])->locale($locale)->isoFormat('D MMM YYYY'),
                'url' => $row['customer'] === null ? null : route('center.customers.show', ['uuid' => $row['customer']['uuid'], 'tab' => 'plans']),
            ], $presenter->members($items)),
        ]);
    }
}
