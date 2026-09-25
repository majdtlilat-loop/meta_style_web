<?php

declare(strict_types=1);

namespace App\Livewire\Center\Benefits;

use App\Kernel\Identity\Models\User;
use App\Modules\Packages\Application\PackagesPresenter;
use App\Modules\Packages\Application\PackagesQuery;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Who holds an active package and how many sessions are left, soonest ending
 * first — on the packages page. Sessions left come from the history in one
 * query; NAME only, linked to the customer's page (docs/21 §14).
 */
final class PackageHolders extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(PackagesQuery $query, PackagesPresenter $presenter): View
    {
        $user = auth('web')->user();

        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $page = $query->holders($user, null, $this->search, 15);
        } catch (AuthorizationException) {
            return view('livewire.center.customers.panel-denied');
        }

        /** @var list<CustomerPackage> $items */
        $items = $page->items();
        $locale = app()->getLocale();

        return view('livewire.center.benefits.package-holders', [
            'page' => $page,
            'holders' => array_map(static fn (array $row): array => $row + [
                'last_day_label' => CarbonImmutable::parse((string) $row['last_day'])->locale($locale)->isoFormat('D MMM YYYY'),
                'url' => $row['customer'] === null ? null : route('center.customers.show', ['uuid' => $row['customer']['uuid'], 'tab' => 'plans']),
            ], $presenter->holders($items)),
        ]);
    }
}
